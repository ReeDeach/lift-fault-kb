// Пример новой подачи (14 с, 1080×1920, плавно): «Заявки из 1С».
// Приёмы из образцов в терминальном стиле: расшифровка текста, схема-конвейер с бегущей точкой,
// раскрытие экрана круглой маской, элемент интерфейса крупно с размытием фона, перетекание
// одного объекта в другой. Всё от времени t; случайность — только с зерном.
/* global CUES */
(function () {
    'use strict';

    var W = 1080, H = 1920;
    var C = {
        ground: '#f4f1f0', surface: '#ffffff', raised: '#eadcda', faint: '#dcd2d0', line: '#9aa8b5',
        ink: '#262b30', muted: '#5e6873', steel: '#4f6578', rose: '#a8646f', ok: '#2f7d68',
        // Терминальная врезка — цвета тёмной темы приложения
        tBg: '#171412', tInk: '#f2ece4', tMuted: '#9a918a', tAccent: '#ff5a1f', tOk: '#7fe0c4', tSignal: '#ff2e88'
    };
    var F = {
        display: '800 {s}px Unbounded, "Arial Black", sans-serif',
        sans: '400 {s}px "Golos Text", system-ui, sans-serif',
        sansB: '600 {s}px "Golos Text", system-ui, sans-serif',
        mono: '500 {s}px "IBM Plex Mono", monospace'
    };
    function font(k, s) { return F[k].replace('{s}', s); }
    var HIT = CUES.hits, SCREENS = CUES.screens;
    var ctx = document.getElementById('stage').getContext('2d');
    function img(id) { return document.getElementById(id); }

    // ---------- помощники ----------
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function prog(t, a, b) { return clamp((t - a) / (b - a), 0, 1); }
    function eOut(p) { return 1 - Math.pow(1 - p, 4); }
    function eInOut(p) { return p < 0.5 ? 8 * p * p * p * p : 1 - Math.pow(-2 * p + 2, 4) / 2; }
    function eBack(p) { var c = 1.5; return 1 + (c + 1) * Math.pow(p - 1, 3) + c * Math.pow(p - 1, 2); }
    function lerp(a, b, p) { return a + (b - a) * p; }
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
    var cache = {};
    function tinted(id, color, w, h) {
        var key = id + color + w + 'x' + h;
        if (!cache[key]) {
            var c = document.createElement('canvas'); c.width = w; c.height = h;
            var x = c.getContext('2d'); x.imageSmoothingEnabled = false;
            x.drawImage(img(id), 0, 0, w, h);
            x.globalCompositeOperation = 'source-in'; x.fillStyle = color; x.fillRect(0, 0, w, h);
            cache[key] = c;
        }
        return cache[key];
    }
    function shadow(blur, oy, a) { ctx.shadowColor = 'rgba(38,43,48,' + a + ')'; ctx.shadowBlur = blur; ctx.shadowOffsetY = oy; }
    function noShadow() { ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0; ctx.shadowOffsetY = 0; }

    // ---------- расшифровка текста: буквы перебирают символы и встают на место ----------
    var GLYPHS = '█▓▒░#%&$@0123456789ЖЗКЛМНПРСТФЦШЭЮЯ';
    function decode(t, a, b, text, x, y, kind, size, color, align) {
        if (t < a) { return 0; }
        ctx.font = font(kind, size); ctx.fillStyle = color; ctx.textBaseline = 'alphabetic';
        var widths = text.split('').map(function (ch) { return ctx.measureText(ch).width; });
        var total = widths.reduce(function (s, w) { return s + w; }, 0) + (text.length - 1) * (kind === 'display' ? 0 : 0);
        var cx = align === 'center' ? x - total / 2 : x;
        var n = text.length, done = true;
        for (var i = 0; i < n; i++) {
            var ch = text[i];
            var appear = a + (b - a) * 0.5 * (i / n);
            var settle = a + (b - a) * (0.35 + 0.65 * (i / n));
            if (t >= appear && ch !== ' ') {
                var g = ch;
                if (t < settle) {
                    var r = rng(i * 7919 + Math.floor(t * 30));
                    g = GLYPHS[Math.floor(r() * GLYPHS.length)];
                    ctx.save(); ctx.globalAlpha *= 0.55; ctx.fillStyle = C.rose;
                    var gw = ctx.measureText(g).width;
                    ctx.fillText(g, cx + (widths[i] - gw) / 2, y);
                    ctx.restore();
                    done = false;
                } else {
                    ctx.fillText(g, cx, y);
                }
            }
            if (t < settle) { done = false; }
            cx += widths[i];
        }
        return { done: done, right: cx };
    }
    function cursor(t, x, y, h) {
        if (Math.floor(t * 2.6) % 2 === 0) { ctx.fillStyle = C.rose; ctx.fillRect(x + 10, y - h * 0.82, h * 0.45, h * 0.9); }
    }

    // ---------- фон: светлая бумага в точку, мягкое стальное свечение ----------
    function background(t) {
        ctx.fillStyle = C.ground; ctx.fillRect(0, 0, W, H);
        var gy = 1500 - 200 * Math.sin(t * 0.35);
        var g = ctx.createRadialGradient(540, gy, 50, 540, gy, 1100);
        g.addColorStop(0, 'rgba(79,101,120,0.16)'); g.addColorStop(0.5, 'rgba(168,100,111,0.06)'); g.addColorStop(1, 'rgba(244,241,240,0)');
        ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
        ctx.fillStyle = 'rgba(79,101,120,0.10)';
        for (var y = 24; y < H; y += 40) { for (var x = 24; x < W; x += 40) { ctx.fillRect(x, y, 3, 3); } }
    }

    // ---------- заголовок и подпись ----------
    var SUBS = [
        [2.6, 6.2, 'Отчёт приходит мастеру на почту в 08:00'],
        [6.4, 8.8, 'и сам находит механика по номеру лифта'],
        [9.0, 12.0, 'Принял — мастер сразу видит кто']
    ];
    function titles(t) {
        // «Заявки из 1С»: расшифровка по центру, потом плавно уезжает в заголовок
        var up = eInOut(prog(t, HIT.titleUp[0], HIT.titleUp[1]));
        var out = prog(t, HIT.poster[0] - 0.4, HIT.poster[0]);
        if (out >= 1) { return; }
        ctx.save();
        ctx.globalAlpha = 1 - out;
        var y = lerp(900, 230, up), s = lerp(1, 0.72, up);
        ctx.translate(540, y); ctx.scale(s, s);
        var r = decode(t, HIT.decode[0], HIT.decode[1], 'ЗАЯВКИ ИЗ 1С', 0, 0, 'display', 96, C.ink, 'center');
        if (r && t < HIT.titleUp[1]) { cursor(t, r.right, 0, 96); }
        ctx.restore();
        if (up < 1) {
            ctx.save(); ctx.globalAlpha = prog(t, 1.2, 1.6) * (1 - up);
            ctx.font = font('mono', 30); ctx.fillStyle = C.steel; ctx.textAlign = 'center';
            ctx.fillText('LIFT FAULT TERMINAL · ЗАЯВКИ НА РЕМОНТ', 540, 1010);
            ctx.restore(); ctx.textAlign = 'left';
        }
        SUBS.forEach(function (sb) {
            if (t < sb[0] || t > sb[1]) { return; }
            ctx.save();
            ctx.globalAlpha = 1 - prog(t, sb[1] - 0.3, sb[1]);
            decode(t, sb[0], sb[0] + 0.7, sb[2], 540, 312, 'sans', 40, C.muted, 'center');
            ctx.restore();
        });
    }

    // ---------- схема: почта → отчёт 1С → номер лифта → механик ----------
    var NODES = [
        ['chat', 'Почта мастера', '08:00 · отчёт 1С.xlsx'],
        ['journal', 'Отчёт 1С', '12 заявок за сутки'],
        ['code', 'Номер лифта', '3105н → Садовая 12, лифт 1'],
        ['settings', 'Механик', 'Петров А. С. · 2 заявки']
    ];
    var NY = [470, 690, 910, 1130], NX = 540, NW = 640, NH = 128;
    function dotPos(t) {
        var p = eInOut(prog(t, HIT.dot[0], HIT.dot[1]));
        return { x: NX, y: lerp(NY[0] + NH / 2, NY[3] + NH / 2, p), p: p };
    }
    function pipeline(t) {
        var fade = 1 - prog(t, HIT.maskGrow[0], HIT.maskGrow[0] + 0.5);
        if (fade <= 0 || t < HIT.nodes[0]) { return; }
        ctx.save(); ctx.globalAlpha = fade;
        // Пунктир между узлами, бегущий
        var top = NY[0] + NH / 2, bottom = NY[3] + NH / 2;
        var lineP = eOut(prog(t, HIT.nodes[0], HIT.nodes[3] + 0.3));
        ctx.strokeStyle = C.line; ctx.lineWidth = 4; ctx.setLineDash([14, 14]); ctx.lineDashOffset = -t * 60;
        ctx.beginPath(); ctx.moveTo(NX, top); ctx.lineTo(NX, lerp(top, bottom, lineP)); ctx.stroke();
        ctx.setLineDash([]);
        var d = dotPos(t);
        NODES.forEach(function (n, i) {
            var a = HIT.nodes[i], p = eBack(prog(t, a, a + 0.5));
            if (t < a) { return; }
            var active = d.y >= NY[i] + NH / 2 - 4;
            var x = NX - NW / 2, y = NY[i] + (1 - clamp(p, 0, 1)) * 30;
            ctx.save();
            ctx.globalAlpha *= clamp(p, 0, 1);
            ctx.translate(NX, y + NH / 2); ctx.scale(lerp(0.92, 1, clamp(p, 0, 1)), lerp(0.92, 1, clamp(p, 0, 1))); ctx.translate(-NX, -(y + NH / 2));
            shadow(30, 10, 0.12);
            ctx.fillStyle = C.surface; rrect(x, y, NW, NH, 8); ctx.fill();
            noShadow();
            ctx.strokeStyle = active ? C.rose : C.faint; ctx.lineWidth = active ? 4 : 2; ctx.stroke();
            ctx.drawImage(tinted('ic-' + n[0], active ? C.rose : C.steel, 60, 60), x + 34, y + 34);
            ctx.font = font('display', 34); ctx.fillStyle = C.ink; ctx.fillText(n[1].toUpperCase(), x + 120, y + 58);
            ctx.font = font('sans', 28); ctx.fillStyle = C.muted; ctx.fillText(n[2], x + 120, y + 98);
            ctx.restore();
        });
        // Бегущая точка со свечением
        if (t >= HIT.dot[0]) {
            var gl = ctx.createRadialGradient(d.x, d.y, 2, d.x, d.y, 70);
            gl.addColorStop(0, 'rgba(168,100,111,0.55)'); gl.addColorStop(1, 'rgba(168,100,111,0)');
            ctx.fillStyle = gl; ctx.beginPath(); ctx.arc(d.x, d.y, 70, 0, Math.PI * 2); ctx.fill();
            ctx.fillStyle = C.rose; ctx.beginPath(); ctx.arc(d.x, d.y, 14, 0, Math.PI * 2); ctx.fill();
        }
        ctx.restore();
    }

    // ---------- терминальная врезка с перекрестьями ----------
    var TERM = [
        ['$ ', 'POST /api/repairs/import', C.tInk],
        ['> ', 'отчёт 1С: 12 строк, 13.10 → 14.10', C.tMuted],
        ['> ', 'лифт найден по номеру: 11 из 12', C.tOk],
        ['> ', 'Петров А. С. — 2 заявки · push', C.tSignal]
    ];
    function terminal(t) {
        var a = HIT.term[0], b = HIT.term[1];
        var fade = 1 - prog(t, HIT.maskGrow[0], HIT.maskGrow[0] + 0.4);
        if (t < a || fade <= 0) { return; }
        var p = eOut(prog(t, a, a + 0.5));
        var x = 110, y = 1340 + (1 - p) * 40, w = 860, h = 250;
        ctx.save(); ctx.globalAlpha = p * fade;
        shadow(40, 16, 0.18);
        ctx.fillStyle = C.tBg; rrect(x, y, w, h, 6); ctx.fill(); noShadow();
        // Перекрестья по углам
        ctx.strokeStyle = C.steel; ctx.lineWidth = 3;
        [[x - 22, y - 22], [x + w + 22, y - 22], [x - 22, y + h + 22], [x + w + 22, y + h + 22]].forEach(function (c) {
            ctx.beginPath(); ctx.moveTo(c[0] - 16, c[1]); ctx.lineTo(c[0] + 16, c[1]); ctx.moveTo(c[0], c[1] - 16); ctx.lineTo(c[0], c[1] + 16); ctx.stroke();
        });
        ctx.font = font('mono', 30);
        var per = (b - a) / TERM.length;
        TERM.forEach(function (ln, i) {
            var la = a + 0.3 + i * per * 0.9, lb = la + per * 0.7;
            if (t < la) { return; }
            var n = Math.floor(prog(t, la, lb) * ln[1].length + 1e-6);
            ctx.fillStyle = C.tAccent; ctx.fillText(ln[0], x + 36, y + 64 + i * 52);
            ctx.fillStyle = ln[2]; ctx.fillText(ln[1].slice(0, n), x + 76, y + 64 + i * 52);
            if (n < ln[1].length || (i === TERM.length - 1 && Math.floor(t * 2.6) % 2 === 0)) {
                var cw = ctx.measureText(ln[1].slice(0, n)).width;
                ctx.fillStyle = C.tAccent; ctx.fillRect(x + 80 + cw, y + 40 + i * 52, 16, 30);
            }
        });
        ctx.restore();
    }

    // ---------- телефон ----------
    var PH = { x: 270, y: 430, w: 540, h: 1169, r: 44, bez: 16 };
    var KS = PH.w / 780;
    function sp(name, key) {
        var r = SCREENS[name].rects[key];
        return { x: PH.x + (r[0] + r[2] / 2) * KS, y: PH.y + (r[1] + r[3] / 2) * KS, w: r[2] * KS, h: r[3] * KS, r: r };
    }
    function phone(t, blur) {
        // Корпус с лёгким наклоном и тенью, как «плавающий» телефон
        ctx.save();
        shadow(70, 30, 0.22);
        ctx.fillStyle = C.ink;
        rrect(PH.x - PH.bez, PH.y - PH.bez, PH.w + PH.bez * 2, PH.h + PH.bez * 2, PH.r + PH.bez); ctx.fill();
        noShadow();
        ctx.save();
        rrect(PH.x, PH.y, PH.w, PH.h, PH.r); ctx.clip();
        ctx.fillStyle = C.steel; ctx.fillRect(PH.x, PH.y, PH.w, PH.h);
        // Маска-круг из точки схемы раскрывает экран
        var m = eInOut(prog(t, HIT.maskGrow[0], HIT.maskGrow[1]));
        var d = { x: NX, y: NY[3] + NH / 2 };
        ctx.beginPath(); ctx.arc(d.x, d.y, lerp(14, 1500, m), 0, Math.PI * 2); ctx.clip();
        if (blur) { ctx.filter = 'blur(' + blur + 'px) saturate(0.8)'; }
        ctx.drawImage(img('s-repairs-list'), PH.x, PH.y, PH.w, 1688 * KS);
        ctx.filter = 'none';
        ctx.restore();
        ctx.fillStyle = C.ink; rrect(PH.x + PH.w / 2 - 52, PH.y + 10, 104, 26, 13); ctx.fill();
        ctx.restore();
    }

    // ---------- уведомление ----------
    function card(x, y, w, title, body, accent, alpha) {
        ctx.save(); ctx.globalAlpha = alpha;
        shadow(50, 18, 0.22);
        ctx.fillStyle = C.surface; rrect(x, y, w, 200, 30); ctx.fill(); noShadow();
        ctx.fillStyle = accent; rrect(x, y, 12, 200, 6); ctx.fill();
        ctx.drawImage(img('app-icon'), x + 40, y + 44, 76, 76);
        ctx.font = font('mono', 24); ctx.fillStyle = C.muted; ctx.fillText('LFT · СЕЙЧАС', x + 144, y + 64);
        ctx.font = font('sansB', 38); ctx.fillStyle = C.ink; ctx.fillText(title, x + 144, y + 114);
        ctx.font = font('sans', 30); ctx.fillStyle = C.muted; ctx.fillText(body, x + 144, y + 160);
        ctx.restore();
    }

    // ---------- сцена телефона: маска, уведомление, «Принял» крупно, перетекание ----------
    function phoneScene(t) {
        if (t < HIT.maskGrow[0] - 0.05 || t > HIT.poster[0] + 0.2) { return; }
        var out = prog(t, HIT.poster[0] - 0.4, HIT.poster[0]);
        var zoom = eInOut(prog(t, HIT.zoom[0], HIT.zoom[1]));
        var acc = sp('repairs-list', 'accept');
        var appear = eOut(prog(t, HIT.maskGrow[0], HIT.maskGrow[0] + 0.6));
        ctx.save();
        ctx.globalAlpha = (1 - out);
        // Камера: плавный наезд на кнопку «Принял»
        var s = lerp(1, 1.7, zoom);
        ctx.translate(540, lerp(1000, 980, zoom)); ctx.scale(s * lerp(0.94, 1, appear), s * lerp(0.94, 1, appear));
        ctx.translate(-lerp(540, acc.x + 120, zoom), -lerp(1015, acc.y, zoom));
        phone(t, 7 * zoom);
        ctx.restore();

        // Уведомление сверху
        var pIn = eBack(prog(t, HIT.push, HIT.push + 0.5)), pOut = eInOut(prog(t, HIT.zoom[0] - 0.2, HIT.zoom[0] + 0.3));
        if (t >= HIT.push && pOut < 1) { card(90, lerp(-240, 360, pIn) - 520 * pOut, 900, 'Заявки на ремонт: 2', 'Садовая 12, п.1 — код 49, 44 C6', C.rose, 1 - pOut); }

        // «Принял»: кнопка отрывается от экрана и висит крупно
        if (zoom > 0 && t < HIT.toMaster[1]) {
            var lift = eOut(prog(t, HIT.zoom[0] + 0.3, HIT.zoom[1] + 0.2));
            var morph = eInOut(prog(t, HIT.morph[0], HIT.morph[1]));
            var fly = eInOut(prog(t, HIT.toMaster[0], HIT.toMaster[1]));
            var ra = SCREENS['repairs-list'].rects.accept, rb = SCREENS['repairs-accepted'].rects.accepted;
            var k = 1.55, k2 = 1.25;
            var w = lerp(ra[2] * k, (rb[2] + 40) * k2, morph), h = lerp(ra[3] * k, 86 * k2, morph);
            var cx = 540, cy = lerp(1000, 360 + 100, fly);
            var press = 1 - 0.06 * Math.sin(Math.PI * prog(t, HIT.tap - 0.05, HIT.tap + 0.2));
            ctx.save();
            ctx.globalAlpha = lift * (1 - prog(fly, 0.6, 1));
            ctx.translate(cx, cy); ctx.scale(press * lerp(1, 1.15, lift), press * lerp(1, 1.15, lift));
            shadow(50, 22, 0.25);
            ctx.fillStyle = C.surface; rrect(-w / 2 - 14, -h / 2 - 14, w + 28, h + 28, 10); ctx.fill(); noShadow();
            var base = ctx.globalAlpha;
            ctx.globalAlpha = base * (1 - prog(morph, 0, 0.45));
            ctx.drawImage(img('s-repairs-list'), ra[0], ra[1], ra[2], ra[3], -ra[2] * k / 2, -ra[3] * k / 2, ra[2] * k, ra[3] * k);
            ctx.globalAlpha = base * prog(morph, 0.55, 1);
            ctx.drawImage(img('s-repairs-accepted'), rb[0], rb[1], rb[2], rb[3], -rb[2] * k2 / 2, -rb[3] * k2 / 2, rb[2] * k2, rb[3] * k2);
            ctx.restore();
            // Касание
            var tp = prog(t, HIT.tap - 0.05, HIT.tap + 0.5);
            if (tp > 0 && tp < 1) {
                ctx.save(); ctx.strokeStyle = C.rose; ctx.globalAlpha = 1 - tp; ctx.lineWidth = 8 * (1 - tp) + 2;
                ctx.beginPath(); ctx.arc(cx - 40, cy, 30 + 90 * eOut(tp), 0, Math.PI * 2); ctx.stroke(); ctx.restore();
            }
        }
        // Перетекание в уведомление мастеру
        var m2 = eOut(prog(t, HIT.toMaster[0] + 0.4, HIT.toMaster[1]));
        var gone = prog(t, HIT.poster[0] - 0.4, HIT.poster[0]);
        if (m2 > 0) { card(90, 360, 900, 'Заявку принял Петров А. С.', 'Садовая 12, п.1 · 16:27', C.ok, m2 * (1 - gone)); }
    }

    // ---------- постер ----------
    var MARK = (function () {
        var cells = [], seen = {};
        [[0, 0, 15, 1], [0, 14, 15, 1], [0, 0, 1, 15], [14, 0, 1, 15], [3, 6, 4, 7], [8, 6, 4, 7], [5, 4, 5, 1], [6, 3, 3, 1], [7, 2, 1, 1]].forEach(function (p) {
            for (var y = p[1]; y < p[1] + p[3]; y++) { for (var x = p[0]; x < p[0] + p[2]; x++) { if (!seen[x + ',' + y]) { seen[x + ',' + y] = 1; cells.push([x, y]); } } }
        });
        return cells;
    }());
    function poster(t) {
        var p = eOut(prog(t, HIT.poster[0] - 0.2, HIT.poster[1]));
        if (p <= 0) { return; }
        ctx.save(); ctx.globalAlpha = p;
        MARK.forEach(function (c) { ctx.fillStyle = C.steel; ctx.fillRect(540 + (c[0] - 7.5) * 26, 660 + (c[1] - 7.5) * 26 + (1 - p) * 30, 24, 24); });
        var wm = img('wordmark'), ww = 720, wh = Math.round(ww * wm.naturalHeight / wm.naturalWidth);
        ctx.drawImage(tinted('wordmark', C.ink, ww, wh), 540 - ww / 2, 930);
        ctx.restore();
        decode(t, HIT.poster[0], HIT.poster[1], 'ЗАЯВКИ 1С — СРАЗУ У МЕХАНИКА', 540, 1250, 'display', 46, C.ink, 'center');
        ctx.save(); ctx.globalAlpha = prog(t, HIT.poster[1] - 0.2, HIT.poster[1] + 0.3);
        ctx.font = font('mono', 28); ctx.fillStyle = C.rose; ctx.textAlign = 'center';
        ctx.fillText('ANDROID · IPHONE · КОМПЬЮТЕР', 540, 1330);
        ctx.restore(); ctx.textAlign = 'left';
    }

    function draw(t) {
        noShadow(); ctx.filter = 'none'; ctx.globalAlpha = 1;
        background(t);
        pipeline(t);
        terminal(t);
        phoneScene(t);
        var band = prog(t, HIT.zoom[0] - 0.3, HIT.zoom[0] + 0.3) * (1 - prog(t, HIT.poster[0] - 0.4, HIT.poster[0]));
        if (band > 0) {
            var g = ctx.createLinearGradient(0, 330, 0, 470);
            g.addColorStop(0, 'rgba(244,241,240,' + band + ')'); g.addColorStop(1, 'rgba(244,241,240,0)');
            ctx.fillStyle = 'rgba(244,241,240,' + band + ')'; ctx.fillRect(0, 0, W, 330);
            ctx.fillStyle = g; ctx.fillRect(0, 330, W, 140);
        }
        titles(t);
        poster(t);
    }
    window.LFT_DRAW = draw;
    draw(0);
}());
