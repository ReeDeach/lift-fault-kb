-- Демо: общий чат и заявки на запчасти. Люди, адреса и переписка ВЫМЫШЛЕНЫ.
-- Время считается от момента загрузки, поэтому переписка всегда «свежая».
-- Соглашения раннера: в тексте нет точки с запятой и одинарных кавычек.

SET @petrov = (SELECT id FROM mechanics WHERE name = 'Петров А. С.');
SET @sidorenko = (SELECT id FROM mechanics WHERE name = 'Сидоренко В. Н.');
SET @kovalchuk = (SELECT id FROM mechanics WHERE name = 'Ковальчук Д. Ю.');
SET @melnik = (SELECT id FROM mechanics WHERE name = 'Мельник И. Г.');
SET @gonchar = (SELECT id FROM mechanics WHERE name = 'Гончар Р. В.');

-- Общий чат
INSERT INTO messages (request_id, author_login, author_id, as_master, body, created_at) VALUES
    (NULL, 'demo:seed', NULL, 1, 'Доброе утро. До пятницы сдаём журналы ТО за месяц.', NOW() - INTERVAL 2 DAY - INTERVAL 5 HOUR),
    (NULL, 'demo:seed', @sidorenko, 0, 'Принял.', NOW() - INTERVAL 2 DAY - INTERVAL 290 MINUTE),
    (NULL, 'demo:seed', @melnik, 0, 'У кого есть запасной ролик отводки на 0401К? Верну, как придёт заказ.', NOW() - INTERVAL 2 DAY - INTERVAL 3 HOUR),
    (NULL, 'demo:seed', @kovalchuk, 0, 'Есть один. Заезжай после обеда, я на Вокзальной.', NOW() - INTERVAL 2 DAY - INTERVAL 170 MINUTE),
    (NULL, 'demo:seed', @melnik, 0, 'Спасибо, буду к двум.', NOW() - INTERVAL 2 DAY - INTERVAL 165 MINUTE),
    (NULL, 'demo:seed', @gonchar, 0, 'Кто сталкивался: УЛ выдаёт 45 только при пуске вниз. Тормоз отрегулирован, зазоры в норме.', NOW() - INTERVAL 1 DAY - INTERVAL 4 HOUR),
    (NULL, 'demo:seed', @petrov, 0, 'Глянь блок-контакт пускателя направления вниз. У меня было так же, контакт подгорел и не замыкался.', NOW() - INTERVAL 1 DAY - INTERVAL 230 MINUTE),
    (NULL, 'demo:seed', @gonchar, 0, 'Точно он. Поменял пускатель, 45 больше не выскакивает. Спасибо!', NOW() - INTERVAL 1 DAY - INTERVAL 1 HOUR),
    (NULL, 'demo:seed', NULL, 1, 'Пришли контакты ВКЗ и ремни привода дверей. Кто заказывал, забирайте в мастерской.', NOW() - INTERVAL 3 HOUR),
    (NULL, 'demo:seed', @sidorenko, 0, 'Заберу завтра с утра.', NOW() - INTERVAL 25 MINUTE);

-- Заявка 1: срочная, заказана
INSERT INTO part_requests (author_id, lift_id, title, quantity, urgent, status, created_at, updated_at)
SELECT @petrov, l.id, 'Контакт ВКЗ (контроль закрытия дверей)', '2 шт', 1, 'ordered', NOW() - INTERVAL 1 DAY - INTERVAL 6 HOUR, NOW() - INTERVAL 1 DAY - INTERVAL 2 HOUR
FROM lifts l WHERE l.address = 'Садовая 12' AND l.lift_no = 1;
SET @r1 = LAST_INSERT_ID();
INSERT INTO messages (request_id, author_login, author_id, as_master, body, status, created_at) VALUES
    (@r1, 'demo:seed', @petrov, 0, 'Подгорел контакт, лифт работает через раз: код 50 после закрытия дверей.', 'new', NOW() - INTERVAL 1 DAY - INTERVAL 6 HOUR),
    (@r1, 'demo:seed', NULL, 1, 'Принял.', 'accepted', NOW() - INTERVAL 1 DAY - INTERVAL 5 HOUR),
    (@r1, 'demo:seed', NULL, 1, 'Заказал у поставщика, обещают в четверг.', 'ordered', NOW() - INTERVAL 1 DAY - INTERVAL 2 HOUR);

