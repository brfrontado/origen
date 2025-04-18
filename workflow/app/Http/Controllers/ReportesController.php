<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Extra\Tools;
use App\Models\Flujos;
use App\Models\Marca;
use App\Models\Productos;
use App\Models\Reporte;
use App\Models\Panel;
use App\Models\UserPanel;
use App\Models\ReporteProgramado;
use App\Models\ReporteProgramadoDetalle;
use App\Models\CotizacionTranscribe;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;


class ReportesController extends Controller {

    use Response;

    public function Listado(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/generar'])) return $AC->NoAccess();

        $item = Reporte::where('marcaId', SSO_BRAND_ID)->get();
        return $this->ResponseSuccess('Reportes obtenidos con éxito', $item);
    }

    public function ListadoFiltrado(Request $request) {
        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/generar'])) return $AC->NoAccess();

        $item = Reporte::where('marcaId', SSO_BRAND_ID)->get();
        foreach ($item as $reporte) {
            $reporte->config = @json_decode($reporte->config, true);
            if ($reporte->config['rolUsuario'] === 'Super Admin') {
                $filteredItem = $item->filter(function ($value) use ($reporte) {
                    return $value->nombre === $reporte->nombre;
                });
                return $this->ResponseSuccess('Reportes obtenidos con éxito', $filteredItem->values());
            }
        }
    }

    public function ListadoFlujos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

