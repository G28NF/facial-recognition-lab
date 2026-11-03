<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Nao foi possivel ler a configuracao local.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) || getenv($name) !== false) {
                continue;
            }

            $value = trim($value);
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
        }
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function require(string $name): string
    {
        $value = self::get($name);
        if ($value === null) {
            throw new RuntimeException("Variavel {$name} nao configurada.");
        }
        return $value;
    }
}
