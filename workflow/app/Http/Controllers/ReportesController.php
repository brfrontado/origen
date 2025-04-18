<?php

namespace App\Http\Controllers;

use app\core\Response;
use app\models\Clientes;
use App\Models\Cotizacion;
use App\Models\CotizacionDetalle;
use App\Models\CotizacionBitacora;
use App\Models\Productos;
use App\Models\Reporte;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Symfony\Component\VarDumper\VarDumper;
use Mailgun\Exception\HttpClientException;
use Mailgun\Mailgun;
use App\Models\CotizacionDetalleBitacora;
use App\Models\Flujos;


class ReportesController extends Controller {

    use Response;

    // plantillas pdf
    public function Save(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $activo = $request->get('activo');
        $producto = $request->get('flujos');
        $tipo = $request->get('tipo');
        $campos = $request->get('campos');
        $docsTpl = $request->get('docsTpl');
        $agrupacion = $request->get('agrupacion');
        $visibilidad = $request->get('visibilidad');
        $variablesDefault = $request->get('variablesDefault');

        $allowSendReport = $request->get('allowSendReport');
        $dateStart = $request->get('dateStart');
        $period_assign = $request->get('period_assign');
        $mailConfig = $request->get('mailConfig');
        $system = $request->get('system');
        //var_dump($agrupacion);

        $item = Reporte::where('id', $id)->first();

        if (empty($item)) {
            $item = new Reporte();
        }

        $arrConfig = [];
        foreach ($campos as $campo) {
            $tmp = explode('||', $campo);
            $arrConfig['c'][] = [
                'id' => $campo,
                'p' => $tmp[0],
                'c' => $tmp[1],
            ];
        }
        foreach ($agrupacion as $campo) {
            $tmp = explode('||', $campo['id']);
            $arrConfig['ag'][] = [
                'id' => $campo['id'],
                'opt' => $campo['campoOpt'],
                'p' => $tmp[0],
                'c' => $tmp[1],
            ];
        }

        $arrConfig['p'] = $producto;
        $arrConfig['tpl'] = $docsTpl;
        $arrConfig['visibilidad'] = $visibilidad;
        $arrConfig['variablesDefault'] = $variablesDefault;
        $arrConfig['system'] = $system;

        $item->id = intval($id);
        $item->nombre = strip_tags($nombre);
        $item->productoId = intval($producto);
        $item->activo = intval($activo);
        $item->config = @json_encode($arrConfig) ?? null;
        $item->tipo = $tipo;
        $item->sendReport=$allowSendReport;
        $item->mailconfig=@json_encode($mailConfig) ?? null;
        $item->dateToSend=$dateStart;
        $item->period=$period_assign;
        $item->save();

        return $this->ResponseSuccess('Plantilla guardada con éxito', ['id' => $item->id]);
    }

    public function ListadoAll(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/listar'])) return $AC->NoAccess();

        $item = Reporte::all();
        return $this->ResponseSuccess('Reportes obtenidos con éxito', $item);
    }

    public function Listado(Request $request) {
        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/listar'])) return $AC->NoAccess();

        $usuarioLogueado = auth('sanctum')->user();
        $rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;

        $reports = Reporte::all();
        $data = [];

        foreach($reports as $item){
            $visibilidad = json_decode($item->config, true)['visibilidad']?? [];
            $access = $AC->CalculateVisibility($usuarioLogueado->id, $rolUsuarioLogueado->id ?? 0, false, $visibilidad['roles'] ?? [], $visibilidad['grupos'] ?? [], $visibilidad['canales'] ?? []);
            if (!$access &&  !in_array($usuarioLogueado->id, $visibilidad['users']?? [])) continue;
            $data[] = $item;
        }
        return $this->ResponseSuccess('Reportes obtenidos con éxito', $data);
    }

    public function ListadoMasivos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/listar'])) return $AC->NoAccess();

