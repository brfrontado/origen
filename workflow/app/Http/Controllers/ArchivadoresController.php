<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Rol;
use App\Models\RolAccess;
use App\Models\RolApp;
use App\Models\User;
use App\Models\UserRol;
use App\Models\Archivador;
use App\Models\ArchivadorDetalle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ArchivadoresController extends Controller {

    use Response;
    public function GetList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['archivadores/admin'])) return $AC->NoAccess();

        $itemList = Archivador::all();

        $response = [];

        foreach ($itemList as $item) {
            $response[] = [
                'id' => $item->id,
                'nombre' => $item->nombre,
                'activo' => $item->activo,
            ];
        }

        if (!empty($itemList)) {
            return $this->ResponseSuccess('Ok', $response);
        }
        else {
            return $this->ResponseError('Error al obtener aplicaciones');
        }
    }

    public function GetListFields() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['archivadores/admin'])) return $AC->NoAccess();

        $itemList = Archivador::all();

        $response = [];

        foreach ($itemList as $item) {

            $campos = $item->detalle;

            foreach ($campos as $campo) {
                $response[] = [
                    'id' => $campo->id,
                    'nombre' => "{$item->nombre} / {$campo->nombre}",
                ];
            }
        }

        if (!empty($itemList)) {
            return $this->ResponseSuccess('Ok', $response);
        }
        else {
            return $this->ResponseError('Error al obtener aplicaciones');
        }
    }

    public function Load($rolId) {

        $item = Archivador::where([['id', '=', $rolId]])->with('detalle')->first();

        if (!empty($item)) {
            return $this->ResponseSuccess('Ok', $item);
        }
        else {
            return $this->ResponseError('Aplicación inválida');
        }
    }

    public function Save(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['archivadores/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $activo = $request->get('activo');

        $campos = $request->get('campos');

        $activo = ($activo === 'true' || $activo === true) ? true : false;

        if (!empty($id)) {
            $item = Archivador::where([['id', '=', $id]])->first();
        }
        else {
            $item = new Archivador();
        }

        if (empty($item)) {
            return $this->ResponseError('APP-5412', 'Archivador no válido');
        }

        $item->nombre = $nombre;
        $item->activo = $activo;
        $item->save();

        // traigo todos los campos
        foreach ($campos as $campo) {
            if (empty($campo['id'])) {
                $campoTmp = new ArchivadorDetalle();
            }
            else {
                $campoTmp = ArchivadorDetalle::where('id', $campo['id'])->first();
            }

            $campoTmp->archivadorId = $item->id;
            $campoTmp->nombre = $campo['nombre'];
            $campoTmp->tipoCampo = $campo['tipoCampo'];
            $campoTmp->mascara = $campo['mascara'];
            $campoTmp->longitudMin = $campo['longitudMin'];
            $campoTmp->longitudMax = $campo['longitudMax'];
            $campoTmp->save();
        }

        if (!empty($item)) {
            return $this->ResponseSuccess('Guardado con éxito', $item->id);
        }
        else {
            return $this->ResponseError('AUTH-RL934', 'Error al crear rol');
        }
    }

    public function Delete(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['archivadores/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $item = Archivador::find($id);

            if (!empty($item)) {
                $item->delete();
                return $this->ResponseSuccess('Eliminado con éxito', $item->id);
            }
            else {
                return $this->ResponseError('AUTH-R5321', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            var_dump($th->getMessage());
            return $this->ResponseError('AUTH-R5302', 'Error al eliminar');
        }
    }

    public function DeleteField(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['archivadores/admin'])) return $AC->NoAccess();

        $id = $request->get('id');

        $campoTmp = ArchivadorDetalle::where('id', $id)->first();
        if (!empty($campoTmp)) {
            $campoTmp->delete();
        }

        return $this->ResponseSuccess('Campo eliminado con éxito');
    }

    // Otros
    public function GetFilteredList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['archivadores/admin'])) return $AC->NoAccess();

        $user = auth('sanctum')->user();
        $appsForUserTmp = $user->apps;

        // apps para user
        $appsForUser = [];
        foreach ($appsForUserTmp as $app) {
            $appsForUser[$app->appId] = true;
        }

        // apps para rol
        $rolUsuario = $user->rolAsignacion;
        $appsForRolTmp = RolApp::where([['rolId', '=', $rolUsuario->rolId]])->get();

        $appsForRol = [];
        foreach ($appsForRolTmp as $app) {
            $appsForRol[$app->appId] = true;
        }

        $itemList = Archivador::all();

        $response = [];

        foreach ($itemList as $item) {

            // chequeo que esté en el usuario
            if (!isset($appsForUser[$item->id])) {

                // chequeo que esté en el rol
                if (!isset($appsForRol[$item->id])) {
                    continue;
                }
            }

            $response[] = [
                'id' => $item->id,
                'nombre' => $item->nombre,
                'descripcion' => substr($item->descripcion, 0, 60),
                'logo' => $item->logo,
                'urlLogin' => $item->urlLogin,
                'urlLogout' => $item->urlLogout,
                'token' => $item->token,
            ];
        }

        if (!empty($itemList)) {
            return $this->ResponseSuccess('Ok', $response);
        }
        else {
            return $this->ResponseError('Error al obtener aplicaciones');
        }
    }
}
