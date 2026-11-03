<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$target = $root.'/.env';
if (is_file($target)) {
    fwrite(STDERR, ".env ja existe; nenhuma alteracao realizada.\n");
    exit(1);
}
$template = file_get_contents($root.'/.env.example');
if (!is_string($template)) {
    throw new RuntimeException('Modelo .env.example indisponivel.');
}
$replacements = [
    'substitua-por-uma-senha-longa' => bin2hex(random_bytes(24)),
    'gere-uma-chave-criptografica' => base64_encode(random_bytes(32)),
    'gere-uma-chave-de-acesso-local' => bin2hex(random_bytes(24)),
    'gere-um-token-interno' => bin2hex(random_bytes(32)),
];
$contents = str_replace(array_keys($replacements), array_values($replacements), $template);
if (file_put_contents($target, $contents, LOCK_EX) === false) {
    throw new RuntimeException('Nao foi possivel criar .env.');
}
echo ".env local criado sem exibir segredos.\n";
