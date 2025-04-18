<?php
namespace App\Http\Controllers;

use App\core\Response;
use App\Models\Clientes;
use App\Models\Etapas;
use App\Models\Expedientes;
use App\Models\ExpedientesDetail;
use App\Models\ExpedientesEtapas;
use App\Models\Requisitos;
use App\Jobs\UploadToS3Job;
use app\models\RequisitosAsignacion;
use Illuminate\Http\Request;
use Illuminate\Http\File as LaravelFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use Spatie\PdfToImage\Exceptions\PdfIsEncrypted;
use Aws\Textract\TextractClient;
use RecursiveIteratorIterator;
use RecursiveArrayIterator;
use Illuminate\Support\Facades\DB;



class RequisitosController extends Controller {
    use Response;

    public function uploadRequisito(Request $request){
        try {
            //dd($request->all());
            $validateForm = Validator::make($request->all(),
                [
                    'files.*' => 'file',
                    'requisito' => 'required|integer',
                    'cliente' => 'required',
                ]);

            if ($validateForm->fails()) {
                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('CLIENT-AF5ds834', 'Faltan Campos ' . implode(',', $errores));
            }
            $cliente = Clientes::with('expedientes')->where('token', $request->cliente)->first();
            $expedienteId = $cliente->expedientes->id??0;

            if(!empty($cliente)){
                if(empty($expedienteId)){
                    $expediente = new Expedientes();
                    $expediente->clienteId = $cliente->id;
                    $expediente->save();
                    $expedienteId = $expediente->id??0;
                }
                $dir = '/'.$cliente->id.md5('visanet'.$cliente->id);
                $todoOk = [];
                $archivos = $request->file('files');

                if (!is_array($archivos)) {
                    $archivos = array($archivos);
                }

                foreach ($archivos as $index => $archivo) {
                    $fileType = $archivo->getMimeType();
                    //$extension = pathinfo($archivo->getPathname(), PATHINFO_EXTENSION);
                    list($type, $subtype) = explode('/', $fileType);
                    
                    if($fileType =='application/pdf' || $fileType == 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'){

                        if($fileType =='application/pdf'){
                            $arrImagenes = $this->convertPdfToImages($archivo, $request->requisito, $request->cliente);
                            //dd($arrImagenes);
                        }
                        if($fileType =='application/vnd.openxmlformats-officedocument.wordprocessingml.document'){
                            $arrImagenes = $this->convertWordToImages($archivo, $request->requisito, $request->cliente);
                        }

                        if(is_array($arrImagenes) && !empty($arrImagenes)){
                            foreach ($arrImagenes as $key => $image) {
                                $hashName = md5($image->hashName()); // Obtiene el nombre generado por Laravel
                                $extension = pathinfo($image->getPathname(), PATHINFO_EXTENSION); // Obtener la extensión del archivo
                                if(empty($extension)) $extension = 'pdf';
                                $filenameWithExtension = $hashName . '.' . $extension; // Concatena el nombre generado por Laravel con la extensión

                                $publicString = 'private';

                                try {
                                    $path = Storage::disk('s3')->putFileAs(
                                        $dir,
                                        $image,
                                        $filenameWithExtension,
                                        $publicString
                                    );
                                    $arrFinal = $this->extractText($path, $request->requisito);
                                    $detail = new ExpedientesDetail();
                                    $detail->expedienteId = $expedienteId;
                                    $detail->requisitoId = $request->requisito;
                                    $detail->requisitoS3Key = $path;
                                    //dd($archivo->getClientOriginalName());
                                    $detail->requisitoValor = $filenameWithExtension??'';
                                    $detail->requisitoOCR = json_encode($arrFinal);

                                    date_default_timezone_set('America/Guatemala');
                                    //traigo la url temporal
                                    $url = Storage::disk('s3')->temporaryUrl(
                                        $path,
                                        now()->addMinutes(50)
                                    );
                                    if($detail->save()){
                                        $todoOk[$key]['id'] = $detail->id;
                                        $todoOk[$key]['req'] = (int) $request->requisito;
                                        $todoOk[$key]['link'] = $url;
                                        $todoOk[$key]['status'] = true;
                                        $todoOk[$key]['detalle'] = [];
                                        $todoOk[$key]['nombre'] = $archivo->getClientOriginalName();
                                        $todoOk[$key]['ocr'] = $arrFinal;
                                    }
                                }
                                catch (\Exception $e) {
                                    //$response['msg'] = 'Error en subida, por favor intente de nuevo '.$e;
                                    return $this->ResponseError('FILE-AF2459440F', 'Error al cargar archivo '.$e);
                                }
                            }
                        }

                        $arrPrev = $this->previewChanges($expedienteId);
                        //dd($detalle);
                        $todoOk['detail'] = $arrPrev['textract']??[];
                        $todoOk['ocr'] = $arrPrev['textract']??[];
                        $todoOk['preview'] = $arrPrev['preview']??[];
                        $todoOk['formFinal'] = $arrPrev['formulario']??[];
                        return $this->ResponseSuccess( 'archivo subido con éxito', $todoOk);
                    }
                    elseif($type === 'image' && in_array($subtype, ['jpeg', 'png', 'gif', 'webp'])) {

                        $uploaded = \Disk::validateFileUploaded(
                            $archivo,
                            50,
                            [
                                'image/jpg',
                                'image/jpeg',
                                'image/png',
                                'image/gif',
                                'application/pdf',
                                'application/msword'
                            ],
                            $dir

                        );

                        if ($uploaded['status']) {
                            date_default_timezone_set('America/Guatemala');
                            //traigo la url temporal
                            $url = Storage::disk('s3')->temporaryUrl(
                                $uploaded['filePath'],
                                now()->addMinutes(50)
                            );
                            //Traigo la data de mi archivo para escanearlo con ocr
                            $arrFinal = $this->extractText($uploaded['filePath'], $request->requisito);

                            $detail = new ExpedientesDetail();
                            $detail->expedienteId = $expedienteId;
                            $detail->requisitoId = $request->requisito;
                            $detail->requisitoS3Key = $uploaded['filePath'];
                            $detail->requisitoValor = $archivo->getClientOriginalName();
                            $detail->requisitoOCR = json_encode($arrFinal);
                            $detail->save();

                            $todoOk[$index]['id'] = $detail->id;
                            $todoOk[$index]['req'] = (int) $request->requisito;
                            $todoOk[$index]['link'] = $url;
                            $todoOk[$index]['status'] = true;
                            $todoOk[$index]['detalle'] = $uploaded;
                            $todoOk[$index]['nombre'] = $archivo->getClientOriginalName();
                            $todoOk[$index]['ocr'] = $arrFinal;
                            // disco para el usuario registrado
                            $arrPrev = $this->previewChanges($expedienteId);
                            //dd($detalle);
                            $todoOk['ocr'] = $arrPrev['textract']??[];
                            $todoOk['preview'] = $arrPrev['preview']??[];
                            $todoOk['formFinal'] = $arrPrev['formulario']??[];

                        }
                        else{
                            $todoOk[$index]['id']=0;
                            $todoOk[$index]['req']=0;
                            $todoOk[$index]['link']='';
                            $todoOk[$index]['status']=false;
                            $todoOk[$index]['nombre']=$archivo->getClientOriginalName();
                            $todoOk[$index]['detalle'] = [];
                            $todoOk[$index]['ocr'] = [];
                        }
                    }
                    else {
                        dd('error');
                    }

                }
                if(!empty($todoOk)){
                    return $this->ResponseSuccess( 'archivo subido con éxito', $todoOk);
                }
                else{
                    dd('error');
                }

            }
            else{
                return $this->ResponseSuccess( 'No se encontró al cliente', []);
            }

        }
        catch (\Throwable $th) {
            return $this->ResponseError('CLIENT-AF699440F1', 'Error al actualizar el cliente '.$th);
        }





        // devolver una respuesta adecuada
    }

