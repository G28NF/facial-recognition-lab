<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\VectorMath;
use DomainException;
use Generator;
use PDO;
use RuntimeException;
use Throwable;

final class MysqlIdentityRepository
{
    public function __construct(
        private readonly PdoConnection $connection,
        private readonly AuthenticatedCipher $cipher
    ) {}

    /** @param array<string,list<float>> $vectors @param array<string,float> $duplicateThresholds */
    public function register(string $name, array $vectors, array $duplicateThresholds): int
    {
        return $this->connection->transaction(function (PDO $pdo) use ($name, $vectors, $duplicateThresholds): int {
            $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
            $lock->execute(['lock_name' => 'rfv2.identity.register']);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('Cadastro ocupado. Tente novamente.');
            }
            try {
                $duplicateName = $pdo->prepare('SELECT id, active FROM identities WHERE name_lookup_hash = :name_hash LIMIT 1');
                $duplicateName->bindValue(':name_hash', $this->cipher->lookupHash($name), PDO::PARAM_LOB);
                $duplicateName->execute();
                $existing = $duplicateName->fetch();
                if (is_array($existing)) {
                    throw new DomainException((bool) $existing['active'] ? 'Ja existe uma pessoa com este nome.' : 'Este nome pertence a uma pessoa excluida.', 409);
                }
                foreach ($vectors as $modelKey => $vector) {
                    foreach ($this->candidates($modelKey) as $candidate) {
                        if (VectorMath::squaredDistance($vector, $candidate['embedding']) <= $duplicateThresholds[$modelKey]) {
                            throw new DomainException('Este rosto ja esta vinculado a uma identidade.', 409);
                        }
                    }
                }

                $encryptedName = $this->cipher->encrypt($name, 'identity:name');
                $identity = $pdo->prepare('INSERT INTO identities
                    (public_id, name_ciphertext, name_nonce, name_auth_tag, name_key_id, name_lookup_hash, consented_at)
                    VALUES (:public_id, :ciphertext, :nonce, :auth_tag, :key_id, :name_hash, UTC_TIMESTAMP())');
                $identity->bindValue(':public_id', random_bytes(16), PDO::PARAM_LOB);
                $identity->bindValue(':ciphertext', $encryptedName['ciphertext'], PDO::PARAM_LOB);
                $identity->bindValue(':nonce', $encryptedName['nonce'], PDO::PARAM_LOB);
                $identity->bindValue(':auth_tag', $encryptedName['auth_tag'], PDO::PARAM_LOB);
                $identity->bindValue(':key_id', $encryptedName['key_id']);
                $identity->bindValue(':name_hash', $this->cipher->lookupHash($name), PDO::PARAM_LOB);
                $identity->execute();
                $identityId = (int) $pdo->lastInsertId();
                foreach ($vectors as $modelKey => $vector) {
                    $this->insertEmbedding($pdo, $identityId, $modelKey, $vector);
                }
                return $identityId;
            } finally {
                try {
                    $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)')->execute(['lock_name' => 'rfv2.identity.register']);
                } catch (Throwable) {
                }
            }
        });
    }

    /** @param array<string,list<float>> $vectors
     *  @return array{identity_id:int,embedding_ids:array<string,int>,identity_created:bool}
     */
    public function importPair(string $name, array $vectors): array
    {
        return $this->connection->transaction(function (PDO $pdo) use ($name, $vectors): array {
            $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
            $lock->execute(['lock_name' => 'rfv2.identity.register']);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('Cadastro ocupado. Tente novamente.');
            }
            try {
                $lookup = $pdo->prepare('SELECT id, active FROM identities WHERE name_lookup_hash = :name_hash LIMIT 1 FOR UPDATE');
                $lookup->bindValue(':name_hash', $this->cipher->lookupHash($name), PDO::PARAM_LOB);
                $lookup->execute();
                $existing = $lookup->fetch();
                $identityId = is_array($existing) ? (int) $existing['id'] : false;
                $created = false;
                if (is_array($existing) && !(bool) $existing['active']) {
                    throw new DomainException('Este nome pertence a uma pessoa excluida.', 409);
                }
                if ($identityId === false) {
                    $encryptedName = $this->cipher->encrypt($name, 'identity:name');
                    $identity = $pdo->prepare('INSERT INTO identities
                        (public_id, name_ciphertext, name_nonce, name_auth_tag, name_key_id, name_lookup_hash, enrollment_basis, consented_at)
                        VALUES (:public_id, :ciphertext, :nonce, :auth_tag, :key_id, :name_hash, "authorized_dataset", NULL)');
                    $identity->bindValue(':public_id', random_bytes(16), PDO::PARAM_LOB);
                    $identity->bindValue(':ciphertext', $encryptedName['ciphertext'], PDO::PARAM_LOB);
                    $identity->bindValue(':nonce', $encryptedName['nonce'], PDO::PARAM_LOB);
                    $identity->bindValue(':auth_tag', $encryptedName['auth_tag'], PDO::PARAM_LOB);
                    $identity->bindValue(':key_id', $encryptedName['key_id']);
                    $identity->bindValue(':name_hash', $this->cipher->lookupHash($name), PDO::PARAM_LOB);
                    $identity->execute();
                    $identityId = (int) $pdo->lastInsertId();
                    $created = true;
                }
                $embeddingIds = [];
                foreach ($vectors as $modelKey => $vector) {
                    $embeddingIds[$modelKey] = $this->insertEmbedding($pdo, (int) $identityId, $modelKey, $vector);
                }
                return ['identity_id' => (int) $identityId, 'embedding_ids' => $embeddingIds, 'identity_created' => $created];
            } finally {
                try {
                    $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)')->execute(['lock_name' => 'rfv2.identity.register']);
                } catch (Throwable) {
                }
            }
        });
    }

    /** @return Generator<int,array{identity_id:int,embedding:list<float>},mixed,array{mysql_query_ms:float,candidate_read_ms:float,embedding_decrypt_ms:float,candidate_prepare_ms:float}> */
    public function candidates(string $modelKey): Generator
    {
        $queryStarted = hrtime(true);
        $statement = $this->connection->pdo()->prepare('SELECT i.id, e.ciphertext, e.nonce, e.auth_tag, e.dimensions
            FROM embeddings e
            INNER JOIN identities i ON i.id = e.identity_id AND i.active = 1
            INNER JOIN biometric_models m ON m.id = e.biometric_model_id
            WHERE m.model_key = :model_key AND e.active = 1
            ORDER BY i.id, e.sample_number');
        $statement->execute(['model_key' => $modelKey]);
        $queryMs = (hrtime(true) - $queryStarted) / 1_000_000;
        $readNs = $decryptNs = $prepareNs = 0;
        while (true) {
            $started = hrtime(true);
            $row = $statement->fetch();
            $readNs += hrtime(true) - $started;
            if ($row === false) break;
            $started = hrtime(true);
            $embedding = $this->cipher->decryptEmbedding($row['ciphertext'], $row['nonce'], $row['auth_tag'], (int) $row['dimensions']);
            $decryptNs += hrtime(true) - $started;
            $started = hrtime(true);
            $candidate = ['identity_id' => (int) $row['id'], 'embedding' => $embedding];
            $prepareNs += hrtime(true) - $started;
            yield $candidate;
        }
        return [
            'mysql_query_ms' => round($queryMs, 3),
            'candidate_read_ms' => round($readNs / 1_000_000, 3),
            'embedding_decrypt_ms' => round($decryptNs / 1_000_000, 3),
            'candidate_prepare_ms' => round($prepareNs / 1_000_000, 3),
        ];
    }

    /** @return array{name:string,matched_name_query_ms:float,matched_name_decrypt_ms:float} */
    public function identityName(int $identityId): array
    {
        $started = hrtime(true);
        $statement = $this->connection->pdo()->prepare('SELECT name_ciphertext, name_nonce, name_auth_tag
            FROM identities WHERE id = :id AND active = 1 LIMIT 1');
        $statement->execute(['id' => $identityId]);
        $row = $statement->fetch();
        $queryMs = (hrtime(true) - $started) / 1_000_000;
        if (!is_array($row)) throw new RuntimeException('Identidade reconhecida indisponivel.');
        $started = hrtime(true);
        $name = $this->cipher->decrypt($row['name_ciphertext'], $row['name_nonce'], $row['name_auth_tag'], 'identity:name');
        return [
            'name' => $name,
            'matched_name_query_ms' => round($queryMs, 3),
            'matched_name_decrypt_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
        ];
    }

    /** @return list<array{id:int,name:string,faceapi_samples:int,sface_samples:int,created_at:string}> */
    public function list(): array
    {
        $rows = $this->connection->pdo()->query('SELECT i.id, i.name_ciphertext, i.name_nonce, i.name_auth_tag, i.created_at,
            SUM(m.model_key = "faceapi-recognition" AND e.active = 1) AS faceapi_samples,
            SUM(m.model_key = "opencv-sface" AND e.active = 1) AS sface_samples
            FROM identities i
            LEFT JOIN embeddings e ON e.identity_id = i.id
            LEFT JOIN biometric_models m ON m.id = e.biometric_model_id
            WHERE i.active = 1
            GROUP BY i.id, i.name_ciphertext, i.name_nonce, i.name_auth_tag, i.created_at ORDER BY i.id')->fetchAll();
        return $this->mapPeople($rows);
    }

    public function count(): int
    {
        return (int) $this->connection->pdo()->query('SELECT COUNT(*) FROM identities WHERE active = 1')->fetchColumn();
    }

    /** @return array{people:list<array{id:int,name:string,faceapi_samples:int,sface_samples:int,created_at:string}>,total:int,offset:int,limit:int} */
    public function page(int $offset, int $limit, string $query = ''): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 100) throw new \InvalidArgumentException('Paginacao de pessoas invalida.');
        $query = trim($query);
        if (mb_strlen($query) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $query) === 1) {
            throw new \InvalidArgumentException('Pesquisa de pessoas invalida.');
        }
        if ($query !== '') {
            $all = array_values(array_filter($this->list(), static fn(array $person): bool => mb_stripos($person['name'], $query, 0, 'UTF-8') !== false));
            return ['people' => array_slice($all, $offset, $limit), 'total' => count($all), 'offset' => $offset, 'limit' => $limit];
        }
        $statement = $this->connection->pdo()->prepare('SELECT i.id, i.name_ciphertext, i.name_nonce, i.name_auth_tag, i.created_at,
            COALESCE(SUM(m.model_key = "faceapi-recognition" AND e.active = 1), 0) AS faceapi_samples,
            COALESCE(SUM(m.model_key = "opencv-sface" AND e.active = 1), 0) AS sface_samples
            FROM identities i
            LEFT JOIN embeddings e ON e.identity_id = i.id
            LEFT JOIN biometric_models m ON m.id = e.biometric_model_id
            WHERE i.active = 1
            GROUP BY i.id, i.name_ciphertext, i.name_nonce, i.name_auth_tag, i.created_at
            ORDER BY i.id LIMIT :limit OFFSET :offset');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return [
            'people' => $this->mapPeople($statement->fetchAll()),
            'total' => $this->count(),
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    /** @return array{id:int,name:string,enrollment_basis:string,created_at:string,history_events:int,dataset_links:int,samples:list<array<string,mixed>>} */
    public function details(int $identityId): array
    {
        $statement = $this->connection->pdo()->prepare('SELECT i.id, i.name_ciphertext, i.name_nonce, i.name_auth_tag,
            i.enrollment_basis, i.created_at,
            (SELECT COUNT(*) FROM recognition_events re WHERE re.matched_identity_id = i.id) history_events,
            (SELECT COUNT(*) FROM dataset_import_identities dii WHERE dii.identity_id = i.id) dataset_links
            FROM identities i WHERE i.id = :id AND i.active = 1 LIMIT 1');
        $statement->execute(['id' => $identityId]);
        $identity = $statement->fetch();
        if (!is_array($identity)) throw new DomainException('Pessoa nao encontrada.', 404);
        $samples = $this->connection->pdo()->prepare('SELECT e.id, e.sample_number, e.active, e.created_at,
            m.model_key, m.name model_name, ds.split_name, d.name dataset_name
            FROM embeddings e INNER JOIN biometric_models m ON m.id = e.biometric_model_id
            LEFT JOIN dataset_sample_embeddings dse ON dse.gallery_embedding_id = e.id
            LEFT JOIN dataset_samples ds ON ds.id = dse.dataset_sample_id
            LEFT JOIN dataset_imports di ON di.id = ds.dataset_import_id
            LEFT JOIN datasets d ON d.id = di.dataset_id
            WHERE e.identity_id = :identity_id ORDER BY m.model_key, e.sample_number');
        $samples->execute(['identity_id' => $identityId]);
        return [
            'id' => (int) $identity['id'],
            'name' => $this->cipher->decrypt($identity['name_ciphertext'], $identity['name_nonce'], $identity['name_auth_tag'], 'identity:name'),
            'enrollment_basis' => (string) $identity['enrollment_basis'],
            'created_at' => (string) $identity['created_at'],
            'history_events' => (int) $identity['history_events'],
            'dataset_links' => (int) $identity['dataset_links'],
            'samples' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'], 'sample_number' => (int) $row['sample_number'],
                'active' => (bool) $row['active'], 'created_at' => (string) $row['created_at'],
                'model_key' => (string) $row['model_key'], 'model_name' => (string) $row['model_name'],
                'split' => $row['split_name'], 'dataset_name' => $row['dataset_name'],
            ], $samples->fetchAll()),
        ];
    }

    public function rename(int $identityId, string $name): void
    {
        $this->connection->transaction(function (PDO $pdo) use ($identityId, $name): void {
            $identity = $pdo->prepare('SELECT id FROM identities WHERE id = :id AND active = 1 LIMIT 1 FOR UPDATE');
            $identity->execute(['id' => $identityId]);
            if ($identity->fetchColumn() === false) throw new DomainException('Pessoa nao encontrada.', 404);
            $hash = $this->cipher->lookupHash($name);
            $duplicate = $pdo->prepare('SELECT id FROM identities WHERE name_lookup_hash = :name_hash AND id <> :id LIMIT 1');
            $duplicate->bindValue(':name_hash', $hash, PDO::PARAM_LOB);
            $duplicate->bindValue(':id', $identityId, PDO::PARAM_INT);
            $duplicate->execute();
            if ($duplicate->fetchColumn() !== false) throw new DomainException('Ja existe outra pessoa com este nome, inclusive entre as excluidas.', 409);
            $encrypted = $this->cipher->encrypt($name, 'identity:name');
            $update = $pdo->prepare('UPDATE identities SET name_ciphertext = :ciphertext, name_nonce = :nonce,
                name_auth_tag = :auth_tag, name_key_id = :key_id, name_lookup_hash = :name_hash WHERE id = :id');
            $update->bindValue(':ciphertext', $encrypted['ciphertext'], PDO::PARAM_LOB);
            $update->bindValue(':nonce', $encrypted['nonce'], PDO::PARAM_LOB);
            $update->bindValue(':auth_tag', $encrypted['auth_tag'], PDO::PARAM_LOB);
            $update->bindValue(':key_id', $encrypted['key_id']);
            $update->bindValue(':name_hash', $hash, PDO::PARAM_LOB);
            $update->bindValue(':id', $identityId, PDO::PARAM_INT);
            $update->execute();
        });
    }

    /** @return array{history_events:int,dataset_links:int,deactivated_embeddings:int} */
    public function archive(int $identityId): array
    {
        return $this->connection->transaction(function (PDO $pdo) use ($identityId): array {
            $identity = $pdo->prepare('SELECT id FROM identities WHERE id = :id AND active = 1 LIMIT 1 FOR UPDATE');
            $identity->execute(['id' => $identityId]);
            if ($identity->fetchColumn() === false) throw new DomainException('Pessoa nao encontrada.', 404);
            $history = $pdo->prepare('SELECT COUNT(*) FROM recognition_events WHERE matched_identity_id = :id');
            $history->execute(['id' => $identityId]);
            $datasets = $pdo->prepare('SELECT COUNT(*) FROM dataset_import_identities WHERE identity_id = :id');
            $datasets->execute(['id' => $identityId]);
            $embeddings = $pdo->prepare('UPDATE embeddings SET active = 0 WHERE identity_id = :id AND active = 1');
            $embeddings->execute(['id' => $identityId]);
            $pdo->prepare('UPDATE identities SET active = 0 WHERE id = :id')->execute(['id' => $identityId]);
            return [
                'history_events' => (int) $history->fetchColumn(),
                'dataset_links' => (int) $datasets->fetchColumn(),
                'deactivated_embeddings' => $embeddings->rowCount(),
            ];
        });
    }

    public function setSampleActive(int $embeddingId, bool $active): array
    {
        $identityId = $this->connection->transaction(function (PDO $pdo) use ($embeddingId, $active): int {
            $statement = $pdo->prepare('SELECT e.identity_id, i.active identity_active FROM embeddings e
                INNER JOIN identities i ON i.id = e.identity_id WHERE e.id = :id LIMIT 1 FOR UPDATE');
            $statement->execute(['id' => $embeddingId]);
            $sample = $statement->fetch();
            if (!is_array($sample)) throw new DomainException('Amostra facial nao encontrada.', 404);
            if ($active && !(bool) $sample['identity_active']) throw new DomainException('Nao e possivel reativar amostra de pessoa excluida.', 409);
            $pdo->prepare('UPDATE embeddings SET active = :active WHERE id = :id')->execute(['active' => $active ? 1 : 0, 'id' => $embeddingId]);
            return (int) $sample['identity_id'];
        });
        return $this->details($identityId);
    }

    /** @param list<array<string,mixed>> $rows
     *  @return list<array{id:int,name:string,faceapi_samples:int,sface_samples:int,created_at:string}>
     */
    private function mapPeople(array $rows): array
    {
        return array_map(fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => $this->cipher->decrypt($row['name_ciphertext'], $row['name_nonce'], $row['name_auth_tag'], 'identity:name'),
            'faceapi_samples' => (int) $row['faceapi_samples'],
            'sface_samples' => (int) $row['sface_samples'],
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /** @param list<float> $vector */
    private function insertEmbedding(PDO $pdo, int $identityId, string $modelKey, array $vector): int
    {
        $model = $pdo->prepare('SELECT id, dimensions FROM biometric_models WHERE model_key = :model_key LIMIT 1');
        $model->execute(['model_key' => $modelKey]);
        $row = $model->fetch();
        if (!is_array($row) || (int) $row['dimensions'] !== count($vector)) {
            throw new RuntimeException('Modelo biometrico incompativel ou ausente.');
        }
        $sample = $pdo->prepare('SELECT e.sample_number FROM embeddings e
            INNER JOIN biometric_models m ON m.id = e.biometric_model_id
            WHERE e.identity_id = :identity_id AND m.model_key = :model_key
            ORDER BY e.sample_number DESC LIMIT 1 FOR UPDATE');
        $sample->execute(['identity_id' => $identityId, 'model_key' => $modelKey]);
        $lastSample = $sample->fetchColumn();
        $sampleNumber = $lastSample === false ? 1 : (int) $lastSample + 1;
        if ($sampleNumber > 65535) {
            throw new RuntimeException('Limite de amostras da identidade excedido.');
        }
        $encrypted = $this->cipher->encryptEmbedding($vector, count($vector));
        $insert = $pdo->prepare('INSERT INTO embeddings
            (identity_id, biometric_model_id, sample_number, ciphertext, nonce, auth_tag, key_id, dimensions)
            VALUES (:identity_id, :model_id, :sample_number, :ciphertext, :nonce, :auth_tag, :key_id, :dimensions)');
        $insert->bindValue(':identity_id', $identityId, PDO::PARAM_INT);
        $insert->bindValue(':model_id', (int) $row['id'], PDO::PARAM_INT);
        $insert->bindValue(':sample_number', $sampleNumber, PDO::PARAM_INT);
        $insert->bindValue(':ciphertext', $encrypted['ciphertext'], PDO::PARAM_LOB);
        $insert->bindValue(':nonce', $encrypted['nonce'], PDO::PARAM_LOB);
        $insert->bindValue(':auth_tag', $encrypted['auth_tag'], PDO::PARAM_LOB);
        $insert->bindValue(':key_id', $encrypted['key_id']);
        $insert->bindValue(':dimensions', count($vector), PDO::PARAM_INT);
        $insert->execute();
        return (int) $pdo->lastInsertId();
    }
}
