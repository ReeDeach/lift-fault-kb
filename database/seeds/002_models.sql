-- Модели Могилёвлифтмаш по двум руководствам по эксплуатации.
-- Тип подвеса (прямой или полиспастный) здесь намеренно не указан: уточняется автором.

INSERT INTO models (manufacturer_id, name, controller, notes)
SELECT m.id, '0401К', 'УЛ, ПЧ Hpmont',
    'Пассажирский лифт с машинным помещением, 225–1000 кг, редукторная или безредукторная лебёдка. РЭ 0401К.00.00.000.'
FROM manufacturers m
WHERE m.slug = 'mogilevlift'
ON DUPLICATE KEY UPDATE controller = VALUES(controller), notes = VALUES(notes);

INSERT INTO models (manufacturer_id, name, controller, notes)
SELECT m.id, 'ПВБ1010Г', NULL,
    'Пассажирский лифт 1000 кг, безредукторная лебёдка, верхнее малогабаритное машинное помещение, скорость 1,0 и 1,6 м/с. РЭ ПВБ1010Г.00.00.000.'
FROM manufacturers m
WHERE m.slug = 'mogilevlift'
ON DUPLICATE KEY UPDATE notes = VALUES(notes);
