<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Security\AuthenticatedCipher;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MysqlDatasetImportRepository
{
    public function __construct(
        private readonly PdoConnection $connection,
        private readonly AuthenticatedCipher $cipher,
        private readonly MysqlIdentityRepository $identities
    ) {}

    /** @param list<array<string,mixed>> $manifest @param array<string,int> $configuration
     *  @return array<string,mixed>
     */
    public function start(string $datasetName, string $sourceType, array $manifest, array $configuration): array
    {
        $datasetName = trim(preg_replace('/\s+/u', ' ', $datasetName) ?? $datasetName);
        if (mb_strlen($datasetName) < 2 || mb_strlen($datasetName) > 160 || preg_match('/[\x00-\x1F\x7F]/u', $datasetName) === 1) {
            throw new InvalidArgumentException('Nome do conjunto invalido.');
        }
        if (!in_array($sourceType, ['folder', 'zip'], true) || $manifest === []) {
            throw new InvalidArgumentException('Origem ou manifesto de importacao invalido.');
        }
        $token = $this->connection->transaction(function (PDO $pdo) use ($datasetName, $sourceType, $manifest, $configuration): string {
            $dataset = $pdo->prepare('INSERT INTO datasets (name, description) VALUES (:name, :description)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
            $dataset->execute(['name' => $datasetName, 'description' => 'Importacao local com estrutura LFW; uso condicionado a autorizacao e licenca do conjunto.']);
            $datasetId = (int) $pdo->lastInsertId();
            $existing = $pdo->prepare('SELECT id, public_id, status FROM dataset_imports
                WHERE dataset_id = :dataset_id AND source_type = :source_type AND status <> "cancelled"
                ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $existing->execute(['dataset_id' => $datasetId, 'source_type' => $sourceType]);
            $import = $existing->fetch();
            if (!is_array($import)) {
                $publicId = random_bytes(16);
                $insert = $pdo->prepare('INSERT INTO dataset_imports
                    (public_id, dataset_id, source_type, status, configuration, started_at)
                    VALUES (:public_id, :dataset_id, :source_type, "running", :configuration, UTC_TIMESTAMP(3))');
                $insert->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
                $insert->bindValue(':dataset_id', $datasetId, PDO::PARAM_INT);
                $insert->bindValue(':source_type', $sourceType);
                $insert->bindValue(':configuration', json_encode($configuration, JSON_THROW_ON_ERROR));
                $insert->execute();
                $importId = (int) $pdo->lastInsertId();
            } else {
                $publicId = (string) $import['public_id'];
                $importId = (int) $import['id'];
            }

            foreach ($manifest as $entry) {
                $this->insertManifestEntry($pdo, $importId, $entry);
            }
            $this->refreshCounts($pdo, $importId, true);
            return bin2hex($publicId);
        });
        return $this->status($token);
    }

    /** @return array<string,mixed> */
    public function status(string $token): array
    {
        $publicId = $this->tokenBytes($token);
        $statement = $this->connection->pdo()->prepare('SELECT di.id, LOWER(HEX(di.public_id)) import_token, d.name dataset_name,
            di.source_type, di.status, di.total_samples, di.processed_samples, di.rejected_samples,
            di.configuration, di.started_at, di.finished_at
            FROM dataset_imports di INNER JOIN datasets d ON d.id = di.dataset_id
            WHERE di.public_id = :public_id LIMIT 1');
        $statement->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
        $statement->execute();
        $import = $statement->fetch();
        if (!is_array($import)) {
            throw new DomainException('Importacao nao encontrada.', 404);
        }
        $configuration = json_decode((string) $import['configuration'], true, 32, JSON_THROW_ON_ERROR);
        $linked = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM dataset_import_identities WHERE dataset_import_id = :import_id AND identity_id IS NOT NULL');
        $linked->execute(['import_id' => (int) $import['id']]);
        $current = $this->connection->pdo()->prepare('SELECT dii.label_ciphertext, dii.label_nonce, dii.label_auth_tag
            FROM dataset_samples ds INNER JOIN dataset_import_identities dii ON dii.id = ds.import_identity_id
            WHERE ds.dataset_import_id = :import_id AND ds.status = "pending" ORDER BY ds.id LIMIT 1');
        $current->execute(['import_id' => (int) $import['id']]);
        $currentRow = $current->fetch();
        return [
            'import_token' => (string) $import['import_token'], 'dataset_name' => (string) $import['dataset_name'],
            'source_type' => (string) $import['source_type'], 'status' => (string) $import['status'],
            'total_samples' => (int) $import['total_samples'], 'processed_samples' => (int) $import['processed_samples'],
            'rejected_samples' => (int) $import['rejected_samples'], 'pending_samples' => (int) $import['total_samples'] - (int) $import['processed_samples'] - (int) $import['rejected_samples'],
            'linked_identities' => (int) $linked->fetchColumn(),
            'configuration' => is_array($configuration) ? $configuration : [], 'started_at' => $import['started_at'],
            'finished_at' => $import['finished_at'],
            'current_identity' => is_array($currentRow) ? $this->cipher->decrypt($currentRow['label_ciphertext'], $currentRow['label_nonce'], $currentRow['label_auth_tag'], 'dataset:identity-label') : null,
        ];
    }

    /** @param list<string> $statuses
     *  @return array{items:list<array<string,mixed>>,offset:int,limit:int,total:int,next_offset:?int}
     */
    public function samples(string $token, array $statuses, int $offset = 0, int $limit = 100): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 100 || $statuses === [] || array_diff($statuses, ['pending', 'rejected', 'processed']) !== []) {
            throw new InvalidArgumentException('Paginacao ou estados de amostras invalidos.');
        }
        $publicId = $this->tokenBytes($token);
        $import = $this->connection->pdo()->prepare('SELECT id FROM dataset_imports WHERE public_id = :public_id LIMIT 1');
        $import->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
        $import->execute();
        $importId = $import->fetchColumn();
        if ($importId === false) throw new DomainException('Importacao nao encontrada.', 404);
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $where = 'ds.dataset_import_id = ? AND ds.status IN ('.$placeholders.')';
        $count = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM dataset_samples ds WHERE '.$where);
        $count->execute([(int) $importId, ...$statuses]);
        $total = (int) $count->fetchColumn();
        $statement = $this->connection->pdo()->prepare('SELECT LOWER(HEX(ds.public_id)) sample_token, LOWER(HEX(ds.file_fingerprint)) fingerprint,
            ds.split_name, ds.status, ds.failure_code, dii.label_ciphertext, dii.label_nonce, dii.label_auth_tag
            FROM dataset_samples ds INNER JOIN dataset_import_identities dii ON dii.id = ds.import_identity_id
            WHERE '.$where.' ORDER BY ds.id LIMIT ? OFFSET ?');
        $parameter = 1;
        $statement->bindValue($parameter++, (int) $importId, PDO::PARAM_INT);
        foreach ($statuses as $status) $statement->bindValue($parameter++, $status);
        $statement->bindValue($parameter++, $limit, PDO::PARAM_INT);
        $statement->bindValue($parameter, $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        foreach ($statement as $row) {
            $items[] = [
                'sample_token' => (string) $row['sample_token'], 'fingerprint' => (string) $row['fingerprint'],
                'identity' => $this->cipher->decrypt($row['label_ciphertext'], $row['label_nonce'], $row['label_auth_tag'], 'dataset:identity-label'),
                'split' => (string) $row['split_name'], 'status' => (string) $row['status'], 'failure_code' => $row['failure_code'],
            ];
        }
        $next = $offset + count($items);
        return ['items' => $items, 'offset' => $offset, 'limit' => $limit, 'total' => $total, 'next_offset' => $next < $total ? $next : null];
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $statement = $this->connection->pdo()->prepare('SELECT LOWER(HEX(di.public_id)) import_token, d.name dataset_name,
            di.source_type, di.status, di.total_samples, di.processed_samples, di.rejected_samples, di.started_at, di.finished_at
            FROM dataset_imports di INNER JOIN datasets d ON d.id = di.dataset_id ORDER BY di.id DESC LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn(array $row): array => [
            'import_token' => (string) $row['import_token'], 'dataset_name' => (string) $row['dataset_name'],
            'source_type' => (string) $row['source_type'], 'status' => (string) $row['status'],
            'total_samples' => (int) $row['total_samples'], 'processed_samples' => (int) $row['processed_samples'],
            'rejected_samples' => (int) $row['rejected_samples'], 'started_at' => $row['started_at'], 'finished_at' => $row['finished_at'],
        ], $statement->fetchAll());
    }

    public function pause(string $token): array
    {
        return $this->transition($token, 'running', 'paused');
    }

    public function resume(string $token): array
    {
        return $this->transition($token, 'paused', 'running');
    }

    public function cancel(string $token): array
    {
        $publicId = $this->tokenBytes($token);
        $this->connection->transaction(function (PDO $pdo) use ($publicId): void {
            $statement = $pdo->prepare('UPDATE dataset_imports SET status = "cancelled", finished_at = UTC_TIMESTAMP(3)
                WHERE public_id = :public_id AND status IN ("running", "paused")');
            $statement->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
            $statement->execute();
            if ($statement->rowCount() !== 1) {
                throw new DomainException('Importacao nao pode ser cancelada neste estado.', 409);
            }
            $reject = $pdo->prepare('UPDATE dataset_samples ds INNER JOIN dataset_imports di ON di.id = ds.dataset_import_id
                SET ds.status = "rejected", ds.failure_code = "import_cancelled", ds.processed_at = UTC_TIMESTAMP(3), ds.temporary_name = NULL
                WHERE di.public_id = :public_id AND ds.status = "pending"');
            $reject->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
            $reject->execute();
            $refresh = $pdo->prepare('UPDATE dataset_imports di SET
                total_samples = (SELECT COUNT(*) FROM dataset_samples ds WHERE ds.dataset_import_id = di.id),
                processed_samples = (SELECT COUNT(*) FROM dataset_samples ds WHERE ds.dataset_import_id = di.id AND ds.status = "processed"),
                rejected_samples = (SELECT COUNT(*) FROM dataset_samples ds WHERE ds.dataset_import_id = di.id AND ds.status = "rejected")
                WHERE di.public_id = :public_id');
            $refresh->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
            $refresh->execute();
        });
        return $this->status($token);
    }

    /** @return array{temporary_name:?string,mime_type:?string,status:string,source_type:string} */
    public function sampleLocation(string $token, string $sampleToken): array
    {
        $statement = $this->sampleStatement($token, $sampleToken, false);
        $row = $statement->fetch();
        if (!is_array($row)) throw new DomainException('Amostra nao encontrada.', 404);
        return ['temporary_name' => $row['temporary_name'], 'mime_type' => $row['mime_type'], 'status' => (string) $row['sample_status'], 'source_type' => (string) $row['source_type']];
    }

    /** @param list<float> $faceapi @param list<float> $sface @return array<string,mixed> */
    public function store(string $token, string $sampleToken, array $faceapi, array $sface): array
    {
        $this->connection->transaction(function (PDO $pdo) use ($token, $sampleToken, $faceapi, $sface): void {
            $statement = $this->sampleStatement($token, $sampleToken, true);
            $row = $statement->fetch();
            if (!is_array($row)) throw new DomainException('Amostra nao encontrada.', 404);
            if ($row['sample_status'] === 'processed') return;
            if ($row['import_status'] !== 'running' || $row['sample_status'] !== 'pending') {
                throw new DomainException('Amostra nao esta disponivel para processamento.', 409);
            }
            $label = $this->cipher->decrypt($row['label_ciphertext'], $row['label_nonce'], $row['label_auth_tag'], 'dataset:identity-label');
            $vectors = ['faceapi-recognition' => $faceapi, 'opencv-sface' => $sface];
            $modelIds = $this->modelIds($pdo);
            if ($row['split_name'] === 'gallery') {
                $saved = $this->identities->importPair($label, $vectors);
                $identityId = $saved['identity_id'];
                $link = $pdo->prepare('UPDATE dataset_import_identities SET identity_id = :identity_id WHERE id = :id');
                $link->execute(['identity_id' => $identityId, 'id' => (int) $row['import_identity_id']]);
                $insert = $pdo->prepare('INSERT INTO dataset_sample_embeddings
                    (dataset_sample_id, biometric_model_id, usage_type, gallery_embedding_id, dimensions)
                    VALUES (:sample_id, :model_id, "gallery", :embedding_id, :dimensions)');
                foreach ($vectors as $modelKey => $vector) {
                    $insert->execute(['sample_id' => (int) $row['sample_id'], 'model_id' => $modelIds[$modelKey], 'embedding_id' => $saved['embedding_ids'][$modelKey], 'dimensions' => count($vector)]);
                }
            } else {
                $identityId = $row['identity_id'];
                if ($identityId === null) {
                    $identity = $pdo->prepare('SELECT id FROM identities WHERE name_lookup_hash = :name_hash AND active = 1 LIMIT 1');
                    $identity->bindValue(':name_hash', $this->cipher->lookupHash($label), PDO::PARAM_LOB);
                    $identity->execute();
                    $identityId = $identity->fetchColumn();
                    if ($identityId === false) throw new DomainException('A probe exige uma amostra gallery processada para a identidade.', 409);
                    $pdo->prepare('UPDATE dataset_import_identities SET identity_id = :identity_id WHERE id = :id')->execute(['identity_id' => (int) $identityId, 'id' => (int) $row['import_identity_id']]);
                }
                $insert = $pdo->prepare('INSERT INTO dataset_sample_embeddings
                    (dataset_sample_id, biometric_model_id, usage_type, ciphertext, nonce, auth_tag, key_id, dimensions)
                    VALUES (:sample_id, :model_id, "probe", :ciphertext, :nonce, :auth_tag, :key_id, :dimensions)');
                foreach ($vectors as $modelKey => $vector) {
                    $encrypted = $this->cipher->encryptEmbedding($vector, count($vector));
                    $insert->bindValue(':sample_id', (int) $row['sample_id'], PDO::PARAM_INT);
                    $insert->bindValue(':model_id', $modelIds[$modelKey], PDO::PARAM_INT);
                    $insert->bindValue(':ciphertext', $encrypted['ciphertext'], PDO::PARAM_LOB);
                    $insert->bindValue(':nonce', $encrypted['nonce'], PDO::PARAM_LOB);
                    $insert->bindValue(':auth_tag', $encrypted['auth_tag'], PDO::PARAM_LOB);
                    $insert->bindValue(':key_id', $encrypted['key_id']);
                    $insert->bindValue(':dimensions', count($vector), PDO::PARAM_INT);
                    $insert->execute();
                }
            }
            $pdo->prepare('UPDATE dataset_samples SET status = "processed", failure_code = NULL,
                processed_at = UTC_TIMESTAMP(3), temporary_name = NULL WHERE id = :id')->execute(['id' => (int) $row['sample_id']]);
            $this->refreshCounts($pdo, (int) $row['import_id'], false);
        });
        return $this->status($token);
    }

    public function reject(string $token, string $sampleToken, string $failureCode): array
    {
        $this->connection->transaction(function (PDO $pdo) use ($token, $sampleToken, $failureCode): void {
            $statement = $this->sampleStatement($token, $sampleToken, true);
            $row = $statement->fetch();
            if (!is_array($row)) throw new DomainException('Amostra nao encontrada.', 404);
            if ($row['sample_status'] !== 'pending') return;
            $update = $pdo->prepare('UPDATE dataset_samples SET status = "rejected", failure_code = :failure,
                processed_at = UTC_TIMESTAMP(3), temporary_name = NULL WHERE id = :id');
            $update->execute(['failure' => $failureCode, 'id' => (int) $row['sample_id']]);
            $this->refreshCounts($pdo, (int) $row['import_id'], false);
        });
        return $this->status($token);
    }

    /** @param array<string,mixed> $entry */
    private function insertManifestEntry(PDO $pdo, int $importId, array $entry): void
    {
        $label = $entry['identity'] ?? null;
        $sourceKey = $entry['source_key'] ?? null;
        $fingerprint = $entry['fingerprint'] ?? null;
        $split = $entry['split'] ?? null;
        if (!is_string($label) || mb_strlen($label) < 1 || mb_strlen($label) > 120 || !is_string($sourceKey)
            || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || !in_array($split, ['gallery', 'probe'], true)) {
            throw new InvalidArgumentException('Entrada invalida no manifesto.');
        }
        $fingerprintBytes = hex2bin($fingerprint);
        if (!is_string($fingerprintBytes)) throw new InvalidArgumentException('Fingerprint de arquivo invalido.');
        $duplicate = $pdo->prepare('SELECT ds.id FROM dataset_samples ds
            INNER JOIN dataset_imports previous_import ON previous_import.id = ds.dataset_import_id
            INNER JOIN dataset_imports current_import ON current_import.id = :import_id
            WHERE previous_import.dataset_id = current_import.dataset_id AND ds.file_fingerprint = :fingerprint LIMIT 1');
        $duplicate->bindValue(':import_id', $importId, PDO::PARAM_INT);
        $duplicate->bindValue(':fingerprint', $fingerprintBytes, PDO::PARAM_LOB);
        $duplicate->execute();
        if ($duplicate->fetchColumn() !== false) return;
        $labelHash = $this->cipher->lookupHash($label);
        $identity = $pdo->prepare('SELECT id FROM dataset_import_identities WHERE dataset_import_id = :import_id AND source_label_hash = :label_hash LIMIT 1');
        $identity->bindValue(':import_id', $importId, PDO::PARAM_INT);
        $identity->bindValue(':label_hash', $labelHash, PDO::PARAM_LOB);
        $identity->execute();
        $identityId = $identity->fetchColumn();
        if ($identityId === false) {
            $encrypted = $this->cipher->encrypt($label, 'dataset:identity-label');
            $insertIdentity = $pdo->prepare('INSERT INTO dataset_import_identities
                (dataset_import_id, source_label_hash, label_ciphertext, label_nonce, label_auth_tag, label_key_id)
                VALUES (:import_id, :label_hash, :ciphertext, :nonce, :auth_tag, :key_id)');
            $insertIdentity->bindValue(':import_id', $importId, PDO::PARAM_INT);
            $insertIdentity->bindValue(':label_hash', $labelHash, PDO::PARAM_LOB);
            $insertIdentity->bindValue(':ciphertext', $encrypted['ciphertext'], PDO::PARAM_LOB);
            $insertIdentity->bindValue(':nonce', $encrypted['nonce'], PDO::PARAM_LOB);
            $insertIdentity->bindValue(':auth_tag', $encrypted['auth_tag'], PDO::PARAM_LOB);
            $insertIdentity->bindValue(':key_id', $encrypted['key_id']);
            $insertIdentity->execute();
            $identityId = (int) $pdo->lastInsertId();
        }
        $error = $entry['error'] ?? null;
        $temporaryName = $entry['temporary_name'] ?? null;
        if ($temporaryName !== null && (!is_string($temporaryName) || preg_match('/^[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$/D', $temporaryName) !== 1)) {
            throw new InvalidArgumentException('Nome temporario invalido.');
        }
        $insert = $pdo->prepare('INSERT IGNORE INTO dataset_samples
            (public_id, dataset_import_id, import_identity_id, source_key_hash, file_fingerprint, split_name,
             status, failure_code, mime_type, byte_size, width, height, temporary_name, processed_at)
            VALUES (:public_id, :import_id, :identity_id, :source_hash, :fingerprint, :split,
             :status, :failure, :mime, :bytes, :width, :height, :temporary_name, :processed_at)');
        $insert->bindValue(':public_id', random_bytes(16), PDO::PARAM_LOB);
        $insert->bindValue(':import_id', $importId, PDO::PARAM_INT);
        $insert->bindValue(':identity_id', (int) $identityId, PDO::PARAM_INT);
        $insert->bindValue(':source_hash', $this->cipher->lookupHash($sourceKey), PDO::PARAM_LOB);
        $insert->bindValue(':fingerprint', $fingerprintBytes, PDO::PARAM_LOB);
        $insert->bindValue(':split', $split);
        $insert->bindValue(':status', is_string($error) ? 'rejected' : 'pending');
        $insert->bindValue(':failure', is_string($error) ? $error : null);
        $insert->bindValue(':mime', is_string($entry['mime_type'] ?? null) ? $entry['mime_type'] : null);
        $insert->bindValue(':bytes', is_int($entry['byte_size'] ?? null) ? $entry['byte_size'] : null, PDO::PARAM_INT);
        $insert->bindValue(':width', is_int($entry['width'] ?? null) ? $entry['width'] : null, PDO::PARAM_INT);
        $insert->bindValue(':height', is_int($entry['height'] ?? null) ? $entry['height'] : null, PDO::PARAM_INT);
        $insert->bindValue(':temporary_name', $temporaryName);
        $insert->bindValue(':processed_at', is_string($error) ? gmdate('Y-m-d H:i:s.v') : null);
        $insert->execute();
    }

    private function refreshCounts(PDO $pdo, int $importId, bool $allowRestart): void
    {
        $counts = $pdo->prepare('SELECT COUNT(*) total, SUM(status = "processed") processed, SUM(status = "rejected") rejected,
            SUM(status = "pending") pending FROM dataset_samples WHERE dataset_import_id = :id');
        $counts->execute(['id' => $importId]);
        $row = $counts->fetch();
        if (!is_array($row)) throw new RuntimeException('Falha ao consolidar importacao.');
        $pending = (int) $row['pending'];
        $statusSql = $pending === 0 ? 'status = "completed", finished_at = UTC_TIMESTAMP(3)'
            : ($allowRestart ? 'status = IF(status = "completed", "running", status), finished_at = IF(status = "completed", NULL, finished_at)' : 'status = status');
        $update = $pdo->prepare('UPDATE dataset_imports SET total_samples = :total, processed_samples = :processed,
            rejected_samples = :rejected, '.$statusSql.' WHERE id = :id');
        $update->execute(['total' => (int) $row['total'], 'processed' => (int) $row['processed'], 'rejected' => (int) $row['rejected'], 'id' => $importId]);
    }

    private function transition(string $token, string $from, string $to): array
    {
        $publicId = $this->tokenBytes($token);
        $statement = $this->connection->pdo()->prepare('UPDATE dataset_imports SET status = :to_status
            WHERE public_id = :public_id AND status = :from_status');
        $statement->bindValue(':to_status', $to);
        $statement->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
        $statement->bindValue(':from_status', $from);
        $statement->execute();
        if ($statement->rowCount() !== 1) throw new DomainException('Transicao de importacao invalida.', 409);
        return $this->status($token);
    }

    private function sampleStatement(string $token, string $sampleToken, bool $lock): \PDOStatement
    {
        $statement = $this->connection->pdo()->prepare('SELECT di.id import_id, di.status import_status, di.source_type,
            ds.id sample_id, ds.status sample_status, ds.split_name, ds.temporary_name, ds.mime_type,
            dii.id import_identity_id, dii.identity_id, dii.label_ciphertext, dii.label_nonce, dii.label_auth_tag
            FROM dataset_imports di INNER JOIN dataset_samples ds ON ds.dataset_import_id = di.id
            INNER JOIN dataset_import_identities dii ON dii.id = ds.import_identity_id
            WHERE di.public_id = :import_public_id AND ds.public_id = :sample_public_id LIMIT 1'.($lock ? ' FOR UPDATE' : ''));
        $statement->bindValue(':import_public_id', $this->tokenBytes($token), PDO::PARAM_LOB);
        $statement->bindValue(':sample_public_id', $this->tokenBytes($sampleToken), PDO::PARAM_LOB);
        $statement->execute();
        return $statement;
    }

    /** @return array<string,int> */
    private function modelIds(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, model_key FROM biometric_models WHERE model_key IN ("faceapi-recognition", "opencv-sface")')->fetchAll();
        $ids = [];
        foreach ($rows as $row) $ids[(string) $row['model_key']] = (int) $row['id'];
        if (count($ids) !== 2) throw new RuntimeException('Modelos biometricos da importacao ausentes.');
        return $ids;
    }

    private function tokenBytes(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) throw new InvalidArgumentException('Token de importacao invalido.');
        $bytes = hex2bin($token);
        if (!is_string($bytes)) throw new InvalidArgumentException('Token de importacao invalido.');
        return $bytes;
    }
}
