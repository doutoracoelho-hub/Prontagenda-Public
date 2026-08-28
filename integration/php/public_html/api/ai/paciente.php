<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../src/services/AiConsultaService.php';

ai_exigir_get();
$empresaId = ai_empresa_autenticada();

try {
    $pacienteId = isset($_GET['paciente_id']) && ctype_digit((string)$_GET['paciente_id'])
        ? (int)$_GET['paciente_id'] : null;
    $resultados = (new AiConsultaService($pdo))->buscarPacientes(
        $empresaId,
        isset($_GET['telefone']) ? (string)$_GET['telefone'] : null,
        $pacienteId,
        isset($_GET['nome']) ? (string)$_GET['nome'] : null
    );

    if (count($resultados) === 0) {
        ai_responder(['sucesso' => false, 'erro' => 'PACIENTE_NAO_ENCONTRADO', 'mensagem' => 'Nenhum paciente foi encontrado.'], 404);
    }
    if (count($resultados) > 1) {
        ai_responder(['sucesso' => false, 'erro' => 'PACIENTE_AMBIGUO', 'mensagem' => 'Mais de um paciente corresponde à pesquisa.'], 409);
    }
    ai_responder(['sucesso' => true, 'paciente' => $resultados[0]]);
} catch (InvalidArgumentException $e) {
    $codigo = $e->getMessage();
    ai_responder([
        'sucesso' => false,
        'erro' => $codigo,
        'mensagem' => $codigo === 'TELEFONE_INVALIDO'
            ? 'Informe um telefone brasileiro válido com DDD.'
            : 'Informe telefone, paciente_id ou ao menos dois caracteres do nome.',
    ], 400);
} catch (Throwable $e) {
    error_log('[api_ai_paciente] ' . $e->getMessage());
    ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível consultar o paciente.'], 500);
}
