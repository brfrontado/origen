<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AuthController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

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
        $auth = new AuthController();
        $sso = $auth->validateSSO($tokenBearer);

        if (empty($sso['status'])) {
            return [
                'status' => 0,
                'msg' => $isAPI.'Error de autenticación, el usuario no está autenticado',
                'data' => [],
                'error-code' => 'AUT-001',
            ];
        }

        $user = User::where('token', $sso['data']['token'])->first();

        // si el usuario no existe, lo tengo que crear
        if (empty($user)) {
            $user = new User();
            $user->email_verified_at = Carbon::now()->format('Y-m-d H:i:s');
            $user->token = $sso['data']['token'];
        }

        $user->name = $sso['data']['name'];
        $user->email = $sso['data']['email'];
        $user->nombreUsuario = $sso['data']['username'];
        $user->save();

        // si el usuario no existe, retorno sin setear user
        if (empty($user) || $user->ssoToken !== $tokenBearer) {
            return $next($request);
        }
        auth('sanctum')->setUser($user);

        define('SSO_USER', $user);

        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');

        return $next($request);
    }
}
