<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Configuration;
use App\Models\Rol;
use App\Models\Archivador;
use App\Models\RolAccess;
use App\Models\RolApp;
use App\Models\User;
use App\Models\UserApp;
use App\Models\UserCanal;
use App\Models\UserCanalGrupo;
use App\Models\UserJerarquia;
use App\Models\UserJerarquiaDetail;
use App\Models\UserJerarquiaSupervisor;
use App\Models\UserRol;
use App\Models\UserLog;
use App\Models\UserGrupo;
use App\Models\UserGrupoRol;
use App\Models\UserGrupoUsuario;
use App\Models\Productos;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

use Mailgun\Mailgun;
use Matrix\Exception;

class AuthController extends Controller {

    use Response;

    private function checkPassword($pwd) {
        $errors = [];
        $errors['status'] = 1;
        $errors['error'] = [];
        $errors['show'] = '';

        $lenght = 8;

        if (strlen($pwd) < $lenght) {
            $errors['status'] = 0;
            $errors['error'][] = "debe tener al menos {$lenght} caracteres";
        }

        if (!preg_match("#[0-9]+#", $pwd)) {
            $errors['status'] = 0;
            $errors['error'][] = "debe incluir al menos un número";
        }

        if (!preg_match("#[a-zA-Z]+#", $pwd)) {
            $errors['status'] = 0;
            $errors['error'][] = "debe incluir al menos una letra";
        }

        $errors['show'] = implode(', ', $errors['error']);
        $errors['show'] = "La contraseña {$errors['show']}";

        return $errors;
    }

    public function loginValidate(Request $request) {

        $tokenBearer = $request->bearerToken();
        $sso = $this->validateSSO($tokenBearer);

        if (empty($sso['status'])) {
            return $this->ResponseError($sso['error-code'], $sso['msg']);
        }
        else {
            $user = User::where('token', $sso['data']['token'])->where('ssoToken', $tokenBearer)->first();
            if (empty($user)) {
                return $this->ResponseError('AUTH-457', 'Usuario inválido');
            }
            auth('sanctum')->setUser($user);

            // accesos
            $getUserAccess = $this->GetUserAccess();

            return $this->ResponseSuccess('Usuario logueado', [
                'logged' => 1,
                'name' => $sso['data']['name'],
                'email' => $sso['data']['email'],
                'username' => $sso['data']['username'],
                'm' => $getUserAccess,
            ]);
        }
    }

