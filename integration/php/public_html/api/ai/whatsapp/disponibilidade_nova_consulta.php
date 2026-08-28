<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../../src/services/AgendaDisponibilidadeService.php';

whatsapp_ai_exigir_get();
$contexto = whatsapp_ai_contexto($pdo);

try {
    $data = whatsapp_ai_data(isset($_GET['data']) ? (string)$_GET['data'] : null);
    $nome = trim((string)($_GET['profissional'] ?? ''));
    $profissionalSelecionadoId = !empty($contexto['profissional_agendamento_id'])
        ? (int)$contexto['profissional_agendamento_id'] : null;
    if ($data === null || ($profissionalSelecionadoId === null && mb_strlen($nome, 'UTF-8') < 2)) {
        throw new InvalidArgumentException('PARAMETROS_INVALIDOS');
    }

    if ($profissionalSelecionadoId !== null) {
        $stmt = $pdo->prepare(
            "SELECT id, nome FROM usuarios
             WHERE empresa_id = :empresa AND id = :id
               AND nivel_acesso != 'secretaria' LIMIT 1"
        );
        $stmt->execute([':empresa' => $contexto['empresa_id'], ':id' => $profissionalSelecionadoId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, nome FROM usuarios
             WHERE empresa_id = :empresa
               AND nivel_acesso != 'secretaria'
               AND nome COLLATE utf8mb4_unicode_ci LIKE :nome
             ORDER BY nome, id LIMIT 3"
        );
        $stmt->execute([':empresa' => $contexto['empresa_id'], ':nome' => '%' . $nome . '%']);
    }
    $profissionais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($profissionais) === 0) {
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'PROFISSIONAL_NAO_ENCONTRADO', 'mensagem' => 'Não encontrei esse profissional.'], 404);
    }
    if (count($profissionais) > 1) {
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'PROFISSIONAL_AMBIGUO', 'mensagem' => 'Mais de um profissional corresponde ao nome. Informe o nome completo.'], 409);
    }

    $profissional = $profissionais[0];
    $disponibilidadeService = new AgendaDisponibilidadeService($pdo);
    $resultado = $disponibilidadeService->buscar(
        $contexto['empresa_id'], (int)$profissional['id'], $data, null, true
    );
    $alternativas = $resultado['horarios'] === []
        ? $disponibilidadeService->buscarPrimeirasVagas(
            (int)$contexto['empresa_id'],
            (int)$profissional['id'],
            (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d'),
            2,
            60,
            true
        )
        : [];
    whatsapp_ai_responder([
        'sucesso' => true,
        'profissional_nome' => (string)$profissional['nome'],
        'data' => $data,
        'horarios' => $resultado['horarios'],
        'alternativas' => $alternativas,
        'duracao_minutos' => $resultado['duracao_minutos'],
        'resposta_template' => WhatsAppAiTemplateService::horariosData(
            (string)$profissional['nome'], $data, $resultado['horarios'], $alternativas
        ),
    ]);
} catch (InvalidArgumentException $e) {
    whatsapp_ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'Informe o profissional e uma data válida.'], 400);
} catch (Throwable $e) {
    error_log('[api_ai_whatsapp_disponibilidade_nova] ' . $e->getMessage());
    whatsapp_ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível consultar horários.'], 500);
}
