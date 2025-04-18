<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Extra\ClassCache;
use App\Models\UserApiKey;
use App\Models\Configuration;
use App\Models\Marca;
use App\Models\Rol;
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
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use Laravel\Sanctum\PersonalAccessToken;
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
        $app = $request->get('app');
        $subToken = $request->get('stoken');

        /*if (empty($app)) {
                return $this->ResponseError('AUTH-DOM14', 'Dominio inválido o sin autorización');
            }*/

        $ssoDomain = env('APP_DOMAIN');
        $token = PersonalAccessToken::where([['token', '=', $tokenBearer]])->first();

        /*dd($tokenBearer);
        dd($token);*/

        if (!empty($token)) {
            $user = User::where('id', $token->tokenable_id)->first();
            define('SSO_USER', $user ?? false);
            define('SSO_USER_ID', $user->id ?? false);
            define('SSO_USER_ROL', $user->rolAsignacion->rol ?? false);
            define('SSO_USER_ROL_ID', $user->rolAsignacion->rol->id ?? false);
            define('SSO_BRAND', $user->marca ?? 0);
            define('SSO_BRAND_ID', $user->marca->id ?? 0);
            define('APP_STORAGE_PATH', Storage::disk('local')->path('') ?? 0);
            $getUserAccess = $this->GetUserAccess();
            $tokenTmp = '';

            if (!empty($app)) {

                // si está logueado, creo el token para la nueva app
                $subTokenTmp = PersonalAccessToken::where([['tokenable_id', '=', $user->id], ['token', '=', $subToken]])->first();

                // valido subtoken
                if (empty($tmpToken)) {

                    // si inicio sesión, cierro en otros lugares
                    PersonalAccessToken::where([['tokenable_id', '=', $user->id]])->delete();

                    $tokenTmp = random_bytes(20);
                    $tokenTmp = bin2hex($tokenTmp);
                    $userTmpId = md5($user->id);
                    $tokenTmp = "{$user->id}{$tokenTmp}{$userTmpId}";
                    $dateNow = Carbon::now();

                    $subTokenTmp = new PersonalAccessToken();
                    $subTokenTmp->name = $user->name;
                    $subTokenTmp->tokenable_id = $user->id;
                    $subTokenTmp->token = $tokenTmp;
                    $subTokenTmp->expires_at = $dateNow->addDays(3)->toTimeString();
                    $subTokenTmp->last_used_at = Carbon::now()->toTimeString();
                    $subTokenTmp->created_at = Carbon::now()->toTimeString();
                    $subTokenTmp->updated_at = Carbon::now()->toTimeString();
                    $subTokenTmp->save();
                }
                else {
                    $tokenTmp = $subTokenTmp->token;
                }
            }

            // validar expiración
            $now = Carbon::now();
            $expireAt = Carbon::parse($token->expires_at);

            /*var_dump($now);
            dd($expireAt);*/

            if ($now->gt($expireAt)) {
                $token->delete();
                return $this->ResponseError('AUTH-S21', 'La sesión ha vencido');
            }

            return $this->ResponseSuccess('Usuario logueado', [
                'logged' => 1,
                'token' => $user->token,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->nombreUsuario,
                'm' => $getUserAccess,
                'st' => $tokenTmp,
            ]);
        }
        else {
            return $this->ResponseError('AUTH-TKNFA', 'Token inválido');
        }
    }

    public function loginUser(Request $request) {
        try {

            $brandSlug = $request->get('brand');
            $usuario = $request->get('nombreUsuario');
            $password = $request->get('password');
            $app = $request->get('app');

            $brand = Marca::where('slug', $brandSlug)->first();

            if (empty($brand)) {
                return $this->ResponseError('AUTH-B54', 'La marca es inválida');
            }

            $user = User::where('nombreUsuario', $usuario)->where('marcaId', $brand->id)->first();

            if (!empty($user)) {

                $ipFrom = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null);

                $detectDevice = new \Detection\MobileDetect;
                $userAgent = $detectDevice->getUserAgent();

                if (!Hash::check($password, $user->password)) {
                    return $this->ResponseError('AUTH-I60F', 'El usuario no existe o la contraseña es incorrecta');
                }

                // creo el token
                $tokenTmp = random_bytes(20);
                $tokenTmp = bin2hex($tokenTmp);
                $tokenTmp = "{$user->id}{$tokenTmp}";

                $dateNow = Carbon::now();
                $token = PersonalAccessToken::where([['tokenable_id', '=', $user->id]])->first();

                if (empty($token)) {
                    $token = new PersonalAccessToken();
                }
                $token->name = $user->name;
                $token->tokenable_id = $user->id;
                $token->token = $tokenTmp;
                $token->expires_at = $dateNow->addDays(2)->toDateTimeString();
                $token->last_used_at = Carbon::now()->toDateTimeString();
                $token->created_at = Carbon::now()->toDateTimeString();
                $token->updated_at = Carbon::now()->toDateTimeString();
                $token->save();

                // guardo log
                $userLog = new UserLog();
                $userLog->userId = $user->id;
                $userLog->ipFrom = $ipFrom;
                $userLog->userAgent = $userAgent;
                $userLog->save();

                return $this->ResponseSuccess('Ok', [
                    'logged' => 1,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->nombreUsuario,
                    'token' => $tokenTmp,
                ]);
            }
            else {
                return $this->ResponseError('AUTH-UI60F', 'El usuario no existe o la contraseña es incorrecta');
            }

        } catch (\Throwable $th) {
            // dd($th->getMessage());
            return $this->ResponseError('AUTH-AF60F', 'Error al iniciar sesión');
        }
    }

    public function resetPassword(Request $request) {

        $request->validate(['nombreUsuario' => 'required']);

        $usuario = $request->get('nombreUsuario');
        $recuperarCon = $request->get('recuperarCon');

        if ($recuperarCon === 'corporativo') {
            $user = User::where('corporativo', $usuario)->first();
        }
        else if ($recuperarCon === 'usuario') {
            $user = User::where('nombreUsuario', $usuario)->first();
        }
        else if ($recuperarCon === 'correo') {
            $user = User::where('email', $usuario)->first();
        }

        if (!empty($user)) {
            $token = md5(rand(1000, 10000) . microtime()) . md5(microtime() . rand(1000, 10000));
            $user->resetPassword = $token;
            $user->save();

            $configH = new ConfigController();

            // envio de credenciales
            if (!empty($user->email)) {

                $config = $configH->GetConfig('mailgunNotifyConfig');
                $mg = Mailgun::create($config->apiKey ?? ''); // For US servers

                // reemplazo plantilla
                $asunto = $configH->GetConfig('userResetTemplateHtmlAsunto');
                $emailTemplate = $configH->GetConfig('userResetTemplateHtml');

                $tokenUrl = env('APP_URL')."/#/reset-my-password/{$token}";
                $emailTemplate = str_replace('::URL_LOGIN::', env('APP_URL'), $emailTemplate);
                $emailTemplate = str_replace('::USERNAME::', $user->nombreUsuario, $emailTemplate);
                $emailTemplate = str_replace('::NAME::', $user->name, $emailTemplate);
                $emailTemplate = str_replace('::CORREO::', $user->email, $emailTemplate);
                $emailTemplate = str_replace('::TELEFONO::', $user->telefono, $emailTemplate);
                $emailTemplate = str_replace('::CORPORATIVO::', $user->corporativo, $emailTemplate);
                $emailTemplate = str_replace('::URL_RECUPERACION::', $tokenUrl, $emailTemplate);

                try {
                    $mg->messages()->send($config->domain ?? '', [
                        'from' => $config->from ?? '',
                        'to' => $user->email,
                        'subject' => $asunto,
                        'html' => $emailTemplate
                    ]);
                } catch (Exception $e) {
                    return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
                }
            }

            try {

                if (!empty($user->telefono)) {

                    $tokenUrl = env('APP_URL')."/#/reset-my-password/{$token}";

                    //dd($tokenUrl);

                    $whatsappCredentialsSend = $configH->GetConfig('whatsappPasswordForgot', true);
                    $whatsappCredentialsSendToken = $configH->GetConfig('whatsappPasswordForgotToken');
                    $whatsappCredentialsSendUrl = $configH->GetConfig('whatsappPasswordForgotUrl');

                    // reemplazo plantilla
                    $whatsappCredentialsSend = str_replace('::URL_LOGIN::', env('APP_URL'), $whatsappCredentialsSend);
                    $whatsappCredentialsSend = str_replace('::USERNAME::', $user->nombreUsuario, $whatsappCredentialsSend);
                    $whatsappCredentialsSend = str_replace('::NAME::', $user->name, $whatsappCredentialsSend);
                    $whatsappCredentialsSend = str_replace('::CORREO::', $user->email, $whatsappCredentialsSend);
                    $whatsappCredentialsSend = str_replace('::TELEFONO::', $user->telefono, $whatsappCredentialsSend);
                    $whatsappCredentialsSend = str_replace('::CORPORATIVO::', $user->corporativo, $whatsappCredentialsSend);
                    $whatsappCredentialsSend = str_replace('::URL_RECUPERACION::', $tokenUrl, $whatsappCredentialsSend);

                    //dd($whatsappCredentialsSend);

                    $headers = [
                        'Authorization: Bearer ' . $whatsappCredentialsSendToken ?? '',
                        'Content-Type: application/json',
                    ];


                    //dd($whatsappCredentialsSend);
                    //var_dump($headers);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $whatsappCredentialsSendUrl ?? '');
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $whatsappCredentialsSend);  //Post Fields
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    $server_output = curl_exec($ch);
                    $server_output = @json_decode($server_output, true);
                    curl_close($ch);

                    //die();
                    //dd($server_output);

                    if (empty($server_output['success'])) {
                        return $this->ResponseError('AUTH-RL934', 'Error al enviar Whatsapp: ' . ($server_output['reason']['details'][0]['description'] ?? 'Error desconocido'));
                    }
                }

                return $this->ResponseSuccess('Si tu cuenta existe, llegará un enlace de recuperación');
            } catch (Exception $e) {
                return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
            }

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

            $configH = new ConfigController();
            $config = $configH->GetConfig('passwordSecurity');

            if(!empty($config->longitudPass) && $config->longitudPass > 0) {
                if (strlen($request->password) < $config->longitudPass){
                    return $this->ResponseError('AUTH-PAS45', "La contraseña debe tener más de {$config->longitudPass} caracteres");
                }
            }

            if(!empty($config->letrasPass)) {
                if (!preg_match('/[A-Za-z]/', $request->password)){
                    return $this->ResponseError('AUTH-PAS42', "La contraseña debe tener una letra o más");
                }
            }

            if(!empty($config->numerosPass)) {
                if (!preg_match('/[0-9]/', $request->password)){
                    return $this->ResponseError('AUTH-PAS45', "La contraseña debe tener un número o más");
                }
            }

            /*
               +"longitudPass": 6
              +"letrasPass": true
              +"numerosPass": true
              +"caracteresPass": true
             * */

            //dd($config);

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

        $token = PersonalAccessToken::where([['tokenable_id', '=', SSO_USER->id]]);

        if ($token->delete()) {
            return $this->ResponseSuccess('Ok');
        }
        else {
            return $this->ResponseError('LG-54', 'Error al cerrar sesión');
        }
    }

    public function CheckAccess($accessToCheck = []) {

        //return true;

        $hasAccess = true;
        $accessListUser = $this->GetUserAccess();
        foreach ($accessToCheck as $access) {
            if (!isset($accessListUser[$access])) {
                $hasAccess = false;
            }
        }
        //return true;
        return $hasAccess;
    }

    public function NoAccess() {
        return $this->ResponseError('AUTH-001', 'Usuario sin acceso al área solicitada');
    }

    public function GetUserAccess() {
        if (!defined('SSO_USER') || !SSO_USER) return [];
        $user = SSO_USER;
        //$rolUser = $user->rolAsignacion->rol ?? 0;

        $cache = ClassCache::getInstance();
        if (!empty($user->id)) {
            $cacheKey = "USR_R_{$user->id}";
            $rolUser = $cache->getMemcached($cacheKey);
            $rolUser = null;
            if (empty($rolUser)) {
                $rolUser = $user->rolAsignacion->rol ?? 0;
                $cache->setMemcached($cacheKey, $rolUser, 20); // 20 segundos
            }
        }

        $cacheKey = "USR_LA_{$user->id}";
        $accessTMP = $cache->getMemcached($cacheKey);
        $accessTMP = null;
        if (empty($accessTMP)) {
            $accessTMP = $this->LoadUserAccess($rolUser->id ?? 0);
            $cache->setMemcached($cacheKey, $accessTMP, 20); // 20 segundos
        }

        return $accessTMP['data'] ?? [];
    }

    public function GetUserList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $users = User::whereNotNull('email_verified_at')->where('marcaId', SSO_BRAND_ID)->with('rolAsignacion')->get();

        $usersTMp = [];

        if (!empty($users)) {

            foreach ($users as $user) {

                if (empty($user->rolAsignacion)) {
                    $user->rolUsuario = 'Sin rol';
                }
                else {
                    $user->rolUsuario = $user->rolAsignacion->rol->name;
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

        $user = User::where([['id', '=', $userid]])->where('marcaId', SSO_BRAND_ID)->with('rolAsignacion', 'log')->first();

        if (!empty($user)) {
            $user->rolUsuario = $user->rolAsignacion->rolId ?? 0;

            // proceso el log
            $logArr = [];
            foreach ($user->log as $log) {
                $log->date = Carbon::parse($log->created_at)->format('d-m-Y H:i:s');
                $logArr[] = $log;
            }
            $user->logs = $logArr;

            // traigo las apps
            /*$appsList = $user->apps;
            $apps = [];
            foreach ($appsList as $tmp) {
                $apps[$tmp['appId']] = true;
            }
            $user->appList = $apps;*/

            $apiKeys = $user->apiKeys ?? [];
            $apiKeysArr = [];
            foreach ($apiKeys as $key) {
                if ($key->activo) {
                    $apiKeysArr[$key->apikey] = [
                        'id' => $key->id,
                        'createdAt' => Carbon::parse($key->createdAt)->format('d-m-Y H:i'),
                        'apikey' => $key->apikey,
                        'activo' => $key->activo,
                    ];
                }
            }
            $user->apiKeyList = $apiKeysArr;

            $user->makeHidden(['rolAsignacion', 'log', 'email_verified_at', 'updated_at', 'apps', 'apiKeys']);

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
        $expiryDays = $request->get('expiryDays');
        $telefono = $request->get('telefono');
        $telefono = str_replace('-', '', $telefono);
        $telefono = str_replace(' ', '', $telefono);

        $corporativo = $request->get('corporativo');
        $changePassword = $request->get('changePassword');

        // validación de campos
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->ResponseError('US-EM14', 'Correo electrónico inválido');
        }

        $role = Rol::where([['id', '=', $rol]])->first();

        if (empty($role)) {
            return $this->ResponseError('AUTH-RUE93', 'El rol no existe o es inválido');
        }

        if (empty($id)) {
            $user = new User();
            $user->email_verified_at = Carbon::now()->format('Y-m-d H:i:s');
            $user->token = md5(uniqid()).uniqid();
        }
        else {
            $user = User::where('id', $id)->first();
        }

        if ($user->nombreUsuario !== $nombreUsuario) {
            // verifico el correo electrónico
            $userTmp = User::where('nombreUsuario', $nombreUsuario)->where('marcaId', SSO_BRAND_ID)->first();
            if (!empty($userTmp)) {
                return $this->ResponseError('AUTH-UE934', 'El nombre de usuario ya se encuentra en uso');
            }
        }

        // verifico email duplicado
        $userTmp = User::where([['email', '=', $email], ['id', '<>', $id]])->where('marcaId', SSO_BRAND_ID)->first();
        if (!empty($userTmp)) {
            return $this->ResponseError('AUTH-UE934', 'El correo electrónico ya se encuentra configurado en otro usuario');
        }

        if ($changePassword) {
            // Validar password
            $password = strip_tags($password);
            $passConfig = new \stdClass();
            $passConfig->longitudPass = 8;
            $passConfig->letrasPass = true;
            $passConfig->numerosPass = true;
            $passConfig->caracteresPass = true;

            $passwordOk = true;
            if (!empty($passConfig->longitudPass)) {
                if (strlen($password) < $passConfig->longitudPass) $passwordOk = false;
            }
            if (!empty($passConfig->letrasPass)) {
                if(!preg_match('/[A-Z]/', $password)) $passwordOk = false;
            }
            if (!empty($passConfig->numerosPass)) {
                if(!preg_match('/[0-9]/', $password)) $passwordOk = false;
            }
            if (!empty($passConfig->caracteresPass)) {
                if(!preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $password)) $passwordOk = false;
            }

            if (!$passwordOk) {
                return $this->ResponseError('AUTH-FL9AF', 'La contraseña no cumple con los parámetros establecidos');
            }
            $user->password = Hash::make($password);
        }

        $user->nombreUsuario = $nombreUsuario;
        $user->name = strip_tags($name);
        $user->marcaId = SSO_BRAND_ID;
        $user->email = strip_tags($email);
        $user->telefono = strip_tags($telefono);
        $user->corporativo = strip_tags($corporativo);
        $user->active = intval($active);
        $user->save();

        $userRole = UserRol::firstOrNew(['userId' => $user->id]);
        $userRole->rolId = $role->id;
        $userRole->save();

        if (!empty($user)) {
            // envio de credenciales
            return $this->ResponseSuccess('Usuario guardado con éxito', $user->id);
        }
        else {
            return $this->ResponseError('AUTH-RL934', 'Error al crear rol');
        }
    }

    public function CreateApiKey(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        try {
            $user = User::where('id', SSO_USER_ID)->where('marcaId', SSO_BRAND_ID)->first();

            if (!empty($user)) {

                $tokenSmall = bin2hex(random_bytes(10));
                $tokenSmall = strtoupper($tokenSmall);

                $token = bin2hex(random_bytes(30));
                $token = strtoupper($token);

                $userApikey = new UserApiKey();
                $userApikey->userId = $user->id;
                $userApikey->apiKey = $tokenSmall;
                $userApikey->secretApiKey = $token;
                $userApikey->activo = 1;
                $userApikey->save();

                return $this->ResponseSuccess('API Key creada con éxito', [
                    'apiKey' => $tokenSmall,
                    'secretApiKey' => $token,
                ]);
            }
            else {
                return $this->ResponseError('AUTH-UR532', 'Error al crear api key');
            }
        } catch (\Throwable $th) {
            //var_dump($th->getMessage());
            return $this->ResponseError('AUTH-UR530', 'Error al crear api KEY');
        }
    }

    public function RemoveApiKey(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $apiKey = UserApiKey::find($id);

            if (!empty($apiKey)) {

                $apiKey->activo = 0;
                $apiKey->save();

                return $this->ResponseSuccess('API Key desactivada con éxito');
            }
            else {
                return $this->ResponseError('AUTH-AP532', 'Llave inválida');
            }
        } catch (\Throwable $th) {
            //var_dump($th->getMessage());
            return $this->ResponseError('AUTH-UR531', 'Error al eliminar');
        }
    }

    public function DeleteUser(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $user = User::where('id', $id)->where('marcaId', SSO_BRAND_ID);

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

    // grupos de usuario
    public function GetUserGrupoList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin/grupos'])) return $AC->NoAccess();

        $items = UserGrupo::where('marcaId', SSO_BRAND_ID)->get();

        if (!empty($items)) {
            return $this->ResponseSuccess('Información obtenida con éxito', $items);
        }
        else {
            return $this->ResponseError('USR-23', 'Error al listar usuarios');
        }
    }

    public function LoadUserGrupo($id) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $user = UserGrupo::where([['id', '=', $id]])->where('marcaId', SSO_BRAND_ID)->with('users', 'roles')->first();

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
            $item = UserGrupo::where('id', $id)->where('marcaId', SSO_BRAND_ID)->first();
        }

        $item->marcaId = SSO_BRAND_ID;
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
        if (!$AC->CheckAccess(['users/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $user = UserGrupo::where('id', $id)->where('marcaId', SSO_BRAND_ID);

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

        $items = UserCanal::where('marcaId', SSO_BRAND_ID)->get();

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

        $user = UserCanal::where([['id', '=', $id]])->where('marcaId', SSO_BRAND_ID)->with('grupos')->first();

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
            $item = UserCanal::where('id', $id)->where('marcaId', SSO_BRAND_ID)->first();
        }

        $item->marcaId = SSO_BRAND_ID;
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
            $user = UserCanal::where('marcaId', SSO_BRAND_ID)->get();

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

    public function GetRoleList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $roleList = Rol::where('marcaId', SSO_BRAND_ID)->get();

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

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $role = Rol::where([['id', '=', $rolId]])->where('marcaId', SSO_BRAND_ID)->first();

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
            $role = Rol::where([['id', '=', $roleId]])->where('marcaId', SSO_BRAND_ID)->first();
        }
        else {
            $role = new Rol();
        }

        $role->marcaId = SSO_BRAND_ID;
        $role->name = strip_tags($name);
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

            /*// borro los accesos por rol
            RolApp::where([['rolId', '=', $role->id]])->where('marcaId', SSO_BRAND_ID)->delete();

            // guardo los accesos
            foreach ($appList as $item) {
                if (!empty($item['active'])) {
                    $row = new RolApp();
                    $row->rolId = $role->id;
                    $row->appId = $item['id'];
                    $row->save();
                }
            }*/
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
            $role = Rol::where('id', $id)->where('marcaId', SSO_BRAND_ID)->first();

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

        $role = Rol::where([['id', '=', $roleid]])->where('marcaId', SSO_BRAND_ID)->first();

        if (empty($role)) {
            return $this->ResponseError('ERRO-5148', 'El rol no existe');
        }

        $roles = [];
        $roles['rol'] = $role ?? [];
        $roles['access'] = LgcAccessConfig;

        $permisions = [];
        if ($role->superAdmin) {
            foreach ($roles['access'] as $module) {
                foreach ($module['access'] as $access) {
                    $permisions[] = [
                        'rolId' => $role->id,
                        'access' => $access['slug'],
                    ];
                }
            }
        }
        else {
            $permisions = $role->access;
            $permisions = $permisions->toArray();
        }

        $accessList = [];

        try {
            foreach ($roles['access'] as $keyModule => $access) {

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

    private function randomPassword() {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz()*/=@ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890()*/=@123456789';
        $pass = array(); //remember to declare $pass as an array
        $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
        for ($i = 0; $i < 10; $i++) {
            $n = rand(0, $alphaLength);
            $pass[] = $alphabet[$n];
        }
        return implode($pass); //turn the array into a string
    }

    // Jerarquía de usuarios
    public function GetUserJerarquiaList() {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['users/jerarquia/admin'])) return $AC->NoAccess();

        $items = UserJerarquia::where('marcaId', SSO_BRAND_ID)->get();

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

        $item = UserJerarquia::where('marcaId', SSO_BRAND_ID)->where([['id', '=', $id]])->with(['supervisor', 'detalle'])->first();

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
            $item = UserJerarquia::where('marcaId', SSO_BRAND_ID)->where('id', $id)->first();
        }

        $item->marcaId = SSO_BRAND_ID;
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
            $user = UserJerarquia::where('marcaId', SSO_BRAND_ID)->where('id', $id)->first();

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

    public function CalculateAccess_bk() {

        $AC = new AuthController();

        $usuarioId = SSO_USER_ID;
        $usersSupervisor = [];
        $usersDetalle = [];

        // valido si es permiso público
        if (empty($usuarioId)) {
            return false;
        }
        else {
            // traigo las jerarquías donde esté el usuario
            $usersJerarquia = UserJerarquia::where('activo', 1)->get();

            foreach ($usersJerarquia as $jerarquia) {

                // SUpervisores
                $jerarquiaSup = $jerarquia->supervisor;
                foreach ($jerarquiaSup as $jerarquiaSp) {

                    // usuarios directos
                    if (!empty($jerarquiaSp->userId)) {
                        $usersSupervisor[$jerarquiaSp->userId] = $jerarquiaSp->userId;
                    }

                    // por grupo
                    if (!empty($jerarquiaSp->userGroupId)) {
                        // usuarios especificos
                        if ($grupos = $jerarquiaSp->gruposUsuarios) {
                            $gruposUsuarios = $grupos->grupo->users;
                            foreach ($gruposUsuarios as $userAsig){
                                $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                            }
                        }


                        // por rol
                        if ($rol = $jerarquiaSp->gruposRol) {
                            $gruposRol = $rol->rol->usersAsig;
                            foreach ($gruposRol as $userAsig) {
                                $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                            }

                        }
                    }

                    // por rol
                    if (!empty($jerarquiaSp->rolId)) {
                        if ($rol = $jerarquiaSp->rol) {
                            $roles = $rol->usersAsig;
                            foreach ($roles as $userAsig) {
                                $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                            }
                        }
                    }
                }

                // Normales
                $jerarquiaSup = $jerarquia->detalle;
                foreach ($jerarquiaSup as $jerarquiaDt) {

                    // usuarios directos
                    if (!empty($jerarquiaDt->userId)) {
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

            if (!in_array($usuarioId, $usersSupervisor)) {
                $usersSupervisor = [];
                $usersDetalle[] = $usuarioId;
            }

            if ($AC->CheckAccess(['tareas/view/flujos-no-asig'])) {
                $usersDetalle[] = 0;
            }

        }
        return [
            'sup' => $usersSupervisor,
            'det' => $usersDetalle,
            'all' => array_merge($usersSupervisor, $usersDetalle)
        ];
    }

    public function CalculateAccess() {

        $AC = new AuthController();

        $usuarioId = (defined('SSO_USER_ID') ? SSO_USER_ID : 0);
        $marcaId= (defined('SSO_BRAND_ID') ? SSO_BRAND_ID : 0);
        $jerarquiasSupervision = [];
        $usersSupervisor = [];
        $usersDetalle = [];

        $cache = ClassCache::getInstance();
        $cacheKey = "calc_cot_access_{$usuarioId}_{$marcaId}";
        $access = $cache->getMemcached($cacheKey);
        $access = null;

        if (!empty($access)) {
            return $access;
        }

        // valido si es permiso público
        if (empty($usuarioId)) {
            return [
                'sup' => [],
                'det' => [],
                'all' => []
            ];
        }
        else {
            // traigo las jerarquías donde esté el usuario
            $usersJerarquia = UserJerarquia::where('activo', 1)->where('marcaId', $marcaId)->get();

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
                            foreach ($grupos as $userAsig) {
                                if ($userAsig->userId === $usuarioId) {
                                    $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                                    $jerarquiasSupervision[$jerarquiaSp->jerarquiaId] = $jerarquiaSp->jerarquiaId;
                                }
                            }
                        }


                        // por rol

                        if ($rol = $jerarquiaSp->gruposRol) {
                            foreach ($rol as $tmpRol) {
                                $gruposRol = $tmpRol->rol->usersAsig;
                                foreach ($gruposRol as $userAsig) {
                                    if ($userAsig->userId === $usuarioId) {
                                        $usersSupervisor[$userAsig->userId] = $userAsig->userId;
                                        $jerarquiasSupervision[$jerarquiaSp->jerarquiaId] = $jerarquiaSp->jerarquiaId;
                                    }
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

                //dd($usersSupervisor);

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

                            /*$nombreDb = "Eddy orlando pérez";
                            $nombreExcel = "Eddy Orlando Pérez";
                            $nombreDbValidar = strtolower(preg_replace('/[^A-Za-z0-9\-]/', '_', $nombreDb));
                            $nombreExcelValidar = strtolower(preg_replace('/[^A-Za-z0-9\-]/', '_', $nombreExcel));

                            if ($nombreDbValidar === $nombreExcelValidar) {
                                /// lo guardas en la misma
                            }
                            {
                                // lo duplicas
                            }*/

                            // por rol
                            if ($rol = $jerarquiaDt->gruposRol) {
                                foreach ($rol as $tmpRol) {
                                    $gruposRol = $tmpRol->rol->usersAsig;
                                foreach ($gruposRol as $userAsig) {
                                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                    }
                                }

                            }
                        }

                        // por grupo
                        if (!empty($jerarquiaDt->userGroupId)) {
                            // usuarios especificos
                            if ($grupos = $jerarquiaDt->gruposUsuarios) {
                                foreach ($grupos as $userAsig) {
                                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                }
                            }

                            // por rol
                            if ($rol = $jerarquiaDt->gruposRol) {
                                foreach ($rol as $tmpRol) {
                                    $gruposRol = $tmpRol->rol->usersAsig;
                                foreach ($gruposRol as $userAsig) {
                                    $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                    }
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

        $access = [
            'sup' => $usersSupervisor,
            'det' => $usersDetalle,
            'all' => array_merge($usersSupervisor, $usersDetalle)
        ];
        $cache->setMemcached($cacheKey, $access, 30);

        return $access;
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

    // TEST
    public function TestConnection() {

        return $this->ResponseSuccess("Hi! API Working - CloudWorkflow", [
            'date' => Carbon::now()->format('Y-m-d H:i'),
            'region' => 'latin-america-cluster',
            'environment' => 'production',
        ]);
    }
}
