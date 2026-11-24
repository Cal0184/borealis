<?php
declare(strict_types=1);

namespace Borealis\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function verify(Request $req): void
    {
        $sent = $req->header('x-csrf-token') ?? ($req->post['_csrf'] ?? null);
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            throw new HttpException(419, 'La página caducó por seguridad. Recarga e inténtalo de nuevo.');
        }
    }
}
