<?php
namespace App\Http\Controllers;

use app\core\Response;
use app\models\ExpedientesDetail;
use App\Models\Flujos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\TemplateProcessor;
use Dompdf\Dompdf;
use Dompdf\Options;

class FlujosController extends Controller {

    use Response;
    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */
    public function getFlujoDisp(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        try {
            $validateForm = Validator::make($request->all(), [
                'producto' => '',
            ]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-OIWEURY5', 'Faltan Campos');
            }

            if ($request->producto > 0) {
                // Realizar la consulta RAW
                $flujo = Flujos::where('productoId', '=', $request->producto)->orderByDesc('id')->get();
                return $this->ResponseSuccess( 'Flujo obtenido con éxito', $flujo);
            }
            else {
                return $this->ResponseError('FR-458', 'Producto inválido');
            }
        }
        catch (\Throwable $th) {
            return $this->ResponseError('AUTH-547', 'Error al generar tareas'.$th );
        }
    }

    private function recursiveStripTags($data) {

        $arrCamposLimpieza = [
            'ArchivadorCampo', 'Color', 'CssClass', 'Currency', 'Desc', 'Id', 'Label', 'Nombre', 'Ph', 'Tipo', 'Ttp', 'Type', 'Ttp', 'TypeObject'
        ];

        foreach ($data as $key => $value) {
            if(is_array($value)) {
                $data[$key] = $this->recursiveStripTags($value);
            }
            else {
                $data[$key] = strip_tags($value);
                var_dump($key);
            }
        }
        return $data;
    }