    public function getAsignacion(Request $request){
        $usuario = auth('sanctum')->user();
        $rolName = $usuario->getRoleNames()[0] ?? '';
        $query = "
            SELECT
                r.*,
                a.*,
                t.nombre as t_nombre,
                p.*,
                cs.nombre as cs_nombre,
                rc.nombre as rc_nombre
            FROM
                requisitos r
            LEFT JOIN
                requisitos_asignacion a ON r.id = a.requisitoId
            LEFT JOIN
                tareas t ON a.tareaId = t.id
            LEFT JOIN
                productos p ON a.productoId = p.id
            LEFT JOIN
                canales_segmentos cs ON a.segmentoId = cs.id
            LEFT JOIN
                requisitos_categorias rc ON a.categoriaRequisitoId = rc.id
            
        ";

        $requisito = DB::select($query);
        $results = collect($requisito)
            ->groupBy('requisitoId')
            ->mapWithKeys(function ($items, $id) use ($rolName){
                $asignaciones = $items->map(function ($item) {
                    return [
                        'tarea' => [
                            'id' => $item->tareaId,
                            'nombre' => $item->t_nombre,
                        ],
                        'producto' => [
                            'id' => $item->productoId,
                            'nombre' => $item->nombreProducto,
                        ],
                        'segmento' => [
                            'id' => $item->segmentoId,
                            'nombre' => $item->cs_nombre,
                        ],
                        'categoria' => [
                            'id' => $item->categoriaRequisitoId,
                            'nombre' => $item->rc_nombre,
                        ],
                    ];
                })->values();

                $tareas = $asignaciones
                    ->pluck('tarea')
                    ->filter(function ($tarea) {
                        return !empty($tarea['id']) && $tarea['id'] !== 0;
                    })
                    ->unique('id')
                    ->map(function ($tarea) {
                        return $tarea['id'];
                    })->values();

                $categorias = $asignaciones
                    ->pluck('categoria')
                    ->filter(function ($categoria) {
                        return !empty($categoria['id']) && $categoria['id'] !== 0;
                    })
                    ->unique('id')
                    ->map(function ($categoria) {
                        return $categoria['id'];
                    })->values();

                $segmentos = $asignaciones
                    ->pluck('segmento')
                    ->filter(function ($segmento) {
                        return !empty($segmento['id']) && $segmento['id'] !== 0;
                    })
                    ->unique('id')
                    ->map(function ($segmento) {
                        return $segmento['id'];
                    })->values();

                $productos = $asignaciones
                    ->pluck('producto')
                    ->filter(function ($producto) {
                        return !empty($producto['id']) && $producto['id'] !== 0;
                    })
                    ->unique('id')
                    ->map(function ($producto) {
                        return $producto['id'];
                    })->values();

                $asignaciones = [];
                if ($tareas->isNotEmpty()) {
                    $asignaciones['tareas'] = $tareas;
                }
                if ($categorias->isNotEmpty()) {
                    $asignaciones['categorias'] = $categorias;
                }
                if ($segmentos->isNotEmpty()) {
                    $asignaciones['segmentos'] = $segmentos;
                }
                if ($productos->isNotEmpty()) {
                    $asignaciones['productos'] = $productos;
                }

                return [
                    $id => [
                        'id' => $items[0]->requisitoId,
                        'title' => $items[0]->label,
                        'desc' => $items[0]->desc,
                        'multiple' => $items[0]->multiple,
                        'rolName' => $rolName??'',
                        'queryConfig' => $items[0]->queryConfig,
                        'requireBackground' => $items[0]->requireBackground,
                        'isPaymentReq' => $items[0]->isPaymentReq,
                        'asignaciones' => $asignaciones,
                    ]
                ];
            })
            ->toArray();

        return $this->ResponseSuccess('No se encontró al cliente', $results);

    }
    public function addRequisito(Request $request) {
        try {
            $validateForm = Validator::make($request->all(), [
                'title' => 'nullable|string',
                'id' => '',
                'delete' => 'nullable|boolean',
                'tareas' => '',
            ]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-OIWEURY5', 'Faltan Campos');
            }
            if(empty($request->delete)) if(empty($request->id)){
                $etapas = new Requisitos();
                $etapas->nombre = $request->label??'';
                //$etapas->slug = Str::slug($request->label??'');

                if($etapas->save()){
                    if(is_array($request->tareas)){
                        foreach ($request->tareas as $etapa) {
                            $etapaTarea = new RequisitosAsignacion();
                            $etapaTarea->requisitoId = $etapas->id;
                            $etapaTarea->tareaId = $etapa;
                            $etapaTarea->save();
                        }
                    }
                    return $this->ResponseSuccess( 'Ok', $etapas);
                }
                else{
                    return $this->ResponseSuccess( 'Ok', []);
                }
            }
            else{
                $etapas = Requisitos::where('id', '=', $request->id)->first();
                $etapas->nombre = $request->title??$etapas->label;
                //Borro las asignaciones:

                //$etapa->slug = Str::slug($request->nombre??'');

                if($etapas->save()){
                    RequisitosAsignacion::where('tareaId',$request->id)->delete();
                    if(is_array($request->tareas)){
                        foreach ($request->tareas as $etapa) {
                            $etapaTarea = new TareasEtapas();
                            $etapaTarea->idTarea = $etapas->id;
                            $etapaTarea->idEtapa = $etapa;
                            $etapaTarea->save();
                        }
                    }
                    return $this->ResponseSuccess( 'Ok', $etapas);
                }
                else{
                    return $this->ResponseSuccess( 'Ok', []);
                }
            }
            else{
                if(!empty($request->id)){
                    Requisitos::where('id', '=', $request->id)->delete();
                    RequisitosAsignacion::where('requisitoId',$request->id)->delete();
                    return $this->ResponseSuccess( 'Ok', []);
                }
            }



        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-LKSAUYDI38', 'Error al generar tarea'.$th, );
        }
    }

