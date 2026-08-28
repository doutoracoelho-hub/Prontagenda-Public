<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAgendamentoService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'METODO_NAO_PERMITIDO'], 405);
}

$contexto = whatsapp_ai_contexto($pdo);
try {
    $quantidade = (new WhatsAppAgendamentoService($pdo))->cancelarPendentes($contexto);
    whatsapp_ai_responder([
        'sucesso' => true,
        'propostas_canceladas' => $quantidade,
    ]);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_cancelar_proposta] ' . $e->getMessage());
    whatsapp_ai_responder([
        'sucesso' => false,
        'erro' => 'ERRO_INTERNO',
        'mensagem' => 'Não foi possível substituir a preferência anterior com segurança.',
    ], 500);
}
