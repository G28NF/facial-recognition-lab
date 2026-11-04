<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;

set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Erro: '.$error->getMessage().PHP_EOL);
    exit(1);
});

$root = require dirname(__DIR__).'/app/Core/Bootstrap.php';
if (!is_file($root.'/.env')) {
    throw new RuntimeException('Arquivo .env ausente. Execute bin/generate-local-env.php antes da configuracao do banco.');
}

$config = require $root.'/config/database.php';
foreach (['database', 'charset'] as $field) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $config[$field])) {
        throw new RuntimeException('Configuracao de banco invalida.');
    }
}
if ($config['charset'] !== 'utf8mb4') {
    throw new RuntimeException('RFV2_DB_CHARSET deve ser utf8mb4 para o esquema MariaDB da aplicacao.');
}
$port = filter_var($config['port'], FILTER_VALIDATE_INT);
if ($port === false || $port < 1 || $port > 65535) {
    throw new RuntimeException('Porta de banco invalida.');
}
if (!in_array($config['host'], ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('A configuracao automatica aceita somente RFV2_DB_HOST=127.0.0.1 ou localhost. Configure o servidor remoto manualmente.');
}

$runMigrations = static function () use ($root): void {
    require $root.'/database/migrate.php';
    echo "Migrations verificadas.\n";
};

try {
    (new PdoConnection($config))->pdo();
    echo "Conexao da aplicacao com MariaDB confirmada.\n";
    $runMigrations();
    exit(0);
} catch (Throwable $initialError) {
    $previous = $initialError->getPrevious();
    if ($previous instanceof PDOException && in_array((int) ($previous->errorInfo[1] ?? 0), [2002, 2003], true)) {
        throw new RuntimeException('MariaDB indisponivel. Inicie o MySQL/MariaDB no XAMPP e confira RFV2_DB_HOST e RFV2_DB_PORT.', 0, $initialError);
    }
    fwrite(STDERR, "A conta da aplicacao ainda nao pode conectar; serao solicitadas credenciais administrativas para criar somente os recursos ausentes.\n");
}

$prompt = static function (string $message, string $default = ''): string {
    fwrite(STDOUT, $message);
    $value = fgets(STDIN);
    $value = is_string($value) ? trim($value) : '';
    return $value !== '' ? $value : $default;
};
$promptSecret = static function (): string {
    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
        $command = 'powershell.exe -NoProfile -Command "$secret = Read-Host -Prompt \'Senha administrativa do MariaDB\' -AsSecureString; $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret); try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }"';
        $value = shell_exec($command);
        if (is_string($value)) {
            return rtrim($value, "\r\n");
        }
    }
    fwrite(STDOUT, 'Senha administrativa do MariaDB (entrada visivel): ');
    $value = fgets(STDIN);
    return is_string($value) ? rtrim($value, "\r\n") : '';
};

$adminUser = $prompt('Usuario administrativo do MariaDB [root]: ', 'root');
$adminPassword = $promptSecret();
try {
    $admin = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $port, $config['charset']),
        $adminUser,
        $adminPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $error) {
    throw new RuntimeException('Nao foi possivel autenticar o administrador MariaDB. Nenhuma alteracao foi realizada.', 0, $error);
}

$accountHost = $config['host'] === 'localhost' ? 'localhost' : '127.0.0.1';
$account = $admin->prepare('SELECT 1 FROM mysql.user WHERE User = ? AND Host = ? LIMIT 1');
$account->execute([$config['user'], $accountHost]);
$accountExists = $account->fetchColumn() !== false;

$database = '`'.$config['database'].'`';
$admin->exec("CREATE DATABASE IF NOT EXISTS {$database} CHARACTER SET {$config['charset']} COLLATE utf8mb4_unicode_ci");
if (!$accountExists) {
    $admin->exec(sprintf(
        'CREATE USER %s@%s IDENTIFIED BY %s',
        $admin->quote($config['user']),
        $admin->quote($accountHost),
        $admin->quote($config['password'])
    ));
    echo "Usuario da aplicacao criado.\n";
} else {
    echo "Usuario da aplicacao ja existe; a senha existente nao foi alterada.\n";
}
$admin->exec(sprintf('GRANT ALL PRIVILEGES ON %s.* TO %s@%s', $database, $admin->quote($config['user']), $admin->quote($accountHost)));
$admin->exec('FLUSH PRIVILEGES');

try {
    (new PdoConnection($config))->pdo();
} catch (Throwable $error) {
    throw new RuntimeException('O banco foi verificado, mas a conta configurada no .env ainda nao conecta. Confira RFV2_DB_USER e RFV2_DB_PASSWORD; o script nao altera senhas existentes.', 0, $error);
}
echo "Banco e permissao da aplicacao confirmados.\n";
$runMigrations();
