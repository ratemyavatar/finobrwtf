<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class ClientIp
{
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $peer = (string) $request->server->get('REMOTE_ADDR');
        $forwarded = $request->server->get('HTTP_CF_CONNECTING_IP');

        if (is_string($forwarded) && filter_var($forwarded, FILTER_VALIDATE_IP) && $this->trusted($peer)) {
            $request->server->set('REMOTE_ADDR', $forwarded);
        }

        return $next($request);
    }

    private function trusted(string $peer): bool
    {
        $proxies = array_filter(array_map('trim', explode(',', (string) config('finobe.trusted_proxies'))));

        return $peer !== '' && IpUtils::checkIp($peer, [...self::CLOUDFLARE, ...$proxies]);
    }
}
