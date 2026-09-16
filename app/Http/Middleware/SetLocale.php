<?php

namespace App\Http\Middleware;

use Closure;

class SetLocale
{
    protected array $supported = ['en', 'fr'];

    /**
     * Sets the app locale from an `Accept-Language` header or `?lang=` query
     * param, so translated fields (e.g. Food::display_name) resolve per request.
     */
    public function handle($request, Closure $next)
    {
        $lang = $request->query('lang') ?? $request->header('Accept-Language');
        $lang = $lang ? strtolower(substr($lang, 0, 2)) : null;

        if (in_array($lang, $this->supported, true)) {
            app()->setLocale($lang);
        }

        return $next($request);
    }
}
