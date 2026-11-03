<?php
declare(strict_types=1);

use App\Infrastructure\Security\AuthenticatedCipher;

$root = require __DIR__.'/bootstrap.php';
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$key = base64_encode(str_repeat('k', 32));
$cipher = new AuthenticatedCipher($key, 'test-v1');
$encryptedName = $cipher->encrypt('Pessoa de Teste', 'identity:name');
$check($encryptedName['ciphertext'] !== 'Pessoa de Teste', 'Nome pessoal permaneceu em texto puro.');
$check($cipher->decrypt($encryptedName['ciphertext'], $encryptedName['nonce'], $encryptedName['auth_tag'], 'identity:name') === 'Pessoa de Teste', 'Nome pessoal nao foi recuperado.');

$vector = array_fill(0, 128, 0.125);
$encryptedVector = $cipher->encryptEmbedding($vector, 128);
$check(!str_contains($encryptedVector['ciphertext'], '0.125'), 'Embedding permaneceu em texto puro.');
$check(strlen($encryptedVector['nonce']) === 12 && strlen($encryptedVector['auth_tag']) === 16, 'AES-GCM sem nonce ou tag validos.');
$check(hash_equals($cipher->lookupHash('  Maria  Silva '), $cipher->lookupHash('maria silva')), 'Normalizacao do indice protegido falhou.');

$schema = (string) file_get_contents($root.'/database/migrations/001_initial_schema.sql');
foreach (['architectures', 'identities', 'biometric_models', 'embeddings', 'experiments', 'video_sessions', 'recognition_events', 'execution_metrics', 'name_ciphertext', 'auth_tag', 'FOREIGN KEY', 'ix_embeddings_model_active'] as $required) {
    $check(str_contains($schema, $required), "Esquema sem {$required}.");
}
$check(substr_count($schema, "('A',") >= 1 && substr_count($schema, "('B',") >= 1, 'Arquiteturas A e B nao foram registradas.');
$check(!str_contains($schema, "('C',"), 'Arquitetura C nao pertence a V2.');

$importSchema = (string) file_get_contents($root.'/database/migrations/003_dataset_import.sql');
foreach (['dataset_imports', 'dataset_import_identities', 'dataset_samples', 'dataset_sample_embeddings', 'enrollment_basis', 'usage_type'] as $required) {
    $check(str_contains($importSchema, $required), "Esquema de importacao sem {$required}.");
}

$evaluationSchema = (string) file_get_contents($root.'/database/migrations/004_probe_evaluations.sql');
foreach (['probe_evaluations', 'probe_evaluation_results', 'expected_identity_id', 'predicted_identity_id', 'technical_failure'] as $required) {
    $check(str_contains($evaluationSchema, $required), "Esquema de avaliacao sem {$required}.");
}
$webcamSchema = (string) file_get_contents($root.'/database/migrations/005_webcam_supervised_evaluation.sql');
foreach (['webcam_evaluation_segments', 'webcam_evaluation_results', 'session_group_id', 'technical_failure'] as $required) {
    $check(str_contains($webcamSchema, $required), "Esquema de avaliacao webcam sem {$required}.");
}

$check(!is_file($root.'/docker-compose.yml'), 'Docker Compose nao deve fazer parte da configuracao da V2.');
$check(!str_contains($schema, 'utf8mb4_0900_ai_ci'), 'Schema mantem collation exclusiva do MySQL 8.');
$check(str_contains($schema, 'utf8mb4_unicode_ci'), 'Schema nao declara collation compativel com MariaDB.');

echo "OK - estrutura, esquema e criptografia autenticada\n";
