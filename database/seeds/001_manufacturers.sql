-- Производители. Сид идемпотентный: повторный запуск ничего не дублирует.

INSERT INTO manufacturers (name, slug) VALUES
    ('Могилёвлифтмаш', 'mogilevlift'),
    ('Гомельлифт', 'gomellift'),
    ('Doppler', 'doppler'),
    ('Otis', 'otis')
AS new
ON DUPLICATE KEY UPDATE name = new.name;
