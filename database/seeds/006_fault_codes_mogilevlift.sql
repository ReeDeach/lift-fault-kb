-- Коды неисправностей, которые руководства называют прямо.
-- Оба кода описаны в методике контроля колодок тормоза РЭ 0401К. Значение E36 сверено с таблицей ошибок ПЧ Hpmont HD5L.

INSERT INTO fault_codes (model_id, code, title, description, is_safety_related, source)
SELECT m.id, '45', 'УЛ: ошибка контроля колодок тормоза',
    'Ошибка устройства управления лифтом. По методике РЭ при отключённом проводе КТА или КТВ после пуска вверх в режиме МП2 через 2 секунды в УЛ фиксируется ошибка 45, одновременно в ПЧ Hpmont появляется E36, кабина останавливается.',
    1,
    'РЭ 0401К (методика контроля колодок тормоза)'
FROM models m
JOIN manufacturers f ON f.id = m.manufacturer_id
WHERE f.slug = 'mogilevlift' AND m.name = '0401К'
ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), is_safety_related = VALUES(is_safety_related), source = VALUES(source);

INSERT INTO fault_codes (model_id, code, title, description, is_safety_related, source)
SELECT m.id, 'E36', 'ПЧ Hpmont: ошибка обратной связи пускателя (контактора) или тормоза',
    'Код преобразователя частоты Hpmont HD5L. Таблица ошибок ПЧ называет причины: повреждён пускатель, неверно подключён контакт обратной связи. В паре с ошибкой 45 в УЛ указывает на цепь контроля колодок тормоза.',
    1,
    'РЭ ПЧ Hpmont HD5L (таблица ошибок), РЭ 0401К (методика контроля колодок тормоза)'
FROM models m
JOIN manufacturers f ON f.id = m.manufacturer_id
WHERE f.slug = 'mogilevlift' AND m.name = '0401К'
ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), is_safety_related = VALUES(is_safety_related), source = VALUES(source);

INSERT IGNORE INTO fault_code_symptoms (fault_code_id, symptom_id)
SELECT fc.id, s.id
FROM fault_codes fc
JOIN models m ON m.id = fc.model_id
JOIN symptoms s ON s.title = 'Остановка с ошибкой контроля тормоза или пускателя (УЛ 45, ПЧ E36)'
WHERE m.name = '0401К' AND fc.code IN ('45', 'E36');