    public function deleteRequisito(Request $request){
        try {
            $validateForm = Validator::make($request->all(),
                [
                    'requisito' => 'required|integer',
                    'cliente' => 'required',

                ]);

            if ($validateForm->fails()) {
                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('FILE-AF5ds834', 'Faltan Campos ' . implode(',', $errores));
            }
            $cliente = Clientes::with('expedientes')->where('token', $request->cliente)->first();
            $expedienteId = $cliente->expedientes->id??0;

            if(!empty($cliente)){
                if(!empty($expedienteId)){
                    $detalle = ExpedientesDetail::where('expedienteId','=',$expedienteId)
                        ->where('requisitoId','=',$request->requisito);
                    if(!empty($detalle->get())){
                        foreach ($detalle->get() as $item) {
                            if(!empty($item->requisitoS3Key)){
                                Storage::disk('s3')->delete($item->requisitoS3Key);
                            }

                        }
                    }
                    if($detalle->delete()){
                        $arrPrev = $this->previewChanges($expedienteId);
                        //dd($detalle);
                        $todoOk['ocr'] = $arrPrev['textract']??[];
                        $todoOk['preview'] = $arrPrev['preview']??[];
                        $todoOk['formFinal'] = $arrPrev['formulario']??[];
                        return $this->ResponseSuccess( 'archivo eliminado con éxito', $todoOk);
                    }
                }
                else{
                    return $this->ResponseError('FILE-AS5678', 'Error al eliminar archivos, no se encontró al expediente');
                }

            }
            else{
                return $this->ResponseSuccess( 'No se encontró al cliente', []);
            }

        }
        catch (\Throwable $th) {
            return $this->ResponseError('CLIENT-AF699440F1', 'Error al actualizar el cliente '.$th);
        }





        // devolver una respuesta adecuada
    }

