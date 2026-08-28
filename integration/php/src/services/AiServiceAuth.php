<?php

declare(strict_types=1);

final class AiServiceAuth
{
    public static function empresaIdAutenticada(): int
    {
        $tokenEsperado = trim((string)(getenv('PRONTAGENDA_AI_INTERNAL_API_TOKEN') ?: ''));
        $empresaId = filter_var(getenv('PRONTAGENDA_AI_INTERNAL_EMPRESA_ID'), FILTER_VALIDATE_INT);
        $cabecalho = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($cabecalho === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $nome => $valor) {
                if (strcasecmp((string)$nome, 'Authorization') === 0) {
                    $cabecalho = trim((string)$valor);
                    break;
                }
            }
        }

        if ($tokenEsperado === '' || !$empresaId || $empresaId < 1) {
            throw new RuntimeException('INTEGRACAO_NAO_CONFIGURADA');
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $cabecalho, $partes)
            || !hash_equals($tokenEsperado, trim($partes[1]))) {
            throw new DomainException('NAO_AUTORIZADO');
        }

        if (isset($_GET['empresa_id']) && (string)$_GET['empresa_id'] !== '') {
            $solicitada = filter_var($_GET['empresa_id'], FILTER_VALIDATE_INT);
            if (!$solicitada || (int)$solicitada !== (int)$empresaId) {
                throw new DomainException('EMPRESA_NAO_AUTORIZADA');
            }
        }

        return (int)$empresaId;
    }
}
