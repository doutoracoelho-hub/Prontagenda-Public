-- Intervalo/duração exclusivo dos agendamentos criados pela IA.
-- Copia o valor atual para não alterar o comportamento após a implantação.

ALTER TABLE configuracoes_agenda_usuario
    ADD COLUMN intervalo_ia_minutos INT NULL AFTER intervalo_minutos;

UPDATE configuracoes_agenda_usuario
SET intervalo_ia_minutos = intervalo_minutos
WHERE intervalo_ia_minutos IS NULL;

ALTER TABLE configuracoes_agenda_usuario
    MODIFY COLUMN intervalo_ia_minutos INT NOT NULL DEFAULT 30;
