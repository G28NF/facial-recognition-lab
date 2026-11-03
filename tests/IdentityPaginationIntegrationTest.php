<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Security\AuthenticatedCipher;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$security = require $root.'/config/security.php';
$repository = new MysqlIdentityRepository($connection, new AuthenticatedCipher($security['data_key'], $security['data_key_id']));

$first = $repository->page(0, 20);
if ($first['total'] < 1 || count($first['people']) > 20 || $first['limit'] !== 20 || $first['offset'] !== 0) {
    throw new RuntimeException('Primeira pagina de identidades invalida.');
}
$second = $repository->page(20, 20);
if (count($first['people']) === 20 && $second['people'] !== [] && $first['people'][0]['id'] === $second['people'][0]['id']) {
    throw new RuntimeException('Paginas consecutivas repetiram a primeira identidade.');
}
$small = $repository->page(0, 10);
if (count($small['people']) > 10 || $small['limit'] !== 10) {
    throw new RuntimeException('Limite de dez registros nao foi respeitado.');
}
$medium = $repository->page(0, 50);
if (count($medium['people']) > 50 || $medium['limit'] !== 50) {
    throw new RuntimeException('Limite de cinquenta registros nao foi respeitado.');
}
$name = $first['people'][0]['name'];
$query = mb_substr($name, 0, min(3, mb_strlen($name)));
$filtered = $repository->page(0, 100, $query);
if ($filtered['total'] < 1 || count($filtered['people']) > 100) {
    throw new RuntimeException('Pesquisa paginada nao retornou resultados esperados.');
}
foreach ($filtered['people'] as $person) {
    if (mb_stripos($person['name'], $query, 0, 'UTF-8') === false) throw new RuntimeException('Filtro retornou pessoa fora da pesquisa.');
}

echo "OK - paginacao de identidades, limites e pesquisa preservada\n";