    public function validateSSO($token) {
        $headers = ['Cache-Control: no-cache','Content-Type: application/json', 'Authorization: Bearer '.$token];
        $data = [
            'app' => env('APP_DOMAIN')
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SSO_URL')."/sso/auth/validate");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));  //Post Fields
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec ($ch);
        curl_close ($ch);
        //dd($response);
        $response = @json_decode($response, true);
        if (isset($response['status'])) {
            return $response;
        }
        else {
            return [
                'status' => 0,
                'msg' => 'Error al iniciar sesión',
            ];
        }
    }

    public function closeSessionSSO($token) {
        $headers = ['Cache-Control: no-cache','Content-Type: application/json', 'Authorization: Bearer '.$token];
        $data = [
            'utoken' => $token
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SSO_URL')."/sso/auth/close");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));  //Post Fields
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec ($ch);
        curl_close ($ch);
        //var_dump($response);
        $response = @json_decode($response, true);
        if (isset($response['status'])) {
            return $response;
        }
        else {
            return [
                'status' => 0,
                'msg' => 'Error al iniciar sesión',
            ];
        }
    }

    public function loginUser(Request $request) {
        try {
            $validateUser = Validator::make($request->all(),
                [
                    'ssotoken' => 'required',
                ]);

            if ($validateUser->fails()) {
                return $this->ResponseError('AUTH-AF10F', 'Debe enviar el token');
            }

            $validoSso = $this->validateSSO($request['ssotoken']);

            if (empty($validoSso['status'])) return $this->ResponseError($validoSso['error-code'], $validoSso['msg']);

            if ($validoSso['status']) {
                $user = User::where('token', $validoSso['data']['token'])->first();

                // si el usuario no existe, lo tengo que crear
                if (empty($user)) {
                    $user = new User();
                    $user->email_verified_at = Carbon::now()->format('Y-m-d H:i:s');
                    $user->token = $validoSso['data']['token'];
                }

                $user->name = $validoSso['data']['name'];
                $user->email = $validoSso['data']['email'];
                $user->nombreUsuario = $validoSso['data']['username'];
                $user->ssoToken = $request['ssotoken'];
                $user->save();

                return $this->ResponseSuccess('Ok', [
                    'token' => $request['ssotoken']
                ]);
            }
            else {
                return $this->ResponseError('USR-4785', 'El usuario no existe o no está habilitado para acceder a esta aplicación');
            }

        } catch (\Throwable $th) {
            /*var_dump($th->getMessage());
            die();*/
            return $this->ResponseError('AUTH-AF60F', 'Error al iniciar sesión');
        }
    }

    public function resetPassword(Request $request) {

        $request->validate(['nombreUsuario' => 'required']);

        $user = User::where('nombreUsuario', $request->nombreUsuario)->first();

        if (!empty($user)) {
            $token = md5(rand(1000, 10000) . microtime()).md5(microtime() . rand(1000, 10000));
            $user->resetPassword = $token;
            $user->save();

            $configH = new ConfigController();

            // envio de credenciales
            if (!empty($user->email)) {

                $config = $configH->GetConfig('mailgunNotifyConfig');
                $mg = Mailgun::create($config->apiKey ?? ''); // For US servers

                // reemplazo plantilla
                $templateHtml = $configH->GetConfig('userResetTemplateHtml');
                $templateHtml = str_replace('::URL_RECUPERACION::', env('APP_URL')."/#/reset-my-password/{$token}", $templateHtml);

                try {
                    $mg->messages()->send($config->domain ?? '', [
                        'from'    => $config->from ?? '',
                        'to'      => $user->email,
                        'subject' => $config->subject ?? '',
                        'html'    => $templateHtml
                    ]);

                    return $this->ResponseSuccess( 'Si tu cuenta existe, llegará un enlace de recuperación');
                }
                catch (Exception $e) {
                    return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
                }
            }
            /*
            else {
                if (!empty($user->telefono)) {

                    $config = $configH->GetConfig('whatsappNotifyConfig');

                    $dataSend = [
                        "type" => $config->type ?? '',
                        "users" => [
                            [
                                "priority" => "<priority>",
                                "phone" => "+502{$user->telefono}",
                                "params" => [
                                    "nombre_asegurado" => $user->name,
                                    "usuario" => $user->nombreUsuario,
                                    "link" => env('APP_URL')
                                ]
                            ]
                        ]
                    ];
                    //dd($dataSend);

                    $headers = [
                        'Authorization: Bearer '.$config->token ?? '',
                        'Content-Type: application/json',
                    ];
                    //var_dump($headers);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL,$config->url ?? '');
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));  //Post Fields
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    $server_output = curl_exec ($ch);
                    $server_output = @json_decode($server_output, true);
                    curl_close ($ch);
                }
            }*/
        }
    }

    public function resetPasswordWithToken(Request $request) {

        $request->validate(['token' => 'required', 'password' => 'required']);
        $request->token = trim($request->token);


        if (empty($request->token)) {
            return $this->ResponseError('AUTH-54TKI', 'El token es inválido');
        }

        $user = User::where('resetPassword', $request->token)->first();

        if (!empty($user)) {

            $user->password = Hash::make($request->password);
            $user->resetPassword = null;
            $user->save();

            return $this->ResponseSuccess('Se ha cambiado tu contraseña');
        }
        else {
            return $this->ResponseError('AUTH-TKINV', 'El token es inválido');
        }
    }

    public function loginClose(Request $request) {
        $type = $request->get('type');
        $tokenBearer = $request->bearerToken();

        $user = User::where('ssoToken', $tokenBearer)->first();

        if (!empty($user)) {

            if ($type === 'all') {
                $this->closeSessionSSO($user->token);
            }

            $user->ssoToken = null;
            $user->save();
        }

        return $this->ResponseSuccess('Ok');
    }

    public function CheckAccess($accessToCheck = []) {
        $hasAccess = true;
        $accessListUser = $this->GetUserAccess();
        foreach ($accessToCheck as $access) {
            if (!isset($accessListUser[$access])) {
                $hasAccess = false;
            }
        }
        return $hasAccess;
    }

    public function NoAccess() {
        return $this->ResponseError('AUTH-001', 'Usuario sin acceso al área solicitada');
    }

    public function GetUserAccess() {
        $user = auth('sanctum')->user();

        $accessTMP = [];
        if (!empty($user->rolAsignacion->rol)) {
            $rolUser = $user->rolAsignacion->rol;
            $accessTMP = $this->LoadUserAccess($rolUser->id ?? 0);
        }


        return $accessTMP['data'] ?? [];
    }

    public function GetUserList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/listar'])) return $AC->NoAccess();

        $users = User::whereNotNull('email_verified_at')->with('rolAsignacion')->get();

        $usersTMp = [];

        if (!empty($users)) {

            foreach ($users as $user) {

                if (empty($user->rolAsignacion)) {
                    $user->rolUsuario = 'Sin rol';
                }
                else {
                    $user->rolUsuario = $user->rolAsignacion->rol->name ?? 0;
                }
                $user->estado = ($user->active) ? 'Activo' : 'Desactivado';

                $user->makeHidden(['rolAsignacion', 'email_verified_at', 'updated_at']);

                $usersTMp[] = $user;
            }

            return $this->ResponseSuccess('Información obtenida con éxito', $usersTMp);
        }
        else {
            return $this->ResponseError('Error al listar usuarios');
        }
    }

    public function LoadUser($userid) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $user = User::where([['id', '=', $userid]])->with('rolAsignacion', 'log')->first();

        if (!empty($user)) {
            $user->rolUsuario = $user->rolAsignacion->rolId ?? 0;

            // proceso el log
            $logArr = [];
            foreach ($user->log as $log) {
                $log->date = Carbon::parse($log->created_at)->format('d-m-Y H:i:s');
                $logArr[] = $log;
            }
            $user->logs = $logArr;

            $user->variables = @json_decode($user->userVars);

            $user->makeHidden(['rolAsignacion', 'log', 'email_verified_at', 'updated_at', 'apps', 'userVars']);

            return $this->ResponseSuccess('Ok', $user);
        }
        else {
            return $this->ResponseError('USR-8548', 'Usuario no válido');
        }
    }