    public function modificarFlujo(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        try {
            $validateForm = Validator::make($request->all(), [
                'flujo' => '',
                'producto' => '',
                'flujoId' => '',
                'nombre' => '',
                'activo' => '',
                'modoPruebas' => '',
                'borrar' => '',
            ]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-OIWEURY5', 'Faltan Campos');
            }
            if(!empty($request->flujo)){

                $flujo = Flujos::where('id', '=', $request->flujoId)->first();

                if(!empty($request->activo)){
                    $productoId = $request->producto;
                    // Actualizar los flujos que coinciden con el productoId
                    Flujos::where('productoId', $productoId)->update(['activo' => 0]);
                }

                // limpieza de json
                //$request->flujo = $this->recursiveStripTags($request->flujo);
                //dd($flujoLimpio);

                if(empty($request->flujoId)){
                    $flujo = new Flujos();
                    $nombreString = $request->nombre??'';
                    $flujo->nombre = $nombreString.'_nuevo';
                    $flujo->flujo_config = json_encode($request->flujo, JSON_UNESCAPED_UNICODE);
                    $flujo->productoId = $request->producto;

                    $flujo->activo = (!empty($request->activo));
                    $flujo->modoPruebas = (!empty($request->modoPruebas));
                    //dd($flujo->flujo_config);
                    $flujo->save();
                    return $this->ResponseSuccess( 'Flujo guardado con éxito', $flujo);
                }
                else {
                    $flujo->nombre = $request->nombre??'';
                    $flujo->flujo_config = json_encode($request->flujo, JSON_UNESCAPED_UNICODE);
                    $flujo->productoId = $request->producto;
                    $flujo->activo = (!empty($request->activo));
                    $flujo->modoPruebas = (!empty($request->modoPruebas));
                    if($flujo->save()){

                        return $this->ResponseSuccess( 'Flujo guardado con éxito', $flujo);
                    }
                    else{
                        return $this->ResponseSuccess( 'Flujo guardado con éxito', ['nope']);
                    }
                }

            }
            else{
                return $this->ResponseSuccess( 'Flujo guardado con éxito', ['sinflujo']);
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-LKSAUYDI38', 'Error al generar tarea'.$th, );
        }
    }


    public function descargarFlujo(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        try {
            $validateForm = Validator::make($request->all(), [
                'flujo' => '',
                'producto' => '',
                'flujoId' => '',
                'nombre' => '',
                'activo' => '',
                'modoPruebas' => '',
                'borrar' => '',
            ]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-OIWEURY5', 'Faltan Campos');
            }
            if(!empty($request->flujo)){

                $flujo = Flujos::where('id', '=', $request->flujoId)->first();

                if(!empty($request->activo)){
                    $productoId = $request->producto;
                    // Actualizar los flujos que coinciden con el productoId
                    Flujos::where('productoId', $productoId)->update(['activo' => 0]);
                }

                // limpieza de json
                //$request->flujo = $this->recursiveStripTags($request->flujo);
                //dd($flujoLimpio);

                if(empty($request->flujoId)){
                    $flujo = new Flujos();
                    $nombreString = $request->nombre??'';
                    $flujo->nombre = $nombreString.'_nuevo';
                    $flujo->flujo_config = json_encode($request->flujo, JSON_UNESCAPED_UNICODE);
                    $flujo->productoId = $request->producto;

                    $flujo->activo = (!empty($request->activo));
                    $flujo->modoPruebas = (!empty($request->modoPruebas));
                    //dd($flujo->flujo_config);
                    $flujo->save();
                    return $this->ResponseSuccess( 'Flujo guardado con éxito', $flujo);
                }
                else {
                    $flujo->nombre = $request->nombre??'';
                    $flujo->flujo_config = json_encode($request->flujo, JSON_UNESCAPED_UNICODE);
                    $flujo->productoId = $request->producto;
                    $flujo->activo = (!empty($request->activo));
                    $flujo->modoPruebas = (!empty($request->modoPruebas));
                    if($flujo->save()){

                        return $this->ResponseSuccess( 'Flujo guardado con éxito', $flujo);
                    }
                    else{
                        return $this->ResponseSuccess( 'Flujo guardado con éxito', ['nope']);
                    }
                }

            }
            else{
                return $this->ResponseSuccess( 'Flujo guardado con éxito', ['sinflujo']);
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-LKSAUYDI38', 'Error al generar tarea'.$th, );
        }
    }

    public function uploadPdfTemplate(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/plantillas-pdf'])) return $AC->NoAccess();

        $archivo = $request->file('file');

        $fileType = $archivo->getMimeType();
        if ($fileType !== 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return $this->ResponseError('TPL-524', 'Tipo de archivo no válido para plantilla PDF, solo se aceptan archivos tipo Word ');
        }

        $domPdfPath = base_path('vendor/dompdf/dompdf');
        \PhpOffice\PhpWord\Settings::setPdfRendererPath($domPdfPath);
        \PhpOffice\PhpWord\Settings::setPdfRendererName('DomPDF');

        $Content = \PhpOffice\PhpWord\IOFactory::load($archivo->getRealPath());
        $PDFWriter = \PhpOffice\PhpWord\IOFactory::createWriter($Content,'PDF');
        $PDFWriter->save(storage_path('tmp/'.uniqid().'.pdf'));

        $templateProcessor = new TemplateProcessor('tmp/'.uniqid().'.pdf');
        $templateProcessor->setValue('firstname', 'John');
        $templateProcessor->setValue('lastname', 'Doe');

        dd('tmp/'.uniqid().'html');


        if (!is_array($archivos)) {
            $archivos = array($archivos);
        }

        foreach ($archivos as $index => $archivo) {




            //$extension = pathinfo($archivo->getPathname(), PATHINFO_EXTENSION);
            list($type, $subtype) = explode('/', $fileType);

            if ($fileType == 'application/pdf' || $fileType == 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {

                if ($fileType == 'application/pdf') {
                    $arrImagenes = $this->convertPdfToImages($archivo, $request->requisito, $request->cliente);
                    //dd($arrImagenes);
                }
                if ($fileType == 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                    $arrImagenes = $this->convertWordToImages($archivo, $request->requisito, $request->cliente);
                }

                if (is_array($arrImagenes) && !empty($arrImagenes)) {
                    foreach ($arrImagenes as $key => $image) {
                        $hashName = md5($image->hashName()); // Obtiene el nombre generado por Laravel
                        $extension = pathinfo($image->getPathname(), PATHINFO_EXTENSION); // Obtener la extensión del archivo
                        if (empty($extension)) $extension = 'pdf';
                        $filenameWithExtension = $hashName . '.' . $extension; // Concatena el nombre generado por Laravel con la extensión

                        $publicString = 'private';

                        try {
                            $path = Storage::disk('s3')->putFileAs($dir, $image, $filenameWithExtension, $publicString);
                            $arrFinal = $this->extractText($path, $request->requisito);
                            $detail = new ExpedientesDetail();
                            $detail->expedienteId = $expedienteId;
                            $detail->requisitoId = $request->requisito;
                            $detail->requisitoS3Key = $path;
                            //dd($archivo->getClientOriginalName());
                            $detail->requisitoValor = $filenameWithExtension ?? '';
                            $detail->requisitoOCR = json_encode($arrFinal);

                            date_default_timezone_set('America/Guatemala');
                            //traigo la url temporal
                            $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(50));
                            if ($detail->save()) {
                                $todoOk[$key]['id'] = $detail->id;
                                $todoOk[$key]['req'] = (int)$request->requisito;
                                $todoOk[$key]['link'] = $url;
                                $todoOk[$key]['status'] = true;
                                $todoOk[$key]['detalle'] = [];
                                $todoOk[$key]['nombre'] = $archivo->getClientOriginalName();
                                $todoOk[$key]['ocr'] = $arrFinal;
                            }
                        } catch (\Exception $e) {
                            //$response['msg'] = 'Error en subida, por favor intente de nuevo '.$e;
                            return $this->ResponseError('FILE-AF2459440F', 'Error al cargar archivo ' . $e);
                        }
                    }
                }

                $arrPrev = $this->previewChanges($expedienteId);
                //dd($detalle);
                $todoOk['detail'] = $arrPrev['textract'] ?? [];
                $todoOk['ocr'] = $arrPrev['textract'] ?? [];
                $todoOk['preview'] = $arrPrev['preview'] ?? [];
                $todoOk['formFinal'] = $arrPrev['formulario'] ?? [];
                return $this->ResponseSuccess('archivo subido con éxito', $todoOk);
            }
        }

    }

    public function printFlujo(Request $request) {
        ini_set('max_execution_time', 400);
        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        try {
        $htmlForPrint = $request->html;
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => FALSE,
                'verify_peer_name' => FALSE,
                'allow_self_signed' => TRUE
            ]
        ]);

        $dompdf->setHttpContext($context);
        $htmlVar = <<<EOT
            <!DOCTYPE html>
            <html lang="es">
            <head>        
                <style>
                    *,
                    ::after,
                    ::before {
                        box-sizing: border-box;
                    }
                
                    body {
                        font-size: 12px;
                        font-weight: 400;
                        line-height: 1em;
                        font-family: 'helvetica', sans-serif;
                        margin: auto;
                        width: 80%
                    }
                    .noBreakPage {
                        page-break-inside: avoid;
                    }
                    
                    .tm_receta_top {
                        margin-bottom: 18px;
                    }
                    
                    .tm_receta_sample_text {
                        padding: 0px 0 8px;
                        line-height: 1.6em;
                        color: #9c9c9c;
                    }    
            
                    .imgLogo {
                        max-height : 150px;
                        max-width: 200px;
                        margin-bottom: 20px;
                    }            
                    
                    .tm_receta_company_name {
                        color: #111;
                        font-size: 16px;
                        line-height: 1.4em;
                        font-weight: bold;
                    }        
                    
                    .tm_receta_body{
                        width: 100%;
                        border: 1px solid #efefef;
                        padding: 10px;
                        border-radius: 5px;
                    }
                    
                    .text-center{
                        width:100%;
                        text-align: center;
                    }
                    
                    .tbl_contenido{
                        width: 100%;
                    }
                    
                    .tbl_contenido td{
                        padding-bottom: 15px
                    }
                    
                    .border_down{
                        border-bottom: 1px solid #eeeeee;
                    }
                </style> 
            </head>
            <body class = "noBreakPage">
                {$htmlForPrint}
            </body>
            </html>
            EOT;

        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($htmlVar);
        $dompdf->render();

        $pdfTmp = $dompdf->output();
        $tmpFile = storage_path("tmp/" . md5(uniqid()) . ".pdf");

        file_put_contents($tmpFile, $pdfTmp);

        $arrContextOptions = array(
            "ssl" => array(
                "verify_peer" => false,
                "verify_peer_name" => false,
            ),
        );
        $response = file_get_contents($tmpFile, false, stream_context_create($arrContextOptions));
        $type = 'pdf';
        $dataPDF = 'data:application/pdf;base64,' . base64_encode($response);

        return $this->ResponseSuccess("Impresión exitosa", ['type' => 'pdf', 'basePDF' => $dataPDF]);
    }
    catch (\Throwable $th) {
        return $this->ResponseError('FPD-547', 'Error al generar impresion' );
    }

    }

}
