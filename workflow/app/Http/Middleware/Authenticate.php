<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Models\UserApiKey;
use Carbon\Carbon;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\UserApp;

class Authenticate
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            return route('login');
        }
    }

    public function handle(Request $request, Closure $next)
    {

        $tokenBearer = $request->bearerToken();
        $isAPI = (strpos($tokenBearer, ':') > 0) ? 'API: ' : '';
        $expire = false;

        if ($isAPI) {
            $apiKey = explode(':', $tokenBearer);
            $tokenPersonal = UserApiKey::where('apiKey', trim($apiKey[0] ?? ''))->where('secretApiKey', trim($apiKey[1] ?? ''))->first();

            if (empty($tokenPersonal)) {
                return [
                    'status' => 0,
                    'msg' => $isAPI.' API Key inválida o no existe',
                    'data' => [],
                    'error-code' => 'AUT-006',
                ];
            }

            $expire = Carbon::now()->addDays(1);
            $user = User::where('id', $tokenPersonal->userId)->first();
        }
        else {
            $tokenPersonal = PersonalAccessToken::where('token', $tokenBearer)->first();

            if (empty($tokenPersonal)) {
                return [
                    'status' => 0,
                    'msg' => $isAPI.'Token inválido',
                    'data' => [],
                    'error-code' => 'AUT-003',
                ];
            }

            $expire = Carbon::parse($tokenPersonal->expires_at);
            $user = User::where('id', $tokenPersonal->tokenable_id)->first();
        }

        // valido expiración

        $now = Carbon::now();

        if ($now->gt($expire)) {
            return [
                'status' => 0,
                'msg' => $isAPI.'El token ha expirado o no existe',
                'data' => [],
                'error-code' => 'AUT-003',
            ];
        }



        if (empty($user)) {
            return [
                'status' => 0,
                'msg' => $isAPI.'Usuario inválido',
                'data' => [],
                'error-code' => 'AUT-002',
            ];
        }
        auth('sanctum')->setUser($user);

        define('SSO_USER', $user);
        define('SSO_USER_ID', $user->id ?? 0);
        define('SSO_USER_ROL', $user->rolAsignacion->rol ?? false);
        define('SSO_USER_ROL_ID', $user->rolAsignacion->rol->id ?? false);
        define('SSO_BRAND', $user->marca ?? 0);
        define('SSO_BRAND_ID', $user->marca->id ?? 0);
        define('APP_STORAGE_PATH', Storage::disk('local')->path('') ?? 0);

        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        $response = $next($request);
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
