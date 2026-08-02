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
        $scheme = (string) config('frontend.scheme', 'http');
        $host = $request->getHost();
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
        return self::baseFromRequest($request).'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
