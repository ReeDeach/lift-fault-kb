-- Демо: отчёт 1С о заявках на ремонт за прошедшие сутки. Номера лифтов, адреса и пометки ВЫМЫШЛЕНЫ,
-- коды — настоящие коды УЛ из базы. Две заявки оставлены без лифта: на показе мастер выбирает лифт.
-- Соглашения раннера: точка с запятой только внутри строки, не в конце.

UPDATE lifts SET reg_number = '3105н' WHERE address = 'Садовая 12' AND lift_no = 1;
UPDATE lifts SET reg_number = '4412н' WHERE address = 'Набережная 18' AND lift_no = 3;
UPDATE lifts SET reg_number = '5207н' WHERE address = 'Северная 44' AND lift_no = 1;
UPDATE lifts SET reg_number = '7731н' WHERE address = 'Вокзальная 1' AND lift_no = 2;
UPDATE lifts SET reg_number = '2286н' WHERE address = 'Парковая 2' AND lift_no = 1;

INSERT INTO repair_reports (period_from, period_to, section, file_name, uploaded_by)
VALUES (CURDATE() - INTERVAL 1 DAY + INTERVAL 8 HOUR, CURDATE() + INTERVAL 8 HOUR, 'УЧ.ДЕМО-УЧАСТОК', 'демо-отчёт.xlsx', 'demo:seed');
SET @rep = LAST_INSERT_ID();
SET @d = CURDATE() - INTERVAL 1 DAY;

INSERT INTO repair_calls (report_id, lift_reg, address, floor, started_at, finished_at, stopped, stops_30d, info, codes, kind, lift_id) VALUES
    (@rep, '3105н', 'САДОВАЯ 12 п.1', '4', @d + INTERVAL 1109 MINUTE, @d + INTERVAL 1135 MINUTE, 0, 8, '49 MLM', '49', 'fault',
        (SELECT id FROM lifts WHERE reg_number = '3105н')),
    (@rep, '3105н', 'САДОВАЯ 12 п.1', '4', @d + INTERVAL 1280 MINUTE, NULL, 1, 8, '49 MLM;44 C6 MLM', '49 44 C6', 'fault',
        (SELECT id FROM lifts WHERE reg_number = '3105н')),
    (@rep, '4412н', 'НАБЕРЕЖНАЯ 18 п.3', '9', @d + INTERVAL 1145 MINUTE, @d + INTERVAL 1190 MINUTE, 0, 4, '45 MLM;44 C0 MLM', '45 44 C0', 'fault',
        (SELECT id FROM lifts WHERE reg_number = '4412н')),
    (@rep, '5207н', 'СЕВЕРНАЯ 44 п.1', '1', @d + INTERVAL 888 MINUTE, @d + INTERVAL 990 MINUTE, 0, 1, 'Посторон.предмет', NULL, 'fault',
        (SELECT id FROM lifts WHERE reg_number = '5207н')),
    (@rep, '7731н', 'ВОКЗАЛЬНАЯ 1 п.2', '1', @d + INTERVAL 540 MINUTE, @d + INTERVAL 990 MINUTE, 1, 1, 'ТО2', NULL, 'planned',
        (SELECT id FROM lifts WHERE reg_number = '7731н')),
    (@rep, '2286н', 'ПАРКОВАЯ 2', '1', @d + INTERVAL 1230 MINUTE, @d + INTERVAL 1330 MINUTE, 0, 3, '48 MLM;Регулировка замка.', '48', 'fault',
        (SELECT id FROM lifts WHERE reg_number = '2286н')),
    (@rep, '6115н', 'ЦВЕТОЧНАЯ 21 п.2', '5', @d + INTERVAL 1347 MINUTE, @d + INTERVAL 1425 MINUTE, 0, 1, '99 MLM;АПСиПДЗ сброс', '99', 'fault', NULL),
    (@rep, '6613н', 'СОЛНЕЧНАЯ 5', '1', CURDATE() + INTERVAL 464 MINUTE, NULL, 0, 2, 'Проверить раб.лифта. Частые застр.', NULL, 'fault', NULL);
