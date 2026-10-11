-- Источник сведений: документ или опыт, на котором основана запись.
-- Нужен, чтобы любой ответ базы и ИИ-диагностики можно было проверить по первоисточнику.

ALTER TABLE symptoms ADD COLUMN source VARCHAR(255) NULL;

ALTER TABLE causes ADD COLUMN source VARCHAR(255) NULL;

ALTER TABLE fixes ADD COLUMN source VARCHAR(255) NULL;

ALTER TABLE fault_codes ADD COLUMN source VARCHAR(255) NULL;
