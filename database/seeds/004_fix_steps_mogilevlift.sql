-- Пошаговые проверки для ремонтов из 003. Шаги переписываются заново при каждом запуске.
-- Если название ремонта в SET написано с опечаткой, @f станет NULL и вставка упадёт: так опечатки не пройдут молча.

DELETE FROM fix_steps WHERE fix_id IN (SELECT id FROM fixes WHERE source LIKE 'РЭ%');

SET @f = (SELECT id FROM fixes WHERE title = 'Проверить питание, заменить автомат или предохранитель');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Измерить напряжение на фазах вводного устройства', NULL),
    (@f, 2, 'Проверить автоматы и предохранители цепей управления и сигнализации', NULL),
    (@f, 3, 'Заменить сработавший автомат или предохранитель', 'Появляется сигнализация, кабина реагирует на кнопки');

SET @f = (SELECT id FROM fixes WHERE title = 'Выровнять длину тяговых канатов');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'На верхней балке кабины проверить зазор между роликом выключателя подвески кабины и лыжей', NULL),
    (@f, 2, 'Выровнять длину канатов гайками на тягах крепления в верхней балке противовеса', NULL),
    (@f, 3, 'При необходимости перепассовать канаты в обойме', NULL),
    (@f, 4, 'Проверить точность остановки на нескольких этажах', 'Точность остановки в пределах ±35 мм');

SET @f = (SELECT id FROM fixes WHERE title = 'Укоротить канат ограничителя скорости');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'В приямке проверить зазор между роликом выключателя ВНУ и поворотной частью натяжного устройства', NULL),
    (@f, 2, 'Укоротить канат ограничителя скорости перепассовкой ветви, подходящей сверху к рычагу механизма включения ловителей', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Отрегулировать отводку двери кабины и ролики замка');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Проверить зазоры между роликами замка и отводкой по значениям из РЭ на привод дверей', NULL),
    (@f, 2, 'Отрегулировать взаимное положение отводки и ролика', NULL),
    (@f, 3, 'Проверить расстояние между щеками отводок и при необходимости отрегулировать', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Проверить выключатель закрытия дверей, при необходимости заменить');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Проверить напряжение на выключателе закрытия дверей', 'При замкнутых контактах напряжение падает до нуля'),
    (@f, 2, 'Проверить регулировку выключателя', NULL),
    (@f, 3, 'Исправить цепь или заменить неисправный выключатель', 'Кабина начинает движение после смыкания створок');

SET @f = (SELECT id FROM fixes WHERE title = 'Отрегулировать замок створок двери шахты');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Отрегулировать работу замка створок двери шахты', NULL),
    (@f, 2, 'При отсутствии кабины на этаже попытаться раздвинуть створки двери шахты', 'Створки не раздвигаются');

SET @f = (SELECT id FROM fixes WHERE title = 'Отрегулировать блок контроля двери шахты');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Отрегулировать блок контроля двери шахты', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Очистить пороги кабины и шахты');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Очистить пороги кабинной и шахтной дверей от посторонних предметов', 'Двери закрываются без самореверсирования');

SET @f = (SELECT id FROM fixes WHERE title = 'Отрегулировать упор или кулачок открывания дверей');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Отрегулировать положение упора или кулачка открывания дверей кабины', 'Створки открываются на полный проём');

SET @f = (SELECT id FROM fixes WHERE title = 'Проверить цепь реверса, при необходимости заменить выключатель');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Проверить цепь питания и сам выключатель реверса', NULL),
    (@f, 2, 'Восстановить цепь, при поломке заменить выключатель', 'При помехе закрытию двери реверсируются');

SET @f = (SELECT id FROM fixes WHERE title = 'Отрегулировать положение шунта точной остановки');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Проверить зазоры между шунтом и выключателем', NULL),
    (@f, 2, 'Отрегулировать положение шунта этажа, на котором кабина проходит мимо', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Заменить ограничитель скорости или его пружину');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Проверить ограничитель скорости по методике РЭ', NULL),
    (@f, 2, 'Заменить ограничитель скорости или его пружину', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Подтянуть крепление башмаков, заменить вкладыши');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Проверить затяжку башмаков кабины и их суммарный зазор', NULL),
    (@f, 2, 'Подтянуть крепление башмаков', NULL),
    (@f, 3, 'Заменить изношенные вкладыши', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Измерить напряжение на фазах и восстановить питание лебёдки');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Измерить напряжение на фазах вводного устройства', 'Между каждыми двумя фазами 380 В, между фазой и нулём 220 В (по РЭ)'),
    (@f, 2, 'Восстановить цепь питания лебёдки', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Проверить изоляцию и заземление, устранить пробой');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Измерить сопротивление изоляции токоведущих частей и проводов', NULL),
    (@f, 2, 'Проверить заземление', NULL),
    (@f, 3, 'Устранить пробой и повреждения изоляции', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Очистить тормозной шкив и накладки от масла');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Осмотреть тормозной шкив и накладки рычагов на наличие масла', NULL),
    (@f, 2, 'Протереть ветошью, смоченной в уайт-спирите, затем сухой ветошью', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Заменить накладки тормоза или тормозной диск');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Измерить толщину накладок рычагов тормоза', 'Для тормоза на быстроходном валу не менее 2 мм (по РЭ 0401К)'),
    (@f, 2, 'Заменить рычаги тормоза или тормозной диск', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Отрегулировать тормоз');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'Измерить воздушный зазор тормоза и сверить с требованием для данного типа тормоза', NULL),
    (@f, 2, 'Отрегулировать тормоз по методике РЭ', NULL),
    (@f, 3, 'Проверить тормозное усилие по методике РЭ', NULL);

SET @f = (SELECT id FROM fixes WHERE title = 'Проверить цепи обратной связи тормоза и пускателя');
INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES
    (@f, 1, 'При отключённом питании проверить подключение проводов КТА (клемма XT2А:18) и КТВ (клемма XT2А:20) в устройстве УЛ', NULL),
    (@f, 2, 'Проверить микропереключатели тормоза А31 и А32', NULL),
    (@f, 3, 'Проверить в ПЧ Hpmont параметры F12.07=10 и F12.08=20', NULL),
    (@f, 4, 'Проверить контакт обратной связи и исправность пускателя, повреждённый пускатель заменить', NULL);
