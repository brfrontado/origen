<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Rol;
use App\Models\RolAccess;
use App\Models\RolApp;
use App\Models\User;
use App\Models\UserRol;
use App\Models\Archivador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AppController extends Controller {

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

    public function Load($rolId) {

        $item = Archivador::where([['id', '=', $rolId]])->first();

        if (!empty($item)) {

            $appUrl = env('APP_URL', '');

            $item->integrationScriptHtml = <<<EOD
<!-- INICIA INTEGRACIÓN SSO EL ROBLE -->
<script>
(function(w,d,s,l,i,c){
    window.ERSSO = {t: i, h: {}, c: c};
    let f=d.getElementsByTagName(s)[0],
    j=d.createElement(s);j.async=true;j.src=
    '{$appUrl}/ERLd.js';f.parentNode.insertBefore(j,f);
})(window,document,'script','elRoble','{$item->token}', function (response) {
    // Agregar aquí el callback después de iniciar sesión
    console.log(response);
});
</script>
<!-- FIN DE INTEGRACIÓN SSO EL ROBLE -->
EOD;

            $item->integrationScriptVue = <<<EOD
/* INICIA INTEGRACIÓN SSO EL ROBLE */
(function(w,d,s,l,i,c){
    window.ERSSO = {t: i, h: {}, c: c};
    let f=d.getElementsByTagName(s)[0],
    j=d.createElement(s);j.async=true;j.src=
    '{$appUrl}/ERLd.js';f.parentNode.insertBefore(j,f);
})(window,document,'script','elRoble','{$item->token}', function (response) {
    // Agregar aquí el callback después de iniciar sesión
    console.log(response);
});
/* INICIA INTEGRACIÓN SSO EL ROBLE */
EOD;

            return $this->ResponseSuccess('Ok', $item);
        }
        else {
            return $this->ResponseError('Aplicación inválida');
        }
    }

    public function Save(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $roleId = $request->get('id');
        $nombre = $request->get('nombre');
        $descripcion = $request->get('descripcion');
        $logoutUrl = $request->get('logoutUrl');
        $loginUrl = $request->get('loginUrl');
        $activa = $request->get('activa');
        $logo = $request->file('logo');

        $activa = ($activa === 'true' || $activa === true) ? true : false;

        if (!empty($roleId)) {
            $item = Archivador::where([['id', '=', $roleId]])->first();
        }
        else {
            $item = new Archivador();
            $item->token = $this->token(30);
        }

        if (empty($item)) {
            return $this->ResponseError('APP-5412', 'Aplicación no válida');
        }

        $item->nombre = $nombre;
        $item->descripcion = $descripcion;
        $item->urlLogout = $logoutUrl;
        $item->urlLogin = $loginUrl;
        $item->activa = $activa;

        if (!empty($logo)){
            $path = $logo->getRealPath();
            $logo = file_get_contents($path);
            $base64 = base64_encode($logo);
            $item->logo = $base64;
        }

        $item->save();

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