    public function saveInfoRequisito(Request $request){
        try {
            $validateForm = Validator::make($request->all(),
                [
                    'requisito' => 'required|integer',
                    'cliente' => 'required',
                    'formulario' => 'required'

                ]);

            if ($validateForm->fails()) {
                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('FILE-AF5ds834', 'Faltan Campos ' . implode(',', $errores));
            }
            $cliente = Clientes::with('expedientes')->where('token', $request->cliente)->first();
            $expedienteId = $cliente->expedientes->id??0;

            if(!empty($cliente)){
                if(!empty($expedienteId)){
                    $detalle = ExpedientesDetail::where('expedienteId','=',$expedienteId)
                        ->where('requisitoId','=',$request->requisito);
                    if(!empty($detalle->get())){
                        foreach ($detalle->get() as $item) {
                            $item->requisitoValor = json_encode($request->formulario);
                            $item->save();
                            //Storage::disk('s3')->delete($item->requisitoS3Key);
                        }
                    }
                    $arrPrev = $this->previewChanges($expedienteId);
                    //dd($detalle);
                    $todoOk['ocr'] = $arrPrev['textract']??[];
                    $todoOk['preview'] = $arrPrev['preview']??[];
                    $todoOk['formFinal'] = $arrPrev['formulario']??[];
                    return $this->ResponseSuccess( 'archivo actualizado con éxito', $todoOk);

                }
                else{
                    return $this->ResponseError('FILE-AS5678', 'Error al eliminar archivos, no se encontró al expediente');
                }

            }
            else{
                return $this->ResponseSuccess( 'No se encontró al cliente', []);
            }

        }
        catch (\Throwable $th) {
            return $this->ResponseError('CLIENT-AF699440F1', 'Error al actualizar el cliente '.$th);
        }





        // devolver una respuesta adecuada
    }

