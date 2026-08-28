<?php

declare(strict_types=1);

final class ServicoAdicionalService
{
    public const IA_WHATSAPP = 'ia_whatsapp';

    public function __construct(private PDO $pdo)
    {
    }

    public function empresaPossuiServicoAtivo(int $empresaId, string $servicoSlug): bool
    {
        if ($empresaId < 1 || trim($servicoSlug) === '') {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1
                   FROM assinaturas_servicos a
             INNER JOIN servicos_adicionais s ON s.id = a.servico_id
                  WHERE a.empresa_id = :empresa
                    AND s.slug = :servico
                    AND s.ativo = 1
                    AND a.status IN ('trial', 'ativa')
                    AND (a.data_inicio IS NULL OR a.data_inicio <= CURRENT_DATE)
                    AND (a.data_fim IS NULL OR a.data_fim >= CURRENT_DATE)
                    AND EXISTS (
                        SELECT 1 FROM assinaturas ap
                         WHERE ap.empresa_id = a.empresa_id
                           AND LOWER(TRIM(ap.status)) IN ('ativa','ativo','trial')
                           AND (ap.data_fim IS NULL OR DATE(ap.data_fim) >= CURRENT_DATE)
                    )
                  LIMIT 1"
            );
            $stmt->execute([
                ':empresa' => $empresaId,
                ':servico' => trim($servicoSlug),
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Falha fechada: sem conseguir confirmar a contratação, não gera
            // consumo externo de IA. O fluxo chamador aplica o fallback humano.
            error_log('[servico_adicional_auth] ' . $e->getMessage());
            return false;
        }
    }

    public function exigirServicoAtivo(int $empresaId, string $servicoSlug): void
    {
        if (!$this->empresaPossuiServicoAtivo($empresaId, $servicoSlug)) {
            throw new DomainException('SERVICO_NAO_CONTRATADO');
        }
    }
}
