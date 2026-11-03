<?php
declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PdoConnection
{
    private ?PDO $pdo = null;

    /** @param array{host:string,port:string,database:string,charset:string,user:string,password:string} $config */
    public function __construct(private readonly array $config) {}

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $port = filter_var($this->config['port'], FILTER_VALIDATE_INT);
        foreach (['database', 'charset'] as $field) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $this->config[$field])) {
                throw new RuntimeException('Configuracao de banco invalida.');
            }
        }
        if ($port === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('Porta de banco invalida.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $this->config['host'], $port, $this->config['database'], $this->config['charset']);
        try {
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $error) {
            throw new RuntimeException('Banco da V2 indisponivel.', 0, $error);
        }
        return $this->pdo;
    }

    public function transaction(callable $operation): mixed
    {
        $pdo = $this->pdo();
        $owner = !$pdo->inTransaction();
        if ($owner) {
            $pdo->beginTransaction();
        }
        try {
            $result = $operation($pdo);
            if ($owner) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owner) {
                try {
                    $pdo->rollBack();
                } catch (PDOException) {
                }
            }
            throw $error;
        }
    }
}