    public function SaveUser(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $id = $request->get('id');

        $nombreUsuario = $request->get('nombreUsuario');
        $nombreUsuario = trim(strip_tags($nombreUsuario));

        $name = $request->get('nombre');
        $email = $request->get('correoElectronico');
        $password = $request->get('password');
        $rol = $request->get('rolUsuario');
        $active = $request->get('active');
        $fueraOficina = $request->get('fueraOficina');
        $variables = $request->get('variables');
        $telefono = $request->get('telefono');
        $telefono = str_replace('-', '', $telefono);
        $telefono = str_replace(' ', '', $telefono);

        $corporativo = $request->get('corporativo');
        $changePassword = $request->get('changePassword');

        $sendCredentials = $request->get('sendCredentials');
        $sendCredentialsEmail = $request->get('sendCredentialsEmail');
        $sendCredentialsSMS = $request->get('sendCredentialsSMS');
        $sendCredentialsWhatsapp = $request->get('sendCredentialsWhatsapp');

        $appList = $request->get('appList');

        $role = Rol::where([['id', '=', $rol]])->first();

        if (empty($role)) {
            return $this->ResponseError('AUTH-RUE93', 'El rol no existe o es inválido');
        }

        //dd($id);

        if (empty($id)) {
            $user = new User();
            $user->email_verified_at = Carbon::now()->format('Y-m-d H:i:s');
        }
        else {
            $user = User::where('id', $id)->first();
        }

        if ($user->nombreUsuario !== $nombreUsuario) {
            // verifico el correo electrónico
            $userTmp = User::where('nombreUsuario', $nombreUsuario)->first();
            if (!empty($userTmp)) {
                return $this->ResponseError('AUTH-UE934', 'El nombre de usuario ya se encuentra en uso');
            }
        }

        // verifico email duplicado
        /*$userTmp = User::where([['email', '=', $email], ['nombreUsuario', '<>', $nombreUsuario]])->first();
        if (!empty($userTmp)) {
            return $this->ResponseError('AUTH-UE934', 'El correo electrónico ya se encuentra configurado en otro usuario');
        }*/

        // verifico el corporativo
        /*if (!empty($corporativo)) {
            $userTmp = User::where([['corporativo', '=', $corporativo], ['nombreUsuario', '<>', $nombreUsuario]])->first();
            if (!empty($userTmp)) {
                return $this->ResponseError('AUTH-UE934', 'El corporativo ya se encuentra configurado en otro usuario');
            }
        }*/

        if ($changePassword) {
            $user->password = Hash::make($password);
        }

        //$user->nombreUsuario = $nombreUsuario;
        $user->name = strip_tags($name);
        $user->email = strip_tags($email);
        $user->telefono = strip_tags($telefono);
        $user->corporativo = strip_tags($corporativo);
        $user->active = intval($active);
        $user->fueraOficina = intval($fueraOficina);
        $user->userVars = @json_encode($variables);
        $user->save();

        $userRole = UserRol::firstOrNew(['userId' => $user->id]);
        $userRole->rolId = $role->id;
        $userRole->save();

        // borro los accesos por rol
        //UserApp::where([['userId', '=', $user->id]])->delete();

        // guardo los accesos
        /*foreach ($appList as $item) {
            if (!empty($item['active'])) {
                $row = new UserApp();
                $row->userId = $user->id;
                $row->appId = $item['id'];
                $row->save();
            }
        }*/

        // traigo la configuración
        $configH = new ConfigController();

        if (!empty($user)) {

            // envio de credenciales
            if ($sendCredentialsEmail) {

                $config = $configH->GetConfig('mailgunNotifyConfig');
                $mg = Mailgun::create($config->apiKey ?? ''); // For US servers

                // reemplazo plantilla
                $templateHtml = $configH->GetConfig('userCreateTemplateHtml');
                $templateHtml = str_replace('::URL_LOGIN::', env('APP_URL'), $templateHtml);
                $templateHtml = str_replace('::PASSWORD::', $password, $templateHtml);
                $templateHtml = str_replace('::USERNAME::', $nombreUsuario, $templateHtml);

                try {
                    $mg->messages()->send($config->domain ?? '', [
                        'from'    => $config->from ?? '',
                        'to'      => $email,
                        'subject' => $config->subject ?? '',
                        'html'    => $templateHtml
                    ]);
                }
                catch (Exception $e) {
                    return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
                }
            }

            /*
             Notificación para recibir la primera contraseña
            curl --location --request POST 'https://api-india.yalochat.com/notifications/api/v1/accounts/corporacion-bi/bots/seguros_el_roble/notifications' --header 'Authorization: Bearer PON_TU_CLAVE_API_AQUÍ' --header 'Content-Type: application/json' --data '{"type":"bienvenida-sso","users":[{"priority":"<priority>","phone":"+<phone>","params":{"nombre_asegurado":"<nombre_asegurado>","usuario":"<usuario>","Contrasena":"<Contrasena>"}}]}'


            Notificación para reinicio de contraseña

            curl --location --request POST 'https://api-india.yalochat.com/notifications/api/v1/accounts/corporacion-bi/bots/seguros_el_roble/notifications' --header 'Authorization: Bearer PON_TU_CLAVE_API_AQUÍ' --header 'Content-Type: application/json' --data '{"type":"cambio-contrasena-sso","users":[{"priority":"<priority>","phone":"+<phone>","params":{"nombre_asegurado":"<nombre_asegurado>","usuario":"<usuario>","Contrasena":"<Contrasena>"}}]}'
             * */

            if ($sendCredentialsWhatsapp) {

                if (empty($telefono)) {
                    return $this->ResponseError('AUTH-RL9AF', 'Teléfono inválido para envío de Whatsapp');
                }

                $config = $configH->GetConfig('whatsappNotifyConfig');

                $dataSend = [
                    "type" => $config->type ?? '',
                    "users" => [
                        [
                            "priority" => "<priority>",
                            "phone" => "+502{$telefono}",
                            "params" => [
                                "colaborador" => $name,
                                "usuario" => $nombreUsuario,
                                "contrasenia" => $password,
                                "link" => env('APP_URL')
                            ]
                        ]
                    ]
                ];
                //dd($dataSend);

                $headers = [
                    'Authorization: Bearer '.$config->token ?? '',
                    'Content-Type: application/json',
                ];
                //var_dump($headers);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL,$config->url ?? '');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));  //Post Fields
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $server_output = curl_exec ($ch);
                $server_output = @json_decode($server_output, true);
                curl_close ($ch);
            }

            return $this->ResponseSuccess('Usuario guardado con éxito', $user->id);
        }
        else {
            return $this->ResponseError('AUTH-RL934', 'Error al crear rol');
        }
    }