    public function changeToRevision(Request $request){
        try {
            $validateForm = Validator::make($request->all(),
                [
                    'cliente' => 'required',
                    'categoriaSelected' => 'required'

                ]);

            if ($validateForm->fails()) {
                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('FILE-AF5ds834', 'Faltan Campos ' . implode(',', $errores));
            }
            $cliente = Clientes::with('expedientes')->where('token', $request->cliente)->first();
            $expedienteId = $cliente->expedientes->id??0;
            $objExpediente = Expedientes::where('id', '=',$expedienteId)->first();
            if(empty($expedienteId)){
                $objExpedienteN = new Expedientes();
                $objExpedienteN->clienteId = $cliente->id??0;
                $objExpedienteN->requisitoCategoriaId = $request->categoriaSelected;
                $objExpedienteN->save();
                $expedienteId = $objExpedienteN->id??0;
            }
            else{
                $objExpediente->requisitoCategoriaId = $request->categoriaSelected;
                $objExpediente->save();
            }
            $etapaId = Etapas::where('slug','=','revision')->first();
            $etapaRevision = Etapas::where('slug','=','revisionre')->first();
            $expedienteEtapa = ExpedientesEtapas::where('expedienteId', '=',$expedienteId)->first();
            $cliente->ordenGeneral = $etapaId->orden;
            if($cliente->segmento != 2 || $etapaRevision??0 == $expedienteEtapa->etapaId) {
                $cliente->save();
            }

            if(empty($expedienteEtapa)){
                $expedienteEtapaN = new ExpedientesEtapas();
                $expedienteEtapaN->expedienteId = $expedienteId;
                //Esto lo hago para activar el listener y que se envie el correo de notificacion
                $expedienteEtapaN->save();
                if($cliente->segmento != 2){
                    $expedienteEtapaN->etapaId = $etapaId->id;
                    $expedienteEtapaN->save();
                }

            }
            else{
                if($cliente->segmento != 2 || $etapaRevision??0 == $expedienteEtapa->etapaId) {
                    $expedienteEtapa->etapaId = $etapaId->id;
                    $expedienteEtapa->save();
                }
            }
            $arrPrev = $this->previewChanges($expedienteId);
            $todoOk['ocr'] = $arrPrev['textract']??[];
            $todoOk['preview'] = $arrPrev['preview']??[];
            $todoOk['formFinal'] = $arrPrev['formulario']??[];
            return $this->ResponseSuccess( 'archivo actualizado con éxito', $todoOk);
        }
        catch (\Throwable $th) {
            return $this->ResponseError('CLIENT-AF699440F1', 'Error al actualizar el cliente '.$th);
        }





        // devolver una respuesta adecuada
    }

