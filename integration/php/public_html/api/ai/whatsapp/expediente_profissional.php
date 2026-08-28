<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/AgendaExpedienteService.php';

whatsapp_ai_exigir_get();
$contexto = whatsapp_ai_contexto($pdo);

try {
    $profissionalId = isset($_GET['profissional_id']) && ctype_digit((string)$_GET['profissional_id'])
        ? (int)$_GET['profissional_id'] : 0;
    if (!empty($contexto['profissional_agendamento_id'])) {
        $profissionalId = (int)$contexto['profissional_agendamento_id'];
    }
    if ($profissionalId < 1) {
        throw new InvalidArgumentException('PROFISSIONAL_INVALIDO');
    }

    $dados = (new AgendaExpedienteService($pdo))->obter($contexto['empresa_id'], $profissionalId);
    $resumoFormatado = WhatsAppAiTemplateService::resumoExpediente(
        $dados['profissional_nome'],
        $dados['faixas_atendimento']
    );
    whatsapp_ai_responder(array_merge($dados, [
        'sucesso' => true,
        'resumo_expediente' => $resumoFormatado,
        'resposta_template' => WhatsAppAiTemplateService::expediente(
            $dados['profissional_nome'],
            $dados['faixas_atendimento']
        ),
    ]));
} catch (DomainException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'O profissional não pertence a esta empresa.'], 404);
} catch (InvalidArgumentException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'Escolha um profissional válido.'], 400);
} catch (Throwable $e) {
    $linhaLog = sprintf(
        "[%s] [api_ai_whatsapp_expediente] %s: %s em %s:%d\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        str_replace(["\r", "\n"], ' ', $e->getMessage()),
        $e->getFile(),
        $e->getLine()
    );
    error_log(trim($linhaLog));
    $diretorioLog = dirname(__DIR__, 4) . '/storage/logs';
    if ((is_dir($diretorioLog) || @mkdir($diretorioLog, 0750, true)) && is_writable($diretorioLog)) {
        @file_put_contents($diretorioLog . '/whatsapp_ai.log', $linhaLog, FILE_APPEND | LOCK_EX);
    }
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível consultar o expediente.'], 500);
}
