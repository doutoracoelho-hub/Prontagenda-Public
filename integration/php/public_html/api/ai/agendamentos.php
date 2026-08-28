<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../src/services/AiConsultaService.php';

ai_exigir_get();
$empresaId = ai_empresa_autenticada();

try {
    $pacienteId = isset($_GET['paciente_id']) && ctype_digit((string)$_GET['paciente_id'])
        ? (int)$_GET['paciente_id'] : 0;
    if ($pacienteId < 1) {
        throw new InvalidArgumentException('PACIENTE_ID_INVALIDO');
    }
    $profissionalId = null;
    if (isset($_GET['profissional_id']) && (string)$_GET['profissional_id'] !== '') {
        if (!ctype_digit((string)$_GET['profissional_id']) || (int)$_GET['profissional_id'] < 1) {
            throw new InvalidArgumentException('PROFISSIONAL_ID_INVALIDO');
        }
        $profissionalId = (int)$_GET['profissional_id'];
    }
    $status = isset($_GET['status']) && trim((string)$_GET['status']) !== ''
        ? trim((string)$_GET['status']) : null;
    if ($status !== null && (mb_strlen($status) > 50 || !preg_match('/^[\pL\pN _-]+$/u', $status))) {
        throw new InvalidArgumentException('STATUS_INVALIDO');
    }

    $agendamentos = (new AiConsultaService($pdo))->buscarAgendamentos(
        $empresaId, $pacienteId, ai_data_iso(isset($_GET['data']) ? (string)$_GET['data'] : null), $profissionalId, $status
    );
    ai_responder(['sucesso' => true, 'agendamentos' => $agendamentos]);
} catch (DomainException $e) {
    ai_responder(['sucesso' => false, 'erro' => 'PACIENTE_NAO_ENCONTRADO', 'mensagem' => 'O paciente não foi encontrado nesta empresa.'], 404);
} catch (InvalidArgumentException $e) {
    ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'Um ou mais filtros são inválidos.'], 400);
} catch (Throwable $e) {
    error_log('[api_ai_agendamentos] ' . $e->getMessage());
    ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível consultar os agendamentos.'], 500);
}
