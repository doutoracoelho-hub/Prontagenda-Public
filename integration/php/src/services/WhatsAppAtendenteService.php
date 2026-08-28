<?php
declare(strict_types=1);

class WhatsAppAtendenteService
{
    public const CAP_RECEPCAO = 'recepcao';
    public const CAP_IDENTIFICACAO = 'identificacao';
    public const CAP_DUVIDAS_ADMINISTRATIVAS = 'duvidas_administrativas';
    public const CAP_CONSULTAR_HORARIOS = 'consultar_horarios';
    public const CAP_TRANSFERIR_HUMANO = 'transferir_humano';
    public const CAP_CRIAR_AGENDAMENTO = 'criar_agendamento';
    public const CAP_ALTERAR_AGENDAMENTO = 'alterar_agendamento';
    public const CAP_CANCELAR_AGENDAMENTO = 'cancelar_agendamento';

    private const COLUNAS_CAPACIDADES = [
        self::CAP_RECEPCAO => 'recepcao_ativa',
        self::CAP_IDENTIFICACAO => 'identificacao_ativa',
        self::CAP_DUVIDAS_ADMINISTRATIVAS => 'duvidas_administrativas_ativas',
        self::CAP_CONSULTAR_HORARIOS => 'consulta_horarios_ativa',
        self::CAP_TRANSFERIR_HUMANO => 'transferencia_humana_ativa',
        self::CAP_CRIAR_AGENDAMENTO => 'criar_agendamento_ativo',
        self::CAP_ALTERAR_AGENDAMENTO => 'alterar_agendamento_ativo',
        self::CAP_CANCELAR_AGENDAMENTO => 'cancelar_agendamento_ativo',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function carregarConfiguracao(int $empresaId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM whatsapp_atendente_configuracoes WHERE empresa_id = ? LIMIT 1'
        );
        $st->execute([$empresaId]);
        $config = $st->fetch(PDO::FETCH_ASSOC);

        return $config ?: null;
    }

    public function podeExecutar(int $empresaId, string $capacidade): bool
    {
        $coluna = self::COLUNAS_CAPACIDADES[$capacidade] ?? null;
        if ($coluna === null) {
            return false;
        }

        $config = $this->carregarConfiguracao($empresaId);

        return !empty($config['ativo']) && !empty($config[$coluna]);
    }

    public function exigeAtendimentoHumano(string $capacidade): bool
    {
        return in_array($capacidade, [
            self::CAP_CRIAR_AGENDAMENTO,
            self::CAP_ALTERAR_AGENDAMENTO,
            self::CAP_CANCELAR_AGENDAMENTO,
        ], true);
    }

    public function abrirAtendimentoHumano(
        int $empresaId,
        int $conversaId,
        string $motivo,
        ?int $pacienteId = null,
        ?int $agendamentoId = null,
        string $fila = 'recepcao',
        array $contexto = []
    ): int {
        $this->pdo->beginTransaction();

        try {
            $stLockConversa = $this->pdo->prepare(
                'SELECT id FROM whatsapp_conversas WHERE id = ? AND empresa_id = ? FOR UPDATE'
            );
            $stLockConversa->execute([$conversaId, $empresaId]);
            if ($stLockConversa->fetchColumn() === false) {
                throw new RuntimeException('Conversa nao encontrada para a empresa.');
            }

            $stExistente = $this->pdo->prepare(
                "SELECT id
                   FROM whatsapp_atendimentos_humanos
                  WHERE empresa_id = ?
                    AND conversa_id = ?
                    AND status IN ('aguardando', 'em_atendimento')
               ORDER BY id DESC
                  LIMIT 1
                    FOR UPDATE"
            );
            $stExistente->execute([$empresaId, $conversaId]);
            $existente = $stExistente->fetchColumn();

            if ($existente !== false) {
                $this->pdo->commit();
                return (int)$existente;
            }

            $st = $this->pdo->prepare(
                "INSERT INTO whatsapp_atendimentos_humanos
                    (empresa_id, conversa_id, paciente_id, agendamento_id, fila, motivo, status, contexto_json, solicitado_em, criado_em, atualizado_em)
                 VALUES
                    (:empresa_id, :conversa_id, :paciente_id, :agendamento_id, :fila, :motivo, 'aguardando', :contexto_json, NOW(), NOW(), NOW())"
            );
            $st->execute([
                ':empresa_id' => $empresaId,
                ':conversa_id' => $conversaId,
                ':paciente_id' => $pacienteId,
                ':agendamento_id' => $agendamentoId,
                ':fila' => trim($fila) !== '' ? trim($fila) : 'recepcao',
                ':motivo' => trim($motivo) !== '' ? trim($motivo) : 'solicitacao_do_paciente',
                ':contexto_json' => $contexto !== []
                    ? json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ]);
            $atendimentoId = (int)$this->pdo->lastInsertId();

            $stConversa = $this->pdo->prepare(
                "UPDATE whatsapp_conversas
                    SET modo_atendimento = 'humano',
                        estado_fluxo = 'aguardando_humano',
                        bot_pausado_em = NOW(),
                        ultima_interacao_em = NOW(),
                        lock_version = lock_version + 1,
                        atualizado_em = NOW()
                  WHERE id = ?
                    AND empresa_id = ?"
            );
            $stConversa->execute([$conversaId, $empresaId]);

            $this->pdo->commit();
            return $atendimentoId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
