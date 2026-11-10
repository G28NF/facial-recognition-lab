<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Persistence\MysqlVideoSessionRepository;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\ComparisonService;
use App\Services\ContinuousRecognitionService;
use App\Services\PythonFaceClient;
use App\Services\RecognitionService;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$models = require $root.'/config/biometrics.php';
$security = require $root.'/config/security.php';
$python = new PythonFaceClient(require $root.'/config/python.php');
$identities = new MysqlIdentityRepository($connection, new AuthenticatedCipher($security['data_key'], $security['data_key_id']));
$sessions = new MysqlVideoSessionRepository($connection);
$service = new ContinuousRecognitionService($sessions, new RecognitionService(new ComparisonService($identities, $models), $python), $python, $models);
$token = $sessions->start(1000, ['A' => 1.0, 'B' => 1.0]);
$group = hex2bin($token);
if (!is_string($group)) throw new RuntimeException('Token invalido.');

try {
    $noFace = $service->processArchitectureA($token, 1, 0, null, 'no_face', ['faceapi_failure_ms' => 1.0], []);
    $multipleFaces = $service->processArchitectureA($token, 2, 0, null, 'multiple_faces', ['faceapi_failure_ms' => 1.0], []);
    $technical = $service->processArchitectureA($token, 3, 0, null, 'technical_failure', ['faceapi_failure_ms' => 1.0], []);
    if ($noFace['error'] !== 'no_face' || $multipleFaces['error'] !== 'multiple_faces' || $technical['error'] !== 'technical_failure') {
        throw new RuntimeException('Classificacao de falha Face API.js incorreta.');
    }
    $b = $service->processArchitectureB($token, 1, 0, 'imagem-invalida', []);
    if ($b['architecture'] !== 'B' || $b['status'] !== 'error') throw new RuntimeException('Falha de A impediu o processamento independente de B.');
    $events = $pdo->prepare('SELECT re.architecture_id, re.frame_sequence, JSON_UNQUOTE(JSON_EXTRACT(re.metadata, "$.failure")) failure FROM recognition_events re INNER JOIN video_sessions vs ON vs.id=re.video_session_id WHERE vs.session_group_id=:group ORDER BY re.architecture_id, re.frame_sequence');
    $events->bindValue(':group', $group, PDO::PARAM_LOB); $events->execute(); $stored = $events->fetchAll(PDO::FETCH_ASSOC);
    if (count($stored) !== 4 || $stored[0]['failure'] !== 'no_face' || $stored[1]['failure'] !== 'multiple_faces' || $stored[2]['failure'] !== 'technical_failure' || $stored[3]['architecture_id'] !== 'B') {
        throw new RuntimeException('Falhas independentes nao foram persistidas corretamente.');
    }
    $sessions->end($token);
} finally {
    $delete = $pdo->prepare('DELETE FROM video_sessions WHERE session_group_id=:group');
    $delete->bindValue(':group', $group, PDO::PARAM_LOB); $delete->execute();
}

echo "OK - falhas Face API.js classificadas e arquitetura B independente\n";
