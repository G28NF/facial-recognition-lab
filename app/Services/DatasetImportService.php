<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Persistence\MysqlDatasetImportRepository;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class DatasetImportService
{
    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const FAILURE_CODES = ['unsupported_format', 'invalid_image', 'image_too_large', 'dimensions_invalid', 'faceapi_rejected', 'python_rejected', 'python_unavailable', 'backend_rejected', 'backend_failed', 'gallery_required', 'processing_failed', 'import_cancelled'];

    /** @param array<string,int|string> $config @param array<string,array{dimensions:int,threshold_squared:float,ambiguity_margin_squared:float}> $models */
    public function __construct(
        private readonly MysqlDatasetImportRepository $repository,
        private readonly PythonFaceClient $python,
        private readonly array $models,
        private readonly array $config
    ) {}

    /** @param array<mixed> $manifest @return array<string,mixed> */
    public function startFolder(string $datasetName, array $manifest, int $maximumPeople, int $maximumImages, int $galleryImages): array
    {
        [$maximumPeople, $maximumImages, $galleryImages] = $this->requestedLimits($maximumPeople, $maximumImages, $galleryImages);
        if (count($manifest) > (int) $this->config['maximum_files']) {
            throw new InvalidArgumentException('Quantidade de arquivos excede o limite da importacao.');
        }
        $normalized = [];
        $perIdentity = [];
        foreach ($manifest as $entry) {
            if (!is_array($entry)) throw new InvalidArgumentException('Manifesto de pasta invalido.');
            $identity = $this->identityLabel($entry['identity'] ?? null);
            $perIdentity[$identity] = ($perIdentity[$identity] ?? 0) + 1;
            if (count($perIdentity) > $maximumPeople || $perIdentity[$identity] > $maximumImages) continue;
            $sourceKey = $entry['source_key'] ?? null;
            $fingerprint = $entry['fingerprint'] ?? null;
            $mime = $entry['mime_type'] ?? null;
            $bytes = filter_var($entry['byte_size'] ?? null, FILTER_VALIDATE_INT);
            if (!is_string($sourceKey) || strlen($sourceKey) > 500 || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('Identificador de arquivo invalido.');
            }
            $error = null;
            if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) $error = 'unsupported_format';
            elseif ($bytes === false || $bytes < 1 || $bytes > (int) $this->config['maximum_image_bytes']) $error = 'image_too_large';
            $sourceNamespace = $entry['source_namespace'] ?? null;
            $sourceIdentityKey = $entry['source_identity_key'] ?? null;
            if (($sourceNamespace === null) !== ($sourceIdentityKey === null)
                || ($sourceNamespace !== null && (!is_string($sourceNamespace) || !is_string($sourceIdentityKey)
                    || $sourceNamespace === '' || $sourceIdentityKey === '' || mb_strlen($sourceNamespace) > 200 || mb_strlen($sourceIdentityKey) > 200))) {
                throw new InvalidArgumentException('Identificador estavel da fonte invalido.');
            }
            $normalized[] = [
                'identity' => $identity, 'source_key' => $sourceKey, 'fingerprint' => $fingerprint,
                'split' => $perIdentity[$identity] <= $galleryImages ? 'gallery' : 'probe',
                'mime_type' => is_string($mime) ? $mime : null, 'byte_size' => $bytes === false ? null : $bytes,
                'source_namespace' => $sourceNamespace, 'source_identity_key' => $sourceIdentityKey,
                'error' => is_string($entry['error'] ?? null) && in_array($entry['error'], self::FAILURE_CODES, true) ? $entry['error'] : $error,
            ];
        }
        if ($normalized === []) throw new InvalidArgumentException('Nenhuma imagem elegivel foi selecionada.');
        return $this->repository->start($datasetName, 'folder', $normalized, $this->configuration($maximumPeople, $maximumImages, $galleryImages));
    }

    /** @return array<string,mixed> */
    public function startZip(string $datasetName, string $archivePath, int $maximumPeople, int $maximumImages, int $galleryImages, string $sourceSplit = 'auto'): array
    {
        [$maximumPeople, $maximumImages, $galleryImages] = $this->requestedLimits($maximumPeople, $maximumImages, $galleryImages);
        if (!in_array($sourceSplit, ['auto', 'gallery', 'probe'], true)) {
            throw new InvalidArgumentException('Destino das amostras ZIP invalido.');
        }
        if (!extension_loaded('zip') || !is_file($archivePath) || filesize($archivePath) === false || filesize($archivePath) > (int) $this->config['maximum_archive_bytes']) {
            throw new InvalidArgumentException('ZIP ausente, indisponivel ou maior que o limite.');
        }
        $staging = $this->createStagingDirectory();
        $archive = new ZipArchive();
        $opened = false;
        try {
            if ($archive->open($archivePath) !== true) throw new InvalidArgumentException('Arquivo ZIP invalido.');
            $opened = true;
            if ($archive->numFiles < 1 || $archive->numFiles > (int) $this->config['maximum_files']) {
                throw new InvalidArgumentException('Quantidade de entradas do ZIP fora do limite.');
            }
            $entries = [];
            $uncompressed = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                if (!is_array($stat)) throw new InvalidArgumentException('Entrada ZIP invalida.');
                $name = str_replace('\\', '/', $stat['name']);
                $this->assertSafeArchivePath($archive, $index, $name);
                if (str_ends_with($name, '/')) continue;
                $size = $stat['size'];
                $compressed = $stat['comp_size'];
                $uncompressed += $size;
                if ($uncompressed > (int) $this->config['maximum_uncompressed_bytes']) throw new InvalidArgumentException('Conteudo descompactado excede o limite.');
                if ($size > 0 && ($compressed === 0 || $size / max(1, $compressed) > (int) $this->config['maximum_compression_ratio'])) {
                    throw new InvalidArgumentException('Taxa de compressao suspeita no ZIP.');
                }
                $parts = array_values(array_filter(explode('/', $name), static fn(string $part): bool => $part !== ''));
                if (count($parts) < 2) continue;
                $identity = $this->identityLabel($parts[count($parts) - 2]);
                $entries[] = ['index' => $index, 'name' => $name, 'identity' => $identity, 'size' => $size];
            }
            usort($entries, static fn(array $left, array $right): int => [$left['identity'], $left['name']] <=> [$right['identity'], $right['name']]);
            $manifest = [];
            $people = [];
            foreach ($entries as $entry) {
                $identity = $entry['identity'];
                if (!isset($people[$identity]) && count($people) >= $maximumPeople) continue;
                $people[$identity] = ($people[$identity] ?? 0) + 1;
                if ($people[$identity] > $maximumImages) continue;
                $split = $sourceSplit === 'auto'
                    ? ($people[$identity] <= $galleryImages ? 'gallery' : 'probe')
                    : $sourceSplit;
                $manifest[] = $this->extractArchiveEntry($archive, $entry, $staging, $split);
            }
            if ($manifest === []) throw new InvalidArgumentException('O ZIP nao contem imagens na estrutura identidade/arquivo.');
            $result = $this->repository->start($datasetName, 'zip', $manifest, $this->configuration($maximumPeople, $maximumImages, $galleryImages, $sourceSplit));
            $target = $this->jobDirectory((string) $result['import_token']);
            if (!is_dir($target) && !rename($staging, $target)) throw new RuntimeException('Nao foi possivel proteger os temporarios da importacao.');
            if (is_dir($target) && is_dir($staging)) {
                foreach (scandir($staging) ?: [] as $name) {
                    if ($name === '.' || $name === '..') continue;
                    if (is_file($staging.DIRECTORY_SEPARATOR.$name)) @rename($staging.DIRECTORY_SEPARATOR.$name, $target.DIRECTORY_SEPARATOR.$name);
                }
            }
            if (is_dir($staging)) $this->removeDirectory($staging);
            $status = $this->repository->status((string) $result['import_token']);
            if ($status['status'] === 'completed') $this->cleanupJob((string) $result['import_token']);
            return $status;
        } finally {
            if ($opened) $archive->close();
            if (is_dir($staging)) $this->removeDirectory($staging);
        }
    }

    /** @param ?array<mixed> $faceapi @return array<string,mixed> */
    public function process(string $token, string $sampleToken, ?array $faceapi, string $image, ?string $faceapiFailure = null): array
    {
        $location = $this->repository->sampleLocation($token, $sampleToken);
        if ($location['status'] === 'processed') return $this->repository->status($token);
        if ($location['status'] !== 'pending') throw new DomainException('Amostra nao esta pendente.', 409);
        $this->validateJpegDataUri($image);
        $vectors = ['A' => null, 'B' => null];
        $failures = [];
        try {
            if (in_array('A', $location['completed_architectures'], true)) {
                // The persisted embedding is authoritative; do not repeat A during a B retry.
            } elseif ($faceapi === null) {
                $failures['A'] = in_array($faceapiFailure, ['no_face', 'multiple_faces'], true) ? $faceapiFailure : 'internal_failure';
            } else {
                $vectors['A'] = VectorMath::validate($faceapi, $this->models['faceapi-recognition']['dimensions']);
            }
        } catch (Throwable) { $failures['A'] = 'internal_failure'; }
        try {
            if (!in_array('B', $location['completed_architectures'], true)) $vectors['B'] = $this->python->extract($image)['embedding'];
        } catch (Throwable $error) {
            $failures['B'] = $error->getCode() === 422 ? 'biometric_failure' : ($error->getCode() === 503 ? 'http_failure' : 'internal_failure');
        }
        // Persistence errors leave the sample pending; they must never reject a valid vector.
        $result = $this->repository->store($token, $sampleToken, $vectors['A'], $vectors['B'], $failures);
        $updated = $this->repository->sampleLocation($token, $sampleToken);
        if ($updated['status'] !== 'pending') $this->removeNamedSampleFile($token, $location['temporary_name']);
        if ($result['status'] === 'completed') $this->cleanupJob($token);
        return $result;
    }

    /** @return array<string,mixed> */
    public function reject(string $token, string $sampleToken, string $failureCode): array
    {
        if (!in_array($failureCode, self::FAILURE_CODES, true)) throw new InvalidArgumentException('Motivo de rejeicao invalido.');
        $location = $this->repository->sampleLocation($token, $sampleToken);
        $result = $this->repository->reject($token, $sampleToken, $failureCode);
        $this->removeNamedSampleFile($token, $location['temporary_name']);
        if ($result['status'] === 'completed') $this->cleanupJob($token);
        return $result;
    }

    public function pause(string $token): array
    {
        return $this->repository->pause($token);
    }

    public function resume(string $token): array
    {
        return $this->repository->resume($token);
    }

    public function cancel(string $token): array
    {
        $result = $this->repository->cancel($token);
        $this->cleanupJob($token);
        return $result;
    }

    public function status(string $token): array
    {
        return $this->repository->status($token);
    }

    /** @param list<string> $statuses @return array<string,mixed> */
    public function samples(string $token, array $statuses, int $offset, int $limit): array
    {
        return $this->repository->samples($token, $statuses, $offset, $limit);
    }

    public function recent(): array
    {
        return $this->repository->recent();
    }

    /** @return array{path:string,mime_type:string} */
    public function image(string $token, string $sampleToken): array
    {
        $location = $this->repository->sampleLocation($token, $sampleToken);
        if ($location['source_type'] !== 'zip' || $location['status'] !== 'pending' || !is_string($location['temporary_name'])) {
            throw new DomainException('Imagem temporaria indisponivel.', 404);
        }
        $directory = $this->jobDirectory($token);
        $path = $directory.DIRECTORY_SEPARATOR.$location['temporary_name'];
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        if (!is_string($realDirectory) || !is_string($realPath) || !str_starts_with($realPath, $realDirectory.DIRECTORY_SEPARATOR) || !is_file($realPath)) {
            throw new DomainException('Imagem temporaria indisponivel.', 404);
        }
        return ['path' => $realPath, 'mime_type' => is_string($location['mime_type']) ? $location['mime_type'] : 'application/octet-stream'];
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return [
            'zip_available' => extension_loaded('zip'),
            'maximum_people' => (int) $this->config['maximum_people'],
            'maximum_images_per_person' => (int) $this->config['maximum_images_per_person'],
            'maximum_files' => (int) $this->config['maximum_files'],
            'maximum_image_bytes' => (int) $this->config['maximum_image_bytes'],
            'maximum_archive_bytes' => (int) $this->config['maximum_archive_bytes'],
            'maximum_pixels' => (int) $this->config['maximum_pixels'],
            'minimum_dimension' => (int) $this->config['minimum_dimension'],
        ];
    }

    /** @return array{0:int,1:int,2:int} */
    private function requestedLimits(int $people, int $images, int $gallery): array
    {
        if ($people < 1 || $people > (int) $this->config['maximum_people'] || $images < 2 || $images > (int) $this->config['maximum_images_per_person'] || $gallery < 1 || $gallery > $images) {
            throw new InvalidArgumentException('Limites de pessoas, imagens ou gallery invalidos.');
        }
        return [$people, $images, $gallery];
    }

    private function identityLabel(mixed $value): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('Identidade ausente na estrutura LFW.');
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
            || str_contains($value, '/') || str_contains($value, '\\') || in_array($value, ['.', '..'], true)) {
            throw new InvalidArgumentException('Identidade invalida na estrutura LFW.');
        }
        return $value;
    }

    private function assertSafeArchivePath(ZipArchive $archive, int $index, string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:\//D', $name) === 1) {
            throw new InvalidArgumentException('Caminho absoluto ou invalido no ZIP.');
        }
        foreach (explode('/', $name) as $part) if ($part === '..') throw new InvalidArgumentException('Tentativa de path traversal no ZIP.');
        $operations = 0;
        $attributes = 0;
        if ($archive->getExternalAttributesIndex($index, $operations, $attributes) && $operations === ZipArchive::OPSYS_UNIX) {
            $type = ($attributes >> 16) & 0170000;
            if ($type === 0120000) throw new InvalidArgumentException('Links simbolicos nao sao permitidos no ZIP.');
        }
    }

    /** @param array{index:int,name:string,identity:string,size:int} $entry @return array<string,mixed> */
    private function extractArchiveEntry(ZipArchive $archive, array $entry, string $staging, string $split): array
    {
        $stream = $archive->getStream($entry['name']);
        if (!is_resource($stream)) throw new InvalidArgumentException('Nao foi possivel ler uma entrada do ZIP.');
        $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
        $safeExtension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $temporaryName = substr(hash('sha256', $entry['name']), 0, 32).'.'.$safeExtension;
        $path = $staging.DIRECTORY_SEPARATOR.$temporaryName;
        $output = fopen($path, 'xb');
        if (!is_resource($output)) { fclose($stream); throw new RuntimeException('Falha ao criar arquivo temporario protegido.'); }
        $written = stream_copy_to_stream($stream, $output, (int) $this->config['maximum_image_bytes'] + 1);
        fclose($stream);
        fclose($output);
        if (!is_int($written)) throw new RuntimeException('Falha ao extrair imagem do ZIP.');
        $fingerprint = hash_file('sha256', $path);
        if (!is_string($fingerprint)) throw new RuntimeException('Falha ao identificar imagem do ZIP.');
        $error = null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $dimensions = @getimagesize($path);
        if ($written > (int) $this->config['maximum_image_bytes']) $error = 'image_too_large';
        elseif (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) $error = 'unsupported_format';
        elseif (!is_array($dimensions)) $error = 'invalid_image';
        elseif ((int) $dimensions[0] < (int) $this->config['minimum_dimension'] || (int) $dimensions[1] < (int) $this->config['minimum_dimension']
            || (int) $dimensions[0] * (int) $dimensions[1] > (int) $this->config['maximum_pixels']) $error = 'dimensions_invalid';
        if ($error !== null) { @unlink($path); $temporaryName = null; }
        return [
            'identity' => $entry['identity'], 'source_key' => $entry['name'], 'fingerprint' => $fingerprint,
            'split' => $split, 'mime_type' => is_string($mime) ? $mime : null, 'byte_size' => $written,
            'width' => is_array($dimensions) ? (int) $dimensions[0] : null, 'height' => is_array($dimensions) ? (int) $dimensions[1] : null,
            'temporary_name' => $temporaryName, 'error' => $error,
        ];
    }

    private function validateJpegDataUri(string $image): void
    {
        if (!str_starts_with($image, 'data:image/jpeg;base64,')) throw new InvalidArgumentException('Imagem processada deve ser JPEG.');
        $decoded = base64_decode(substr($image, 23), true);
        if (!is_string($decoded) || strlen($decoded) < 100 || strlen($decoded) > (int) $this->config['maximum_image_bytes']) {
            throw new InvalidArgumentException('Imagem processada invalida ou maior que o limite.');
        }
        $dimensions = @getimagesizefromstring($decoded);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($decoded);
        if ($mime !== 'image/jpeg' || !is_array($dimensions)) throw new InvalidArgumentException('Conteudo da imagem processada invalido.');
        if ((int) $dimensions[0] < (int) $this->config['minimum_dimension'] || (int) $dimensions[1] < (int) $this->config['minimum_dimension']
            || (int) $dimensions[0] * (int) $dimensions[1] > (int) $this->config['maximum_pixels']) {
            throw new InvalidArgumentException('Dimensoes da imagem processada fora do limite.');
        }
    }

    /** @return array<string,int|string> */
    private function configuration(int $people, int $images, int $gallery, string $sourceSplit = 'auto'): array
    {
        return ['maximum_people' => $people, 'maximum_images_per_person' => $images, 'gallery_images_per_person' => $gallery, 'source_split' => $sourceSplit];
    }

    private function createStagingDirectory(): string
    {
        $root = $this->temporaryRoot();
        $path = $root.DIRECTORY_SEPARATOR.'staging-'.bin2hex(random_bytes(16));
        if (!mkdir($path, 0700) && !is_dir($path)) throw new RuntimeException('Nao foi possivel criar diretorio temporario protegido.');
        return $path;
    }

    private function temporaryRoot(): string
    {
        $root = (string) $this->config['temporary_root'];
        if ($root === '') throw new RuntimeException('Diretorio temporario de importacao nao configurado.');
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Nao foi possivel criar a raiz temporaria.');
        $real = realpath($root);
        if (!is_string($real)) throw new RuntimeException('Raiz temporaria invalida.');
        return $real;
    }

    private function jobDirectory(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) throw new InvalidArgumentException('Token de importacao invalido.');
        return $this->temporaryRoot().DIRECTORY_SEPARATOR.$token;
    }

    private function removeNamedSampleFile(string $token, mixed $temporaryName): void
    {
        if (!is_string($temporaryName) || preg_match('/^[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$/D', $temporaryName) !== 1) return;
        $directory = $this->jobDirectory($token);
        $path = $directory.DIRECTORY_SEPARATOR.$temporaryName;
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        if (is_string($realDirectory) && is_string($realPath) && str_starts_with($realPath, $realDirectory.DIRECTORY_SEPARATOR) && is_file($realPath)) @unlink($realPath);
    }

    private function cleanupJob(string $token): void
    {
        $directory = $this->jobDirectory($token);
        if (is_dir($directory)) $this->removeDirectory($directory);
    }

    private function removeDirectory(string $directory): void
    {
        $root = $this->temporaryRoot();
        $real = realpath($directory);
        if (!is_string($real) || !str_starts_with($real, $root.DIRECTORY_SEPARATOR) || $real === $root) return;
        $items = scandir($real);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $real.DIRECTORY_SEPARATOR.$item;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($real);
    }
}
