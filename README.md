# lift-fault-kb

[![CI](https://github.com/ReeDeach/lift-fault-kb/actions/workflows/ci.yml/badge.svg)](https://github.com/ReeDeach/lift-fault-kb/actions/workflows/ci.yml)

База знаний по неисправностям лифтов: поиск по коду, полнотекстовый поиск по описанию симптомов и ИИ-диагностика с проверкой по базе.

> **Важно.** Проект — справочник. Он не заменяет документацию производителя,
> инструкции по эксплуатации и правила техники безопасности. Лифт — это
> безопасность людей: работы на оборудовании выполняет только обученный и
> допущенный персонал. Ответы ИИ-диагностики носят справочный характер.

## Стек

- PHP 8.3, MySQL 8
- Поиск: Manticore — Sphinx-совместимый движок (SphinxQL)
- Фронтенд: JavaScript, jQuery и CSS без сборщиков
- Docker, Composer, PHPUnit, GitHub Actions
- ИИ: запрос к API языковой модели, ключ в `.env`

## Запуск

```bash
cp .env.example .env        # заполните пароли
docker compose up -d --build
docker compose exec php composer install
curl http://localhost:8080/api/health
```

Тесты:

```bash
docker compose exec php composer test
```

## Статус

- [x] Этап 1. Каркас: Docker, Composer, роутер, CI
- [ ] Этап 2. Миграции и данные
- [ ] Этап 3. REST API и тесты
- [ ] Этап 4. Поиск через Manticore
- [ ] Этап 5. Оптимизация (индексы, `EXPLAIN`)
- [ ] Этап 6. ИИ-диагностика
- [ ] Этап 7. Интерфейс на jQuery
- [ ] Этап 8. Swagger UI и скриншоты

## Данные

Все записи в базе составлены автором по собственному опыту. Таблицы
неисправностей из заводских руководств защищены авторским правом и в
репозиторий не копируются.

## Лицензия

MIT
