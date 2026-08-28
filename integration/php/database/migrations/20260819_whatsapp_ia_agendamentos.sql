-- Criação segura de agendamentos originados pelo assistente do WhatsApp.
-- Execute uma única vez e mantenha a escrita desabilitada até validar o fluxo.
-- No servidor PHP, PRONTAGENDA_AI_AGENDAMENTO_WRITE_ENABLED deve permanecer FALSE.
-- Para ativar, são necessárias as duas chaves: variável global TRUE e escrita_ativa=1 por empresa.

CREATE TABLE IF NOT EXISTS whatsapp_ai_agendamento_config (
    empresa_id INT NOT NULL,
    escrita_ativa TINYINT(1) NOT NULL DEFAULT 0,
    validade_minutos INT NOT NULL DEFAULT 10,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (empresa_id),
    CONSTRAINT fk_whatsapp_ai_agendamento_config_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_agendamento_solicitacoes (
    id BIGINT NOT NULL AUTO_INCREMENT,
    solicitacao_uuid CHAR(36) NOT NULL,
    empresa_id INT NOT NULL,
    conversa_id BIGINT NOT NULL,
    profissional_id INT NOT NULL,
    paciente_id INT DEFAULT NULL,
    paciente_nome VARCHAR(255) NOT NULL,
    telefone VARCHAR(30) NOT NULL,
    data_hora_inicio DATETIME NOT NULL,
    data_hora_fim DATETIME NOT NULL,
    duracao_minutos INT NOT NULL,
    status ENUM('aguardando_confirmacao','confirmando','confirmado','expirado','conflito','cancelado','falhou') NOT NULL DEFAULT 'aguardando_confirmacao',
    idempotency_key CHAR(64) NOT NULL,
    confirmacao_token_hash CHAR(64) NOT NULL,
    mensagem_origem_id BIGINT DEFAULT NULL,
    agendamento_id INT DEFAULT NULL,
    expira_em DATETIME NOT NULL,
    confirmado_em DATETIME DEFAULT NULL,
    erro_codigo VARCHAR(80) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_whatsapp_agendamento_uuid (solicitacao_uuid),
    UNIQUE KEY uk_whatsapp_agendamento_idempotencia (idempotency_key),
    KEY idx_whatsapp_agendamento_conversa (empresa_id, conversa_id, status, criado_em),
    KEY idx_whatsapp_agendamento_slot (empresa_id, profissional_id, data_hora_inicio),
    KEY idx_whatsapp_agendamento_expiracao (status, expira_em),
    CONSTRAINT fk_whatsapp_agendamento_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_agendamento_conversa FOREIGN KEY (conversa_id) REFERENCES whatsapp_conversas(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_agendamento_profissional FOREIGN KEY (profissional_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_agendamento_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE agendamentos
    ADD COLUMN origem_agendamento VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER empresa_id,
    ADD COLUMN whatsapp_conversa_id BIGINT DEFAULT NULL AFTER origem_agendamento,
    ADD COLUMN whatsapp_solicitacao_id BIGINT DEFAULT NULL AFTER whatsapp_conversa_id,
    ADD KEY idx_agendamentos_origem (empresa_id, origem_agendamento, criado_em),
    ADD KEY idx_agendamentos_whatsapp_conversa (whatsapp_conversa_id),
    ADD UNIQUE KEY uk_agendamentos_whatsapp_solicitacao (whatsapp_solicitacao_id);
