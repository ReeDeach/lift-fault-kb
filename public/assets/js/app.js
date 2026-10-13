/*
 * Lift Fault Terminal: интерфейс справочника на jQuery.
 * Экраны переключаются по адресу после #: #/codes, #/journal, #/ai.
 * Все данные приходят из REST API (/api/...), разметка — классы из app.css.
 */
(function ($) {
    'use strict';

    var PAGE_LIMIT = 100;
    var OUTCOMES = {
        resolved: { text: 'решено', cls: 'lft-badge--ok' },
        partial: { text: 'частично', cls: 'lft-badge--warn' },
        unresolved: { text: 'не решено', cls: 'lft-badge--alarm' }
    };

    var $view = $('#app-view');
    var modelsRequest = null;
    var listCache = { key: null, items: [], total: 0 };
    var renderToken = 0;

    // ---------- Вспомогательное ----------

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icon(name, extra) {
        return '<span class="lft-icon lft-icon--' + name + (extra ? ' ' + extra : '') + '" aria-hidden="true"></span>';
    }

    function formatDate(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
        return m ? m[3] + '.' + m[2] + '.' + m[1] : esc(iso);
    }

    function hashFor(page, params) {
        var q = new URLSearchParams();
        $.each(params || {}, function (key, value) {
            if (value !== null && value !== undefined && value !== '') {
                q.set(key, value);
            }
        });
        var query = q.toString();
        return '#/' + page + (query ? '?' + query : '');
    }

    function go(page, params, replace) {
        var hash = hashFor(page, params);
        if (replace) {
            history.replaceState(null, '', hash);
            render();
        } else {
            location.hash = hash;
        }
    }

    function currentRoute() {
        var raw = location.hash.replace(/^#\/?/, '');
        var parts = raw.split('?');
        var params = {};
        new URLSearchParams(parts[1] || '').forEach(function (value, key) {
            params[key] = value;
        });
        return { page: parts[0] || 'codes', params: params };
    }

    function setStatus(ok) {
        $('#app-status')
            .toggleClass('lft-badge--ok', ok)
            .toggleClass('lft-badge--alarm', !ok)
            .html(icon(ok ? 'ok' : 'fault', 'lft-icon--12') + (ok ? 'база онлайн' : 'нет связи'));
        $('#app-system').text(ok ? 'stable' : 'unstable').attr('class', ok ? 'ok' : 'app-bad');
    }

    // Запрос к API. Ошибка приходит как {status, message, fields}.
    function api(path, params) {
        var data = {};
        $.each(params || {}, function (key, value) {
            if (value !== null && value !== undefined && value !== '') {
                data[key] = value;
            }
        });
        return $.ajax({ url: path, data: data, dataType: 'json', cache: true }).then(
            function (data) {
                setStatus(true);
                return data;
            },
            function (xhr) {
                var body = xhr.responseJSON && xhr.responseJSON.error;
                if (!body) {
                    setStatus(false);
                }
                return $.Deferred().reject({
                    status: xhr.status,
                    message: body ? body.message : 'Нет связи с сервером',
                    fields: body && body.fields ? body.fields : {}
                }).promise();
            }
        );
    }

    function loadModels() {
        if (!modelsRequest) {
            modelsRequest = api('/api/models').then(function (data) {
                return data.items;
            });
            modelsRequest.fail(function () {
                modelsRequest = null;
            });
        }
        return modelsRequest;
    }

    // Все страницы списка кодов подряд: нужно журналу, где случаи собираются из дерева.
    function loadAllCodes(params) {
        var items = [];
        function page(offset) {
            return api('/api/codes', $.extend({}, params, { limit: PAGE_LIMIT, offset: offset })).then(function (data) {
                items = items.concat(data.items);
                if (data.items.length > 0 && items.length < data.total) {
                    return page(offset + PAGE_LIMIT);
                }
                return items;
            });
        }
        return page(0);
    }

    function modelOptions(models, selected) {
        var html = '<option value="">Все модели</option>';
        $.each(models, function (_, m) {
            var value = String(m.id);
            html += '<option value="' + esc(value) + '"' + (value === String(selected || '') ? ' selected' : '') + '>'
                + esc(m.manufacturer.name + ' · ' + m.name) + ' (' + m.codes_count + ')</option>';
        });
        return html;
    }

    function ownerLabel(owner) {
        if (!owner) {
            return '';
        }
        return owner.type === 'model' ? 'Модель ' + owner.name : owner.name;
    }

    function plural(n, one, few, many) {
        var n10 = n % 10;
        var n100 = n % 100;
        if (n10 === 1 && n100 !== 11) {
            return one;
        }
        return n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14) ? few : many;
    }

    function loading(text) {
        return '<p class="lft-label app-loading">' + esc(text || 'Загрузка') + '</p>';
    }

    function errorWindow(error, title) {
        return '<div class="lft-window app-error" role="alert">'
            + '<div class="lft-window__bar"><span class="lft-label">' + esc(title || 'Ошибка') + ' · ' + esc(error.status || 'нет связи') + '</span>'
            + '<span class="lft-window__x" aria-hidden="true">×</span></div>'
            + '<div class="lft-window__body"><p class="app-text">' + esc(error.message) + '</p>'
            + '<button type="button" class="lft-btn lft-btn--ghost" data-action="reload">Повторить</button></div></div>';
    }

    function setActiveNav(page) {
        $('[data-page]').each(function () {
            var on = $(this).data('page') === page;
            $(this).toggleClass('on', on).attr('aria-current', on ? 'page' : null);
        });
    }

    // ---------- Экран «Коды» ----------

    function renderCodes(params, token) {
        var code = params.code || '';
        var model = params.model || '';

        $view.html(
            '<form class="fp-search app-search" id="code-form" role="search">'
            + '<label class="fp-field">' + icon('search')
            + '<input class="app-input" name="code" id="code-input" value="' + esc(code) + '" autocomplete="off" '
            + 'placeholder="Код с пульта: 44, C0, E36" aria-label="Код неисправности" maxlength="32"></label>'
            + '<label class="fp-chip app-select">' + icon('factory')
            + '<select name="model" id="model-select" aria-label="Модель лифта"><option value="">Загрузка моделей…</option></select></label>'
            + '<button class="lft-btn" type="submit">Найти код</button>'
            + '<p class="app-field-error lft-label" id="code-error" hidden></p>'
            + '</form>'
            + '<div class="fp-cols app-cols">'
            + '<section class="app-list" aria-label="Список кодов"><div id="code-list">' + loading() + '</div></section>'
            + '<section class="fp-main" id="code-main">' + loading() + '</section>'
            + '<section class="fp-side" id="code-side"></section>'
            + '</div>'
        );

        loadModels().then(function (models) {
            if (token === renderToken) {
                $('#model-select').html(modelOptions(models, model));
            }
        }, function () {
            $('#model-select').html('<option value="">Модели недоступны</option>');
        });

        renderCodeList(model, params.id, token);

        if (params.id) {
            renderCodeDetail(params.id, token);
        } else if (code) {
            searchCode(code, model, token);
        } else {
            renderCodesIntro();
        }
    }

    function renderCodeList(model, activeId, token) {
        var key = model || '*';
        var $list = $('#code-list');

        function draw() {
            var html = '<span class="lft-label app-list-head">Коды · ' + listCache.items.length + ' из ' + listCache.total + '</span>'
                + '<ul class="fp-list">';
            var group = null;
            $.each(listCache.items, function (_, item) {
                var on = String(item.id) === String(activeId || '');
                var owner = ownerLabel(item.owner);
                if (owner !== group) {
                    group = owner;
                    html += '<li class="app-group lft-label">' + esc(owner) + '</li>';
                }
                html += '<li' + (on ? ' class="on"' : '') + '><a href="' + hashFor('codes', { model: model, id: item.id }) + '">'
                    + '<b class="' + (item.is_safety_related ? 'app-safety' : '') + '">' + esc(item.code) + '</b>'
                    + '<span>' + esc(item.title) + '</span></a></li>';
            });
            html += '</ul>';
            if (listCache.items.length < listCache.total) {
                html += '<div class="app-more"><button type="button" class="lft-btn lft-btn--ghost" data-action="more">Показать ещё</button></div>';
            }
            if (listCache.total === 0) {
                html += '<p class="app-empty">У этой модели в базе пока нет кодов.</p>';
            }
            $list.html(html);
        }

        if (listCache.key === key) {
            draw();
            return;
        }

        api('/api/codes', { model: model || null, limit: PAGE_LIMIT, offset: 0 }).then(function (data) {
            listCache = { key: key, items: data.items, total: data.total };
            if (token === renderToken) {
                draw();
            }
        }, function (error) {
            if (token === renderToken) {
                $list.html('<div class="app-pad">' + errorWindow(error, 'Список кодов') + '</div>');
            }
        });
    }

    function loadMoreCodes() {
        var route = currentRoute();
        var model = route.params.model || '';
        api('/api/codes', { model: model || null, limit: PAGE_LIMIT, offset: listCache.items.length }).then(function (data) {
            listCache.items = listCache.items.concat(data.items);
            listCache.total = data.total;
            renderCodeList(model, route.params.id, renderToken);
        });
    }

    function renderCodesIntro() {
        $('#code-main').html(
            '<p class="lft-label">Поиск по коду</p>'
            + '<p class="fp-title">Введите код с пульта</p>'
            + '<p class="fp-desc">Код и модель принимаются и латиницей, и кириллицей: А0 и A0 найдут одно и то же. '
            + 'Без модели поиск покажет все варианты кода: один номер в разных устройствах управления может значить разное.</p>'
            + '<p class="fp-desc app-muted">Или выберите код в списке слева.</p>'
        );
        $('#code-side').html(sideInfo());
    }

    function sideInfo() {
        return '<div class="lft-window"><div class="lft-window__bar"><span class="lft-label">'
            + icon('info', 'lft-icon--12') + ' Справка</span><span class="lft-window__x" aria-hidden="true">×</span></div>'
            + '<div class="lft-window__body"><p class="app-text">Справочная система. Не заменяет документацию производителя '
            + 'и правила техники безопасности. Работы на лифтовом оборудовании выполняет только обученный и допущенный персонал.</p></div></div>';
    }

    function searchCode(code, model, token) {
        api('/api/codes', { code: code, model: model || null, limit: PAGE_LIMIT }).then(function (data) {
            if (token !== renderToken) {
                return;
            }
            if (data.total === 0) {
                renderNotFound(code, model);
            } else if (data.items.length === 1) {
                go('codes', { model: model, code: code, id: data.items[0].id }, true);
            } else {
                renderVariants(code, model, data.items);
            }
        }, function (error) {
            if (token !== renderToken) {
                return;
            }
            if (error.status === 400 && error.fields.code) {
                $('#code-error').text(error.fields.code).prop('hidden', false);
                $('#code-input').attr('aria-invalid', 'true');
                renderCodesIntro();
            } else {
                $('#code-main').html(errorWindow(error, 'Поиск'));
            }
        });
    }

    function renderVariants(code, model, items) {
        var html = '<p class="lft-label">Найдено вариантов: ' + items.length + '</p>'
            + '<p class="fp-title">Код ' + esc(code) + ' значит разное</p>'
            + '<p class="fp-desc">Номер встречается в нескольких устройствах управления. Выберите своё: его название написано на шильдике или в паспорте лифта.</p>'
            + '<div class="app-variants">';
        $.each(items, function (_, item) {
            html += '<a class="app-variant" href="' + hashFor('codes', { model: model, code: code, id: item.id }) + '">'
                + '<span class="lft-label">' + esc(ownerLabel(item.owner)) + '</span>'
                + '<b class="app-variant-code' + (item.is_safety_related ? ' app-safety' : '') + '">' + esc(item.code) + '</b>'
                + '<span>' + esc(item.title) + '</span></a>';
        });
        $('#code-main').html(html + '</div>');
        $('#code-side').html(sideInfo());
    }

    function renderCodeDetail(id, token) {
        api('/api/codes/' + encodeURIComponent(id)).then(function (data) {
            if (token === renderToken) {
                drawDetail(data.item);
            }
        }, function (error) {
            if (token !== renderToken) {
                return;
            }
            if (error.status === 404) {
                renderNotFound('#' + id, currentRoute().params.model);
            } else {
                $('#code-main').html(errorWindow(error, 'Код'));
            }
        });
    }

    function causesHtml(causes) {
        if (!causes.length) {
            return '<p class="app-empty">Причины для этого кода ещё не внесены. Смотрите РЭ производителя.</p>';
        }
        var html = '<ol class="lft-steps app-causes">';
        $.each(causes, function (i, cause) {
            var meta = cause.direct
                ? '<span class="lft-badge lft-badge--warn">по РЭ</span>'
                : '<span class="lft-label app-likely" title="Оценка вероятности по симптому">вероятность '
                    + '<i class="app-meter" style="--v:' + Number(cause.likelihood || 0) + '"></i> ' + esc(cause.likelihood) + '/5</span>';
            html += '<li class="lft-step"><span class="lft-step__n">' + (i + 1) + '</span><div class="app-cause">'
                + '<p class="app-cause-title">' + esc(cause.title) + '</p>'
                + '<div class="app-cause-meta">' + meta + (cause.source ? '<span class="lft-label">' + esc(cause.source) + '</span>' : '') + '</div>'
                + fixesHtml(cause.fixes || [])
                + '</div></li>';
        });
        return html + '</ol>';
    }

    function fixesHtml(fixes) {
        var html = '';
        $.each(fixes, function (_, fix) {
            html += '<details class="app-fix"><summary>' + icon('settings', 'lft-icon--12')
                + '<span>Ремонт: ' + esc(fix.title) + '</span><span class="lft-label">' + fix.steps.length + ' ' + plural(fix.steps.length, 'шаг', 'шага', 'шагов') + '</span></summary>'
                + '<div class="app-fix-body">'
                + (fix.summary ? '<p class="app-text">' + esc(fix.summary) + '</p>' : '')
                + (fix.safety_note ? '<div class="fp-safety app-safety-note">' + icon('warning') + esc(fix.safety_note) + '</div>' : '')
                + '<ol class="app-fix-steps">';
            $.each(fix.steps, function (_, step) {
                html += '<li><span class="app-step-n">' + step.step_no + '</span><div><p>' + esc(step.instruction) + '</p>'
                    + (step.expected_result ? '<p class="app-muted">Ожидаемо: ' + esc(step.expected_result) + '</p>' : '') + '</div></li>';
            });
            html += '</ol></div></details>';
        });
        return html;
    }

    function casesHtml(cases) {
        if (!cases.length) {
            return '<p class="app-empty">Случаев с этим кодом в журнале пока нет.</p>';
        }
        var html = '';
        $.each(cases, function (_, c) {
            var outcome = OUTCOMES[c.outcome] || { text: c.outcome, cls: '' };
            html += '<div class="fp-case"><div class="fp-case-top"><span class="lft-label">' + formatDate(c.occurred_on) + '</span>'
                + '<span class="lft-badge ' + outcome.cls + '">' + esc(outcome.text) + '</span></div>'
                + '<p>' + esc(c.description) + '</p>'
                + (c.notes ? '<p class="app-muted">' + esc(c.notes) + '</p>' : '') + '</div>';
        });
        return html;
    }

    function drawDetail(item) {
        var safety = item.is_safety_related;
        document.title = 'Код ' + item.code + ' — Lift Fault Terminal';

        $('#code-main').html(
            '<div class="fp-hero">'
            + '<span class="lft-code__num app-hero-code' + (safety ? ' app-hero-code--alarm' : '') + '">' + esc(item.code) + '</span>'
            + '<div><div class="app-hero-meta">'
            + (safety ? '<span class="lft-badge lft-badge--alarm">■ безопасность</span>' : '')
            + '<span class="lft-label">' + esc(ownerLabel(item.owner)) + '</span></div>'
            + '<h1 class="fp-title">' + esc(item.title) + '</h1>'
            + (item.description ? '<p class="fp-desc">' + esc(item.description) + '</p>' : '')
            + '</div></div>'
            + (safety ? '<div class="fp-safety">' + icon('warning') + 'Код связан с безопасностью. Работы выполняет только допущенный персонал. Справочник не заменяет РЭ.</div>' : '')
            + '<h2 class="fp-h app-h">Возможные причины</h2>'
            + causesHtml(item.causes || [])
            + (item.source ? '<p class="lft-label app-source">Источник: ' + esc(item.source) + '</p>' : '')
        );

        var symptoms = '';
        if (item.symptoms && item.symptoms.length) {
            symptoms = '<div><h2 class="fp-h">Симптомы</h2><ul class="app-symptoms">';
            $.each(item.symptoms, function (_, s) {
                symptoms += '<li>' + esc(s.title) + '</li>';
            });
            symptoms += '</ul></div>';
        }

        $('#code-side').html(
            '<div><h2 class="fp-h app-h-icon">' + icon('journal') + 'Случаи из журнала</h2>' + casesHtml(item.cases || [])
            + (item.cases && item.cases.length >= 5 ? '<p class="lft-label app-note">Показаны последние 5 случаев</p>' : '') + '</div>'
            + symptoms
        );
    }

    function renderNotFound(code, model) {
        var display = String(code).toUpperCase();
        $view.find('.app-cols').replaceWith(
            '<div class="nf app-nf">'
            + '<div class="nf-corner app-nf-left"><span class="lft-label app-bad">' + icon('warning', 'lft-icon--12') + ' Error 404</span><span class="lft-label">Code not found</span></div>'
            + '<div class="nf-corner app-nf-right"><span class="lft-label">Запрос: <span class="app-accent">' + esc(display) + '</span></span><span class="lft-label">Совпадений: <span class="app-accent">0</span></span></div>'
            + '<p class="nf-word">Код</p>'
            + '<p class="nf-code' + (display.length > 4 ? ' app-nf-long' : '') + '">' + esc(display) + '</p>'
            + '<p class="nf-sub">не найден</p>'
            + '<div class="nf-wins">'
            + '<div class="lft-window app-nf-help"><div class="lft-window__bar"><span class="lft-label">Что можно сделать?</span><span class="lft-window__x" aria-hidden="true">×</span></div>'
            + '<div class="lft-window__body">'
            + '<div class="nf-act">' + icon('code', 'lft-icon--48') + '<span>Проверьте, не код ли это другой системы управления (Err, Er)</span></div>'
            + (model ? '<a class="nf-act app-link" href="' + hashFor('codes', { code: code }) + '">' + icon('factory', 'lft-icon--48') + '<span>Поищите во всех моделях и устройствах</span></a>' : '')
            + '<a class="nf-act app-link" href="' + hashFor('codes', { model: model }) + '">' + icon('search', 'lft-icon--48') + '<span>Откройте список кодов и найдите похожий</span></a>'
            + '</div></div>'
            + '<div class="lft-window app-nf-warn"><div class="lft-window__bar"><span class="lft-label">Внимание</span><span class="lft-window__x" aria-hidden="true">×</span></div>'
            + '<div class="lft-window__body">' + icon('warning', 'lft-icon--48 app-bad')
            + '<p class="app-nf-text">Серьёзно?<br>Значение не придумываем.<br>Смотрите РЭ.</p>'
            + '<button type="button" class="lft-btn lft-btn--signal" data-action="clear">ОК</button></div></div>'
            + '</div></div>'
        );
    }

    // ---------- Экран «Журнал» ----------

    function renderJournal(params, token) {
        var model = params.model || '';
        $view.html(
            '<form class="fp-search app-search" id="journal-form" role="search">'
            + '<label class="fp-field">' + icon('search')
            + '<input class="app-input" id="journal-filter" autocomplete="off" placeholder="Фильтр: код или слово из записи" aria-label="Фильтр журнала" value="' + esc(params.q || '') + '"></label>'
            + '<label class="fp-chip app-select">' + icon('factory')
            + '<select name="model" id="journal-model" aria-label="Модель лифта"><option value="">Загрузка моделей…</option></select></label>'
            + '</form>'
            + '<div id="journal-body">' + loading('Собираем случаи из базы') + '</div>'
        );

        loadModels().then(function (models) {
            if (token === renderToken) {
                $('#journal-model').html(modelOptions(models, model));
            }
        });

        loadAllCodes({ model: model || null, expand: 'tree' }).then(function (codes) {
            if (token !== renderToken) {
                return;
            }
            var rows = [];
            $.each(codes, function (_, code) {
                $.each(code.cases || [], function (_, c) {
                    rows.push({ c: c, code: code });
                });
            });
            rows.sort(function (a, b) {
                return a.c.occurred_on < b.c.occurred_on ? 1 : a.c.occurred_on > b.c.occurred_on ? -1 : b.c.id - a.c.id;
            });
            drawJournal(rows, model);
            applyJournalFilter();
        }, function (error) {
            if (token === renderToken) {
                $('#journal-body').html('<div class="app-pad">' + errorWindow(error, 'Журнал') + '</div>');
            }
        });
    }

    function drawJournal(rows, model) {
        var byCode = {};
        var safetyCount = 0;
        var resolved = 0;
        $.each(rows, function (_, r) {
            byCode[r.code.code] = (byCode[r.code.code] || 0) + 1;
            if (r.code.is_safety_related) {
                safetyCount++;
            }
            if (r.c.outcome === 'resolved') {
                resolved++;
            }
        });
        var top = Object.keys(byCode).map(function (k) {
            return { code: k, n: byCode[k] };
        }).sort(function (a, b) {
            return b.n - a.n || (a.code < b.code ? -1 : 1);
        });
        var max = top.length ? top[0].n : 1;

        if (!rows.length) {
            $('#journal-body').html('<div class="app-pad"><p class="fp-title">Случаев пока нет</p>'
                + '<p class="fp-desc">Для выбранной модели в журнале нет случаев, привязанных к кодам.</p></div>');
            return;
        }

        var stats = '<div class="jr-stats">'
            + '<div class="jr-stat"><span class="lft-label">Случаев с кодом</span><span class="jr-num">' + rows.length + '</span><p>Записи журнала, привязанные к кодам в базе.</p></div>'
            + '<div class="jr-stat"><span class="lft-label">Разных кодов</span><span class="jr-num">' + top.length + '</span><p>Чаще всего: ' + esc(top[0].code) + '.</p></div>'
            + '<div class="jr-stat"><span class="lft-label">Коды безопасности</span><span class="jr-num app-bad">' + safetyCount + '</span><p>Случаи с кодами, связанными с безопасностью.</p></div>'
            + '<div class="jr-stat"><span class="lft-label">Решено</span><span class="jr-num">' + resolved + '<small>/' + rows.length + '</small></span><p>По отметке об итоге в записи.</p></div>'
            + '</div>';

        var table = '<table class="jr-table"><thead><tr><th>Дата</th><th>Код</th><th>Что случилось</th><th>Заметка</th><th>Итог</th></tr></thead><tbody>';
        $.each(rows, function (_, r) {
            var outcome = OUTCOMES[r.c.outcome] || { text: r.c.outcome, cls: '' };
            var text = (r.code.code + ' ' + r.code.title + ' ' + r.c.description + ' ' + (r.c.notes || '')).toLowerCase();
            table += '<tr data-text="' + esc(text) + '"><td class="d">' + formatDate(r.c.occurred_on) + '</td>'
                + '<td class="c"><a href="' + hashFor('codes', { model: model, id: r.code.id }) + '" title="' + esc(r.code.title) + '">' + esc(r.code.code) + '</a></td>'
                + '<td>' + esc(r.c.description) + '</td><td class="n">' + esc(r.c.notes || '') + '</td>'
                + '<td><span class="lft-badge ' + outcome.cls + '">' + esc(outcome.text) + '</span></td></tr>';
        });
        table += '</tbody></table><p class="app-empty" id="journal-none" hidden>Под фильтр ничего не подошло.</p>';

        var bars = '<div class="jr-bars">';
        $.each(top.slice(0, 8), function (_, t) {
            bars += '<div class="jr-bar"><b>' + esc(t.code) + '</b><i style="width:' + Math.max(8, Math.round(t.n / max * 100)) + '%"></i><span>' + t.n + '</span></div>';
        });
        bars += '</div>';

        $('#journal-body').html(stats
            + '<div class="fp-cols app-journal-cols">'
            + '<section class="app-table-wrap">' + table + '</section>'
            + '<section class="fp-side"><div><h2 class="fp-h app-h-icon">' + icon('journal') + 'Повторы</h2>' + bars
            + '<p class="lft-label app-note">По каждому коду API отдаёт до 5 последних случаев</p></div></section>'
            + '</div>');
    }

    function applyJournalFilter() {
        var query = $.trim($('#journal-filter').val() || '').toLowerCase();
        var shown = 0;
        $('#journal-body tbody tr').each(function () {
            var match = !query || String($(this).data('text')).indexOf(query) !== -1;
            $(this).prop('hidden', !match);
            if (match) {
                shown++;
            }
        });
        $('#journal-none').prop('hidden', shown > 0);
    }

    // ---------- Экран «ИИ-диагностика» ----------

    function renderAi() {
        $view.html(
            '<div class="nf app-ai">'
            + '<div class="nf-corner app-nf-left"><span class="lft-label">' + icon('chip', 'lft-icon--12') + ' AI · диагностика</span><span class="lft-label">Status: <span class="app-accent">этап 6</span></span></div>'
            + '<p class="nf-word">ИИ-диагностика</p>'
            + '<p class="nf-sub app-accent">скоро</p>'
            + '<div class="nf-wins">'
            + '<div class="lft-window app-nf-help"><div class="lft-window__bar"><span class="lft-label">Что будет здесь</span><span class="lft-window__x" aria-hidden="true">×</span></div>'
            + '<div class="lft-window__body">'
            + '<div class="nf-act">' + icon('chip', 'lft-icon--48') + '<span>Описываете симптом своими словами или вводите код</span></div>'
            + '<div class="nf-act">' + icon('journal', 'lft-icon--48') + '<span>Ответ сверяется с базой кодов и журналом случаев</span></div>'
            + '<div class="nf-act">' + icon('warning', 'lft-icon--48') + '<span>Ответ справочный: работы выполняет допущенный персонал</span></div>'
            + '</div></div></div>'
            + '<div class="ch-input app-ai-input"><label class="fp-field">' + icon('chip')
            + '<input class="app-input" disabled placeholder="Раздел появится на этапе 6 (POST /api/diagnose)" aria-label="Вопрос ИИ-диагностике"></label>'
            + '<button class="lft-btn" type="button" disabled>Отправить</button></div>'
            + '<p class="app-nf-back"><a class="lft-btn lft-btn--ghost" href="#/codes">Пока — поиск по коду</a></p>'
            + '</div>'
        );
    }

    // ---------- Маршрутизация и события ----------

    function render() {
        var route = currentRoute();
        var token = ++renderToken;
        var page = { codes: true, journal: true, ai: true }[route.page] ? route.page : 'codes';

        setActiveNav(page);
        document.title = 'Lift Fault Terminal — база неисправностей лифтов';
        window.scrollTo(0, 0);

        if (page === 'journal') {
            renderJournal(route.params, token);
        } else if (page === 'ai') {
            renderAi();
        } else {
            renderCodes(route.params, token);
        }
    }

    $(document)
        .on('submit', '#code-form', function (e) {
            e.preventDefault();
            var code = $.trim($('#code-input').val());
            go('codes', { model: $('#model-select').val(), code: code });
        })
        .on('change', '#model-select', function () {
            var code = $.trim($('#code-input').val());
            go('codes', { model: $(this).val(), code: code });
        })
        .on('submit', '#journal-form', function (e) {
            e.preventDefault();
        })
        .on('input', '#journal-filter', applyJournalFilter)
        .on('change', '#journal-model', function () {
            go('journal', { model: $(this).val() });
        })
        .on('click', '[data-action="more"]', function () {
            $(this).prop('disabled', true).text('Загрузка…');
            loadMoreCodes();
        })
        .on('click', '[data-action="reload"]', function () {
            listCache.key = null;
            modelsRequest = null;
            render();
        })
        .on('click', '[data-action="clear"]', function () {
            go('codes', { model: currentRoute().params.model });
        });

    function tick() {
        var d = new Date();
        var pad = function (n) {
            return (n < 10 ? '0' : '') + n;
        };
        $('#app-clock').text(pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + ' · ' + pad(d.getHours()) + ':' + pad(d.getMinutes()));
    }

    tick();
    setInterval(tick, 30000);
    api('/api/health');
    $(window).on('hashchange', render);
    render();
}(jQuery));
