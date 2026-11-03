<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Security\AuthenticatedCipher;

$root = require __DIR__.'/bootstrap.php';
$security = require $root.'/config/security.php';
$cipher = new AuthenticatedCipher($security['data_key'], $security['data_key_id']);
$connection = new PdoConnection(require $root.'/config/database.php');
$name = 'Pessoa Persistencia Segura';
$vector = array_fill(0, 128, 0.125);
$encryptedName = $cipher->encrypt($name, 'identity:name');
$encryptedVector = $cipher->encryptEmbedding($vector, 128);

$rolledBack = false;
try {
    $connection->transaction(static function (PDO $pdo) use ($cipher, $name, $encryptedName, $encryptedVector): void {
        $identity = $pdo->prepare('INSERT INTO identities
            (public_id, name_ciphertext, name_nonce, name_auth_tag, name_key_id, name_lookup_hash, consented_at)
            VALUES (:public_id, :ciphertext, :nonce, :auth_tag, :key_id, :lookup_hash, UTC_TIMESTAMP())');
        $identity->execute([
            'public_id' => random_bytes(16),
            'ciphertext' => $encryptedName['ciphertext'],
            'nonce' => $encryptedName['nonce'],
            'auth_tag' => $encryptedName['auth_tag'],
            'key_id' => $encryptedName['key_id'],
            'lookup_hash' => $cipher->lookupHash($name),
        ]);
        $identityId = (int) $pdo->lastInsertId();
        $modelId = (int) $pdo->query("SELECT id FROM biometric_models WHERE model_key = 'faceapi-recognition'")->fetchColumn();
        $embedding = $pdo->prepare('INSERT INTO embeddings
            (identity_id, biometric_model_id, ciphertext, nonce, auth_tag, key_id, dimensions)
            VALUES (:identity_id, :model_id, :ciphertext, :nonce, :auth_tag, :key_id, 128)');
        $embedding->execute([
            'identity_id' => $identityId,
            'model_id' => $modelId,
            'ciphertext' => $encryptedVector['ciphertext'],
            'nonce' => $encryptedVector['nonce'],
            'auth_tag' => $encryptedVector['auth_tag'],
            'key_id' => $encryptedVector['key_id'],
        ]);

        $stored = $pdo->query("SELECT i.name_ciphertext, i.name_nonce, i.name_auth_tag, e.ciphertext AS embedding_ciphertext
            FROM identities i INNER JOIN embeddings e ON e.identity_id = i.id WHERE i.id = {$identityId}")->fetch();
        if (!is_array($stored) || str_contains($stored['name_ciphertext'], $name) || str_contains($stored['embedding_ciphertext'], '0.125')) {
            throw new RuntimeException('Dado sensivel foi persistido em texto puro.');
        }
        if ($cipher->decrypt($stored['name_ciphertext'], $stored['name_nonce'], $stored['name_auth_tag'], 'identity:name') !== $name) {
            throw new RuntimeException('Nome criptografado nao foi recuperado.');
        }
        throw new DomainException('rollback-test');
    });
} catch (DomainException $error) {
    if ($error->getMessage() !== 'rollback-test') {
        throw $error;
    }
    $rolledBack = true;
}

if (!$rolledBack) {
    throw new RuntimeException('Teste de persistencia nao executou rollback.');
}
echo "OK - nome e embedding criptografados no MySQL e rollback confirmado\n";
