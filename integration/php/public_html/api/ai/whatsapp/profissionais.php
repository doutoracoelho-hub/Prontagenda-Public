<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAtendenteService.php';

whatsapp_ai_exigir_get();
$contexto = whatsapp_ai_contexto($pdo);

try {
    $stmt = $pdo->prepare(
        "SELECT id, nome FROM usuarios
         WHERE empresa_id = :empresa AND nivel_acesso != 'secretaria'
         ORDER BY nome, id"
    );
    $stmt->execute([':empresa' => $contexto['empresa_id']]);
    $profissionais = array_map(static fn(array $item): array => [
        'id' => (int)$item['id'],
        'nome' => (string)$item['nome'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    $atendimentoId = null;
    if ($profissionais === []) {
        $atendimentoId = (new WhatsAppAtendenteService($pdo))->abrirAtendimentoHumano(
            (int)$contexto['empresa_id'], (int)$contexto['conversa_id'],
            'sem_profissionais_disponiveis',
            !empty($contexto['paciente_id']) ? (int)$contexto['paciente_id'] : null,
            null, 'recepcao', ['origem' => 'clara']
        );
    }
    whatsapp_ai_responder([
        'sucesso' => true,
        'profissionais' => $profissionais,
        'encaminhado_equipe' => $atendimentoId !== null,
        'atendimento_id' => $atendimentoId,
        'resposta_template' => WhatsAppAiTemplateService::profissionais($profissionais),
    ]);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_profissionais] ' . $e->getMessage());
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível listar os profissionais.'], 500);
}
