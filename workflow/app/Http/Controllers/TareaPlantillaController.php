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
use App\Models\TareaPlantilla;
use App\Models\TareaPlantillaDetalle;
use App\Models\TareaPlantillaPaso;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TareaPlantillaController extends Controller {

    use Response;

    public function GetList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $itemList = TareaPlantilla::all();

        $response = [];

        foreach ($itemList as $item) {
            $response[] = [
                'id' => $item->id,
                'nombre' => $item->nombre,
                'descripcion' => $item->descripcion,
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
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $itemList = TareaPlantilla::all();

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

        $item = TareaPlantilla::where([['id', '=', $rolId]])->with('paso', 'paso.campos', 'paso.campos.archivadorDetalle', 'paso.campos.archivadorDetalle.archivador')->first();

        if (!empty($item)) {

            $arrSecciones = $item->toArray();

            usort($arrSecciones['paso'], function ($a, $b) {
                if ($a['orden'] > $b['orden']) {
                    return 1;
                }
                elseif ($a['orden'] < $b['orden']) {
                    return -1;
                }
                return 0;
            });

            return $this->ResponseSuccess('Ok', $arrSecciones);
        }
        else {
            return $this->ResponseError('Plantilla inválida');
        }
    }

    public function Save(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $descripcion = $request->get('descripcion');
        $iniciaEstado = $request->get('iniciaEstado');
        $finEstado = $request->get('finEstado');
        //$urlAmigable = $request->get('urlAmigable');
        $activo = $request->get('activo');
        $pasos = $request->get('pasos');

        if (!empty($id)) {
            $item = TareaPlantilla::where([['id', '=', $id]])->first();
        }
        else {
            $item = new TareaPlantilla();
        }

        $activo = ($activo === 'true' || $activo === true) ? true : false;

        if (empty($item)) {
            return $this->ResponseError('APP-5412', 'Plantilla no válida');
        }

        $item->nombre = $nombre;
        $item->descripcion = $descripcion;
        $item->iniciaEstado = $iniciaEstado;
        $item->finEstado = $finEstado;
        $item->activo = $activo;
        $item->save();

        // guardo secciones
        foreach ($pasos as $paso) {
            //dd($seccion);

            if (!empty($paso['id'])) {
                $seccionTmp = TareaPlantillaPaso::where([['id', '=', $paso['id']]])->first();
            }
            else {
                $seccionTmp = new TareaPlantillaPaso();
            }

            if (empty($seccionTmp)) {
                return $this->ResponseError('APP-S5412', 'Sección inválida');
            }

            $seccionTmp->nombre = $paso['nombre'] ?? 'Sin nombre de sección';
            $seccionTmp->tareaPlantillaId = $item->id;
            $seccionTmp->instrucciones = $paso['instrucciones'];
            $seccionTmp->orden = $paso['orden'];
            $seccionTmp->save();

            // traigo todos los campos
            foreach ($paso['campos'] as $campo) {

                if (empty($campo['id'])) {
                    $campoTmp = new TareaPlantillaDetalle();
                }
                else {
                    $campoTmp = TareaPlantillaDetalle::where('id', $campo['id'])->first();
                }

                $campoTmp->tareaPlantillaId = $item->id;
                $campoTmp->pasoId = $seccionTmp->id;
                $campoTmp->archivadorDetalleId = $campo['archivadorDetalleId'];
                $campoTmp->nombre = $campo['nombre'];
                $campoTmp->layoutSizePc = $campo['layoutSizePc'] ?? 4;
                $campoTmp->layoutSizeMobile = $campo['layoutSizeMobile'] ?? 12;
                $campoTmp->cssClass = $campo['cssClass'] ?? '';
                $campoTmp->requerido = $campo['requerido'] ?? 0;
                $campoTmp->deshabilitado = $campo['deshabilitado'] ?? 0;
                $campoTmp->visible = $campo['visible'] ?? 1;
                $campoTmp->activo = $campo['activo'] ?? 1;

                $campoTmp->save();
            }
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
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $item = Formulario::find($id);

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

    public function DeletePaso(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $item = TareaPlantillaPaso::find($id);

            if (!empty($item)) {
                $item->delete();
                return $this->ResponseSuccess('Eliminado con éxito', $item->id);
            }
            else {
                return $this->ResponseError('AUTH-R5320', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            var_dump($th->getMessage());
            return $this->ResponseError('AUTH-R53020', 'Error al eliminar');
        }
    }

    public function DeleteField(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');

        $campoTmp = FormularioDetalle::where('id', $id)->first();
        if (!empty($campoTmp)) {
            $campoTmp->delete();
        }

        return $this->ResponseSuccess('Campo eliminado con éxito');
    }

    // Otros
    public function GetFilteredList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

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
