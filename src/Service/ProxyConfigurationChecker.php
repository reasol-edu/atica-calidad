<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spots, from the request at hand, a misconfigured SYMFONY_TRUSTED_PROXIES — which otherwise goes
 * unnoticed while the activity log records the proxy's IP for everyone and the password-reset rate
 * limiter lumps every user together — so the administration panel and the activity log can warn
 * about it:
 *
 * - UNTRUSTED_PROXY: the request carries X-Forwarded-* headers but comes from an address that isn't
 *   a trusted proxy. Only flagged when that address is private or loopback (a reverse proxy on the
 *   same machine or network): from a public address, the headers may just as well have been forged
 *   by the visitor, and suggesting to trust it would be wrong.
 * - TRUSTS_ANYONE: a trusted range covers the whole internet (0.0.0.0/0, ::/0), so any visitor can
 *   make up their IP with X-Forwarded-For.
 */
final class ProxyConfigurationChecker
{
    public const string UNTRUSTED_PROXY = 'untrusted_proxy';
    public const string TRUSTS_ANYONE   = 'trusts_anyone';

    private const array FORWARDED_HEADERS = ['X_FORWARDED_FOR', 'X_FORWARDED_PROTO', 'X_FORWARDED_HOST', 'X_FORWARDED_PORT', 'FORWARDED'];

    /** @return array{problem: string, proxy: string}|null what's wrong, and the address of the proxy the request came through */
    public function check(Request $request): ?array
    {
        $remote = $request->server->getString('REMOTE_ADDR');

        foreach (Request::getTrustedProxies() as $range) {
            if (\in_array($range, ['0.0.0.0/0', '::/0'], true)) {
                return ['problem' => self::TRUSTS_ANYONE, 'proxy' => $range];
            }
        }

        if ($remote === '' || $request->isFromTrustedProxy() || !$this->hasForwardedHeaders($request)) {
            return null;
        }

        return IpUtils::checkIp($remote, IpUtils::PRIVATE_SUBNETS)
            ? ['problem' => self::UNTRUSTED_PROXY, 'proxy' => $remote]
            : null;
    }

    private function hasForwardedHeaders(Request $request): bool
    {
        foreach (self::FORWARDED_HEADERS as $header) {
            if ($request->server->has('HTTP_' . $header)) {
                return true;
            }
        }

        return false;
    }
}
