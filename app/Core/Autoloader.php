<?php
declare(strict_types=1);

namespace App\Core;

final class Autoloader
{
    public static function register(string $projectRoot): void
    {
        spl_autoload_register(static function (string $class) use ($projectRoot): void {
            $prefix = 'App\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
            $path = $projectRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.$relative.'.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}
