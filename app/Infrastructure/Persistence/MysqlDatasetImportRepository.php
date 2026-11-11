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
    private const TEMPORARY_FAILURES = ['http_failure', 'internal_failure'];
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
                $this->insertManifestEntry($pdo, $importId, $datasetId, $entry);
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
        $excluded = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM dataset_samples WHERE dataset_import_id = :import_id AND integrity_status <> "clear"');
        $excluded->execute(['import_id' => (int) $import['id']]);
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
            'integrity_excluded_samples' => (int) $excluded->fetchColumn(),
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
            ds.split_name, ds.status, ds.failure_code, ds.integrity_status, ds.integrity_reason,
            dii.label_ciphertext, dii.label_nonce, dii.label_auth_tag,
            EXISTS(SELECT 1 FROM dataset_sample_embeddings dse INNER JOIN biometric_models bm ON bm.id=dse.biometric_model_id
                WHERE dse.dataset_sample_id=ds.id AND bm.model_key="faceapi-recognition") has_faceapi,
            EXISTS(SELECT 1 FROM dataset_sample_embeddings dse INNER JOIN biometric_models bm ON bm.id=dse.biometric_model_id
                WHERE dse.dataset_sample_id=ds.id AND bm.model_key="opencv-sface") has_sface
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
                'integrity_status' => (string) $row['integrity_status'], 'integrity_reason' => $row['integrity_reason'],
                'completed_architectures' => array_keys(array_filter(['A' => (bool) $row['has_faceapi'], 'B' => (bool) $row['has_sface']])),
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

    /** @return array{temporary_name:?string,mime_type:?string,status:string,source_type:string,completed_architectures:list<string>} */
    public function sampleLocation(string $token, string $sampleToken): array
    {
        $statement = $this->sampleStatement($token, $sampleToken, false);
        $row = $statement->fetch();
        if (!is_array($row)) throw new DomainException('Amostra nao encontrada.', 404);
        return ['temporary_name' => $row['temporary_name'], 'mime_type' => $row['mime_type'], 'status' => (string) $row['sample_status'], 'source_type' => (string) $row['source_type'],
            'completed_architectures' => $this->completedArchitectures($this->connection->pdo(), (int) $row['sample_id'])];
    }

    /** @param ?list<float> $faceapi @param ?list<float> $sface @param array<string,string> $failures @return array<string,mixed> */
    public function store(string $token, string $sampleToken, ?array $faceapi, ?array $sface, array $failures = []): array
    {
        $this->connection->transaction(function (PDO $pdo) use ($token, $sampleToken, $faceapi, $sface, $failures): void {
            $statement = $this->sampleStatement($token, $sampleToken, true);
            $row = $statement->fetch();
            if (!is_array($row)) throw new DomainException('Amostra nao encontrada.', 404);
            if ($row['sample_status'] === 'processed') return;
            if ($row['import_status'] !== 'running' || $row['sample_status'] !== 'pending') {
                throw new DomainException('Amostra nao esta disponivel para processamento.', 409);
            }
            if ($row['sample_integrity_status'] !== 'clear') {
                $pdo->prepare('UPDATE dataset_samples SET status = "processed", processed_at = UTC_TIMESTAMP(3), temporary_name = NULL WHERE id = :id')
                    ->execute(['id' => (int) $row['sample_id']]);
                $this->refreshCounts($pdo, (int) $row['import_id'], false);
                return;
            }
            $label = $this->cipher->decrypt($row['label_ciphertext'], $row['label_nonce'], $row['label_auth_tag'], 'dataset:identity-label');
            $modelIds = $this->modelIds($pdo);
            $completed = $this->completedArchitectures($pdo, (int) $row['sample_id']);
            $inputs = ['A' => ['model' => 'faceapi-recognition', 'vector' => $faceapi], 'B' => ['model' => 'opencv-sface', 'vector' => $sface]];
            $safeFailures = $this->failureMap($row['failure_code'] ?? null);
            $vectors = [];
            foreach ($inputs as $architecture => $input) {
                if (in_array($architecture, $completed, true)) { unset($safeFailures[$architecture]); continue; }
                if ($input['vector'] !== null) { $vectors[$input['model']] = $input['vector']; unset($safeFailures[$architecture]); continue; }
                $safeFailures[$architecture] = in_array($failures[$architecture] ?? null, ['no_face', 'multiple_faces', 'biometric_failure', 'http_failure', 'internal_failure'], true) ? $failures[$architecture] : 'internal_failure';
            }
            $prospective = $completed;
            foreach (array_keys($vectors) as $modelKey) $prospective[] = $modelKey === 'faceapi-recognition' ? 'A' : 'B';
            $prospective = array_values(array_unique($prospective));
            if ($row['split_name'] === 'gallery' && $prospective === []) {
                $retry = array_filter(['A', 'B'], fn(string $architecture): bool => in_array($safeFailures[$architecture] ?? null, self::TEMPORARY_FAILURES, true));
                $status = $retry === [] ? 'rejected' : 'pending';
                $pdo->prepare('UPDATE dataset_samples SET status = :status, failure_code = :failures,
                    processed_at = :processed_at, temporary_name = :temporary_name WHERE id = :id')
                    ->execute(['status' => $status, 'failures' => json_encode($safeFailures, JSON_THROW_ON_ERROR),
                        'processed_at' => $status === 'pending' ? null : gmdate('Y-m-d H:i:s.v'),
                        'temporary_name' => $status === 'pending' ? $row['temporary_name'] : null, 'id' => (int) $row['sample_id']]);
                $this->refreshCounts($pdo, (int) $row['import_id'], false);
                return;
            }
            if ($row['split_name'] === 'gallery') {
                $source = $this->sourceLink($pdo, $row);
                if ($source['status'] === 'ambiguous') {
                    $this->excludeForIntegrity($pdo, $row, 'ambiguous_source_identity');
                    return;
                }
                try {
                    if ($source['identity_id'] !== null) {
                        $saved = ['identity_id' => $source['identity_id'], 'embedding_ids' => $this->identities->appendToIdentity($source['identity_id'], $vectors)];
                    } else {
                        $saved = $this->identities->importPair($label, $vectors, false, (bool) $row['source_identity_is_stable']);
                        $pdo->prepare('UPDATE dataset_identity_source_links SET identity_id = :identity_id, status = "resolved"
                            WHERE source_namespace_hash = :namespace_hash AND source_identity_key_hash = :identity_hash')
                            ->execute(['identity_id' => $saved['identity_id'], 'namespace_hash' => $row['source_namespace_hash'], 'identity_hash' => $row['source_identity_key_hash']]);
                    }
                } catch (DomainException $error) {
                    if ($error->getCode() !== 409) throw $error;
                    $pdo->prepare('UPDATE dataset_identity_source_links SET status = "ambiguous"
                        WHERE source_namespace_hash = :namespace_hash AND source_identity_key_hash = :identity_hash')
                        ->execute(['namespace_hash' => $row['source_namespace_hash'], 'identity_hash' => $row['source_identity_key_hash']]);
                    $this->excludeForIntegrity($pdo, $row, 'ambiguous_source_identity');
                    return;
                }
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
                    $source = $this->sourceLink($pdo, $row);
                    if ($source['identity_id'] === null || $source['status'] !== 'resolved') {
                        $this->excludeForIntegrity($pdo, $row, 'expected_identity_unresolved');
                        return;
                    }
                    $identityId = $source['identity_id'];
                    $pdo->prepare('UPDATE dataset_import_identities SET identity_id = :identity_id WHERE id = :id')->execute(['identity_id' => $identityId, 'id' => (int) $row['import_identity_id']]);
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
            foreach (array_keys($vectors) as $modelKey) $completed[] = $modelKey === 'faceapi-recognition' ? 'A' : 'B';
            $completed = array_values(array_unique($completed));
            $retry = array_filter(['A', 'B'], fn(string $architecture): bool => !in_array($architecture, $completed, true) && in_array($safeFailures[$architecture] ?? null, self::TEMPORARY_FAILURES, true));
            $reject = $row['split_name'] === 'gallery' && $completed === [] && $retry === [];
            $status = $reject ? 'rejected' : ($retry === [] ? 'processed' : 'pending');
            $pdo->prepare('UPDATE dataset_samples SET status = :status, failure_code = :failures,
                processed_at = :processed_at, temporary_name = :temporary_name WHERE id = :id')
                ->execute(['status' => $status, 'failures' => $safeFailures === [] ? null : json_encode($safeFailures, JSON_THROW_ON_ERROR),
                    'processed_at' => $status === 'pending' ? null : gmdate('Y-m-d H:i:s.v'),
                    'temporary_name' => $status === 'pending' ? $row['temporary_name'] : null, 'id' => (int) $row['sample_id']]);
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
    private function insertManifestEntry(PDO $pdo, int $importId, int $datasetId, array $entry): void
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
        $sourceNamespace = $entry['source_namespace'] ?? null;
        $sourceIdentityKey = $entry['source_identity_key'] ?? null;
        if (($sourceNamespace === null) !== ($sourceIdentityKey === null)
            || ($sourceNamespace !== null && (!is_string($sourceNamespace) || !is_string($sourceIdentityKey)
                || $sourceNamespace === '' || $sourceIdentityKey === '' || mb_strlen($sourceNamespace) > 200 || mb_strlen($sourceIdentityKey) > 200))) {
            throw new InvalidArgumentException('Identificador estavel da fonte invalido.');
        }
        $stableIdentity = $sourceNamespace !== null;
        $sourceNamespaceHash = $this->cipher->lookupHash($stableIdentity ? 'source:'.$sourceNamespace : 'dataset:'.$datasetId);
        $sourceIdentityHash = $this->cipher->lookupHash($stableIdentity ? 'identity:'.$sourceIdentityKey : 'label:'.$label);
        $duplicate = $pdo->prepare('SELECT ds.id FROM dataset_samples ds
            INNER JOIN dataset_imports previous_import ON previous_import.id = ds.dataset_import_id
            INNER JOIN dataset_imports current_import ON current_import.id = :import_id
            WHERE previous_import.dataset_id = current_import.dataset_id AND ds.file_fingerprint = :fingerprint LIMIT 1');
        $duplicate->bindValue(':import_id', $importId, PDO::PARAM_INT);
        $duplicate->bindValue(':fingerprint', $fingerprintBytes, PDO::PARAM_LOB);
        $duplicate->execute();
        if ($duplicate->fetchColumn() !== false) return;
        $overlap = $pdo->prepare('SELECT ds.split_name FROM dataset_samples ds
            INNER JOIN dataset_imports previous_import ON previous_import.id = ds.dataset_import_id
            WHERE previous_import.dataset_id <> :dataset_id AND ds.file_fingerprint = :fingerprint LIMIT 1');
        $overlap->bindValue(':dataset_id', $datasetId, PDO::PARAM_INT);
        $overlap->bindValue(':fingerprint', $fingerprintBytes, PDO::PARAM_LOB);
        $overlap->execute();
        $overlapSplit = $overlap->fetchColumn();
        $labelHash = $this->cipher->lookupHash($label);
        $identity = $pdo->prepare('SELECT id FROM dataset_import_identities WHERE dataset_import_id = :import_id
            AND source_namespace_hash = :namespace_hash AND source_identity_key_hash = :identity_hash LIMIT 1');
        $identity->bindValue(':import_id', $importId, PDO::PARAM_INT);
        $identity->bindValue(':namespace_hash', $sourceNamespaceHash, PDO::PARAM_LOB);
        $identity->bindValue(':identity_hash', $sourceIdentityHash, PDO::PARAM_LOB);
        $identity->execute();
        $identityId = $identity->fetchColumn();
        if ($identityId === false) {
            $encrypted = $this->cipher->encrypt($label, 'dataset:identity-label');
            $insertIdentity = $pdo->prepare('INSERT INTO dataset_import_identities
                (dataset_import_id, source_label_hash, source_namespace_hash, source_identity_key_hash, source_identity_is_stable,
                 label_ciphertext, label_nonce, label_auth_tag, label_key_id)
                VALUES (:import_id, :label_hash, :namespace_hash, :identity_hash, :stable, :ciphertext, :nonce, :auth_tag, :key_id)');
            $insertIdentity->bindValue(':import_id', $importId, PDO::PARAM_INT);
            $insertIdentity->bindValue(':label_hash', $labelHash, PDO::PARAM_LOB);
            $insertIdentity->bindValue(':namespace_hash', $sourceNamespaceHash, PDO::PARAM_LOB);
            $insertIdentity->bindValue(':identity_hash', $sourceIdentityHash, PDO::PARAM_LOB);
            $insertIdentity->bindValue(':stable', $stableIdentity ? 1 : 0, PDO::PARAM_INT);
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
             status, failure_code, integrity_status, integrity_reason, mime_type, byte_size, width, height, temporary_name, processed_at)
            VALUES (:public_id, :import_id, :identity_id, :source_hash, :fingerprint, :split,
             :status, :failure, :integrity_status, :integrity_reason, :mime, :bytes, :width, :height, :temporary_name, :processed_at)');
        $insert->bindValue(':public_id', random_bytes(16), PDO::PARAM_LOB);
        $insert->bindValue(':import_id', $importId, PDO::PARAM_INT);
        $insert->bindValue(':identity_id', (int) $identityId, PDO::PARAM_INT);
        $insert->bindValue(':source_hash', $this->cipher->lookupHash($sourceKey), PDO::PARAM_LOB);
        $insert->bindValue(':fingerprint', $fingerprintBytes, PDO::PARAM_LOB);
        $insert->bindValue(':split', $split);
        $insert->bindValue(':status', is_string($error) ? 'rejected' : 'pending');
        $insert->bindValue(':failure', is_string($error) ? $error : null);
        $insert->bindValue(':integrity_status', $overlapSplit === false ? 'clear' : 'excluded');
        $insert->bindValue(':integrity_reason', $overlapSplit === false ? null : 'cross_dataset_image_overlap');
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
            ds.id sample_id, ds.status sample_status, ds.split_name, ds.temporary_name, ds.mime_type, ds.failure_code,
            ds.integrity_status sample_integrity_status,
            dii.id import_identity_id, dii.identity_id, dii.source_namespace_hash, dii.source_identity_key_hash,
            dii.source_identity_is_stable, dii.label_ciphertext, dii.label_nonce, dii.label_auth_tag
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

    /** @return list<string> */
    private function completedArchitectures(PDO $pdo, int $sampleId): array
    {
        $statement = $pdo->prepare('SELECT bm.model_key FROM dataset_sample_embeddings dse INNER JOIN biometric_models bm ON bm.id=dse.biometric_model_id WHERE dse.dataset_sample_id=:sample_id');
        $statement->execute(['sample_id' => $sampleId]);
        $architectures = [];
        foreach ($statement as $row) $architectures[] = $row['model_key'] === 'faceapi-recognition' ? 'A' : 'B';
        return $architectures;
    }

    /** @return array<string,string> */
    private function failureMap(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? array_filter($decoded, static fn(mixed $failure): bool => is_string($failure)) : [];
    }

    /** @param array<string,mixed> $row @return array{identity_id:?int,status:string} */
    private function sourceLink(PDO $pdo, array $row): array
    {
        $insert = $pdo->prepare('INSERT IGNORE INTO dataset_identity_source_links
            (source_namespace_hash, source_identity_key_hash, status) VALUES (:namespace_hash, :identity_hash, "unresolved")');
        $insert->bindValue(':namespace_hash', $row['source_namespace_hash'], PDO::PARAM_LOB);
        $insert->bindValue(':identity_hash', $row['source_identity_key_hash'], PDO::PARAM_LOB);
        $insert->execute();
        $link = $pdo->prepare('SELECT identity_id, status FROM dataset_identity_source_links
            WHERE source_namespace_hash = :namespace_hash AND source_identity_key_hash = :identity_hash LIMIT 1 FOR UPDATE');
        $link->bindValue(':namespace_hash', $row['source_namespace_hash'], PDO::PARAM_LOB);
        $link->bindValue(':identity_hash', $row['source_identity_key_hash'], PDO::PARAM_LOB);
        $link->execute();
        $value = $link->fetch();
        if (!is_array($value)) throw new RuntimeException('Vinculo de fonte indisponivel.');
        return ['identity_id' => $value['identity_id'] === null ? null : (int) $value['identity_id'], 'status' => (string) $value['status']];
    }

    /** @param array<string,mixed> $row */
    private function excludeForIntegrity(PDO $pdo, array $row, string $reason): void
    {
        $pdo->prepare('UPDATE dataset_import_identities SET integrity_status = "ambiguous", integrity_reason = :reason
            WHERE id = :id')->execute(['reason' => $reason, 'id' => (int) $row['import_identity_id']]);
        $pdo->prepare('UPDATE dataset_samples SET status = "processed", integrity_status = "excluded", integrity_reason = :reason,
            processed_at = UTC_TIMESTAMP(3), temporary_name = NULL WHERE id = :id')
            ->execute(['reason' => $reason, 'id' => (int) $row['sample_id']]);
        $this->refreshCounts($pdo, (int) $row['import_id'], false);
    }

    private function tokenBytes(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) throw new InvalidArgumentException('Token de importacao invalido.');
        $bytes = hex2bin($token);
        if (!is_string($bytes)) throw new InvalidArgumentException('Token de importacao invalido.');
        return $bytes;
    }
}
