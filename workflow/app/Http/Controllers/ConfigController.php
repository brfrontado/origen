<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Configuration;
use App\Models\Cotizacion;
use App\Models\SistemaVariable;
use App\Models\Archivador;
use App\Models\SystemConsumo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class ConfigController extends Controller {

    use Response;

    private function token($length = 50) {
        $bytes = random_bytes($length);
        return bin2hex($bytes);
    }

    public function GetList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $itemList = Archivador::all();

        $response = [];

        foreach ($itemList as $item) {
            $response[] = [
                'id' => $item->id,
                'nombre' => $item->nombre,
                'urlLogin' => $item->urlLogin,
                'logo' => $item->logo,
            ];
        }

        if (!empty($itemList)) {
            return $this->ResponseSuccess('Ok', $response);
        }
        else {
            return $this->ResponseError('Error al obtener aplicaciones');
        }
    }

    public function Load() {

        $items = Configuration::all();

        $config = [];
        foreach ($items as $item) {
            $config[$item->slug] = ($item->typeRow === 'json') ? @json_decode($item->dataText) : $item->dataText;
        }

        if (!empty($config)) {
            return $this->ResponseSuccess('Ok', $config);
        }
        else {
            return $this->ResponseError('Error al obtener configuración');
        }
    }


    public function GetVars() {

        $items = SistemaVariable::where('marcaId', SSO_BRAND_ID)->get();

        if (!empty($items)) {
            return $this->ResponseSuccess('Ok', $items);
        }
        else {
            return $this->ResponseError('CNF-214', 'Error al obtener variables de sistema');
        }
    }

    public function SaveVars(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $vars = $request->get('vars');

        foreach ($vars as $var) {
            if (!empty($var['id'])) {
                $row = SistemaVariable::find($var['id']);
                $row->slug = $var['slug'];
                $row->contenido = $var['contenido'];
                $row->marcaId = SSO_BRAND_ID;
                $row->save();
            }
            else {
                SistemaVariable::updateOrCreate(['slug' => $var['slug']], ['contenido'  => $var['contenido']]);
            }
        }

        return $this->ResponseSuccess('Variables actualizadas con éxito');
    }

    public function reportConsumo(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $cotizacionToken = $request->get('t');
        $typeConsumo = $request->get('ty');
        $data = $request->get('d');

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionToken]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-412', 'Cotización no válida');
        }

        $consumo = new SystemConsumo();
        $consumo->marcaId = $cotizacion->marcaId;
        $consumo->cotizacionId = $cotizacion->id;
        $consumo->tipo = $typeConsumo;
        $consumo->data = $data;
        $consumo->save();

        return $this->ResponseSuccess('Load');
    }



}
