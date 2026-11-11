<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) {
            if ($e->getStatusCode() !== 403 || $request->expectsJson()) {
                return null;
            }
            try {
                return response()->view('v2/Forbidden', ['data' => app(\App\View\PageData::class)->with([], '403 Forbidden')], 403);
            } catch (\Throwable $ignored) {
                return null;
            }
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            $back = strtok(url()->previous(), '#');
            $back .= (str_contains($back, '?') ? '&' : '?') . 'upload=too_big';

            return redirect($back . (str_contains($request->path(), 'settings') ? '#general' : ''));
        });
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend([\App\Http\Middleware\ClientIp::class, \App\Http\Middleware\NormalizeClientPaths::class]);

        $middleware->web(append: [\App\Http\Middleware\AdminSitePolicy::class, \App\Http\Middleware\GrantDailyDius::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        $middleware->alias(['admin' => \App\Http\Middleware\RequireAdmin::class, 'limit' => \App\Http\Middleware\LimitRoute::class]);
        $middleware->validateCsrfTokens([
            '/marketplace/productinfo',
            '/asset/getscriptstate.ashx',
            '/game/placelauncher.ashx',
            '/api/gameserver/update',
            '/game/badge/awardbadge.ashx',
            '/game/validate-machine',
            '/game/machineconfiguration.ashx',
            '/game/report-stats',
        ]);
    })
    ->create();
