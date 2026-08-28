ALTER TABLE whatsapp_mensagens
    ADD COLUMN origem_envio VARCHAR(30) NULL AFTER direction,
    ADD KEY idx_whatsapp_mensagens_auditoria
        (empresa_id, profissional_id, origem_envio, criado_em);