    public function extractText($filePath, $reqId){
        $requisito = Requisitos::where('id','=',$reqId)->first();
        $config=[];
        if(!empty($requisito->queryConfig)){
            $config = json_decode($requisito->queryConfig, true);
            foreach ($config as &$query) {
                unset($query['Type']);
                unset($query['Label']);
                unset($query['Options']);
                unset($query['Pattern']);
            }

        }
       //dd($config);

        $client = new TextractClient([
            'region' => env('AWS_DEFAULT_REGION'),
            'version' => 'latest',
            'credentials' => [
                'key'    => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY')
            ]
        ]);
        $arrayAnalize['Document']['S3Object']['Bucket'] = env('AWS_BUCKET');
        $arrayAnalize['Document']['S3Object']['Name'] = $filePath;
        $arrayAnalize['FeatureTypes'][] = 'FORMS';
        if(!empty($config) && empty($requisito->requireBackground)) {
            $arrayAnalize['FeatureTypes'][] = 'QUERIES';
            $arrayAnalize['QueriesConfig']['Queries'] = $config;
            $result = $client->analyzeDocument($arrayAnalize);
        }
        //dd($arrayAnalize);
        $blocks = $result['Blocks']??[];
        $words = $this->searchArrayes($blocks, 'BlockType', 'WORD');
        $wordsN = array();
        foreach ($words as $word){
            $wordsN[$word['Id']] = $word['Text'];
        }
        //Loop through all the blocks:
        $form = array();
        $queries = array();
        $contador = 0;
        $contadorQ = 0;
        foreach ($blocks as $value) {
            if ($value["BlockType"] === 'KEY_VALUE_SET' && $value["EntityTypes"][0] === 'KEY') {
                $key='';
                $valor='';
                foreach ($value['Relationships'] as $relationship) {
                    if($relationship['Type'] === 'CHILD'){
                        foreach ($relationship['Ids'] as $word) {
                            if (array_key_exists($word, $wordsN)) {
                                $key .= (empty($key ?? '') && !empty($wordsN[$word])) ? $wordsN[$word] : " " . $wordsN[$word];
                            }
                        }
                    }
                    if($relationship['Type'] === 'VALUE'){
                        foreach ($relationship['Ids'] as $word) {
                            $arrValores = $this->searchArrayes($result["Blocks"], 'Id', $word);
                            if(isset($arrValores[0]['Relationships'])){
                                foreach ($arrValores[0]['Relationships'] as $relationshipValue){
                                    if($relationshipValue['Type'] === 'CHILD'){
                                        foreach ($relationshipValue['Ids'] as $word) {
                                            if (array_key_exists($word, $wordsN)) {
                                                $valor .= (empty($valor))?$wordsN[$word]??'':" ".$wordsN[$word]??'';
                                            }

                                        }
                                    }

                                }
                            }
                        }
                    }
                    if(!empty(trim($key))){
                        $form[$contador]['key']=$key;
                        $form[$contador]['valor']=$valor;
                        $form[$contador]['id']=$value["Id"];
                        $form[$contador]['bloque']=$value;
                        $arrFinal['bloques'][] = $value;
                        $contador++;
                    }
                }
            }
            if ($value["BlockType"] === 'QUERY') {
                $key = $value["Query"]['Alias'];
                $valor='';
                if(isset($value['Relationships'])){
                    foreach ($value['Relationships'] as $relationship) {
                        if($relationship['Type'] === 'ANSWER'){
                            foreach ($relationship['Ids'] as $word) {
                                $arrValores = $this->searchArrayes($result["Blocks"], 'Id', $word);
                                if(isset($arrValores[0]['Text'])){
                                    $valor = $arrValores[0]['Text'];
                                }
                            }
                        }
                        if(!empty($key)){
                            $queries[$contadorQ]['key']=$key;
                            $queries[$contadorQ]['valor']=$valor;
                            $queries[$contadorQ]['id']=$value["Id"];
                            $queries[$contadorQ]['bloque']=$value;
                            $arrFinal['bloques'][] = $value;
                            $contadorQ++;
                        }
                    }
                }

            }
        }
        $arrFinal = [];
        if($blocks){
            $arrFinal['formularios'] = $form;
            $arrFinal['queries'] = $queries;
        }
        return $arrFinal;
    }

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
    public function array_search_recursive($array, $key, $value) {
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($array));

