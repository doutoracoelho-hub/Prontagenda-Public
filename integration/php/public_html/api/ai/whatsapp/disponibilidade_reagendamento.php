<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/AgendaDisponibilidadeService.php';

whatsapp_ai_exigir_get();
$contexto = whatsapp_ai_contexto($pdo);

try {
    $paciente = whatsapp_ai_paciente($pdo, $contexto);
    $agendamentoId = isset($_GET['agendamento_id']) && ctype_digit((string)$_GET['agendamento_id'])
        ? (int)$_GET['agendamento_id'] : 0;
    $data = whatsapp_ai_data(isset($_GET['data']) ? (string)$_GET['data'] : null);
    if ($agendamentoId < 1 || $data === null) {
        throw new InvalidArgumentException('PARAMETROS_INVALIDOS');
    }

    $stmt = $pdo->prepare(
        'SELECT a.profissional_id, a.duracao_minutos, a.data_hora_inicio, a.data_hora_fim '
        . 'FROM agendamentos a WHERE a.id = :agendamento AND a.empresa_id = :empresa '
        . 'AND a.paciente_id = :paciente AND a.data_hora_fim >= NOW() LIMIT 1'
    );
    $stmt->execute([
        ':agendamento' => $agendamentoId,
        ':empresa' => $contexto['empresa_id'],
        ':paciente' => (int)$paciente['id'],
    ]);
    $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agendamento) {
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'AGENDAMENTO_NAO_ENCONTRADO', 'mensagem' => 'A consulta não pertence a esta conversa ou não pode ser reagendada.'], 404);
    }
    $duracao = (int)($agendamento['duracao_minutos'] ?? 0);
    if ($duracao < 1) {
        $duracao = max(1, (int)((strtotime((string)$agendamento['data_hora_fim']) - strtotime((string)$agendamento['data_hora_inicio'])) / 60));
    }
    $resultado = (new AgendaDisponibilidadeService($pdo))->buscar(
        $contexto['empresa_id'], (int)$agendamento['profissional_id'], $data, $duracao
    );
    $nomeStmt = $pdo->prepare('SELECT nome FROM usuarios WHERE id = :id AND empresa_id = :empresa LIMIT 1');
    $nomeStmt->execute([':id' => (int)$agendamento['profissional_id'], ':empresa' => $contexto['empresa_id']]);
    $nomeProfissional = (string)($nomeStmt->fetchColumn() ?: 'o profissional escolhido');
    whatsapp_ai_responder([
        'sucesso' => true,
        'agendamento_id' => $agendamentoId,
        'data' => $data,
        'horarios' => $resultado['horarios'],
        'duracao_minutos' => $resultado['duracao_minutos'],
        'resposta_template' => WhatsAppAiTemplateService::horariosData(
            $nomeProfissional, $data, $resultado['horarios']
        ),
    ]);
} catch (InvalidArgumentException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'Os parâmetros são inválidos.'], 400);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_disponibilidade] ' . $e->getMessage());
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível consultar horários.'], 500);
}
