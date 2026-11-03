<?php
declare(strict_types=1);

use App\Services\LocalSessionAuth;
require __DIR__.'/bootstrap.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/RF/ReconhecimentoFacial-2/public/index.php';
$auth = new LocalSessionAuth('test-access-key');
if ($auth->login('wrong') || !$auth->login('test-access-key') || !$auth->authenticated()) {
    throw new RuntimeException('Autenticacao local falhou.');
}
$token = $auth->csrfToken();
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
try {
    $auth->requireAuthenticated(true);
    throw new RuntimeException('CSRF invalido foi aceito.');
} catch (DomainException $error) {
    if ($error->getCode() !== 403) throw $error;
}
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
$auth->requireAuthenticated(true);
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
try {
    $auth->requireAuthenticated();
    throw new RuntimeException('Acesso remoto foi aceito.');
} catch (DomainException $error) {
    if ($error->getCode() !== 403) throw $error;
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$auth->logout();
echo "OK - sessao local, autorizacao e CSRF\n";
