<?php
namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Canales;
use App\Models\Clientes;
use App\Models\Etapas;
use App\Models\ExpedientesEtapas;
use App\Models\ExpedientesDetail;
use App\Models\ExpedientesTareasRespuestas;
use App\Models\Productos;
use App\Models\Requisitos;
use App\Models\RequisitosCategorias;
use App\Models\RequisitosAsignacion;
use App\Models\Tareas;
use App\Models\TareasCanales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Aws\Credentials\CredentialProvider;
use Aws\Textract\TextractClient;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Auth;


use App\Models\Formularios;
use Carbon\Carbon;

class FormulariosController extends Controller {

    use Response;


    public function searchArrayes($array, $key, $value){
        $results = array();

        if (is_array($array)) {
            if (isset($array[$key]) && $array[$key] == $value) {
                $results[] = $array;
            }
            foreach ($array as $subarray) {
                $results = array_merge($results, $this->searchArrayes($subarray, $key, $value));
            }
        }
        return $results;
    }

    public function GetFormList() {

        $items = Formularios::all();

        $itemsResponse = [];

        if (!empty($items)) {

            foreach ($items as $item) {
                $itemsResponse[] = [
                    'id' => $item->id,
                    'name' => $item->formName,
                    'status' => $item->status,
                    'schemaStructure' => $item->schemaStructure,
                    'schemaUi' => $item->schemaUi,
                ];
            }

            return $this->ResponseSuccess('ok', $itemsResponse);
        }
        else {
            return $this->ResponseError('FR-AF58', 'Error al obtener formularios');
        }
    }

    public function GetFormDetail(Request $request) {

        $id = $request->get('formid');

        $item = Formularios::where([['id', '=', $id, ['status', '=', 1]]])->first();

        $itemsResponse = [
            'id' => $item->id,
            'name' => $item->formName,
            'status' => $item->status,
            'schemaStructure' => $item->schemaStructure,
            'schemaUi' => $item->schemaUi,
        ];

        if (!empty($item)) {
            return $this->ResponseSuccess('ok', $itemsResponse);
        }
        else {
            return $this->ResponseError('RU-AF58', 'Error al obtener información');
        }
    }

    public function saveFields(Request $request) {

        $id = $request->get('loteid');
        $loteR = $request->get('lote');
        $personal = $request->get('personal');

        $lote = Lote::where([['id', '=', $id]])->first();

        if (empty($id)) {
            $lote = new Lote();
        }

        $lote->userId = auth('sanctum')->user()->id;
        $lote->nameLote = $loteR['nombre'];
        $lote->dateCreated = Carbon::now()->format('Y-m-d h:i:s');
        $lote->statusLote = $loteR['estado'];
        $lote->esquemaComisionId = $loteR['esquemaComisionId'];
        $lote->save();

        if (!empty($lote)) {

            // borro las reglas anteriores
            LoteDetail::where([['loteId', '=', $lote->id]])->delete();

            foreach ($personal as $personalId => $active) {
                if ($active) {
                    $tmpItem = new LoteDetail();
                    $tmpItem->loteId = $lote->id;
                    $tmpItem->personalId = $personalId;
                    $tmpItem->save();
                }
            }
            return $this->ResponseSuccess('ok', $lote->id);
        }
        else {
            return $this->ResponseError('RU-AF58', 'Error al obtener reglas');
        }
    }

    public function getCategoriasRequisitos($cliente){

        $categorias = RequisitosCategorias::all();
        $cliente = Clientes::with('expedientes.expedienteDetail')->where('token', $cliente)->first();
        $expediente = $cliente->expedientes->requisitoCategoriaId??0;
        $arrFinal['categorias'] = [];
        $arrFinal['expediente'] = $expediente;
        if (!empty($categorias)) {
            $arrFinal['categorias'] = $categorias;
            return $this->ResponseSuccess('ok', $arrFinal);
        }
        else {
            return $this->ResponseError('RU-AF58682', 'Error al obtener categorías');
        }
    }

