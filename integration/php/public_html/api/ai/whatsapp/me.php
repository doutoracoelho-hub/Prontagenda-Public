<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

whatsapp_ai_exigir_get();
$contexto = whatsapp_ai_contexto($pdo);

try {
    $paciente = whatsapp_ai_paciente($pdo, $contexto);
    whatsapp_ai_responder([
        'sucesso' => true,
        'paciente' => ['nome' => $paciente['nome']],
        'resposta_template' => WhatsAppAiTemplateService::identidade((string)$paciente['nome']),
    ]);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_me] ' . $e->getMessage());
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível confirmar a identidade.'], 500);
}
