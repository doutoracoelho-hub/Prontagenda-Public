-- Unifica conversas do mesmo telefone brasileiro e impede novas duplicacoes.
-- A chave remove formatacao, codigo 55 opcional e a diferenca do nono digito.
-- Execute uma unica vez, preferencialmente com backup recente do banco.

SET @whatsapp_tem_telefone_chave = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'whatsapp_conversas'
       AND COLUMN_NAME = 'telefone_chave'
);
SET @whatsapp_sql_coluna = IF(
    @whatsapp_tem_telefone_chave = 0,
    'ALTER TABLE whatsapp_conversas ADD COLUMN telefone_chave VARCHAR(20) NULL AFTER telefone',
    'SELECT 1'
);
PREPARE whatsapp_stmt_coluna FROM @whatsapp_sql_coluna;
EXECUTE whatsapp_stmt_coluna;
DEALLOCATE PREPARE whatsapp_stmt_coluna;

UPDATE whatsapp_conversas
SET telefone_chave = CASE
    WHEN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '') = ''
      THEN NULL
    WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 13
         AND LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 2) = '55'
         AND SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 5, 1) = '9'
      THEN CONCAT(
          LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 4),
          SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 6)
      )
    WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 12
         AND LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 2) = '55'
      THEN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')
    WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 11
         AND SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 3, 1) = '9'
      THEN CONCAT(
          '55',
          LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 2),
          SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 4)
      )
    WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 10
      THEN CONCAT('55', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''))
    ELSE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')
END;

DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_conversa_merge;
CREATE TEMPORARY TABLE tmp_whatsapp_conversa_merge (
    duplicada_id BIGINT NOT NULL PRIMARY KEY,
    principal_id BIGINT NOT NULL,
    KEY idx_tmp_whatsapp_principal (principal_id)
) ENGINE=InnoDB;

INSERT INTO tmp_whatsapp_conversa_merge (duplicada_id, principal_id)
SELECT c.id, grupos.principal_id
FROM whatsapp_conversas c
JOIN (
    SELECT empresa_id, telefone_chave, MAX(id) AS principal_id
    FROM whatsapp_conversas
    WHERE telefone_chave <> ''
    GROUP BY empresa_id, telefone_chave
    HAVING COUNT(*) > 1
) grupos
  ON grupos.empresa_id = c.empresa_id
 AND grupos.telefone_chave = c.telefone_chave
WHERE c.id <> grupos.principal_id;

-- O MySQL nao permite referenciar a mesma tabela temporaria duas vezes no
-- mesmo UNION (erro 1137). Materializamos o escopo uma unica vez.
DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_conversa_escopo;
CREATE TEMPORARY TABLE tmp_whatsapp_conversa_escopo (
    principal_id BIGINT NOT NULL,
    conversa_id BIGINT NOT NULL PRIMARY KEY,
    KEY idx_tmp_whatsapp_escopo_principal (principal_id)
) ENGINE=InnoDB;

INSERT INTO tmp_whatsapp_conversa_escopo (principal_id, conversa_id)
SELECT principal_id, duplicada_id
FROM tmp_whatsapp_conversa_merge;

INSERT IGNORE INTO tmp_whatsapp_conversa_escopo (principal_id, conversa_id)
SELECT principal_id, principal_id
FROM tmp_whatsapp_conversa_merge;

-- Se houver mais de uma fila ativa, conserva somente o atendimento mais recente.
DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_atendimento_ativo;
CREATE TEMPORARY TABLE tmp_whatsapp_atendimento_ativo (
    principal_id BIGINT NOT NULL PRIMARY KEY,
    atendimento_id BIGINT NOT NULL
) ENGINE=InnoDB;

INSERT INTO tmp_whatsapp_atendimento_ativo (principal_id, atendimento_id)
SELECT agrupado.principal_id, MAX(ah.id)
FROM tmp_whatsapp_conversa_escopo agrupado
JOIN whatsapp_atendimentos_humanos ah ON ah.conversa_id = agrupado.conversa_id
WHERE ah.status IN ('aguardando', 'em_atendimento')
GROUP BY agrupado.principal_id;

UPDATE whatsapp_atendimentos_humanos ah
JOIN tmp_whatsapp_conversa_escopo agrupado ON agrupado.conversa_id = ah.conversa_id
JOIN tmp_whatsapp_atendimento_ativo ativo ON ativo.principal_id = agrupado.principal_id
SET ah.status = 'encerrado',
    ah.encerrado_em = COALESCE(ah.encerrado_em, NOW()),
    ah.atualizado_em = NOW()
