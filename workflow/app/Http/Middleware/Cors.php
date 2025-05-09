<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle($request, Closure $next) {

        $allowRequest = false;

        // For test
        $hostOrigin = (isset($_SERVER["HTTP_ORIGIN"]))?parse_url($_SERVER["HTTP_ORIGIN"]):false;
        if (!$hostOrigin) {
            $hostOrigin["host"] = $_SERVER["HTTP_HOST"];
        }

        // Mejor quemado, evita un query
        $dominiosPermitidos = [
        ];

        if (!empty($hostOrigin["host"])) {

            // If the domain is allowed
            if (in_array($hostOrigin["host"], $dominiosPermitidos)) {
                $allowRequest = true;
            }
        }

        $allowRequest = true; // acá no validaré dominios


        // If the domain have access
        if ($allowRequest) {
            $headers = [
                'Access-Control-Allow-Origin'      => '*',
                'Access-Control-Allow-Methods'     => 'POST, GET, OPTIONS',
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Max-Age'           => '86400',
                'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, Access-Control-Allow-Origin, Access-Control-Allow-Methods, x-from-app'
            ];

            if ($request->isMethod('OPTIONS')) {
                return response()->json('{"method":"OPTIONS"}', 200, $headers);
            }

            $response = $next($request);
            foreach($headers as $key => $value) {
                $response->header($key, $value);
            }

            return $response;
        }
        else{
            return "Dominio sin acceso al área solicitada";
        }
    }
}
