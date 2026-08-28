<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAtendenteService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'METODO_NAO_PERMITIDO'], 405);
}

$contexto = whatsapp_ai_contexto($pdo);
$entrada = json_decode((string)file_get_contents('php://input'), true);
$motivosPermitidos = ['sem_disponibilidade', 'solicitacao_clinica', 'preferencia_ambigua', 'erro_agenda', 'solicitacao_paciente'];
$motivo = is_array($entrada) ? trim((string)($entrada['motivo'] ?? 'solicitacao_paciente')) : 'solicitacao_paciente';
if (!in_array($motivo, $motivosPermitidos, true)) {
    $motivo = 'solicitacao_paciente';
}

try {
    $id = (new WhatsAppAtendenteService($pdo))->abrirAtendimentoHumano(
        (int)$contexto['empresa_id'],
        (int)$contexto['conversa_id'],
        $motivo,
        !empty($contexto['paciente_id']) ? (int)$contexto['paciente_id'] : null,
        null,
        'recepcao',
        ['origem' => 'atendimento_virtual', 'motivo' => $motivo]
    );
    whatsapp_ai_responder([
        'sucesso' => true,
        'encaminhado_equipe' => true,
        'atendimento_id' => $id,
        'resposta_template' => 'Essa questão precisa ser vista pela nossa equipe. Vou deixar sua mensagem para eles continuarem o atendimento por aqui.',
    ]);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_encaminhar_atendimento] ' . $e->getMessage());
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível abrir o atendimento humano.'], 500);
}
