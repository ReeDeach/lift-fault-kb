/*
 * Lift Fault Terminal: интерфейс справочника на jQuery.
 * Экраны переключаются по адресу после #: #/codes, #/journal, #/base, #/ai, #/requests, #/chat.
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

    // Модели сгруппированы по производителю, как в списке кодов. Модели без кодов не
    // показываем (кроме уже выбранной, чтобы фильтр не сбросился молча).
    function modelOptions(models, selected) {
        var html = '<option value="">Все модели</option>';
        var makers = [];
        var byMaker = {};
        $.each(models, function (_, m) {
            if (m.codes_count === 0 && String(m.id) !== String(selected || '')) {
                return;
            }
            var maker = m.manufacturer.name;
            if (!byMaker[maker]) {
                byMaker[maker] = [];
                makers.push(maker);
            }
            byMaker[maker].push(m);
        });
        $.each(makers, function (_, maker) {
            html += '<optgroup label="' + esc(maker) + '">';
            $.each(byMaker[maker], function (_, m) {
                var value = String(m.id);
                html += '<option value="' + esc(value) + '"' + (value === String(selected || '') ? ' selected' : '')
                    + ' data-maker="' + esc(maker) + '" data-name="' + esc(m.name) + '" data-count="' + esc(m.codes_count) + '">'
                    + esc(maker + ' · ' + m.name) + ' (' + m.codes_count + ')</option>';
            });
            html += '</optgroup>';
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
        function optionHtml($o, grouped) {
            var out = '<button type="button" class="app-dd-opt" role="option" aria-selected="' + $o.is(':selected') + '" data-value="' + esc($o.val()) + '">';
            if ($o.data('name')) {
                // В группе производитель уже в заголовке
                out += (grouped ? '' : '<span class="lft-label">' + esc($o.data('maker')) + '</span>')
                    + '<span class="app-dd-name">' + esc($o.data('name')) + '</span>'
                    + '<span class="app-dd-count">' + esc($o.data('count')) + ' ' + plural(Number($o.data('count')), 'код', 'кода', 'кодов') + '</span>';
            } else {
                out += '<span class="app-dd-name">' + esc($o.text()) + '</span>';
            }
            return out + '</button>';
        }
        $select.children().each(function () {
            var $c = $(this);
            if ($c.is('optgroup')) {
                html += '<div class="app-dd-group lft-label" role="presentation">' + esc($c.attr('label')) + '</div>';
                $c.children('option').each(function () {
                    html += optionHtml($(this), true);
                });
            } else {
                html += optionHtml($c, false);
            }
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
            + '<button class="lft-btn app-find" type="submit" aria-label="Найти код">' + icon('search') + '<span>Найти код</span></button>'
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

    // Строки кодов с подзаголовками устройств (УЛ, ПЧ); labelOf — своя подпись устройства
    function codeRowsHtml(items, model, activeId, labelOf) {
        var html = '';
        var group = null;
        $.each(items, function (_, item) {
            var on = String(item.id) === String(activeId || '');
            var owner = (labelOf || ownerLabel)(item.owner);
            if (owner !== group) {
                group = owner;
                html += '<li class="app-group lft-label">' + esc(owner) + '</li>';
            }
            html += '<li' + (on ? ' class="on"' : '') + '><a href="' + hashFor('codes', { model: model, id: item.id }) + '">'
                + '<b class="' + (item.is_safety_related ? 'app-safety' : '') + '">' + esc(item.code) + '</b>'
                + '<span>' + esc(item.title) + '</span></a></li>';
        });
        return html;
    }

    function renderCodeList(model, activeId, token) {
        if (!model) {
            renderModelGroups(activeId, token);
            return;
        }
        var key = model;
        var $list = $('#code-list');

        function draw() {
            var html = '<span class="lft-label app-list-head">Коды · ' + listCache.items.length + ' из ' + listCache.total + '</span>'
                + '<ul class="fp-list">' + codeRowsHtml(listCache.items, model, activeId) + '</ul>';
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

        api('/api/codes', { model: model, limit: PAGE_LIMIT, offset: 0 }).then(function (data) {
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

    // ---------- Список кодов по производителям: вкладка раскрывается по нажатию ----------
    // Коды принадлежат устройствам (УЛ, ПЧ), а устройства стоят на моделях. Без выбранной
    // модели слева — производители, внутри — их коды по устройствам. У устройства, которое
    // стоит на модели, в подзаголовке указана модель; исполнения без привязки идут следом.

    var makersCache = null;
    var openGroups = {};

    function makerGroups() {
        if (makersCache) {
            return makersCache;
        }
        makersCache = $.when(loadModels(), loadAllCodes({})).then(function (models, codes) {
            var groups = [];
            var byMaker = {};
            var modelMaker = {};
            var ctrlModels = {};

            function group(maker) {
                if (!byMaker[maker]) {
                    byMaker[maker] = { key: 'g' + groups.length, maker: maker, models: [], extra: false, items: [] };
                    groups.push(byMaker[maker]);
                }
                return byMaker[maker];
            }

            $.each(models, function (_, m) {
                modelMaker[m.id] = m.manufacturer.name;
                if (m.codes_count > 0) {
                    group(m.manufacturer.name).models.push(m.name);
                }
                $.each(m.controllers, function (_, c) {
                    (ctrlModels[c.id] = ctrlModels[c.id] || []).push(m);
                });
            });

            var order = {};
            $.each(codes, function (i, item) {
                var o = item.owner;
                var linked = o.type === 'model' || !!ctrlModels[o.id];
                var maker = o.type === 'model' ? modelMaker[o.id]
                    : linked ? ctrlModels[o.id][0].manufacturer.name
                        : (o.vendor || 'Другие устройства');
                var g = group(maker);
                var ownerKey = o.type + o.id;
                if (!(ownerKey in order)) {
                    order[ownerKey] = (linked ? (o.kind === 'drive' ? 1 : 0) : 2) * 100000 + i;
                }
                g.extra = g.extra || !linked;
                g.items.push({ item: item, rank: order[ownerKey], pos: i });
            });

            $.each(groups, function (_, g) {
                // Коды одного устройства подряд: УЛ модели, её ПЧ, потом другие исполнения
                g.items.sort(function (a, b) { return a.rank - b.rank || a.pos - b.pos; });
                g.items = $.map(g.items, function (x) { return x.item; });
                g.note = g.models.join(', ') + (g.extra ? (g.models.length ? ' + другие исполнения' : 'Устройства без модели') : '');
            });
            return {
                groups: $.grep(groups, function (g) { return g.items.length > 0; }),
                labelOf: function (owner) {
                    var on = owner.type === 'controller' && ctrlModels[owner.id];
                    return ownerLabel(owner) + (on ? ' — на ' + $.map(on, function (m) { return m.name; }).join(', ') : '');
                }
            };
        });
        makersCache.fail(function () {
            makersCache = null;
        });
        return makersCache;
    }

    function renderModelGroups(activeId, token) {
        var $list = $('#code-list');
        if (!$list.find('.app-acc-list').length) {
            $list.html('<span class="lft-label app-list-head">Загрузка…</span>');
        }
        makerGroups().then(function (data) {
            if (token !== renderToken) {
                return;
            }
            var html = '<span class="lft-label app-list-head">Производители · ' + data.groups.length + '</span><div class="app-acc-list">';
            $.each(data.groups, function (_, g) {
                html += accHtml(g.key, g.note, g.maker, g.items.length);
            });
            $list.html(html + '</div>');
            $.each(openGroups, function (key, open) {
                if (open) {
                    openGroup(key, activeId, false);
                }
            });
        }, function (error) {
            if (token === renderToken) {
                $list.html('<div class="app-pad">' + errorWindow(error, 'Список кодов') + '</div>');
            }
        });
    }

    function accHtml(key, note, name, count) {
        return '<div class="app-acc" data-acc="' + key + '">'
            + '<button type="button" class="app-acc-head" aria-expanded="false" aria-controls="acc-' + key + '">'
            + '<span class="app-acc-title"><span class="lft-label">' + esc(note) + '</span><span class="app-acc-name">' + esc(name) + '</span></span>'
            + '<span class="app-acc-count">' + count + ' ' + plural(count, 'код', 'кода', 'кодов') + '</span>'
            + '</button><ul class="fp-list app-acc-body" id="acc-' + key + '" hidden></ul></div>';
    }

    function openGroup(key, activeId, scroll) {
        var $acc = $('.app-acc[data-acc="' + key + '"]');
        if (!$acc.length) {
            return;
        }
        var $body = $acc.find('.app-acc-body');
        openGroups[key] = true;
        $acc.addClass('is-open').find('.app-acc-head').attr('aria-expanded', 'true');
        $body.prop('hidden', false);
        makerGroups().then(function (data) {
            var found = $.grep(data.groups, function (g) { return g.key === key; });
            // Ссылки без фильтра модели: иначе список переключится в плоский вид одной модели
            $body.html(found.length ? codeRowsHtml(found[0].items, null, activeId, data.labelOf)
                : '<li class="app-group lft-label">Кодов пока нет</li>');
            if (scroll) {
                var $on = $body.find('li.on');
                ($on.length ? $on : $acc)[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        });
    }

    function closeGroup(key) {
        var $acc = $('.app-acc[data-acc="' + key + '"]');
        openGroups[key] = false;
        $acc.removeClass('is-open').find('.app-acc-head').attr('aria-expanded', 'false');
        $acc.find('.app-acc-body').prop('hidden', true);
    }

    // Открытый код — раскрыть его производителя в списке слева, если он ещё свёрнут
    function revealInList(item) {
        if (currentRoute().params.model) {
            return;
        }
        makerGroups().then(function (data) {
            $.each(data.groups, function (_, g) {
                if ($.grep(g.items, function (i) { return i.id === item.id; }).length) {
                    if (!openGroups[g.key]) {
                        openGroup(g.key, item.id, true);
                    }
                    return false;
                }
                return true;
            });
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
                revealInList(data.item);
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

        // Хватает кода («44») или пары слов: подробности ИИ спросит сам
        if (text.length < 2) {
            $error.text(followUp ? 'Напишите уточнение' : 'Опишите неисправность или напишите код').prop('hidden', false);
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
        $('#app-push').prop('hidden', !session.role);
        syncPush();
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
            + '<img class="app-login-mark app-logo-dark" src="/assets/img/lft-mark.svg" alt="">'
            + '<img class="app-login-mark app-logo-light" src="/assets/img/lft-mark-light.svg" alt="">'
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
            + '<div class="fp-field app-field-search">'
            + '<input class="app-input" id="pj-q" type="search" value="' + esc(params.q || '') + '" autocomplete="off" '
            + 'placeholder="Адрес, код или слово из записи" aria-label="Поиск по журналу">'
            + '<button type="submit" class="app-field-go" aria-label="Найти" title="Найти">' + icon('search') + '</button></div>'
            + (master ? '<label class="fp-chip app-select">' + icon('settings')
                + '<select id="pj-mechanic" aria-label="Механик"><option value="">Все механики</option></select></label>' : '')
            + '<button type="button" class="lft-btn lft-btn--ghost app-add-entry" data-add-entry>' + icon('journal') + 'Добавить запись</button>'
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
                + '<p class="app-nf-back"><a class="lft-btn" href="#/ai">' + icon('chip') + ' Открыть ИИ-диагностику</a>'
                + ' <button type="button" class="lft-btn lft-btn--ghost app-add-entry" data-add-entry>' + icon('journal') + 'Добавить вручную</button></p></div>');
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
    // Та же шторка открывается из журнала кнопкой «Добавить запись»: тогда записи без ИИ,
    // например закрыть заявку по лифту, и поля заполняются вручную.

    var saving = { mechanic: null, place: null, lift: null, draft: null, draftRequest: null, manual: false, preset: null };

    // preset — запись по заявке 1С: {repair_id, lift: {id, label} | null, draft: {codes, title, …}}
    function openSaveSheet(manual, preset) {
        if (!manual && !ai.messages.length) {
            return;
        }
        saving = { mechanic: session.me.mechanic ? session.me.mechanic.id : null, place: null, lift: null, draft: null, draftRequest: null, manual: !!manual, preset: preset || null };
        var title = manual ? 'Новая запись в журнал' : 'Сохранить в журнал';
        $('body').addClass('app-dd-lock').append('<div class="app-dd-backdrop app-sheet-backdrop"></div>'
            + '<div class="app-sheet lft-window" role="dialog" aria-label="' + title + '"><div class="lft-window__bar">'
            + '<span class="lft-label">' + icon('journal', 'lft-icon--12') + ' ' + title + '</span>'
            + '<button type="button" class="lft-window__x app-sheet-close" aria-label="Закрыть">×</button></div>'
            + '<div class="lft-window__body" id="save-body">' + loading('Загружаем лифты') + '</div></div>');

        // Черновик из переписки готовится сразу, пока механик выбирает лифт. Вручную — пустой
        saving.draftRequest = manual
            ? $.Deferred().resolve({ draft: $.extend({ title: '', status: 'resolved' }, preset ? preset.draft : {}), source: 'manual' }).promise()
            : apiPost('/api/journal/draft', { messages: ai.messages, answers: ai.answers });
        if (preset && preset.lift) {
            // Лифт заявки уже известен: сразу к записи
            $('#save-body').html('<div class="app-step"><span class="lft-label">Лифт</span><p class="app-text">' + esc(preset.lift.label) + '</p></div><div id="save-draft"></div>');
            chooseLift(preset.lift.id);
            return;
        }
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
        $('#save-draft').html(loading(saving.manual ? 'Открываем запись' : 'Собираем запись из переписки'));
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
        var local = d.occurred_at || (now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes()));
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
            + '<span class="lft-label">4 · Запись' + (result.source === 'ai' ? ' · собрана ИИ, проверьте' : (result.source === 'manual' ? ' · заполните' : ' · по шаблону')) + '</span>'
            + '<label class="app-draft-field"><span class="lft-label">Коды</span><input class="app-draft-input" name="codes" value="' + esc(d.codes || '') + '" autocapitalize="characters" autocorrect="off" spellcheck="false"></label>'
            + field('title', 'Суть' + (saving.manual ? ' · например, заявка: лифт стоит, код 50' : ''), d.title, 2)
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
        if (!saving.manual) {
            body.chat = $.map(ai.messages, function (m, i) {
                var a = ai.answers[i];
                return 'Механик: ' + m + (a && a.summary ? '\nИИ: ' + a.summary : '');
            }).join('\n\n');
        }

        $('#save-submit').prop('disabled', true).attr('aria-busy', 'true');
        apiPost('/api/journal', body).then(function (data) {
            liftsRequest = null;
            if (saving.preset && saving.preset.repair_id) {
                apiPost('/api/repairs/' + saving.preset.repair_id + '/journal', { entry_id: data.item.id, as: asRole() });
            }
            $('.app-sheet .lft-window__body').html(
                '<div class="app-done">' + icon('ok') + '</div>'
                + '<p class="fp-title">Записано</p>'
                + '<p class="app-text">' + esc(liftName(data.item.lift)) + '</p>'
                + '<p class="app-text app-muted">' + esc(data.item.title) + '</p>'
                + '<div class="app-login-actions"><a class="lft-btn" href="' + hashFor('journal', { lift: data.item.lift.id }) + '" data-close-sheet>Открыть журнал лифта</a>'
                + (saving.manual
                    ? '<button type="button" class="lft-btn lft-btn--ghost" data-close-sheet data-refresh>Готово</button></div>'
                    : '<button type="button" class="lft-btn lft-btn--ghost" id="save-new">Новый разбор</button></div>')
            );
        }, function (error) {
            $('#save-submit').prop('disabled', false).removeAttr('aria-busy');
            $('#save-error').text($.map(error.fields || {}, function (t, k) { return k + ': ' + t; }).join(' · ') || error.message).prop('hidden', false);
        });
    }

    // ---------- Связь: заявки на запчасти и общий чат ----------
    // Заявку подаёт механик, мастер отвечает в её переписке и меняет статус. Чат общий для
    // механиков всех участков и мастера. Новые сообщения подтягиваются опросом сервера.
    // Фото телефон сам уменьшает до 1600 точек по стороне и отправляет JPEG: так снимок
    // весит сотни килобайт, а не мегабайты, и в нём не остаётся координат съёмки.

    var REQUEST_STATUSES = {
        new: { text: 'новая', cls: 'lft-badge--warn' },
        accepted: { text: 'принята', cls: '' },
        ordered: { text: 'заказана', cls: '' },
        ready: { text: 'пришла', cls: 'lft-badge--ok' },
        issued: { text: 'выдана', cls: '' },
        rejected: { text: 'отклонена', cls: 'lft-badge--alarm' }
    };
    var STATUS_EVENTS = {
        new: 'Заявка подана',
        accepted: 'Заявка принята',
        ordered: 'Деталь заказана',
        ready: 'Деталь пришла, можно забирать',
        issued: 'Деталь выдана',
        rejected: 'Заявка отклонена'
    };
    var STATUS_STEPS = ['new', 'accepted', 'ordered', 'ready', 'issued'];
    var MASTER_STATUSES = ['accepted', 'ordered', 'ready', 'issued', 'rejected'];
    var PHOTO_SIDE = 1600;
    var MAX_PHOTOS = 4;
    var SEEN_CHAT = 'lft:seen-chat';
    var SEEN_REQUESTS = 'lft:seen-requests';
    var trays = {};
    var pollTimer = null;

    function asRole() {
        return isMaster() ? 'master' : undefined;
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function startOfDay(d) {
        var x = new Date(d);
        x.setHours(0, 0, 0, 0);
        return x;
    }

    // Время сообщения: сегодня — только часы, вчера — «вчера», раньше — дата
    function when(iso) {
        var d = new Date(iso);
        if (isNaN(d.getTime())) {
            return '';
        }
        var hm = pad2(d.getHours()) + ':' + pad2(d.getMinutes());
        var days = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
        if (days === 0) {
            return hm;
        }
        return (days === 1 ? 'вчера' : pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1)) + ' ' + hm;
    }

    function clock(iso) {
        var d = new Date(iso);
        return isNaN(d.getTime()) ? '' : pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function dayLabel(iso) {
        var d = new Date(iso);
        var days = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
        if (days === 0) {
            return 'Сегодня';
        }
        if (days === 1) {
            return 'Вчера';
        }
        return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
    }

    function remember(key, value) {
        try {
            if (value === undefined) {
                return localStorage.getItem(key);
            }
            localStorage.setItem(key, value);
        } catch (e) { /* без хранилища значки «новое» просто не запоминаются */ }
        return null;
    }

    function statusBadge(status) {
        var s = REQUEST_STATUSES[status] || { text: status, cls: '' };
        return '<span class="lft-badge app-req-status ' + s.cls + '">' + esc(s.text) + '</span>';
    }

    function photosHtml(photos) {
        if (!photos || !photos.length) {
            return '';
        }
        var html = '<div class="app-photos app-photos--' + Math.min(photos.length, 4) + '">';
        $.each(photos, function (_, p) {
            html += '<button type="button" class="app-photo" data-photo="' + esc(p.url) + '" aria-label="Открыть фото">'
                + '<img src="' + esc(p.url) + '" alt="" loading="lazy" width="' + Number(p.width) + '" height="' + Number(p.height) + '"></button>';
        });
        return html + '</div>';
    }

    // Сообщение чата или переписки по заявке. В заявке смена статуса — отдельной строкой.
    function messageHtml(m, inRequest) {
        var master = m.author.role === 'master';
        var who = master ? 'Мастер' : m.author.name;
        // «Моё» — с этого аккаунта и в той же роли: мастер, он же механик, видит свои сообщения
        // мастером справа только в роли мастера
        var mine = m.mine && master === isMaster();
        var cls = 'app-msg' + (mine ? ' is-mine' : '') + (master ? ' is-master' : '');
        var html = '<div class="' + cls + '" data-msg="' + Number(m.id) + '">';
        var bubble = !!(m.body || m.photos.length);
        if (inRequest && m.status) {
            // Кто и когда — в сообщении под строкой статуса, а если текста нет — в самой строке
            html += '<p class="app-msg-event lft-label">' + icon(m.status === 'rejected' ? 'fault' : 'ok', 'lft-icon--12')
                + '<span>' + esc(STATUS_EVENTS[m.status] || m.status) + (bubble ? '' : ' · ' + esc(who) + ' · ' + esc(clock(m.at))) + '</span></p>';
        }
        if (bubble) {
            html += '<div class="app-msg-bubble">'
                + '<span class="app-msg-who lft-label">' + icon(master ? 'building' : 'settings', 'lft-icon--12')
                + '<b>' + esc(who) + '</b>'
                + (!master && !inRequest && m.author.section ? '<span>' + esc(m.author.section) + '</span>' : '')
                + '<time>' + esc(clock(m.at)) + '</time></span>'
                + (m.body ? '<p class="app-msg-text">' + esc(m.body) + '</p>' : '')
                + photosHtml(m.photos)
                + '</div>';
        }
        return html + '</div>';
    }

    // Лента сообщений с подписями дней
    function appendMessages($list, messages, inRequest) {
        var lastDay = $list.data('day') || '';
        var html = '';
        $.each(messages, function (_, m) {
            if ($list.find('[data-msg="' + Number(m.id) + '"]').length) {
                return;
            }
            var day = dayLabel(m.at);
            if (day !== lastDay) {
                html += '<p class="app-day lft-label"><span>' + esc(day) + '</span></p>';
                lastDay = day;
            }
            html += messageHtml(m, inRequest);
        });
        $list.data('day', lastDay).append(html);
    }

    function nearBottom() {
        return window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 160;
    }

    function scrollToBottom() {
        window.scrollTo(0, document.documentElement.scrollHeight);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function poll(token, seconds, fn) {
        stopPolling();
        pollTimer = setInterval(function () {
            if (token !== renderToken) {
                stopPolling();
                return;
            }
            if (!document.hidden) {
                fn();
            }
        }, seconds * 1000);
    }

    // ---------- Фото: уменьшение на телефоне и загрузка ----------

    function shrinkPhoto(file) {
        var d = $.Deferred();
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
            var scale = Math.min(1, PHOTO_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(url);
            d.resolve(canvas.toDataURL('image/jpeg', 0.82));
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            d.reject({ message: 'Не удалось открыть фото. Выберите снимок JPEG или PNG' });
        };
        img.src = url;
        return d.promise();
    }

    function trayHtml(key) {
        return '<div class="app-tray" data-tray="' + key + '" hidden></div>';
    }

    function attachHtml(key) {
        return '<label class="app-attach" title="Приложить фото" aria-label="Приложить фото">' + icon('camera')
            + '<input type="file" accept="image/*" multiple data-tray-input="' + key + '"></label>';
    }

    function drawTray(key) {
        var items = trays[key] || [];
        var html = '';
        $.each(items, function (i, p) {
            html += '<span class="app-tray-item' + (p.busy ? ' is-busy' : '') + '"><img src="' + esc(p.preview) + '" alt="">'
                + (p.busy ? '<i class="lft-label">…</i>' : '')
                + '<button type="button" class="app-tray-x" data-tray-remove="' + key + '" data-index="' + i + '" aria-label="Убрать фото">×</button></span>';
        });
        $('[data-tray="' + key + '"]').html(html).prop('hidden', !items.length);
        $('[data-tray-input="' + key + '"]').closest('.app-attach').toggleClass('is-full', items.length >= MAX_PHOTOS);
    }

    function addPhotos(key, files, $error) {
        trays[key] = trays[key] || [];
        var room = MAX_PHOTOS - trays[key].length;
        if (files.length > room) {
            $error.text('Не больше ' + MAX_PHOTOS + ' фото в одном сообщении').prop('hidden', false);
        }
        $.each(Array.prototype.slice.call(files, 0, Math.max(0, room)), function (_, file) {
            var item = { id: null, busy: true, preview: URL.createObjectURL(file) };
            trays[key].push(item);
            drawTray(key);
            shrinkPhoto(file).then(function (data) {
                return apiPost('/api/uploads', { data: data });
            }).then(function (res) {
                item.id = res.item.id;
                item.busy = false;
                drawTray(key);
            }, function (error) {
                trays[key] = $.grep(trays[key], function (p) { return p !== item; });
                URL.revokeObjectURL(item.preview);
                drawTray(key);
                $error.text(error.message).prop('hidden', false);
            });
        });
    }

    function trayIds(key) {
        return $.map(trays[key] || [], function (p) { return p.id; });
    }

    function trayBusy(key) {
        return $.grep(trays[key] || [], function (p) { return p.busy; }).length > 0;
    }

    function clearTray(key) {
        $.each(trays[key] || [], function (_, p) {
            URL.revokeObjectURL(p.preview);
        });
        trays[key] = [];
        drawTray(key);
    }

    function errorText(error) {
        var fields = $.map(error.fields || {}, function (t) { return t; });
        return fields.length ? fields.join(' · ') : error.message;
    }

    function composerHtml(key, placeholder, extra) {
        return '<form class="ch-input app-ai-form app-compose" data-compose="' + key + '">'
            + (extra || '')
            + trayHtml(key)
            + '<div class="app-ai-row">'
            + attachHtml(key)
            + '<label class="fp-field app-ai-text">'
            + '<textarea class="app-input" data-compose-text rows="1" maxlength="2000" placeholder="' + esc(placeholder) + '" aria-label="Сообщение"></textarea></label>'
            + '<button class="lft-btn app-ai-send" type="submit" aria-label="Отправить">' + icon('arrow-up') + '<span>Отправить</span></button>'
            + '</div>'
            + '<p class="app-field-error lft-label" data-compose-error hidden></p>'
            + '</form>';
    }

    // ---------- Экран «Чат» ----------

    var chatState = { first: null, last: 0 };

    function renderChat(token) {
        chatState = { first: null, last: 0 };
        trays.chat = [];
        $view.html(
            '<div class="app-ai-page app-chat-page">'
            + '<div class="app-chat-head"><span class="lft-label">' + icon('chat', 'lft-icon--12') + ' Чат · все участки</span>'
            + '<span class="lft-label app-muted">механики и мастер</span></div>'
            + '<div class="app-msgs" id="chat-list">' + loading('Загрузка сообщений') + '</div>'
            + composerHtml('chat', 'Сообщение в общий чат')
            + '</div>'
        );

        api('/api/chat').then(function (data) {
            if (token !== renderToken) {
                return;
            }
            var $list = $('#chat-list').empty().removeData('day');
            if (data.items.length >= 50) {
                $list.append('<div class="app-more"><button type="button" class="lft-btn lft-btn--ghost" id="chat-older">Раньше</button></div>');
            }
            if (!data.items.length) {
                $list.append('<p class="app-empty" id="chat-empty">Сообщений пока нет. Напишите первым: вопрос по неисправности, ищу деталь, кто рядом.</p>');
            }
            appendMessages($list, data.items, false);
            noteChat(data.items);
            scrollToBottom();
            poll(token, 4, pollChat);
        }, function (error) {
            $('#chat-list').html('<div class="app-pad">' + errorWindow(error, 'Чат') + '</div>');
        });
    }

    function noteChat(items) {
        if (!items.length) {
            return;
        }
        chatState.first = chatState.first === null ? items[0].id : Math.min(chatState.first, items[0].id);
        chatState.last = Math.max(chatState.last, items[items.length - 1].id);
        remember(SEEN_CHAT, String(chatState.last));
        drawDots();
    }

    function pollChat() {
        api('/api/chat', { after: chatState.last }).then(function (data) {
            if (!data.items.length || currentRoute().page !== 'chat') {
                return;
            }
            var stick = nearBottom();
            $('#chat-empty').remove();
            appendMessages($('#chat-list'), data.items, false);
            noteChat(data.items);
            if (stick) {
                scrollToBottom();
            }
        });
    }

    function loadOlderChat() {
        var $btn = $('#chat-older').prop('disabled', true).text('Загрузка…');
        api('/api/chat', { before: chatState.first }).then(function (data) {
            var height = document.documentElement.scrollHeight;
            var $older = $('<div></div>');
            appendMessages($older, data.items, false);
            $btn.parent().remove();
            // Подпись дня первого из уже показанных сообщений может повториться: убираем лишнюю
            var $list = $('#chat-list');
            var firstDay = $list.children('.app-day').first();
            if (firstDay.length && $older.children('.app-day').last().text() === firstDay.text()) {
                firstDay.remove();
            }
            $list.prepend($older.children());
            if (data.items.length >= 50) {
                $list.prepend('<div class="app-more"><button type="button" class="lft-btn lft-btn--ghost" id="chat-older">Раньше</button></div>');
            }
            if (data.items.length) {
                chatState.first = data.items[0].id;
            }
            window.scrollBy(0, document.documentElement.scrollHeight - height);
        }, function () {
            $btn.prop('disabled', false).text('Раньше');
        });
    }

    // ---------- Экран «Заявки» ----------

    function renderRequests(params, token) {
        stopPolling();
        if (params.new) {
            renderNewRequest(token);
        } else if (params.id) {
            renderRequest(Number(params.id), token);
        } else {
            renderRequestList(params, token);
        }
    }

    function requestCardHtml(r, master) {
        var meta = [];
        if (master) {
            meta.push(esc(r.author.name) + (r.author.section ? ' · ' + esc(r.author.section) : ''));
        }
        if (r.lift) {
            meta.push(esc(liftName(r.lift)));
        }
        return '<li><a class="app-req" href="' + hashFor('requests', { id: r.id }) + '">'
            + '<span class="app-req-top">' + statusBadge(r.status)
            + (r.urgent ? '<span class="lft-badge lft-badge--alarm">срочно</span>' : '')
            + '<time class="lft-label">' + esc(when(r.updated_at)) + '</time></span>'
            + '<span class="app-req-title">' + esc(r.title) + (r.quantity ? ' <span class="app-muted">· ' + esc(r.quantity) + '</span>' : '') + '</span>'
            + (meta.length ? '<span class="app-req-meta lft-label">' + meta.join(' · ') + '</span>' : '')
            + (r.last_body ? '<span class="app-req-last">' + (r.last_by_master ? '<b>Мастер:</b> ' : '') + esc(r.last_body) + '</span>' : '')
            + '<span class="app-req-count lft-label">' + icon('chat', 'lft-icon--12') + r.messages_count
            + (r.photos_count ? ' ' + icon('camera', 'lft-icon--12') + r.photos_count : '') + '</span>'
            + '</a></li>';
    }

    function renderRequestList(params, token) {
        var master = isMaster();
        var open = master && params.all !== '1';
        $view.html(
            '<div class="app-pad app-req-page">'
            + reqSwitchHtml('requests')
            + '<div class="app-req-head"><div><p class="lft-label">' + icon('box', 'lft-icon--12') + ' Заявки на запчасти</p>'
            + '<p class="fp-title">' + (master ? 'Заявки механиков' : 'Мои заявки') + '</p></div>'
            + (master ? '' : '<a class="lft-btn" href="' + hashFor('requests', { new: 1 }) + '">' + icon('box') + 'Новая заявка</a>')
            + '</div>'
            + (master ? '<div class="app-types app-journal-tabs" role="tablist">'
                + '<a class="fp-chip app-type" aria-pressed="' + open + '" href="' + hashFor('requests', {}) + '">Открытые</a>'
                + '<a class="fp-chip app-type" aria-pressed="' + !open + '" href="' + hashFor('requests', { all: 1 }) + '">Все</a></div>' : '')
            + '<div id="req-list">' + loading() + '</div>'
            + '</div>'
        );

        api('/api/requests', { as: asRole(), open: open ? 1 : undefined }).then(function (data) {
            if (token !== renderToken) {
                return;
            }
            if (!data.items.length) {
                $('#req-list').html('<p class="app-empty">' + (master
                    ? (open ? 'Открытых заявок нет.' : 'Заявок пока нет.')
                    : 'Заявок пока нет. Нужна деталь — нажмите «Новая заявка», приложите фото, мастер ответит здесь же.') + '</p>');
                return;
            }
            var html = '<ul class="app-req-list">';
            $.each(data.items, function (_, r) {
                html += requestCardHtml(r, master);
            });
            $('#req-list').html(html + '</ul>');
            markRequestsSeen(data.items);
        }, function (error) {
            $('#req-list').html(errorWindow(error, 'Заявки'));
        });
    }

    function markRequestsSeen(items) {
        var latest = remember(SEEN_REQUESTS) || '';
        $.each(items, function (_, r) {
            if (r.updated_at > latest) {
                latest = r.updated_at;
            }
        });
        remember(SEEN_REQUESTS, latest);
        drawDots();
    }

    function stepsHtml(status) {
        if (status === 'rejected') {
            return '<p class="app-req-rejected lft-label">' + icon('fault', 'lft-icon--12') + 'Заявка отклонена</p>';
        }
        var at = STATUS_STEPS.indexOf(status);
        var html = '<ol class="app-steps">';
        $.each(STATUS_STEPS, function (i, s) {
            html += '<li class="' + (i < at ? 'is-done' : (i === at ? 'is-now' : '')) + '"><i></i><span class="lft-label">' + esc(REQUEST_STATUSES[s].text) + '</span></li>';
        });
        return html + '</ol>';
    }

    function renderRequest(id, token) {
        $view.html('<div class="app-ai-page app-req-page">'
            + '<div class="app-pad app-req-detail" id="req-detail">' + loading() + '</div>'
            + '<div class="app-msgs" id="req-thread"></div>'
            + '<div id="req-compose"></div></div>');

        api('/api/requests/' + id, { as: asRole() }).then(function (data) {
            if (token === renderToken) {
                drawRequest(data.item, token);
                poll(token, 8, function () {
                    pollRequest(id, token);
                });
            }
        }, function (error) {
            $('#req-detail').html('<a class="app-back lft-label" href="' + hashFor('requests', {}) + '">← Все заявки</a>' + errorWindow(error, 'Заявка'));
        });
    }

    function drawRequest(r, token) {
        var master = isMaster();
        document.title = r.title + ' — заявка · Lift Fault Terminal';
        $('#req-detail').html(
            '<a class="app-back lft-label" href="' + hashFor('requests', {}) + '">← Все заявки</a>'
            + '<div class="app-req-card">'
            + '<p class="app-req-top">' + statusBadge(r.status) + (r.urgent ? '<span class="lft-badge lft-badge--alarm">срочно</span>' : '')
            + '<span class="lft-label">№ ' + r.id + ' · ' + esc(when(r.created_at)) + '</span></p>'
            + '<p class="fp-title">' + esc(r.title) + '</p>'
            + '<dl class="app-req-facts">'
            + (r.quantity ? '<div><dt class="lft-label">Количество</dt><dd>' + esc(r.quantity) + '</dd></div>' : '')
            + '<div><dt class="lft-label">Механик</dt><dd>' + esc(r.author.name) + (r.author.section ? ' · ' + esc(r.author.section) : '') + '</dd></div>'
            + (r.lift ? '<div><dt class="lft-label">Лифт</dt><dd>' + esc(liftName(r.lift)) + '</dd></div>' : '')
            + '</dl>'
            + stepsHtml(r.status)
            + '</div>'
        );
        var $thread = $('#req-thread').empty().removeData('day');
        appendMessages($thread, r.messages, true);
        $('#req-thread').data('count', r.messages.length);

        var mine = !master && session.me.mechanic && r.author.id === session.me.mechanic.id;
        if (master || mine) {
            trays.req = [];
            var extra = '';
            if (master) {
                extra = '<div class="app-status-pick" role="group" aria-label="Сменить статус"><span class="lft-label">Статус</span>';
                $.each(MASTER_STATUSES, function (_, s) {
                    extra += '<button type="button" class="fp-chip app-type" data-req-status="' + s + '" aria-pressed="false"' + (s === r.status ? ' disabled' : '') + '>'
                        + esc(REQUEST_STATUSES[s].text) + '</button>';
                });
                extra += '</div>';
            } else if (r.status === 'ready') {
                extra = '<button type="button" class="lft-btn app-got" data-req-got>' + icon('ok') + 'Получил деталь</button>';
            }
            $('#req-compose').html(composerHtml('req', master ? 'Ответ механику' : 'Сообщение мастеру', extra));
            $('#req-compose form').data('request', r.id);
        }
        markRequestsSeen([r]);
    }

    // Новое в переписке: заявка перерисовывается (статус и кнопки тоже могли поменяться),
    // набранный текст и выбранные фото остаются
    function pollRequest(id, token, force) {
        return api('/api/requests/' + id, { as: asRole() }).then(function (data) {
            if (token !== renderToken || (!force && data.item.messages.length === $('#req-thread').data('count'))) {
                return;
            }
            var stick = force || nearBottom();
            var text = $('#req-compose textarea').val();
            var keep = trays.req;
            drawRequest(data.item, token);
            $('#req-compose textarea').val(text || '');
            trays.req = keep || [];
            drawTray('req');
            if (stick) {
                scrollToBottom();
            }
        });
    }

    function renderNewRequest(token) {
        if (isMaster()) {
            go('requests', {}, true);
            return;
        }
        trays.newreq = [];
        $view.html(
            '<div class="app-pad app-req-page">'
            + '<a class="app-back lft-label" href="' + hashFor('requests', {}) + '">← Мои заявки</a>'
            + '<form class="lft-window app-req-form" id="req-form" novalidate>'
            + '<div class="lft-window__bar"><span class="lft-label">' + icon('box', 'lft-icon--12') + ' Новая заявка мастеру</span>'
            + '<span class="lft-window__x" aria-hidden="true">×</span></div>'
            + '<div class="lft-window__body">'
            + '<label class="app-field"><span class="lft-label">Что нужно *</span>'
            + '<input class="app-draft-input" name="title" maxlength="255" required placeholder="Например: контакт ВКЗ, ремень привода дверей" autocomplete="off"></label>'
            + '<div class="app-field-row">'
            + '<label class="app-field"><span class="lft-label">Количество</span>'
            + '<input class="app-draft-input" name="quantity" maxlength="50" placeholder="2 шт" autocomplete="off"></label>'
            + '<button type="button" class="fp-chip app-type app-urgent" id="req-urgent" aria-pressed="false">' + icon('warning', 'lft-icon--12') + 'Срочно</button>'
            + '</div>'
            + '<div class="app-field"><span class="lft-label">Лифт</span>'
            + '<label class="fp-chip app-select">' + icon('lift')
            + '<select name="lift_id" id="req-lift" aria-label="Лифт"><option value="">Загрузка лифтов…</option></select></label></div>'
            + '<label class="app-field"><span class="lft-label">Комментарий</span>'
            + '<textarea class="app-draft-input" name="body" rows="3" maxlength="2000" placeholder="Что случилось, марка или номер детали, если известны"></textarea></label>'
            + '<div class="app-field"><span class="lft-label">Фото детали или шильдика · до ' + MAX_PHOTOS + '</span>'
            + '<div class="app-field-row">' + attachHtml('newreq') + trayHtml('newreq') + '</div></div>'
            + '<p class="app-field-error lft-label" id="req-error" hidden></p>'
            + '<button type="submit" class="lft-btn" id="req-submit">' + icon('arrow-up') + 'Отправить мастеру</button>'
            + '</div></form></div>'
        );

        loadLifts().then(function (places) {
            if (token !== renderToken) {
                return;
            }
            var me = session.me.mechanic ? session.me.mechanic.id : null;
            var html = '<option value="">Без привязки к лифту</option>';
            $.each(places, function (_, p) {
                if (p.mechanic_id !== me) {
                    return;
                }
                $.each(p.lifts, function (_, l) {
                    html += '<option value="' + Number(l.id) + '">'
                        + esc(liftName({ address: p.address, object_name: p.object_name, lift_no: l.lift_no })) + '</option>';
                });
            });
            $('#req-lift').html(html).trigger('lft:refresh');
        });
    }

    function submitNewRequest() {
        var $form = $('#req-form');
        var $error = $('#req-error').prop('hidden', true);
        if (trayBusy('newreq')) {
            $error.text('Подождите, фото ещё загружается').prop('hidden', false);
            return;
        }
        var lift = $form.find('[name="lift_id"]').val();
        var body = {
            title: $.trim($form.find('[name="title"]').val()),
            quantity: $.trim($form.find('[name="quantity"]').val()) || null,
            urgent: $('#req-urgent').attr('aria-pressed') === 'true',
            lift_id: lift ? Number(lift) : null,
            body: $.trim($form.find('[name="body"]').val()) || null,
            photos: trayIds('newreq')
        };
        if (!body.title) {
            $error.text('Напишите, что нужно').prop('hidden', false);
            $form.find('[name="title"]').trigger('focus');
            return;
        }
        $('#req-submit').prop('disabled', true).attr('aria-busy', 'true');
        apiPost('/api/requests', body).then(function (data) {
            trays.newreq = [];
            go('requests', { id: data.item.id }, true);
        }, function (error) {
            $('#req-submit').prop('disabled', false).removeAttr('aria-busy');
            $error.text(errorText(error)).prop('hidden', false);
        });
    }

    function submitCompose($form) {
        var key = $form.data('compose');
        var $text = $form.find('[data-compose-text]');
        var $error = $form.find('[data-compose-error]').prop('hidden', true);
        var $status = $form.find('[data-req-status][aria-pressed="true"]');
        var body = { body: $.trim($text.val()) || null, photos: trayIds(key), as: asRole() };
        if ($status.length) {
            body.status = String($status.data('req-status'));
        }
        if (trayBusy(key)) {
            $error.text('Подождите, фото ещё загружается').prop('hidden', false);
            return;
        }
        if (!body.body && !body.photos.length && !body.status) {
            $text.trigger('focus');
            return;
        }
        var url = key === 'chat' ? '/api/chat' : '/api/requests/' + Number($form.data('request')) + '/messages';
        var $send = $form.find('.app-ai-send').prop('disabled', true).attr('aria-busy', 'true');
        apiPost(url, body).then(function (data) {
            $send.prop('disabled', false).removeAttr('aria-busy');
            $text.val('');
            growTextarea($text[0]);
            clearTray(key);
            if (key === 'chat') {
                $('#chat-empty').remove();
                appendMessages($('#chat-list'), [data.item], false);
                noteChat([data.item]);
                scrollToBottom();
            } else {
                pollRequest(Number($form.data('request')), renderToken, true);
            }
        }, function (error) {
            $send.prop('disabled', false).removeAttr('aria-busy');
            $error.text(errorText(error)).prop('hidden', false);
        });
    }

    // ---------- Значки «есть новое» на вкладках ----------

    function drawDots() {
        var u = session.updates;
        if (!u) {
            return;
        }
        var page = currentRoute().page;
        var chat = u.chat_last_id > Number(remember(SEEN_CHAT) || 0) && page !== 'chat';
        var req = !!u.requests_updated_at && u.requests_updated_at > (remember(SEEN_REQUESTS) || '') && page !== 'requests';
        $('[data-page="chat"]').toggleClass('has-new', chat);
        $('[data-page="requests"]').toggleClass('has-new', req);
    }

    function checkUpdates() {
        if (!session.role || document.hidden) {
            return;
        }
        api('/api/updates', { as: asRole() }).then(function (u) {
            // Первое открытие: старое не считается новым
            if (remember(SEEN_CHAT) === null) {
                remember(SEEN_CHAT, String(u.chat_last_id));
            }
            if (remember(SEEN_REQUESTS) === null) {
                remember(SEEN_REQUESTS, u.requests_updated_at || '');
            }
            session.updates = u;
            drawDots();
        });
    }

    setInterval(checkUpdates, 30000);

    $(document)
        .on('change', '[data-tray-input]', function () {
            var key = $(this).data('tray-input');
            var $error = key === 'newreq' ? $('#req-error') : $(this).closest('form').find('[data-compose-error]');
            $error.prop('hidden', true);
            addPhotos(key, this.files, $error);
            this.value = '';
        })
        .on('click', '[data-tray-remove]', function () {
            var key = $(this).data('tray-remove');
            var item = trays[key][Number($(this).data('index'))];
            if (item) {
                URL.revokeObjectURL(item.preview);
                trays[key].splice(Number($(this).data('index')), 1);
                drawTray(key);
            }
        })
        .on('submit', '[data-compose]', function (e) {
            e.preventDefault();
            submitCompose($(this));
        })
        .on('keydown', '[data-compose-text]', function (e) {
            var desktop = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
            if (e.key === 'Enter' && ((desktop && !e.shiftKey) || e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                submitCompose($(this).closest('form'));
            }
        })
        .on('input', '[data-compose-text]', function () {
            growTextarea(this);
        })
        .on('click', '[data-req-status]', function () {
            var on = $(this).attr('aria-pressed') !== 'true';
            $('[data-req-status]').attr('aria-pressed', 'false');
            $(this).attr('aria-pressed', String(on));
            $(this).closest('form').find('[data-compose-text]').attr('placeholder', on ? 'Комментарий к статусу, можно пусто' : 'Ответ механику');
        })
        .on('click', '[data-req-got]', function () {
            var $form = $(this).closest('form');
            $(this).prop('disabled', true);
            apiPost('/api/requests/' + Number($form.data('request')) + '/messages', { status: 'issued', body: 'Получил.' }).then(function () {
                pollRequest(Number($form.data('request')), renderToken, true);
            }, function (error) {
                $form.find('[data-compose-error]').text(errorText(error)).prop('hidden', false);
            });
        })
        .on('click', '#chat-older', loadOlderChat)
        .on('click', '#req-urgent', function () {
            $(this).attr('aria-pressed', String($(this).attr('aria-pressed') !== 'true'));
        })
        .on('submit', '#req-form', function (e) {
            e.preventDefault();
            submitNewRequest();
        })
        .on('click', '[data-photo]', function () {
            $('body').addClass('app-dd-lock').append(
                '<div class="app-viewer" role="dialog" aria-label="Фото"><img src="' + esc($(this).data('photo')) + '" alt="">'
                + '<button type="button" class="lft-window__x app-viewer-x" aria-label="Закрыть">×</button></div>'
            );
            $('.app-viewer-x').trigger('focus');
        })
        .on('click', '.app-viewer', function () {
            $('.app-viewer').remove();
            $('body').removeClass('app-dd-lock');
        })
        .on('keydown', function (e) {
            if (e.key === 'Escape' && $('.app-viewer').length) {
                $('.app-viewer').remove();
                $('body').removeClass('app-dd-lock');
            }
        });

    // ---------- Заявки на ремонт из отчёта 1С ----------
    // Мастер каждое утро загружает отчёт 1С (XLSX с почты). Механик видит заявки по своим
    // лифтам и закрывает их записью в журнал: лифт, коды и время уже подставлены.

    function reqSwitchHtml(active) {
        return '<div class="app-types app-journal-tabs app-req-switch" role="tablist">'
            + '<a class="fp-chip app-type" aria-pressed="' + (active === 'repairs') + '" href="' + hashFor('repairs', {}) + '">' + icon('smile', 'lft-icon--12') + 'Ремонт · 1С</a>'
            + '<a class="fp-chip app-type" aria-pressed="' + (active === 'requests') + '" href="' + hashFor('requests', {}) + '">' + icon('box', 'lft-icon--12') + 'Запчасти</a></div>';
    }

    function shortTime(iso) {
        var m = /^\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso || '');
        return m ? m[2] + '.' + m[1] + ' ' + m[3] + ':' + m[4] : '';
    }

    function repeatClass(n) {
        return n >= 5 ? ' r5' : (n >= 4 ? ' r4' : (n >= 2 ? ' r2' : ''));
    }

    // «44 C6» — одна плашка: подсказка C* относится к коду перед ней
    function codeGroups(codes) {
        var groups = [];
        $.each(codes, function (_, c) {
            if (/^C\d+$/.test(c) && groups.length) {
                groups[groups.length - 1].push(c);
            } else {
                groups.push([c]);
            }
        });
        return $.map(groups, function (g) { return g.join(' '); });
    }

    function liftLabel(lift) {
        return liftName({ address: lift.address, object_name: lift.object_name, lift_no: lift.lift_no });
    }

    function repairCardHtml(r, master) {
        var codes = codeGroups(r.codes);
        var html = '<li class="app-rep' + (r.finished_at ? '' : ' is-open') + '" data-repair="' + r.id + '">'
            + '<div class="app-rep-top"><span class="app-rep-num">' + esc(r.reg) + '</span>'
            + (r.finished_at ? '' : '<span class="lft-badge lft-badge--alarm">не закрыта</span>')
            + (r.kind === 'planned' ? '<span class="lft-badge">плановая</span>' : '')
            + (r.stopped ? '<span class="lft-badge lft-badge--warn">лифт стоит</span>' : '')
            + '<span class="app-rep-count' + repeatClass(r.stops_30d) + '" title="Остановок за 30 дней">' + r.stops_30d + ' за 30 дн.</span></div>'
            + '<p class="app-rep-addr">' + esc(r.address) + (r.floor ? ' · эт. ' + esc(r.floor) : '') + '</p>'
            + '<p class="app-rep-time lft-label">' + esc(shortTime(r.started_at)) + ' → ' + (r.finished_at ? esc(shortTime(r.finished_at)) : 'ещё не закрыта') + '</p>';
        if (codes.length) {
            html += '<div class="app-rep-codes">';
            $.each(codes, function (_, c) {
                html += '<a class="app-rep-code" href="' + hashFor('codes', { code: c }) + '" title="Открыть код: что значит, причины">' + esc(c) + '</a>';
            });
            html += '</div>';
        }
        var notes = $.grep((r.info || '').split(/\s*;\s*/), function (p) { return p && !/^\d{1,3}(\s+[CС]\d{1,2})?(\s+MLM)?$/i.test(p); });
        if (notes.length) {
            html += '<p class="app-rep-note">' + esc(notes.join(' · ')) + '</p>';
        }
        if (r.lift) {
            html += '<p class="app-rep-who">' + icon('settings', 'lft-icon--12') + esc(r.lift.mechanic || 'без механика') + ' · ' + esc(liftLabel(r.lift)) + '</p>';
        } else if (master || r.candidates.length) {
            html += '<div class="app-rep-match"><span class="lft-label">' + (master ? 'Чей это лифт?' : 'Какой из ваших лифтов?')
                + ' Выберите один раз — номер ' + esc(r.reg) + ' запомнится</span>';
            if (r.candidates.length) {
                $.each(r.candidates, function (_, c) {
                    html += '<button type="button" class="fp-chip app-type" data-repair-lift="' + c.id + '">'
                        + esc(liftLabel(c)) + (master && c.mechanic ? ' · ' + esc(c.mechanic) : '') + (c.reg ? ' · №' + esc(c.reg) : '') + '</button>';
                });
            } else {
                html += '<span class="app-muted">Адреса нет в списках лифтов бригады</span>';
            }
            html += '</div>';
        }
        if (r.journal_entry_id) {
            html += '<a class="app-rep-done lft-label" href="' + hashFor('journal', { lift: r.lift ? r.lift.id : '' }) + '">' + icon('ok', 'lft-icon--12') + 'Закрыта записью в журнале</a>';
        } else if (r.kind === 'fault') {
            html += '<button type="button" class="lft-btn lft-btn--ghost app-rep-close" data-repair-close="' + r.id + '">' + icon('journal', 'lft-icon--12') + 'Закрыть записью в журнал</button>';
        }
        return html + '</li>';
    }

    var repairsData = null;
    var repairsKind = 'fault';

    function renderRepairs(params, token) {
        var master = isMaster();
        $view.html(
            '<div class="app-pad app-req-page">'
            + reqSwitchHtml('repairs')
            + '<div class="app-req-head"><div><p class="lft-label">' + icon('smile', 'lft-icon--12') + ' Заявки на ремонт · отчёт 1С</p>'
            + '<p class="fp-title" id="rep-title">' + (master ? 'Заявки участка' : 'Заявки по моим лифтам') + '</p>'
            + '<p class="app-muted" id="rep-period"></p></div>'
            + (master ? '<label class="lft-btn app-rep-upload">' + icon('arrow-up') + 'Загрузить отчёт 1С'
                + '<input type="file" id="rep-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>' : '')
            + '</div>'
            + '<p class="app-field-error lft-label" id="rep-msg" hidden></p>'
            + '<div id="rep-body">' + loading() + '</div>'
            + '</div>'
        );

        api('/api/repairs', { as: asRole(), report: params.report }).then(function (data) {
            if (token !== renderToken) {
                return;
            }
            repairsData = data;
            drawRepairs();
        }, function (error) {
            $('#rep-body').html(errorWindow(error, 'Заявки на ремонт'));
        });
    }

    function drawRepairs() {
        var data = repairsData;
        var master = isMaster();
        if (!data.report) {
            $('#rep-body').html('<p class="app-empty">' + (master
                ? 'Отчётов пока нет. Загрузите утренний отчёт 1С (файл XLSX из почты) — заявки разложатся по лифтам механиков.'
                : 'Отчётов пока нет: мастер загружает отчёт 1С утром.') + '</p>');
            return;
        }
        var r = data.report;
        $('#rep-period').text(shortTime(r.period_from) + ' → ' + shortTime(r.period_to) + (r.section ? ' · ' + r.section : ''));

        var items = data.items;
        var faults = $.grep(items, function (x) { return x.kind === 'fault'; });
        var open = $.grep(items, function (x) { return !x.finished_at; });
        var heavy = {};
        $.each(items, function (_, x) { if (x.stops_30d >= 4) { heavy[x.reg] = true; } });
        var unmatched = $.grep(items, function (x) { return !x.lift; });

        var html = '';
        if (data.reports.length > 1) {
            html += '<label class="fp-chip app-select app-rep-select">' + icon('journal') + '<select id="rep-report" aria-label="Отчёт">';
            $.each(data.reports, function (_, x) {
                html += '<option value="' + x.id + '"' + (x.id === r.id ? ' selected' : '') + '>' + esc(shortTime(x.period_from) + ' → ' + shortTime(x.period_to)) + ' · ' + x.faults + ' неиспр.</option>';
            });
            html += '</select></label>';
        }
        html += '<div class="app-rep-tiles">'
            + '<div><b>' + faults.length + '</b><span class="lft-label">неисправн.</span></div>'
            + '<div><b>' + (items.length - faults.length) + '</b><span class="lft-label">ТО и работы</span></div>'
            + '<div class="bad"><b>' + open.length + '</b><span class="lft-label">не закрыты</span></div>'
            + '<div class="bad"><b>' + Object.keys(heavy).length + '</b><span class="lft-label">лифтов 4+ ост.</span></div>'
            + '</div>';
        if (master && unmatched.length) {
            html += '<p class="app-rep-warn lft-label">' + icon('warning', 'lft-icon--12') + unmatched.length + ' ' + plural(unmatched.length, 'заявка', 'заявки', 'заявок') + ' без лифта: укажите, чей это лифт, — механик увидит заявку</p>';
        }
        html += '<div class="app-types app-journal-tabs" role="tablist">'
            + '<button type="button" class="fp-chip app-type" data-rep-kind="fault" aria-pressed="' + (repairsKind === 'fault') + '">Неисправности</button>'
            + '<button type="button" class="fp-chip app-type" data-rep-kind="planned" aria-pressed="' + (repairsKind === 'planned') + '">ТО и работы</button>'
            + '<button type="button" class="fp-chip app-type" data-rep-kind="all" aria-pressed="' + (repairsKind === 'all') + '">Все</button></div>';
        var shown = $.grep(items, function (x) { return repairsKind === 'all' || x.kind === repairsKind; });
        html += shown.length
            ? '<ul class="app-rep-list">' + $.map(shown, function (x) { return repairCardHtml(x, master); }).join('') + '</ul>'
            : '<p class="app-empty">' + (master ? 'Нет заявок этого вида.' : 'По вашим лифтам заявок нет.') + '</p>';
        $('#rep-body').html(html);
        $('#rep-body .app-select select').each(function () {
            customSelect(this);
        });
    }

    function uploadReport(file) {
        var $msg = $('#rep-msg').prop('hidden', true);
        if (!file) {
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            $msg.text('Файл слишком большой: отчёт 1С занимает килобайты').prop('hidden', false);
            return;
        }
        var reader = new FileReader();
        $('.app-rep-upload').attr('aria-busy', 'true');
        reader.onload = function () {
            apiPost('/api/repairs/import', { name: file.name, data: String(reader.result) }).then(function (res) {
                $('.app-rep-upload').removeAttr('aria-busy');
                go('repairs', { report: res.report_id });
                setTimeout(function () {
                    $('#rep-msg').removeClass('app-field-error').addClass('app-ok-msg')
                        .text('Загружено: ' + res.rows + ' ' + plural(res.rows, 'строка', 'строки', 'строк') + ', неисправностей ' + res.faults
                            + '. Лифт найден у ' + res.matched + ', без лифта ' + res.unmatched + '.')
                        .prop('hidden', false);
                }, 400);
            }, function (error) {
                $('.app-rep-upload').removeAttr('aria-busy');
                $msg.text(errorText(error)).prop('hidden', false);
            });
        };
        reader.onerror = function () {
            $('.app-rep-upload').removeAttr('aria-busy');
            $msg.text('Не удалось прочитать файл').prop('hidden', false);
        };
        reader.readAsDataURL(file);
    }

    $(document)
        .on('change', '#rep-file', function () {
            uploadReport(this.files[0]);
            this.value = '';
        })
        .on('change', '#rep-report', function () {
            go('repairs', { report: $(this).val() });
        })
        .on('click', '[data-rep-kind]', function () {
            repairsKind = String($(this).data('rep-kind'));
            drawRepairs();
        })
        .on('click', '[data-repair-lift]', function () {
            var id = Number($(this).closest('[data-repair]').data('repair'));
            $(this).prop('disabled', true);
            apiPost('/api/repairs/' + id + '/lift', { lift_id: Number($(this).data('repair-lift')), as: asRole() }).then(function () {
                render();
            }, function (error) {
                window.alert(errorText(error));
            });
        })
        .on('click', '[data-repair-close]', function () {
            var id = Number($(this).data('repair-close'));
            var r = $.grep(repairsData.items, function (x) { return x.id === id; })[0];
            if (!r) {
                return;
            }
            var notes = $.grep((r.info || '').split(/\s*;\s*/), function (p) { return p && !/^\d{1,3}(\s+[CС]\d{1,2})?(\s+MLM)?$/i.test(p); });
            var codes = r.codes.join(' ');
            openSaveSheet(true, {
                repair_id: r.id,
                lift: r.lift ? { id: r.lift.id, label: liftLabel(r.lift) + (r.lift.mechanic ? ' · ' + r.lift.mechanic : '') } : null,
                draft: {
                    codes: codes,
                    title: 'Заявка 1С' + (codes ? ': код ' + codes : '') + (notes.length ? (codes ? ', ' : ': ') + notes.join(', ') : ''),
                    symptoms: $.trim('Заявка ' + shortTime(r.started_at) + (r.floor ? ', эт. ' + r.floor : '') + '. '
                        + (r.stopped ? 'Лифт стоял. ' : '') + (notes.length ? notes.join('. ') + '.' : '')),
                    status: 'resolved',
                    occurred_at: r.started_at.slice(0, 16)
                }
            });
        });

    // ---------- Маршрутизация и события ----------

    function render() {
        var route = currentRoute();
        var token = ++renderToken;
        // Отдельного поиска больше нет: поиск по описанию делает ИИ-диагностика
        var page = route.page === 'search' ? 'ai' : route.page;
        page = { codes: true, journal: true, base: true, ai: true, requests: true, repairs: true, chat: true }[page] ? page : 'codes';
        if (page === 'base' && !isMaster()) {
            page = 'journal';
        }

        setActiveNav(page === 'base' ? 'journal' : (page === 'repairs' ? 'requests' : page));
        document.title = 'Lift Fault Terminal — база неисправностей лифтов';
        window.scrollTo(0, 0);

        closeDropdown(false);
        stopPolling();
        if (!session.role) {
            renderLogin();
        } else if (page === 'journal') {
            renderJournal(route.params, token);
        } else if (page === 'base') {
            renderBase(route.params, token);
        } else if (page === 'ai') {
            renderAi(token);
        } else if (page === 'repairs') {
            renderRepairs(route.params, token);
        } else if (page === 'requests') {
            renderRequests(route.params, token);
        } else if (page === 'chat') {
            renderChat(token);
        } else {
            renderCodes(route.params, token);
        }
        $view.find('.app-select select').each(function () {
            customSelect(this);
        });
        drawDots();
        if (session.role && Date.now() - (session.checked || 0) > 10000) {
            session.checked = Date.now();
            checkUpdates();
        }
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
        .on('click', '#ai-save', function () {
            openSaveSheet(false);
        })
        .on('click', '[data-add-entry]', function () {
            openSaveSheet(true);
        })
        .on('click', '.app-sheet-close, .app-sheet-backdrop, [data-close-sheet]', closeSaveSheet)
        .on('click', '[data-refresh]', function () {
            render();
        })
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
        .on('click', '.app-acc-head', function () {
            var key = $(this).closest('.app-acc').data('acc');
            if ($(this).attr('aria-expanded') === 'true') {
                closeGroup(key);
            } else {
                openGroup(key, currentRoute().params.id, true);
            }
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

    // ---------- Push-уведомления ----------
    // Подписка — на устройство: в роли, в которой открыто приложение, со своим набором тем.
    // На iPhone уведомления работают только у приложения, добавленного на экран «Домой».

    var PUSH_PREFS = 'lft:push-prefs';
    var push = { server: null, registration: null, on: false };

    function pushSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    }

    function isIos() {
        return /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
    }

    function pushPrefs(value) {
        var prefs = { chat: true, requests: true, repairs: true, quiet: false };
        try {
            if (value) {
                localStorage.setItem(PUSH_PREFS, JSON.stringify(value));
            }
            $.extend(prefs, JSON.parse(localStorage.getItem(PUSH_PREFS) || '{}'));
        } catch (e) { /* без хранилища — настройки по умолчанию */ }
        return value || prefs;
    }

    function pushServer() {
        return push.server ? Promise.resolve(push.server) : Promise.resolve(api('/api/push')).then(function (s) {
            push.server = s;
            return s;
        });
    }

    function pushRegistration() {
        if (!push.registration) {
            push.registration = navigator.serviceWorker.register('/sw.js').then(function () {
                return navigator.serviceWorker.ready;
            });
        }
        return push.registration;
    }

    function keyBytes(b64) {
        var raw = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64.length + 3) % 4));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            out[i] = raw.charCodeAt(i);
        }
        return out;
    }

    // Подписка этого браузера, если она сделана нашим ключом сервера (ключ сменили — подписки нет)
    function currentSubscription() {
        return pushRegistration().then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            var key = sub && sub.options && sub.options.applicationServerKey;
            if (sub && key && push.server && btoa(String.fromCharCode.apply(null, new Uint8Array(key))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') !== push.server.public_key) {
                return sub.unsubscribe().then(function () {
                    return null;
                });
            }
            return sub;
        });
    }

    function savePush(sub) {
        return Promise.resolve(apiPost('/api/push/subscribe', { subscription: sub.toJSON(), role: session.role, prefs: pushPrefs() }));
    }

    function drawPushButton() {
        $('#app-push').toggleClass('is-on', push.on);
    }

    // Вызывается в обработчике нажатия: iPhone спрашивает разрешение только в ответ на жест
    function enablePush() {
        return Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                throw { message: 'Уведомления не разрешены. Разрешите их для этого сайта в настройках браузера' };
            }
            return currentSubscription();
        }).then(function (sub) {
            return sub || pushRegistration().then(function (reg) {
                return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(push.server.public_key) });
            });
        }).then(function (sub) {
            return savePush(sub);
        }).then(function () {
            push.on = true;
            drawPushButton();
        });
    }

    function disablePush() {
        return currentSubscription().then(function (sub) {
            if (!sub) {
                return null;
            }
            return Promise.resolve(apiPost('/api/push/unsubscribe', { endpoint: sub.endpoint })).catch(function () {
                return null;
            }).then(function () {
                return sub.unsubscribe();
            });
        }).then(function () {
            push.on = false;
            drawPushButton();
        });
    }

    // При входе и смене роли: сервер узнаёт, в какой роли теперь это устройство
    function syncPush() {
        if (!session.role || !pushSupported() || Notification.permission !== 'granted') {
            push.on = false;
            drawPushButton();
            return;
        }
        pushServer().then(function (server) {
            return server.enabled ? currentSubscription() : null;
        }).then(function (sub) {
            push.on = !!sub;
            drawPushButton();
            return sub ? savePush(sub) : null;
        }).catch(function () { /* уведомления не мешают работе приложения */ });
    }

    function switchHtml(attr, on, title, hint, disabled) {
        return '<label class="app-switch"><input type="checkbox" ' + attr + (on ? ' checked' : '') + (disabled ? ' disabled' : '') + '>'
            + '<span class="app-switch-text">' + title + (hint ? '<small>' + hint + '</small>' : '') + '</span>'
            + '<span class="app-switch-track" aria-hidden="true"></span></label>';
    }

    function openPushSheet() {
        $('body').addClass('app-dd-lock').append('<div class="app-dd-backdrop app-sheet-backdrop"></div>'
            + '<div class="app-sheet lft-window" role="dialog" aria-label="Уведомления"><div class="lft-window__bar">'
            + '<span class="lft-label">' + icon('bell', 'lft-icon--12') + ' Уведомления</span>'
            + '<button type="button" class="lft-window__x app-sheet-close" aria-label="Закрыть">×</button></div>'
            + '<div class="lft-window__body" id="push-body">' + loading('Проверяем') + '</div></div>');
        pushServer().then(function (server) {
            return server.enabled && pushSupported() ? currentSubscription() : null;
        }).then(function (sub) {
            push.on = !!sub;
            drawPushButton();
            drawPushSheet();
        }, function (error) {
            // Ошибка API — своим окном, отказ браузера (service worker, push) — простым текстом
            $('#push-body').html(error && error.status !== undefined
                ? errorWindow(error, 'Уведомления')
                : '<p class="app-text">Этот браузер не дал включить уведомления.</p>'
                    + '<p class="lft-label">Откройте приложение в Chrome, Firefox, Edge или Safari (на iPhone — с экрана «Домой»). '
                    + 'В режиме инкогнито уведомления не работают.</p>');
        });
    }

    function drawPushSheet() {
        var $body = $('#push-body');
        if (!push.server.enabled) {
            $body.html('<p class="app-text">На сервере уведомления пока не настроены.</p>');
            return;
        }
        if (!pushSupported()) {
            $body.html(isIos() && !isStandalone()
                ? '<p class="app-text">На iPhone уведомления приходят только в приложение на экране «Домой».</p>'
                    + '<ol class="app-push-steps"><li>В Safari нажмите «Поделиться» ' + icon('box', 'lft-icon--12') + '</li>'
                    + '<li>Выберите «На экран Домой»</li><li>Откройте LFT со значка на экране и снова нажмите колокольчик</li></ol>'
                : '<p class="app-text">Этот браузер не умеет получать уведомления. Откройте приложение в Chrome, Firefox, Edge или Safari.</p>');
            return;
        }
        if (Notification.permission === 'denied') {
            $body.html('<p class="app-text">Уведомления для этого сайта запрещены в настройках браузера.</p>'
                + '<p class="lft-label">Chrome: значок слева от адреса → Настройки сайта → Уведомления → Разрешить. Потом откройте это окно снова.</p>');
            return;
        }
        var prefs = pushPrefs();
        var off = !push.on;
        var topics = isMaster()
            ? switchHtml('data-push-pref="requests"', prefs.requests, 'Заявки на запчасти', 'Новые заявки и ответы механиков', off)
            : switchHtml('data-push-pref="repairs"', prefs.repairs, 'Заявки на ремонт', 'Новые вызовы по моим лифтам из отчёта 1С', off)
                + switchHtml('data-push-pref="requests"', prefs.requests, 'Мои заявки на запчасти', 'Ответ мастера и смена статуса', off);
        $body.html(
            '<p class="app-text">Приходят, даже когда приложение закрыто. Настройки — для этого устройства, роль: '
                + (isMaster() ? 'мастер' : 'механик') + '. О своих сообщениях не сообщаем.</p>'
            + '<div class="app-push-list">' + switchHtml('data-push-main', push.on, '<b>Уведомления на этом устройстве</b>') + '</div>'
            + '<div class="app-push-list">' + topics
                + switchHtml('data-push-pref="chat"', prefs.chat, 'Общий чат', 'Сообщения механиков и мастера', off)
                + switchHtml('data-push-pref="quiet"', prefs.quiet, 'Тишина ночью', 'С 22:00 до 7:00 ничего не приходит', off)
            + '</div>'
            + '<div class="app-push-actions"><button type="button" class="lft-btn lft-btn--ghost" id="push-test"' + (off ? ' disabled' : '') + '>'
                + icon('bell') + 'Проверить</button><p class="lft-label" id="push-msg" role="status"></p></div>'
        );
    }

    function pushMessage(text, bad) {
        $('#push-msg').text(text).toggleClass('app-bad', !!bad);
    }

    $(document)
        .on('click', '#app-push', openPushSheet)
        .on('change', '[data-push-main]', function () {
            var $box = $(this).prop('disabled', true);
            var action = this.checked ? enablePush() : disablePush();
            action.then(drawPushSheet, function (error) {
                $box.prop({ checked: push.on, disabled: false });
                // Ошибки браузера (DOMException) приходят по-английски: показываем только их вид
                pushMessage(error && error.name && error.status === undefined
                    ? 'Браузер не дал подписаться (' + error.name + '). Попробуйте ещё раз или другой браузер'
                    : (error && error.message) || 'Не получилось', true);
            });
        })
        .on('change', '[data-push-pref]', function () {
            var prefs = pushPrefs();
            prefs[$(this).data('push-pref')] = this.checked;
            pushPrefs(prefs);
            currentSubscription().then(function (sub) {
                return sub ? savePush(sub) : null;
            }).then(function () {
                pushMessage('Сохранено');
            }, function (error) {
                pushMessage(error && error.message ? error.message : 'Не сохранилось', true);
            });
        })
        .on('click', '#push-test', function () {
            pushMessage('Отправляем…');
            currentSubscription().then(function (sub) {
                if (!sub) {
                    throw { message: 'Устройство не подписано: включите уведомления заново' };
                }
                return apiPost('/api/push/test', { endpoint: sub.endpoint });
            }).then(function () {
                pushMessage('Отправлено: уведомление придёт через несколько секунд');
            }, function (error) {
                pushMessage(error && error.message ? error.message : 'Не отправилось', true);
            });
        });

    // Нажали на уведомление при открытом приложении: service worker просит открыть нужный экран
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', function (e) {
            if (e.data && e.data.type === 'open' && typeof e.data.hash === 'string' && e.data.hash.charAt(0) === '#') {
                location.hash = e.data.hash;
            }
        });
    }

    // ---------- Тема: тёмная «терминал» или светлая в цветах фирмы ----------

    function theme() {
        return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    function drawThemeButton() {
        var light = theme() === 'light';
        var label = light ? 'Включить тёмную тему' : 'Включить светлую тему';
        $('#app-theme').html(icon(light ? 'moon' : 'sun', 'lft-icon--12')).attr({ 'aria-label': label, title: label });
    }

    $(document).on('click', '#app-theme', function () {
        var next = theme() === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', next);
        try {
            localStorage.setItem('lft:theme', next);
        } catch (e) { /* без хранилища тема продержится до перезагрузки */ }
        drawThemeButton();
    });
    drawThemeButton();

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
