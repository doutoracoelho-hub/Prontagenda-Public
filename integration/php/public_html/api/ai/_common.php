<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../src/services/AiServiceAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ai_responder(array $dados, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_exigir_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        ai_responder(['sucesso' => false, 'erro' => 'METODO_NAO_PERMITIDO', 'mensagem' => 'Use o método GET.'], 405);
    }
}

function ai_empresa_autenticada(): int
{
    try {
        return AiServiceAuth::empresaIdAutenticada();
    } catch (DomainException $e) {
        $codigo = $e->getMessage();
        ai_responder([
            'sucesso' => false,
            'erro' => $codigo,
            'mensagem' => $codigo === 'EMPRESA_NAO_AUTORIZADA'
                ? 'O token não autoriza acesso à empresa informada.'
                : 'Credenciais de serviço inválidas.',
        ], $codigo === 'EMPRESA_NAO_AUTORIZADA' ? 403 : 401);
    } catch (RuntimeException $e) {
        ai_responder(['sucesso' => false, 'erro' => 'INTEGRACAO_NAO_CONFIGURADA', 'mensagem' => 'A integração de IA não está configurada.'], 503);
    }
}

function ai_data_iso(?string $valor): ?string
{
    if ($valor === null || trim($valor) === '') {
        return null;
    }
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', trim($valor));
    if (!$data || $data->format('Y-m-d') !== trim($valor)) {
        throw new InvalidArgumentException('DATA_INVALIDA');
    }
    return $data->format('Y-m-d');
}
