-- Serviços opcionais contratados separadamente da assinatura principal.
-- A IA do WhatsApp é vinculada à empresa, e não a um assento de usuário.

CREATE TABLE IF NOT EXISTS servicos_adicionais (
    id INT NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    nome VARCHAR(150) NOT NULL,
    descricao VARCHAR(500) DEFAULT NULL,
    valor_centavos INT UNSIGNED NOT NULL DEFAULT 0,
    moeda CHAR(3) NOT NULL DEFAULT 'BRL',
    ciclo ENUM('mensal','avulso') NOT NULL DEFAULT 'mensal',
    limite_mensagens_mes INT UNSIGNED DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_servicos_adicionais_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assinaturas_servicos (
    id BIGINT NOT NULL AUTO_INCREMENT,
    empresa_id INT NOT NULL,
    servico_id INT NOT NULL,
    status ENUM('pendente','trial','ativa','suspensa','cancelada','expirada') NOT NULL DEFAULT 'pendente',
    data_inicio DATE DEFAULT NULL,
    data_fim DATE DEFAULT NULL,
    proxima_cobranca DATETIME DEFAULT NULL,
    renovacao_automatica TINYINT(1) NOT NULL DEFAULT 0,
    gateway VARCHAR(30) DEFAULT NULL,
    provider_ref VARCHAR(191) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_assinaturas_servicos_empresa_servico (empresa_id, servico_id),
    KEY idx_assinaturas_servicos_status (status, data_fim),
    KEY idx_assinaturas_servicos_provider (gateway, provider_ref),
    CONSTRAINT fk_assinaturas_servicos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assinaturas_servicos_servico FOREIGN KEY (servico_id) REFERENCES servicos_adicionais(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assinaturas_servicos_pedidos (
    id BIGINT NOT NULL AUTO_INCREMENT,
    empresa_id INT NOT NULL,
    servico_id INT NOT NULL,
    valor_centavos INT UNSIGNED NOT NULL,
    moeda CHAR(3) NOT NULL DEFAULT 'BRL',
    status ENUM('pendente','pago','cancelado','falhou','reembolsado') NOT NULL DEFAULT 'pendente',
    gateway VARCHAR(30) NOT NULL DEFAULT 'mercadopago',
    provider_ref VARCHAR(191) DEFAULT NULL,
    checkout_url TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_servicos_pedidos_empresa_status (empresa_id, status, criado_em),
    KEY idx_servicos_pedidos_provider (gateway, provider_ref),
    CONSTRAINT fk_servicos_pedidos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_servicos_pedidos_servico FOREIGN KEY (servico_id) REFERENCES servicos_adicionais(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO servicos_adicionais
    (slug, nome, descricao, valor_centavos, moeda, ciclo, limite_mensagens_mes, ativo)
VALUES
    ('ia_whatsapp', 'IA para WhatsApp',
     'Atendimento contextual e agendamento inteligente pelo WhatsApp.',
     0, 'BRL', 'mensal', NULL, 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    descricao = VALUES(descricao),
    atualizado_em = CURRENT_TIMESTAMP;

-- Transição segura: empresas que já usam a atendente recebem 30 dias para
-- contratar o adicional, evitando interrupção imediata após a implantação.
INSERT INTO assinaturas_servicos
    (empresa_id, servico_id, status, data_inicio, data_fim, renovacao_automatica)
SELECT w.empresa_id, s.id, 'trial', CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY), 0
  FROM whatsapp_atendente_configuracoes w
 INNER JOIN servicos_adicionais s ON s.slug = 'ia_whatsapp'
 WHERE w.ativo = 1
ON DUPLICATE KEY UPDATE empresa_id = VALUES(empresa_id);
