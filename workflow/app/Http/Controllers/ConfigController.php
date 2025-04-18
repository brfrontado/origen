<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Configuration;
use App\Models\SistemaVariable;
use App\Models\Archivador;
use App\Models\CotizacionLoteOrden;
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

        $items = SistemaVariable::all();

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
                $row->save();
            }
            else {
                SistemaVariable::updateOrCreate(['slug' => $var['slug']], ['contenido'  => $var['contenido']]);
            }
        }

        return $this->ResponseSuccess('Variables actualizadas con éxito');
    }

    public function deleteVars(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();
        $varId = $request->get('idVars');

        $var = SistemaVariable::where('id', $varId)->first();
        if (!empty($var)) {
            $var->delete();
            return $this->ResponseSuccess('Eliminado con éxito', $var);
        }
        else {
            return $this->ResponseError('AUTH-AFd10dwF', 'Variable no existe');
        }
    }

    public function getLoteOrder(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();
        // $varId = $request->get('idVars');
        $productoId = $request->get('productoId');
        $orden = CotizacionLoteOrden::where('productoId', $productoId)->get();
        return $this->ResponseSuccess('Información obtenida con éxito', $orden);
    }

    public function saveLoteOrder(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();
        $productoId = $request->get('productoId');
        $orden = $request->get('orden');

        CotizacionLoteOrden::where('productoId', $productoId)->delete();

        foreach ($orden as $item) {
            $order = new CotizacionLoteOrden();
            $order->productoId = $productoId;
            $order->campo = $item['nombre'];
            $order->orden = $item['order'] ?? 0;
            $order->useForSearch = $item['useForSearch'] ? 1 : 0;
            $order->save();
        }

        return $this->ResponseSuccess('Información guardada éxito');
    }

}
