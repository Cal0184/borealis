<?php
declare(strict_types=1);

namespace Borealis\Core;

/** Small declarative validator. Rules: required, string, max:N, min:N, email, int, date, in:a|b, color, bool, nullable. */
final class Validator
{
    public static function check(array $input, array $rules): array
    {
        $errors = [];
        $clean = [];
        foreach ($rules as $field => $ruleStr) {
            $list = explode(',', $ruleStr);
            $present = array_key_exists($field, $input);
            $v = $input[$field] ?? null;
            if (is_string($v)) $v = trim($v);
            $sometimes = in_array('sometimes', $list, true);
            if ($sometimes && !$present) continue;
            if ($v === '' || $v === null) {
                if (in_array('required', $list, true)) {
                    $errors[$field] = 'Este campo es obligatorio.';
                } elseif ($present || !$sometimes) {
                    $clean[$field] = null;
                }
                continue;
            }
            foreach ($list as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = match ($name) {
                    'string' => is_scalar($v) ? null : 'Valor no válido.',
                    'max' => mb_strlen((string)$v) > (int)$arg ? "Máximo $arg caracteres." : null,
                    'min' => mb_strlen((string)$v) < (int)$arg ? "Mínimo $arg caracteres." : null,
                    'email' => self::emailError((string)$v),
                    'int' => filter_var($v, FILTER_VALIDATE_INT) !== false ? null : 'Debe ser un número entero.',
                    'number' => is_numeric($v) && (float)$v >= 0 && (float)$v < 10000 ? null : 'Debe ser un número positivo.',
                    'date' => self::isDate((string)$v) ? null : 'Fecha no válida.',
                    'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', (string)$v) ? null : 'Hora no válida (hh:mm).',
                    'in' => in_array((string)$v, explode('|', (string)$arg), true) ? null : 'Opción no válida.',
                    'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$v) ? null : 'Color no válido.',
                    'bool' => in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false', 'on'], true) ? null : 'Valor no válido.',
                    default => null,
                };
                if ($err) { $errors[$field] = $err; break; }
            }
            if (!isset($errors[$field])) {
                $clean[$field] = match (true) {
                    in_array('int', $list, true) => (int)$v,
                    in_array('bool', $list, true) => in_array($v, [true, 1, '1', 'true', 'on'], true),
                    in_array('number', $list, true) => (float)$v,
                    in_array('color', $list, true) => strtoupper((string)$v),
                    default => is_string($v) ? $v : (is_scalar($v) ? (string)$v : $v),
                };
            }
        }
        if ($errors) throw HttpException::invalid($errors);
        return $clean;
    }

    /** Common misspellings of big providers' domains => the intended domain. */
    public const EMAIL_TYPOS = [
        'gmail.con' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmail.om' => 'gmail.com', 'gmail.comm' => 'gmail.com', 'gmail.es' => 'gmail.com',
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gimail.com' => 'gmail.com', 'gmail.cpm' => 'gmail.com', 'gmail.vom' => 'gmail.com', 'gmail.xom' => 'gmail.com',
        'hotmail.con' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmial.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com', 'hotmil.com' => 'hotmail.com', 'homail.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com', 'hotmail.cm' => 'hotmail.com', 'hormail.com' => 'hotmail.com',
        'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com', 'outloo.com' => 'outlook.com', 'outlook.co' => 'outlook.com', 'otulook.com' => 'outlook.com',
        'yaho.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.co' => 'yahoo.com', 'yhoo.com' => 'yahoo.com',
        'icloud.con' => 'icloud.com', 'iclod.com' => 'icloud.com', 'icloud.co' => 'icloud.com', 'live.con' => 'live.com',
    ];

    /**
     * Stricter than "has an @ and a dot": a valid address per PHP, a real-looking domain
     * (labels of letters, digits and hyphens, a top-level domain of 2+ letters), no dots at the
     * edges of the user part, and a suggestion for common misspellings such as gmail.con.
     */
    public static function emailError(string $v): ?string
    {
        $v = trim($v);
        $bad = 'Correo electrónico no válido. Revisa que tenga la forma nombre@dominio.com.';
        if (mb_strlen($v) > 190 || substr_count($v, '@') !== 1) return $bad;
        [$local, $domain] = explode('@', $v);
        if ($local === '' || $domain === '' || strlen($local) > 64) return $bad;
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return $bad;
        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) return $bad;
        $domain = strtolower($domain);
        $labels = explode('.', $domain);
        if (count($labels) < 2) return $bad;
        foreach ($labels as $l) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $l)) return $bad;
        }
        if (!preg_match('/^[a-z]{2,24}$/', end($labels))) return 'El dominio del correo no parece válido (después del último punto debe haber al menos dos letras, como .com o .mx).';
        if (isset(self::EMAIL_TYPOS[$domain])) return '¿Quisiste decir ' . $local . '@' . self::EMAIL_TYPOS[$domain] . '? Revisa el dominio del correo.';
        return null;
    }

    public static function isDate(string $v): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
