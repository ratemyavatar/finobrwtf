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

        // any other 403 (abort(403), staff-only pages): the real site's "Error" card, with the normal navbar
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) {
            if ($e->getStatusCode() !== 403 || $request->expectsJson()) {
                return null;
            }
            try {
                $data = (new \App\Http\Controllers\frontEnd($request))->getData();
                $data['data']['embeds']['title'] = '403 Forbidden' . ($data['data']['embeds']['title'] ?? ' - Finobe');
                return response()->view('v2/Forbidden', $data, 403);
            } catch (\Throwable $ignored) {
                return null;   // fall back to Laravel's own 403 page
            }
        });

        // upload bigger than PHP allows: send people back with a normal message instead of an error page.
        // (this happens before the session starts, so the message travels in the URL: ?upload=too_big)
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            $back = strtok(url()->previous(), '#');
            $back .= (str_contains($back, '?') ? '&' : '?') . 'upload=too_big';

            return redirect($back . (str_contains($request->path(), 'settings') ? '#general' : ''));
        });
    })
    ->withMiddleware(function (Middleware $middleware) {
        // (keep every middleware setting in this one block: a second withMiddleware() resets the global list)

        // Roblox clients use mixed-case paths (/Game/..., /Asset/...); normalise before routing
        $middleware->prepend(\App\Http\Middleware\NormalizeClientPaths::class);

        $middleware->web(append: [\App\Http\Middleware\AdminSitePolicy::class]);
        $middleware->validateCsrfTokens([
            '/logout',
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
