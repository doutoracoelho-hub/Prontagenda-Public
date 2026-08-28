<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAtendenteService.php';

whatsapp_ai_exigir_get();
$contexto = whatsapp_ai_contexto($pdo);

function encaminhar_consulta_agendamento(PDO $pdo, array $contexto, string $motivo, string $mensagem, ?int $pacienteId = null, array $detalhes = []): never
{
    $atendimentoId = (new WhatsAppAtendenteService($pdo))->abrirAtendimentoHumano(
        (int)$contexto['empresa_id'], (int)$contexto['conversa_id'], $motivo,
        $pacienteId, null, 'recepcao', array_merge(['origem' => 'consulta_agendamento_whatsapp'], $detalhes)
    );
    whatsapp_ai_responder([
        'sucesso' => true, 'agendamentos' => [], 'encaminhado_equipe' => true,
        'atendimento_id' => $atendimentoId, 'resposta_template' => $mensagem,
    ]);
}

try {
    $empresaId = (int)$contexto['empresa_id'];
    $conversaId = (int)$contexto['conversa_id'];
    $data = whatsapp_ai_data(isset($_GET['data']) ? (string)$_GET['data'] : null);
    $pacienteId = $contexto['paciente_id'] !== null ? (int)$contexto['paciente_id'] : null;
    $consultaService = new AiConsultaService($pdo);

    if ($pacienteId !== null) {
        $pacientes = $consultaService->buscarPacientes($empresaId, null, $pacienteId, null);
        if (count($pacientes) !== 1) $pacienteId = null;
    } elseif (trim((string)$contexto['telefone']) !== '') {
        $pacientes = $consultaService->buscarPacientes($empresaId, (string)$contexto['telefone'], null, null);
        if (count($pacientes) > 1) {
            encaminhar_consulta_agendamento(
                $pdo, $contexto, 'identidade_ambigua_consulta_agendamento',
                'Encontrei mais de um cadastro possível. Essa parte precisa ser vista pela nossa equipe, então vou deixar sua mensagem para continuarem o atendimento por aqui.',
                null, ['quantidade_cadastros' => count($pacientes)]
            );
        }
        if (count($pacientes) === 1) $pacienteId = (int)$pacientes[0]['id'];
    }

    $stInconsistente = $pdo->prepare(
        "SELECT s.id, s.agendamento_id
           FROM whatsapp_agendamento_solicitacoes s
      LEFT JOIN agendamentos a ON a.id = s.agendamento_id AND a.empresa_id = s.empresa_id
          WHERE s.empresa_id = :empresa AND s.conversa_id = :conversa
            AND s.status = 'confirmado' AND s.agendamento_id IS NOT NULL
            AND s.data_hora_fim >= NOW() AND a.id IS NULL
       ORDER BY s.id DESC LIMIT 1"
    );
    $stInconsistente->execute([':empresa' => $empresaId, ':conversa' => $conversaId]);
    $inconsistente = $stInconsistente->fetch(PDO::FETCH_ASSOC);
    if ($inconsistente) {
        encaminhar_consulta_agendamento(
            $pdo, $contexto, 'agendamento_confirmado_inconsistente',
            'Encontrei uma confirmação anterior, mas nossa equipe precisa conferir os dados da consulta. Vou deixar sua mensagem para continuarem o atendimento por aqui.',
            $pacienteId,
            ['solicitacao_id' => (int)$inconsistente['id'], 'agendamento_id_ausente' => (int)$inconsistente['agendamento_id']]
        );
    }

    $where = ['a.empresa_id = :empresa'];
    $params = [':empresa' => $empresaId, ':conversa' => $conversaId];
    if ($pacienteId !== null) {
        $where[] = "(a.paciente_id = :paciente OR (a.origem_agendamento = 'whatsapp_ia' AND a.whatsapp_conversa_id = :conversa))";
        $params[':paciente'] = $pacienteId;
    } else {
        $where[] = "a.origem_agendamento = 'whatsapp_ia' AND a.whatsapp_conversa_id = :conversa";
    }
    if ($data !== null) {
        $where[] = 'a.data_hora_inicio >= :data_inicio AND a.data_hora_inicio < :data_fim';
        $params[':data_inicio'] = $data . ' 00:00:00';
        $params[':data_fim'] = (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d H:i:s');
    } else {
        $where[] = 'a.data_hora_fim >= NOW()';
    }
    $stmt = $pdo->prepare(
        'SELECT a.id, u.nome AS profissional_nome, a.data_hora_inicio, a.data_hora_fim, a.status '
        . 'FROM agendamentos a JOIN usuarios u ON u.id = a.profissional_id AND u.empresa_id = a.empresa_id '
        . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY a.data_hora_inicio ASC, a.id ASC LIMIT 50'
    );
    $stmt->execute($params);
    $publicos = array_map(static fn(array $item): array => [
        'id' => (int)$item['id'],
        'profissional_nome' => (string)$item['profissional_nome'],
        'data' => substr((string)$item['data_hora_inicio'], 0, 10),
        'hora_inicio' => substr((string)$item['data_hora_inicio'], 11, 5),
        'hora_fim' => substr((string)$item['data_hora_fim'], 11, 5),
        'status' => (string)($item['status'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($publicos === []) {
        encaminhar_consulta_agendamento(
            $pdo, $contexto, 'agendamento_nao_localizado',
            'Não consegui localizar uma consulta futura por aqui. Vou deixar sua mensagem para nossa equipe verificar e continuar o atendimento.',
            $pacienteId, ['data_consultada' => $data]
        );
    }
    whatsapp_ai_responder([
        'sucesso' => true,
        'agendamentos' => $publicos,
        'encaminhado_equipe' => false,
        'resposta_template' => WhatsAppAiTemplateService::agendamentos($publicos),
    ]);
} catch (InvalidArgumentException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'DATA_INVALIDA', 'mensagem' => 'A data informada é inválida.'], 400);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_agendamentos] ' . $e->getMessage());
    try {
        encaminhar_consulta_agendamento(
            $pdo, $contexto, 'instabilidade_consulta_agendamento',
            'Essa questão precisa ser vista pela nossa equipe. Vou deixar sua mensagem para eles continuarem o atendimento por aqui.',
            isset($pacienteId) ? $pacienteId : null, ['erro_tecnico' => get_class($e)]
        );
    } catch (Throwable $encaminhamentoErro) {
        error_log('[api_ai_whatsapp_agendamentos_encaminhamento] ' . $encaminhamentoErro->getMessage());
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível consultar seus agendamentos.'], 500);
    }
}
