<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Alerta;
use Illuminate\Http\Request;

class AlertasController extends Controller {

    use Response;

    // conexiones
    public function getList(Request $request) {

        $items = Alerta::where('marcaId', SSO_BRAND_ID)->get();

        $response = [];
        foreach ($items as $item) {

            $item->configData = @json_decode($item->configData, true);

            $response[] = [
                'id' => $item->id,
                'token' => $item->token,
                'nombre' => $item->nombre,
                'type' => $item->type,
                'modoActivo' => $item->modoActivo,
                'configData' => $item->configData,
                'textAlert' => $item->textAlert,
                'activo' => $item->activo,
            ];
        }

        return $this->ResponseSuccess('Alertas obtenidas con éxito', $response);
    }

    public function getAlert(Request $request) {
        $id = $request->get('id');
        $item = Alerta::where('marcaId', SSO_BRAND_ID)->where('id', $id)->get(['id', 'nombre','token', 'type', 'textAlert', 'modoActivo', 'activo', 'configData'])->first();

        if (empty($item)) {
            return $this->ResponseError('CONN-451', 'Conexión inválida');
        }

        $item = $item->toArray();
        $item['configData'] = @json_decode($item['configData']);

        return $this->ResponseSuccess('Alerta obtenida con éxito', $item);
    }

    public function save(Request $request) {

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $type = $request->get('type');
        $modoActivo = $request->get('modoActivo');
        $textAlert = $request->get('textAlert');
        $configData = $request->get('configData');
        $activo = $request->get('activo');
        //$wasaTpl = $request->get('waTpl');

        $item = Alerta::where('marcaId', SSO_BRAND_ID)->where('id', $id)->first();

        if (empty($item)) {
            $item = new Alerta();
            $item->token = trim(bin2hex(random_bytes(4)));
        }

        $item->marcaId = SSO_BRAND_ID;
        $item->nombre = $nombre;
        $item->type = $type;
        $item->modoActivo = $modoActivo;
        $item->textAlert = $textAlert;
        $item->activo = $activo;
        $item->configData = @json_encode($configData);
        $item->save();

        return $this->ResponseSuccess('Alerta guardada con éxito', ['id' => $item->id]);
    }

}
