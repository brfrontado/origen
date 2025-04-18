<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Extra\ClassCache;
use app\models\ExpedientesDetail;
use App\Models\Flujos;
use App\Models\Productos;
use App\Models\SistemaVariable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\TemplateProcessor;

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
                return $this->ResponseSuccess('Flujo obtenido con éxito', $flujo);
            }
            else {
                return $this->ResponseError('FR-458', 'Producto inválido');
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-547', 'Error al generar tareas' . $th);
        }
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

            $newVersion = $request->nv;

            $productoId = $request->producto ?? 0;

            // valido el producto
            $productoTmp = Productos::where('id', $productoId)->where('marcaId', SSO_BRAND_ID)->first();
            if (empty($productoTmp)) {
                return $this->ResponseError('FLOW-PRBR', 'Flujo inválido');
            }

            $cache = ClassCache::getInstance();

            if (!empty($request->flujo)) {

                if (!empty($request->activo)) {
                    // Actualizar los flujos que coinciden con el productoId
                    Flujos::where('productoId', $productoId)->update(['activo' => 0]);
                }

                if (empty($request->flujoId)) {
                    $flujo = new Flujos();
                    $nombreString = $request->nombre ?? '';
                    $flujo->nombre = $nombreString . '_nuevo';
                    $flujo->version = uniqid();
                    $flujo->flujo_config = json_encode($request->flujo, JSON_UNESCAPED_UNICODE);
                    $flujo->productoId = $request->producto;

                    $flujo->activo = (!empty($request->activo));
                    $flujo->modoPruebas = (!empty($request->modoPruebas));
                    //dd($flujo->flujo_config);
                    $flujo->save();

                    $cache->setMemcached("FL_PR_{$productoId}", false, 300);
                    $cache->setMemcached("FL_CF_{$flujo->id}", false, 300);

                    return $this->ResponseSuccess('Flujo guardado con éxito', $flujo);
                }
                else if(!empty($request->flujoId)) {
                    $flujo = Flujos::where('id', '=', $request->flujoId)->where('productoId', $request->producto)->first();
                    $flujo->version = uniqid();
                    $flujo->nombre = $request->nombre ?? '';
                    $flujo->flujo_config = json_encode($request->flujo, JSON_UNESCAPED_UNICODE);
                    $flujo->productoId = $request->producto;
                    $flujo->activo = (!empty($request->activo));
                    $flujo->modoPruebas = (!empty($request->modoPruebas));
                    if ($flujo->save()) {
                        $cache->setMemcached("FL_PR_{$productoId}", false, 300);
                        $cache->setMemcached("FL_CF_{$flujo->id}", false, 300);
                        return $this->ResponseSuccess('Flujo guardado con éxito', $flujo);
                    }
                    else {
                        return $this->ResponseSuccess('Flujo guardado con éxito', ['nope']);
                    }
                }

            }
            else {
                return $this->ResponseSuccess('Flujo guardado con éxito', ['sinflujo']);
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-LKSAUYDI38', 'Error al generar tarea' . $th);
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
        $PDFWriter = \PhpOffice\PhpWord\IOFactory::createWriter($Content, 'PDF');
        $PDFWriter->save(storage_path('tmp/' . uniqid() . '.pdf'));

        $templateProcessor = new TemplateProcessor('tmp/' . uniqid() . '.pdf');
        $templateProcessor->setValue('firstname', 'John');
        $templateProcessor->setValue('lastname', 'Doe');

        dd('tmp/' . uniqid() . 'html');


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

    public function getDocPlusList(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $usuarioLogueado = auth('sanctum')->user();

        if (empty($usuarioLogueado->marcaId)) {
            return $this->ResponseError('ERR039', 'Error al obtener plantillas Docs Studio');
        }

        $arrTpl = [];

        $payGatewayAPIKEY = SistemaVariable::where('slug', 'API_PAYGATEWAY')->where('marcaId', $usuarioLogueado->marcaId)->first();

        if (!empty($payGatewayAPIKEY->contenido)) {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$payGatewayAPIKEY->contenido ?? ''
            );

            $ch = curl_init(env('PAYGATEWAY_API_URL', '').'/formularios/docs-plus/ocr-templates');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $data = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            $dataResponse = @json_decode($data, true);

            if (!empty($dataResponse['data'])) {
                foreach ($dataResponse['data'] as $key => $value) {
                    $arrTpl[$value['id']] = [
                        't' => $value['token'],
                        'n' => $value['nombre'],
                    ];
                }
            }

        }


        return $this->ResponseSuccess('Plantillas obtenidas con éxito', $arrTpl);
    }

    public function getDocPlusListPDF(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $usuarioLogueado = auth('sanctum')->user();

        if (empty($usuarioLogueado->marcaId)) {
            return $this->ResponseError('ERR039', 'Error al obtener plantillas Docs Studio');
        }

        $arrTpl = [];

        $payGatewayAPIKEY = SistemaVariable::where('slug', 'API_PAYGATEWAY')->where('marcaId', $usuarioLogueado->marcaId)->first();

        if (!empty($payGatewayAPIKEY->contenido)) {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$payGatewayAPIKEY->contenido ?? ''
            );

            $ch = curl_init(env('PAYGATEWAY_API_URL', '').'/formularios/all');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $data = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            $dataResponse = @json_decode($data, true);
            // var_dump($dataResponse);

            if (!empty($dataResponse['data'])) {
                foreach ($dataResponse['data'] as $key => $value) {
                    $arrTpl[$value['id']] = [
                        't' => $value['token'],
                        'd' => $value['descripcion'],
                    ];
                }
            }

        }


        return $this->ResponseSuccess('Plantillas obtenidas con éxito', $arrTpl);
    }
}