    public function DeleteUser(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $user = User::find($id);

            if (!empty($user)) {
                $user->active = 0;
                $user->save();
                return $this->ResponseSuccess('Eliminado con éxito', $user->id);
            }
            else {
                return $this->ResponseError('AUTH-UR532', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            //var_dump($th->getMessage());
            return $this->ResponseError('AUTH-UR530', 'Error al eliminar');
        }
    }

    public function UserSync(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $headers = ['Cache-Control: no-cache','Content-Type: application/json', 'Authorization: Bearer '.env('SSO_APIKEY')];
        $data = ['appToken' => env('SSO_TOKEN_APP')];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SSO_URL')."/sso/users/get-list");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));  //Post Fields
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec ($ch);
        curl_close ($ch);
        $response = @json_decode($response, true);

        // dd($response);

        if (!empty($response['status'])) {

            foreach ($response['data'] as $userSso) {

                $user = User::where('token', $userSso['token'])->first();

                // si el usuario no existe, lo tengo que crear
                if (empty($user)) {
                    $user = new User();
                    $user->email_verified_at = Carbon::now()->format('Y-m-d H:i:s');
                    $user->token = $userSso['token'];
                }
                $user->telefono = $userSso['telefono'];
                $user->corporativo = $userSso['corporativo'];
                $user->name = $userSso['name'];
                $user->email = $userSso['email'];
                $user->nombreUsuario = $userSso['nombreUsuario'];
                $user->save();
            }

            return $this->ResponseSuccess('Sincronización realizada con éxito');
        }
        else {
            return $this->ResponseError('USR-412', 'Error al listar usuarios desde SSO');
        }
    }

    public function saveAsignacionMasiva(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $asignacion = $request->get('asignacion');

        if (is_array($asignacion)) {
            foreach ($asignacion as $row) {
                if (!isset($row['nombre_usuario']) || !isset($row['rol_id']) || !isset($row['grupo_id'])) {
                    return $this->ResponseError('AUTH-449', 'Plantilla inválida, revise las columnas');
                }
            }

            // proceso
            foreach ($asignacion as $row) {
                if (!empty($row['nombre_usuario'])) {
                    $user = User::where('nombreUsuario', $row['nombre_usuario'])->first();

                    if (!empty($user)) {

                        if (!empty($row['rol_id'])) {
                            $userRole = UserRol::firstOrNew(['userId' => $user->id]);
                            $userRole->rolId = intval($row['rol_id']);
                            $userRole->save();
                        }

                        $grupoId = intval($row['grupo_id']);
                        if ($grupoId > 0) {
                            $UserGrupoUsuario = UserGrupoUsuario::where('userGroupId', $row['grupo_id'])->where('userId', $user->id)->first();
                            if (empty($UserGrupoUsuario)) {
                                $row = new UserGrupoUsuario();
                                $row->userGroupId = $grupoId;
                                $row->userId = $user->id;
                                $row->save();
                            }
                        }
                    }
                }
            }

            return $this->ResponseSuccess('Archivo procesado con éxito');
        }
        else {
            return $this->ResponseError('AUTH-449', 'Archivo inválido');
        }
    }

    // canales
    public function GetUserGrupoList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/grupos'])) return $AC->NoAccess();

        $items = UserGrupo::all();

        if (!empty($items)) {
            return $this->ResponseSuccess('Información obtenida con éxito', $items);
        }
        else {
            return $this->ResponseError('USR-23', 'Error al listar usuarios');
        }
    }

    public function LoadUserGrupo($id) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/grupos'])) return $AC->NoAccess();

        $user = UserGrupo::where([['id', '=', $id]])->with('users', 'roles')->first();

        // traigo los roles
        $itemList = $user->roles;
        $items = [];
        foreach ($itemList as $tmp) {
            $items[] = $tmp['rolId'];
        }
        $user->rolList = $items;


        // traigo los roles
        $itemList = $user->users;
        $items = [];
        foreach ($itemList as $tmp) {
            $items[] = $tmp['userId'];
        }
        $user->userList = $items;

        $user->makeHidden(['users', 'roles']);

