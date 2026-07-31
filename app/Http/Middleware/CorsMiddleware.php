<?php

namespace App\Http\Middleware;

use Closure;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $allowedOrigins = env('CORS_ALLOWED_ORIGINS', env('APP_URL', '*'));
        $origin = $request->headers->get('Origin');

        if ($allowedOrigins === '*') {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } elseif ($origin && in_array($origin, explode(',', $allowedOrigins))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, Application');

        return $response;
    }
}
