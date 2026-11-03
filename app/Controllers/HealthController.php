<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InfrastructureStatusService;

final class HealthController
{
    public function __construct(private readonly InfrastructureStatusService $status) {}

    public function show(): void
    {
        $status = $this->status->status();
        $healthy = $status['database'] === 'disponivel';
        http_response_code($healthy ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $healthy ? 'ok' : 'degraded', 'database' => $status['database'], 'schema_version' => $status['schema_version']], JSON_THROW_ON_ERROR);
    }
}
