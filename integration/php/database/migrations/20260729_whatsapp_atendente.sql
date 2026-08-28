-- As tabelas operacionais da atendente já existem no banco.
-- Esta migração adiciona somente o histórico auditável das decisões automáticas.

CREATE TABLE IF NOT EXISTS whatsapp_atendente_decisoes (
    id BIGINT NOT NULL AUTO_INCREMENT,
    empresa_id INT NOT NULL,
    conversa_id BIGINT NOT NULL,
    mensagem_id BIGINT DEFAULT NULL,
    intencao VARCHAR(80) NOT NULL,
    decisao ENUM('responder','encaminhar','ignorar') NOT NULL,
    resposta TEXT DEFAULT NULL,
    metadata_json LONGTEXT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_decisoes_empresa_conversa (empresa_id, conversa_id, criado_em),
    KEY idx_whatsapp_decisoes_mensagem (mensagem_id),
    CONSTRAINT fk_whatsapp_decisoes_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_decisoes_conversa
        FOREIGN KEY (conversa_id) REFERENCES whatsapp_conversas(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_decisoes_mensagem
        FOREIGN KEY (mensagem_id) REFERENCES whatsapp_mensagens(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

