-- Чья заявка, даже если лифт ещё не определён: по адресу несколько лифтов, но все у одного
-- механика. Тогда механик видит заявку сразу и сам выбирает лифт, не дожидаясь мастера.

ALTER TABLE repair_calls
    ADD COLUMN mechanic_id INT UNSIGNED NULL AFTER lift_id,
    ADD KEY idx_repair_calls_mechanic (report_id, mechanic_id),
    ADD CONSTRAINT fk_repair_calls_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics (id) ON DELETE SET NULL;
