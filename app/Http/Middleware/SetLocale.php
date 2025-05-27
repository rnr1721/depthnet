<?php

namespace App\Http\Middleware;

use App\Contracts\OptionsServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Application;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(
        protected OptionsServiceInterface $optionsService,
        protected CacheRepository $cache,
        protected Application $app
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $language = $this->cache->remember('app_language', 3600, function () {
            return $this->optionsService->get('site_locale', 'en');
        });
        $this->app->setLocale($language);
        return $next($request);
    }
}
