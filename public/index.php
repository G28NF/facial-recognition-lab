<?php
declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Persistence\MysqlVideoSessionRepository;
use App\Infrastructure\Persistence\MysqlMetricsDashboardRepository;
use App\Infrastructure\Persistence\MysqlDatasetImportRepository;
use App\Infrastructure\Persistence\MysqlProbeEvaluationRepository;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\ComparisonService;
use App\Services\ContinuousRecognitionService;
use App\Services\BenchmarkDashboardService;
use App\Services\StatisticsCalculator;
use App\Services\InfrastructureStatusService;
use App\Services\LocalSessionAuth;
use App\Services\PythonFaceClient;
use App\Services\RecognitionService;
use App\Services\RegistrationService;
use App\Services\DatasetImportService;
use App\Services\ProbeEvaluationService;

$projectRoot = require dirname(__DIR__).'/app/Core/Bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; style-src 'self'; script-src 'self' https://cdn.jsdelivr.net; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
header('Cache-Control: no-store');

$connection = new PdoConnection(require $projectRoot.'/config/database.php');
$status = new InfrastructureStatusService($connection);
$route = trim((string) ($_GET['route'] ?? ''), '/');

if ($route === 'health') {
    (new HealthController($status))->show();
    exit;
}
if (str_starts_with($route, 'api/')) {
    $security = require $projectRoot.'/config/security.php';
    $cipher = new AuthenticatedCipher($security['data_key'], $security['data_key_id']);
    $repository = new MysqlIdentityRepository($connection, $cipher);
    $models = require $projectRoot.'/config/biometrics.php';
    $python = new PythonFaceClient(require $projectRoot.'/config/python.php');
    $auth = new LocalSessionAuth((require $projectRoot.'/config/auth.php')['access_key']);
    $comparison = new ComparisonService($repository, $models);
    $recognition = new RecognitionService($comparison, $python);
    $videoSessions = new MysqlVideoSessionRepository($connection);
    $dashboard = new BenchmarkDashboardService(new MysqlMetricsDashboardRepository($connection), new StatisticsCalculator());
    $imports = new DatasetImportService(
        new MysqlDatasetImportRepository($connection, $cipher, $repository),
        $python,
        $models,
        require $projectRoot.'/config/import.php'
    );
    $probeEvaluations = new ProbeEvaluationService(
        new MysqlProbeEvaluationRepository($connection, $cipher),
        $comparison,
        new StatisticsCalculator(),
        $models
    );
    (new ApiController(
        $auth,
        $repository,
        new RegistrationService($repository, $models),
        $recognition,
        $python,
        new ContinuousRecognitionService($videoSessions, $recognition, $python, $models),
        $dashboard,
        $imports,
        $probeEvaluations,
        require $projectRoot.'/config/video.php'
    ))->dispatch(substr($route, 4));
    exit;
}
if ($route !== '') {
    http_response_code(404);
    echo 'Rota nao encontrada.';
    exit;
}
$auth = new LocalSessionAuth((require $projectRoot.'/config/auth.php')['access_key']);
(new HomeController($status, $auth))->index($projectRoot);
