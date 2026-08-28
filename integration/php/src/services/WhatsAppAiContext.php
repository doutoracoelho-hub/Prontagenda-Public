<?php

declare(strict_types=1);

require_once __DIR__ . '/TelefoneNormalizer.php';

final class WhatsAppAiContext
{
    public function __construct(private PDO $pdo)
    {
    }

    public function emitir(int $empresaId, int $conversaId, string $telefone, int $validadeSegundos = 300): string
    {
        $segredo = $this->segredo();
        $telefoneCanonico = TelefoneNormalizer::variantesBrasil($telefone)[0];
        $payload = [
            'v' => 1,
            'empresa_id' => $empresaId,
            'conversa_id' => $conversaId,
            'telefone' => $telefoneCanonico,
            'exp' => time() + max(30, min($validadeSegundos, 900)),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $corpo = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $assinatura = self::base64UrlEncode(hash_hmac('sha256', $corpo, $segredo, true));
        return $corpo . '.' . $assinatura;
    }

    /** @return array{empresa_id:int,conversa_id:int,telefone:string,paciente_id:int|null,profissional_agendamento_id:int|null} */
    public function autenticarRequisicao(): array
    {
        $cabecalho = trim((string)(
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        ));
        $tokenAlternativo = trim((string)(
            $_SERVER['HTTP_X_PRONTAGENDA_WHATSAPP_CONTEXT']
            ?? ''
        ));
        if (preg_match('/^Bearer\s+(.+)$/i', $cabecalho, $partes)) {
            $token = trim((string)$partes[1]);
        } elseif ($tokenAlternativo !== '') {
            // Alguns provedores removem Authorization antes de entregar a
            // requisicao ao PHP. Este cabecalho preserva o token assinado sem
            // expo-lo na URL ou em parametros.
            $token = $tokenAlternativo;
        } else {
            throw new DomainException('CONTEXTO_CABECALHO_AUSENTE');
        }
        $segmentos = explode('.', $token);
        if (count($segmentos) !== 2) {
            throw new DomainException('CONTEXTO_FORMATO_INVALIDO');
        }
        [$corpo, $assinatura] = $segmentos;
        $esperada = self::base64UrlEncode(hash_hmac('sha256', $corpo, $this->segredo(), true));
        if (!hash_equals($esperada, $assinatura)) {
            throw new DomainException('CONTEXTO_ASSINATURA_INVALIDA');
        }
        $json = self::base64UrlDecode($corpo);
        $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1 || (int)($payload['exp'] ?? 0) < time()) {
            throw new DomainException('CONTEXTO_WHATSAPP_EXPIRADO');
        }

        $empresaId = (int)($payload['empresa_id'] ?? 0);
        $conversaId = (int)($payload['conversa_id'] ?? 0);
        $telefone = (string)($payload['telefone'] ?? '');
        if ($empresaId < 1 || $conversaId < 1 || $telefone === '') {
            throw new DomainException('CONTEXTO_PAYLOAD_INVALIDO');
        }

        $stmt = $this->pdo->prepare(
            'SELECT c.paciente_id, c.telefone, c.agendamento_profissional_id_pendente FROM whatsapp_conversas c '
            . 'WHERE c.id = :conversa AND c.empresa_id = :empresa LIMIT 1'
        );
        $stmt->execute([':conversa' => $conversaId, ':empresa' => $empresaId]);
        $conversa = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conversa) {
            throw new DomainException('CONTEXTO_CONVERSA_NAO_ENCONTRADA');
        }
        $variantesToken = TelefoneNormalizer::variantesBrasil($telefone);
        $variantesConversa = TelefoneNormalizer::variantesBrasil((string)$conversa['telefone']);
        if (count(array_intersect($variantesToken, $variantesConversa)) === 0) {
            throw new DomainException('CONTEXTO_TELEFONE_DIVERGENTE');
        }

        return [
            'empresa_id' => $empresaId,
            'conversa_id' => $conversaId,
            'telefone' => $telefone,
            'paciente_id' => !empty($conversa['paciente_id']) ? (int)$conversa['paciente_id'] : null,
            'profissional_agendamento_id' => !empty($conversa['agendamento_profissional_id_pendente'])
                ? (int)$conversa['agendamento_profissional_id_pendente'] : null,
        ];
    }

    private function segredo(): string
    {
        $segredo = trim((string)(getenv('PRONTAGENDA_AI_WHATSAPP_CONTEXT_SECRET') ?: ''));
        if (strlen($segredo) < 32) {
            throw new RuntimeException('CONTEXTO_WHATSAPP_NAO_CONFIGURADO');
        }
        return $segredo;
    }

    private static function base64UrlEncode(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $valor): string
    {
        $resto = strlen($valor) % 4;
        if ($resto !== 0) {
            $valor .= str_repeat('=', 4 - $resto);
        }
        $resultado = base64_decode(strtr($valor, '-_', '+/'), true);
        if ($resultado === false) {
            throw new DomainException('CONTEXTO_WHATSAPP_INVALIDO');
        }
        return $resultado;
    }
}
