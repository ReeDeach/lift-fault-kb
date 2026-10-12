-- Источник реального случая и уникальный ключ: по нему сид случаев перезапускается без дублей.
-- В источнике указывается тип записи и номер, но не адрес объекта, не номер лифта и не фамилии.

ALTER TABLE cases ADD COLUMN source VARCHAR(190) NULL;

ALTER TABLE cases ADD UNIQUE KEY uq_cases_source (source);
