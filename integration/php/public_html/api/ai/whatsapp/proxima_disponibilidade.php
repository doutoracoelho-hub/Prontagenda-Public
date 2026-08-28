<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/AgendaDisponibilidadeService.php';
require_once __DIR__ . '/../../../../src/services/AgendaExpedienteService.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAtendenteService.php';

whatsapp_ai_exigir_get();
$contexto = whatsapp_ai_contexto($pdo);

try {
    $parseDias = static function (string $valor, bool $nuloQuandoVazio): ?array {
        $valor = trim($valor);
        if ($valor === '') return $nuloQuandoVazio ? null : [];
        if (!preg_match('/^[1-7](?:,[1-7])*$/', $valor)) {
            throw new InvalidArgumentException('DIAS_SEMANA_INVALIDOS');
        }
        $dias = array_values(array_unique(array_map('intval', explode(',', $valor))));
        sort($dias);
        return $dias;
    };
    $profissionalId = isset($_GET['profissional_id']) && ctype_digit((string)$_GET['profissional_id'])
        ? (int)$_GET['profissional_id'] : 0;
    if (!empty($contexto['profissional_agendamento_id'])) {
        $profissionalId = (int)$contexto['profissional_agendamento_id'];
    }
    $horario = trim((string)($_GET['horario_preferido'] ?? ''));
    $tipo = trim((string)($_GET['tipo_preferencia'] ?? 'exato'));
    $horarioFim = trim((string)($_GET['horario_fim'] ?? ''));
    $diasPreferidos = $parseDias((string)($_GET['dias_preferidos'] ?? ''), true);
    $diasExcluidos = $parseDias((string)($_GET['dias_excluidos'] ?? ''), false) ?? [];
    if ($profissionalId < 1) {
        throw new InvalidArgumentException('PROFISSIONAL_INVALIDO');
    }

    $tiposIntervalo = ['intervalo', 'periodo', 'primeiro_disponivel'];
    $tiposSemValidacaoExataExpediente = [...$tiposIntervalo, 'aproximado'];
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horario)
        || !in_array($tipo, ['exato', 'a_partir', 'ate', ...$tiposSemValidacaoExataExpediente], true)
        || (in_array($tipo, $tiposIntervalo, true)
            && (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horarioFim) || $horarioFim < $horario))) {
        throw new InvalidArgumentException('PREFERENCIA_INVALIDA');
    }

    $expedienteService = new AgendaExpedienteService($pdo);
    $expediente = $expedienteService->obter($contexto['empresa_id'], $profissionalId);
    $diasPermitidos = $diasPreferidos ?? array_values(array_diff(range(1, 7), $diasExcluidos));
    if (!in_array($tipo, $tiposSemValidacaoExataExpediente, true)
        && !$expedienteService->aceitaPreferenciaEmDias($expediente['faixas_atendimento'], $horario, $tipo, $diasPermitidos)) {
        whatsapp_ai_responder([
            'sucesso' => true,
            'fora_expediente' => true,
            'profissional_nome' => $expediente['profissional_nome'],
            'horario_preferido' => $horario,
            'tipo_preferencia' => $tipo,
            'dias_preferidos' => $diasPreferidos,
            'dias_excluidos' => $diasExcluidos,
            'preferencia' => null,
            'alternativa_mais_cedo' => null,
            'alternativas' => [],
            'resumo_expediente' => $expediente['resumo_expediente'],
            'resposta_template' => WhatsAppAiTemplateService::horarioForaExpediente(
                $expediente['profissional_nome'],
                $expediente['faixas_atendimento']
            ),
        ]);
    }

    $resultado = (new AgendaDisponibilidadeService($pdo))->buscarProximaPreferencia(
        $contexto['empresa_id'], $profissionalId, $horario, $tipo, $diasPreferidos, $diasExcluidos, null, 60,
        $horarioFim !== '' ? $horarioFim : null,
        true
    );
    if (!empty($resultado['horario_fora_grade'])) {
        whatsapp_ai_responder([
            'sucesso' => true,
            'fora_expediente' => false,
            'fora_grade' => true,
            'profissional_nome' => $expediente['profissional_nome'],
            'horario_preferido' => $horario,
            'tipo_preferencia' => $tipo,
            'dias_preferidos' => $diasPreferidos,
            'dias_excluidos' => $diasExcluidos,
            'intervalo_minutos' => $resultado['intervalo_minutos'],
            'preferencia' => null,
            'alternativa_mais_cedo' => $resultado['proxima_na_grade'],
            'alternativas' => $resultado['proxima_na_grade'] !== null ? [$resultado['proxima_na_grade']] : [],
            'resposta_template' => WhatsAppAiTemplateService::horarioForaGrade(
                $expediente['profissional_nome'],
                $horario,
                (int)$resultado['intervalo_minutos'],
                $resultado['proxima_na_grade']
            ),
        ]);
    }
    $atendimentoId = null;
    if ($resultado['preferencia'] === null && $resultado['alternativa_mais_cedo'] === null) {
        $atendimentoId = (new WhatsAppAtendenteService($pdo))->abrirAtendimentoHumano(
            (int)$contexto['empresa_id'], (int)$contexto['conversa_id'],
            'sem_disponibilidade_agenda',
            !empty($contexto['paciente_id']) ? (int)$contexto['paciente_id'] : null,
            null, 'recepcao', [
                'origem' => 'atendimento_virtual',
                'profissional_id' => $profissionalId,
                'tipo_preferencia' => $tipo,
                'dias_preferidos' => $diasPreferidos,
                'dias_excluidos' => $diasExcluidos,
            ]
        );
    }
    whatsapp_ai_responder([
        'sucesso' => true,
        'fora_expediente' => false,
        'profissional_nome' => $expediente['profissional_nome'],
        'horario_preferido' => $horario,
        'tipo_preferencia' => $tipo,
        'horario_fim' => $horarioFim !== '' ? $horarioFim : null,
        'dias_preferidos' => $diasPreferidos,
        'dias_excluidos' => $diasExcluidos,
        'preferencia' => $resultado['preferencia'],
        'alternativa_mais_cedo' => $resultado['alternativa_mais_cedo'],
        'alternativas' => $resultado['alternativas'],
        'resposta_preferencia_unica' => $resultado['preferencia'] !== null
            ? WhatsAppAiTemplateService::preferenciaUnica(
                $expediente['profissional_nome'],
                $resultado['preferencia']
            )
            : null,
        'dias_consultados' => $resultado['dias_consultados'],
        'encaminhado_equipe' => $atendimentoId !== null,
        'atendimento_id' => $atendimentoId,
        'resposta_template' => WhatsAppAiTemplateService::proximaDisponibilidade(
            $expediente['profissional_nome'],
            $resultado['preferencia'],
            $resultado['alternativa_mais_cedo'],
            $resultado['alternativas']
        ),
    ]);
} catch (DomainException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'O profissional não pertence a esta empresa.'], 404);
} catch (InvalidArgumentException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'Informe profissional e preferência de horário válidos.'], 400);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_proxima_disponibilidade] ' . $e->getMessage());
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível procurar a próxima vaga.'], 500);
}
