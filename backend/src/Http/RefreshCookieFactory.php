<?php

namespace App\Http;

use App\Application\Auth\TokenBundle;
use Symfony\Component\HttpFoundation\Cookie;

final class RefreshCookieFactory
{
    public const string NAME = 'mygenotree_refresh';

    public function create(TokenBundle $bundle): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($bundle->refreshToken)
            ->withExpires($bundle->refreshExpiresAt)
            ->withPath('/api/v1/auth')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function expire(): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue('')
            ->withExpires(1)
            ->withPath('/api/v1/auth')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}
