<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class HandleLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales', ['en', 'ru']);
        $locale = $request->user()?->locale;

        if (! $locale) {
            $cookieLocale = $request->cookie('locale');
            if ($cookieLocale && in_array($cookieLocale, $supported, true)) {
                $locale = $cookieLocale;
            }
        }

        if (! $locale || ! in_array($locale, $supported, true)) {
            $locale = config('app.locale', 'ru');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