WHERE ah.status IN ('aguardando', 'em_atendimento')
  AND ah.id <> ativo.atendimento_id;

UPDATE whatsapp_mensagens m
JOIN tmp_whatsapp_conversa_merge mapa ON mapa.duplicada_id = m.conversa_id
SET m.conversa_id = mapa.principal_id;

UPDATE whatsapp_atendimentos_humanos ah
JOIN tmp_whatsapp_conversa_merge mapa ON mapa.duplicada_id = ah.conversa_id
SET ah.conversa_id = mapa.principal_id;

UPDATE whatsapp_atendente_decisoes d
JOIN tmp_whatsapp_conversa_merge mapa ON mapa.duplicada_id = d.conversa_id
SET d.conversa_id = mapa.principal_id;

UPDATE whatsapp_agendamento_solicitacoes s
JOIN tmp_whatsapp_conversa_merge mapa ON mapa.duplicada_id = s.conversa_id
SET s.conversa_id = mapa.principal_id;

UPDATE agendamentos a
JOIN tmp_whatsapp_conversa_merge mapa ON mapa.duplicada_id = a.whatsapp_conversa_id
SET a.whatsapp_conversa_id = mapa.principal_id;

UPDATE whatsapp_jobs j
JOIN tmp_whatsapp_conversa_merge mapa ON mapa.duplicada_id = j.conversa_id
SET j.conversa_id = mapa.principal_id;

-- Recupera dados uteis que possam existir apenas na conversa anterior.
UPDATE whatsapp_conversas principal
JOIN (
    SELECT mapa.principal_id,
           MAX(duplicada.paciente_id) AS paciente_id,
           MAX(duplicada.agendamento_id_ativo) AS agendamento_id_ativo,
           MAX(duplicada.expira_em) AS expira_em
    FROM tmp_whatsapp_conversa_merge mapa
    JOIN whatsapp_conversas duplicada ON duplicada.id = mapa.duplicada_id
    GROUP BY mapa.principal_id
) dados ON dados.principal_id = principal.id
SET principal.paciente_id = COALESCE(principal.paciente_id, dados.paciente_id),
    principal.agendamento_id_ativo = COALESCE(principal.agendamento_id_ativo, dados.agendamento_id_ativo),
    principal.expira_em = CASE
        WHEN principal.expira_em IS NULL AND dados.expira_em IS NULL THEN NULL
        ELSE GREATEST(
            COALESCE(principal.expira_em, '1970-01-01 00:00:00'),
            COALESCE(dados.expira_em, '1970-01-01 00:00:00')
        )
    END,
    principal.atualizado_em = NOW();

DELETE duplicada
FROM whatsapp_conversas duplicada
JOIN tmp_whatsapp_conversa_merge mapa ON mapa.duplicada_id = duplicada.id;

-- Recalcula os ponteiros depois de mover todo o historico.
UPDATE whatsapp_conversas c
SET c.ultima_mensagem_inbound_id = (
        SELECT MAX(m.id) FROM whatsapp_mensagens m
        WHERE m.empresa_id = c.empresa_id AND m.conversa_id = c.id AND m.direction = 'inbound'
    ),
    c.ultima_mensagem_outbound_id = (
        SELECT MAX(m.id) FROM whatsapp_mensagens m
        WHERE m.empresa_id = c.empresa_id AND m.conversa_id = c.id AND m.direction = 'outbound'
    ),
    c.ultima_interacao_em = COALESCE((
        SELECT MAX(m.criado_em) FROM whatsapp_mensagens m
        WHERE m.empresa_id = c.empresa_id AND m.conversa_id = c.id
    ), c.ultima_interacao_em),
    c.atualizado_em = NOW();

ALTER TABLE whatsapp_conversas
    MODIFY COLUMN telefone_chave VARCHAR(20)
    GENERATED ALWAYS AS (
        CASE
            WHEN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '') = ''
              THEN NULL
            WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 13
                 AND LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 2) = '55'
                 AND SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 5, 1) = '9'
              THEN CONCAT(LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 4), SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 6))
            WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 12
                 AND LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 2) = '55'
              THEN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')
            WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 11
                 AND SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 3, 1) = '9'
              THEN CONCAT('55', LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 2), SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), 4))
            WHEN LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')) = 10
              THEN CONCAT('55', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''))
            ELSE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(telefone, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')
        END
    ) STORED,
    ADD UNIQUE KEY uk_whatsapp_conversa_empresa_telefone_chave (empresa_id, telefone_chave);

DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_atendimento_ativo;
DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_conversa_escopo;
DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_conversa_merge;
