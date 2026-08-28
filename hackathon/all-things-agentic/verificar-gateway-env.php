<?php

declare(strict_types=1);

$arquivo = $argv[1] ?? '';
if ($arquivo === '' || !is_file($arquivo)) {
    fwrite(STDERR, "Arquivo .env não encontrado.\n");
    exit(1);
}

$env = parse_ini_file($arquivo, false, INI_SCANNER_RAW);
if (!is_array($env)) {
    fwrite(STDERR, "Não foi possível interpretar o arquivo .env.\n");
    exit(1);
}

$url = trim((string)($env['PRONTAGENDA_AI_GATEWAY_URL'] ?? ''));
$token = trim((string)($env['PRONTAGENDA_AI_GATEWAY_TOKEN'] ?? ''));

echo 'URL_ATUAL=' . $url . PHP_EOL;
echo 'TOKEN_LENGTH=' . strlen($token) . PHP_EOL;
echo 'TOKEN_SHA256=' . hash('sha256', $token) . PHP_EOL;

