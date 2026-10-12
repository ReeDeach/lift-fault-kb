/*
 * Интерфейс базы неисправностей лифтов. jQuery, без сборки.
 *
 * Весь текст из API выводится через .text(), то есть экранируется. Как HTML вставляются
 * только title_html и snippet_html из /api/search: сервер экранирует их сам
 * и оставляет лишь теги <mark>.
 *
 * Адреса экранов (hash): #diagnose, #code, #code/45, #item/123, #search, #search/двери
 */
(function ($) {
    'use strict';

    var MODEL_KEY = 'lift-kb:model';
    var TYPES = { codes: 'Код', symptoms: 'Симптом', causes: 'Причина', fixes: 'Ремонт', cases: 'Случай' };
    var CONFIDENCE = { high: 'вероятно', medium: 'возможно', low: 'маловероятно' };
    var OUTCOME = { resolved: 'устранено', partial: 'частично', unresolved: 'не устранено' };

    // ---------- Общее ----------

    function api(method, path, data) {
        var options = { method: method, url: path, dataType: 'json', timeout: 60000 };
        if (method === 'GET') {
            options.data = data;
        } else {
            options.contentType = 'application/json';
            options.data = JSON.stringify(data);
        }
        return $.ajax(options).then(null, function (xhr) {
            var error = xhr.responseJSON && xhr.responseJSON.error;
            var message = error ? error.message : (xhr.status === 0 ? 'Нет связи с сервером' : 'Ошибка ' + xhr.status);
            if (error && error.fields) {
                message += ': ' + $.map(error.fields, function (text) { return text; }).join('; ');
            }
            return $.Deferred().reject({ status: xhr.status, message: message }).promise();
        });
    }

    function el(tag, className, text) {
        var $el = $('<' + tag + '>');
        if (className) { $el.addClass(className); }
        if (text !== undefined && text !== null) { $el.text(text); }
        return $el;
    }

    function storage(key, value) {
        try {
            if (value === undefined) { return window.localStorage.getItem(key); }
            window.localStorage.setItem(key, value);
        } catch (e) { /* приватный режим: просто не запоминаем */ }
        return null;
    }

    function modelId() {
        return $('#model').val() || '';
    }

    function loading($target, text) {
        $target.empty().append(el('div', 'loading', text || 'Загрузка…'));
    }

    function showError($form, message) {
        $form.find('.form__error').text(message).prop('hidden', !message);
    }

    // ---------- Модели ----------

    function loadModels() {
        var saved = storage(MODEL_KEY);
        return api('GET', '/api/models').then(function (data) {
            var $select = $('#model');
            var groups = {};
            $.each(data.items, function (_, m) {
                var name = m.manufacturer.name;
                if (!groups[name]) {
                    groups[name] = $('<optgroup>').attr('label', name).appendTo($select);
                }
                groups[name].append($('<option>').val(String(m.id)).text(m.name));
            });
            if (saved && $select.find('option[value="' + saved.replace(/\D/g, '') + '"]').length) {
                $select.val(saved);
            }
        }, function () { /* без списка моделей поиск работает по всем */ });
    }

    // ---------- Дерево кода ----------

    function renderFix(fix) {
        var $fix = el('div', 'fix');
        $fix.append(el('div', 'fix__title', fix.title));
        if (fix.summary) { $fix.append(el('p', '', fix.summary)); }
        if (fix.safety_note) { $fix.append(el('p', 'fix__safety', '⚠ ' + fix.safety_note)); }
        if (fix.steps && fix.steps.length) {
            var $steps = el('ol', 'steps');
            $.each(fix.steps, function (_, step) {
                var $li = el('li', '', step.instruction);
                if (step.expected_result) { $li.append(el('span', 'expected', 'Ожидается: ' + step.expected_result)); }
                $steps.append($li);
            });
            $fix.append($steps);
        }
        return $fix;
    }

    function renderFixes(fixes) {
        if (!fixes || !fixes.length) { return $(); }
        var $details = $('<details>');
        $details.append(el('summary', '', fixes.length === 1 ? 'Как устранить' : 'Как устранить (' + fixes.length + ')'));
        $.each(fixes, function (_, fix) { $details.append(renderFix(fix)); });
        return $details;
    }

    function renderCases(cases) {
        var $list = el('ul', 'list');
        $.each(cases, function (_, c) {
            var meta = c.occurred_on + ' · ' + (OUTCOME[c.outcome] || c.outcome) + (c.code ? ' · код ' + c.code : '');
            $list.append(el('li', 'case').append(el('div', 'case__meta', meta), el('div', '', c.description)));
        });
        return $list;
    }

    function renderCode(item) {
        var $card = el('article', 'card');
        var $head = el('div', 'code-head')
            .append(el('span', 'code-head__code', item.code))
            .append(el('span', 'code-head__title', item.title));
        if (item.is_safety_related) { $head.append(el('span', 'badge badge--danger', 'цепь безопасности')); }
        $card.append($head, el('p', 'owner', item.owner.name));
        if (item.description) { $card.append(el('p', 'desc', item.description)); }

        if (item.symptoms && item.symptoms.length) {
            $card.append(el('h3', 'section-title', 'Симптомы'));
            var $symptoms = el('ul', 'evidence');
            $.each(item.symptoms, function (_, s) { $symptoms.append(el('li', '', s.title)); });
            $card.append($symptoms);
        }

        if (item.causes && item.causes.length) {
            $card.append(el('h3', 'section-title', 'Причины'));
            $.each(item.causes, function (i, cause) {
                var badge = cause.direct
                    ? el('span', 'badge badge--high', 'по руководству')
                    : el('span', 'badge', 'вероятность ' + cause.likelihood + ' из 5');
                var $cause = el('div', 'cause').css('margin-top', i ? '14px' : 0);
                $cause.append(el('div', 'cause__head').append(el('h4', 'cause__title', cause.title), badge));
                $cause.append(renderFixes(cause.fixes));
                $card.append($cause);
            });
        }

        if (item.cases && item.cases.length) {
            $card.append(el('h3', 'section-title', 'Случаи из практики'), renderCases(item.cases));
        }
        if (item.source) { $card.append(el('p', 'owner', 'Источник: ' + item.source)); }
        return $card;
    }

    // ---------- Диагностика ----------

    function aiStatus(ai) {
        if (ai.status === 'ok') {
            return el('div', 'status status--ok', 'Разбор ИИ по данным базы' + (ai.discarded ? ' (отброшено вариантов не из базы: ' + ai.discarded + ')' : ''));
        }
        var cls = ai.status === 'skipped' ? 'status status--muted' : 'status status--warn';
        return el('div', cls, ai.message || 'ИИ не использовался');
    }

    function renderDiagnosis(data) {
        var $out = $('<div>');
        $out.append(aiStatus(data.ai));

        if (data.safety && data.safety.length) {
            var $safety = el('div', 'safety').attr('role', 'note').append(el('strong', '', 'Техника безопасности'));
            var $ul = $('<ul>');
            $.each(data.safety, function (_, note) { $ul.append(el('li', '', note)); });
            $out.append($safety.append($ul));
        }

        if (data.ai.summary) { $out.append(el('p', 'summary', data.ai.summary)); }

        if (!data.causes.length) {
            $out.append(el('div', 'card empty', 'В базе нет подходящих причин. Уточните описание или укажите код ошибки.'));
        }

        $.each(data.causes, function (i, cause) {
            var $card = el('article', 'card cause');
            var $head = el('div', 'cause__head')
                .append(el('span', 'cause__num', String(i + 1)), el('h3', 'cause__title', cause.title));
            if (cause.confidence) {
                $head.append(el('span', 'badge badge--' + cause.confidence, CONFIDENCE[cause.confidence]));
            }
            $card.append($head);
            if (cause.reasoning) { $card.append(el('p', '', cause.reasoning)); }
            if (cause.first_check) {
                $card.append(el('p', 'cause__check').append(el('b', '', 'Проверить первым: '), document.createTextNode(cause.first_check)));
            }
            if (cause.evidence && cause.evidence.length) {
                var $ev = el('ul', 'evidence');
                $.each(cause.evidence, function (_, e) { $ev.append(el('li', '', e)); });
                $card.append($ev);
            }
            $card.append(renderFixes(cause.fixes));
            $out.append($card);
        });

        if (data.ai.questions && data.ai.questions.length) {
            var $q = el('ul', 'questions');
            $.each(data.ai.questions, function (_, q) { $q.append(el('li', '', q)); });
            $out.append(el('h3', 'section-title', 'Уточните, чтобы сузить выбор'), el('div', 'card').append($q));
        }

        if (data.codes.length) {
            var $codes = el('ul', 'list');
            $.each(data.codes, function (_, c) {
                $codes.append($('<li>').append(
                    $('<a class="hit">').attr('href', '#item/' + c.id)
                        .append(el('div', 'hit__title', c.code + ' — ' + c.title), el('div', 'hit__snippet', c.owner.name))
                ));
            });
            $out.append(el('h3', 'section-title', 'Коды'), el('div', 'card').append($codes));
        }

        if (data.cases.length) {
            $out.append(el('h3', 'section-title', 'Похожие случаи'), el('div', 'card').append(renderCases(data.cases)));
        }

        if (data.search === 'unavailable') {
            $out.append(el('p', 'owner', 'Полнотекстовый поиск недоступен: разбор сделан только по коду.'));
        }
        return $out;
    }

    $('#diagnose-form').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var description = $.trim($form.find('[name=description]').val());
        var code = $.trim($form.find('[name=code]').val());
        if (description.length < 10) {
            showError($form, 'Опишите неисправность подробнее: не короче 10 символов.');
            return;
        }
        showError($form, '');

        var body = { description: description };
        if (code) { body.code = code; }
        if (modelId()) { body.model = modelId(); }

        var $btn = $form.find('button').prop('disabled', true);
        var $result = $('#diagnose-result');
        loading($result, 'Разбираем: поиск по базе и ИИ, до полуминуты…');
        api('POST', '/api/diagnose', body).then(function (data) {
            $result.empty().append(renderDiagnosis(data));
        }, function (err) {
            $result.empty();
            showError($form, err.message);
        }).always(function () { $btn.prop('disabled', false); });
    });

    // ---------- Код ошибки ----------

    function showCode(code) {
        var $form = $('#code-form');
        var $result = $('#code-result');
        $form.find('[name=code]').val(code);
        showError($form, '');
        loading($result);

        var query = { code: code, expand: 'tree', limit: 20 };
        if (modelId()) { query.model = modelId(); }
        api('GET', '/api/codes', query).then(function (data) {
            $result.empty();
            if (!data.items.length) {
                $result.append(el('div', 'card empty', 'Код ' + code + ' не найден' + (modelId() ? ' для выбранной модели' : '') + '.'));
                return;
            }
            if (data.items.length > 1) {
                $result.append(el('div', 'status status--warn',
                    'Код встречается в ' + data.total + ' устройствах и значит в них разное. Выберите модель, чтобы оставить нужный.'));
            }
            $.each(data.items, function (_, item) { $result.append(renderCode(item)); });
        }, function (err) {
            $result.empty();
            showError($form, err.message);
        });
    }

    function showItem(id) {
        var $result = $('#code-result');
        loading($result);
        api('GET', '/api/codes/' + encodeURIComponent(id)).then(function (data) {
            $('#code-form [name=code]').val(data.item.code);
            $result.empty().append(renderCode(data.item));
        }, function (err) {
            $result.empty().append(el('div', 'card empty', err.message));
        });
    }

    $('#code-form').on('submit', function (e) {
        e.preventDefault();
        var code = $.trim($(this).find('[name=code]').val());
        if (!code) {
            showError($(this), 'Введите код.');
            return;
        }
        location.hash = 'code/' + encodeURIComponent(code);
    });

    // ---------- Поиск ----------

    function renderHit(hit) {
        var $title = el('div', 'hit__title');
        $title.append(el('span', 'badge', TYPES[hit.type]).css('margin-right', '6px'));
        if (hit.type === 'codes') { $title.append(document.createTextNode(hit.code + ' — ')); }
        if (hit.title_html) { $title.append($('<span>').html(hit.title_html)); } else { $title.append(document.createTextNode(hit.title)); }

        var $hit = hit.type === 'codes' ? $('<a class="hit">').attr('href', '#item/' + hit.id) : el('div', 'hit');
        $hit.append($title);
        if (hit.snippet_html) { $hit.append($('<div class="hit__snippet">').html(hit.snippet_html)); }
        if (hit.type === 'codes' && hit.owner) { $hit.append(el('div', 'hit__snippet', hit.owner.name)); }
        return $('<li>').append($hit);
    }

    function showSearch(q) {
        var $form = $('#search-form');
        var $result = $('#search-result');
        $form.find('[name=q]').val(q);
        showError($form, '');

        var types = $form.find('[name=type]:checked').map(function () { return this.value; }).get();
        if (!types.length) {
            showError($form, 'Выберите хотя бы один раздел.');
            return;
        }
        loading($result);
        var query = { q: q, limit: 30 };
        if (types.length < 5) { query.type = types.join(','); }
        if (modelId()) { query.model = modelId(); }

        api('GET', '/api/search', query).then(function (data) {
            $result.empty();
            if (!data.items.length) {
                $result.append(el('div', 'card empty', 'Ничего не найдено. Попробуйте другие слова.'));
                return;
            }
            var $list = el('ul', 'list');
            $.each(data.items, function (_, hit) { $list.append(renderHit(hit)); });
            $result.append(el('p', 'owner', 'Найдено: ' + data.total), el('div', 'card').append($list));
        }, function (err) {
            $result.empty();
            showError($form, err.message);
        });
    }

    $('#search-form').on('submit', function (e) {
        e.preventDefault();
        var q = $.trim($(this).find('[name=q]').val());
        if (!q) {
            showError($(this), 'Введите, что искать.');
            return;
        }
        var hash = 'search/' + encodeURIComponent(q);
        if (location.hash === '#' + hash) { showSearch(q); } else { location.hash = hash; }
    });

    $('#search-form [name=type]').on('change', function () {
        var q = $.trim($('#search-form [name=q]').val());
        if (q) { showSearch(q); }
    });

    // ---------- Навигация ----------

    function route() {
        var parts = location.hash.replace(/^#/, '').split('/');
        var tab = parts[0] || 'diagnose';
        var arg = parts.length > 1 ? decodeURIComponent(parts.slice(1).join('/')) : '';
        if (tab === 'item') { tab = 'code'; }
        if (!$('[data-panel="' + tab + '"]').length) { tab = 'diagnose'; }

        $('[data-panel]').prop('hidden', true);
        $('[data-panel="' + tab + '"]').prop('hidden', false);
        $('.tab').attr('aria-selected', 'false').filter('[data-tab="' + tab + '"]').attr('aria-selected', 'true');

        if (parts[0] === 'item' && arg) { showItem(arg); }
        if (parts[0] === 'code' && arg) { showCode(arg); }
        if (parts[0] === 'search' && arg) { showSearch(arg); }
    }

    $('#model').on('change', function () {
        storage(MODEL_KEY, modelId());
        // Перезапросить открытый экран с новой моделью
        if (/^#(code|search)\/./.test(location.hash)) { route(); }
    });

    $(window).on('hashchange', route);
    loadModels().always(route);
})(jQuery);
