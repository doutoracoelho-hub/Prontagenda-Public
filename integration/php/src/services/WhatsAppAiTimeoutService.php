<?php

declare(strict_types=1);

require_once __DIR__ . '/WhatsAppAtendenteService.php';

/** Encaminha perguntas da atendente virtual que ficaram sem resposta. */
final class WhatsAppAiTimeoutService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{timeout_minutos:int,verificadas:int,encaminhadas:int,erros:int} */
    public function encaminharPerguntasSemResposta(int $limite = 50): array
    {
        $timeoutMinutos = $this->timeoutMinutos();
        $limite = max(1, min(200, $limite));

        $sql = "SELECT c.id AS conversa_id,
                       c.empresa_id,
                       c.paciente_id,
                       c.agendamento_id_ativo,
                       m.id AS mensagem_pergunta_id,
                       m.criado_em AS pergunta_enviada_em
                  FROM whatsapp_conversas c
                  JOIN whatsapp_mensagens m
                    ON m.id = c.ultima_mensagem_outbound_id
                   AND m.empresa_id = c.empresa_id
                   AND m.conversa_id = c.id
                 WHERE COALESCE(c.modo_atendimento, 'bot') = 'bot'
                   AND m.direction = 'outbound'
                   AND (
                       m.origem_envio = 'atendente_virtual'
                       OR c.observacoes = 'aguardando_decisao_remarcacao'
                   )
                   AND (
                       m.mensagem LIKE '%?%'
                       OR c.estado_fluxo = 'ia_whatsapp'
                       OR c.observacoes = 'aguardando_decisao_remarcacao'
                   )
                   AND m.criado_em <= DATE_SUB(NOW(), INTERVAL {$timeoutMinutos} MINUTE)
                   AND NOT EXISTS (
                       SELECT 1
                         FROM whatsapp_mensagens mi
                        WHERE mi.empresa_id = c.empresa_id
                          AND mi.conversa_id = c.id
                          AND mi.direction = 'inbound'
                          AND (mi.criado_em > m.criado_em OR (mi.criado_em = m.criado_em AND mi.id > m.id))
                   )
                   AND NOT EXISTS (
                       SELECT 1
                         FROM whatsapp_atendimentos_humanos ah
                        WHERE ah.empresa_id = c.empresa_id
                          AND ah.conversa_id = c.id
                          AND ah.status IN ('aguardando', 'em_atendimento')
                   )
              ORDER BY m.criado_em ASC
                 LIMIT {$limite}";

        $candidatas = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $resultado = [
            'timeout_minutos' => $timeoutMinutos,
            'verificadas' => count($candidatas),
            'encaminhadas' => 0,
            'erros' => 0,
        ];

        $atendente = new WhatsAppAtendenteService($this->pdo);
        foreach ($candidatas as $candidata) {
            try {
                // Revalida imediatamente antes da abertura. Se o paciente
                // respondeu enquanto o cron processava, a conversa é ignorada.
                if (!$this->continuaSemResposta($candidata, $timeoutMinutos)) {
                    continue;
                }

                $atendente->abrirAtendimentoHumano(
                    (int)$candidata['empresa_id'],
                    (int)$candidata['conversa_id'],
                    'ia_sem_resposta_timeout',
                    !empty($candidata['paciente_id']) ? (int)$candidata['paciente_id'] : null,
                    !empty($candidata['agendamento_id_ativo']) ? (int)$candidata['agendamento_id_ativo'] : null,
                    'recepcao',
                    [
                        'origem' => 'timeout_pergunta_ia',
                        'mensagem_pergunta_id' => (int)$candidata['mensagem_pergunta_id'],
                        'pergunta_enviada_em' => (string)$candidata['pergunta_enviada_em'],
                        'timeout_minutos' => $timeoutMinutos,
                    ]
                );
                $resultado['encaminhadas']++;
            } catch (Throwable $e) {
                $resultado['erros']++;
                error_log(sprintf(
                    '[whatsapp_ai_timeout] empresa=%d conversa=%d erro=%s',
                    (int)$candidata['empresa_id'],
                    (int)$candidata['conversa_id'],
                    $e->getMessage()
                ));
            }
        }

        return $resultado;
    }

    private function continuaSemResposta(array $candidata, int $timeoutMinutos): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1
               FROM whatsapp_conversas c
               JOIN whatsapp_mensagens m
                 ON m.id = c.ultima_mensagem_outbound_id
                AND m.empresa_id = c.empresa_id
                AND m.conversa_id = c.id
              WHERE c.id = :conversa
                AND c.empresa_id = :empresa
                AND c.ultima_mensagem_outbound_id = :mensagem
                AND COALESCE(c.modo_atendimento, 'bot') = 'bot'
                AND (
                    m.origem_envio = 'atendente_virtual'
                    OR c.observacoes = 'aguardando_decisao_remarcacao'
                )
                AND (
                    m.mensagem LIKE '%?%'
                    OR c.estado_fluxo = 'ia_whatsapp'
                    OR c.observacoes = 'aguardando_decisao_remarcacao'
                )
                AND m.criado_em <= DATE_SUB(NOW(), INTERVAL {$timeoutMinutos} MINUTE)
                AND NOT EXISTS (
                    SELECT 1 FROM whatsapp_mensagens mi
                     WHERE mi.empresa_id = c.empresa_id
                       AND mi.conversa_id = c.id
                       AND mi.direction = 'inbound'
                       AND (mi.criado_em > m.criado_em OR (mi.criado_em = m.criado_em AND mi.id > m.id))
                )
              LIMIT 1"
        );
        $st->execute([
            ':conversa' => (int)$candidata['conversa_id'],
            ':empresa' => (int)$candidata['empresa_id'],
            ':mensagem' => (int)$candidata['mensagem_pergunta_id'],
        ]);
        return (bool)$st->fetchColumn();
    }

    private function timeoutMinutos(): int
    {
        $valor = (int)($_ENV['WHATSAPP_AI_RESPOSTA_TIMEOUT_MINUTOS']
            ?? getenv('WHATSAPP_AI_RESPOSTA_TIMEOUT_MINUTOS')
            ?: 30);
        return max(5, min(1440, $valor));
    }
}
