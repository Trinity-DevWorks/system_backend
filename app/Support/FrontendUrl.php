<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class FrontendUrl
{
    /**
     * Build the SPA origin for the current request host (tenant or central).
     */
    public static function baseFromRequest(Request $request): string
    {
        return self::baseForHost($request->getHost());
    }

    /**
     * SPA origin for an explicit host (e.g. a tenant domain when the request is on the central host).
     */
    public static function baseForHost(string $host): string
    {
        $scheme = (string) config('frontend.scheme', 'http');
        $port = config('frontend.port');

        $authority = $host;
        if ($port !== null && $port !== '') {
            $portNum = (int) $port;
            $isDefault = ($scheme === 'https' && $portNum === 443)
                || ($scheme === 'http' && $portNum === 80);

            if ($portNum > 0 && ! $isDefault) {
                $authority .= ':'.$portNum;
            }
        }

        return "{$scheme}://{$authority}";
    }

    public static function passwordReset(Request $request, string $token, string $email): string
    {
        return self::passwordResetForHost($request->getHost(), $token, $email);
    }

    public static function passwordResetForHost(string $host, string $token, string $email): string
    {
        return self::baseForHost($host).'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