        return $this->ResponseSuccess('Ok', $user);
    }

    public function SaveUseGrupo(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/grupos'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $activo = $request->get('activo');
        $usuarios = $request->get('usuarios');
        $roles = $request->get('roles');

        if (empty($id)) {
            $item = new UserGrupo();
        }
        else {
            $item = UserGrupo::where('id', $id)->first();
        }

        $item->nombre = $nombre;
        $item->activo = intval($activo);
        $item->save();

        // borro los accesos por rol
        UserGrupoRol::where([['userGroupId', '=', $item->id]])->delete();

        // guardo los accesos
        foreach ($roles as $itemTmp) {
            $row = new UserGrupoRol();
            $row->userGroupId = $item->id;
            $row->rolId = $itemTmp;
            $row->save();
        }

        // guardo los accesos
        UserGrupoUsuario::where([['userGroupId', '=', $item->id]])->delete();

        foreach ($usuarios as $itemTmp) {
            $row = new UserGrupoUsuario();
            $row->userGroupId = $item->id;
            $row->userId = $itemTmp;
            $row->save();
        }

        return $this->ResponseSuccess('Grupo guardado con éxito', $item->id);
    }

    public function DeleteUserGrupo(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/grupos'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $user = UserGrupo::find($id);

            if (!empty($user)) {
                $user->delete();
                return $this->ResponseSuccess('Eliminado con éxito');
            }
            else {
                return $this->ResponseError('AUTH-UR532', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            //var_dump($th->getMessage());
            return $this->ResponseError('AUTH-UR530', 'Error al eliminar');
        }
    }

    // grupos de usuario
    public function GetUserCanalList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/canales'])) return $AC->NoAccess();

        $items = UserCanal::all();

        if (!empty($items)) {
            return $this->ResponseSuccess('Información obtenida con éxito', $items);
        }
        else {
            return $this->ResponseError('USR-23', 'Error al listar usuarios');
        }
    }

    public function LoadUserCanal($id) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/canales'])) return $AC->NoAccess();

        $user = UserCanal::where([['id', '=', $id]])->with('grupos')->first();

        // traigo los roles
        $itemList = $user->grupos;
        $items = [];
        foreach ($itemList as $tmp) {
            $items[] = $tmp['userGroupId'];
        }
        $user->grupoList = $items;

        $user->makeHidden(['grupos']);

        return $this->ResponseSuccess('Ok', $user);
    }

    public function SaveUseCanal(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/canales'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $activo = $request->get('activo');
        //$usuarios = $request->get('usuarios');
        $grupos = $request->get('grupos');

        if (empty($id)) {
            $item = new UserCanal();
        }
        else {
            $item = UserCanal::where('id', $id)->first();
        }

        $item->nombre = $nombre;
        $item->activo = intval($activo);
        $item->save();

        // borro los accesos por rol
        UserCanalGrupo::where([['userCanalId', '=', $item->id]])->delete();

        // guardo los accesos
        foreach ($grupos as $itemTmp) {
            $row = new UserCanalGrupo();
            $row->userCanalId = $item->id;
            $row->userGroupId = $itemTmp;
            $row->save();
        }

        // guardo los accesos
        /*UserGrupoUsuario::where([['userGroupId', '=', $item->id]])->delete();

        foreach ($usuarios as $itemTmp) {
            $row = new UserGrupoUsuario();
            $row->userGroupId = $item->id;
            $row->userId = $itemTmp;
            $row->save();
        }*/

        return $this->ResponseSuccess('Grupo guardado con éxito', $item->id);
    }

    public function DeleteUserCanal(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/canales'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $user = UserCanal::find($id);

            if (!empty($user)) {
                UserCanalGrupo::where([['userCanalId', '=', $user->id]])->delete();
                $user->delete();
                return $this->ResponseSuccess('Eliminado con éxito');
            }
            else {
                return $this->ResponseError('AUTH-UR450', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            //var_dump($th->getMessage());
            return $this->ResponseError('AUTH-UR451', 'Error al eliminar');
        }
    }

    // Jerarquía de usuarios
    public function GetUserJerarquiaList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/jerarquia/admin'])) return $AC->NoAccess();

        $items = UserJerarquia::all();

        if (!empty($items)) {
            return $this->ResponseSuccess('Información obtenida con éxito', $items);
        }
        else {
            return $this->ResponseError('USR-23', 'Error al listar Jerarquias');
        }
    }

    public function LoadUserJerarquia($id) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/jerarquia/admin'])) return $AC->NoAccess();

        $item = UserJerarquia::where([['id', '=', $id]])->with(['supervisor', 'detalle'])->first();

        // traigo supervisores
        $itemList = $item->supervisor;

        $itemsRolSup = [];
        $itemsGroupSup = [];
        $itemsUserSup = [];
        foreach ($itemList as $tmp) {
            if (!empty($tmp['rolId'])) $itemsRolSup[] = $tmp['rolId'];
            if (!empty($tmp['userGroupId'])) $itemsGroupSup[] = $tmp['userGroupId'];
            if (!empty($tmp['userId'])) $itemsUserSup[] = $tmp['userId'];
        }
        $item->rolSup = $itemsRolSup;
        $item->groupSup = $itemsGroupSup;
        $item->userSup = $itemsUserSup;

        // traigo detalle
        $itemList = $item->detalle;

        $itemsCanalD = [];
        $itemsRolD = [];
        $itemsGroupD = [];
        $itemsUserD = [];
        foreach ($itemList as $tmp) {
            if (!empty($tmp['canalId'])) $itemsCanalD[] = $tmp['canalId'];
            if (!empty($tmp['rolId'])) $itemsRolD[] = $tmp['rolId'];
            if (!empty($tmp['userGroupId'])) $itemsGroupD[] = $tmp['userGroupId'];
            if (!empty($tmp['userId'])) $itemsUserD[] = $tmp['userId'];
        }
        $item->canalD = $itemsCanalD;
        $item->rolD = $itemsRolD;
        $item->groupD = $itemsGroupD;
        $item->userD = $itemsUserD;

        $item->makeHidden(['supervisor', 'detalle']);

        return $this->ResponseSuccess('Ok', $item);
    }

    public function SaveUseJerarquia(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/jerarquia/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $activo = $request->get('activo');
        //$usuarios = $request->get('usuarios');

        $gruposSup = $request->get('gruposSup');
        $rolesSup = $request->get('rolesSup');
        $usuariosSup = $request->get('usuariosSup');

        $rolesD = $request->get('rolesD');
        $usuariosD = $request->get('usuariosD');
        $groupsD = $request->get('groupsD');
        $canalD = $request->get('canalD');

        if (empty($id)) {
            $item = new UserJerarquia();
        }
        else {
            $item = UserJerarquia::where('id', $id)->first();
        }

        $item->nombre = $nombre;
        $item->activo = intval($activo);
        $item->save();

        // borro los accesos por rol
        UserJerarquiaSupervisor::where([['jerarquiaId', '=', $item->id]])->delete();

        // guardo los accesos
        foreach ($gruposSup as $itemTmp) {
            $row = new UserJerarquiaSupervisor();
            $row->jerarquiaId = $item->id;
            $row->userGroupId = $itemTmp;
            $row->save();
        }

        // guardo los accesos
        foreach ($rolesSup as $itemTmp) {
            $row = new UserJerarquiaSupervisor();
            $row->jerarquiaId = $item->id;
            $row->rolId = $itemTmp;
            $row->save();
        }

        // guardo los accesos
        foreach ($usuariosSup as $itemTmp) {
            $row = new UserJerarquiaSupervisor();
            $row->jerarquiaId = $item->id;
            $row->userId = $itemTmp;
            $row->save();
        }

        // guardo los accesos
        UserJerarquiaDetail::where([['jerarquiaId', '=', $item->id]])->delete();

        foreach ($canalD as $itemTmp) {
            $row = new UserJerarquiaDetail();
            $row->jerarquiaId = $item->id;
            $row->canalId = $itemTmp;
            $row->save();
        }

        foreach ($groupsD as $itemTmp) {
            $row = new UserJerarquiaDetail();
            $row->jerarquiaId = $item->id;
            $row->userGroupId = $itemTmp;
            $row->save();
        }

        foreach ($usuariosD as $itemTmp) {
            $row = new UserJerarquiaDetail();
            $row->jerarquiaId = $item->id;
            $row->userId = $itemTmp;
            $row->save();
        }

        foreach ($rolesD as $itemTmp) {
            $row = new UserJerarquiaDetail();
            $row->jerarquiaId = $item->id;
            $row->rolId = $itemTmp;
            $row->save();
        }

        return $this->ResponseSuccess('Jerarquía guardada con éxito', $item->id);
    }

    public function DeleteUserJerarquia(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/jerarquia/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $user = UserJerarquia::find($id);

            if (!empty($user)) {
                UserJerarquiaSupervisor::where([['jerarquiaId', '=', $user->id]])->delete();
                UserJerarquiaDetail::where([['jerarquiaId', '=', $user->id]])->delete();
                $user->delete();
                return $this->ResponseSuccess('Eliminado con éxito');
            }
            else {
                return $this->ResponseError('AUTH-UR450', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            //var_dump($th->getMessage());
            return $this->ResponseError('AUTH-UR451', 'Error al eliminar');
        }
    }

    public function CalculateAccess() {

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        //$rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;

        $jerarquiasSupervision = [];
        $usersSupervisor = [];
        $usersDetalle = [];

        // valido si es permiso público
        if (empty($usuarioLogueado)) {
            return [
                'sup' => [],
                'det' => [],
                'all' => []
            ];
        }
        else {
            // traigo las jerarquías donde esté el usuario
            $usersJerarquia = UserJerarquia::where('activo', 1)->get();
            foreach ($usersJerarquia as $jerarquia) {

                // SUpervisores
                $jerarquiaSup = $jerarquia->supervisor;

                foreach ($jerarquiaSup as $jerarquiaSp) {

                    // usuarios directos
                    if (!empty($jerarquiaSp->userId) && $usuarioId === $jerarquiaSp->userId) {
                        $usersSupervisor[$jerarquiaSp->userId] = $jerarquiaSp->userId;
                        $jerarquiasSupervision[$jerarquiaSp->jerarquiaId] = $jerarquiaSp->jerarquiaId;
                    }

                    // por grupo
                    if (!empty($jerarquiaSp->userGroupId)) {
                        // usuarios especificos
                        if ($grupos = $jerarquiaSp->gruposUsuarios) {
                            $gruposUsuarios = $grupos->grupo->users;
                            foreach ($gruposUsuarios as $userAsig){
                                if ($userAsig->userId === $usuarioId) {
                                    $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                                    $jerarquiasSupervision[$jerarquiaSp->jerarquiaId] = $jerarquiaSp->jerarquiaId;
                                }
                            }
                        }


                        // por rol
                        if ($rol = $jerarquiaSp->gruposRol) {
                            $gruposRol = $rol->rol->usersAsig;
                            foreach ($gruposRol as $userAsig) {
                                if ($userAsig->userId === $usuarioId) {
                                    $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                                    $jerarquiasSupervision[$jerarquiaSp->jerarquiaId] = $jerarquiaSp->jerarquiaId;
                                }
                            }

                        }
                    }

                    // por rol
                    if (!empty($jerarquiaSp->rolId)) {
                        if ($rol = $jerarquiaSp->rol) {
                            $roles = $rol->usersAsig;
                            foreach ($roles as $userAsig) {
                                if ($userAsig->userId === $usuarioId) {
                                    $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                                    $jerarquiasSupervision[$jerarquiaSp->jerarquiaId] = $jerarquiaSp->jerarquiaId;
                                }
                            }
                        }
                    }
                }

                // Si va a supervisar algo
                if (isset($usersSupervisor[$usuarioId])) {
                    // Normales
                    $jerarquiaSup = $jerarquia->detalle;

                    foreach ($jerarquiaSup as $jerarquiaDt) {

                        if (!isset($jerarquiasSupervision[$jerarquiaDt->jerarquiaId])) {
                            continue;
                        }

                        // usuarios directos
                        if (!empty($jerarquiaDt->userId) && $jerarquiaDt->userId) {
                            $usersDetalle[$jerarquiaDt->userId] = $jerarquiaDt->userId;
                        }

                        // por canal
                        if (!empty($jerarquiaDt->canalId)) {
                            // usuarios especificos
                            if ($canal = $jerarquiaDt->canal) {
                                $gruposUsuarios = $canal->grupos;

                                foreach ($gruposUsuarios as $grupoU) {
                                    if ($grupo = $grupoU->grupo) {
                                        $users = $grupo->users;

                                        // por usuario del grupo
                                        foreach ($users as $userAsig) {
                                            $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                        }
                                        // por rol
                                        if ($rol = $grupo->roles) {
                                            foreach ($rol as $r) {
                                                if ($gruposRol = $r->rol) {
                                                    $roles = $gruposRol->usersAsig;
                                                    foreach ($roles as $userAsig) {
                                                        $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }


                            // por rol
                            if ($rol = $jerarquiaDt->gruposRol) {
                                $gruposRol = $rol->rol->usersAsig;
                                foreach ($gruposRol as $userAsig) {
                                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                }

                            }
                        }

                        // por grupo
                        if (!empty($jerarquiaDt->userGroupId)) {
                            // usuarios especificos
                            if ($grupos = $jerarquiaDt->gruposUsuarios) {
                                $gruposUsuarios = $grupos->grupo->users;
                                foreach ($gruposUsuarios as $userAsig){
                                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                }
                            }


                            // por rol
                            if ($rol = $jerarquiaDt->gruposRol) {
                                $gruposRol = $rol->rol->usersAsig;
                                foreach ($gruposRol as $userAsig) {
                                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                }
                            }
                        }

                        // por rol
                        if (!empty($jerarquiaDt->rolId)) {
                            if ($rol = $jerarquiaDt->rol) {
                                $roles = $rol->usersAsig;
                                foreach ($roles as $userAsig) {
                                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!in_array($usuarioId, $usersSupervisor)) {
            $usersSupervisor = [];
            $usersDetalle[] = $usuarioId;
        }

        if ($this->CheckAccess(['tareas/non/user'])) {
            $usersDetalle[] = 0;
        }

        return [
            'sup' => $usersSupervisor,
            'det' => $usersDetalle,
            'all' => array_merge($usersSupervisor, $usersDetalle)
        ];
    }

    public function CalculateVisibility($usuarioLogueadoId, $rolUsuarioLogueadoId, $public, $rolAssign, $groupAssign, $canalAssig) {
        $usersDetalle = [];

        if ($public) return true;

        // evalua canales
        if (!empty($canalAssig) && is_array($canalAssig) && count($canalAssig) > 0) {
            $canales = UserCanalGrupo::whereIn('userCanalId', $canalAssig)->get();

            foreach ($canales as $canal) {

                $gruposUsuarios = $canal->canal->grupos;

                foreach ($gruposUsuarios as $grupoU) {
                    if ($grupo = $grupoU->grupo) {
                        $users = $grupo->users;

                        // por usuario del grupo
                        foreach ($users as $userAsig) {
                            $usersDetalle[$userAsig->userId] = $userAsig->userId;
                        }
                        // por rol
                        if ($rol = $grupo->roles) {
                            foreach ($rol as $r) {
                                if ($gruposRol = $r->rol) {
                                    $roles = $gruposRol->usersAsig;
                                    foreach ($roles as $userAsig) {
                                        $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // usuarios específicos del grupo
        if (!empty($groupAssign) && is_array($groupAssign) && count($groupAssign) > 0) {

            // verifico usuarios específicos
            $usersGroup = UserGrupoUsuario::whereIn('userGroupId', $groupAssign)->get();
            foreach ($usersGroup as $grupoUser) {
                $gruposUsuarios = $grupoUser->grupo->users;
                foreach ($gruposUsuarios as $userAsig) {
                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                }
            }

            // por rol
            $usersGroupR = UserGrupoRol::whereIn('userGroupId', $groupAssign)->get();

            foreach ($usersGroupR as $gruposRol) {
                $userA = $gruposRol->rol->usersAsig;
                foreach ($userA as $userAsig) {
                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                }
            }
        }

        // verifico roles específicos
        if (!empty($rolAssign) && is_array($rolAssign) && count($rolAssign) > 0) {

            //dd($rolAssign);
            if (in_array($rolUsuarioLogueadoId, $rolAssign)) {
                $usersDetalle[] = $usuarioLogueadoId;
            }
        }

        return (in_array($usuarioLogueadoId, $usersDetalle));
    }

    // menu
    public function GetMenu() {

        $getUserAccess = $this->GetUserAccess();

        $accessList = [];
        foreach (LgcMenu as $menu) {

            if (!empty($menu['access'])) {
                if (!isset($getUserAccess[$menu['access']])) {
                    continue;
                }
            }
            $accessList[] = $menu;
        }

        $this->ResponseSuccess('Ok', $accessList);
    }

    public function GetRoleList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $roleList = Rol::all();

        $roles = [];

        foreach ($roleList as $rol) {
            $roles[] = [
                'id' => $rol->id,
                'name' => $rol->name,
            ];
        }

        if (!empty($roleList)) {
            return $this->ResponseSuccess('Ok', $roles);
        }
        else {
            return $this->ResponseError('Error al listar roles');
        }
    }

    public function GetRoleAccessList() {
        return $this->ResponseSuccess('Ok', LgcAccessConfig);
    }

    public function GetRoleDetail($rolId) {

        $role = Rol::where([['id', '=', $rolId]])->first();

        if (!empty($role)) {

            // traigo accesos
            $accessList = $role->access;
            $access = [];
            foreach ($accessList as $accessTmp) {
                $access[$accessTmp['access']] = true;
            }

            // traigo las apps
            $appsList = $role->apps;
            $apps = [];
            foreach ($appsList as $tmp) {
                $apps[$tmp['appId']] = true;
            }

            return $this->ResponseSuccess('Ok', [
                'nombre' => $role->name,
                'access' => $access,
                'apps' => $apps,
            ]);
        }
        else {
            return $this->ResponseError('Rol inválido');
        }
    }

    public function SaveRole(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $roleId = $request->get('id');
        $name = $request->get('nombre');
        $access = $request->get('access');
        $appList = $request->get('appList');

        if (!empty($roleId)) {
            $role = Rol::where([['id', '=', $roleId]])->first();
        }
        else {
            $role = new Rol();
        }

        $role->name = $name;
        $role->save();

        if (!empty($role)) {

            // borro los accesos por rol
            RolAccess::where([['rolId', '=', $role->id]])->delete();

            // guardo los accesos
            foreach ($access as $modulo) {
                foreach ($modulo['access'] as $permiso) {
                    if (!empty($permiso['active'])) {
                        $acceso = new RolAccess();
                        $acceso->rolId = $role->id;
                        $acceso->access = $permiso['slug'];
                        $acceso->save();
                    }
                }
            }

            // borro los accesos por rol
            RolApp::where([['rolId', '=', $role->id]])->delete();

            // guardo los accesos
            foreach ($appList as $item) {
                if (!empty($item['active'])) {
                    $row = new RolApp();
                    $row->rolId = $role->id;
                    $row->appId = $item['id'];
                    $row->save();
                }
            }
            return $this->ResponseSuccess('Guardado con éxito', $role->id);
        }
        else {
            return $this->ResponseError('AUTH-RL934', 'Error al crear rol');
        }
    }

    public function DeleteRole(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $role = Rol::find($id);

            if (!empty($role)) {
                $role->delete();
                return $this->ResponseSuccess('Eliminado con éxito', $role->id);
            }
            else {
                return $this->ResponseError('AUTH-R5321', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            var_dump($th->getMessage());
            return $this->ResponseError('AUTH-R5302', 'Error al eliminar');
        }
    }

    public function LoadUserAccess($roleid) {

        $role = Rol::where([['id', '=', $roleid]])->select('id', 'name')->first();

        if (!empty($role)) {
            $role = Rol::where([['id', '=', $roleid]])->first();
        }
        else {
            return $this->ResponseError('ERRO-5148', 'El rol no existe');
        }

        $roles = [];
        $roles['rol'] = $role ?? [];
        $roles['access'] = LgcAccessConfig;

        $permisions = [];
        if (!empty($role)) {
            $permisions = $role->access;
            $permisions = $permisions->toArray();
        }

        $accessList = [];

        try {
            foreach ($roles['access'] as $keyModule => $access) {

                /*var_dump($keyModule);
                dd($access);*/

                foreach ($access['access'] as $accessKey => $accessTmp) {

                    foreach ($permisions as $permision) {

                        if (empty($roles['access'][$keyModule]['access'][$accessKey]['status'])) {

                            if ($permision['access'] == $accessTmp['slug']) {
                                $accessList[$access['module']] = true;
                                $accessList[$permision['access']] = true;
                            }
                        }
                    }
                }
            }

            return $this->ResponseSuccess('Ok', $accessList, false);
        } catch (\Mockery\Exception $exception) {
            return $this->ResponseError('ERRAU-547', 'Error al cargar', $roles);
        }
    }

    // Loggin desde directorio
    public function ssoLoginStart(Request $request) {

        $appToken = $request->get('a');

        if (!empty($appToken)) {
            return $this->ResponseSuccess('Usuario logueado', [
                'logged' => 1,
                'name' => 'test',
                'm' => '',
            ]);
        }
        else {
            return $this->ResponseError('SSO-014', 'Aplicación inválida');
        }
    }

    public function ssoLoginValidate(Request $request) {

        if (auth('sanctum')->check()) {

            $user = auth('sanctum')->user();
            $token = auth('sanctum')->user()->tokens()->where('tokenable_id', auth()->id())->first();

            if (empty($token)) {
                return $this->ResponseError('AUTH-TKINV', 'Token inválido');
            }

            if (!$user->active) {
                return $this->ResponseError('AUTH-ACT', 'Usuario desactivado');
            }

            $getUserAccess = $this->GetUserAccess();

            return $this->ResponseSuccess('Usuario logueado', [
                'logged' => 1,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->nombreUsuario,
                'm' => $getUserAccess,
            ]);
        }
        else {
            return $this->ResponseError('AUTH-TKIV11', 'Token inválido');
        }
    }

    public function SSO_GetUserList(Request $request) {

        $appToken = $request->get('appToken');

        //$appToken = '6ac5a2e4e744b7e2f936c174a70364662617264a1aec990e545588684d47';
        //$AC = new AuthController();
        //if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $appToken = Archivador::where([['activa', '=', '1'], ['token', '=', $appToken]])->first();
        //dd($appToken);

        if (empty($appToken)) {
            return $this->ResponseError('SSO-521', 'Aplicación inválida');
        }

        $asignacion = $appToken->usersApp;
        $rolesApp = $appToken->rolesAsig;

        $usersTMp = [];

        if (!empty($asignacion)) {

            foreach ($rolesApp as $rolAppAsig) {

                $rolesUsuarios = $rolAppAsig->rol->usersAsig;

                foreach ($rolesUsuarios as $rolUser) {

                    $user = User::whereNotNull('email_verified_at')->with('rolAsignacion')->where([['id', '=', $rolUser->userId], ['active', '=', 1]])->first();

                    if (empty($user->rolAsignacion)) {
                        $user->rolUsuario = 'Sin rol';
                    }
                    else {
                        $user->rolUsuario = $user->rolAsignacion->rol->name;
                    }

                    $user->makeHidden(['rolAsignacion', 'email_verified_at', 'updated_at']);

                    $usersTMp[$user->id] = $user;
                }
            }

            foreach ($asignacion as $userAsig) {

                $user = User::whereNotNull('email_verified_at')->with('rolAsignacion')->where([['id', '=', $userAsig->userId]])->first();

                if (empty($user->rolAsignacion)) {
                    $user->rolUsuario = 'Sin rol';
                }
                else {
                    $user->rolUsuario = $user->rolAsignacion->rol->name;
                }

                $user->makeHidden(['rolAsignacion', 'email_verified_at', 'updated_at']);

                $usersTMp[$user->id] = $user;
            }

            $arrUserFinal = [];
            foreach ($usersTMp as $tmp) {
                $arrUserFinal[] = $tmp;
            }

            return $this->ResponseSuccess('Información obtenida con éxito', $arrUserFinal);
        }
        else {
            return $this->ResponseError('SSO-547', 'Error al listar usuarios');
        }
    }

    //Access Productos
    public function AccessProducts($type = false) {
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        if(empty($usuarioLogueado)) return [];
        $rol = $usuarioLogueado->rolAsignacion;
        $rolId = $rol->rolId;
        $grupoUsuario = $usuarioLogueado->grupos->all();
        $grupoRol = UserGrupoRol::where('rolId', $rolId)->get()->all();

        $grupos = array_unique(
            array_map(
                function($e){ return $e->userGroupId;},
                array_merge($grupoUsuario, $grupoRol))
        );

        $canales = array_unique(
            array_map(
                function($e){ return $e->userCanalId;},
                UserCanalGrupo::whereIn('userGroupId', $grupos)->get()->all())
        );

        $productos = Productos::all();
        $data = [];
        $dataAccessStatus = [];

        foreach($productos as $producto){
            $visibilidadProducto = json_decode($producto->extraData, true);
            $visibilidadAccessStatus = $visibilidadProducto['visibilidadStatus'] ?? false;

            if(!empty($visibilidadAccessStatus)
            && (in_array($rolId, array_map('intval', $visibilidadAccessStatus['roles_assign']))
            || count(array_intersect($grupos, array_map('intval', $visibilidadAccessStatus['grupos_assign']))) > 0
            || count(array_intersect($canales, array_map('intval', $visibilidadAccessStatus['canales_assign']))) > 0)){
                $dataAccessStatus[] = $producto->id;
            }

            if(!in_array($rolId, array_map('intval', $visibilidadProducto['roles_assign']))
            && count(array_intersect($grupos, array_map('intval',$visibilidadProducto['grupos_assign']))) <= 0
            && count(array_intersect($canales, array_map('intval',$visibilidadProducto['canales_assign']))) <= 0) continue;
            $data[] = $producto->id;
        }

        if(!empty($type) && $type === 'status') return $dataAccessStatus;

        return $data;
    }
}
