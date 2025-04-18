<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\ConfiguracionOCR;
use App\Models\PdfTemplate;
use App\Models\SistemaVariable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OcrController extends Controller {

    use Response;

    public function getDocPlusPDFList(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.env('ANY_SUBSCRIPTIONS_TOKEN')
        );

        $ch = curl_init(env('ANY_SUBSCRIPTIONS_URL', '').'/formularios/all/1/2500');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $dataResponse = @json_decode($data, true);

        $arrTpl = [];

        if (!empty($dataResponse['data']['formularios'])) {
            foreach ($dataResponse['data']['formularios'] as $key => $value) {
                $arrTpl[$value['id']] = [
                    't' => $value['token'],
                    'n' => $value['descripcion'],
                ];
            }
        }

        return $this->ResponseSuccess('Plantillas obtenidas con éxito', $arrTpl);
    }

    public function getDocPlusList(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/ocr_config'])) return $AC->NoAccess();

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.env('ANY_SUBSCRIPTIONS_TOKEN')
        );

        $ch = curl_init(env('ANY_SUBSCRIPTIONS_URL', '').'/formularios/docs-plus/ocr-templates');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $dataResponse = @json_decode($data, true);

        $arrTpl = [];

        if (!empty($dataResponse['data'])) {
            foreach ($dataResponse['data'] as $key => $value) {
                $arrTpl[$value['id']] = [
                    't' => $value['token'],
                    'n' => $value['nombre'],
                ];
            }
        }

        return $this->ResponseSuccess('Plantillas obtenidas con éxito', $arrTpl);
    }

    public function getList(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/ocr_config'])) return $AC->NoAccess();

        $item = ConfiguracionOCR::all();

        return $this->ResponseSuccess('Configuración obtenida con éxito', $item);
    }

    public function getTemplate(Request $request, $id) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/ocr_config'])) return $AC->NoAccess();
        $item = ConfiguracionOCR::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('OCR-145', 'Error al obtener configuración');
        }

        $item->configuracion = @json_decode($item->configuracion);

        return $this->ResponseSuccess('Plantilla obtenida con éxito', $item);
    }

    public function saveTemplate(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/ocr_config'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $configuracion = $request->get('configuracion');
        $activo = $request->get('activo');

        $item = ConfiguracionOCR::where('id', $id)->first();
        $fileNameHash = md5(uniqid());

        if (empty($item)) {
            $item = new ConfiguracionOCR();
            $item->id = $id;
        }
        $item->nombre = $nombre;
        $item->activo = intval($activo);
        $item->configuracion = json_encode($configuracion);
        $item->save();

        return $this->ResponseSuccess('Configuració guardada con éxito', ['id' => $item->id]);
    }

    public function deleteTemplate(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/ocr_config'])) return $AC->NoAccess();

        $id = $request->get('id');
        $item = ConfiguracionOCR::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('OCR-145', 'Configuración inválida');
        }

        $item->delete();

        return $this->ResponseSuccess('Plantilla eliminada con éxito', $item);
    }
}
