<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Services\LocalSessionAuth;
use App\Services\ContinuousRecognitionService;
use App\Services\BenchmarkDashboardService;
use App\Services\PythonFaceClient;
use App\Services\RecognitionService;
use App\Services\RegistrationService;
use App\Services\DatasetImportService;
use App\Services\ProbeEvaluationService;
use DomainException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class ApiController
{
    public function __construct(
        private readonly LocalSessionAuth $auth,
        private readonly MysqlIdentityRepository $identities,
        private readonly RegistrationService $registration,
        private readonly RecognitionService $recognition,
        private readonly PythonFaceClient $python,
        private readonly ContinuousRecognitionService $continuous,
        private readonly BenchmarkDashboardService $dashboard,
        private readonly DatasetImportService $imports,
        private readonly ProbeEvaluationService $probeEvaluations,
        /** @var array{default_interval_ms:int,minimum_interval_ms:int,maximum_interval_ms:int} */
        private readonly array $videoConfig
    ) {}

    public function dispatch(string $action): void
    {
        try {
            if ($action === 'metrics-export') {
                $this->exportMetrics();
                return;
            }
            if ($action === 'import-image') {
                $this->importImage();
                return;
            }
            $result = match ($action) {
                'login' => $this->login(),
                'logout' => $this->logout(),
                'bootstrap' => $this->bootstrap(),
                'people' => $this->people(),
                'register' => $this->register(),
                'person-detail' => $this->personDetail(),
                'person-rename' => $this->personRename(),
                'person-delete' => $this->personDelete(),
                'sample-status' => $this->sampleStatus(),
                'recognize-a' => $this->recognizeA(),
                'recognize-b' => $this->recognizeB(),
                'warmup-b' => $this->warmupB(),
                'session-start' => $this->sessionStart(),
                'session-evaluation-identity' => $this->sessionEvaluationIdentity(),
                'session-pause' => $this->sessionPause(),
                'session-resume' => $this->sessionResume(),
                'session-end' => $this->sessionEnd(),
                'session-presence' => $this->sessionPresence(),
                'session-warmup' => $this->sessionWarmup(),
                'session-frame' => $this->sessionFrame(),
                'session-frame-a' => $this->sessionFrameA(),
                'session-frame-b' => $this->sessionFrameB(),
                'session-finalize' => $this->sessionFinalize(),
                'dashboard' => $this->dashboard(),
                'probe-evaluation-run' => $this->probeEvaluationRun(),
                'metrics-delete-session' => $this->deleteMetricsSession(),
                'metrics-clear' => $this->clearMetrics(),
                'import-start' => $this->importStart(),
                'import-zip' => $this->importZip(),
                'import-status' => $this->importStatus(),
                'import-samples' => $this->importSamples(),
                'import-process' => $this->importProcess(),
                'import-reject' => $this->importReject(),
                'import-pause' => $this->importPause(),
                'import-resume' => $this->importResume(),
                'import-cancel' => $this->importCancel(),
                default => throw new DomainException('Endpoint nao encontrado.', 404),
            };
            $this->json($result, $action === 'register' ? 201 : 200);
        } catch (Throwable $error) {
            $status = $error instanceof DomainException && $error->getCode() >= 400
                ? $error->getCode()
                : (($error instanceof InvalidArgumentException || $error instanceof JsonException || ($error instanceof RuntimeException && $error->getCode() === 422)) ? 422 : ($error->getCode() === 503 ? 503 : 500));
            $message = $status >= 500 && !($error instanceof RuntimeException && $error->getCode() === 503)
                ? 'Falha interna ao processar a operacao.'
                : $error->getMessage();
            $this->json(['error' => $message], $status);
        }
    }

    /** @return array{success:true} */
    private function login(): array
    {
        $this->method('POST');
        $this->auth->requireLocalRequest();
        $input = $this->input(2048);
        $key = $input['access_key'] ?? null;
        if (!is_string($key) || !$this->auth->login($key)) {
            throw new DomainException('Chave de acesso invalida.', 401);
        }
        return ['success' => true];
    }

    /** @return array{success:true} */
    private function logout(): array
    {
        $this->method('POST');
        $this->auth->requireAuthenticated(true);
        $this->auth->logout();
        return ['success' => true];
    }

    /** @return array<string,mixed> */
    private function bootstrap(): array
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        return [
            'csrf_token' => $this->auth->csrfToken(),
            'identity_count' => $this->identities->count(),
            'video' => $this->videoConfig,
            'import' => $this->imports->capabilities(),
            'recent_imports' => $this->imports->recent(),
        ];
    }

    /** @return array<string,mixed> */
    private function people(): array
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        $limit = $this->positiveInt($_GET['limit'] ?? 20);
        if (!in_array($limit, [10, 20, 50, 100], true)) throw new InvalidArgumentException('Limite de pessoas invalido.');
        $offset = $this->nonNegativeInt($_GET['offset'] ?? 0);
        $query = $_GET['query'] ?? '';
        if (!is_string($query)) throw new InvalidArgumentException('Pesquisa de pessoas invalida.');
        return $this->identities->page($offset, $limit, $query);
    }

    /** @return array{id:int,success:true} */
    private function register(): array
    {
        $this->method('POST');
        $this->auth->requireAuthenticated(true);
        $input = $this->input(7_500_000);
        if (!is_string($input['name'] ?? null) || !is_bool($input['consent'] ?? null) || !is_array($input['faceapi_embedding'] ?? null) || !is_string($input['image'] ?? null)) {
            throw new InvalidArgumentException('Dados de cadastro invalidos.');
        }
        $sface = $this->python->extract($input['image']);
        $id = $this->registration->register($input['name'], $input['consent'], $input['faceapi_embedding'], $sface['embedding']);
        return ['id' => $id, 'success' => true];
    }

    /** @return array<string,mixed> */
    private function personDetail(): array
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        return $this->identities->details($this->queryPositiveInt('id'));
    }

    /** @return array{success:true} */
    private function personRename(): array
    {
        $input = $this->protectedInput(2048);
        if (!is_string($input['name'] ?? null)) throw new InvalidArgumentException('Nome ausente.');
        $this->registration->rename($this->inputPositiveInt($input, 'id'), $input['name']);
        return ['success' => true];
    }

    /** @return array{success:true,impact:array{history_events:int,dataset_links:int,deactivated_embeddings:int}} */
    private function personDelete(): array
    {
        $input = $this->protectedInput(1024);
        return ['success' => true, 'impact' => $this->identities->archive($this->inputPositiveInt($input, 'id'))];
    }

    /** @return array{success:true,person:array<string,mixed>} */
    private function sampleStatus(): array
    {
        $input = $this->protectedInput(1024);
        if (!is_bool($input['active'] ?? null)) throw new InvalidArgumentException('Estado da amostra invalido.');
        return ['success' => true, 'person' => $this->identities->setSampleActive($this->inputPositiveInt($input, 'embedding_id'), $input['active'])];
    }

    /** @return array<string,mixed> */
    private function recognizeA(): array
    {
        $this->method('POST');
        $this->auth->requireAuthenticated(true);
        $input = $this->input(80_000);
        if (!is_array($input['embedding'] ?? null)) {
            throw new InvalidArgumentException('Embedding Face API.js ausente.');
        }
        return $this->recognition->architectureA($input['embedding'], is_array($input['metrics'] ?? null) ? $input['metrics'] : []);
    }

    /** @return array<string,mixed> */
    private function recognizeB(): array
    {
        $this->method('POST');
        $this->auth->requireAuthenticated(true);
        $input = $this->input(7_500_000);
        if (!is_string($input['image'] ?? null)) {
            throw new InvalidArgumentException('Imagem ausente.');
        }
        return $this->recognition->architectureB($input['image']);
    }

    /** @return array<string,mixed> */
    private function warmupB(): array
    {
        $this->method('POST');
        $this->auth->requireAuthenticated(true);
        return $this->python->warmup();
    }

    /** @return array<string,mixed> */
    private function sessionStart(): array
    {
        $input = $this->protectedInput(4096);
        $interval = filter_var($input['interval_ms'] ?? null, FILTER_VALIDATE_INT);
        if ($interval === false || $interval < $this->videoConfig['minimum_interval_ms'] || $interval > $this->videoConfig['maximum_interval_ms']) {
            throw new InvalidArgumentException('Intervalo de reconhecimento invalido.');
        }
        $initialization = is_array($input['initialization'] ?? null) ? $input['initialization'] : [];
        return $this->continuous->start($interval, [
            'A' => $this->initializationMetrics($initialization['A'] ?? null),
            'B' => $this->initializationMetrics($initialization['B'] ?? null),
        ], isset($input['expected_identity_id']) ? $this->positiveInt($input['expected_identity_id']) : null);
    }

    private function sessionEvaluationIdentity(): array
    {
        $input=$this->protectedInput(1024); $identity=$input['expected_identity_id'] ?? null;
        if ($identity !== null) $identity=$this->positiveInt($identity);
        return $this->continuous->changeEvaluationIdentity($this->token($input), $identity);
    }

    /** @return array<string,mixed> */
    private function sessionPause(): array
    {
        $input = $this->protectedInput(2048);
        return $this->continuous->pause($this->token($input));
    }

    /** @return array<string,mixed> */
    private function sessionResume(): array
    {
        $input = $this->protectedInput(2048);
        return $this->continuous->resume($this->token($input));
    }

    /** @return array<string,mixed> */
    private function sessionEnd(): array
    {
        $input = $this->protectedInput(2048);
        return $this->continuous->end($this->token($input));
    }

    /** @return array<string,mixed> */
    private function sessionPresence(): array
    {
        $input = $this->protectedInput(4096);
        return $this->continuous->monitor(
            $this->token($input),
            $this->positiveInt($input['frame_sequence'] ?? null),
            $this->nonNegativeInt($input['missed_intervals'] ?? null),
            is_array($input['monitoring_metrics'] ?? null) ? $input['monitoring_metrics'] : []
        );
    }

    /** @return array<string,mixed> */
    private function sessionWarmup(): array
    {
        $input = $this->protectedInput(7_500_000);
        if (!is_string($input['image'] ?? null)) {
            throw new InvalidArgumentException('Imagem de aquecimento ausente.');
        }
        return $this->continuous->warmup(
            $this->token($input),
            $input['image'],
            isset($input['faceapi_warmup_ms'])
                ? $this->nonNegativeNumber($input['faceapi_warmup_ms']) : null,
            isset($input['faceapi_first_inference_ms'])
                ? $this->nonNegativeNumber($input['faceapi_first_inference_ms']) : null,
            $this->positiveInt($input['frame_sequence'] ?? null),
            $this->nonNegativeInt($input['missed_intervals'] ?? null),
            is_array($input['monitoring_metrics'] ?? null) ? $input['monitoring_metrics'] : []
        );
    }

    /** @return array<string,mixed> */
    private function sessionFrame(): array
    {
        $input = $this->protectedInput(7_500_000);
        $faceapiEmbedding = $input['faceapi_embedding'] ?? null;
        if (!is_string($input['image'] ?? null) || ($faceapiEmbedding !== null && !is_array($faceapiEmbedding))) {
            throw new InvalidArgumentException('Quadro comparativo invalido.');
        }
        return $this->continuous->processRound(
            $this->token($input),
            $this->positiveInt($input['frame_sequence'] ?? null),
            $this->nonNegativeInt($input['missed_intervals'] ?? null),
            $input['image'],
            $faceapiEmbedding,
            isset($input['faceapi_failure']) && is_string($input['faceapi_failure']) ? $input['faceapi_failure'] : null,
            is_array($input['faceapi_metrics'] ?? null) ? $input['faceapi_metrics'] : [],
            is_array($input['monitoring_metrics'] ?? null) ? $input['monitoring_metrics'] : []
        );
    }

    /** @return array<string,mixed> */
    private function sessionFrameA(): array
    {
        $input = $this->protectedInput(80_000);
        $embedding = $input['faceapi_embedding'] ?? null;
        if ($embedding !== null && !is_array($embedding)) throw new InvalidArgumentException('Embedding Face API.js invalido.');
        return $this->continuous->processArchitectureA(
            $this->token($input), $this->positiveInt($input['frame_sequence'] ?? null), $this->nonNegativeInt($input['missed_intervals'] ?? null),
            $embedding, isset($input['faceapi_failure']) && is_string($input['faceapi_failure']) ? $input['faceapi_failure'] : null,
            is_array($input['faceapi_metrics'] ?? null) ? $input['faceapi_metrics'] : [],
            is_array($input['monitoring_metrics'] ?? null) ? $input['monitoring_metrics'] : []
        );
    }

    /** @return array<string,mixed> */
    private function sessionFrameB(): array
    {
        $input = $this->protectedInput(7_500_000);
        if (!is_string($input['image'] ?? null)) throw new InvalidArgumentException('Imagem ausente.');
        return $this->continuous->processArchitectureB(
            $this->token($input), $this->positiveInt($input['frame_sequence'] ?? null), $this->nonNegativeInt($input['missed_intervals'] ?? null),
            $input['image'], is_array($input['monitoring_metrics'] ?? null) ? $input['monitoring_metrics'] : []
        );
    }

    /** @return array<string,mixed> */
    private function sessionFinalize(): array
    {
        $input = $this->protectedInput(4096);
        return $this->continuous->finalize(
            $this->token($input),
            $this->positiveInt($input['frame_sequence'] ?? null),
            is_array($input['metrics'] ?? null) ? $input['metrics'] : [],
            isset($input['architecture']) && is_string($input['architecture']) ? $input['architecture'] : null
        );
    }

    /** @return array<string,mixed> */
    private function dashboard(): array
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT);
        $token = $_GET['session_token'] ?? null;
        if ($page === false || $limit === false || ($token !== null && !is_string($token))) {
            throw new InvalidArgumentException('Filtros do painel invalidos.');
        }
        return $this->dashboard->dashboard($page, $limit, $token === '' ? null : $token) + [
            'precision' => $this->probeEvaluations->dashboard(),
        ];
    }

    /** @return array<string,mixed> */
    private function probeEvaluationRun(): array
    {
        $input = $this->protectedInput(2048);
        return $this->probeEvaluations->run(
            $this->arrayToken($input, 'import_token'),
            $this->positiveInt($input['maximum_samples'] ?? null)
        );
    }

    /** @return array<string,mixed> */
    private function deleteMetricsSession(): array
    {
        $input = $this->protectedInput(2048);
        $removed = $this->dashboard->deleteSession($this->token($input));
        return ['success' => true, 'removed' => $removed];
    }

    /** @return array<string,mixed> */
    private function clearMetrics(): array
    {
        $this->protectedInput(1024);
        return ['success' => true, 'removed' => $this->dashboard->clearAll()];
    }

    /** @return array<string,mixed> */
    private function importStart(): array
    {
        $input = $this->protectedInput(2_500_000);
        if (!is_string($input['dataset_name'] ?? null) || !is_array($input['manifest'] ?? null)) {
            throw new InvalidArgumentException('Dados da importacao por pasta invalidos.');
        }
        return $this->imports->startFolder(
            $input['dataset_name'], $input['manifest'], $this->positiveInt($input['maximum_people'] ?? null),
            $this->positiveInt($input['maximum_images_per_person'] ?? null), $this->positiveInt($input['gallery_images_per_person'] ?? null)
        );
    }

    /** @return array<string,mixed> */
    private function importZip(): array
    {
        $this->method('POST');
        $this->auth->requireAuthenticated(true);
        $file = $_FILES['archive'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name']) || !is_string($_POST['dataset_name'] ?? null)) {
            throw new InvalidArgumentException('Upload ZIP invalido ou acima do limite do PHP.');
        }
        $sourceSplit = $_POST['source_split'] ?? null;
        if (!is_string($sourceSplit) || !in_array($sourceSplit, ['auto', 'gallery', 'probe'], true)) {
            throw new InvalidArgumentException('Selecione o destino das amostras ZIP: distribuir, gallery ou probe.');
        }
        return $this->imports->startZip(
            $_POST['dataset_name'], $file['tmp_name'], $this->positiveInt($_POST['maximum_people'] ?? null),
            $this->positiveInt($_POST['maximum_images_per_person'] ?? null), $this->positiveInt($_POST['gallery_images_per_person'] ?? null), $sourceSplit
        );
    }

    /** @return array<string,mixed> */
    private function importStatus(): array
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        return $this->imports->status($this->queryToken('import_token'));
    }

    /** @return array<string,mixed> */
    private function importSamples(): array
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        $states = $_GET['states'] ?? 'pending,rejected';
        if (!is_string($states)) throw new InvalidArgumentException('Estados das amostras invalidos.');
        $statuses = array_values(array_unique(array_filter(explode(',', $states), static fn(string $state): bool => in_array($state, ['pending', 'rejected', 'processed'], true))));
        if ($statuses === []) throw new InvalidArgumentException('Estados das amostras invalidos.');
        $offset = $this->nonNegativeInt($_GET['offset'] ?? 0);
        $limit = $this->positiveInt($_GET['limit'] ?? 100);
        if ($limit > 100) throw new InvalidArgumentException('Limite de amostras invalido.');
        return $this->imports->samples($this->queryToken('import_token'), $statuses, $offset, $limit);
    }

    /** @return array<string,mixed> */
    private function importProcess(): array
    {
        $input = $this->protectedInput(7_500_000);
        if (!is_array($input['faceapi_embedding'] ?? null) || !is_string($input['image'] ?? null)) {
            throw new InvalidArgumentException('Dados da amostra invalidos.');
        }
        return $this->imports->process($this->arrayToken($input, 'import_token'), $this->arrayToken($input, 'sample_token'), $input['faceapi_embedding'], $input['image']);
    }

    /** @return array<string,mixed> */
    private function importReject(): array
    {
        $input = $this->protectedInput(2048);
        if (!is_string($input['failure_code'] ?? null)) throw new InvalidArgumentException('Motivo de rejeicao ausente.');
        return $this->imports->reject($this->arrayToken($input, 'import_token'), $this->arrayToken($input, 'sample_token'), $input['failure_code']);
    }

    /** @return array<string,mixed> */
    private function importPause(): array
    {
        $input = $this->protectedInput(1024);
        return $this->imports->pause($this->arrayToken($input, 'import_token'));
    }

    /** @return array<string,mixed> */
    private function importResume(): array
    {
        $input = $this->protectedInput(1024);
        return $this->imports->resume($this->arrayToken($input, 'import_token'));
    }

    /** @return array<string,mixed> */
    private function importCancel(): array
    {
        $input = $this->protectedInput(1024);
        return $this->imports->cancel($this->arrayToken($input, 'import_token'));
    }

    private function importImage(): void
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        $image = $this->imports->image($this->queryToken('import_token'), $this->queryToken('sample_token'));
        header('Content-Type: '.$image['mime_type']);
        header('Content-Length: '.filesize($image['path']));
        header('Content-Disposition: inline');
        readfile($image['path']);
    }

    private function exportMetrics(): void
    {
        $this->method('GET');
        $this->auth->requireAuthenticated();
        $token = $_GET['session_token'] ?? null;
        if ($token !== null && (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1)) {
            throw new InvalidArgumentException('Sessao para exportacao invalida.');
        }
        $headers = $this->dashboard->csvHeaders();
        $filename = 'reconhecimento-facial-metricas-'.($token === null ? 'todas' : substr($token, 0, 12)).'-'.gmdate('Ymd-His').'.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        if ($output === false) throw new RuntimeException('Nao foi possivel gerar o CSV.');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers, ',', '"', '');
        foreach ($this->dashboard->exportRows($token) as $row) {
            fputcsv($output, array_map(static fn(string $column): mixed => $row[$column] ?? null, $headers), ',', '"', '');
        }
        fclose($output);
    }

    /** @return array<string,mixed> */
    private function protectedInput(int $maxBytes): array
    {
        $this->method('POST');
        $this->auth->requireAuthenticated(true);
        return $this->input($maxBytes);
    }

    /** @param array<string,mixed> $input */
    private function token(array $input): string
    {
        if (!is_string($input['session_token'] ?? null)) {
            throw new InvalidArgumentException('Sessao ausente.');
        }
        return $input['session_token'];
    }

    /** @param array<string,mixed> $input */
    private function arrayToken(array $input, string $field): string
    {
        $token = $input[$field] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) throw new InvalidArgumentException('Token de importacao invalido.');
        return $token;
    }

    private function queryToken(string $field): string
    {
        $token = $_GET[$field] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) throw new InvalidArgumentException('Token de importacao invalido.');
        return $token;
    }

    private function queryPositiveInt(string $field): int
    {
        return $this->positiveInt($_GET[$field] ?? null);
    }

    /** @param array<string,mixed> $input */
    private function inputPositiveInt(array $input, string $field): int
    {
        return $this->positiveInt($input[$field] ?? null);
    }

    private function positiveInt(mixed $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 1) {
            throw new InvalidArgumentException('Sequencia invalida.');
        }
        return $validated;
    }

    private function nonNegativeInt(mixed $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 0) {
            throw new InvalidArgumentException('Contador invalido.');
        }
        return $validated;
    }

    private function nonNegativeNumber(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Metrica invalida.');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0 || $number > 3_600_000) {
            throw new InvalidArgumentException('Metrica invalida.');
        }
        return $number;
    }

    /** @return array<string,float> */
    private function initializationMetrics(mixed $value): array
    {
        if (is_int($value) || is_float($value)) return ['initialization_ms' => $this->nonNegativeNumber($value)];
        if (!is_array($value)) throw new InvalidArgumentException('Metricas de inicializacao invalidas.');
        $metrics = [];
        foreach (['initialization_ms', 'model_load_ms', 'runtime_ready_ms'] as $name) {
            if (array_key_exists($name, $value)) $metrics[$name] = $this->nonNegativeNumber($value[$name]);
        }
        if (!isset($metrics['initialization_ms'])) throw new InvalidArgumentException('Tempo de inicializacao ausente.');
        return $metrics;
    }

    private function method(string $expected): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $expected) {
            throw new DomainException('Metodo HTTP nao permitido.', 405);
        }
    }

    /** @return array<string,mixed> */
    private function input(int $maxBytes): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
            throw new DomainException('Requisicao muito grande.', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > $maxBytes) {
            throw new InvalidArgumentException('Corpo JSON invalido.');
        }
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Objeto JSON invalido.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
