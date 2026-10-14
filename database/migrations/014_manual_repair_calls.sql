-- Заявка на ремонт от мастера лично (не из 1С): без отчёта и, если у лифта нет номера 1С,
-- без номера. created_by — кто из мастеров оставил заявку, NULL — заявка из отчёта 1С.

ALTER TABLE repair_calls
    MODIFY report_id INT UNSIGNED NULL,
    MODIFY lift_reg VARCHAR(32) NULL,
    ADD COLUMN created_by VARCHAR(190) NULL AFTER journal_entry_id,
    ADD KEY idx_repair_calls_manual (created_by, created_at);
