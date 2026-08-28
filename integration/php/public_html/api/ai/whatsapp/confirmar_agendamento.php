<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAgendamentoService.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAiTemplateService.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAtendenteService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'METODO_NAO_PERMITIDO', 'mensagem' => 'Use o método POST.'], 405);
}

$contexto = whatsapp_ai_contexto($pdo);
$entrada = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'JSON_INVALIDO', 'mensagem' => 'Não foi possível interpretar a confirmação.'], 400);
}

$acao = trim((string)($entrada['acao'] ?? ''));
$service = new WhatsAppAgendamentoService($pdo);

try {
    if ($acao === 'preparar') {
        $profissionalId = filter_var($entrada['profissional_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!empty($contexto['profissional_agendamento_id'])) {
            $profissionalId = (int)$contexto['profissional_agendamento_id'];
        }
        $inicio = trim((string)($entrada['data_hora_inicio'] ?? ''));
        $nome = isset($entrada['paciente_nome']) ? trim((string)$entrada['paciente_nome']) : null;
        $mensagemId = filter_var($entrada['mensagem_origem_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($profissionalId === false || $inicio === '') {
            throw new InvalidArgumentException('DADOS_SOLICITACAO_INVALIDOS');
        }
        $resultado = $service->preparar(
            $contexto,
            (int)$profissionalId,
            $inicio,
            $nome,
            $mensagemId === false ? null : (int)$mensagemId
        );
        whatsapp_ai_responder([
            'sucesso' => true,
            'solicitacao' => $resultado,
            'resposta_template' => match ($resultado['identidade_status'] ?? '') {
                'aguardando_nome' => WhatsAppAiTemplateService::solicitarNomeAtendido(),
                'confirmada' => WhatsAppAiTemplateService::confirmarOpcaoComPaciente(
                    $resultado['paciente_nome'],
                    $resultado['profissional_nome'],
                    $resultado['data_hora_inicio']
                ),
                default => WhatsAppAiTemplateService::confirmarDestinatario(
                    $resultado['paciente_nome'] !== '' ? $resultado['paciente_nome'] : null
                ),
            },
        ]);
    }

    if ($acao === 'confirmar') {
        $resultado = $service->confirmarPendente($contexto);
        whatsapp_ai_responder([
            'sucesso' => true,
            'agendamento' => $resultado,
            'resposta_template' => WhatsAppAiTemplateService::agendamentoConfirmado(
                $resultado['profissional_nome'],
                $resultado['data_hora_inicio']
            ),
        ]);
    }

    throw new InvalidArgumentException('ACAO_INVALIDA');
} catch (DomainException $e) {
    $codigo = $e->getMessage();
    if ($codigo === 'AGENDAMENTO_IA_DESATIVADO') {
        $atendimentoId = (new WhatsAppAtendenteService($pdo))->abrirAtendimentoHumano(
            (int)$contexto['empresa_id'],
            (int)$contexto['conversa_id'],
            'confirmacao_agendamento_ia_desativada',
            !empty($contexto['paciente_id']) ? (int)$contexto['paciente_id'] : null,
            null,
            'recepcao',
            ['origem' => 'clara', 'acao' => 'confirmar_agendamento']
        );
        whatsapp_ai_responder([
            'sucesso' => true,
            'encaminhado_equipe' => true,
            'atendimento_id' => $atendimentoId,
            'resposta_template' => 'Sua solicitação foi encaminhada para nossa equipe e está aguardando atendimento para a confirmação do horário.',
        ]);
    }
    $status = match ($codigo) {
        'AGENDAMENTO_IA_DESATIVADO' => 503,
        'VAGA_NAO_DISPONIVEL' => 409,
        'VAGA_JA_CONFIRMADA' => 409,
        'SOLICITACAO_EXPIRADA' => 410,
        'CONFIRMACAO_INVALIDA' => 401,
        'IDENTIDADE_NAO_CONFIRMADA' => 409,
        default => 422,
    };
    $mensagem = match ($codigo) {
        'AGENDAMENTO_IA_DESATIVADO' => 'A confirmação automática ainda não está habilitada.',
        'VAGA_NAO_DISPONIVEL' => 'Essa vaga acabou de ser ocupada. Vou verificar a opção disponível mais próxima para você.',
        'VAGA_JA_CONFIRMADA' => 'Esse horário já está marcado para você. Se quiser alterar essa consulta, posso passar sua mensagem para a equipe.',
        'SOLICITACAO_EXPIRADA' => 'Essa opção expirou. Vou consultar novamente antes de confirmar.',
        'NOME_PACIENTE_OBRIGATORIO' => 'Para concluir um primeiro agendamento, preciso do seu nome completo.',
        'IDENTIDADE_NAO_CONFIRMADA' => 'Antes de confirmar o horário, preciso saber para quem será a consulta.',
        'HORARIO_NAO_ESCOLHIDO' => $service->mensagemHorarioPendente($contexto, $inicio ?? ''),
        'SOLICITACAO_NAO_CONFIRMAVEL' => 'Vamos escolher um novo horário. Qual dia e horário fica melhor para você?',
        default => 'Não consegui confirmar esse horário. Pode me dizer novamente qual opção você prefere?',
    };
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $codigo, 'mensagem' => $mensagem], $status);
} catch (InvalidArgumentException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'Os dados do horário são inválidos.'], 400);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_confirmar_agendamento] ' . $e->getMessage());
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível confirmar o agendamento.'], 500);
}