        foreach ($iterator as $leaf) {
            $subArray = $iterator->getSubIterator();
            if ($subArray->key() == $key && $leaf == $value) {
                return true;
            }
        }

        return false;
    }


    /**
     * @throws \ImagickException
     */
    public function convertPdfToImages($file, $requisito, $cliente) {
        if ($file->getClientMimeType() != 'application/pdf') {
            return false;
        }
        $requisitos = Requisitos::where('id',$requisito)->first();
        $images = [];
        $maxSize = 4000000; // Tamaño máximo permitido para el archivo generado
        $originalResolution = 280; // Resolución original de las imágenes generadas
        $reductionPercentage = 20; // Porcentaje de reducción de la resolución, Más es menor resolución
        $quality = 80; //Calidad de la imagen
        $resolution = 0; //Calidad de la imagen
        $reducedResolution = floor($originalResolution * (100 - $reductionPercentage) / 100); // Calcular la resolución reducida
        //dd($requisitos);

        //Si no tiene que mandarse a aws no lo convierto
        if (!empty($requisitos->queryConfig) && empty($requisitos->requireBackground)) {


            $imagick = new \Imagick();
            $imagick->setOption('policy', 'coder "PDF" { permission read; }');

            $imagick->readImage($file->getRealPath());

            // Definir el tamaño máximo deseado (tamaño carta)
            $maxWidth = 612; // 8.5 pulgadas * 72 ppp
            $maxHeight = 792; // 11 pulgadas * 72 ppp

            // Obtener las dimensiones actuales de la imagen
            $actualWidth = $imagick->getImageWidth();
            $actualHeight = $imagick->getImageHeight();
            // Calcular las nuevas dimensiones manteniendo la proporción de aspecto
            if ($actualWidth > $maxWidth || $actualHeight > $maxHeight) {
                $imagick->scaleImage($maxWidth, $maxHeight, true);
            }

            $numero_paginas = $imagick->getNumberImages();


            if ((filesize($file->getRealPath()) > $maxSize) || $numero_paginas > 20) {
                if ($numero_paginas > 20 && (filesize($file->getRealPath()) < $maxSize)) {
                    $reductionPercentage = 40;
                    $quality = 60;
                    $reducedResolution = floor($originalResolution * (100 - $reductionPercentage) / 100); // Calcular la resolución reducida
                    $resolution = $reducedResolution;
                    $imagick->setResolution($resolution, $resolution);
                }
                else if ($numero_paginas < 5 && (filesize($file->getRealPath()) > $maxSize)) {
                    $reductionPercentage = 40; // Porcentaje de reducción de la resolución, Más es menor resolución
                    $reducedResolution = floor($originalResolution * (100 - $reductionPercentage) / 100); // Calcular la resolución reducida
                    // Si el tamaño del archivo supera el límite de 10, utilizar la resolución reducida
                    $resolution = $reducedResolution;
                    $imagick->setResolution($resolution, $resolution);
                }
                elseif ($numero_paginas > 20 && (filesize($file->getRealPath()) > $maxSize)) {
                    $reductionPercentage = 50;
                    $quality = 50;
                    $reducedResolution = floor($originalResolution * (100 - $reductionPercentage) / 100); // Calcular la resolución reducida
                    $resolution = $reducedResolution;
                    $imagick->setResolution($resolution, $resolution);
                }

            }
            else {
                // Si el tamaño del archivo no supera el límite de 7MB, utilizar la resolución original
                //dd('sapo');
                //$imagick = new \Imagick();
                //$imagick->setOption('policy', 'coder "PDF" { permission read; }');
                $imagick->setResolution($originalResolution, $originalResolution);

                //$imagick->readImage($file->getRealPath());

                // Definir el tamaño máximo deseado (tamaño carta)
                $maxWidth = 612; // 8.5 pulgadas * 72 ppp
                $maxHeight = 792; // 11 pulgadas * 72 ppp

                // Obtener las dimensiones actuales de la imagen
                $actualWidth = $imagick->getImageWidth();
                $actualHeight = $imagick->getImageHeight();
                // Calcular las nuevas dimensiones manteniendo la proporción de aspecto
                if ($actualWidth > $maxWidth || $actualHeight > $maxHeight) {
                    $imagick->scaleImage($maxWidth, $maxHeight, true);
                }

                // $resolution = $originalResolution;

            }

            $originalName = $file->getClientOriginalName();
            $counter = 0;
            $hash = md5($originalName);

            foreach ($imagick as $image) {
                $counter++;

                // Establecer el formato y la calidad de la imagen
                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality($quality);
                $tempFile = tempnam(sys_get_temp_dir(), $hash) . '-page-' . $counter . '.jpg';
                $image->writeImage($tempFile);
                $images[] = new LaravelFile($tempFile);
            }
            return $images;
        }
        else{
            $images[] = $file;
            return $images;
        }
    }

    public function convertWordToImages($file, $requisito, $cliente) {
        if ($file->getClientMimeType() != 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return false;
        }
        Settings::setPdfRendererName(Settings::PDF_RENDERER_MPDF);
        Settings::setPdfRendererPath(base_path().'/vendor/mpdf/mpdf');

        $phpWord = IOFactory::load($file->getRealPath());
        $pdfWriter = IOFactory::createWriter($phpWord, 'PDF');
        $tempFile = tempnam(sys_get_temp_dir(), uniqid()).'.pdf';
        ini_set('pcre.backtrack_limit', '1000000000');

        $pdfWriter->save($tempFile);


        $tempFile = new UploadedFile($tempFile, uniqid().'.pdf', 'application/pdf', filesize($tempFile));

        $arrImagenes = $this->convertPdfToImages($tempFile, $requisito, $cliente);
        return $arrImagenes;
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
                            $item->requisitoS3Key??'',
                            now()->addMinutes(50)
                        );
                    }
                    else{
                        $url='';
                    }

                    $preview[$item->requisitoId][$contador]['requisitoId'] = $item->requisitoId;
                    $preview[$item->requisitoId][$contador]['src'] = $url;
                    // Obtener la extensión del archivo
                    $extension = pathinfo($item->requisitoS3Key, PATHINFO_EXTENSION);

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
            $arrFinal['detalle'] = $detalle;
            $arrFinal['preview'] = $preview;
            $arrFinal['textract'] = $textract;
            $arrFinal['formulario'] = $formularioFinal;

            return $arrFinal;
        }
        else{
            return false;
        }
    }

}