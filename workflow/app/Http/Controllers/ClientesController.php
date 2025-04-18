<?php
namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Canales;
use App\Models\CanalesSegmentos;
use App\Models\CanalesSegmentosAsignacion;
use App\Models\CanalesUsuarios;
use App\Models\Clientes;
use App\Models\ClientesProductos;
use App\Models\ExpedientesEtapas;
use App\Models\Flujos;
use App\Models\Productos;
use App\Models\ExpedientesTareasRespuestas;
use App\Models\Tareas;
use App\Models\Etapas;
use App\Models\Expedientes;
use App\Models\ExpedientesDetail;
use App\Models\RequisitosAsignacion;
use App\Models\User;
use Illuminate\Http\File as LaravelFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Carbon\CarbonInterval;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ClientesController extends Controller {

    use Response;


    public function buscarPorToken($token) {
        try {
            $cliente = Clientes::where('token', $token)->first();
            if(!empty($cliente)){

                $productosIds = ClientesProductos::where('idCliente', $cliente->id)->pluck('idProducto')->toArray();
                $cliente->productos = $productosIds??[];
                $cliente->sucursales = $cliente->sucursales??1;
                $clientesProductos = ClientesProductos::where('idCliente', $cliente->id)->get();
                if(!$clientesProductos->isEmpty()){
                    foreach ($clientesProductos as $clienteProducto) {
                        $productoTipo = Productos::where('id','=',$clienteProducto->idProducto)->first();
                        //dd($clienteProducto);
                        $direccion = array(
                            'cantidad' => $clienteProducto->cantidad,
                            'index' => $clienteProducto->sucursal,
                            'nombre' => $clienteProducto->direccion,
                            'productosDir' => array($clienteProducto->idProducto),
                            'sucursal' => $clienteProducto->nombreSucursal
                        );

                        $arrayOriginal[$clienteProducto->sucursal] = $direccion;
                        $arrayTipoProducto[$clienteProducto->sucursal] = ($productoTipo->isVirtual)?true:false;
                    }
                }
                else{
                    $direccion = array(
                        'cantidad' => 1,
                        'index' => 0,
                        'nombre' => '',
                        'productosDir' => array(),
                        'sucursal' => ''
                    );

                    $arrayOriginal[0] = $direccion;
                    $arrayTipoProducto[0] = false;
                }
                $cliente->direcciones = $arrayOriginal??[];
                $cliente->tipoProducto = $arrayTipoProducto??[];
                $cliente->extraData = $this->cleanAndValidateJSON($cliente->extraData??'[]');



                //dd($etapas);
                return $this->ResponseSuccess( 'Ok', $cliente);
            }
            else{
                return $this->ResponseSuccess( 'Ok', []);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al buscar el cliente ');
        }

    }

    public function formatearTiempo($minutos){
        $dias = floor($minutos / 1440);
        $horas = floor(($minutos % 1440) / 60);
        $minutosRestantes = $minutos % 60;

        return sprintf('%02d días %02d horas %02d minutos', $dias, $horas, $minutosRestantes);
    }

    public function cleanAndValidateJSON($jsonData) {
        $cleanedData = stripslashes($jsonData);
        $decodedData = json_decode($cleanedData, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decodedData;
        } else {
            return false;
        }
    }


    public function getCredenciales($token) {
        $arrFinal['url'] = '#';
        // Recupera los registros de la tabla que contienen la información que deseas incluir en el archivo ZIP.
        try {
            $cliente = Clientes::where('token', $token)->first();
            if(!empty($cliente)){
                $expediente = Expedientes::where('clienteId',$cliente->id??0)->first();


                $requisitos = RequisitosAsignacion::whereNotNull('tareaId')
                    ->distinct('requisitoId')
                    ->pluck('requisitoId');
                //$registros = DB::table('tabla')->whereNotNull('s3_key')->get();

                // Crea un archivo ZIP.
                $zip = new ZipArchive();
                $nombre_archivo_zip = 'Credenciales.zip';
                date_default_timezone_set('America/Guatemala');
                $ruta_archivo_zip = tempnam(sys_get_temp_dir(), 'zip_');
                if ($zip->open(str_replace("\0", "", $ruta_archivo_zip), ZipArchive::CREATE) === TRUE) {
                    foreach ($requisitos as $registro) {
                        $detalleReq = ExpedientesDetail::where('expedienteId', $expediente->id??0)->where('requisitoId',$registro)->get();
                        if(!empty($detalleReq)){
                            foreach ($detalleReq as $item) {
                                // Agrega los archivos que deseas incluir en el archivo ZIP.

                                //traigo la url temporal
                                if(!empty($item->requisitoS3Key)){
                                    $url = Storage::disk('s3')->temporaryUrl(
                                        $item->requisitoS3Key,
                                        now()->addMinutes(50)
                                    );
                                    $nombre_archivo = basename(parse_url($url, PHP_URL_PATH));
                                    //$zip->addFromString($nombre_archivo, file_get_contents($url));
                                }
                            }
                        }
                    }
                    $clienteProductos = ClientesProductos::where('idCliente',$cliente->id??0)->get();
                    if(!empty($clienteProductos)){

                        foreach ($clienteProductos as $clienteProducto) {
                            $arrManuales = DB::table('productos_manuales')->where('productoId',$clienteProducto->idProducto)->where('isManual','=',true)->get();
                            //dd($clienteProducto);
                            if(!empty($arrManuales)){
                                foreach ($arrManuales as $arrManual) {
                                    //traigo la url temporal
                                    $url = Storage::disk('s3')->temporaryUrl(
                                        $arrManual->s3Key,
                                        now()->addMinutes(2)//Solo lo necesito para crear el archivo zip
                                    );
                                    $nombre_archivo = basename(parse_url($url, PHP_URL_PATH));
                                    $carpeta = 'Manuales/';
                                    $zip->addFromString($carpeta . $nombre_archivo, file_get_contents($url));

                                }
                            }
                        }
                    }
                    if($zip->close()){
                        if (file_exists($ruta_archivo_zip)) {
                            $dir = '/'.$cliente->id.md5('visanet'.$cliente->id);
                            $zip = new LaravelFile($ruta_archivo_zip??'');
                            $path = Storage::disk('s3')->putFileAs(
                                $dir,
                                $zip,
                                $nombre_archivo_zip,
                                'private'
                            );    // el archivo existe
                            $url = Storage::disk('s3')->temporaryUrl(
                                $path,
                                now()->addDays(2)
                            );


                            $tarea = Tareas::where('slug','ONB-RECEPCION-CONTRATO')->first();
                            $respuesta = ExpedientesTareasRespuestas::where('idExpediente',$expediente->id??0)->where('idTarea',$tarea->id)->first();

                            // Crear el arreglo de respuestas
                            $respuestas = [];
                            // Decodificar la cadena JSON en un arreglo asociativo
                            if(!empty($respuesta)){
                                $productosClientes = ClientesProductos::where('idCliente', $expediente->clienteId)
                                    ->join('productos', 'clientes_productos.idProducto', '=', 'productos.id')
                                    ->get()
                                    ->groupBy('sucursal')
                                    ->map(function ($items) {
                                        return $items->groupBy('idProducto')
                                            ->map(function ($items) {
                                                return [
                                                    'nombreProducto' => $items->pluck('nombreProducto')->first(),
                                                    'idProducto' => $items->pluck('idProducto')->first(),
                                                    'cantidad' => $items->pluck('cantidad')->first(),
                                                    'direccion' => $items->pluck('direccion')->first(),
                                                    'afiliacion' => ''
                                                ];
                                            });
                                    });
                                $array = json_decode($respuesta->respuesta??[], true);
                                foreach ($array as $clave => $respuesta) {
                                    // Si la clave comienza con "noAfiliacion-"
                                    if (strpos($clave, 'noAfiliacion-') === 0) {
                                        // Extraer la información de la sucursal y el producto de la clave
                                        $partes = explode('-', $clave);
                                        $sucursal = $partes[2];
                                        $idProducto = $partes[1];
                                        //dd($productosClientes[$sucursal][$idProducto]);
                                        // Agregar la respuesta al arreglo de respuestas en la ubicación correspondiente
                                        if(isset($array['noAfiliacion-'.$idProducto.'-'.$sucursal])){
                                            $respuestas[] =  $array['noAfiliacion-'.$idProducto.'-'.$sucursal]. " ->".$productosClientes[$sucursal][$idProducto]['nombreProducto']??'';
                                        }

                                    }
                                }
                            }
                            $listaSeparada = implode(" ✔ ", $respuestas);

                            $linkInicial = url('/formularios?cliente='.$cliente->token);
                            $linkRequisitos = url('/requisitos?cliente='.$cliente->token);
                            $linkTerminos = url('/terminos?cliente='.$cliente->token);
                            $template = 'mailer/cambio-estado-credenciales';
                            $data = [
                                'linkTimeline' => $url,
                                'nombres' => $cliente->nombres??'',
                                'linkInicial' => $linkInicial,
                                'linkContrato' => $linkTerminos,
                                'numeroAfiliacion' => $listaSeparada,
                                'linkRequisitos' => $linkRequisitos,
                                'appName' => LgcAppTitle,
                                'year' => Date('Y'),
                                'nombreEstado' => 'Credenciales'
                            ];
                            if($template !='rechazo-documento'){
                                Mail::send($template, $data, function ($message) use ($cliente) {
                                    $message->to($cliente->correo??'');
                                    $message->subject('Generación de Credenciales VisaNet');
                                });
                            }

                        }
                    }
                }
                // Devuelve la URL del archivo ZIP.


                $arrFinal['url'] = '#';
                //dd($etapas);
                return $this->ResponseSuccess( 'Ok', $arrFinal);
            }
            else{
                return $this->ResponseSuccess( 'Ok', $arrFinal);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al buscar el cliente '.$th);
        }


    }
    public function actualizarValoresEnJSON($configJson, $valoresArray) {

        $jsonData = json_decode($configJson, true);

        foreach ($valoresArray as  $campoValores) {
            $campoName = $campoValores->campoName;
            $requisitoValor = $campoValores->requisitoValor;

            foreach ($jsonData['nodes'] as &$nodo) {
                if (isset($nodo['formulario']['secciones'])) {
                    foreach ($nodo['formulario']['secciones'] as &$seccion) {
                        foreach ($seccion['campos'] as &$campoConfig) {
                            if ($campoConfig['id'] == $campoName) {
                                $campoConfig['valor'] = $requisitoValor;
                            }
                        }
                    }
                }
            }
        }

        return json_encode($jsonData);
    }
    public function getClientes($productoId) {
        try {
            $camposActivos = [];
            $valores = [];
            $user = auth('sanctum')->user();
            $query = Expedientes::select('expedientes.id as expedienteId', 'expedientes.clienteId', 'expedientes.estado', 'expedientes.updateToken', 'expedientes.dateCreated', 'expedientes.productoId', 'clientes.usuarioId')
                ->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')
                ->where('expedientes.productoId', '=', $productoId)
                ->where('clientes.usuarioId', '=', $user->id)
                ->get();
            foreach ($query as $item) {
                $flujo = Flujos::where('id','=',$item->updateToken)->first();

                $camposActivos[$item->expedienteId] = $this->getVisibleFields($flujo->flujo_config??'');
                if(!empty($camposActivos[$item->expedienteId])){
                    foreach ($camposActivos[$item->expedienteId] as $index => $camposActivo) {
                        $arrValores = ExpedientesDetail::where('expedienteId','=', $item->expedienteId)->where('campoName','=', $camposActivo['id'])->get();

                        foreach ($arrValores as $arrValor) {
                            if((!empty($arrValor['requisitoValor']))){
                                $valores[$item->expedienteId][$index][] = $arrValor;
                            }
                            else{
                                $valores[$item->expedienteId][$index][]['campoName'] = $camposActivo['id'];
                            }
                        }
                    }
                }
            }


            return $this->ResponseSuccess('Ok', $valores);

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales' . $th);
        }
    }
    public function getValorByExpediente($expedienteId) {
        try {
            $camposActivos = [];
            $valores = [];
            $user = auth('sanctum')->user();

            $query = Expedientes::select('expedientes.id as expedienteId', 'expedientes.clienteId', 'expedientes.estado', 'expedientes.updateToken', 'expedientes.dateCreated', 'expedientes.productoId', 'clientes.usuarioId')
                ->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')
                ->where('expedientes.id', '=', $expedienteId)
                ->where('clientes.usuarioId', '=', $user->id)
                ->first();
            $flujo = Flujos::where('id','=',$query->updateToken)->first();
            $arrValores = ExpedientesDetail::where('expedienteId','=', $query->expedienteId)->get();
            //dd($arrValores);
            $flujoConfig = $this->actualizarValoresEnJSON($flujo->flujo_config, $arrValores);
            $arrFinal['flujo'] = $flujoConfig;
            $arrFinal['valores'] = $arrValores;
            return $this->ResponseSuccess('Variables generadas con éxito', $arrFinal);

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales' . $th);
        }
    }
    public function getVisibleFields($json) {
        $data = json_decode($json, true);

        // Buscar el nodo con el ID especificad

        // Obtener las secciones y campos que son visibles
        $visibleFields = array();
        foreach ($data['nodes'] as $n) {
            foreach ($n['formulario']['secciones'] as $seccion) {
                foreach ($seccion['campos'] as $campo) {
                    if (isset($campo['showInReports']) && $campo['showInReports']) {
                        $visibleFields[] = $campo;
                    }
                }
            }
        }

        return $visibleFields;
    }
    public function deleteCliente(Request $request){
        try {
            $user = auth('sanctum')->user();
            $rolName = $user->getRoleNames()[0] ?? '';
            $searchTerm = $request->clienteId;

            if ($rolName === 'Super Administrador' && filled($searchTerm)) {
                $cliente = Clientes::where('id', $searchTerm)->first();

                if (!empty($cliente->id)) {
                    $expediente = Expedientes::where('clienteId', $cliente->id)->first();

                    if (!empty($expediente)) {
                        $expedienteEtapa = ExpedientesEtapas::where('expedienteId',$expediente->id)->first();
                        if(!empty($expedienteEtapa)){
                            $expedienteEtapa->delete();
                        }
                        $expediente->delete();
                    }

                    $cliente->delete();
                    return $this->ResponseSuccess('Ok', []);
                }
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al borrar cliente' . $th);
        }
    }



//getUserConfig
//getClientes
    public function getUserConfig(Request $request) {

        try {
            $user = auth('sanctum')->user();
            $canales = CanalesUsuarios::where('idUser',$user->id)->get();
            //dd($canales);

            $arrFinal = [];

            if(!empty($canales)){
                foreach ($canales as $key => $canal) {
                    $segmentosFinal = [];
                    $canal = Canales::where('id', $canal->idCanal)->first();
                    $segmentos = CanalesSegmentosAsignacion::where('idCanal',$canal->id??0)->get();
                    if(!empty($segmentos)){
                        foreach ($segmentos as $segmento) {
                            $segmentosFinal[] = CanalesSegmentos::where('id',$segmento->idSegmento)->first();

                        }
                    }
                    $arrFinal[$key]['canales']= $canal;
                    $arrFinal[$key]['segmentos']= $segmentosFinal;
                    //$canal->estadoGeneral = $canales->expedientes??[];
                }
                usort($arrFinal, function ($a, $b) {
                    return strcasecmp($a['canales']->nombre, $b['canales']->nombre);
                });

                return $this->ResponseSuccess( 'Ok', $arrFinal);
            }
            else{
                return $this->ResponseSuccess( 'Ok', []);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales'.$th);
        }
    }
    public function addCliente(Request $request) {

        try {
            $validateForm = Validator::make($request->all(),
                [
                    'nombres' => '',
                    'correo' => 'email',
                    'contacto' => '',
                    'nit' => '',
                    'identificacion' => '',
                    'canal' => '',
                    'segmento' => ''
                ]);

            if ($validateForm->fails()) {
                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos' . implode(',', $errores));
            }
            $user = auth('sanctum')->user();
            $cliente = new Clientes();
            $cliente->nombres = $request->nombres;
            $cliente->nit = $request->nit;
            $cliente->identificacion = $request->identificacion;
            $cliente->contacto = $request->contacto;
            $cliente->canalId = $request->canal;
            $cliente->segmento = $request->segmento??0;
            $cliente->correo = $request->correo;

            $cliente->ordenGeneral = 1;

            $cliente->usuarioId = $user->id;
            $data = [
                'nombres' => $cliente->nombres,
                'nit' => $cliente->nit,
                'identificacion' => $cliente->identificacion,
                'contacto' => $cliente->contacto,
                'segmento' => $cliente->segmento,
                'correo' => $cliente->correo,
                'unique_id' => (string) Str::uuid()
            ];
            $secret_key = 'prueballaveMortalMaestraPOrelmomentosetienequecambiaralennv';
            $token = hash_hmac('sha256', json_encode($data), $secret_key);
            $clienteExiste = Clientes::with('expedientes')->where('token', $token)->first();
            $expedienteId = $cliente->expedientes->id??0;
            if(!empty($clienteExiste)){
                $cliente = $clienteExiste;
                $cliente->canalId = $request->canal;
                $cliente->segmento = $request->segmento;
                $cliente->correo = $request->correo;
                $cliente->usuarioId = $user->id;
                if(empty($cliente->ordenGeneral)){
                    $cliente->ordenGeneral = 1;
                }
                $cliente->save();
                $timelineLink = url('#');
                $linkInicial = url('#');
                $linkRequisitos = url('#');
                $data = [
                    'linkTimeline' => $timelineLink,
                    'nombres' => $cliente->nombres??'',
                    'linkInicial' => $linkInicial,
                    'linkRequisitos' => $linkRequisitos,
                    'appName' => LgcAppTitle,
                    'year' => Date('Y')
                ];

                Mail::send('mailer/bienvenida', $data, function ($message) use ($cliente) {
                    //dd($cliente->correo);
                    $message->to($cliente->correo);
                    $message->subject('Bienvenido');
                });
                if(empty($expedienteId)){
                    $expediente = new Expedientes();
                    $expediente->clienteId = $cliente->id;
                    $expediente->save();
                    $expedienteEtapa = new ExpedientesEtapas();
                    $expedienteEtapa->expedienteId = $expediente->id;
                    $expedienteEtapa->etapaId = 28;//Presentación
                    $expedienteEtapa->save();
                }
                return $this->ResponseSuccess( 'Ok', $cliente);
            }
            else{
                $cliente->token = $token;

                if($cliente->save()){
                    if(empty($expedienteId)){
                        $expediente = new Expedientes();
                        $expediente->clienteId = $cliente->id;
                        $expediente->save();
                        $expedienteEtapa = new ExpedientesEtapas();
                        $expedienteEtapa->expedienteId = $expediente->id;
                        $expedienteEtapa->etapaId = 28;//Presentación
                        $expedienteEtapa->save();
                    }

                    return $this->ResponseSuccess( 'Ok', $cliente);
                }
                else{
                    return $this->ResponseSuccess( 'Ok', []);
                }
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al guardar cliente');
        }
    }
    public function actualizarPorToken(Request $request){
        try {
            $validateForm = Validator::make($request->all(),
                [
                    'token' => 'required',
                    'nombres' => 'required',
                    'correo' => 'email',
                    'contacto' => 'regex:/^(?:\+?\d{1,3}[\s-])?\d{8}$/',
                    'nit' => 'regex:/^\d{5,9}[-\s]?[A-Za-z0-9]$/',
                    'identificacion' => 'required',
                    'sucursales' => 'integer|required',
                    'productos' => '',
                    'direcciones' => 'required|array',
                    'direcciones.*.productosDir' => 'required',
                    'parentescoPepParentezco' => '',
                    'parentescoPepApellidos' =>'',
                    'parentescoPepNombres' => '',
                    'parentescoPepInstitucion' => '',
                    'parentescoPepPais' => '',
                    'parentescoPepCargo' => '',
                    'parentescoPepPeriodo' => '',
                    'isPepInstitucion' => '',
                    'isPepPais' => '',
                    'isPepCargo' => '',
                    'isPepPeriodo' => '',
                    'isPep' => '',
                    'nombreComercial' => '',
                    'razonSocial' => '',
                    'parentescoPep' => '',
                    'correoContable' => '',
                    'giroComercial' => '',
                    'pepProveedor' => '',
                    'extraData' => ''
                ]);

            if ($validateForm->fails()) {
                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('CLIENT-AF5834', 'Faltan Campos ' , $errores);
            }
            $cliente = Clientes::with('expedientes')->where('token', $request->token)->first();
            $expedienteId = $cliente->expedientes->id??0;
            //Borro los productos
            ClientesProductos::where('idCliente','=', $cliente->id)->delete();
            if(!empty($cliente)){
                $cliente->nombres = $request->nombres??'';
                $cliente->nit = $request->nit??'';
                $cliente->identificacion = $request->identificacion??'';
                $cliente->contacto = $request->contacto??'';
                $cliente->correo = $request->correo??'';
                $cliente->correoContable = $request->correoContable??'';
                $cliente->giroComercial = $request->giroComercial??'';
                $cliente->isPepPeriodo = $request->isPepPeriodo??'';
                $cliente->isPepCargo = $request->isPepCargo??'';
                $cliente->isPepPais = $request->isPepPais??'';
                $cliente->isPepInstitucion = $request->isPepInstitucion??'';
                $cliente->parentescoPepPeriodo = $request->parentescoPepPeriodo??'';
                $cliente->parentescoPepCargo = $request->parentescoPepCargo??'';
                $cliente->parentescoPepPais = $request->parentescoPepPais??'';
                $cliente->parentescoPepInstitucion = $request->parentescoPepInstitucion??'';
                $cliente->parentescoPepNombres = $request->parentescoPepNombres??'';
                $cliente->parentescoPepApellidos = $request->parentescoPepApellidos??'';
                $cliente->parentescoPepParentesco = $request->parentescoPepParentesco??'';
                $cliente->parentescoPep = $request->parentescoPep??'';
                $cliente->isPep = $request->isPep??'';
                $cliente->razonSocial = $request->razonSocial??'';
                $cliente->pepProveedor = $request->pepProveedor??'';
                $cliente->nombreComercial = $request->nombreComercial??'';
                $cliente->extraData = json_encode($request->extraData??[]);
                $cliente->sucursales = $request->sucursales??'';
                $cliente->ordenGeneral = ($cliente->ordenGeneral > 1)?$cliente->ordenGeneral:1;
                //$umbral = 2; // Umbral de distancia de Levenshtein
                $blackList = DB::table('black_list')
                    ->select('nombre')
                    ->where('nombre', '=', $cliente->nombreComercial)
                    ->orWhere('nombre', '=', $cliente->razonSocial )
                    ->first();
                //dd($blackList);
                if(!empty($blackList)){
                    $tarea = Tareas::where('slug','ONB-RECEPCION-CONTRATO')->first();
                    $respuesta = ExpedientesTareasRespuestas::where('idExpediente',$expediente->id??0)->where('idTarea',$tarea->id)->first();

                    // Crear el arreglo de respuestas
                    $respuestas = [];
                    // Decodificar la cadena JSON en un arreglo asociativo
                    if(!empty($respuesta)){
                        $productosClientes = ClientesProductos::where('idCliente', $expediente->clienteId)
                            ->join('productos', 'clientes_productos.idProducto', '=', 'productos.id')
                            ->get()
                            ->groupBy('sucursal')
                            ->map(function ($items) {
                                return $items->groupBy('idProducto')
                                    ->map(function ($items) {
                                        return [
                                            'nombreProducto' => $items->pluck('nombreProducto')->first(),
                                            'idProducto' => $items->pluck('idProducto')->first(),
                                            'cantidad' => $items->pluck('cantidad')->first(),
                                            'direccion' => $items->pluck('direccion')->first(),
                                            'afiliacion' => ''
                                        ];
                                    });
                            });
                        $array = json_decode($respuesta->respuesta??[], true);
                        foreach ($array as $clave => $respuesta) {
                            // Si la clave comienza con "noAfiliacion-"
                            if (strpos($clave, 'noAfiliacion-') === 0) {
                                // Extraer la información de la sucursal y el producto de la clave
                                $partes = explode('-', $clave);
                                $sucursal = $partes[2];
                                $idProducto = $partes[1];
                                //dd($productosClientes[$sucursal][$idProducto]);
                                // Agregar la respuesta al arreglo de respuestas en la ubicación correspondiente
                                if(isset($array['noAfiliacion-'.$idProducto.'-'.$sucursal])){
                                    $respuestas[] =  $array['noAfiliacion-'.$idProducto.'-'.$sucursal]. " ->".$productosClientes[$sucursal][$idProducto]['nombreProducto']??'';
                                }

                            }
                        }
                    }
                    $listaSeparada = implode(" ✔ ", $respuestas);

                    $linkInicial = url('/formularios?cliente='.$cliente->token);
                    $linkRequisitos = url('/requisitos?cliente='.$cliente->token);
                    $linkTerminos = url('/terminos?cliente='.$cliente->token);
                    $template = 'mailer/cambio-estado-blacklist';
                    $data = [
                        'linkTimeline' => '',
                        'nombres' => $cliente->nombres??'',
                        'linkInicial' => $linkInicial,
                        'linkContrato' => $linkTerminos,
                        'numeroAfiliacion' => $listaSeparada,
                        'linkRequisitos' => $linkRequisitos,
                        'appName' => LgcAppTitle,
                        'year' => Date('Y'),
                        'nombreEstado' => 'Credenciales'
                    ];
                    if($template !='rechazo-documento'){
                        Mail::send($template, $data, function ($message) use ($cliente) {
                            $message->to($cliente->correo??'');
                            $message->subject('Rechazo por políticas internas');
                        });
                    }
                    return $this->ResponseSuccess('CLIENT-AF699440F', 'Error al actualizar el cliente');
                }

                if($cliente->save()){
                    if(empty($expedienteId)){
                        $expediente = new Expedientes();
                        $expediente->clienteId = $cliente->id;
                        $expediente->save();
                    }
                    foreach ($request->direcciones as $direccion){
                        foreach ($direccion['productosDir'] as $productoId){
                            $clienteProducto = new ClientesProductos();
                            $clienteProducto->idProducto = $productoId;
                            $clienteProducto->cantidad = $direccion['cantidad'];
                            $clienteProducto->sucursal = $direccion['index'];
                            $clienteProducto->direccion = $direccion['nombre'];
                            $clienteProducto->nombreSucursal = $direccion['sucursal']??'';
                            $clienteProducto->idCliente = $cliente->id;
                            $clienteProducto->save();
                        }

                    }
                    $clientesProductos = ClientesProductos::where('idCliente', $cliente->id)->get();

                    foreach ($clientesProductos as $clienteProducto) {
                        $direccion = array(
                            'cantidad' => $clienteProducto->cantidad,
                            'index' => $clienteProducto->sucursal,
                            'nombre' => $clienteProducto->direccion,
                            'productosDir' => array($clienteProducto->idProducto),
                            'sucursal' => $clienteProducto->nombreSucursal
                        );

                        $arrayOriginal[$clienteProducto->sucursal] = $direccion;
                    }
                    $cliente->direcciones = $arrayOriginal??[];
                    return $this->ResponseSuccess( 'Cliente actualizado con éxito', $cliente);
                }
                else{
                    return $this->ResponseError('CLIENT-AF699440F', 'Error al actualizar el cliente');
                }

            }
            else{
                return $this->ResponseSuccess( 'No se encontró al cliente', []);
            }

        }
        catch (\Throwable $th) {
            return $this->ResponseError('CLIENT-AF699440F1', 'Error al actualizar el cliente '.$th);
        }
    }

    public function generarDashboards(Request $request){

        try {
            //dd($request->dateIni,$request->dateFinal);
            $user = auth('sanctum')->user();
           // $canales = CanalesUsuarios::where('idUser',$user->id)->get();
            $AC = new AuthController();
            if ($AC->CheckAccess(['clientes/admin/canal'])){
                $canalIds = CanalesUsuarios::where('idUser', $user->id)->pluck('idCanal')->toArray();
            }
            else{
                $canalIds = false;
            }


            //dd($canales);

            $arrFinal = [];
            if(!empty($request->canalId)){
                if (!in_array($request->canalId, $canalIds)) {
                    $request->canalId = 0;
                }
            }

            $canalNames = Canales::whereIn('id', $canalIds)->pluck('nombre', 'id');
            $etapaNames = DB::table('etapas')->pluck('nombre', 'id');
            $segmentosNames = DB::table('canales_segmentos')->pluck('nombre', 'id');
            $tareasNames = DB::table('tareas')->pluck('nombre', 'id');

            $results = DB::table('clientes')
                ->join('expedientes', 'clientes.id', '=', 'expedientes.clienteId')
                ->leftJoin('expedientes_etapas', 'expedientes.id', '=', 'expedientes_etapas.expedienteId')
                ->selectRaw('clientes.canalId, clientes.segmento, expedientes_etapas.etapaId, COUNT(*) AS contador, clientes.dateCreated, COALESCE(expedientes_etapas.dateUpdated, NOW()) AS dateUpdated, 
                TIME_TO_SEC(TIMEDIFF(COALESCE(expedientes_etapas.dateUpdated, NOW()), clientes.dateCreated)) AS tiempoDiferencia')
                ->whereIn('clientes.canalId', $canalIds)
                ->when($canalIds == false, function ($query) use ($user) {
                    return $query->where('clientes.usuarioId', $user->id);
                })
                ->when((!empty($request->dateIni) && !empty($request->dateFinal)), function ($query) use ($request, $user) {
                    return $query->whereBetween('clientes.dateCreated', [$request->dateIni, $request->dateFinal]);
                })
                ->when((!empty($request->etapaId)), function ($query) use ($request, $user) {
                    return $query->where('expedientes_etapas.etapaId', [$request->etapaId]);
                })
                ->when((!empty($request->canalId)), function ($query) use ($request, $user) {
                    return $query->where('clientes.canalId', '=', $request->canalId);
                })
                ->groupBy('clientes.canalId', 'clientes.segmento', 'expedientes_etapas.etapaId', 'clientes.dateCreated', 'expedientes_etapas.dateUpdated')
                ->get();

            $resultsTareas = DB::table('expedientes_tareas_respuestas')
                ->join('expedientes', 'expedientes_tareas_respuestas.idExpediente', '=', 'expedientes.id')
                ->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')
                ->leftJoin('expedientes_etapas', 'expedientes.id', '=', 'expedientes_etapas.expedienteId')
                ->select(
                    'expedientes_tareas_respuestas.idTarea',
                    DB::raw('GROUP_CONCAT(expedientes_tareas_respuestas.idExpediente) as expedientes'),
                    DB::raw('COUNT(expedientes_tareas_respuestas.id) as countTareas'),
                    DB::raw('SUM(CASE WHEN expedientes_tareas_respuestas.vistoBueno IS NOT NULL THEN 1 ELSE 0 END) as countVistoBueno')
                )
                ->whereIn('clientes.canalId', $canalIds)
                ->when($canalIds == false, function ($query) use ($user) {
                    return $query->where('clientes.usuarioId', $user->id);
                })
                ->when(!empty($request->dateIni) && !empty($request->dateFinal), function ($query) use ($request) {
                    return $query->whereBetween('clientes.dateCreated', [$request->dateIni, $request->dateFinal]);
                })
                ->when(!empty($request->canalId), function ($query) use ($request) {
                    return $query->where('clientes.canalId', '=', $request->canalId);
                })
                ->groupBy('expedientes_tareas_respuestas.idTarea')
                ->get();




            $resultsTareasEtapas = DB::table('expedientes_tareas_respuestas')
                ->join('expedientes', 'expedientes_tareas_respuestas.idExpediente', '=', 'expedientes.id')
                ->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')
                ->join('expedientes_etapas', 'expedientes.id', '=', 'expedientes_etapas.expedienteId')
                ->join('tareas_etapas', 'tareas_etapas.idTarea', '=', 'expedientes_tareas_respuestas.idTarea')
                ->select(
                    'expedientes_etapas.etapaId',
                    DB::raw('GROUP_CONCAT(DISTINCT expedientes.id) as expedientes'),
                    DB::raw('COUNT(DISTINCT expedientes.id) as countExpedientes'),
                    DB::raw('COUNT(DISTINCT CASE WHEN expedientes_tareas_respuestas.vistoBueno IS NOT NULL THEN expedientes.id END) as countVistoBueno')
                )
                ->whereIn('clientes.canalId', $canalIds)
                ->when($canalIds == false, function ($query) use ($user) {
                    return $query->where('clientes.usuarioId', $user->id);
                })
                ->when(!empty($request->dateIni) && !empty($request->dateFinal), function ($query) use ($request) {
                    return $query->whereBetween('clientes.dateCreated', [$request->dateIni, $request->dateFinal]);
                })
                ->when(!empty($request->canalId), function ($query) use ($request) {
                    return $query->where('clientes.canalId', '=', $request->canalId);
                })
                ->groupBy('expedientes_etapas.etapaId')
                ->get();

            $counterArrayByNombreEtapa = [];
            $counterArrayByNombreCanal = [];
            $averageArrayByNombreEtapa = [];
            $porcentajeCanales = [];
            $porcentajeEtapas = [];
            $counterArrayByDay = [];
            $counterTask = [];
            $counterVistoBueno = [];

            foreach ($results as $result) {
                $canalId = $result->canalId;
                $segmento = $result->segmento;
                $etapaId = $result->etapaId;
                $contador = $result->contador??0;
                $tiempoDiferencia = $result->tiempoDiferencia;

                $nombreCanal = $canalNames[$canalId] ?? 'Desconocido';
                $segmentoName = $segmentosNames[$segmento] ?? 'Afiliación Normal';
                $nombreEtapa = $etapaNames[$etapaId] ?? 'Inicio';

                $counterArrayByNombreEtapa[$nombreEtapa] = isset($counterArrayByNombreEtapa[$nombreEtapa]) ? $counterArrayByNombreEtapa[$nombreEtapa] + $contador : $contador;
                $averageArrayByNombreEtapa[$nombreEtapa] = isset($averageArrayByNombreEtapa[$nombreEtapa]) ? $averageArrayByNombreEtapa[$nombreEtapa] + $tiempoDiferencia : $tiempoDiferencia;
                $counterArrayByNombreCanal["{$nombreCanal} {$segmentoName}"] = isset($counterArrayByNombreCanal["{$nombreCanal} {$segmentoName}"]) ? $counterArrayByNombreCanal["{$nombreCanal} {$segmentoName}"] + $contador : $contador;
                $day = date('d/m', strtotime($result->dateCreated));
                $counterArrayByDay[$day] = isset($counterArrayByDay[$day]) ? $counterArrayByDay[$day] + $contador : $contador;

            }
            foreach ($resultsTareas as $result) {
                $idTarea = $result->idTarea;
                $countTareas = $result->countTareas;
                $tareaName = $tareasNames[$idTarea];

                $counterTask[$tareaName] = isset($counterTask[$tareaName]) ? $counterTask[$tareaName] + $countTareas : $countTareas;
            }

            foreach ($resultsTareasEtapas as $result) {
                $etapaId = $result->etapaId;
                $countVistoBueno = $result->countVistoBueno;
                $stepName =  $etapaNames[$etapaId] ?? 'Inicio';
                $counterVistoBueno[$stepName] = isset($counterVistoBueno[$stepName]) ? $counterVistoBueno[$stepName] + $countVistoBueno : $countVistoBueno;
            }


            // Calcular el promedio por etapa dividiendo la suma de tiempo por el contador


            // Ordenar las etapas por el campo "orden" de la tabla "etapas"
            $etapasOrdenadas = DB::table('etapas')->orderBy('orden')->pluck('nombre', 'id');

            // Ordenar los canales de mayor a menor según la cantidad de registros


            $totalRegistrosCanales = array_sum($counterArrayByNombreCanal);

            foreach ($counterArrayByNombreCanal as $nombreCanal => $contador) {
                $porcentaje = ($contador / $totalRegistrosCanales) * 100;
                $porcentajeCanales[$nombreCanal] = round($porcentaje,2);
            }

            // Calcular el total de registros de todas las etapas
            $totalRegistrosEtapas = array_sum($counterArrayByNombreEtapa);

            // Calcular los porcentajes de cada etapa
            foreach ($counterArrayByNombreEtapa as $nombreEtapa => $contador) {
                $porcentaje = ($contador / $totalRegistrosEtapas) * 100;
                $porcentajeEtapas[$nombreEtapa] = round($porcentaje,2);
            }
            //ordeno resultados
            $etapasOrdenadasArray = $etapasOrdenadas->toArray();

            $newArray = [];
            $porcentajeEtapasOr = [];
            $averageArrayByNombreEtapaOr = [];

            foreach ($etapasOrdenadasArray as  $etapaNombre) {
                $contador = $counterArrayByNombreEtapa[$etapaNombre] ?? 0;
                $contador2 = $porcentajeEtapas[$etapaNombre] ?? 0;
                $contadorAverage = $averageArrayByNombreEtapa[$etapaNombre] ?? 0;
                $porcentajeEtapasOr[$etapaNombre] = $contador2;
                $newArray[$etapaNombre] = $contador;
                $averageArrayByNombreEtapaOr[$etapaNombre] = $contadorAverage;
            }

            $porcentajeEtapas = $porcentajeEtapasOr;
            $averageArrayByNombreEtapa = $averageArrayByNombreEtapaOr;
            arsort($counterArrayByNombreCanal);
            arsort($porcentajeCanales);
            ksort($counterArrayByDay);


            foreach ($averageArrayByNombreEtapa as $nombreEtapa => $tiempoTotal) {
                $promedioSegundos = (!empty($newArray[$etapaNombre]))? $tiempoTotal / $newArray[$etapaNombre]:0;
                $promedioMinutos = floor($promedioSegundos / 60);
                $promedioFormateado = $this->formatearTiempo($promedioMinutos);

                $averageArrayByNombreEtapa[$nombreEtapa] = $promedioFormateado;
            }



            $arrFinal['etapas'] = $newArray;
            $arrFinal['canales'] = $counterArrayByNombreCanal;
            $arrFinal['promedios'] = $averageArrayByNombreEtapa;
            $arrFinal['porcentajeCanales'] = $porcentajeCanales;
            $arrFinal['porcentajeEtapas'] = $porcentajeEtapas;
            $arrFinal['tareas'] = $counterTask;
            $arrFinal['firmados'] = $counterVistoBueno;
            $arrFinal['totalDias'] = $counterArrayByDay;
            $arrFinal['Total'] = $totalRegistrosCanales;
            $arrFinal['pasosNames'] = $etapaNames;
            $arrFinal['canalesName'] = $canalNames;
            $arrFinal['tareasNames'] = $tareasNames;
            return $this->ResponseSuccess('Ok', $arrFinal);



        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales'.$th);
        }

    }


}