-- Заявка 2: пришла, можно забирать
INSERT INTO part_requests (author_id, lift_id, title, quantity, urgent, status, created_at, updated_at)
SELECT @sidorenko, l.id, 'Ремень привода дверей кабины', '1 шт', 0, 'ready', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 3 HOUR
FROM lifts l WHERE l.address = 'Набережная 18' AND l.lift_no = 3;
SET @r2 = LAST_INSERT_ID();
INSERT INTO messages (request_id, author_login, author_id, as_master, body, status, created_at) VALUES
    (@r2, 'demo:seed', @sidorenko, 0, 'Ремень растянулся, двери закрываются рывками.', 'new', NOW() - INTERVAL 4 DAY),
    (@r2, 'demo:seed', NULL, 1, 'Заказал.', 'ordered', NOW() - INTERVAL 3 DAY),
    (@r2, 'demo:seed', NULL, 1, 'Пришёл, лежит в мастерской на полке.', 'ready', NOW() - INTERVAL 3 HOUR);

-- Заявка 3: новая, ещё без ответа
INSERT INTO part_requests (author_id, lift_id, title, quantity, urgent, status, created_at, updated_at)
SELECT @kovalchuk, l.id, 'Ролик отводки 0401К', '4 шт', 0, 'new', NOW() - INTERVAL 40 MINUTE, NOW() - INTERVAL 40 MINUTE
FROM lifts l WHERE l.address = 'Северная 44' AND l.lift_no = 1;
SET @r3 = LAST_INSERT_ID();
INSERT INTO messages (request_id, author_login, author_id, as_master, body, status, created_at) VALUES
    (@r3, 'demo:seed', @kovalchuk, 0, 'Один отдал Мельнику, на участке ещё два лифта с износом роликов.', 'new', NOW() - INTERVAL 40 MINUTE);

-- Заявка 4: выдана
INSERT INTO part_requests (author_id, lift_id, title, quantity, urgent, status, created_at, updated_at)
VALUES (@petrov, NULL, 'Лампы освещения кабины, светодиодные 220 В', '6 шт', 0, 'issued', NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 6 DAY);
SET @r4 = LAST_INSERT_ID();
INSERT INTO messages (request_id, author_login, author_id, as_master, body, status, created_at) VALUES
    (@r4, 'demo:seed', @petrov, 0, 'На запас.', 'new', NOW() - INTERVAL 9 DAY),
    (@r4, 'demo:seed', NULL, 1, 'Пришли.', 'ready', NOW() - INTERVAL 7 DAY),
    (@r4, 'demo:seed', @petrov, 0, 'Забрал, спасибо.', 'issued', NOW() - INTERVAL 6 DAY);

-- Заявка 5: принята, мастер уточняет
INSERT INTO part_requests (author_id, lift_id, title, quantity, urgent, status, created_at, updated_at)
SELECT @sidorenko, l.id, 'Плата ПСК для УЛ ФБИР.484430.013', '1 шт', 1, 'accepted', NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 2 HOUR
FROM lifts l WHERE l.address = 'Зелёная 30' AND l.lift_no = 2;
SET @r5 = LAST_INSERT_ID();
INSERT INTO messages (request_id, author_login, author_id, as_master, body, status, created_at) VALUES
    (@r5, 'demo:seed', @sidorenko, 0, 'Подгорела дорожка у разъёма платы, лифт стоит. Ждём плату.', 'new', NOW() - INTERVAL 5 HOUR),
    (@r5, 'demo:seed', NULL, 1, 'Принял, уточняю цену и срок у поставщика, напишу сюда.', 'accepted', NOW() - INTERVAL 2 HOUR);