    public function previewChanges($expedienteId){
        if(!empty($expedienteId)){
            $detalle = ExpedientesDetail::where('expedienteId','=',$expedienteId)->get();
            $preview = [];
            $textract = [];
            $formularioFinal = [];
            $contador = 0;

            if(!empty($detalle)){

                foreach ($detalle as $index => $item) {
                    $formulario = [];
                    $valoresVerificados = json_decode($item->requisitoValor,true);

                    $requisito = Requisitos::where('id','=',$item->requisitoId)->first();

                    if(!empty($requisito->queryConfig)){
                        $formulario = json_decode($requisito->queryConfig, true);
                        foreach ($formulario as &$query) {
                            unset($query['Pages']);
                        }
                    }


                    if(!empty($item->requisitoS3Key)){
                        $url = Storage::disk('s3')->temporaryUrl(
                            $item->requisitoS3Key,
                            now()->addMinutes(50)
                        );
                    }
                    else{
                        $url ='';
                    }

                    $preview[$item->requisitoId][$contador]['requisitoId'] = $item->requisitoId;
                    $preview[$item->requisitoId][$contador]['src'] = $url;
                    // Obtener la extensión del archivo
                    $extension = pathinfo($item->requisitoS3Key, PATHINFO_EXTENSION);

                    // Añadir la extensión al array
                    $preview[$item->requisitoId][$contador]['extension'] = strtolower($extension);
                    $objOcr = json_decode($item->requisitoOCR, true);
                    if(!empty($objOcr['formularios'])){
                        $textract[$item->requisitoId]['formularios'][] = $objOcr['formularios'];
                    }
                    if(!empty($formulario)){

                        foreach ($formulario as  $input) {
                            //$formularioFinal[$item->requisitoId][$input['Alias']]['valor'] = $formularioFinal[$item->requisitoId][$input['Alias']]['valor']??'';
                            if(!isset($formularioFinal[$item->requisitoId][$input['Alias']])){
                                $formularioFinal[$item->requisitoId][$input['Alias']] = $input;
                            }//$formularioFinal[$item->requisitoId][$input['Alias']]['valor'] = $formularioFinal[$item->requisitoId][$input['Alias']]['valor']??'';
                            if(!isset($formularioFinal[$item->requisitoId][$input['Alias']]['valores'])){
                                $formularioFinal[$item->requisitoId][$input['Alias']]['valores'] = [];
                            }
                            $valor = $this->searchArrayes($objOcr['queries']??[], 'key', $input['Alias']);
                            if(isset($valor[0]['valor']) && ($input['Type'] == 'text' || $input['Type'] == 'textarea')){
                                $formularioFinal[$item->requisitoId][$input['Alias']]['valores'][] = $valor[0]['valor'];
                                $formularioFinal[$item->requisitoId][$input['Alias']]['valor'] = $valoresVerificados[$input['Alias']]['valor']??$valor[0]['valor'];
                            }
                            else{
                                $formularioFinal[$item->requisitoId][$input['Alias']]['valor'] = $valoresVerificados[$input['Alias']]['valor']??'';
                            }
                            $formularioFinal[$item->requisitoId][$input['Alias']]['validado'] = !empty($valoresVerificados[$input['Alias']]['valor']);
                            $formularioFinal[$item->requisitoId][$input['Alias']]['valor-validado'] = $valoresVerificados[$input['Alias']]['valor']??'';

                        }
                        $textract[$item->requisitoId]['queries'][] = $objOcr['queries']??[];
                    }
                    $contador++;
                }
            }
            $arrFinal['preview'] = $preview;
            $arrFinal['textract'] = $textract;
            $arrFinal['formulario'] = $formularioFinal;

            return $arrFinal;
        }
        else{
            return false;
        }
    }

