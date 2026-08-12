<?php

declare(strict_types=1);

namespace app\support;

use think\Response;
use think\facade\Session;

final class Web
{
    public static function redirect(string $url, ?string $message = null, string $type = 'success'): Response
    {
        $response = Response::create($url, 'redirect');
        if ($message !== null) {
            $response->with($type, $message);
        }

        return $response;
    }

    public static function csrfToken(): string
    {
        $token = (string) Session::get('csrf_token', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }

        return $token;
    }

    public static function rotateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set('csrf_token', $token);

        return $token;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
