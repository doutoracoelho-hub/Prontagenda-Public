<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este utilitário só pode ser executado por CLI.\n");
    exit(1);
}

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../../src/config/env.php';
prontagenda_load_env();
require_once __DIR__ . '/../../src/config/conexao.php';
require_once __DIR__ . '/../../src/services/WhatsAppAiContext.php';
require_once __DIR__ . '/../../src/services/TelefoneNormalizer.php';

$opcoes = getopt('', ['conversa::', 'telefone::', 'empresa::', 'salvar-env']);
$conversaId = isset($opcoes['conversa']) && ctype_digit((string)$opcoes['conversa'])
    ? (int)$opcoes['conversa'] : 0;
$telefone = trim((string)($opcoes['telefone'] ?? ''));
$empresaId = isset($opcoes['empresa']) && ctype_digit((string)$opcoes['empresa'])
    ? (int)$opcoes['empresa'] : 0;

if ($conversaId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, empresa_id, telefone FROM whatsapp_conversas WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $conversaId]);
} elseif ($telefone !== '' && $empresaId > 0) {
    try {
        $variantes = TelefoneNormalizer::variantesBrasil($telefone);
    } catch (InvalidArgumentException) {
        fwrite(STDERR, "Telefone inválido. Informe DDD e número.\n");
        exit(1);
    }
    $stmt = $pdo->prepare(
        'SELECT id, empresa_id, telefone FROM whatsapp_conversas '
        . 'WHERE empresa_id = :empresa AND telefone IN (:telefone1, :telefone2) '
        . 'ORDER BY atualizado_em DESC, id DESC LIMIT 1'
    );
    $stmt->execute([
        ':empresa' => $empresaId,
        ':telefone1' => $variantes[0],
        ':telefone2' => $variantes[1],
    ]);
} else {
    fwrite(STDERR, "Use --conversa=ID ou --telefone=DDDNUMERO --empresa=ID.\n");
    exit(1);
}

$conversa = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$conversa || trim((string)$conversa['telefone']) === '') {
    fwrite(STDERR, "Conversa inexistente ou sem telefone.\n");
    exit(2);
}

$token = (new WhatsAppAiContext($pdo))->emitir(
    (int)$conversa['empresa_id'],
    (int)$conversa['id'],
    (string)$conversa['telefone'],
    7200
);

if (array_key_exists('salvar-env', $opcoes)) {
    $envAgente = __DIR__ . '/../prontagenda_whatsapp_agent/.env';
    $conteudo = is_file($envAgente) ? (string)file_get_contents($envAgente) : '';
    $linha = 'PRONTAGENDA_WHATSAPP_CONTEXT_TOKEN=' . $token;
    if (preg_match('/^PRONTAGENDA_WHATSAPP_CONTEXT_TOKEN=.*$/m', $conteudo)) {
        $conteudo = (string)preg_replace('/^PRONTAGENDA_WHATSAPP_CONTEXT_TOKEN=.*$/m', $linha, $conteudo);
    } else {
        $conteudo = rtrim($conteudo) . PHP_EOL . $linha . PHP_EOL;
    }
    if (file_put_contents($envAgente, $conteudo, LOCK_EX) === false) {
        fwrite(STDERR, "Não foi possível atualizar o .env do agente.\n");
        exit(3);
    }
    fwrite(STDOUT, "Contexto gravado no .env do agente WhatsApp.\n");
} else {
    fwrite(STDOUT, $token . PHP_EOL);
}
fwrite(STDERR, "Contexto de teste emitido por 2 horas. Reinicie o ADK agora.\n");