        $item = Reporte::where('tipo', 'm')->where('activo', 1)->get();
        return $this->ResponseSuccess('Reportes obtenidos con éxito', $item);
    }

    public function ListadoFlujos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

        $item = Productos::all();
        $item->makeHidden(['descripcion', 'token', 'extraData', 'imagenData']);
        return $this->ResponseSuccess('Reportes obtenidos con éxito', $item);
    }

    public function GetDocsPlusTpl(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.env('ANY_SUBSCRIPTIONS_TOKEN')
        );
        $ch = curl_init(env('ANY_SUBSCRIPTIONS_URL', '').'/formularios/all');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $dataResponse = @json_decode($data, true);

        $templates = [];
        if (!empty($dataResponse['status'])) {
            foreach ($dataResponse['data'] as $data) {
                $templates[$data['id']] = [
                    'n' => $data['descripcion']." ({$data['token']})",
                    't' => $data['token'],
                ];
            }
        }

        return $this->ResponseSuccess('Plantillas obtenidas con éxito', $templates);
    }

    public function NodosCampos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

        $productosTmp = $request->get('productos');
        // voy a traer los productos
        $productos = Productos::whereIn('id', $productosTmp)->get();

        $allFields = [];
        $arrResponse = [];
        foreach ($productos as $producto) {

            $flujo = $producto->flujo->where('activo', 1)->first();
            if (empty($flujo)) {
                return $this->ResponseError('RPT-001', 'Flujo no válido');
            }

            $flujoConfig = @json_decode($flujo->flujo_config, true);
            if (!is_array($flujoConfig)) {
                return $this->ResponseError('RPT-002', 'Error al interpretar flujo, por favor, contacte a su administrador');
            }

            foreach ($flujoConfig['nodes'] as $nodo) {

                //$resumen
                if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                    foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                        foreach ($seccion['campos'] as $keyCampoTmp => $campo) {
                            $seccionId = str_replace(' ', '_', $seccion['nombre']);
                            $keyCampo = $producto->id.'_'.$seccionId.'||'.$campo['id'] ;
                            $allFields[$keyCampo]['id'] = $keyCampo;
                            $allFields[$keyCampo]['r'] = $campo['id'];
                            $allFields[$keyCampo]['label'] = $campo['nombre'];
                            $allFields[$keyCampo]['pr'] = $producto->nombreProducto;
                            $allFields[$keyCampo]['nodo'] = strip_tags($nodo['label']);
                        }
                    }
                }
            }

            // variables default
            $allFields['_id'] = [
                'id' => $producto->id.'_0||'.'_id',
                'r' => '_id',
                'label' => 'No. de tarea',
                'pr' => $producto->nombreProducto,
                'nodo' => 'N/D',
            ];
            $allFields['_dateCreated'] = [
                'id' => $producto->id.'_0||'.'_dateCreated',
                'r' => '_dateCreated',
                'label' => 'Tarea - Fecha creación',
                'pr' => $producto->nombreProducto,
                'nodo' => 'N/D',
            ];
            $allFields['_creado_p'] = [
                'id' => $producto->id.'_0||'.'_creado_p',
                'r' => '_creado_p',
                'label' => 'Tarea - Nombre creador',
                'pr' => $producto->nombreProducto,
                'nodo' => 'N/D',
            ];
            $allFields['_ag_asig'] = [
                'id' => $producto->id.'_0||'.'_ag_asig',
                'r' => '_ag_asig',
                'label' => 'Tarea - Agente asignado',
                'pr' => $producto->nombreProducto,
                'nodo' => 'N/D',
            ];
            $allFields['_estado'] = [
                'id' => $producto->id.'_0||'.'_estado',
                'r' => '_estado',
                'label' => 'Tarea - Estado',
                'pr' => $producto->nombreProducto,
                'nodo' => 'N/D',
            ];
        }

        return $this->ResponseSuccess('Campos obtenidos con éxito', $allFields);
    }

    public function GetReporte(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/generar'])) return $AC->NoAccess();

        $id = $request->get('id');

        $item = Reporte::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('RPT-014', 'Error al obtener reporte');
        }

        $item->c = @json_decode($item->config);
        $item->mail = @json_decode($item->mailconfig);
        $item->makeHidden(['config']);
        $item->makeHidden(['mailconfig']);

        return $this->ResponseSuccess('Reporte obtenido con éxito', $item);
    }

    public function Generar(Request $request, $public = false) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/generar']) && !$public) return $AC->NoAccess();

        $id = $request->get('reporteId');
        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');

        $fechaIni = Carbon::parse($fechaIni);
        $fechaFin = Carbon::parse($fechaFin);

        $item = Reporte::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('RPT-015', 'Error al obtener reporte');
        }

        // $item->nombre
        $config = @json_decode($item->config, true);

        if($item->tipo === 's') {
            $data = [
                'system' => $config['system'],
                'fechaIni' => $fechaIni,
                'fechaFin' => $fechaFin,
                'reportName' => $item->nombre,
            ];
            return $this->GenerarSistema($data);
        }


        /*$strQueryFull = "SELECT C.
                        FROM cotizaciones AS C
                        JOIN M_servicios S on D.siniestro = S.siniestro
                        WHERE
                            D.fecha >= '".$fechaIni->toDateString()."'
                        AND D.fecha <= '".$fechaFin->toDateString()."'
                        GROUP BY D.codigoDiagnostico, D.diagnosticoDesc, D.fecha
                        ORDER BY ConteoSiniestro DESC";
        */

        $campos = '';
        $camposOri = [];
        foreach ($config['c'] as $conf) {
            $campos .= ($campos !== '') ? ", '{$conf['c']}'" : "'{$conf['c']}'";
            $camposOri[] = $conf['c'];
        }

        $variablesDefault = $config['variablesDefault']?? [];
        foreach ($variablesDefault as $conf) {
            $campos .= ($campos !== '') ? ", '{$conf}'" : "'{$conf}'";
            $camposOri[] = $conf;
        }

        $prod = '';
        foreach ($config['p'] as $conf) {
            $conf = intval($conf);
            $prod .= ($prod !== '') ? ", {$conf}" : "{$conf}";
        }
       
        $strQueryFull = "SELECT C.id, C.dateCreated, C.dateExpire, C.productoId, C.usuarioId, C.usuarioIdAsignado, C.estado, CD.campo, CD.valorLong, P.nombreProducto
                FROM cotizaciones AS C
                JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                JOIN productos AS P ON C.productoId = P.id
                WHERE 
                    C.productoId IN ($prod)
                    AND CD.campo IN ({$campos})
                    AND C.dateCreated >= '".$fechaIni->format('Y-m-d')." 00:00:00'
                    AND C.dateCreated <= '".$fechaFin->format('Y-m-d')." 23:59:59'
                ";
    
    
        /*print($strQueryFull);
        die();*/

        $queryTmp = DB::select(DB::raw($strQueryFull));

        $datosFinal = [];
        $datosFinal[] = [
            'No.',
            'Fecha creación',
            'Fecha expiración',
            'Producto',
            /*'Usuario Asignado',
            'Usuario Creador',*/
        ];

        $campos = [];
        $data = [];
    foreach ($queryTmp as $tmp) {
        if($tmp->campo === 'FECHA_HOY')  $valorLong = Carbon::now()->setTimezone('America/Guatemala')->toDateTimeString();
        $campos[] = $tmp->campo;
        $data[$tmp->id][$tmp->campo] = $tmp->valorLong;
        $data[$tmp->id]['ESTADO_ACTUAL'] = $tmp->estado ?? 'Sin Estado';
    }

       

        // Eliminación de duplicados y valores vacíos en camposOri
        $camposOri = array_filter(array_unique($camposOri));
        // Reindexar el array para evitar índices no secuenciales
        $camposOri = array_values($camposOri);

        foreach ($camposOri as $campo) {
            // Agregar siempre la columna al encabezado, independientemente de si tiene datos
            $datosFinal[0][] = $campo;

        }

        foreach ($queryTmp as $tmp) {
            $datosFinal[$tmp->id]['id'] = $tmp->id;
            $datosFinal[$tmp->id]['dateCreated'] = $tmp->dateCreated;
            $datosFinal[$tmp->id]['dateExpire'] = $tmp->dateExpire;
            $datosFinal[$tmp->id]['nombreProducto'] = $tmp->nombreProducto;

            foreach ($camposOri as $campo) {
                // Asignar el valor correspondiente o una cadena vacía si no existe
                $datosFinal[$tmp->id][$campo] = $data[$tmp->id][$campo] ?? '';
            }
        }

        $spreadsheet = new Spreadsheet();

        $spreadsheet
            ->getProperties()
            ->setCreator("GastosMedicos-ElRoble")
            ->setLastModifiedBy('Automator') // última vez modificado por
            ->setTitle('Reporte de '.$item->nombre)
            ->setDescription('Reporte');

        // first sheet
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Hoja 1");
        $sheet->fromArray($datosFinal, NULL, 'A1');

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cell->setValueExplicit($cell->getValue(), DataType::TYPE_STRING);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $fileNameHash = md5(uniqid());
        $tmpPath = storage_path("tmp/{$fileNameHash}.xlsx");
        $writer->save($tmpPath);

        $disk = Storage::disk('s3');
        $path = $disk->putFileAs("/tmp/files", $tmpPath, "{$fileNameHash}.xlsx");
        $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

        return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $temporarySignedUrl]);
    }



    public function GenerarSistema($data) {
        $system = $data['system'];
        $fechaIni = $data['fechaIni'];
        $fechaFin = $data['fechaFin'];
        $reportName = $data['reportName'];

        $options = [
            'R1' => [
                'datos' => [
                    ['value' => 'flujo', 'text' => 'Flujo'],
                    ['value' => 'parcial', 'text' => 'Parcial']
                ],
                'strQueryFull' => "SELECT P.nombreProducto as flujo, COUNT(C.id) as parcial
                FROM cotizaciones AS C
                JOIN productos AS P ON C.productoId = P.id
                WHERE C.dateCreated >= '".$fechaIni->format('Y-m-d')." 00:00:00'
                AND C.dateCreated <= '".$fechaFin->format('Y-m-d')." 23:59:59'
                GROUP BY P.id
                ORDER BY P.nombreProducto",
            ],
            'R2' => [
                'datos' => [
                    ['value' => 'flujo', 'text' => 'Flujo'],
                    ['value' => 'nodoName', 'text' => 'Nombre del Nodo'],
                    ['value' => 'nodoNameId', 'text' => 'Identificador de Nodo'],
                    ['value' => 'parcial', 'text' => 'Parcial']
                ],
                'strQueryFull' => "SELECT P.nombreProducto as flujo, C.productoId, C.nodoActual, COUNT(C.id) as parcial
                FROM cotizaciones AS C
                JOIN productos AS P ON C.productoId = P.id
                WHERE C.dateCreated >= '".$fechaIni->format('Y-m-d')." 00:00:00'
                AND C.dateCreated <= '".$fechaFin->format('Y-m-d')." 23:59:59'
                GROUP BY P.nombreProducto, C.productoId, C.nodoActual
                ORDER BY P.nombreProducto",
            ]
        ];
        if(empty($options[$system])) return $this->ResponseError('RPT-0110', 'No existe reporte de sistema');

        $datosFinal = [];
        $queryTmp = DB::select(DB::raw($options[$system]['strQueryFull']));
        $foot = [];
        $total = 0;

        $camposOri = $options[$system]['datos'];

        foreach ($camposOri as $campo) {
            $datosFinal[0][] = $campo['text'];
            $foot[] = '';
        }

        $flujosFromCotizacion = [];

        foreach ($queryTmp as $tmp) {
            $datosFinal[] = [];
            $total += $tmp->parcial?? 0;
            if(!empty($tmp->nodoActual) && !empty($tmp->productoId)){
                if(empty($flujosFromCotizacion[$tmp->productoId])){
                    $producto = Productos::where('id', $tmp->productoId)->first();
                    $flujo = $producto->flujo->first();
                    if (!empty($flujo)) {
                        $flujoConfig = @json_decode($flujo->flujo_config, true);
                        $flujosFromCotizacion[$tmp->productoId] = $flujoConfig;
                    }
                }
                $flujoConfig = $flujosFromCotizacion[$tmp->productoId];
                if(!empty($flujoConfig)){
                    $nodo = array_values(array_filter($flujoConfig['nodes'], function($nodo) use ($tmp) {
                        return $nodo['id'] === $tmp->nodoActual;
                    }))[0]?? [];

                    $tmp->nodoName = $nodo['nodoName']?? '';
                    $tmp->nodoNameId = $nodo['nodoId']?? '';
                }
            }
            foreach ($camposOri as $campo) {
                $value = $campo['value'];
                $datosFinal[count($datosFinal)-1][$campo['value']] = $tmp->$value ?? '';
            }
        }

        $foot[count($foot)-2] = 'Total';
        $foot[count($foot)-1] = $total;
        $datosFinal[] = $foot;

        $spreadsheet = new Spreadsheet();

        $spreadsheet
            ->getProperties()
            ->setCreator("GastosMedicos-ElRoble")
            ->setLastModifiedBy('Automator') // última vez modificado por
            ->setTitle('Reporte de '.$reportName)
            ->setDescription('Reporte');

        // first sheet
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Hoja 1");
        $sheet->fromArray($datosFinal, NULL, 'A1');

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cell->setValueExplicit($cell->getValue(), DataType::TYPE_STRING);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $fileNameHash = md5(uniqid());
        $tmpPath = storage_path("tmp/{$fileNameHash}.xlsx");
        $writer->save($tmpPath);

        $disk = Storage::disk('s3');
        $path = $disk->putFileAs("/tmp/files", $tmpPath, "{$fileNameHash}.xlsx");
        $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

        return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $temporarySignedUrl]);
    }

    public function ReporteByNodoBitacora(Request $request) {
        $cotizacionId = $request->get('token');
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();
        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Tarea no válida');
        }

        $camposAll = CotizacionDetalleBitacora::
            where('cotizacionId', $cotizacion->id)
            ->orderBy('id', 'DESC')
            ->get();

        $producto = Productos::where('id', $cotizacion->productoId)->first();
        $flujo = Flujos::Where('productoId', '=', $producto->id)->Where('activo', '=', 1)->first();
        if (!empty($producto)) $data['producto'] = $producto->toArray();
        if (!empty($flujo)) $data['flujo'] = $flujo->toArray();

        $typesNode = [
            "start" => "Inicio",
            "input" => "Entradas",
            "condition" => "Condición",
            "process" => "Proceso",
            "setuser" => "Usuario",
            "review" => "Revisión",
            "output" => "Salida",
        ];

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        $allNodes = [];
        $nodoStart = '';
        foreach($flujoConfig['nodes'] as $node){
            $allNodes[$node['id']] = $node;
            if ($node['typeObject'] === 'start'){
                $nodoStart  = $node['id'];
            }
        }
        //cotizacionId, nodo, etapa, campo, valor, fecha,

        $datosFinal = [];
        $datosFinal[0] = ['TAREA', 'NODO', 'ETAPA', 'CAMPO','CAMPOID', 'VALOR', 'FECHA', 'USUARIO'];

        foreach($camposAll as $campo){
             $usuario = $campo->usuario ?? null;
            $datosFinal[] = [
                'TAREA' => $campo->cotizacionId,
                'NODO' => $allNodes[$campo->nodoId?? $nodoStart]['nodoName'],
                'ETAPA' =>$typesNode[$allNodes[$campo->nodoId?? $nodoStart]['typeObject'] ?? 'default'] ?? 'Nodo sin etapa',
                'CAMPO' => $campo->label,
                'CAMPOID' => $campo->campo,
                'VALOR' => $campo->valorLong,
                'FECHA' => Carbon::parse($campo->createdAt)->setTimezone('America/Guatemala')->format('d/m/Y H:i'),
                'USUARIO'=> $usuario->name?? 'Sin Usuario',
            ];
        }


        $spreadsheet = new Spreadsheet();

        $spreadsheet
            ->getProperties()
            ->setCreator("GastosMedicos-ElRoble")
            ->setLastModifiedBy('Automator') // última vez modificado por
            ->setTitle('Reporte de Logs Bitacora')
            ->setDescription('Reporte');

        // first sheet
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Hoja 1");
        $sheet->fromArray($datosFinal, NULL, 'A1');

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cell->setValueExplicit($cell->getValue(), DataType::TYPE_STRING);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $fileNameHash = md5(uniqid());
        $tmpPath = storage_path("tmp/{$fileNameHash}.xlsx");
        $writer->save($tmpPath);

        $disk = Storage::disk('s3');
        $path = $disk->putFileAs("/tmp/files", $tmpPath, "{$fileNameHash}.xlsx");
        $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

        return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $temporarySignedUrl]);
    }

    public function GenerarMasivo(Request $request) {
        ini_set('max_execution_time', 1500); // media una hora aprox
        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/generar'])) return $AC->NoAccess();

        $tareas = $request->get('tareas');
        $reporte = $request->get('reporte');
        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');

        $fechaIni = Carbon::parse($fechaIni);
        $fechaFin = Carbon::parse($fechaFin);

        $item = Reporte::where('id', $reporte)->first();
        if (empty($item)) {
            return $this->ResponseError('RPT-014', 'Reporte inválido');
        }

        $fieldsToGroup = [];
        $fieldsToSend = [];
        $fieldsToSendOrder = [
            0 // orden
        ];
        $reporteConfig = @json_decode($item->config, true);
        foreach ($reporteConfig['c'] as $item) {
            $fieldsToSend[$item['c']] = $item;
            $fieldsToSendOrder[] = $item['c'];
        }
        foreach ($reporteConfig['ag'] as $item) {
            //var_dump($item);;
            $fieldsToGroup[$item['c']] = $item['opt'];
        }

        $variablesDefault = $reporteConfig ['variablesDefault']?? [];
        foreach ($variablesDefault as $item) {
            $arrConfig = [
                'id' => $item,
                'p' => $item,
                'c' => $item,
            ];
            $fieldsToSend[$item] = $arrConfig;
            $fieldsToSendOrder[] = $item;
        }

        $cotizaciones = Cotizacion::whereIn('id', $tareas)->with('campos')->get();

        $arrDataSend = [];
        $dataSend['token'] = $reporteConfig['tpl'] ?? '';
        $dataSend['operation'] = 'generate';
        $dataSend['response'] = 'url';

        $usuarioLogueado = auth('sanctum')->user();
        // var_dump($usuarioLogueado);
        $arrDataSend['IMPRESO_POR'] = $usuarioLogueado->name ?? 'N/D';

        $fieldsTypes = [];

        $headersSet = false;
        $allPathGroup = [];
        if (!empty($cotizaciones)) {
            //$arrDataSend['tabla_masiva']['headers'][] = "No.";

            $contador = 1;
            if(empty($arrDataSend['file']))  $arrDataSend['file'] = [];
            foreach ($cotizaciones as $key => $coti) {

                // numeración automática
                $arrDataSend['tabla_masiva']['rows'][$key][0] = $key + 1;

                foreach ($coti->campos as $campo) {
                    if ($campo->tipo === 'text' ||
                        $campo->tipo === 'option' ||
                        $campo->tipo === 'select' ||
                        $campo->tipo === 'textArea' ||
                        $campo->tipo === 'default'||
                        $campo->tipo === 'number' ||
                        $campo->tipo === 'date' ||
                        $campo->tipo === 'currency'
                    ) {
                        $fieldsTypes[$campo->campo] = $campo->tipo;

                        // si se deben agrupar
                        if (isset($fieldsToGroup[$campo->campo])) {
                            if ($fieldsToGroup[$campo->campo] === 'sum') {
                                if (!isset($arrDataSend[$campo->campo])) {
                                    $arrDataSend[$campo->campo] = 0;
                                }
                                $arrDataSend[$campo->campo] += $campo->valorLong;
                            }
                            if ($fieldsToGroup[$campo->campo] === 'showg') {
                                if (!isset($arrDataSend[$campo->campo])) {
                                    $arrDataSend[$campo->campo] = $campo->valorLong;
                                }
                            }
                        }

                        if (isset($fieldsToSend[$campo->campo])) {
                            $keyOrder = array_search($campo->campo, $fieldsToSendOrder);
                            /*if (!$headersSet) {
                                $arrDataSend['tabla_masiva']['headers'][] = $campo->label;
                            }*/
                            $arrDataSend['tabla_masiva']['rows'][$key][$keyOrder] = $campo->valorLong ?? 'N/D';
                        }

                        if ($campo->campo === 'USUARIO_ACT_NODO_nodo_caja') {
                            $arrDataSend['autorizado_por']['rows'][] = [
                                $contador,
                                $campo->valorLong,
                            ];
                        }
                    }
                    if($campo->isFile &&  $campo->tipo !== 'signature' && $campo->campo !==  'SYSTEM_TEMPLATE' && !empty($campo->valorLong)){
                        //if(count($allPathGroup[count($allPathGroup)-1]) > 20) $allPathGroup[] = [];
                        $allPathGroup[] = $campo->valorLong;
                    }
                }

                $headersSet = true;
                $contador++;
            }
        }

        // formateo
        foreach ($arrDataSend as $fieldKey => $value){
            if (!empty($fieldsTypes[$fieldKey])) {

                if (is_array($value)) {
                    foreach ($value as $tk => $tv) {
                        if (is_float($tv)) {
                            $arrDataSend[$fieldKey][$tk] = number_format($tv, 2);
                        }
                    }
                }
                else {
                    if ($fieldsTypes[$fieldKey] === 'currency') {
                        $arrDataSend[$fieldKey] = number_format($value, 2);
                    }
                }
            }
        }


        // Genero reporte inicial
        $dataSend['data'] = $arrDataSend;

        /*var_dump($dataSend);
        die();*/

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.env('ANY_SUBSCRIPTIONS_TOKEN')
        );

        /*var_dump(json_encode($dataSend));
        die();*/

        $ch = curl_init(env('ANY_SUBSCRIPTIONS_URL', '').'/formularios/docs-plus/generate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $dataResponse = @json_decode($data, true);

        $reporteUrl = '';
        if (!empty($dataResponse['status'])) {
            /*$tmpFile = base_path().'/public/tmp'.md5(uniqid()).".pdf";
            file_put_contents($tmpFile, fopen($dataResponse['data']['url'], 'r'));*/
            //return response()->download($tmpFile, "Reporte masivo Workflow.pdf");
            /*$url = base64_encode($dataResponse['data']['url']);
            $newUrl = env('APP_URL')."/api/reportes/download/file/{$url}";*/

            $reporteUrl = $dataResponse['data']['url'];
            //return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $newUrl]);
        }

        /*$allFiles = [];

        if (!empty($reporteUrl)) {
            $allFiles[] = $reporteUrl;
        }*/

        //$allFiles = array_merge($allFiles, $allPathGroup);

        //var_dump($allFiles);
        /*var_dump($dataResponse);
        var_dump($allPathGroup);
        die();*/


        /*$headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.env('ANY_SUBSCRIPTIONS_TOKEN')
        );
        $dataMerge = ["merge" => $allFiles];

        $urlTmp = env('ANY_SUBSCRIPTIONS_ELB', '').'/formularios/docs-plus/pdf-merge';

        $ch = curl_init($urlTmp);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataMerge));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);

        $info = curl_getinfo($ch);
        curl_close($ch);
        $dataResponse = @json_decode($data, true);

        if (empty($dataResponse['status'])) {
            return $this->ResponseError('RPT-014', $dataResponse['msg'] ?? 'Error al unir adjuntos');
        }*/


        // var_dump($dataResponse);

        if (!empty($dataResponse['status'])) {
            /*$tmpFile = base_path().'/public/tmp'.md5(uniqid()).".pdf";
            file_put_contents($tmpFile, fopen($dataResponse['data']['url'], 'r'));*/
            //return response()->download($tmpFile, "Reporte masivo Workflow.pdf");
            $url = base64_encode($dataResponse['data']['url']);
            $newUrl = env('APP_URL')."/api/reportes/download/file/{$url}";

            return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $newUrl]);
        }
        else {
            return $this->ResponseError('RPT-015', $dataResponse['msg'] ?? 'Error al generar reporte');
        }
    }

    public function DescargarForzado($url) {

        $AC = new AuthController();
        //$url = $request->get('url');
        //if (!$AC->CheckAccess(['reportes/listar'])) return $AC->NoAccess();

        $urlNew = base64_decode($url);

        header("Content-type:application/pdf");
        header("Content-Disposition:attachment;filename=\"Reporte masivo Workflow.pdf\"");
        /*header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename=\"Reporte masivo Workflow.pdf\"");*/
        readfile($urlNew);
        exit;
    }

    public function DeleteReporte(Request $request) {
        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/eliminar'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $item = Reporte::find($id);

            if (!empty($item)) {
                $item->delete();
                return $this->ResponseSuccess('Eliminado con éxito', $item->id);
            }
            else {
                return $this->ResponseError('AUTH-R6321', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            var_dump($th->getMessage());
            return $this->ResponseError('AUTH-R6302', 'Error al eliminar');
        }
    }

    public function ReportProgram(Request $request) {
        $date = Carbon::now();

        $reports = Reporte::where([['sendReport', 1],['dateToSend', '<=', $date]])->get();
        foreach($reports as $report){
            $period = $report->period;
            $mailconfig = json_decode($report->mailconfig, true);
            if(empty($period) || empty($mailconfig)) continue;

            $dateToSend = Carbon::parse($report->dateToSend);
            $fechaIni =  Carbon::parse($report->dateToSend);
            $newDateToSend = Carbon::parse($report->dateToSend);

            if($period === 'week'){
                $diff = $date->diffInWeeks($dateToSend) + 1;
                $fechaIni->subWeeks(1);
                $newDateToSend->addWeeks($diff);
            }else if($period === 'month'){
                $diff = $date->diffInMonths($dateToSend) + 1;
                $fechaIni->subMonths(1);
                $newDateToSend->addMonths($diff);
            }else if($period === 'year'){
                $diff = $date->diffInYears($dateToSend) + 1;
                $fechaIni->subYears(1);
                $newDateToSend->addYears($diff);
            }else {
                $diff = $date->diffInDays($dateToSend) + 1;
                $fechaIni->subDays(1);
                $newDateToSend->addDays($diff);
            }

            $requestTmp = new \Illuminate\Http\Request();
            $requestTmp->replace(['reporteId' => $report->id,'fechaIni' => $fechaIni, 'fechaFin' => $date]);

            $tmp = $this->Generar($requestTmp, true);
            $tmp = json_decode($tmp, true);

            if (empty($tmp['status'])) {
                return $this->ResponseError('REP-421', 'Error generar');
            }

            $destino = $mailconfig['destino'] ?? '';
            if(empty($destino)) continue;
            $asunto = $mailconfig['asunto'] ?? '';
            $config = $mailconfig['mailgun'] ?? [];
            $contenido = $mailconfig['salidasEmail'] ?? '';
            $attachments = [0 => ['url' => $tmp['data']['url'], 'name' => $report->nombre, 'ext'=> 'xlsx']];

            $data = [
                'destino' => $destino,
                'asunto' => $asunto,
                'config' => $config,
                'attachments' => $attachments,
                'contenido' => $contenido,
            ];

            $email = $this->sendEmail($data);
            $report->dateToSend = $newDateToSend->toDateString();
            $report->save();
        }
        return $this->ResponseSuccess('Reportes programados generados con exito');
    }

    public function sendEmail($data) {
        //data
        $destino = $data['destino']?? false;
        $asunto = $data['asunto']?? false;
        $config = $data['config'] ?? [];
        $attachments = $data['attachments'] ?? false;
        $contenido = $data['contenido'] ?? '';

        $attachmentsSend = [];
        if ($attachments) {
            foreach ($attachments as $attach) {
                $s3_file = file_get_contents($attach['url']);
                $attachmentsSend[] = ['fileContent' => $s3_file, 'filename' => ($attach['name'] ?? 'Sin nombre') . '.' . $attach['ext']];
            }
        }

        try {
            $mg = Mailgun::create($config['apiKey'] ?? ''); // For US servers
            $email = $mg->messages()->send($config['domain'] ?? '', [
                'from' => $config['from'] ?? '',
                'to' => $destino ?? '',
                'subject' => $asunto ?? '',
                'html' => $contenido,
                'attachment' => $attachmentsSend
            ]);
            return $this->ResponseSuccess('Enviado con exito');
        } catch (HttpClientException $e) {
            return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
        }
    }
}