        $item = Productos::where('marcaId', '=', SSO_BRAND_ID)->get();
        $item->makeHidden(['descripcion', 'token', 'extraData', 'imagenData']);
        return $this->ResponseSuccess('Reportes obtenidos con éxito', $item);
    }

    public function NodosCampos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/list-fields'])) return $AC->NoAccess();

        $productosTmp = $request->get('productos');

        // voy a traer los productos
        $productos = Productos::where('marcaId', '=', SSO_BRAND_ID)->whereIn('id', $productosTmp)->get();

        $allFields = [];
        $arrResponse = [];
        foreach ($productos as $producto) {

            $flujo = $producto->flujo->first();
            if (empty($flujo)) {
                return $this->ResponseError('RPT-001', 'Flujo no válido');
            }

            $flujoConfig = @json_decode($flujo->flujo_config, true);
            if (!is_array($flujoConfig)) {
                return $this->ResponseError('RPT-002', 'Error al interpretar flujo, por favor, contacte a su administrador');
            }

            // traer los campos de ws automáticos, esto impactará el performance, hay que agregar una ventana de configuración para WS
            /*$strQueryFull = "SELECT CD.campo
                            FROM cotizaciones AS C
                            JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                            WHERE C.productoId = {$producto->id}
                            AND CD.valorLong IS NOT NULL
                            GROUP BY CD.campo";

            $queryTmp = DB::select(DB::raw($strQueryFull));*/

            foreach ($flujoConfig['nodes'] as $nodo) {

                //$resumen
                if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                    foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                        foreach ($seccion['campos'] as $keyCampoTmp => $campo) {

                            if (empty($campo['id'])) continue;

                            //$keyCampo = $campo['id'] ;
                            $keyCampo = $producto->id . '||' . $campo['id'];
                            $allFields[$keyCampo]['id'] = $keyCampo;
                            $allFields[$keyCampo]['label'] = $campo['nombre'];
                            $allFields[$keyCampo]['pr'] = $producto->nombreProducto;
                            $allFields[$keyCampo]['nodo'] = strip_tags($nodo['label']);
                        }
                    }
                }
            }

            /*foreach ($queryTmp as $tmp) {
                if (!empty($tmp->campo) && !isset($allFields[$tmp->campo])) {
                    //$keyCampo = $tmp->campo;
                    $keyCampo = $producto->id.'||'.$campo['id'] ;
                    $allFields[$keyCampo]['id'] = $keyCampo;
                    $allFields[$keyCampo]['label'] = 'Campo automatizado';
                    $allFields[$keyCampo]['pr'] = $producto->nombreProducto;
                    $allFields[$keyCampo]['nodo'] = $tmp->campo;
                }
            }*/
            //die();

            //dd($flujoConfig);
        }

        return $this->ResponseSuccess('Campos obtenidos con éxito', $allFields);
    }

    public function NodosCamposCopy(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/list-fields'])) return $AC->NoAccess();

        $productosTmp = $request->get('productos');

        // voy a traer los productos
        $productos = Productos::where('marcaId', '=', SSO_BRAND_ID)->whereIn('id', $productosTmp)->get();

        $allFields = [];
        $arrResponse = [];
        foreach ($productos as $producto) {

            $flujo = $producto->flujo->first();
            if (empty($flujo)) {
                return $this->ResponseError('RPT-001', 'Flujo no válido');
            }

            $flujoConfig = @json_decode($flujo->flujo_config, true);
            if (!is_array($flujoConfig)) {
                return $this->ResponseError('RPT-002', 'Error al interpretar flujo, por favor, contacte a su administrador');
            }

            // traer los campos de ws automáticos, esto impactará el performance, hay que agregar una ventana de configuración para WS
            $dateFilter = Carbon::now()->subMonths(2)->format('Y-m-d H:i:s');
            $strQueryFull = "SELECT TMP.campo FROM (
                                SELECT CD.campo
                                FROM cotizaciones AS C
                                    JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                                WHERE
                                    C.deletedTask = 0
                                    AND C.productoId = {$producto->id}
                                    AND C.dateCreated <= '{$dateFilter}'
                                    AND CD.tipo IS NULL
                                    AND CD.valorLong IS NOT NULL
                                          ) AS TMP
                            GROUP BY TMP.campo";

            /*$strQueryFull = "SELECT CD.campo
                            FROM cotizaciones AS C
                            JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                            WHERE
                            C.productoId = {$producto->id}
                            AND CD.tipo IS NULL
                            AND CD.valorLong IS NOT NULL
                            GROUP BY CD.campo";*/

            $queryTmp = DB::select(DB::raw($strQueryFull));

            foreach ($flujoConfig['nodes'] as $nodo) {

                //$resumen
                if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                    foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                        foreach ($seccion['campos'] as $keyCampoTmp => $campo) {

                            if (empty($campo['id'])) continue;

                            $keyCampo = $campo['id'];
                            //$keyCampo = $producto->id.'||'.$campo['id'] ;
                            $allFields[$keyCampo]['id'] = $keyCampo;
                            $allFields[$keyCampo]['label'] = $campo['nombre'];
                            $allFields[$keyCampo]['pr'] = $producto->nombreProducto;
                            $allFields[$keyCampo]['nodo'] = strip_tags($nodo['label']);
                        }
                    }
                }
            }

            foreach ($queryTmp as $tmp) {
                if (!empty($tmp->campo) && !isset($allFields[$tmp->campo])) {
                    $keyCampo = $tmp->campo;
                    //$keyCampo = $producto->id.'||'.$campo['id'] ;
                    $allFields[$keyCampo]['id'] = $keyCampo;
                    $allFields[$keyCampo]['label'] = 'Campo automatizado';
                    $allFields[$keyCampo]['pr'] = $producto->nombreProducto;
                    $allFields[$keyCampo]['nodo'] = $tmp->campo;
                }
            }
            //die();

            //dd($flujoConfig);
        }

        return $this->ResponseSuccess('Campos obtenidos con éxito', $allFields);
    }

    public function GetReporte(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

        $id = $request->get('id');

        $item = Reporte::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('RPT-014', 'Error al obtener reporte');
        }

        if ($item->marcaId !== SSO_BRAND_ID) {
            return $this->ResponseError('RPT-0132', 'Flujo inválido');
        }

        $item->c = @json_decode($item->config);
        $item->makeHidden(['config']);

        return $this->ResponseSuccess('Reporte obtenido con éxito', $item);
    }

    public function Generar(Request $request) {
        
    $reporteId = $request->get('reporteId');

    $reporte = Reporte::where('id', $reporteId)->first();
    $config = @json_decode($reporte->config, true);
    $rolUsuario = $config['rolUsuario'];

    if ($rolUsuario === 'Super Admin'|| $rolUsuario === 'VGA') {
        // Código para usuarios administradores
        

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/generar'])) return $AC->NoAccess();

        $id = $request->get('reporteId');

        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');
        $fechaIni = Tools::dateConvertToDB($fechaIni, true);
        $fechaFin = Tools::dateConvertToDB($fechaFin, false, true);

        $item = Reporte::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('RPT-015', 'Error al obtener reporte');
        }

        if ($item->marcaId !== SSO_BRAND_ID) {
            return $this->ResponseError('RPT-0132', 'Flujo inválido');
        }

        $config = @json_decode($item->config, true);


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
        foreach ($config['c'] as $conf) {
            $campos .= ($campos !== '') ? ", '{$conf['c']}'" : "'{$conf['c']}'";
        }

        $prodArr = [];
        $prod = '';
        foreach ($config['p'] as $conf) {
            $conf = intval($conf);
            $prodArr[] = $conf;
            $prod .= ($prod !== '') ? ", {$conf}" : "{$conf}";
        }

        $strQueryFull = "SELECT C.id, C.dateCreated, C.dateExpire, C.productoId, C.usuarioId, C.usuarioIdAsignado, CD.campo, CD.valorLong, P.nombreProducto
                        FROM cotizaciones AS C
                        JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                        JOIN productos AS P ON C.productoId = P.id
                        WHERE 
                            C.productoId IN ($prod)
                            AND CD.campo IN ({$campos})
                            AND C.dateCreated >= '" . $fechaIni . "'
                            AND C.dateCreated <= '" . $fechaFin . "'
                            AND CD.valorLong IS NOT NULL
                        ";


// información de flujos
        $allLabels = [];
        $flujos = Flujos::whereIn('productoId', $prodArr)->where('activo', 1)->get();

        foreach ($flujos as $flujo) {
            $flujoTmp = @json_decode($flujo->flujo_config, true);

            foreach ($flujoTmp['nodes'] as $nodo) {

                if (empty($nodo['typeObject'])) continue;

                // todos los campos
                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                    foreach ($seccion['campos'] as $campo) {
                        $allLabels[$campo['id']] = trim($campo['nombre']);
                    }
                }
            }
        }
        /*print($strQueryFull);
        die();*/

        $queryTmp = DB::select(DB::raw($strQueryFull));

        $datosFinal = [];
        $datosFinal[] = [
            'Identificador',
            'Fecha creación',
            'Fecha expiración',
            'Producto',
            /*'Usuario Asignado',
            'Usuario Creador',*/
        ];

        $campos = [];
        $data = [];


        foreach ($queryTmp as $tmp) {
            $campoTmp = $tmp->campo;
            if (isset($allLabels[$tmp->campo])) {
                $campoTmp = $allLabels[$tmp->campo];
            }
            $campos[$campoTmp] = $campoTmp;
            $data[$tmp->id][$campoTmp] = $tmp->valorLong;
        }

        foreach ($campos as $campo) {
            $datosFinal[0][] = $campo;
        }

        foreach ($queryTmp as $tmp) {
            $datosFinal[$tmp->id]['id'] = $tmp->id;
            $datosFinal[$tmp->id]['dateCreated'] = Tools::dateConvertFromDB($tmp->dateCreated);
            $datosFinal[$tmp->id]['dateExpire'] = $tmp->dateExpire;
            $datosFinal[$tmp->id]['nombreProducto'] = $tmp->nombreProducto;
            foreach ($campos as $campo) {
                $datosFinal[$tmp->id][$campo] = (!empty($data[$tmp->id][$campo]) ? $data[$tmp->id][$campo] : '');
            }
        }

        //dd($datosFinal);

        $spreadsheet = new Spreadsheet();

        $spreadsheet
            ->getProperties()
            ->setCreator("GastosMedicos-ElRoble")
            ->setLastModifiedBy('Automator') // última vez modificado por
            ->setTitle('Reporte de ' . $item->nombre)
            ->setDescription('Reporte');

        // first sheet
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Hoja 1");
        $sheet->fromArray($datosFinal, NULL, 'A1');

        $writer = new Xlsx($spreadsheet);
        $fileNameHash = md5(uniqid());
        $tmpPath = storage_path("tmp/{$fileNameHash}.xlsx");
        $writer->save($tmpPath);

        $disk = Storage::disk('s3');
        $path = $disk->putFileAs("/tmp/files", $tmpPath, "{$fileNameHash}.xlsx");
        $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

        return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $temporarySignedUrl]);
    

        
    } elseif ($rolUsuario === 'usuario') {
        // Código para usuarios normales
        echo "Bienvenido, usuario.";
    } else {
        // Código para cuando no hay rol definido
        // return $this->ResponseError('RPT-014', 'Error al obtener reporte, error de configuracion de rol');
    }
    }

    /*public function ReporteNodosCampos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/list-fields'])) return $AC->NoAccess();

        $productosTmp = $request->get('productos');

        // voy a traer los productos
        $productos = Productos::where('marcaId', '=', SSO_BRAND_ID)->whereIn('id', $productosTmp)->get();

        $allFields = [];
        $arrResponse = [];
        foreach ($productos as $producto) {

            $flujo = $producto->flujo->first();
            if (empty($flujo)) {
                return $this->ResponseError('RPT-001', 'Flujo no válido');
            }

            $flujoConfig = @json_decode($flujo->flujo_config, true);
            if (!is_array($flujoConfig)) {
                return $this->ResponseError('RPT-002', 'Error al interpretar flujo, por favor, contacte a su administrador');
            }

            // traer los campos de ws automáticos, esto impactará el performance, hay que agregar una ventana de configuración para WS
            $strQueryFull = "SELECT CD.campo
                            FROM cotizaciones AS C
                            JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                            WHERE C.productoId = {$producto->id}
                            AND CD.valorLong IS NOT NULL
                            GROUP BY CD.campo";

            $queryTmp = DB::select(DB::raw($strQueryFull));

            foreach ($flujoConfig['nodes'] as $nodo) {

                //$resumen
                if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                    foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                        foreach ($seccion['campos'] as $keyCampoTmp => $campo) {

                            if (empty($campo['id'])) continue;

                            //$keyCampo = $campo['id'] ;
                            $keyCampo = $producto->id.'||'.$campo['id'] ;
                            $allFields[$keyCampo]['id'] = $keyCampo;
                            $allFields[$keyCampo]['label'] = $campo['nombre'];
                            $allFields[$keyCampo]['pr'] = $producto->nombreProducto;
                            $allFields[$keyCampo]['nodo'] = strip_tags($nodo['label']);
                        }
                    }
                }
            }

            foreach ($queryTmp as $tmp) {
                if (!empty($tmp->campo) && !isset($allFields[$tmp->campo])) {
                    //$keyCampo = $tmp->campo;
                    $keyCampo = $producto->id.'||'.$campo['id'] ;
                    $allFields[$keyCampo]['id'] = $keyCampo;
                    $allFields[$keyCampo]['label'] = 'Campo automatizado';
                    $allFields[$keyCampo]['pr'] = $producto->nombreProducto;
                    $allFields[$keyCampo]['nodo'] = $tmp->campo;
                }
            }
            //die();

            //dd($flujoConfig);
        }

        return $this->ResponseSuccess('Campos obtenidos con éxito', $allFields);
    }*/

    public function Save(Request $request) {

    $AC = new AuthController();
    if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

    $id = $request->get('id');
    $nombre = $request->get('nombre');
    $activo = $request->get('activo');
    $producto = $request->get('flujos');
    $campos = $request->get('campos');
    $rolUsuario = $request->get('rolUsuario'); // 👈 Aquí obtenemos el rol
    
    $item = Reporte::where('id', $id)->first();

    if (empty($item)) {
        $item = new Reporte();
    } else {
        if ($item->marcaId !== SSO_BRAND_ID) {
            return $this->ResponseError('RPT-0135', 'Reporte inválido');
        }
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

    $arrConfig['p'] = $producto;
    $arrConfig['rolUsuario'] = $rolUsuario; // 👈 Lo agregamos al config

    $item->id = intval($id);
    $item->nombre = strip_tags($nombre);
    $item->marcaId = SSO_BRAND_ID;
    $item->activo = intval($activo);
    $item->config = @json_encode($arrConfig) ?? null;
    $item->save();

    return $this->ResponseSuccess('Plantilla guardada con éxito', ['id' => $item->id]);
}
    public function MisConsumos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/mis-consumos'])) return $AC->NoAccess();

        $id = $request->get('reporteId');

        $descargar = $request->get('descargar') ?? false;
        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');
        $typeRpt = $request->get('typeRpt');
        $fechaIni = Tools::dateConvertToDB($fechaIni, true);
        $fechaFin = Tools::dateConvertToDB($fechaFin, false, true);


        $marcaId = SSO_BRAND_ID;
        /*$strQueryFull = "SELECT C.id, C.dateCreated, C.dateExpire, C.productoId, C.usuarioId, C.usuarioIdAsignado, CD.campo, CD.valorLong, P.nombreProducto
                        FROM cotizaciones AS C
                        JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                        JOIN productos AS P ON C.productoId = P.id
                        WHERE
                            C.marcaId = '{$marcaId}'
                            #AND CD.useForSearch = 1
                            AND C.dateCreated >= '" . $fechaIni . "'
                            AND C.dateCreated <= '" . $fechaFin . "'
                            AND CD.valorLong IS NOT NULL
                        ORDER BY
                            C.productoId,
                            C.id,
                            CD.campo";*/

        $filterVacias = '';

        if ($typeRpt === 'nonvac') {
            $filterVacias = ' AND CD.valorLong IS NOT NULL';
        }

        $strQueryFull = "SELECT C.id, C.dateCreated, C.dateExpire, C.productoId, C.usuarioId, C.usuarioIdAsignado, CD.campo, CD.valorLong, P.nombreProducto
                        FROM cotizaciones AS C
                        LEFT JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId AND CD.useForSearch = 1 AND CD.valorLong IS NOT NULL
                        JOIN productos AS P ON C.productoId = P.id
                        WHERE 
                            C.marcaId = '{$marcaId}'
                            #AND CD.useForSearch = 1
                            AND C.dateCreated >= '" . $fechaIni . "'
                            AND C.dateCreated <= '" . $fechaFin . "'
                            {$filterVacias}
                        ORDER BY
                            C.productoId,
                            C.id,
                            CD.campo";


        /*print($strQueryFull);
        die();*/

        $queryTmp = DB::select(DB::raw($strQueryFull));

        $datosFinal = [];
        $datosFinal[] = [
            'Identificador',
            'Fecha creación',
            'Fecha expiración',
            'Producto',
            /*'Usuario Asignado',
            'Usuario Creador',*/
        ];

        $campos = [];
        $data = [];
        $conteoPorFlujo = [];
        $productosName = [];
        $conteoFinal = [];

        foreach ($queryTmp as $tmp) {
            $campos[$tmp->campo] = $tmp->campo;
            $data[$tmp->id][$tmp->campo] = $tmp->valorLong;
        }

        foreach ($campos as $campo) {
            $datosFinal[0][] = $campo;
        }

        foreach ($queryTmp as $tmp) {
            $datosFinal[$tmp->id]['id'] = $tmp->id;
            $datosFinal[$tmp->id]['dateCreated'] = Tools::dateConvertFromDB($tmp->dateCreated);
            $datosFinal[$tmp->id]['dateExpire'] = $tmp->dateExpire;
            $datosFinal[$tmp->id]['nombreProducto'] = $tmp->nombreProducto;
            foreach ($campos as $campo) {
                $datosFinal[$tmp->id][$campo] = (!empty($data[$tmp->id][$campo]) ? $data[$tmp->id][$campo] : '');
            }

            $conteoPorFlujo[$tmp->productoId][$tmp->id] = 1;
            $productosName[$tmp->productoId] = $tmp->nombreProducto;
        }

        // conteoFinal
        foreach ($conteoPorFlujo as $productoId => $rows) {
            $productoName = (isset($productosName[$productoId]) ? $productosName[$productoId] : 'Producto sin nombre');
            $conteoFinal[$productoId]['n'] = $productoName;
            $conteoFinal[$productoId]['c'] = count($rows);
        }

        /*var_dump($datosFinal);
        die();*/

        // consumos geoposicionamiento
        /*$strQueryFull = "SELECT SC.id, SC.cotizacionId, SC.usuarioId,
                        FROM systemConsumo AS SC
                        LEFT JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId AND CD.useForSearch = 1 AND CD.valorLong IS NOT NULL
                        JOIN productos AS P ON C.productoId = P.id
                        WHERE
                            C.marcaId = '{$marcaId}'
                            #AND CD.useForSearch = 1
                            AND C.dateCreated >= '" . $fechaIni . "'
                            AND C.dateCreated <= '" . $fechaFin . "'
                            #AND CD.valorLong IS NOT NULL
                        ORDER BY
                            C.productoId,
                            C.id,
                            CD.campo";*/

        // reporte de transcripciones
        $transcripciones = CotizacionTranscribe::where('marcaId', $marcaId)->where([['createdAt', '>=', $fechaIni], ['createdAt', '<=', $fechaFin]])->whereNotNull('contenido')->get();
        $transcripcionesTotal = count($transcripciones);

        if ($descargar) {
            $spreadsheet = new Spreadsheet();

            $spreadsheet
                ->getProperties()
                ->setCreator("CloudWorkflow")
                ->setLastModifiedBy('Automator') // última vez modificado por
                ->setTitle('Reporte de consumo')
                ->setDescription('Reporte');

            // first sheet
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Hoja 1");
            $sheet->fromArray($datosFinal, NULL, 'A1');

            $writer = new Xlsx($spreadsheet);
            $fileNameHash = md5(uniqid());
            $tmpPath = storage_path("tmp/{$fileNameHash}.xlsx");
            $writer->save($tmpPath);

            $disk = Storage::disk('s3');
            $path = $disk->putFileAs("/tmp/files", $tmpPath, "{$fileNameHash}.xlsx");
            $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

            return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $temporarySignedUrl]);
        }
        else {

            $conteo = [
                'total' => count($datosFinal) - 1, // por el header
                'flujo' => $conteoFinal, // por el header
                'transcripciones' => $transcripcionesTotal, // por el header
            ];

            return $this->ResponseSuccess('Reporte generado con éxito', $conteo);
        }
    }

    public function GetProgrammedList(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/download-docs'])) return $AC->NoAccess();

        $programados = ReporteProgramado::where('marcaId', SSO_BRAND_ID)->where('usuarioId', SSO_USER_ID)->with('producto')->get();

        $arrData = [];
        foreach ($programados as $tmp) {
            $tipo = '';
            if ($tmp->tipo === 'gen') {
                $tipo = 'Solo archivos generados';
            }
            else if ($tmp->tipo === 'files') {
                $tipo = 'Solo archivos subidos';
            }
            else if ($tmp->tipo === 'gen_files') {
                $tipo = 'Archivos generados y subidos';
            }

            $arrData[] = [
                'id' => $tmp->id,
                'producto' => $tmp->producto->nombreProducto,
                'tipo' => $tipo,
                'segmentacion' => $tmp->segmentacionUno,
                'orden' => $tmp->ordenUno,
                'fechaIni' => Carbon::parse($tmp->fechaIni)->format('d-m-Y'),
                'fechaFin' => Carbon::parse($tmp->fechaFin)->format('d-m-Y'),
                'totalRows' => $tmp->totalRows,
                'estado' => $tmp->estado . " ({$tmp->progreso}%)",
                'pg' => $tmp->progreso,
            ];
        }

        return $this->ResponseSuccess('Descargas programadas obtenidas con éxito', $arrData);
    }

    public function GetProgrammedDetail(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/download-docs'])) return $AC->NoAccess();

        $id = $request->get('id');

        $programados = ReporteProgramado::where('marcaId', SSO_BRAND_ID)->where('usuarioId', SSO_USER_ID)->where('id', $id)->with('producto')->first();

        if (empty($programados)) {
            return $this->ResponseError('RPT-50F', 'Reporte programado inválido');
        }

        $programados = ReporteProgramadoDetalle::where('reporteProgramadoId', $programados->id)->get(['cotizacionId', 'isProcessed', 'hasError', 'id']);

        return $this->ResponseSuccess('Detalle obtenido con éxito', $programados);
    }

    // descargas programadas

    public function ProgramarDescargaDocumentos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/download-docs'])) return $AC->NoAccess();

        $id = $request->get('productoId');
        $reporteSegmentacion = $request->get('reporteSegmentacion');
        $reporteOrden = $request->get('reporteOrden');
        $typeDownload = $request->get('typeDownload');

        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');
        $fechaIni = Tools::dateConvertToDB($fechaIni, true);
        $fechaFin = Tools::dateConvertToDB($fechaFin, false, true);

        $id = intval($id);
        $producto = Productos::find($id);

        if (empty($producto)) {
            return $this->ResponseError('RPT-017', 'El flujo seleccionado es inválido');
        }

        $brand = SSO_BRAND_ID;

        $strQueryFull = "SELECT C.id
                        FROM cotizaciones AS C
                            JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                        WHERE 
                        C.marcaId = '{$brand}'
                        AND C.productoId = '{$id}'
                        AND C.deletedTask = 0
                        AND C.dateUpdated >= '" . $fechaIni . "'
                        AND C.dateUpdated <= '" . $fechaFin . "'
                        AND (CD.isFile = 1 OR CD.fromSalida = 1 OR CD.useForSearch = 1)
                        GROUP BY C.id";

        $queryTmp = DB::select(DB::raw($strQueryFull));

        $conteo = count($queryTmp);
        if ($conteo === 0) {
            return $this->ResponseError('RPT-54F', 'No existen tareas que coincidan con los filtros, no se programará la descarga');
        }

        $rp = new ReporteProgramado();
        $rp->marcaId = SSO_BRAND_ID;
        $rp->usuarioId = SSO_USER_ID;
        $rp->productoId = $producto->id;
        $rp->segmentacionUno = $reporteSegmentacion;
        $rp->ordenUno = $reporteOrden;
        $rp->tipo = $typeDownload;
        $rp->totalRows = $conteo;
        $rp->fechaIni = $fechaIni;
        $rp->fechaFin = $fechaFin;
        $rp->estado = 'Creando';
        $rp->save();

        // detalle
        foreach ($queryTmp as $item) {
            $tmpRpt = new ReporteProgramadoDetalle();
            $tmpRpt->reporteProgramadoId = $rp->id;
            $tmpRpt->cotizacionId = $item->id;
            $tmpRpt->save();
        }

        $rp->estado = 'En progreso';
        $rp->save();

        return $this->ResponseSuccess('Descarga programada con éxito');
    }

    public function EliminarDescargaProgramada(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/download-docs'])) return $AC->NoAccess();

        $id = $request->get('id');

        $programado = ReporteProgramado::where('marcaId', SSO_BRAND_ID)->where('id', $id)->first();

        if (empty($programado)) {
            return $this->ResponseError('PRDW-478', 'Descarga programada inválida');
        }
        else {
            $this->removeDir($programado->workingPath);
            $this->removeDir("{$programado->workingPath}.zip");
            $programado->delete();

            $marca = Marca::find($programado->marcaId);

            // elimino de s3
            $tmpPath = "{$marca->token}/_fdownload/{$programado->downloadName}.zip";
            @Storage::disk('s3')->delete($tmpPath);

            return $this->ResponseSuccess('Descarga programada con éxito');
        }
    }

    private function removeDir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . "/" . $object) == "dir")
                        $this->removeDir($dir . "/" . $object);
                    else unlink($dir . "/" . $object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    public function Delete(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        $item = Reporte::where('id', $id)->where('marcaId', SSO_BRAND_ID)->first();

        if (!empty($item)) {
            $item->delete();
            return $this->ResponseSuccess('Reporte eliminado con éxito');
        }
        else {
            return $this->ResponseError('RPT-0134', 'Reporte inválido');
        }
    }

    public function ProgramarDescarga(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['reportes/download-docs'])) return $AC->NoAccess();

        $id = $request->get('id');
        $programado = ReporteProgramado::where('marcaId', SSO_BRAND_ID)->where('id', $id)->first();

        if (empty($programado)) {
            return $this->ResponseError('RPT-847', 'Descarga programada inválida');
        }

        $marca = Marca::find($programado->marcaId);
        $tmpPath = "{$marca->token}/_fdownload/{$programado->downloadName}.zip";
        //var_dump($tmpPath);

        $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($tmpPath, now()->addMinutes(10));
        return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $temporarySignedUrl]);
    }

    public function processDownload() {

        ini_set('memory_limit', '1024M');

        $programados = ReporteProgramado::where('progreso', '<', '100')->where('estado', '=', 'En progreso')->limit(5)->get();

        foreach ($programados as $programado) {

            // detalle
            $detalle = ReporteProgramadoDetalle::where('reporteProgramadoId', $programado->id)->where('isProcessed', 0)->limit(30)->get();

            $conteoRows = count($detalle);
            if ($conteoRows > 0) {

                foreach ($detalle as $item) {
                    // descarga documentos a local
                    $this->DescargarDocumentosById($item->cotizacionId, $programado);

                    // coloca procesado
                    $item->isProcessed = 1;
                    $item->save();
                }

                $conteoTmp = ReporteProgramadoDetalle::where('reporteProgramadoId', $programado->id)->where('isProcessed', 1)->get();
                $progreso = (count($conteoTmp) * 100) / $programado->totalRows;
                $programado->progreso = ($progreso >= 100) ? 100 : floor($progreso);
                $programado->save();
            }

            if ($programado->progreso === 100) {
                // terminó, hay que crear zip

                if (!file_exists($programado->workingPath)) {
                    continue;
                }

                $filesToDelete = [];
                $zip = new ZipArchive();
                $zip->open("{$programado->workingPath}.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);

                // Create recursive directory iterator
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($programado->workingPath),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $name => $file) {
                    // Skip directories (they would be added automatically)
                    if (!$file->isDir()) {
                        // Get real and relative path for current file
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($programado->workingPath) + 1);

                        // Add current file to archive
                        $zip->addFile($filePath, $relativePath);
                    }
                }

                // Zip archive will be created only after closing object
                $zip->close();
                $this->removeDir($programado->workingPath);

                //sube zip a s3
                if (file_exists("{$programado->workingPath}.zip")) {
                    $bytes = filesize("{$programado->workingPath}.zip");
                    $fileSize = $bytes / 1048576;
                    $programado->progreso = 100;
                    $programado->estado = 'Finalizado';
                    $programado->fileSizeMb = $fileSize;
                    $programado->save();

                    $marca = Marca::find($programado->marcaId);
                    $tmpPath = "{$marca->token}/_fdownload/{$programado->downloadName}.zip";
                    $disk = Storage::disk('s3');
                    $disk->put($tmpPath, file_get_contents("{$programado->workingPath}.zip"));
                    unlink("{$programado->workingPath}.zip");
                }
            }
        }

        return $this->ResponseSuccess('Proceso realizado con éxito');
    }

    public function DescargarDocumentosById($cotizacionId, $programado) {

        ini_set('memory_limit', '1024M');

        $strQueryFull = "SELECT C.id, C.dateCreated, CD.campo, CD.valorLong, CD.isFile, CD.fromSalida, CD.useForSearch
                        FROM cotizaciones AS C
                            JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId
                        WHERE 
                            C.id = '{$cotizacionId}'";

        $queryTmp = DB::select(DB::raw($strQueryFull));

        if (empty($queryTmp)) {
            $reporteTmp = ReporteProgramadoDetalle::where('reporteProgramadoId', $programado->id)->where('cotizacionId', $cotizacionId)->first();
            $reporteTmp->hasError = true;
            $reporteTmp->save();
            return false;
        }

        $producto = Productos::find($programado->productoId);

        if (empty($producto)) {
            return $this->ResponseError('RPT-017', 'Flujo inválido');
        }

        $segmentacionValue = '';

        $arrData = [];
        foreach ($queryTmp as $row) {

            if (!empty($programado->segmentacionUno) && $programado->segmentacionUno === $row->campo) {
                $segmentacionValue = substr($row->valorLong, 0, 56);
            }

            // orden
            if (!empty($programado->ordenUno) && $row->campo === $programado->ordenUno) {
                if (empty($arrData[$row->id]['s'])) {
                    $arrData[$row->id]['s'] = '';
                }
                $arrData[$row->id]['s'] .= "{$row->valorLong}_";
            }

            if (!empty($row->useForSearch)) {
                if (empty($arrData[$row->id]['s'])) {
                    $arrData[$row->id]['s'] = '';
                }
                $arrData[$row->id]['s'] .= "{$row->valorLong}_{$row->campo}_";
            }

            if (!empty($row->fromSalida)) {
                $arrData[$row->id]['g'][] = [
                    'c' => $row->campo,
                    'v' => $row->valorLong,
                ];
            }
            else if (!empty($row->isFile)) {
                $arrData[$row->id]['f'][] = [
                    'c' => $row->campo,
                    'v' => $row->valorLong,
                ];
            }
        }

        $reportDownload = "{$programado->id}_{$producto->id}_{$producto->nombreProducto}";
        $reportDownload = str_replace(' ', '_', $reportDownload);
        $path = storage_path("tmp/{$reportDownload}");

        // guarda el working path
        if (empty($programado->workingPath)) {
            $programado->downloadName = $reportDownload;
            $programado->workingPath = $path;
            $programado->save();
        }

        // valida si existe el folder
        if (!file_exists($path)) {
            mkdir($path);
        }

        if (!empty($segmentacionValue)) {
            $path = "{$path}/{$segmentacionValue}";
            if (!file_exists($path)) {
                mkdir($path);
            }
        }

        $includeFilesGen = ($programado->tipo === 'gen' || $programado->tipo === 'gen_files');
        $includeFilesUp = ($programado->tipo === 'files' || $programado->tipo === 'gen_files');

        // descarga los datos
        foreach ($arrData as $id => $row) {

            if (empty($row['s'])) {
                $row['s'] = "sin_nombre_{$id}";

                $reporteTmp = ReporteProgramadoDetalle::where('reporteProgramadoId', $programado->id)->where('cotizacionId', $cotizacionId)->first();
                $reporteTmp->hasError = true;
                $reporteTmp->save();
                break;
            }

            $row['s'] = trim($row['s'], '_');
            $row['s'] = "{$row['s']}_no_{$cotizacionId}";

            // valida directorio
            if (!file_exists("{$path}/{$row['s']}")) {
                mkdir("{$path}/{$row['s']}");
            }

            // descargo archivos
            if (!empty($row['f']) && $includeFilesUp) {
                foreach ($row['f'] as $key => $data) {
                    if (empty($data['v'])) {
                        continue;
                    }
                    $ext = pathinfo($data['v'], PATHINFO_EXTENSION);
                    $s3_file = Storage::disk('s3')->get($data['v']);
                    $fileName = "{$path}/{$row['s']}/{$data['c']}_{$key}.{$ext}";
                    file_put_contents($fileName, $s3_file);
                    if (!file_exists($fileName)) {
                        $reporteTmp = ReporteProgramadoDetalle::where('reporteProgramadoId', $programado->id)->where('cotizacionId', $cotizacionId)->first();
                        $reporteTmp->hasError = true;
                        $reporteTmp->save();
                    }
                }
            }

            // descargo generados
            if (!empty($row['g']) && $includeFilesGen) {
                foreach ($row['g'] as $key => $data) {
                    if (empty($data['v'])) {
                        continue;
                    }
                    $ext = pathinfo($data['v'], PATHINFO_EXTENSION);
                    $s3_file = Storage::disk('s3')->get($data['v']);
                    $fileName = "{$path}/{$row['s']}/{$data['c']}_{$key}.{$ext}";
                    file_put_contents($fileName, $s3_file);
                    if (!file_exists($fileName)) {
                        $reporteTmp = ReporteProgramadoDetalle::where('reporteProgramadoId', $programado->id)->where('cotizacionId', $cotizacionId)->first();
                        $reporteTmp->hasError = true;
                        $reporteTmp->save();
                    }
                }
            }
        }
    }

    // paneles
    public function PanelListado(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['paneles/config'])) return $AC->NoAccess();

        $item = Panel::where('marcaId', SSO_BRAND_ID)->get();
        return $this->ResponseSuccess('Paneles obtenidos con éxito', $item);
    }

    public function PanelListadoWithAccess(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['paneles/listado'])) return $AC->NoAccess();

        $item = DB::table('panels')->join('userPanelsAccess', 'panels.id', '=', 'userPanelsAccess.panelId')
            ->where('marcaId', SSO_BRAND_ID)
            ->where('userPanelsAccess.usuarioId', SSO_USER_ID)
            ->get(['panels.id', 'panels.nombre']);
        return $this->ResponseSuccess('Paneles obtenidos con éxito', $item);
    }

    public function GetPanel(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['paneles/config'])) return $AC->NoAccess();

        $id = $request->get('id');

        $item = Panel::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('RPT-014', 'Error al obtener reporte');
        }

        if ($item->marcaId !== SSO_BRAND_ID) {
            return $this->ResponseError('RPT-0132', 'Flujo inválido');
        }

        $acceso = [];
        foreach ($item->access as $accesoP) {
            $acceso[] = $accesoP->usuarioId;
        }

        $item->makeHidden(['access']);
        $item->users = $acceso;


        return $this->ResponseSuccess('Panel obtenido con éxito', $item);
    }

    public function GetPanelWithAccess(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['paneles/listado'])) return $AC->NoAccess();

        $id = $request->get('id');

        $item = DB::table('panels')->join('userPanelsAccess', 'panels.id', '=', 'userPanelsAccess.panelId')
            ->where('marcaId', SSO_BRAND_ID)
            ->where('userPanelsAccess.usuarioId', SSO_USER_ID)
            ->where('panels.id', $id)
            ->first(['panels.id', 'panels.nombre', 'panels.urlPanel', ]);
        return $this->ResponseSuccess('Paneles obtenidos con éxito', $item);
    }

    public function SavePanel(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['paneles/config'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $enlace = $request->get('enlace');
        $users = $request->get('users');

        $item = Panel::where('id', $id)->first();

        if (empty($item)) {
            $item = new Panel();
        }
        else {
            if ($item->marcaId !== SSO_BRAND_ID) {
                return $this->ResponseError('RPT-0135', 'Reporte inválido');
            }
        }

        /*$arrConfig = [];
        foreach ($campos as $campo) {
            $tmp = explode('||', $campo);
            $arrConfig['c'][] = [
                'id' => $campo,
                'p' => $tmp[0],
                'c' => $tmp[1],
            ];
        }

        $arrConfig['p'] = $producto;*/

        $item->id = intval($id);
        $item->nombre = strip_tags($nombre);
        $item->marcaId = SSO_BRAND_ID;
        $item->urlPanel = $enlace;
        //$item->config = @json_encode($arrConfig) ?? null;
        $item->save();

        // guarda accesos
        UserPanel::where('panelId', $item->id)->delete();
        foreach ($users as $user) {
            $uP = new UserPanel();
            $uP->panelId = $item->id;
            $uP->usuarioId = $user;
            $uP->save();
        }

        return $this->ResponseSuccess('Panel guardado con éxito', ['id' => $item->id]);
    }

    public function DeletePanel(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['paneles/config'])) return $AC->NoAccess();

        $id = $request->get('id');
        $item = Panel::where('id', $id)->where('marcaId', SSO_BRAND_ID)->first();

        if (!empty($item)) {
            $item->delete();
            return $this->ResponseSuccess('Panel eliminado con éxito');
        }
        else {
            return $this->ResponseError('RPT-0134', 'Panel inválido');
        }
    }
}
