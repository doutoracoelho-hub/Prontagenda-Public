<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../src/services/AgendaDisponibilidadeService.php';

ai_exigir_get();
$empresaId = ai_empresa_autenticada();

try {
    $data = ai_data_iso(isset($_GET['data']) ? (string)$_GET['data'] : null);
    if ($data === null) {
        throw new InvalidArgumentException('DATA_OBRIGATORIA');
    }
    $profissionalId = isset($_GET['profissional_id']) && ctype_digit((string)$_GET['profissional_id'])
        ? (int)$_GET['profissional_id'] : 0;
    if ($profissionalId < 1 && isset($_GET['profissional_nome'])) {
        $nome = trim((string)$_GET['profissional_nome']);
        if (mb_strlen($nome, 'UTF-8') < 2) {
            throw new InvalidArgumentException('PROFISSIONAL_INVALIDO');
        }
        $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE empresa_id = :empresa AND nivel_acesso != 'secretaria' AND nome COLLATE utf8mb4_unicode_ci LIKE :nome ORDER BY nome LIMIT 3");
        $stmt->execute([':empresa' => $empresaId, ':nome' => '%' . $nome . '%']);
        $profissionais = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($profissionais) > 1) {
            ai_responder(['sucesso' => false, 'erro' => 'PROFISSIONAL_AMBIGUO', 'mensagem' => 'Mais de um profissional corresponde ao nome.'], 409);
        }
        $profissionalId = count($profissionais) === 1 ? (int)$profissionais[0]['id'] : 0;
    }
    if ($profissionalId < 1) {
        throw new DomainException('PROFISSIONAL_NAO_ENCONTRADO');
    }
    $duracao = isset($_GET['duracao_minutos']) && (string)$_GET['duracao_minutos'] !== ''
        ? filter_var($_GET['duracao_minutos'], FILTER_VALIDATE_INT) : null;
    $resultado = (new AgendaDisponibilidadeService($pdo))->buscar(
        $empresaId,
        $profissionalId,
        $data,
        $duracao ?: null,
        true
    );
    ai_responder(['sucesso' => true, 'profissional_id' => $profissionalId, 'data' => $data] + $resultado);
} catch (DomainException $e) {
    ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'O profissional não foi encontrado nesta empresa.'], 404);
} catch (InvalidArgumentException $e) {
    ai_responder(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => 'Os parâmetros de disponibilidade são inválidos.'], 400);
} catch (Throwable $e) {
    error_log('[api_ai_disponibilidade] ' . $e->getMessage());
    ai_responder(['sucesso' => false, 'erro' => 'ERRO_INTERNO', 'mensagem' => 'Não foi possível consultar a disponibilidade.'], 500);
}
