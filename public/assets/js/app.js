/*
 * Lift Fault Terminal: интерфейс справочника на jQuery.
 * Экраны переключаются по адресу после #: #/codes, #/search, #/journal, #/ai.
 * Все данные приходят из REST API (/api/...), разметка — классы из app.css.
 * Текст из API вставляется только через esc(). Без экранирования вставляются лишь
 * title_html и snippet_html из /api/search: сервер экранирует их сам и оставляет только <mark>.
 */
(function ($) {
    'use strict';

    var PAGE_LIMIT = 100;
    var OUTCOMES = {
        resolved: { text: 'решено', cls: 'lft-badge--ok' },
        partial: { text: 'частично', cls: 'lft-badge--warn' },
        unresolved: { text: 'не решено', cls: 'lft-badge--alarm' }
    };

    var TYPES = { codes: 'Код', symptoms: 'Симптом', causes: 'Причина', fixes: 'Ремонт', cases: 'Случай' };
    var CONFIDENCE = {
        high: { text: 'вероятно', cls: 'lft-badge--ok' },
        medium: { text: 'возможно', cls: 'lft-badge--warn' },
        low: { text: 'маловероятно', cls: '' }
    };
    var AI_EXAMPLES = [
        { d: 'Постоянно появляется ошибка 49, 50, двери открываются медленно', c: '' },
        { d: 'Пассажиры застряли у этажа, на индикаторе 44 с подсказкой', c: '44 C6' },
        { d: 'При пуске лебёдка гудит, свет в кабине тускнеет, кабина стоит', c: '' }
    ];

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

    function apiPost(path, body) {
        return $.ajax({
            url: path, method: 'POST', data: JSON.stringify(body), contentType: 'application/json',
            dataType: 'json', timeout: 90000
        }).then(function (data) {
            setStatus(true);
            return data;
        }, function (xhr) {
            var err = xhr.responseJSON && xhr.responseJSON.error;
            if (!err) {
                setStatus(false);
            }
            return $.Deferred().reject({
                status: xhr.status,
                message: err ? err.message : (xhr.statusText === 'timeout' ? 'Сервер не ответил вовремя' : 'Нет связи с сервером'),
                fields: err && err.fields ? err.fields : {}
            }).promise();
        });
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
            html += '<option value="' + esc(value) + '"' + (value === String(selected || '') ? ' selected' : '')
                + ' data-maker="' + esc(m.manufacturer.name) + '" data-name="' + esc(m.name) + '" data-count="' + esc(m.codes_count) + '">'
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

    // ---------- Свой выпадающий список ----------
    // Системный <select> на телефоне рисует сама ОС, стилями его не поменять. Поэтому он
    // остаётся скрытым (на нём держатся обработчики change), а поверх рисуется кнопка
    // и список в стиле терминала: на широком экране — под полем, на телефоне — шторкой снизу.

    function customSelect(select) {
        var $select = $(select);
        if ($select.data('lft-dd')) {
            return;
        }
        var $btn = $('<button type="button" class="app-dd-btn" aria-haspopup="listbox" aria-expanded="false"></button>')
            .attr('aria-label', $select.attr('aria-label') || 'Выбор');
        $select.addClass('app-dd-native').attr({ tabindex: '-1', 'aria-hidden': 'true' }).after($btn).data('lft-dd', $btn);

        function update() {
            var $opt = $select.find('option:selected');
            $btn.text($opt.data('name') ? $opt.data('maker') + ' · ' + $opt.data('name') : $opt.text());
        }
        $select.on('change lft:refresh', update);
        update();
    }

    function closeDropdown(focusButton) {
        var $open = $('.app-dd');
        if (!$open.length) {
            return;
        }
        var $btn = $open.data('button');
        $open.remove();
        $('.app-dd-backdrop').remove();
        $('body').removeClass('app-dd-lock');
        if ($btn) {
            $btn.attr('aria-expanded', 'false');
            if (focusButton) {
                $btn.trigger('focus');
            }
        }
    }

    function openDropdown($btn) {
        closeDropdown(false);
        var $select = $btn.prev('select');
        var sheet = window.matchMedia('(max-width: 1023px)').matches;
        var title = $select.attr('aria-label') || 'Выбор';
        var html = '<div class="app-dd' + (sheet ? ' app-dd--sheet' : '') + '" role="listbox" aria-label="' + esc(title) + '">';
        if (sheet) {
            html += '<div class="lft-window__bar"><span class="lft-label">' + esc(title) + '</span>'
                + '<button type="button" class="lft-window__x app-dd-close" aria-label="Закрыть">×</button></div>';
        }
        $select.find('option').each(function () {
            var $o = $(this);
            var on = $o.is(':selected');
            html += '<button type="button" class="app-dd-opt" role="option" aria-selected="' + on + '" data-value="' + esc($o.val()) + '">';
            if ($o.data('name')) {
                html += '<span class="lft-label">' + esc($o.data('maker')) + '</span><span class="app-dd-name">' + esc($o.data('name')) + '</span>'
                    + '<span class="app-dd-count">' + esc($o.data('count')) + ' ' + plural(Number($o.data('count')), 'код', 'кода', 'кодов') + '</span>';
            } else {
                html += '<span class="app-dd-name">' + esc($o.text()) + '</span>';
            }
            html += '</button>';
        });
        var $dd = $(html + '</div>').data('button', $btn);

        if (sheet) {
            $('body').addClass('app-dd-lock').append('<div class="app-dd-backdrop"></div>').append($dd);
        } else {
            $btn.closest('.app-select').append($dd);
        }
        $btn.attr('aria-expanded', 'true');
        ($dd.find('[aria-selected="true"]')[0] || $dd.find('.app-dd-opt')[0]).focus();
    }

    // ---------- Экран «Коды» ----------

    function renderCodes(params, token) {
        var code = params.code || '';
        var model = params.model || '';

        $view.html(
            '<form class="fp-search app-search" id="code-form" role="search">'
            + '<label class="fp-field">' + icon('search')
            + '<input class="app-input" name="code" id="code-input" value="' + esc(code) + '" autocomplete="off" '
            + 'placeholder="Код с пульта: 45, 44 C6, E36" aria-label="Код неисправности" maxlength="64" autocapitalize="characters" autocorrect="off" spellcheck="false"></label>'
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
                $('#model-select').html(modelOptions(models, model)).trigger('lft:refresh');
            }
        }, function () {
            $('#model-select').html('<option value="">Модели недоступны</option>').trigger('lft:refresh');
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
        api('/api/codes', { code: code, model: model || null, limit: PAGE_LIMIT, expand: 'tree' }).then(function (data) {
            if (token !== renderToken) {
                return;
            }
            var distinct = {};
            $.each(data.items, function (_, item) {
                distinct[item.code.toUpperCase()] = (distinct[item.code.toUpperCase()] || 0) + 1;
            });
            var ambiguous = $.grep(Object.keys(distinct), function (k) { return distinct[k] > 1; });

            if (data.total === 0) {
                renderNotFound(code, model);
            } else if (data.items.length === 1) {
                go('codes', { model: model, code: code, id: data.items[0].id }, true);
            } else if (!ambiguous.length) {
                // «44 C6»: разные коды, у каждого один вариант — показываем все сразу
                drawDetails(data.items);
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
            + '<p class="fp-title">' + (/[\s,;\/]/.test($.trim(code)) ? 'Коды ' + esc(code) + ' значат' : 'Код ' + esc(code) + ' значит') + ' разное</p>'
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
        document.title = 'Код ' + item.code + ' — Lift Fault Terminal';
        $('#code-main').html(detailMainHtml(item));
        $('#code-side').html(detailSideHtml([item]));
    }

    function drawDetails(items) {
        document.title = 'Коды ' + $.map(items, function (i) { return i.code; }).join(' ') + ' — Lift Fault Terminal';
        $('#code-main').html($.map(items, function (item, i) {
            return (i ? '<div class="app-detail-sep"></div>' : '') + detailMainHtml(item);
        }).join(''));
        $('#code-side').html(detailSideHtml(items));
    }

    function detailMainHtml(item) {
        var safety = item.is_safety_related;
        return (
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
    }

    function detailSideHtml(items) {
        var cases = [];
        var symptoms = [];
        var seen = {};
        $.each(items, function (_, item) {
            $.each(item.cases || [], function (_, c) {
                if (!seen['c' + c.id]) {
                    seen['c' + c.id] = true;
                    cases.push(c);
                }
            });
            $.each(item.symptoms || [], function (_, s) {
                if (!seen['s' + s.id]) {
                    seen['s' + s.id] = true;
                    symptoms.push(s);
                }
            });
        });

        var html = '<div><h2 class="fp-h app-h-icon">' + icon('journal') + 'Случаи из журнала</h2>' + casesHtml(cases)
            + (items.length === 1 && cases.length >= 5 ? '<p class="lft-label app-note">Показаны последние 5 случаев</p>' : '') + '</div>';
        if (symptoms.length) {
            html += '<div><h2 class="fp-h">Симптомы</h2><ul class="app-symptoms">';
            $.each(symptoms, function (_, s) {
                html += '<li>' + esc(s.title) + '</li>';
            });
            html += '</ul></div>';
        }
        return html;
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

    // ---------- Экран «Общая база» (мастер): обезличенные случаи из базы знаний ----------

    function renderBase(params, token) {
        var model = params.model || '';
        $view.html(
            '<form class="fp-search app-search" id="journal-form" role="search">'
            + '<label class="fp-field">' + icon('search')
            + '<input class="app-input" id="journal-filter" autocomplete="off" placeholder="Фильтр: код или слово из записи" aria-label="Фильтр журнала" value="' + esc(params.q || '') + '"></label>'
            + '<label class="fp-chip app-select">' + icon('factory')
            + '<select name="model" id="journal-model" aria-label="Модель лифта"><option value="">Загрузка моделей…</option></select></label>'
            + '<div class="app-types app-journal-tabs" role="tablist">'
            + '<a class="fp-chip app-type" aria-pressed="false" href="#/journal">Журналы механиков</a>'
            + '<a class="fp-chip app-type" aria-pressed="true" href="#/base">Общая база</a></div>'
            + '</form>'
            + '<div id="journal-body">' + loading('Собираем случаи из базы') + '</div>'
        );

        loadModels().then(function (models) {
            if (token === renderToken) {
                $('#journal-model').html(modelOptions(models, model)).trigger('lft:refresh');
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
        countUp($('#journal-body'));
    }

    // Числа сводки досчитываются от нуля. При «уменьшить движение» — сразу итог.
    function countUp($root) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        $root.find('.jr-num').each(function () {
            var node = this.firstChild;
            var target = node && node.nodeType === 3 ? parseInt(node.nodeValue, 10) : NaN;
            if (!(target > 0)) {
                return;
            }
            var start = performance.now();
            var step = function (now) {
                var k = Math.min(1, (now - start) / 600);
                node.nodeValue = String(Math.round(target * (1 - Math.pow(1 - k, 3))));
                if (k < 1) {
                    requestAnimationFrame(step);
                }
            };
            node.nodeValue = '0';
            requestAnimationFrame(step);
        });
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

    // ---------- Экран «Поиск» ----------

    function renderSearch(params, token) {
        var q = params.q || '';
        var model = params.model || '';
        var types = params.type ? params.type.split(',') : [];

        var chips = '';
        $.each(TYPES, function (key, label) {
            var on = !types.length || types.indexOf(key) !== -1;
            chips += '<button type="button" class="fp-chip app-type" data-type="' + key + '" aria-pressed="' + on + '">' + esc(label) + '</button>';
        });

        $view.html(
            '<form class="fp-search app-search" id="search-form" role="search">'
            + '<label class="fp-field">' + icon('search')
            + '<input class="app-input" id="search-input" type="search" value="' + esc(q) + '" autocomplete="off" maxlength="300" '
            + 'placeholder="Симптом, причина, код: двери не закрываются" aria-label="Что искать"></label>'
            + '<label class="fp-chip app-select">' + icon('factory')
            + '<select id="search-model" aria-label="Модель лифта"><option value="">Загрузка моделей…</option></select></label>'
            + '<button class="lft-btn" type="submit">Искать</button>'
            + '<div class="app-types" role="group" aria-label="Разделы">' + chips + '</div>'
            + '<p class="app-field-error lft-label" id="search-error" hidden></p>'
            + '</form>'
            + '<div class="app-pad" id="search-body"></div>'
        );

        loadModels().then(function (models) {
            if (token === renderToken) {
                $('#search-model').html(modelOptions(models, model)).trigger('lft:refresh');
            }
        });

        if (!q) {
            $('#search-body').html(
                '<p class="lft-label">Полнотекстовый поиск</p>'
                + '<p class="fp-title">Ищите своими словами</p>'
                + '<p class="fp-desc">Поиск идёт по кодам, симптомам, причинам, ремонтам и случаям из журнала. '
                + 'Формы слов не важны: «дверь не закрывается» найдёт «Двери не закрываются». Модель оставляет только её коды.</p>'
            );
            return;
        }

        $('#search-body').html(loading('Ищем'));
        var query = { q: q, model: model || null, limit: 40 };
        if (types.length && types.length < Object.keys(TYPES).length) {
            query.type = types.join(',');
        }
        api('/api/search', query).then(function (data) {
            if (token === renderToken) {
                drawSearch(data, model);
            }
        }, function (error) {
            if (token !== renderToken) {
                return;
            }
            if (error.status === 400) {
                $('#search-error').text($.map(error.fields, function (t) { return t; }).join(' · ') || error.message).prop('hidden', false);
                $('#search-body').empty();
            } else {
                $('#search-body').html(errorWindow(error, 'Поиск'));
            }
        });
    }

    function drawSearch(data, model) {
        if (!data.items.length) {
            $('#search-body').html('<p class="fp-title">Ничего не нашлось</p><p class="fp-desc">Попробуйте другие слова или уберите фильтр разделов.</p>');
            return;
        }
        var html = '<p class="lft-label">Найдено: ' + data.total + '</p><ul class="app-hits">';
        $.each(data.items, function (_, hit) {
            // title_html и snippet_html экранированы сервером, в них только <mark>
            var title = hit.title_html || esc(hit.title);
            var body = '<span class="app-hit-title">' + title + '</span>'
                + (hit.snippet_html ? '<span class="app-hit-snippet">' + hit.snippet_html + '</span>' : '')
                + (hit.owner ? '<span class="lft-label">' + esc(ownerLabel(hit.owner)) + '</span>' : '');
            var lead = hit.type === 'codes'
                ? '<b class="app-hit-code' + (hit.is_safety_related ? ' app-safety' : '') + '">' + esc(hit.code) + '</b>'
                : '<span class="lft-label app-hit-type">' + esc(TYPES[hit.type] || hit.type) + '</span>';
            html += '<li>' + (hit.type === 'codes'
                ? '<a class="app-hit" href="' + hashFor('codes', { model: model, id: hit.id }) + '">' + lead + '<span class="app-hit-body">' + body + '</span></a>'
                : '<div class="app-hit">' + lead + '<span class="app-hit-body">' + body + '</span></div>') + '</li>';
        });
        $('#search-body').html(html + '</ul>');
    }

    function submitSearch() {
        var types = $('.app-type[aria-pressed="true"]').map(function () { return $(this).data('type'); }).get();
        if (!types.length) {
            $('#search-error').text('Выберите хотя бы один раздел').prop('hidden', false);
            return;
        }
        go('search', {
            q: $.trim($('#search-input').val()),
            model: $('#search-model').val(),
            type: types.length < Object.keys(TYPES).length ? types.join(',') : null
        });
    }

    // ---------- Экран «ИИ-диагностика» ----------

    // Переписка живёт, пока открыта вкладка, и переживает переход между разделами.
    // API не помнит прошлых запросов, поэтому уточнение отправляется вместе со всеми
    // предыдущими сообщениями этой переписки: «двери при этом открыты» без исходного
    // описания ничего бы не значило.
    var AI_MAX_LENGTH = 2000;
    var AI_STORE = 'lft:ai';
    var ai = { thread: [], messages: [], answers: [], code: '', model: null };

    // Браузер на телефоне перезагружает вкладку после возврата из другого приложения,
    // поэтому переписка хранится в sessionStorage: живёт, пока открыта вкладка
    function saveAi() {
        try {
            sessionStorage.setItem(AI_STORE, JSON.stringify(ai));
        } catch (e) { /* приватный режим или переполнение: переписка просто не сохранится */ }
    }

    try {
        var stored = JSON.parse(sessionStorage.getItem(AI_STORE) || 'null');
        if (stored && $.isArray(stored.thread) && $.isArray(stored.messages)) {
            ai = $.extend(ai, stored);
            ai.answers = $.isArray(stored.answers) ? stored.answers : [];
        }
    } catch (e) { /* нет доступа к хранилищу */ }

    function renderAi(token) {
        var examples = '';
        $.each(AI_EXAMPLES, function (i, ex) {
            examples += '<button type="button" class="fp-chip app-example" data-example="' + i + '">' + esc(ex.c ? ex.d.split(',')[0] + ' · ' + ex.c : ex.d.split(',')[0]) + '</button>';
        });

        $view.html(
            '<div class="app-ai-page">'
            + '<div class="ch-thread" id="ai-thread">'
            + '<div class="lft-window app-ai-intro"><div class="lft-window__bar"><span class="lft-label">' + icon('chip', 'lft-icon--12') + ' AI · диагностика</span>'
            + '<span class="lft-window__x" aria-hidden="true">×</span></div><div class="lft-window__body">'
            + '<p class="app-text">Опишите, что видите и слышите, и добавьте код с пульта, если он есть. Код можно написать и прямо в тексте: «ошибка 49, 50».</p>'
            + '<div class="nf-act">' + icon('journal', 'lft-icon--48') + '<span>Причины подбираются только из базы: руководства производителей и журнал</span></div>'
            + '<div class="nf-act">' + icon('chip', 'lft-icon--48') + '<span>ИИ расставляет их по вероятности, объясняет и задаёт уточняющие вопросы. Отвечайте на них здесь же</span></div>'
            + '<div class="nf-act">' + icon('warning', 'lft-icon--48') + '<span>Ответ справочный: работы выполняет допущенный персонал</span></div>'
            + '<div class="app-examples"><span class="lft-label">Например</span>' + examples + '</div>'
            + '</div></div>'
            + '</div>'
            + '<form class="ch-input app-ai-form" id="ai-form">'
            + '<div class="app-ai-bar">'
            + '<button type="button" class="app-ai-toggle lft-label" id="ai-toggle" aria-expanded="false" aria-controls="ai-opts">'
            + icon('settings', 'lft-icon--12') + '<span id="ai-summary"></span></button>'
            + '<span class="app-ai-actions">'
            + '<button type="button" class="app-ai-new lft-label" id="ai-save" hidden>' + icon('journal', 'lft-icon--12') + 'В журнал</button>'
            + '<button type="button" class="app-ai-new lft-label" id="ai-new" hidden>' + icon('close', 'lft-icon--12') + 'Новый</button>'
            + '</span>'
            + '</div>'
            + '<div class="app-ai-opts" id="ai-opts">'
            + '<label class="fp-chip app-ai-code">' + icon('code')
            + '<input class="app-input" id="ai-code" maxlength="64" placeholder="Код: 44 C6" aria-label="Код с пульта" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false"></label>'
            + '<label class="fp-chip app-select">' + icon('factory')
            + '<select id="ai-model" aria-label="Модель лифта"><option value="">Загрузка моделей…</option></select></label>'
            + '</div>'
            + '<div class="app-ai-row">'
            + '<label class="fp-field app-ai-text">'
            + '<textarea class="app-input" id="ai-text" rows="1" maxlength="' + AI_MAX_LENGTH + '" placeholder="Что происходит с лифтом" aria-label="Сообщение"></textarea></label>'
            + '<button class="lft-btn app-ai-send" type="submit" id="ai-send" aria-label="Отправить">' + icon('arrow-up') + '<span>Разобрать</span></button>'
            + '</div>'
            + '<p class="app-field-error lft-label" id="ai-error" hidden></p>'
            + '</form>'
            + '</div>'
        );

        $.each(ai.thread, function (_, html) {
            $('#ai-thread').append(html);
        });
        if (ai.thread.length) {
            $('.app-ai-intro').remove();
        }
        $('#ai-code').val(ai.code);
        updateAiForm();

        loadModels().then(function (models) {
            if (token === renderToken) {
                var selected = ai.model !== null ? ai.model : (currentRoute().params.model || '');
                $('#ai-model').html(modelOptions(models, selected)).trigger('lft:refresh');
                updateAiForm();
            }
        });

        if (ai.thread.length) {
            var last = $('#ai-thread').children().last()[0];
            if (last) {
                last.scrollIntoView({ block: 'start' });
            }
        }
    }

    // Сводка параметров над полем ввода и подсказки в зависимости от того, начата ли переписка
    function updateAiForm() {
        var model = $('#ai-model option:selected');
        var modelText = model.data('name') ? model.data('maker') + ' · ' + model.data('name') : 'все модели';
        var code = $.trim($('#ai-code').val());
        $('#ai-summary').text((code ? 'Код ' + code : 'Без кода') + ' · ' + modelText);
        $('#ai-new, #ai-save').prop('hidden', !ai.messages.length);
        $('#ai-text').attr('placeholder', ai.messages.length ? 'Уточнение или ответ на вопрос' : 'Что происходит с лифтом');
    }

    function growTextarea(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 132) + 'px';
    }

    // Всё сказанное в переписке одним описанием; если длинно — без самых старых сообщений
    function aiDescription(text) {
        var parts = ai.messages.concat([text]);
        var joined = parts.join('. ');
        while (joined.length > AI_MAX_LENGTH && parts.length > 1) {
            parts.shift();
            joined = parts.join('. ');
        }
        return joined.slice(0, AI_MAX_LENGTH);
    }

    function submitAi() {
        var text = $.trim($('#ai-text').val());
        var code = $.trim($('#ai-code').val());
        var model = $('#ai-model').val();
        var $error = $('#ai-error');
        var followUp = ai.messages.length > 0;

        if (!text || (!followUp && text.length < 10)) {
            $error.text(followUp ? 'Напишите уточнение' : 'Опишите неисправность подробнее: не короче 10 символов').prop('hidden', false);
            return;
        }
        $error.prop('hidden', true);

        var modelName = model ? $('#ai-model option:selected').text().replace(/\s*\(\d+\)$/, '') : 'все модели';
        var question = '<div class="ch-q"><span class="lft-label">' + (followUp ? 'Уточнение' : 'Вы') + ' · ' + esc(modelName)
            + (code ? ' · код ' + esc(code) : '') + '</span>' + esc(text) + '</div>';
        var $pending = $('<div class="ch-progress app-ai-wait" role="status"><div><span class="lft-label">Ищем в базе, спрашиваем ИИ</span>'
            + '<span class="lft-label app-accent app-ai-elapsed">0 с</span></div><div class="ch-meter"><i></i></div></div>');
        // Счётчик секунд — живой признак работы при любых настройках анимации телефона
        var started = Date.now();
        var elapsedTimer = setInterval(function () {
            $pending.find('.app-ai-elapsed').text(Math.round((Date.now() - started) / 1000) + ' с');
        }, 1000);

        var body = { description: aiDescription(text) };
        if (code) {
            body.code = code;
        }
        if (model) {
            body.model = model;
        }

        $('.app-ai-intro').remove();
        $('#ai-thread').append(question).append($pending);
        $('#ai-send').prop('disabled', true).attr('aria-busy', 'true');
        $('#ai-text').val('');
        growTextarea($('#ai-text')[0]);
        $pending[0].scrollIntoView({ block: 'end', behavior: 'smooth' });

        apiPost('/api/diagnose', body).then(function (data) {
            var answer = aiAnswerHtml(data);
            ai.thread.push(question, answer);
            ai.messages.push(text);
            ai.answers.push({
                summary: data.ai.summary || '',
                codes: $.map(data.codes, function (c) { return c.code; }),
                causes: $.map(data.causes.slice(0, 5), function (c) {
                    return { title: c.title, confidence: c.confidence, first_check: c.first_check };
                }),
                questions: data.ai.questions || []
            });
            saveAi();
            var $answer = $(answer);
            $pending.replaceWith($answer);
            // К вопросу, а не к ответу: видно и что спросили, и начало ответа
            ($answer.prev('.ch-q')[0] || $answer[0]).scrollIntoView({ block: 'start', behavior: 'smooth' });
        }, function (error) {
            // Неудачный запрос не попадает в переписку: текст возвращается в поле
            $pending.prev('.ch-q').remove();
            $pending.replaceWith('<div class="ch-a app-ai-failed">' + errorWindow(error, 'Диагностика') + '</div>');
            $('#ai-text').val(text);
            growTextarea($('#ai-text')[0]);
            if (error.status === 400 && Object.keys(error.fields).length) {
                $error.text($.map(error.fields, function (t) { return t; }).join(' · ')).prop('hidden', false);
            }
        }).always(function () {
            clearInterval(elapsedTimer);
            $('#ai-send').prop('disabled', false).removeAttr('aria-busy');
            updateAiForm();
        });
    }

    function aiAnswerHtml(data) {
        var ai = data.ai;
        var byAi = ai.status === 'ok';
        var bar = byAi ? 'AI · ' + esc(ai.model) : 'База · без ИИ';
        var html = '<div class="ch-a"><div class="lft-window"><div class="lft-window__bar"><span class="lft-label">' + icon('chip', 'lft-icon--12') + ' ' + bar + '</span>'
            + '<span class="lft-window__x" aria-hidden="true">×</span></div><div class="lft-window__body">';

        var badges = '';
        if (data.detected_codes && data.detected_codes.length) {
            badges += '<span class="lft-badge">' + icon('code', 'lft-icon--12') + 'коды из текста: ' + esc(data.detected_codes.join(', ')) + '</span>';
        }
        if (byAi) {
            badges += '<span class="lft-badge lft-badge--ok">' + icon('ok', 'lft-icon--12') + 'причины только из базы'
                + (ai.discarded ? ' · отброшено ' + ai.discarded : '') + '</span>';
        }
        if (badges) {
            html += '<div class="app-cause-meta">' + badges + '</div>';
        }
        if (!byAi && ai.message) {
            html += '<p class="app-text app-muted">' + esc(ai.message) + '</p>';
        }
        if (ai.summary) {
            html += '<p class="app-text app-ai-summary">' + esc(ai.summary) + '</p>';
        }

        if (data.safety && data.safety.length) {
            html += '<div class="fp-safety app-ai-safety">' + icon('warning') + '<div><span class="lft-label">Техника безопасности</span><ul>';
            $.each(data.safety, function (_, note) {
                html += '<li>' + esc(note) + '</li>';
            });
            html += '</ul></div></div>';
        }

        if (data.causes.length) {
            html += '<ol class="lft-steps app-causes">';
            $.each(data.causes, function (i, cause) {
                var conf = CONFIDENCE[cause.confidence];
                html += '<li class="lft-step"><span class="lft-step__n">' + (i + 1) + '</span><div class="app-cause">'
                    + '<p class="app-cause-title">' + esc(cause.title) + '</p>'
                    + '<div class="app-cause-meta">' + (conf ? '<span class="lft-badge ' + conf.cls + '">' + conf.text + '</span>' : '')
                    + (cause.source ? '<span class="lft-label">' + esc(cause.source) + '</span>' : '') + '</div>'
                    + (cause.reasoning ? '<p class="app-text">' + esc(cause.reasoning) + '</p>' : '')
                    + (cause.first_check ? '<p class="app-check"><span class="lft-label">Проверить первым</span>' + esc(cause.first_check) + '</p>' : '')
                    + evidenceHtml(cause.evidence)
                    + fixesHtml(cause.fixes || [])
                    + '</div></li>';
            });
            html += '</ol>';
        }

        if (ai.questions && ai.questions.length) {
            html += '<div class="app-questions"><span class="lft-label">Уточните, чтобы сузить выбор</span><ul>';
            $.each(ai.questions, function (_, q) {
                html += '<li>' + esc(q) + '</li>';
            });
            html += '</ul></div>';
        }

        if (data.codes.length) {
            html += '<div class="ch-src">';
            $.each(data.codes, function (_, c) {
                html += '<a href="' + hashFor('codes', { id: c.id }) + '" title="' + esc(c.title) + '">' + esc(c.code) + ' · ' + esc(ownerLabel(c.owner)) + '</a>';
            });
            html += '</div>';
        }
        if (data.cases.length) {
            html += '<details class="app-fix"><summary>' + icon('journal', 'lft-icon--12') + '<span>Похожие случаи из журнала</span>'
                + '<span class="lft-label">' + data.cases.length + '</span></summary><div class="app-fix-body">' + casesHtml(data.cases) + '</div></details>';
        }
        if (data.search === 'unavailable') {
            html += '<p class="lft-label">Полнотекстовый поиск недоступен: разбор сделан только по коду</p>';
        }

        return html + '<p class="lft-label">Справочно · работы выполняет допущенный персонал</p></div></div></div>';
    }

    function evidenceHtml(evidence) {
        if (!evidence || !evidence.length) {
            return '';
        }
        var html = '<ul class="app-evidence">';
        $.each(evidence, function (_, e) {
            html += '<li>' + esc(e) + '</li>';
        });
        return html + '</ul>';
    }

    // ---------- Вход: кто вы и в какой роли ----------
    // Пользователя определяет сервер по аккаунту Tailscale. Роль (механик или мастер) — режим
    // интерфейса: права мастера сервер всё равно проверяет по своему списку.

    var ROLE_STORE = 'lft:role';
    var STATUSES = {
        resolved: { text: 'устранено', cls: 'lft-badge--ok' },
        monitoring: { text: 'наблюдать', cls: 'lft-badge--warn' },
        repeat_visit: { text: 'повторный выезд', cls: 'lft-badge--alarm' },
        unresolved: { text: 'не устранено', cls: 'lft-badge--alarm' }
    };
    var session = { me: null, role: null, error: null };
    var DEMO_USER = 'lft:demo-user';
    var DEMO_MECHANIC = 'lft:demo-mechanic';

    function demoValue(key, value) {
        try {
            if (value === undefined) {
                return localStorage.getItem(key);
            }
            if (value === null) {
                localStorage.removeItem(key);
            } else {
                localStorage.setItem(key, value);
            }
        } catch (e) { /* без хранилища демо-сессия живёт до перезагрузки */ }
        return null;
    }

    // Демо-копия без входа: браузер представляется случайным идентификатором.
    // Рабочая копия эти заголовки игнорирует, пользователя там определяет Tailscale.
    var demoUser = demoValue(DEMO_USER);
    if (!demoUser) {
        demoUser = 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        demoValue(DEMO_USER, demoUser);
    }
    $.ajaxSetup({
        beforeSend: function (xhr) {
            xhr.setRequestHeader('X-Demo-User', demoUser);
            var mechanic = demoValue(DEMO_MECHANIC);
            if (mechanic) {
                xhr.setRequestHeader('X-Demo-Mechanic', mechanic);
            }
        }
    });
    var liftsRequest = null;

    function storedRole(value) {
        try {
            if (value === undefined) {
                return localStorage.getItem(ROLE_STORE);
            }
            if (value === null) {
                localStorage.removeItem(ROLE_STORE);
            } else {
                localStorage.setItem(ROLE_STORE, value);
            }
        } catch (e) { /* без хранилища роль спросят при следующем открытии */ }
        return null;
    }

    function startSession() {
        api('/api/me').then(function (me) {
            session.me = me;
            var role = storedRole();
            if ((role === 'master' && !me.is_master) || (role === 'mechanic' && !me.mechanic)) {
                role = null;
            }
            session.role = role;
            $('#app-demo').prop('hidden', !me.demo);
            drawUser();
            render();
        }, function (error) {
            session.error = error;
            render();
        });
    }

    function isMaster() {
        return session.role === 'master';
    }

    function drawUser() {
        var $u = $('#app-user');
        if (!session.role) {
            $u.prop('hidden', true);
            return;
        }
        var name = isMaster() ? 'мастер' : (session.me.mechanic ? session.me.mechanic.name : 'механик');
        $u.prop('hidden', false).html(icon(isMaster() ? 'building' : 'settings', 'lft-icon--12') + '<span>' + esc(name) + '</span>');
    }

    function renderLogin() {
        if (session.error) {
            $view.html('<div class="app-pad"><p class="lft-label app-bad">Вход</p><p class="fp-title">Откройте через Tailscale</p>'
                + '<p class="fp-desc">' + esc(session.error.message) + '</p></div>');
            return;
        }
        if (!session.me) {
            $view.html(loading('Вход'));
            return;
        }
        var me = session.me;
        $view.html(
            '<div class="nf app-login">'
            + '<img class="app-login-mark" src="/assets/img/lft-mark.svg" alt="">'
            + '<p class="nf-word">Кто вы?</p>'
            + '<p class="lft-label">' + (me.demo ? 'Демо · вход без регистрации, любая роль' : 'Аккаунт: ' + esc(me.login)) + '</p>'
            + '<div class="app-login-actions">'
            + '<button type="button" class="lft-btn" data-login="mechanic">' + icon('settings') + 'Войти как механик</button>'
            + '<button type="button" class="lft-btn lft-btn--ghost" data-login="master">' + icon('building') + 'Войти как мастер</button>'
            + '</div>'
            + '<div id="login-more"></div>'
            + '</div>'
        );
    }

    function pickMechanicHtml(mechanics) {
        var html = '<div class="lft-window app-login-pick"><div class="lft-window__bar"><span class="lft-label">Выберите себя</span>'
            + '<span class="lft-window__x" aria-hidden="true">×</span></div><div class="lft-window__body">'
            + '<p class="app-text app-muted">' + (session.me && session.me.demo
                ? 'Демо: выберите любого вымышленного механика, его можно сменить в любой момент.'
                : 'Один раз: аккаунт привяжется к механику, и в журнале будут ваши лифты.') + '</p>';
        var demo = session.me && session.me.demo;
        $.each(mechanics || [], function (_, m) {
            var taken = m.linked && !demo;
            html += '<button type="button" class="app-pick-row" data-mechanic="' + m.id + '"' + (taken ? ' disabled' : '') + '>'
                + '<span class="app-pick-main">' + esc(m.name) + '</span>'
                + '<span class="lft-label">' + (taken ? 'уже привязан' : m.lifts + ' ' + plural(m.lifts, 'лифт', 'лифта', 'лифтов')) + '</span></button>';
        });
        return html + '</div></div>';
    }

    function loadLifts() {
        if (!liftsRequest) {
            liftsRequest = api('/api/lifts').then(function (data) {
                return data.items;
            });
            liftsRequest.fail(function () {
                liftsRequest = null;
            });
        }
        return liftsRequest;
    }

    function placeName(p) {
        return p.address + (p.object_name ? ' · ' + p.object_name : '');
    }

    function liftName(lift) {
        return lift.address + (lift.object_name ? ' · ' + lift.object_name : '') + ', лифт ' + lift.lift_no;
    }

    function formatDateTime(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value || '');
        return m ? m[3] + '.' + m[2] + '.' + m[1] + ' ' + m[4] + ':' + m[5] : esc(value);
    }

    // ---------- Экран «Журнал» ----------

    function renderJournal(params, token) {
        var master = isMaster();
        var mechanic = master ? (params.mechanic || '') : '';
        var tabs = '';
        if (master) {
            tabs = '<div class="app-types app-journal-tabs" role="tablist">'
                + '<a class="fp-chip app-type" aria-pressed="true" href="#/journal">Журналы механиков</a>'
                + '<a class="fp-chip app-type" aria-pressed="false" href="#/base">Общая база</a></div>';
        }

        $view.html(
            '<form class="fp-search app-search" id="pj-form" role="search">'
            + '<label class="fp-field">' + icon('search')
            + '<input class="app-input" id="pj-q" type="search" value="' + esc(params.q || '') + '" autocomplete="off" '
            + 'placeholder="Адрес, код или слово из записи" aria-label="Поиск по журналу"></label>'
            + (master ? '<label class="fp-chip app-select">' + icon('settings')
                + '<select id="pj-mechanic" aria-label="Механик"><option value="">Все механики</option></select></label>' : '')
            + '<button class="lft-btn" type="submit">Найти</button>'
            + tabs
            + '</form>'
            + '<div id="pj-body">' + loading('Загружаем журнал') + '</div>'
        );

        if (master) {
            api('/api/mechanics').then(function (data) {
                if (token !== renderToken) {
                    return;
                }
                var html = '<option value="">Все механики</option>';
                $.each(data.items, function (_, m) {
                    html += '<option value="' + m.id + '"' + (String(m.id) === String(mechanic) ? ' selected' : '') + '>' + esc(m.name) + '</option>';
                });
                $('#pj-mechanic').html(html).trigger('lft:refresh');
            });
        }

        api('/api/journal', { q: params.q || null, mechanic_id: mechanic || null, lift_id: params.lift || null, limit: 100 }).then(function (data) {
            if (token === renderToken) {
                drawPersonalJournal(data, master, params);
            }
        }, function (error) {
            if (token === renderToken) {
                $('#pj-body').html('<div class="app-pad">' + errorWindow(error, 'Журнал') + '</div>');
            }
        });
    }

    function drawPersonalJournal(data, master, params) {
        var $body = $('#pj-body');
        if (!data.items.length) {
            $body.html('<div class="app-pad"><p class="lft-label">Журнал</p><p class="fp-title">' + (params.q || params.lift ? 'Ничего не нашлось' : 'Записей пока нет') + '</p>'
                + '<p class="fp-desc">Записи появляются из ИИ-диагностики: разберите неисправность и нажмите «Сохранить в журнал» — '
                + 'адрес и лифт выбираются в два касания, текст записи соберётся сам.</p>'
                + '<p class="app-nf-back"><a class="lft-btn" href="#/ai">' + icon('chip') + ' Открыть ИИ-диагностику</a></p></div>');
            return;
        }

        var html = '<div class="fp-cols app-journal-cols"><section class="app-pj-list">';
        if (params.lift) {
            html += '<p class="lft-label">Один лифт · <a class="app-link" href="' + hashFor('journal', { mechanic: params.mechanic, q: params.q }) + '">показать все</a></p>';
        }
        html += '<p class="lft-label">Записей: ' + data.total + '</p>';

        // У мастера без фильтра — по механикам, внутри каждого по дате (сервер отдаёт по дате)
        var grouped = master && !params.mechanic;
        var mechanicOf = function (e) { return e.lift.mechanic ? e.lift.mechanic.name : 'Без механика'; };
        var items = data.items.slice();
        if (grouped) {
            items = $.map(items, function (e, i) { return { e: e, i: i }; }).sort(function (a, b) {
                return mechanicOf(a.e).localeCompare(mechanicOf(b.e), 'ru') || a.i - b.i;
            }).map(function (x) { return x.e; });
        }
        var group = null;
        $.each(items, function (_, e) {
            var groupKey = grouped ? mechanicOf(e) : null;
            if (groupKey !== group && groupKey !== null) {
                group = groupKey;
                html += '<h2 class="fp-h app-pj-group">' + esc(group) + '</h2>';
            }
            html += entryHtml(e, master);
        });
        html += '</section><section class="fp-side">' + statsHtml(data.stats, params) + '</section></div>';
        $body.html(html);
    }

    function entryHtml(e, master) {
        var status = STATUSES[e.status] || { text: e.status, cls: '' };
        var mine = session.me.mechanic && e.author && e.author.id === session.me.mechanic.id;
        var details = '';
        $.each([['Что было', e.symptoms], ['Причина', e.cause], ['Что сделано', e.work_done], ['Дальше', e.recommendation]], function (_, f) {
            if (f[1]) {
                details += '<div class="app-pj-field"><span class="lft-label">' + f[0] + '</span><p>' + esc(f[1]) + '</p></div>';
            }
        });
        return '<article class="app-pj-entry">'
            + '<div class="app-pj-top"><span class="lft-label">' + formatDateTime(e.occurred_at) + '</span>'
            + '<span class="lft-badge ' + status.cls + '">' + esc(status.text) + '</span></div>'
            + '<a class="app-pj-lift" href="' + hashFor('journal', { lift: e.lift.id, mechanic: master ? currentRoute().params.mechanic : null }) + '">'
            + icon('building', 'lft-icon--12') + esc(liftName(e.lift)) + '</a>'
            + '<p class="app-pj-title">' + (e.codes ? '<b class="app-pj-codes">' + esc(e.codes) + '</b>' : '') + esc(e.title) + '</p>'
            + (details ? '<details class="app-fix"><summary>' + icon('journal', 'lft-icon--12') + '<span>Подробно</span><span class="lft-label">'
                + (e.source === 'ai' ? 'из ИИ' : 'вручную') + '</span></summary><div class="app-fix-body">' + details
                + '<p class="lft-label">Записал: ' + esc(e.author ? e.author.name : 'мастер') + '</p>'
                + (mine || master ? '<button type="button" class="lft-btn lft-btn--ghost app-pj-del" data-entry="' + e.id + '">Удалить запись</button>' : '')
                + '</div></details>' : '')
            + '</article>';
    }

    function statsHtml(stats, params) {
        var html = '<div><h2 class="fp-h app-h-icon">' + icon('fault') + 'Повторы за 90 дней</h2>';
        if (!stats.lifts.length) {
            return html + '<p class="app-empty">Пока пусто.</p></div>';
        }
        var max = stats.lifts[0].entries;
        html += '<div class="jr-bars">';
        $.each(stats.lifts.slice(0, 8), function (_, l) {
            html += '<a class="jr-bar app-pj-bar" href="' + hashFor('journal', { lift: l.lift_id, mechanic: params.mechanic }) + '" title="' + esc(liftName(l)) + '">'
                + '<b>' + esc(l.address + (l.object_name ? ' ' + l.object_name : '')) + ' · ' + l.lift_no + '</b>'
                + '<i' + (l.open ? '' : ' class="m"') + ' style="width:' + Math.max(8, Math.round(l.entries / max * 100)) + '%"></i><span>' + l.entries + '</span></a>';
        });
        html += '</div><p class="lft-label app-note">Оранжевым — есть незакрытые: повторный выезд или не устранено</p></div>';
        if (stats.codes.length) {
            html += '<div><h2 class="fp-h">Частые коды</h2><div class="ch-src">';
            $.each(stats.codes.slice(0, 10), function (_, c) {
                html += '<a href="' + hashFor('codes', { code: c.code }) + '">' + esc(c.code) + ' · ' + c.entries + '</a>';
            });
            html += '</div></div>';
        }
        return html;
    }

    // ---------- Сохранение разбора из ИИ в журнал ----------
    // Механик выбирает, чей лифт, адрес и номер лифта, а текст записи собирается сам из переписки.

    var saving = { mechanic: null, place: null, lift: null, draft: null, draftRequest: null };

    function openSaveSheet() {
        if (!ai.messages.length) {
            return;
        }
        saving = { mechanic: session.me.mechanic ? session.me.mechanic.id : null, place: null, lift: null, draft: null, draftRequest: null };
        $('body').addClass('app-dd-lock').append('<div class="app-dd-backdrop app-sheet-backdrop"></div>'
            + '<div class="app-sheet lft-window" role="dialog" aria-label="Сохранить в журнал"><div class="lft-window__bar">'
            + '<span class="lft-label">' + icon('journal', 'lft-icon--12') + ' Сохранить в журнал</span>'
            + '<button type="button" class="lft-window__x app-sheet-close" aria-label="Закрыть">×</button></div>'
            + '<div class="lft-window__body" id="save-body">' + loading('Загружаем лифты') + '</div></div>');

        // Черновик готовится сразу, пока механик выбирает лифт
        saving.draftRequest = apiPost('/api/journal/draft', { messages: ai.messages, answers: ai.answers });
        loadLifts().then(drawSaveSheet, function (error) {
            $('#save-body').html(errorWindow(error, 'Лифты'));
        });
    }

    function closeSaveSheet() {
        $('.app-sheet, .app-sheet-backdrop').remove();
        $('body').removeClass('app-dd-lock');
    }

    function drawSaveSheet(places) {
        api('/api/mechanics').then(function (data) {
            var html = '<div class="app-step"><span class="lft-label">1 · Чей лифт</span><div class="app-types">';
            $.each(data.items, function (_, m) {
                html += '<button type="button" class="fp-chip app-type" data-save-mechanic="' + m.id + '" aria-pressed="' + (m.id === saving.mechanic) + '">' + esc(m.name) + '</button>';
            });
            html += '</div></div><div class="app-step" id="save-place"></div><div class="app-step" id="save-lift"></div><div id="save-draft"></div>';
            $('#save-body').html(html);
            if (saving.mechanic === null && data.items.length) {
                saving.mechanic = data.items[0].id;
                $('[data-save-mechanic="' + saving.mechanic + '"]').attr('aria-pressed', 'true');
            }
            drawPlaces(places);
        });
    }

    function drawPlaces(places) {
        var mine = $.grep(places, function (p) { return p.mechanic_id === saving.mechanic; });
        var html = '<span class="lft-label">2 · Адрес</span>'
            + '<label class="fp-field app-save-filter">' + icon('search') + '<input class="app-input" id="save-filter" autocomplete="off" placeholder="Начните вводить адрес"></label>'
            + '<div class="app-places" id="save-places">';
        $.each(mine, function (i, p) {
            html += '<button type="button" class="app-pick-row" data-place="' + i + '" data-text="' + esc(placeName(p).toLowerCase()) + '">'
                + '<span class="app-pick-main">' + esc(placeName(p)) + '</span>'
                + '<span class="lft-label">' + p.lifts.length + ' ' + plural(p.lifts.length, 'лифт', 'лифта', 'лифтов') + '</span></button>';
        });
        $('#save-place').html(html + '</div>').data('places', mine);
        $('#save-lift, #save-draft').empty();
    }

    function choosePlace(place) {
        saving.place = place;
        $('#save-places .app-pick-row').attr('aria-pressed', 'false');
        var html = '<span class="lft-label">3 · Лифт на адресе ' + esc(placeName(place)) + '</span><div class="app-liftnos">';
        $.each(place.lifts, function (_, l) {
            html += '<button type="button" class="app-liftno" data-save-lift="' + l.id + '" aria-pressed="false">' + l.lift_no
                + (l.entries ? '<small>' + l.entries + '</small>' : '') + '</button>';
        });
        $('#save-lift').html(html + '</div>');
        if (place.lifts.length === 1) {
            chooseLift(place.lifts[0].id);
        } else {
            $('#save-lift')[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function chooseLift(id) {
        saving.lift = id;
        $('.app-liftno').attr('aria-pressed', 'false').filter('[data-save-lift="' + id + '"]').attr('aria-pressed', 'true');
        $('#save-draft').html(loading('Собираем запись из переписки'));
        saving.draftRequest.then(function (result) {
            saving.draft = result;
            drawDraft(result);
        }, function () {
            drawDraft({ draft: { title: '', status: 'monitoring' }, source: 'template' });
        });
    }

    function drawDraft(result) {
        var d = result.draft;
        var now = new Date();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var local = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
        var statusChips = '';
        $.each(STATUSES, function (key, s) {
            statusChips += '<button type="button" class="fp-chip app-type" data-save-status="' + key + '" aria-pressed="' + (d.status === key) + '">' + esc(s.text) + '</button>';
        });
        var field = function (name, label, value, rows) {
            return '<label class="app-draft-field"><span class="lft-label">' + label + '</span>'
                + '<textarea class="app-draft-input" name="' + name + '" rows="' + rows + '">' + esc(value || '') + '</textarea></label>';
        };
        $('#save-draft').html(
            '<div class="app-step app-draft">'
            + '<span class="lft-label">4 · Запись' + (result.source === 'ai' ? ' · собрана ИИ, проверьте' : ' · по шаблону') + '</span>'
            + '<label class="app-draft-field"><span class="lft-label">Коды</span><input class="app-draft-input" name="codes" value="' + esc(d.codes || '') + '" autocapitalize="characters" autocorrect="off" spellcheck="false"></label>'
            + field('title', 'Суть', d.title, 2)
            + field('symptoms', 'Что было', d.symptoms, 3)
            + field('cause', 'Причина', d.cause, 2)
            + field('work_done', 'Что сделано', d.work_done, 2)
            + field('recommendation', 'Дальше', d.recommendation, 2)
            + '<div class="app-draft-field"><span class="lft-label">Итог</span><div class="app-types">' + statusChips + '</div></div>'
            + '<label class="app-draft-field"><span class="lft-label">Когда</span><input class="app-draft-input" type="datetime-local" name="occurred_at" value="' + local + '"></label>'
            + '<p class="app-field-error lft-label" id="save-error" hidden></p>'
            + '<button type="button" class="lft-btn app-save-btn" id="save-submit">' + icon('ok') + ' Сохранить запись</button>'
            + '</div>'
        );
        $('#save-draft textarea').each(function () {
            growTextarea(this);
        });
        $('#save-draft')[0].scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    function submitEntry() {
        var body = { lift_id: saving.lift, source: saving.draft && saving.draft.source === 'ai' ? 'ai' : 'manual' };
        $('#save-draft .app-draft-input').each(function () {
            body[this.name] = $.trim($(this).val());
        });
        body.status = $('[data-save-status][aria-pressed="true"]').data('save-status') || 'monitoring';
        body.chat = $.map(ai.messages, function (m, i) {
            var a = ai.answers[i];
            return 'Механик: ' + m + (a && a.summary ? '\nИИ: ' + a.summary : '');
        }).join('\n\n');

        $('#save-submit').prop('disabled', true).attr('aria-busy', 'true');
        apiPost('/api/journal', body).then(function (data) {
            liftsRequest = null;
            $('.app-sheet .lft-window__body').html(
                '<div class="app-done">' + icon('ok') + '</div>'
                + '<p class="fp-title">Записано</p>'
                + '<p class="app-text">' + esc(liftName(data.item.lift)) + '</p>'
                + '<p class="app-text app-muted">' + esc(data.item.title) + '</p>'
                + '<div class="app-login-actions"><a class="lft-btn" href="' + hashFor('journal', { lift: data.item.lift.id }) + '" data-close-sheet>Открыть журнал лифта</a>'
                + '<button type="button" class="lft-btn lft-btn--ghost" id="save-new">Новый разбор</button></div>'
            );
        }, function (error) {
            $('#save-submit').prop('disabled', false).removeAttr('aria-busy');
            $('#save-error').text($.map(error.fields || {}, function (t, k) { return k + ': ' + t; }).join(' · ') || error.message).prop('hidden', false);
        });
    }

    // ---------- Маршрутизация и события ----------

    function render() {
        var route = currentRoute();
        var token = ++renderToken;
        var page = { codes: true, search: true, journal: true, base: true, ai: true }[route.page] ? route.page : 'codes';
        if (page === 'base' && !isMaster()) {
            page = 'journal';
        }

        setActiveNav(page === 'base' ? 'journal' : page);
        document.title = 'Lift Fault Terminal — база неисправностей лифтов';
        window.scrollTo(0, 0);

        closeDropdown(false);
        if (!session.role) {
            renderLogin();
        } else if (page === 'journal') {
            renderJournal(route.params, token);
        } else if (page === 'base') {
            renderBase(route.params, token);
        } else if (page === 'search') {
            renderSearch(route.params, token);
        } else if (page === 'ai') {
            renderAi(token);
        } else {
            renderCodes(route.params, token);
        }
        $view.find('.app-select select').each(function () {
            customSelect(this);
        });
    }

    $(document)
        .on('click', '[data-login]', function () {
            var role = $(this).data('login');
            if (role === 'master') {
                if (!session.me.is_master) {
                    $('#login-more').html('<p class="lft-label app-bad">У аккаунта ' + esc(session.me.login) + ' нет прав мастера</p>');
                    return;
                }
                session.role = 'master';
            } else if (session.me.mechanic && !session.me.demo) {
                session.role = 'mechanic';
            } else {
                $('#login-more').html(pickMechanicHtml(session.me.mechanics));
                return;
            }
            storedRole(session.role);
            drawUser();
            render();
        })
        .on('click', '[data-mechanic]', function () {
            if (session.me.demo) {
                demoValue(DEMO_MECHANIC, String($(this).data('mechanic')));
                liftsRequest = null;
                api('/api/me').then(function (me) {
                    session.me = me;
                    session.role = 'mechanic';
                    storedRole('mechanic');
                    drawUser();
                    render();
                });
                return;
            }
            apiPost('/api/me/link', { mechanic_id: Number($(this).data('mechanic')) }).then(function (me) {
                session.me = me;
                session.role = 'mechanic';
                storedRole('mechanic');
                drawUser();
                render();
            }, function (error) {
                $('#login-more').prepend('<p class="lft-label app-bad">' + esc(error.message) + '</p>');
            });
        })
        .on('click', '#app-user', function () {
            session.role = null;
            storedRole(null);
            drawUser();
            render();
        })
        .on('submit', '#pj-form', function (e) {
            e.preventDefault();
            go('journal', { q: $.trim($('#pj-q').val()), mechanic: $('#pj-mechanic').val() });
        })
        .on('change', '#pj-mechanic', function () {
            go('journal', { q: $.trim($('#pj-q').val()), mechanic: $(this).val() });
        })
        .on('click', '.app-pj-del', function () {
            if (!window.confirm('Удалить запись из журнала?')) {
                return;
            }
            $.ajax({ url: '/api/journal/' + Number($(this).data('entry')), method: 'DELETE' }).then(render, function () {
                window.alert('Не удалось удалить запись');
            });
        })
        .on('click', '#ai-save', openSaveSheet)
        .on('click', '.app-sheet-close, .app-sheet-backdrop, [data-close-sheet]', closeSaveSheet)
        .on('click', '[data-save-mechanic]', function () {
            saving.mechanic = Number($(this).data('save-mechanic'));
            $('[data-save-mechanic]').attr('aria-pressed', 'false');
            $(this).attr('aria-pressed', 'true');
            loadLifts().then(drawPlaces);
        })
        .on('input', '#save-filter', function () {
            var q = $.trim($(this).val()).toLowerCase();
            $('#save-places .app-pick-row').each(function () {
                $(this).prop('hidden', q !== '' && String($(this).data('text')).indexOf(q) === -1);
            });
        })
        .on('click', '[data-place]', function () {
            $('#save-places .app-pick-row').attr('aria-pressed', 'false');
            $(this).attr('aria-pressed', 'true');
            choosePlace($('#save-place').data('places')[Number($(this).data('place'))]);
        })
        .on('click', '[data-save-lift]', function () {
            chooseLift(Number($(this).data('save-lift')));
        })
        .on('click', '[data-save-status]', function () {
            $('[data-save-status]').attr('aria-pressed', 'false');
            $(this).attr('aria-pressed', 'true');
        })
        .on('input', '.app-draft-input', function () {
            if (this.tagName === 'TEXTAREA') {
                growTextarea(this);
            }
        })
        .on('click', '#save-submit', submitEntry)
        .on('click', '#save-new', function () {
            closeSaveSheet();
            $('#ai-new').trigger('click');
        })
        .on('keydown', function (e) {
            if (e.key === 'Escape' && $('.app-sheet').length) {
                closeSaveSheet();
            }
        })
        .on('click', '.app-dd-btn', function () {
            if ($(this).attr('aria-expanded') === 'true') {
                closeDropdown(true);
            } else {
                openDropdown($(this));
            }
        })
        .on('click', '.app-dd-opt', function () {
            var $btn = $(this).closest('.app-dd').data('button');
            var value = String($(this).data('value'));
            closeDropdown(true);
            var $select = $btn.prev('select');
            if ($select.val() !== value) {
                $select.val(value).trigger('change');
            }
        })
        .on('click', '.app-select', function (e) {
            // Поле обёрнуто в <label>: нажатие на иконку передалось бы скрытому <select>
            // и открыло бы системный список. Открываем свой.
            var $btn = $(this).find('.app-dd-btn');
            if ($btn.length && !$(e.target).closest('.app-dd, .app-dd-btn').length) {
                e.preventDefault();
                openDropdown($btn);
            }
        })
        .on('click', '.app-dd-backdrop, .app-dd-close', function () {
            closeDropdown(true);
        })
        .on('click', function (e) {
            if (!$(e.target).closest('.app-dd, .app-dd-btn').length) {
                closeDropdown(false);
            }
        })
        .on('keydown', '.app-dd', function (e) {
            var $opts = $(this).find('.app-dd-opt');
            var i = $opts.index(document.activeElement);
            if (e.key === 'Escape') {
                e.preventDefault();
                closeDropdown(true);
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                $opts.eq(Math.max(0, Math.min($opts.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))).trigger('focus');
            } else if (e.key === 'Tab') {
                closeDropdown(false);
            }
        })
        .on('keydown', '.app-dd-btn', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                openDropdown($(this));
            }
        })
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
            go('base', { model: $(this).val() });
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
        })
        .on('submit', '#search-form', function (e) {
            e.preventDefault();
            submitSearch();
        })
        .on('change', '#search-model', function () {
            if ($.trim($('#search-input').val())) {
                submitSearch();
            }
        })
        .on('click', '.app-type', function () {
            $(this).attr('aria-pressed', $(this).attr('aria-pressed') === 'true' ? 'false' : 'true');
            if ($.trim($('#search-input').val())) {
                submitSearch();
            }
        })
        .on('submit', '#ai-form', function (e) {
            e.preventDefault();
            submitAi();
        })
        .on('keydown', '#ai-text', function (e) {
            // С клавиатурой Enter отправляет, Shift+Enter переносит строку.
            // На телефоне Enter переносит строку, отправка — кнопкой.
            var desktop = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
            if (e.key === 'Enter' && ((desktop && !e.shiftKey) || e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                submitAi();
            }
        })
        .on('input', '#ai-text', function () {
            growTextarea(this);
        })
        .on('input', '#ai-code', function () {
            ai.code = $.trim($(this).val());
            saveAi();
            updateAiForm();
        })
        .on('change', '#ai-model', function () {
            ai.model = $(this).val();
            saveAi();
            updateAiForm();
        })
        .on('click', '#ai-toggle', function () {
            var open = $(this).attr('aria-expanded') !== 'true';
            $(this).attr('aria-expanded', String(open));
            $('#ai-form').toggleClass('is-open', open);
        })
        .on('click', '#ai-new', function () {
            ai.thread = [];
            ai.messages = [];
            ai.answers = [];
            saveAi();
            renderAi(renderToken);
            $view.find('.app-select select').each(function () {
                customSelect(this);
            });
            window.scrollTo(0, 0);
        })
        .on('click', '[data-example]', function () {
            var ex = AI_EXAMPLES[Number($(this).data('example'))];
            $('#ai-text').val(ex.d);
            $('#ai-code').val(ex.c);
            ai.code = ex.c;
            submitAi();
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
    startSession();
}(jQuery));
