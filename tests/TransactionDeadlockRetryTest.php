<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlVideoSessionRepository;

$root = require __DIR__.'/bootstrap.php';
$config = require $root.'/config/database.php';
$connection = new PdoConnection($config);
$repository = new MysqlVideoSessionRepository($connection);
$token = $repository->start(1000, ['A' => 1.0, 'B' => 1.0]);
$group = hex2bin($token);
if (!is_string($group)) throw new RuntimeException('Token de sessao invalido.');

$pdo = $connection->pdo();
$ids = $pdo->prepare('SELECT id FROM video_sessions WHERE session_group_id=:group ORDER BY architecture_id');
$ids->bindValue(':group', $group, PDO::PARAM_LOB);
$ids->execute();
$sessionIds = array_map(static fn(array $row): int => (int) $row['id'], $ids->fetchAll());
if (count($sessionIds) !== 2) throw new RuntimeException('Sessoes de teste ausentes.');

$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rfv2-deadlock-'.bin2hex(random_bytes(8));
mkdir($dir, 0700);
$workerFile = $dir.DIRECTORY_SEPARATOR.'worker.php';
file_put_contents($workerFile, <<<'PHP'
<?php
declare(strict_types=1);
use App\Infrastructure\Database\PdoConnection;
$root = $argv[1]; $first = (int) $argv[2]; $second = (int) $argv[3]; $mine = $argv[4]; $other = $argv[5];
$root = require $root.'/app/Core/Bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$attempts = 0;
$connection->transaction(function (PDO $pdo) use ($first, $second, $mine, $other, &$attempts): void {
    $attempts++;
    $update = $pdo->prepare('UPDATE video_sessions SET total_paused_ms=total_paused_ms+1 WHERE id=:id');
    $update->execute(['id' => $first]);
    if ($attempts === 1) {
        file_put_contents($mine, 'ready');
        $deadline = microtime(true) + 5;
        while (!file_exists($other) && microtime(true) < $deadline) usleep(10_000);
        if (!file_exists($other)) throw new RuntimeException('Barreira concorrente expirou.');
    }
    $update->execute(['id' => $second]);
});
echo $attempts;
PHP
);

try {
    $pipes = [];
    $first = proc_open([PHP_BINARY, $workerFile, $root, (string) $sessionIds[0], (string) $sessionIds[1], $dir.DIRECTORY_SEPARATOR.'a', $dir.DIRECTORY_SEPARATOR.'b'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $otherPipes = [];
    $second = proc_open([PHP_BINARY, $workerFile, $root, (string) $sessionIds[1], (string) $sessionIds[0], $dir.DIRECTORY_SEPARATOR.'b', $dir.DIRECTORY_SEPARATOR.'a'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $otherPipes);
    if (!is_resource($first) || !is_resource($second)) throw new RuntimeException('Nao foi possivel iniciar os trabalhadores concorrentes.');
    $firstOutput = stream_get_contents($pipes[1]); $firstError = stream_get_contents($pipes[2]);
    $secondOutput = stream_get_contents($otherPipes[1]); $secondError = stream_get_contents($otherPipes[2]);
    $firstStatus = proc_close($first); $secondStatus = proc_close($second);
    if ($firstStatus !== 0 || $secondStatus !== 0) throw new RuntimeException("Deadlock nao foi recuperado: {$firstError}{$secondError}");
    if (max((int) $firstOutput, (int) $secondOutput) < 2) throw new RuntimeException('A tentativa concorrente nao exercitou a repeticao apos deadlock.');

    $deadlockAttempts = 0;
    try {
        $connection->transaction(function () use (&$deadlockAttempts): void {
            $deadlockAttempts++;
            $error = new PDOException('Deadlock simulado.');
            $error->errorInfo = ['40001', 1213, 'Deadlock simulado.'];
            throw $error;
        });
        throw new RuntimeException('Deadlock esgotado foi aceito.');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1213 || $deadlockAttempts !== 3) throw $error;
    }

    $otherAttempts = 0;
    try {
        $connection->transaction(function () use (&$otherAttempts): void {
            $otherAttempts++;
            $error = new PDOException('Erro nao recuperavel.');
            $error->errorInfo = ['HY000', 1205, 'Erro nao recuperavel.'];
            throw $error;
        });
        throw new RuntimeException('Erro nao relacionado a deadlock foi aceito.');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1205 || $otherAttempts !== 1) throw $error;
    }
} finally {
    $delete = $pdo->prepare('DELETE FROM video_sessions WHERE session_group_id=:group');
    $delete->bindValue(':group', $group, PDO::PARAM_LOB);
    $delete->execute();
    foreach (['a', 'b', 'worker.php'] as $name) @unlink($dir.DIRECTORY_SEPARATOR.$name);
    @rmdir($dir);
}

echo "OK - deadlock transacional recuperado sem duplicar sessao\n";
