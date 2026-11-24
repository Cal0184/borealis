<?php
declare(strict_types=1);

namespace Borealis\Core;

class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $msg = 'No encontramos lo que buscas.'): self { return new self(404, $msg); }
    public static function forbidden(string $msg = 'No tienes permiso para realizar esta acción.'): self { return new self(403, $msg); }
    public static function unauthorized(string $msg = 'Tu sesión terminó. Inicia sesión de nuevo.'): self { return new self(401, $msg); }
    public static function invalid(array $errors, string $msg = 'Revisa los campos marcados.'): self { return new self(422, $msg, $errors); }
    public static function conflict(string $msg): self { return new self(409, $msg); }
}
