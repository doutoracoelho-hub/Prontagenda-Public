-- Mantém o profissional escolhido durante todo o fluxo do novo agendamento.
-- A agenda continua sendo a fonte da verdade para a disponibilidade.
ALTER TABLE whatsapp_conversas
    ADD COLUMN agendamento_profissional_id_pendente BIGINT DEFAULT NULL
        AFTER agendamento_paciente_nome_pendente,
    ADD KEY idx_whatsapp_conversa_profissional_pendente
        (empresa_id, agendamento_profissional_id_pendente);
