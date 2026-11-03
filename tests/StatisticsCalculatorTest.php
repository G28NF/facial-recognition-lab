<?php
declare(strict_types=1);

use App\Services\StatisticsCalculator;

require __DIR__.'/bootstrap.php';
$calculator = new StatisticsCalculator();
$statistics = $calculator->calculate([1, 2, 3, 4, 100, null, -1, 'invalid']);
if ($statistics['count'] !== 5 || $statistics['mean'] !== 22.0 || $statistics['median'] !== 3.0 || $statistics['minimum'] !== 1.0 || $statistics['maximum'] !== 100.0 || $statistics['p95'] !== 100.0) {
    throw new RuntimeException('Media, mediana, extremos ou P95 incorretos.');
}
if (abs((float) $statistics['population_stddev'] - 39.0128) > .0001) {
    throw new RuntimeException('Desvio-padrao populacional incorreto.');
}
$empty = $calculator->calculate([]);
if ($empty['count'] !== 0 || $empty['mean'] !== null || $empty['p95'] !== null) {
    throw new RuntimeException('Conjunto vazio substituiu ausencias por zero.');
}
echo "OK - media, mediana, extremos, P95 nearest-rank e desvio populacional\n";
