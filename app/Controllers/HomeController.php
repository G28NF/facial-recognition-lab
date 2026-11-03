<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InfrastructureStatusService;
use App\Services\LocalSessionAuth;

final class HomeController
{
    public function __construct(private readonly InfrastructureStatusService $status, private readonly LocalSessionAuth $auth) {}

    public function index(string $projectRoot): void
    {
        $infrastructure = $this->status->status();
        $authenticated = $this->auth->authenticated();
        require $projectRoot.'/app/Views/home.php';
    }
}
