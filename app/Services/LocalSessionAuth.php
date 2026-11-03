<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;

final class LocalSessionAuth
{
    private const AUTH_KEY = 'rfv2_authenticated';
    private const CSRF_KEY = 'rfv2_csrf';

    public function __construct(private readonly string $accessKey)
    {
        $this->startSession();
    }

    public function login(string $provided): bool
    {
        if ($provided === '' || !hash_equals($this->accessKey, $provided)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION[self::AUTH_KEY] = true;
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function authenticated(): bool
    {
        return ($_SESSION[self::AUTH_KEY] ?? false) === true;
    }

    public function csrfToken(): string
    {
        if (!$this->authenticated()) {
            throw new DomainException('Autenticacao necessaria.', 401);
        }
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }
        return $token;
    }

    public function requireAuthenticated(bool $csrf = false): void
    {
        $this->requireLocalRequest();
        if (!$this->authenticated()) {
            throw new DomainException('Autenticacao necessaria.', 401);
        }
        if ($csrf) {
            $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!is_string($provided) || !hash_equals($this->csrfToken(), $provided)) {
                throw new DomainException('Token CSRF invalido.', 403);
            }
        }
    }

    public function requireLocalRequest(): void
    {
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            throw new DomainException('Acesso permitido somente neste computador.', 403);
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        session_name('RFV2SESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim($script, '/').'/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}
