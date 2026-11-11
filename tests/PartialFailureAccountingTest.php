<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\{MysqlDatasetImportRepository, MysqlIdentityRepository, MysqlProbeEvaluationRepository, MysqlVideoSessionRepository, MysqlMetricsDashboardRepository};
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\{ComparisonService, ProbeEvaluationService, StatisticsCalculator, DatasetImportService, PythonFaceClient};

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$security = require $root.'/config/security.php';
$cipher = new AuthenticatedCipher($security['data_key'], $security['data_key_id']);
$identities = new MysqlIdentityRepository($connection, $cipher);
$imports = new MysqlDatasetImportRepository($connection, $cipher, $identities);
$models = require $root.'/config/biometrics.php';
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
// Reuse authorized embeddings; never generate biometric fixtures.
$person = null;
foreach ($identities->list() as $candidate) if ($candidate['faceapi_samples'] && $candidate['sface_samples']) { $person = $candidate; break; }
$check($person !== null, 'Requer uma identidade autorizada com A e B.');
$vectors = [];
foreach (['faceapi-recognition', 'opencv-sface'] as $model) foreach ($identities->candidates($model) as $candidate) {
    if ($candidate['identity_id'] === $person['id']) { $vectors[$model] = $candidate['embedding']; break; }
}
$before = [];
foreach (['dataset_imports', 'dataset_samples', 'probe_evaluations', 'video_sessions', 'recognition_events', 'execution_metrics', 'embeddings'] as $table) $before[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $source = 'partial-accounting-'.$suffix;
    $manifest = [];
    foreach (range(1, 4) as $n) $manifest[] = ['identity'=>$person['name'], 'source_namespace'=>$source, 'source_identity_key'=>'person', 'source_key'=>"{$suffix}/{$n}.jpg", 'fingerprint'=>hash('sha256',"{$suffix}/{$n}"), 'split'=>'probe', 'mime_type'=>'image/jpeg','byte_size'=>1000];
    $job = $imports->start('Regression '.$suffix, 'folder', $manifest, []);
    $link = $pdo->prepare('INSERT INTO dataset_identity_source_links (source_namespace_hash, source_identity_key_hash, identity_id, status)
        VALUES (:namespace_hash, :identity_hash, :identity_id, "resolved")');
    $link->bindValue(':namespace_hash', $cipher->lookupHash('source:'.$source), PDO::PARAM_LOB);
    $link->bindValue(':identity_hash', $cipher->lookupHash('identity:person'), PDO::PARAM_LOB);
    $link->bindValue(':identity_id', $person['id'], PDO::PARAM_INT);
    $link->execute();
    $token = $job['import_token'];
    $samples = $imports->samples($token, ['pending'], 0, 10)['items'];
    $imports->store($token, $samples[0]['sample_token'], null, $vectors['opencv-sface'], ['A'=>'no_face']);
    $imports->store($token, $samples[1]['sample_token'], $vectors['faceapi-recognition'], null, ['B'=>'http_failure']);
    $imports->store($token, $samples[2]['sample_token'], null, null, ['A'=>'internal_failure','B'=>'biometric_failure']);
    $config = require $root.'/config/import.php';
    $service = new DatasetImportService($imports, new PythonFaceClient(['base_url'=>'http://127.0.0.1:1','token'=>'test','timeout_seconds'=>1]), $models, $config);
    $image = imagecreatetruecolor(100, 100); ob_start(); imagejpeg($image); $jpeg = ob_get_clean(); imagedestroy($image);
    $service->process($token, $samples[3]['sample_token'], $vectors['faceapi-recognition'], 'data:image/jpeg;base64,'.base64_encode($jpeg));
    $imports->store($token, $samples[1]['sample_token'], null, $vectors['opencv-sface']);
    $imports->store($token, $samples[2]['sample_token'], $vectors['faceapi-recognition'], null, ['B'=>'biometric_failure']);
    $imports->store($token, $samples[3]['sample_token'], null, $vectors['opencv-sface']);
    $job = $imports->store($token, $samples[0]['sample_token'], null, $vectors['opencv-sface'], ['A'=>'no_face']);
    $check($job['processed_samples'] === 4 && $job['rejected_samples'] === 0, 'Resultados parciais ou retry descartados.');
    $galleryManifest = [['identity'=>$person['name'], 'source_namespace'=>$source, 'source_identity_key'=>'gallery-person', 'source_key'=>"{$suffix}/gallery.jpg", 'fingerprint'=>hash('sha256',"{$suffix}/gallery"), 'split'=>'gallery', 'mime_type'=>'image/jpeg','byte_size'=>1000]];
    $gallery = $imports->start('Partial gallery '.$suffix, 'folder', $galleryManifest, []);
    $sample = $imports->samples($gallery['import_token'], ['pending'], 0, 10)['items'][0];
    $embeddingCount = (int)$pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn();
    $imports->store($gallery['import_token'], $sample['sample_token'], null, $vectors['opencv-sface'], ['A'=>'no_face']);
    $imports->store($gallery['import_token'], $sample['sample_token'], null, $vectors['opencv-sface'], ['A'=>'no_face']);
    $check((int)$pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn() === $embeddingCount + 1, 'Gallery parcial perdida ou duplicada.');
    $evaluations = new MysqlProbeEvaluationRepository($connection, $cipher);
    $available = array_column($evaluations->availableImports(), 'eligible_samples', 'import_token');
    $check(($available[$token] ?? 0) === 4, 'Probes parciais excluidas da selecao.');
    $evaluation = new ProbeEvaluationService($evaluations, new ComparisonService($identities, $models), new StatisticsCalculator(), $models);
    $summary = $evaluation->run($token, 4);
    $check($summary['paired_completed'] === 2, 'Apenas probes sem falha tecnica nas duas arquiteturas podem formar pares.');
    $check($summary['architectures']['A']['completed'] === 3 && $summary['architectures']['B']['completed'] === 3, 'Retomada parcial perdeu um resultado valido.');
    foreach ($summary['results'] as $result) $check((int)$result['expected_identity_id'] === $person['id'], 'Identidade esperada alterada.');
    $check($summary['architectures']['A']['technical_failure'] === 1 && $summary['architectures']['B']['technical_failure'] === 1, 'Falhas definitivas classificadas como erro de reconhecimento.');
    $codes = array_column($summary['results'], 'failure_code');
    foreach (['no_face', 'biometric_failure'] as $code) $check(in_array($code,$codes,true), 'Categoria de falha definitiva perdida: '.$code);

    $sessions = new MysqlVideoSessionRepository($connection);
    $session = $sessions->start(1000, ['A'=>[], 'B'=>[]], $person['id']);
    $dashboard = new MysqlMetricsDashboardRepository($connection);
    $unknown = $sessions->recordHttpFailure($session, 'A', 1);
    $check(!$unknown['server_result_known'], 'Falha sem evento marcada como concluida.');
    $sessions->recordHttpFailure($session, 'A', 1);
    $counts = $dashboard->resultCounts($session);
    $check($counts['A']['executions'] === 0 && $counts['A']['http_failures'] === 1 && $counts['A']['unresolved_http'] === 1, 'Retry HTTP duplicado ou contado como execucao.');
    $result = ['status'=>'recognized','id'=>$person['id'],'metrics'=>['pipeline_total_ms'=>1]];
    $sessions->recordArchitectureResult($session, 'A', 1, 0, $result, [], ['A'=>.36,'B'=>1.3225]);
    $known = $sessions->recordHttpFailure($session, 'A', 1);
    $check($known['server_result_known'], 'Resposta perdida apos commit nao conciliada.');
    $counts = $dashboard->resultCounts($session);
    $check($counts['A']['executions'] === 1 && $counts['A']['failures'] === 0 && $counts['A']['http_failures'] === 1 && $counts['A']['unresolved_http'] === 0, 'Transporte sobrescreveu reconhecimento.');
    $check($dashboard->latencyValues($session)['A'] === [], 'Latencia inventada para resposta HTTP perdida.');
    $sessions->end($session);
    $sessions->recordHttpFailure($session, 'B', 1);
    $sessions->recordHttpFailure($session, 'B', 1);
    $counts = $dashboard->resultCounts($session);
    $check($counts['B']['http_failures'] === 1 && $counts['B']['executions'] === 0, 'Recuperacao apos encerrar duplicou eventos.');
} finally { $pdo->rollBack(); }
foreach ($before as $table=>$count) $check((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === $count, 'Limpeza falhou: '.$table);
echo "OK - probes parciais, identidade, classificacao, HTTP antes/depois do commit, retries e rollback\n";
