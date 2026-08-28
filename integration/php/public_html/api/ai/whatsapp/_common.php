<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAiContext.php';
require_once __DIR__ . '/../../../../src/services/AiConsultaService.php';
require_once __DIR__ . '/../../../../src/services/WhatsAppAiTemplateService.php';
require_once __DIR__ . '/../../../../src/services/ServicoAdicionalService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function whatsapp_ai_responder(array $dados, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function whatsapp_ai_exigir_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'METODO_NAO_PERMITIDO', 'mensagem' => 'Use o método GET.'], 405);
    }
}

/** @return array{empresa_id:int,conversa_id:int,telefone:string,paciente_id:int|null} */
function whatsapp_ai_contexto(PDO $pdo): array
{
    try {
        $contexto = (new WhatsAppAiContext($pdo))->autenticarRequisicao();
        (new ServicoAdicionalService($pdo))->exigirServicoAtivo(
            (int)$contexto['empresa_id'],
            ServicoAdicionalService::IA_WHATSAPP
        );
        return $contexto;
    } catch (RuntimeException $e) {
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'INTEGRACAO_NAO_CONFIGURADA', 'mensagem' => 'O canal externo não está configurado.'], 503);
    } catch (DomainException $e) {
        $codigo = $e->getMessage();
        error_log('[api_ai_whatsapp_contexto] ' . $codigo);
        if ($codigo === 'SERVICO_NAO_CONTRATADO') {
            whatsapp_ai_responder([
                'sucesso' => false,
                'erro' => $codigo,
                'mensagem' => 'O serviço de IA não está ativo para esta empresa.',
            ], 402);
        }
        whatsapp_ai_responder(['sucesso' => false, 'erro' => $codigo, 'mensagem' => 'O contexto da conversa é inválido ou expirou.'], 401);
    } catch (Throwable $e) {
        error_log('[api_ai_whatsapp_contexto] ' . $e->getMessage());
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'CONTEXTO_WHATSAPP_INVALIDO', 'mensagem' => 'O contexto da conversa é inválido ou expirou.'], 401);
    }
}

function whatsapp_ai_paciente(PDO $pdo, array $contexto): array
{
    $service = new AiConsultaService($pdo);
    if ($contexto['paciente_id'] !== null) {
        $resultados = $service->buscarPacientes($contexto['empresa_id'], null, $contexto['paciente_id'], null);
    } else {
        $resultados = $service->buscarPacientes($contexto['empresa_id'], $contexto['telefone'], null, null);
    }
    if (count($resultados) === 0) {
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'PACIENTE_NAO_IDENTIFICADO', 'mensagem' => 'Não foi possível identificar o paciente desta conversa.'], 404);
    }
    if (count($resultados) !== 1) {
        whatsapp_ai_responder(['sucesso' => false, 'erro' => 'IDENTIDADE_AMBIGUA', 'mensagem' => 'A identidade precisa ser confirmada pela equipe.'], 409);
    }
    return $resultados[0];
}

function whatsapp_ai_data(?string $valor): ?string
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