    public function getRequisitosByCategory($categoria, $cliente){
        //dd($cliente);
        $requisitos = RequisitosAsignacion::with('requisito')->where('categoriaRequisitoId', $categoria)->get();
        $cliente = Clientes::with('expedientes.expedienteDetail')->where('token', $cliente)->first();
        $requisitoSegmento = RequisitosAsignacion::with('requisito')->where('segmentoId', '=',$cliente->segmento??0)->get();
        if(count($requisitoSegmento) > 0){
            $arrFinal['requisitos'] = $requisitoSegmento;
        }else{
            $arrFinal['requisitos'] = $requisitos;
        }
        $expediente = $cliente->expedientes->id??0;

        $arrPrev = $this->previewChanges($expediente);


        $arrFinal['expediente'] = $expediente;
        $arrFinal['ocr'] = $arrPrev['textract']??[];
        $arrFinal['detalle'] = $arrPrev['preview']??[];
        $arrFinal['formFinal'] = $arrPrev['formulario']??[];
        if (!empty($requisitos)) {

            return $this->ResponseSuccess('ok', $arrFinal);
        }
        else {
            return $this->ResponseError('RU-AF5863282', 'Error al obtener Requisitos');
        }
    }
    public function getContratoDatos($cliente){
        //dd($cliente);
        $cliente = Clientes::with('expedientes.expedienteDetail')->where('token', $cliente)->first();
        if($cliente->segmento == 2){
            $requisitos = Requisitos::select('expedientes.id', 'expedientes_detail.requisitoValor')
                ->join('expedientes_detail', 'requisitos.id', '=', 'expedientes_detail.requisitoId')
                ->join('expedientes', 'expedientes_detail.expedienteId', '=', 'expedientes.id')
                ->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')
                ->leftJoin('expedientes_tareas_respuestas', 'expedientes.id', '=', 'expedientes_tareas_respuestas.idExpediente')
                ->leftJoin('tareas', 'expedientes_tareas_respuestas.idTarea', '=', 'tareas.id')
               //->where('tareas.slug', '=', 'ONB-GENERACION-CONTRATO')
                ->where('clientes.id', '=', $cliente->id??0)
                ->groupBy('expedientes.id', 'expedientes_detail.requisitoValor')
                ->get();

        }
        else{
            $requisitos = Requisitos::select('expedientes.id', 'expedientes_detail.requisitoValor')
                ->join('expedientes_detail', 'requisitos.id', '=', 'expedientes_detail.requisitoId')
                ->join('expedientes', 'expedientes_detail.expedienteId', '=', 'expedientes.id')
                ->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')
                ->join('expedientes_tareas_respuestas', 'expedientes.id', '=', 'expedientes_tareas_respuestas.idExpediente')
                ->join('tareas', 'expedientes_tareas_respuestas.idTarea', '=', 'tareas.id')
                ->whereIn('tareas.slug', ['ONB-GENERACION-CONTRATO','ONB-RECHAZOS'])
                ->where('clientes.id', '=', $cliente->id??0)
                ->groupBy('expedientes.id', 'expedientes_detail.requisitoValor')
                ->get();
            //dd($requisitos);
        }

        $tarea = Tareas::where('slug','ONB-GENERACION-CONTRATO')->first();
        $tareaAfiliacion = Tareas::where('slug','ONB-RECEPCION-CONTRATO')->first();
        $tareaCaso = Tareas::where('slug','ONB-CONFIGURACION-POS')->first();
        $tareaRechazo = Tareas::where('slug','ONB-RECHAZOS')->first();
        $arrayValores = array(
            'nombreComercial',
            'actividad',
            'NIT',
            'regimen',
            'tipoPersona',
            'cui',
            'nombres',
            'apellidos',
            'razonSocial',
            'correo',
            'monedaCuenta',
            'direccion',
            'direccionFiscal',
            'Banco',
            'tipoCuenta',
            'noCuenta',
        );
        $valores = [];
        $arrFinal = [];
        //$tareasRespuestaRechazo = [];
        //$tareasRespuestaRechazo = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tareaRechazo->id??0)->first();
        foreach ($requisitos as $requisito){
            if($cliente->segmento == 2){
                $tareasRespuesta = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->first();
                $tareasRespuestaAfiliacion = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tareaAfiliacion->id??0)->first();
                $tareasRespuestaCaso = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tareaCaso->id??0)->first();
                $tareasRespuestaRechazo = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tareaRechazo->id??0)->first();
            }
            else{
                $tareasRespuesta = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tarea->id??0)->first();
                $tareasRespuestaAfiliacion = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tareaAfiliacion->id??0)->first();
                $tareasRespuestaCaso = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tareaCaso->id??0)->first();
                $tareasRespuestaRechazo = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tareaRechazo->id??0)->first();

            }
            //dd($tareasRespuestaRechazo);
            $arrFinal['vistoBueno'] = $tareasRespuesta->vistoBueno??'';
            $tareasRespuesta = json_decode($tareasRespuesta->respuesta??'',true);
            $tareasRespuestaAfiliacion = json_decode($tareasRespuestaAfiliacion->respuesta??'',true);
            $tareasRespuestaCaso = json_decode($tareasRespuestaCaso->respuesta??'',true);
            $tareasRespuestaRechazo = json_decode($tareasRespuestaRechazo->respuesta??'',true);
            $arrValor = json_decode($requisito->requisitoValor, true);
            if(is_array($arrValor)){
                foreach ($arrValor as $valorFinal) {
                    //dd($valorFinal);

                    $valores[$valorFinal['Alias']] = !empty($valorFinal['valor-validado'])?$valorFinal['valor-validado']:$valorFinal['valor'];
                }
            }
        }

        $encabezados = $arrayValores;

        if (!empty($requisitos)) {
            foreach ($encabezados as $encabezado) {
                $arrFinal[$encabezado] =  $valores[$encabezado]??'';
            }
            if(is_array($tareasRespuesta??[])){
                foreach ($tareasRespuesta??[] as $key => $item) {
                    if($key == 'mcc')  continue;
                    $arrFinal[$key] = $item;
                }
            }
            if(is_array($tareasRespuestaRechazo??[])){
                //dd($tareasRespuestaRechazo??'');
                $arrFinal['rechazos']['motivoRechazo'] = $tareasRespuestaRechazo['motivoRechazo']??'';
                $arrFinal['rechazos']['comentarioRechazo'] = $tareasRespuestaRechazo['comentarioRechazo']??'';
                $arrFinal['rechazos']['requisitoRechazado'] = $tareasRespuestaRechazo['requisitoRechazado']??[];
            }
            if(is_array($tareasRespuestaAfiliacion??[])){
                foreach ($tareasRespuestaAfiliacion??[] as $key => $item) {
                    if (strpos($key, '-') !== false) {
                        $parts = explode('-', $key);
                        $sucursal = $parts[2]??0;
                        $productoId = $parts[1]??0;
                        $noAfiliacion = $tareasRespuestaAfiliacion['noAfiliacion-'.$productoId.'-'.$sucursal]??'';
                        $direccion = $tareasRespuestaAfiliacion['direccion-'.$productoId.'-'.$sucursal]??'';
                        $strProductoNombre = Productos::where('id',$productoId)->first();
                        $result[$sucursal][$productoId] = array(
                            'sucursal' => $sucursal,
                            'productoId' => $productoId,
                            'productoNombre' => $strProductoNombre->nombreProducto??'',
                            'noAfiliacion' => $noAfiliacion,
                            'direccion' => $direccion
                        );
                    }
                }
                $arrFinal['afiliaciones'] = $result??[];
            }
            $result = [];
            if(is_array($tareasRespuestaCaso??[])){
                foreach ($tareasRespuestaCaso??[] as $key => $item) {
                    if (strpos($key, '-') !== false) {
                        $parts = explode('-', $key);
                        $sucursal = $parts[1]??0;
                        $productoId = $parts[0]??0;
                        $noAfiliacion = $tareasRespuestaCaso[$productoId.'-'.$sucursal.'-casoTerminal']??'';
                        $direccion = $tareasRespuestaAfiliacion['direccion-'.$productoId.'-'.$sucursal]??'';
                        $strProductoNombre = Productos::where('id',$productoId)->first();
                        $isBanco = TareasCanales::where('CanalId',$cliente->canalId??null)->first();
                        $isBanco = !empty($isBanco);
                        if(is_numeric($productoId) && is_numeric($sucursal)){
                            $result[$sucursal][$productoId] = array(
                                'sucursal' => $sucursal,
                                'productoId' => $productoId,
                                'productoNombre' => $strProductoNombre->nombreProducto??'',
                                'noCasoTerminal' => $noAfiliacion,
                                'direccion' => $direccion,
                                'isBanco' => $isBanco
                            );
                        }

                    }
                }
                $arrFinal['casos'] = $result??[];
            }
            return $this->ResponseSuccess('ok', $arrFinal);
        }
        else {
            return $this->ResponseError('RU-AF5863282', 'Error al obtener Requisitos');
        }
    }
    public function acceptaContrato($cliente,Request $request){
        try {
            $validateForm = Validator::make($request->all(),
                [
                    'clienteData' => 'required'
                ]);

            if ($validateForm->fails()) {

                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos '.implode(',', $errores));
            }
            else{
                $cliente = Clientes::with('expedientes.expedienteDetail')->where('token', $cliente)->first();
                $tarea = Tareas::where('slug','ONB-GENERACION-CONTRATO')->first();
                //Lo siento niñita ya no tuve tiempo
                if($cliente->segmento == 2){
                    $expediente = $cliente->expedientes->id??0;
                    $tareasRespuesta = new ExpedientesTareasRespuestas();
                    $tareasRespuesta->idTarea = $tarea->id;
                    $tareasRespuesta->idExpediente = $expediente;
                    $tareasRespuesta->usuario = 0;
                    $respuestaTarea = array(
                        "ada" => "N/D",
                        "mcc" => "N/D",
                        "giro" => $request->clienteData['giroComercial']??'',
                        "tasa" => "1.5%",
                        "tarea" => "ONB-GENERACION-CONTRATO",
                        "expediente" => $expediente
                    );
                    //dd($expediente);
                    $jsonRespuesta = json_encode($respuestaTarea);
                    $tareasRespuesta->respuesta = $jsonRespuesta;
                    $tareasRespuesta->save();
                    $etapaId = Etapas::where('slug','=','revision')->first();
                    $expedienteEtapa = ExpedientesEtapas::where('expedienteId', '=', $expediente)->first();
                    //dd($expedienteEtapa);
                    if(!empty($expedienteEtapa)){
                        //Esto activa el listener
                        $expedienteEtapa->etapaId = $etapaId->id;
                        $cliente->ordenGeneral = $etapaId->orden??2;
                        $cliente->save();
                        $expedienteEtapa->save();
                    }
                    else{
                        $expedienteEtapa = new ExpedientesEtapas();
                        $expedienteEtapa->expedienteId = $expediente;
                        $expedienteEtapa->etapaId = $etapaId->id;
                        $cliente->ordenGeneral = $etapaId->orden??2;
                        $cliente->save();
                        $expedienteEtapa->save();
                    }

                }
                $requisitos = Requisitos::select('expedientes.id', 'expedientes_detail.requisitoValor')
                    ->join('expedientes_detail', 'requisitos.id', '=', 'expedientes_detail.requisitoId')
                    ->join('expedientes', 'expedientes_detail.expedienteId', '=', 'expedientes.id')
                    ->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')
                    ->join('expedientes_tareas_respuestas', 'expedientes.id', '=', 'expedientes_tareas_respuestas.idExpediente')
                    ->join('tareas', 'expedientes_tareas_respuestas.idTarea', '=', 'tareas.id')
                    ->where('tareas.slug', '=', 'ONB-GENERACION-CONTRATO')
                    ->where('clientes.id', '=', $cliente->id??0)
                    ->groupBy('expedientes.id', 'expedientes_detail.requisitoValor')
                    ->get();
                //dd($request->clienteData);

                //dd($requisitos);


                $arrayValores = array(
                    'nombreComercial',
                    'actividad',
                    'NIT',
                    'regimen',
                    'tipoPersona',
                    'cui',
                    'nombres',
                    'apellidos',
                    'razonSocial',
                    'correo',
                    'monedaCuenta',
                    'direccion',
                    'direccionFiscal',
                    'Banco',
                    'tipoCuenta',
                    'noCuenta',
                );


                $valores = [];
                $arrFinal = [];
                foreach ($requisitos as $requisito){
                    $tareasRespuesta = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea',$tarea->id??0)->first();
                    //dd($tareasRespuesta);
                    if(is_null($tareasRespuesta->vistoBueno)){
                        $tareasRespuesta->vistoBueno = now()->setTimezone('America/Guatemala');
                    }
                    $tareasRespuesta->save();
                    $arrFinal['vistoBueno'] = $tareasRespuesta->vistoBueno;
                    $tareasRespuesta = json_decode($tareasRespuesta->respuesta,true);
                    $arrValor = json_decode($requisito->requisitoValor, true);
                    if(is_array($arrValor)){
                        foreach ($arrValor as $valorFinal) {

                            $valores[$valorFinal['Alias']] = !empty($valorFinal['valor-validado'])?$valorFinal['valor-validado']:$valorFinal['valor'];
                        }
                    }
                }

                $encabezados = $arrayValores;

                if (!empty($requisitos)) {
                    foreach ($encabezados as $encabezado) {
                        $arrFinal[$encabezado] =  $valores[$encabezado]??'';
                    }
                    foreach ($tareasRespuesta as $key => $item) {
                        $arrFinal[$key] = $item;
                    }
                    //dd($arrFinal);
                    return $this->ResponseSuccess('ok', $arrFinal);
                }
                else {
                    return $this->ResponseError('RU-AF5863282', 'Error al obtener Requisitos');
                }
            }
        }
        catch (\Exception $e){
            dd($e);
        }
        //dd($cliente);

    }

}
