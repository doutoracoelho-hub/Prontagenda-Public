<?php

declare(strict_types=1);

require_once __DIR__ . '/WhatsAppAiContext.php';
require_once __DIR__ . '/ServicoAdicionalService.php';

final class WhatsAppAiGatewayService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function responder(
        int $empresaId,
        int $conversaId,
        string $telefone,
        string $mensagem,
        ?string $messageId = null
    ): array {
        (new ServicoAdicionalService($this->pdo))->exigirServicoAtivo(
            $empresaId,
            ServicoAdicionalService::IA_WHATSAPP
        );

        $baseUrl = rtrim(trim((string)($_ENV['PRONTAGENDA_AI_GATEWAY_URL'] ?? getenv('PRONTAGENDA_AI_GATEWAY_URL') ?: '')), '/');
        $token = trim((string)($_ENV['PRONTAGENDA_AI_GATEWAY_TOKEN'] ?? getenv('PRONTAGENDA_AI_GATEWAY_TOKEN') ?: ''));
        if (!str_starts_with($baseUrl, 'https://') || strlen($token) < 32) {
            throw new RuntimeException('GATEWAY_IA_NAO_CONFIGURADO');
        }

        $contexto = (new WhatsAppAiContext($this->pdo))->emitir(
            $empresaId,
            $conversaId,
            $telefone
        );
        $contextoFluxo = $this->buscarContextoFluxo($empresaId, $conversaId);
        $corpo = json_encode([
            'empresa_id' => $empresaId,
            'conversa_id' => $conversaId,
            'mensagem' => $mensagem,
            'contexto_token' => $contexto,
            'message_id' => $messageId,
            'estado_fluxo' => $contextoFluxo['estado_fluxo'],
            'profissional_id_selecionado' => $contextoFluxo['profissional_id'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $curl = curl_init($baseUrl . '/v1/whatsapp/respond');
        if ($curl === false) {
            throw new RuntimeException('GATEWAY_IA_INDISPONIVEL');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            // Precisa terminar antes do max_execution_time da hospedagem PHP,
            // para que o roteador ainda consiga executar o fallback humano.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $corpo,
        ]);
        $respostaHttp = curl_exec($curl);
        $infoCurl = curl_getinfo($curl);
        $status = (int)($infoCurl['http_code'] ?? 0);
        $erroCurl = curl_error($curl);
        curl_close($curl);

        error_log(sprintf(
            '[whatsapp_ai_metric] conversa=%d status=%d dns_ms=%d connect_ms=%d tls_ms=%d first_byte_ms=%d total_ms=%d',
            $conversaId,
            $status,
            (int)round(((float)($infoCurl['namelookup_time'] ?? 0)) * 1000),
            (int)round(((float)($infoCurl['connect_time'] ?? 0)) * 1000),
            (int)round(((float)($infoCurl['appconnect_time'] ?? 0)) * 1000),
            (int)round(((float)($infoCurl['starttransfer_time'] ?? 0)) * 1000),
            (int)round(((float)($infoCurl['total_time'] ?? 0)) * 1000)
        ));

        if (!is_string($respostaHttp) || $respostaHttp === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('GATEWAY_IA_FALHOU' . ($erroCurl !== '' ? ': ' . $erroCurl : ''));
        }
        $dados = json_decode($respostaHttp, true, 16, JSON_THROW_ON_ERROR);
        $resposta = is_array($dados) ? trim((string)($dados['resposta'] ?? '')) : '';
        if ($resposta === '') {
            throw new RuntimeException('GATEWAY_IA_RESPOSTA_INVALIDA');
        }
        return [
            'resposta' => $resposta,
            'encaminhar_humano' => ($dados['encaminhar_humano'] ?? false) === true,
            'motivo_encaminhamento' => trim((string)($dados['motivo_encaminhamento'] ?? '')),
        ];
    }

    /** @return array{estado_fluxo:string,profissional_id:int|null} */
    private function buscarContextoFluxo(int $empresaId, int $conversaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT estado_fluxo, agendamento_profissional_id_pendente
               FROM whatsapp_conversas
              WHERE id = :conversa AND empresa_id = :empresa
              LIMIT 1'
        );
        $stmt->execute([':conversa' => $conversaId, ':empresa' => $empresaId]);
        $conversa = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'estado_fluxo' => trim((string)($conversa['estado_fluxo'] ?? '')),
            'profissional_id' => !empty($conversa['agendamento_profissional_id_pendente'])
                ? (int)$conversa['agendamento_profissional_id_pendente']
                : null,
        ];
    }
}
