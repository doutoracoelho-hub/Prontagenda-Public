-- Confirma separadamente quem sera atendido antes de autorizar a gravacao.
ALTER TABLE whatsapp_agendamento_solicitacoes
    ADD COLUMN identidade_status ENUM(
        'aguardando_destinatario',
        'aguardando_nome',
        'confirmada'
    ) NOT NULL DEFAULT 'confirmada' AFTER paciente_nome,
    ADD COLUMN identidade_confirmada_em DATETIME DEFAULT NULL AFTER confirmado_em,
    ADD KEY idx_whatsapp_agendamento_identidade (
        empresa_id, conversa_id, status, identidade_status, criado_em
    );

-- Propostas que já estavam abertas antes da migração também precisam passar
-- pela identificação; somente históricos concluídos permanecem confirmados.
UPDATE whatsapp_agendamento_solicitacoes
   SET identidade_status = CASE
           WHEN TRIM(COALESCE(paciente_nome, '')) = '' THEN 'aguardando_nome'
           ELSE 'aguardando_destinatario'
       END,
       identidade_confirmada_em = NULL
 WHERE status = 'aguardando_confirmacao';

ALTER TABLE whatsapp_conversas
    ADD COLUMN agendamento_paciente_nome_pendente VARCHAR(255) DEFAULT NULL
        AFTER observacoes;
