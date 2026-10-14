// Lift Fault Terminal — презентация, 80 с, 1080×1920.
// Всё рисуется на одном холсте функцией draw(t) от времени t: повторный рендер даёт тот же ролик.
// Движение «на двойках»: рисунок меняется 12 раз в секунду. Случайность — только с зерном.
// Время событий — в cues.json (через cues.js), его же берёт генератор звука.
/* global CUES, gsap */
(function () {
    'use strict';

    var W = 1080, H = 1920;
    var C = {
        ground: '#f4f1f0', surface: '#ffffff', raised: '#eadcda', faint: '#dcd2d0', line: '#9aa8b5',
        ink: '#262b30', muted: '#5e6873', steel: '#4f6578', rose: '#a8646f', ok: '#2f7d68', grey: '#b9b3b1'
    };
    var F = {
        display: '800 {s}px Unbounded, "Arial Black", sans-serif',
        sans: '400 {s}px "Golos Text", system-ui, sans-serif',
        sansB: '600 {s}px "Golos Text", system-ui, sans-serif',
        mono: '500 {s}px "IBM Plex Mono", monospace',
        pixel: '400 {s}px "Jersey 10", monospace'
    };
    function font(kind, size) { return F[kind].replace('{s}', size); }

    var S = CUES.scenes, HIT = CUES.hits;
    var canvas = document.getElementById('stage');
    var ctx = canvas.getContext('2d');
    var img = function (id) { return document.getElementById(id); };
    var SCREENS = CUES.screens;

    // ---------- помощники ----------
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function prog(t, a, b) { return clamp((t - a) / (b - a), 0, 1); }
    function eOut(p) { return 1 - Math.pow(1 - p, 3); }
    function eInOut(p) { return p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2; }
    function eBack(p) { var c = 1.7; return 1 + (c + 1) * Math.pow(p - 1, 3) + c * Math.pow(p - 1, 2); }
    function lerp(a, b, p) { return a + (b - a) * p; }
    function inScene(t, name, pad) { var s = S[name]; pad = pad || 0; return t >= s[0] - pad && t < s[1] + pad; }
    // Генератор с зерном (mulberry32): одинаковая «случайность» в каждом рендере
    function rng(seed) {
        return function () {
            seed |= 0; seed = seed + 0x6D2B79F5 | 0;
            var x = Math.imul(seed ^ seed >>> 15, 1 | seed);
            x = x + Math.imul(x ^ x >>> 7, 61 | x) ^ x;
            return ((x ^ x >>> 14) >>> 0) / 4294967296;
        };
    }
    function rrect(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
    }
    function wrap(text, maxW) {
        var words = text.split(' '), lines = [], cur = '';
        words.forEach(function (w) {
            var test = cur ? cur + ' ' + w : w;
            if (ctx.measureText(test).width > maxW && cur) { lines.push(cur); cur = w; } else { cur = test; }
        });
        if (cur) { lines.push(cur); }
        return lines;
    }
    // Пиксельный значок (белая маска) в нужном цвете
    var tintCache = {};
    function tinted(id, color, w, h) {
        var key = id + color + w;
        if (!tintCache[key]) {
            var c = document.createElement('canvas'); c.width = w; c.height = h;
            var x = c.getContext('2d');
            x.drawImage(img(id), 0, 0, w, h);
            x.globalCompositeOperation = 'source-in'; x.fillStyle = color; x.fillRect(0, 0, w, h);
            tintCache[key] = c;
        }
        return tintCache[key];
    }
    function wordmark(x, y, w) {
        var h = Math.round(w * img('wordmark').naturalHeight / img('wordmark').naturalWidth);
        ctx.drawImage(tinted('wordmark', C.ink, w, h), x, y, w, h);
    }
    function icon(name, color, size) {
        var key = name + color + size;
        if (!tintCache[key]) {
            var c = document.createElement('canvas'); c.width = c.height = size;
            var x = c.getContext('2d'); x.imageSmoothingEnabled = false;
            x.drawImage(img('ic-' + name), 0, 0, size, size);
            x.globalCompositeOperation = 'source-in'; x.fillStyle = color; x.fillRect(0, 0, size, size);
            tintCache[key] = c;
        }
        return tintCache[key];
    }

    // ---------- фон: бумага в мелкую пиксельную сетку ----------
    function background(warm) {
        ctx.fillStyle = C.ground; ctx.fillRect(0, 0, W, H);
        ctx.fillStyle = warm > 0 ? 'rgba(168,100,111,' + (0.05 * warm) + ')' : 'rgba(0,0,0,0)';
        ctx.fillRect(0, 0, W, H);
        ctx.fillStyle = 'rgba(79,101,120,0.07)';
        for (var y = 24; y < H; y += 48) { for (var x = 24; x < W; x += 48) { ctx.fillRect(x, y, 3, 3); } }
    }

    // ---------- подпись сверху ----------
    function caption(t, a, b, label, text, color) {
        if (t < a - 0.1 || t > b) { return; }
        var pIn = eOut(prog(t, a, a + 0.5)), pOut = prog(t, b - 0.35, b);
        ctx.save();
        ctx.globalAlpha = pIn * (1 - pOut);
        var dy = (1 - pIn) * 30;
        ctx.font = font('mono', 30); ctx.fillStyle = color || C.steel; ctx.textBaseline = 'alphabetic';
        ctx.fillText(label.toUpperCase().split('').join(String.fromCharCode(8202)), 80, 140 + dy);
        ctx.font = font('display', 60); ctx.fillStyle = C.ink;
        var lines = wrap(text.toUpperCase(), 940);
        // Набор по буквам: подпись «печатается» как в терминале
        var shown = Math.floor(prog(t, a, a + 0.9) * text.length * 1.0 + 0.0001);
        var left = shown;
        lines.forEach(function (ln, i) {
            var part = ln.slice(0, Math.max(0, left)); left -= ln.length + 1;
            ctx.fillText(part, 80, 226 + i * 70 + dy);
        });
        ctx.restore();
    }

    // ---------- телефон ----------
    var PH = { x: 270, y: 420, w: 540, h: 1169, r: 42, bez: 17 };
    var KS = PH.w / 780; // масштаб снимка 780×1688 к экрану телефона
    // Камера: масштаб и центр кадра (по умолчанию — телефон целиком)
    function withPhone(view, fn) {
        ctx.save();
        // Телефон и его приближение — только ниже подписей
        ctx.beginPath(); ctx.rect(0, 340, W, H - 340); ctx.clip();
        var s = view.scale || 1, cx = view.cx === undefined ? PH.x + PH.w / 2 : view.cx, cy = view.cy === undefined ? PH.y + PH.h / 2 : view.cy;
        ctx.translate(W / 2 + (view.dx || 0), (PH.y + PH.h / 2) + (view.dy || 0));
        ctx.scale(s, s);
        ctx.translate(-cx, -cy);
        ctx.globalAlpha = view.alpha === undefined ? 1 : view.alpha;
        // Корпус
        ctx.fillStyle = 'rgba(38,43,48,0.16)';
        rrect(PH.x - PH.bez + 10, PH.y - PH.bez + 24, PH.w + PH.bez * 2, PH.h + PH.bez * 2, PH.r + PH.bez); ctx.fill();
        ctx.fillStyle = C.ink;
        rrect(PH.x - PH.bez, PH.y - PH.bez, PH.w + PH.bez * 2, PH.h + PH.bez * 2, PH.r + PH.bez); ctx.fill();
        ctx.save();
        rrect(PH.x, PH.y, PH.w, PH.h, PH.r); ctx.clip();
        ctx.fillStyle = C.ground; ctx.fillRect(PH.x, PH.y, PH.w, PH.h);
        fn();
        ctx.restore();
        ctx.fillStyle = C.ink; rrect(PH.x + PH.w / 2 - 54, PH.y + 10, 108, 26, 13); ctx.fill();
        ctx.restore();
    }
    // Снимок экрана на телефоне; offY — прокрутка снимка вверх (в пикселях снимка)
    function screen(name, alpha, offY) {
        if (alpha <= 0) { return; }
        ctx.save(); ctx.globalAlpha *= alpha;
        ctx.drawImage(img('s-' + name), PH.x, PH.y - (offY || 0) * KS, PH.w, 1688 * KS);
        ctx.restore();
    }
    // Точка на снимке → координаты холста (до камеры)
    function sp(name, key, fx, fy) {
        var r = SCREENS[name].rects[key];
        return { x: PH.x + (r[0] + r[2] * (fx === undefined ? 0.5 : fx)) * KS, y: PH.y + (r[1] + r[3] * (fy === undefined ? 0.5 : fy)) * KS, w: r[2] * KS, h: r[3] * KS };
    }
    // Касание: кольцо расходится от точки
    function tap(t, at, x, y) {
        var p = prog(t, at - 0.05, at + 0.45);
        if (p <= 0 || p >= 1) { return; }
        ctx.save();
        ctx.strokeStyle = C.rose; ctx.lineWidth = 6 * (1 - p) + 2; ctx.globalAlpha = 1 - p;
        ctx.beginPath(); ctx.arc(x, y, 18 + 46 * eOut(p), 0, Math.PI * 2); ctx.stroke();
        ctx.fillStyle = 'rgba(168,100,111,' + (0.35 * (1 - p)) + ')';
        ctx.beginPath(); ctx.arc(x, y, 22, 0, Math.PI * 2); ctx.fill();
        ctx.restore();
    }
    // Набор текста в поле (поверх подсказки поля)
    function typeInto(t, a, b, r, text, size, pad) {
        if (t < a) { return; }
        var n = Math.floor(prog(t, a, b) * text.length + 0.0001);
        ctx.save();
        ctx.fillStyle = C.surface; ctx.fillRect(r.x - r.w / 2 + 2, r.y - r.h / 2 + 2, r.w - 4, r.h - 4);
        ctx.font = font('sans', size); ctx.fillStyle = C.ink; ctx.textBaseline = 'middle';
        var s = text.slice(0, n);
        ctx.fillText(s, r.x - r.w / 2 + (pad || 12), r.y);
        if (Math.floor(t * 4) % 2 === 0 || n < text.length) {
            var w = ctx.measureText(s).width;
            ctx.fillStyle = C.steel; ctx.fillRect(r.x - r.w / 2 + (pad || 12) + w + 2, r.y - size * 0.6, 3, size * 1.2);
        }
        ctx.restore();
    }

    // ---------- уведомление (как на Android) ----------
    function push(t, at, until, title, body, accent, y) {
        var pIn = eBack(prog(t, at, at + 0.45)), pOut = eInOut(prog(t, until - 0.4, until));
        if (t < at || t > until) { return; }
        var x = 110, w = 860, h = 196;
        y = (y === undefined ? 300 : y) + lerp(-260, 0, pIn) - 300 * pOut;
        ctx.save();
        ctx.globalAlpha = 1 - pOut;
        ctx.fillStyle = 'rgba(38,43,48,0.22)'; rrect(x + 6, y + 14, w, h, 34); ctx.fill();
        ctx.fillStyle = C.surface; rrect(x, y, w, h, 34); ctx.fill();
        ctx.fillStyle = accent || C.steel; rrect(x, y, 14, h, 7); ctx.fill();
        ctx.drawImage(img('app-icon'), x + 40, y + 40, 72, 72);
        ctx.font = font('mono', 24); ctx.fillStyle = C.muted; ctx.textBaseline = 'alphabetic';
        ctx.fillText('LFT · СЕЙЧАС', x + 136, y + 62);
        ctx.font = font('sansB', 36); ctx.fillStyle = C.ink;
        ctx.fillText(title, x + 136, y + 110);
        ctx.font = font('sans', 30); ctx.fillStyle = C.muted;
        ctx.fillText(body, x + 136, y + 156);
        ctx.restore();
    }

    // ---------- 1. Обложка: знак LFT собирается из пикселей ----------
    // Знак: сетка 15×15 (как lft-mark.svg: рамка, две створки, стрелка вверх)
    var MARK = (function () {
        var cells = [];
        var parts = [[0, 0, 15, 1], [0, 14, 15, 1], [0, 0, 1, 15], [14, 0, 1, 15], [3, 6, 4, 7], [8, 6, 4, 7], [5, 4, 5, 1], [6, 3, 3, 1], [7, 2, 1, 1]];
        parts.forEach(function (p) { for (var y = p[1]; y < p[1] + p[3]; y++) { for (var x = p[0]; x < p[0] + p[2]; x++) { cells.push([x, y]); } } });
        var seen = {}; return cells.filter(function (c) { var k = c.join(); if (seen[k]) { return false; } seen[k] = 1; return true; });
    }());
    function mark(t, cx, cy, cell, color, start, dur) {
        var r = rng(7);
        MARK.forEach(function (c, i) {
            var sx = r() * W, sy = r() * H, delay = r() * 0.6;
            var p = eOut(prog(t, start + delay * dur, start + delay * dur + dur * 0.4));
            var x = lerp(sx, cx + (c[0] - 7.5) * cell, p), y = lerp(sy, cy + (c[1] - 7.5) * cell, p);
            ctx.fillStyle = color; ctx.globalAlpha = 0.25 + 0.75 * p;
            ctx.fillRect(Math.round(x), Math.round(y), cell - 2, cell - 2);
        });
        ctx.globalAlpha = 1;
    }
    function sceneCover(t) {
        // Начало чуть раньше нуля: первый кадр уже не пустой
        mark(t, 540, 760, 30, C.steel, -3.6, 6.0);
        var p = prog(t, 2.6, 3.4);
        ctx.save();
        ctx.globalAlpha = eOut(p);
        wordmark(540 - 360, 1080 + (1 - eOut(p)) * 20, 720);
        ctx.font = font('sansB', 40); ctx.fillStyle = C.muted; ctx.textAlign = 'center';
        var sub = 'приложение бригады лифтёров';
        ctx.fillText(sub.slice(0, Math.floor(prog(t, 3.2, 4.2) * sub.length)), 540, 1330);
        ctx.restore(); ctx.textAlign = 'left';
        // Мигающий курсор терминала
        if (t < 4.6 && Math.floor(t * 2.5) % 2 === 0) { ctx.fillStyle = C.rose; ctx.fillRect(540 + 260, 1300, 6, 40); }
        // Уход: знак сжимается в точку, из которой появится первая карточка
        var out = prog(t, 4.5, 5.0);
        if (out > 0) { ctx.fillStyle = 'rgba(244,241,240,' + out + ')'; ctx.fillRect(0, 0, W, H); }
    }

    // ---------- 2. Проблема: всё разбросано ----------
    var PROBLEM = [
        ['code', 'Коды', 'в толстых руководствах'],
        ['smile', 'Заявки', 'в 1С и на почте у мастера'],
        ['box', 'Запчасти', 'по телефону, на словах'],
        ['journal', 'Журнал', 'в тетради — если записали']
    ];
    function sceneProblem(t) {
        var a = S.problem[0];
        caption(t, a + 0.2, S.problem[1] - 0.3, '01 · Как сейчас', 'Всё в разных местах', C.muted);
        var brk = HIT.cardsBreak;
        PROBLEM.forEach(function (c, i) {
            var at = HIT.cardsIn[i];
            var p = eBack(prog(t, at, at + 0.4));
            if (t < at) { return; }
            var x = 100, y = 520 + i * 230, w = 880, h = 190;
            var shake = t > brk - 1.2 && t < brk ? Math.sin(Math.floor(t * 12) * 2.1 + i) * 6 : 0;
            var bp = prog(t, brk, brk + 1.4);
            if (bp > 0) {
                // Карточка рассыпается на пиксели, они летят к экрану будущего телефона
                var r = rng(31 + i);
                for (var k = 0; k < 90; k++) {
                    var px = x + r() * w, py = y + r() * h, tx = PH.x + r() * PH.w, ty = PH.y + r() * PH.h, d = r() * 0.4;
                    var q = eInOut(prog(bp, d, d + 0.6));
                    ctx.fillStyle = k % 3 === 0 ? C.steel : C.grey;
                    ctx.globalAlpha = 1 - prog(bp, 0.85, 1);
                    ctx.fillRect(Math.round(lerp(px, tx, q)), Math.round(lerp(py, ty, q)), 14, 14);
                }
                ctx.globalAlpha = 1;
                return;
            }
            ctx.save();
            ctx.translate(x + shake + (1 - p) * -120, y);
            ctx.globalAlpha = clamp(p, 0, 1);
            ctx.fillStyle = C.surface; rrect(0, 0, w, h, 6); ctx.fill();
            ctx.strokeStyle = C.faint; ctx.lineWidth = 3; ctx.stroke();
            ctx.drawImage(icon(c[0], C.grey, 96), 40, 47);
            ctx.font = font('display', 46); ctx.fillStyle = C.muted; ctx.fillText(c[1].toUpperCase(), 170, 88);
            ctx.font = font('sans', 36); ctx.fillStyle = C.muted; ctx.fillText(c[2], 170, 140);
            // Перечёркнуто: так неудобно
            var sp2 = prog(t, at + 1.6, at + 2.0);
            if (sp2 > 0) { ctx.strokeStyle = C.rose; ctx.lineWidth = 6; ctx.beginPath(); ctx.moveTo(160, 70); ctx.lineTo(160 + 680 * sp2, 70); ctx.stroke(); }
            ctx.restore();
        });
    }

    // ---------- 3. Коды ----------
    function sceneCodes(t) {
        var a = S.codes[0], b = S.codes[1];
        caption(t, a + 0.2, b - 0.2, '02 · Коды', 'Код с пульта: что проверить');
        var appear = eOut(prog(t, a, a + 0.6));
        var opened = prog(t, HIT.codeOpen, HIT.codeOpen + 0.35);
        var zoom = eInOut(prog(t, 16.4, 17.6));
        var inp = sp('codes-empty', 'input'), btn = sp('codes-empty', 'search');
        withPhone({ alpha: appear, scale: lerp(1, 1.18, zoom), cy: lerp(PH.y + PH.h / 2, PH.y + 520, zoom) }, function () {
            screen('codes-empty', 1 - opened);
            if (opened < 1) { typeInto(t, HIT.codeType[0], HIT.codeType[1], inp, '44', 22, 58); }
            screen('codes-44', opened);
            tap(t, HIT.codeTap, btn.x, btn.y);
        });
    }

    // ---------- 4. ИИ ----------
    function sceneAi(t) {
        var a = S.ai[0], b = S.ai[1];
        caption(t, a + 0.1, b - 0.2, '03 · ИИ-диагностика', 'ИИ уточнит и подскажет');
        var ans = prog(t, HIT.aiAnswer, HIT.aiAnswer + 0.4);
        var scroll = eInOut(prog(t, 26.6, 28.4));
        var inp = sp('ai-empty', 'text'), send = sp('ai-empty', 'send');
        withPhone({ alpha: eOut(prog(t, a - 0.2, a + 0.3)) }, function () {
            screen('ai-empty', 1 - ans);
            if (ans < 1) { typeInto(t, HIT.aiType[0], HIT.aiType[1], inp, 'Лифт стоит, двери не закрываются', 21, 8); }
            // Ожидание ответа: бегущие пиксели
            if (t > HIT.aiTap && t < HIT.aiAnswer) {
                for (var i = 0; i < 6; i++) {
                    ctx.fillStyle = (Math.floor(t * 12) + i) % 6 === 0 ? C.rose : C.line;
                    ctx.fillRect(PH.x + 180 + i * 34, PH.y + 760, 22, 22);
                }
            }
            screen('ai-answer-top', ans, scroll * 420);
            tap(t, HIT.aiTap, send.x, send.y);
        });
    }

    // ---------- 5. Поворот: отчёт 1С сам раскладывается по механикам ----------
    var ROWS = [['3105н', 'Садовая 12, п.1', '49 · 44 C6'], ['6613н', 'Солнечная 5', 'Частые застр.'], ['2508н', 'Лесная 7', '45 · 44 C0'], ['6115н', 'Цветочная 21', '99 · АПСиПДЗ'], ['4401н', 'Северная 44', 'ТО2']];
    var MECHS = [['Петров А. С.', 0, 2], ['Сидоренко В. Н.', 1, 3], ['Ковальчук Д. Ю.', 4]];
    function sceneTurn(t) {
        var a = S.turn[0];
        caption(t, a + 0.1, S.turn[1] - 0.1, '04 · Заявки 1С', 'Заявки 1С сами у механиков', C.rose);
        var mailP = eBack(prog(t, HIT.mailIn, HIT.mailIn + 0.5));
        var phoneIn = eOut(prog(t, 32.2, 32.9));
        // Таблица отчёта
        var tx = 120, ty = 470, tw = 840;
        if (phoneIn < 1) {
            ctx.save();
            ctx.globalAlpha = clamp(mailP, 0, 1) * (1 - phoneIn);
            ctx.translate(0, (1 - mailP) * -80);
            ctx.fillStyle = C.surface; rrect(tx, ty, tw, 560, 6); ctx.fill();
            ctx.strokeStyle = C.faint; ctx.lineWidth = 3; ctx.stroke();
            ctx.drawImage(icon('journal', C.steel, 56), tx + 30, ty + 30);
            ctx.font = font('mono', 28); ctx.fillStyle = C.steel; ctx.fillText('ОТЧЁТ 1С · 08:00 · ПОЧТА МАСТЕРА', tx + 104, ty + 68);
            ROWS.forEach(function (r, i) {
                var fp = eInOut(prog(t, HIT.rowsFly[i], HIT.rowsFly[i] + 0.9));
                var y0 = ty + 130 + i * 84;
                var target = MECHS.findIndex(function (m) { return m.indexOf(i, 1) > 0; });
                var mx = 200 + target * 340, my = 1240;
                var x = lerp(tx + 30, mx - 60, fp), y = lerp(y0, my - 30, fp);
                ctx.save();
                ctx.globalAlpha *= 1 - prog(fp, 0.85, 1);
                ctx.fillStyle = fp > 0 ? C.raised : C.ground; rrect(x, y, lerp(tw - 60, 220, fp), 64, 4); ctx.fill();
                ctx.font = font('mono', 26); ctx.fillStyle = C.ink; ctx.fillText(r[0], x + 18, y + 42);
                if (fp < 0.5) { ctx.font = font('sans', 28); ctx.fillText(r[1], x + 150, y + 42); ctx.fillStyle = C.rose; ctx.fillText(r[2], x + 520, y + 42); }
                ctx.restore();
            });
            // Механики, к которым прилетают строки
            MECHS.forEach(function (m, i) {
                var x = 200 + i * 340, y = 1240;
                var got = m.slice(1).filter(function (ri) { return t > HIT.rowsFly[ri] + 0.9; }).length;
                ctx.fillStyle = C.surface; rrect(x - 155, y - 80, 310, 170, 6); ctx.fill();
                ctx.strokeStyle = got ? C.rose : C.faint; ctx.lineWidth = 3; ctx.stroke();
                ctx.drawImage(icon('settings', C.steel, 40), x - 135, y - 58);
                ctx.font = font('sansB', 27); ctx.fillStyle = C.ink; ctx.fillText(m[0], x - 84, y - 28);
                ctx.font = font('pixel', 80); ctx.fillStyle = got ? C.rose : C.line; ctx.fillText(String(got), x - 135, y + 64);
                ctx.font = font('mono', 24); ctx.fillStyle = C.muted; ctx.fillText(got === 1 ? 'ЗАЯВКА' : 'ЗАЯВКИ', x - 88, y + 54);
            });
            ctx.restore();
        }
        // Телефон с уведомлением
        if (phoneIn > 0) {
            withPhone({ alpha: phoneIn, dy: (1 - phoneIn) * 400 }, function () { screen('repairs-list', 1); });
            push(t, HIT.push1, S.turn[1] + 0.6, 'Заявки на ремонт: 2', 'Садовая 12, п.1 — код 49, 44 C6', C.rose, 430);
        }
        // Вспышка поворота
        var fl = prog(t, HIT.push1, HIT.push1 + 0.5);
        if (fl > 0 && fl < 1) { ctx.fillStyle = 'rgba(255,255,255,' + (0.55 * (1 - fl)) + ')'; ctx.fillRect(0, 0, W, H); }
    }

    // ---------- 6. Принял → закрыл ----------
    function sceneAccept(t) {
        var a = S.accept[0], b = S.accept[1];
        caption(t, a + 0.1, b - 0.2, '05 · В работе', 'Принял, закрыл — мастер видит', C.rose);
        var acc = sp('repairs-list', 'accept'), close = sp('repairs-accepted', 'close'), sub = sp('repairs-close-sheet', 'submit');
        var s1 = prog(t, HIT.acceptTap + 0.3, HIT.acceptTap + 0.42);
        var s2 = prog(t, HIT.closeTap + 0.3, HIT.closeTap + 0.42);
        var s3 = prog(t, HIT.submitTap + 0.3, HIT.submitTap + 0.42);
        var zoom = eInOut(prog(t, a, a + 0.8)) * (1 - eInOut(prog(t, HIT.closeTap, HIT.closeTap + 0.6)));
        withPhone({ scale: lerp(1, 1.35, zoom), cy: lerp(PH.y + PH.h / 2, acc.y + 80, zoom) }, function () {
            screen('repairs-list', 1 - s1);
            screen('repairs-accepted', s1 * (1 - s2));
            screen('repairs-close-sheet', s2 * (1 - s3));
            screen('repairs-after', s3);
            tap(t, HIT.acceptTap, acc.x, acc.y);
            tap(t, HIT.closeTap, close.x, close.y);
            tap(t, HIT.submitTap, sub.x, sub.y);
        });
        push(t, HIT.closedPush, b + 0.3, 'Заявка закрыта: устранено', 'Петров А. С. · Лесная 7, лифт 1', C.ok, 430);
    }

    // ---------- 7. Заявка от мастера ----------
    function sceneMaster(t) {
        var a = S.master[0], b = S.master[1];
        caption(t, a + 0.1, b - 0.2, '06 · Мастер', 'Срочное — сразу на телефон', C.rose);
        var st = sp('master-new-call', 'stopped'), send = sp('master-new-call', 'submit');
        withPhone({ alpha: eOut(prog(t, a - 0.1, a + 0.4)) }, function () {
            screen('master-new-call', 1);
            tap(t, HIT.stoppedTap, st.x, st.y);
            tap(t, HIT.sendTap, send.x, send.y);
        });
        push(t, HIT.urgentPush, b + 0.2, 'Заявка от мастера · лифт стоит', 'Лесная 7, лифт 1 — код 44 C6', C.rose, 430);
    }

    // ---------- 8. Запчасти: шаги статуса ----------
    function sceneParts(t) {
        var a = S.parts[0], b = S.parts[1];
        caption(t, a + 0.1, b - 0.2, '07 · Запчасти', 'Деталь: от заявки до выдачи', C.rose);
        var steps = sp('part-new', 'steps');
        var names = ['part-accepted', 'part-ordered', 'part-ready'];
        var zp = eInOut(prog(t, a + 0.3, a + 1.0));
        withPhone({ alpha: eOut(prog(t, a - 0.1, a + 0.4)), scale: lerp(1, 1.6, zp), cy: lerp(PH.y + PH.h / 2, steps.y + 40, zp) }, function () {
            screen('part-new', 1);
            // Полоса шагов из снимков следующих статусов поверх
            names.forEach(function (n, i) {
                var p = prog(t, HIT.partSteps[i], HIT.partSteps[i] + 0.3);
                if (p <= 0) { return; }
                var r = SCREENS[n].rects.steps;
                ctx.save(); ctx.globalAlpha *= p;
                ctx.drawImage(img('s-' + n), r[0], r[1], r[2], r[3], steps.x - steps.w / 2, steps.y - steps.h / 2, steps.w, steps.h);
                ctx.restore();
            });
            // Подсветка текущего шага
            var cur = HIT.partSteps.filter(function (h) { return t > h; }).length;
            var cx = steps.x - steps.w / 2 + steps.w * (cur + 0.5) / 5;
            ctx.strokeStyle = C.rose; ctx.lineWidth = 4; ctx.strokeRect(cx - steps.w / 10 + 4, steps.y - steps.h / 2 - 10, steps.w / 5 - 8, steps.h + 20);
        });
        push(t, HIT.readyPush, b + 0.2, 'Деталь пришла, можно забирать', 'Ролик двери кабины · 2 шт', C.ok, 430);
    }

    // ---------- 9. Чат ----------
    function sceneChat(t) {
        var a = S.chat[0], b = S.chat[1];
        caption(t, a + 0.1, b - 0.2, '08 · Чат бригады', 'Вся бригада на связи', C.rose);
        var on = sp('chat', 'online'), like = sp('chat', 'like'), badge = sp('tabs-unread', 'chat');
        var z = eInOut(prog(t, HIT.onlineZoom, HIT.onlineZoom + 0.6)) * (1 - eInOut(prog(t, 62.2, 62.8)));
        var sw = prog(t, 63.6, 64.0);
        withPhone({ alpha: eOut(prog(t, a - 0.1, a + 0.4)), scale: lerp(1, 1.6, z), cx: lerp(PH.x + PH.w / 2, on.x, z), cy: lerp(PH.y + PH.h / 2, on.y + 60, z) }, function () {
            screen('chat', 1 - sw);
            screen('tabs-unread', sw);
            var hp = prog(t, HIT.heartPop, HIT.heartPop + 0.4);
            if (hp > 0 && hp < 1 && sw < 1) {
                ctx.save(); ctx.translate(like.x - like.w / 4, like.y); ctx.scale(1 + Math.sin(hp * Math.PI) * 0.8, 1 + Math.sin(hp * Math.PI) * 0.8);
                ctx.drawImage(icon('heart', C.rose, 28), -14, -14); ctx.restore();
            }
            var bp = prog(t, HIT.badgePop, HIT.badgePop + 0.5);
            if (bp > 0 && bp < 1) {
                ctx.strokeStyle = C.rose; ctx.lineWidth = 4; ctx.globalAlpha = 1 - bp;
                ctx.beginPath(); ctx.arc(badge.x + 14, badge.y - 26, 16 + 40 * bp, 0, Math.PI * 2); ctx.stroke(); ctx.globalAlpha = 1;
            }
        });
    }

    // ---------- 10. Тёмная тема ----------
    function sceneTheme(t) {
        var a = S.theme[0], b = S.theme[1];
        caption(t, a + 0.1, b - 0.2, '09 · Тёмная тема', 'Тёмная — для ночных вызовов', C.steel);
        var th = sp('codes-44', 'theme');
        var toDark = prog(t, HIT.themeTap + 0.15, HIT.themeTap + 0.9);
        var toLight = prog(t, HIT.themeBack, HIT.themeBack + 0.6);
        var dark = toDark * (1 - toLight);
        withPhone({ alpha: eOut(prog(t, a - 0.1, a + 0.4)) }, function () {
            screen('codes-44', 1);
            if (dark > 0) {
                // Пиксельное растворение: блоки экрана меняются в случайном (с зерном) порядке
                var r = rng(99), cols = 14, rows = 30, cw = PH.w / cols, chh = PH.h / rows;
                for (var y = 0; y < rows; y++) {
                    for (var x = 0; x < cols; x++) {
                        var thr = r();
                        if (thr > dark) { continue; }
                        ctx.drawImage(img('s-codes-44-dark'), x * 780 / cols, y * 1688 / rows, 780 / cols, 1688 / rows, PH.x + x * cw, PH.y + y * chh, cw + 1, chh + 1);
                    }
                }
            }
            tap(t, HIT.themeTap, th.x, th.y);
            tap(t, HIT.themeBack, th.x, th.y);
        });
    }

    // ---------- 11. Сборка: всё в одном приложении ----------
    var TABS = [['code', 'Коды'], ['chip', 'ИИ'], ['journal', 'Журнал'], ['smile', 'Заявки 1С'], ['box', 'Запчасти'], ['chat', 'Чат']];
    function sceneAssembly(t) {
        var a = S.assembly[0], b = S.assembly[1];
        caption(t, a + 0.1, b + 0.2, '10 · Итог', 'Всё в одном приложении', C.steel);
        var phoneOut = eInOut(prog(t, a, a + 0.8));
        if (phoneOut < 1) { withPhone({ alpha: 1 - phoneOut, scale: 1 - 0.3 * phoneOut }, function () { screen('tabs-unread', 1); }); }
        TABS.forEach(function (tb, i) {
            var p = eBack(prog(t, HIT.iconsFly[i], HIT.iconsFly[i] + 0.5));
            if (p <= 0) { return; }
            var col = i % 2, row = Math.floor(i / 2);
            var x = lerp(PH.x + (i + 0.5) * PH.w / 6, 140 + col * 420, clamp(p, 0, 1)), y = lerp(PH.y + PH.h - 60, 520 + row * 300, clamp(p, 0, 1));
            ctx.save();
            ctx.globalAlpha = clamp(p, 0, 1);
            ctx.fillStyle = C.surface; rrect(x, y, 380, 240, 6); ctx.fill();
            ctx.strokeStyle = i === 3 ? C.rose : C.faint; ctx.lineWidth = 4; ctx.stroke();
            ctx.drawImage(icon(tb[0], i === 3 ? C.rose : C.steel, 96), x + 36, y + 36);
            ctx.font = font('display', 40); ctx.fillStyle = C.ink; ctx.fillText(tb[1].toUpperCase(), x + 36, y + 196);
            ctx.restore();
        });
    }

    // ---------- 12. Постер: ничего не движется ----------
    function scenePoster(t) {
        var p = eOut(prog(t, S.poster[0] - 0.4, S.poster[0] + 0.2));
        ctx.save(); ctx.globalAlpha = p;
        ctx.fillStyle = C.ground; ctx.fillRect(0, 0, W, H);
        background(1);
        mark(99, 540, 560, 26, C.steel, -10, 1);
        wordmark(540 - 380, 860, 760);
        ctx.font = font('sansB', 40); ctx.fillStyle = C.ink; ctx.textAlign = 'center';
        ctx.fillText('Коды · ИИ · Журнал', 540, 1180);
        ctx.fillText('Заявки 1С · Запчасти · Чат', 540, 1240);
        ctx.font = font('mono', 30); ctx.fillStyle = C.rose;
        ctx.fillText('ANDROID · IPHONE · КОМПЬЮТЕР', 540, 1360);
        ctx.font = font('sans', 30); ctx.fillStyle = C.muted;
        ctx.fillText('приложение бригады лифтёров', 540, 1440);
        ctx.textAlign = 'left';
        ctx.restore();
    }

    // ---------- кадр ----------
    function draw(time) {
        var t = Math.floor(time * 12 + 1e-6) / 12; // на двойках: 12 рисунков в секунду
        ctx.imageSmoothingEnabled = true;
        var warm = prog(t, HIT.push1, HIT.push1 + 1) * (1 - prog(t, S.theme[0], S.theme[0] + 0.5)) + prog(t, S.assembly[0], S.assembly[0] + 1);
        background(clamp(warm, 0, 1));
        if (inScene(t, 'cover')) { sceneCover(t); }
        if (inScene(t, 'problem', 0.0)) { sceneProblem(t); }
        if (inScene(t, 'codes')) { sceneCodes(t); }
        if (inScene(t, 'ai')) { sceneAi(t); }
        if (inScene(t, 'turn')) { sceneTurn(t); }
        if (inScene(t, 'accept')) { sceneAccept(t); }
        if (inScene(t, 'master')) { sceneMaster(t); }
        if (inScene(t, 'parts')) { sceneParts(t); }
        if (inScene(t, 'chat')) { sceneChat(t); }
        if (inScene(t, 'theme')) { sceneTheme(t); }
        if (inScene(t, 'assembly')) { sceneAssembly(t); }
        if (t >= S.poster[0] - 0.4) { scenePoster(t); }
    }

    // Часы ролика регистрируются в index.html (window.__timelines.main) и вызывают draw(t) на каждом кадре
    window.LFT_DRAW = draw;
    draw(0);
}());
