// Снимки экранов демо для ролика (светлая тема, 390×844 @2x) и положения элементов на них.
// Демо-данные вымышленные. Действия (принять, закрыть заявку, статусы детали) — через API демо.
//   node capture/capture.mjs        → promo/assets/screens/*.png и screens.json
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(fileURLToPath(import.meta.url));
const OUT = join(ROOT, '..', 'promo', 'assets', 'screens');
const BASE = 'http://localhost:8081/';
const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const PORT = 9334;
const DPR = 2;
// Номера демо-механиков меняются при сбросе демо — находим по фамилии
const mechList = await (await fetch(BASE + 'api/mechanics', { headers: { 'X-Demo-User': 'dvideo-probe' } })).json();
const idOf = (name) => mechList.items.find((m) => m.name.startsWith(name)).id;
const PETROV = idOf('Петров');
const SIDORENKO = idOf('Сидоренко');
const KOVAL = idOf('Ковальчук');
mkdirSync(OUT, { recursive: true });
const profile = join(ROOT, '.profile');
rmSync(profile, { recursive: true, force: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------- API демо (как браузер с заголовками демо-входа) ----------
async function apiCall(method, path, body, user = 'dvideo-mech', mechanic = PETROV) {
    const headers = { 'X-Demo-User': user, 'Content-Type': 'application/json' };
    if (mechanic) headers['X-Demo-Mechanic'] = String(mechanic);
    const res = await fetch(BASE.replace(/\/$/, '') + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
    const text = await res.text();
    return { status: res.status, data: text ? JSON.parse(text) : null };
}

// ---------- браузер ----------
const edge = spawn(EDGE, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--hide-scrollbars', '--no-first-run', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });
let targets;
for (let i = 0; i < 50; i++) {
    try { targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json(); if (targets.length) break; } catch { /* стартует */ }
    await sleep(200);
}
const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let seq = 0;
const pending = new Map();
ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, (m) => (m.error ? reject(new Error(method + ': ' + m.error.message)) : resolve(m.result)));
    ws.send(JSON.stringify({ id, method, params }));
});
const js = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error('JS: ' + JSON.stringify(r.exceptionDetails).slice(0, 300));
    return r.result.value;
};

await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: DPR, mobile: true });
await send('Page.navigate', { url: BASE });
await sleep(1500);

const meta = {};
let n = 0;

async function open(hash, { role = 'mechanic', theme = 'light', mechanic = PETROV, user = 'dvideo-mech', wait = 2200, keep = false } = {}) {
    await js(`${keep ? '' : 'localStorage.clear();'} localStorage.setItem('lft:demo-user','${user}'); localStorage.setItem('lft:demo-mechanic','${mechanic}');
        localStorage.setItem('lft:role','${role}'); localStorage.setItem('lft:theme','${theme}'); 1`);
    await send('Page.navigate', { url: BASE + '?v=' + (++n) + hash });
    await sleep(wait);
    // Окно «Справка» снизу на экране кодов — для ролика прячем
    await js(`(() => { document.querySelectorAll('.lft-window').forEach(w => { if (w.textContent.trim().toLowerCase().startsWith('справка')) w.style.display = 'none'; }); return 1; })()`);
    await sleep(300);
}

// Снимок и положения элементов (в пикселях снимка)
async function snap(name, selectors = {}) {
    await js('document.activeElement && document.activeElement.blur(); 1');
    await sleep(200);
    const rects = await js(`(() => { const out = {}; const sel = ${JSON.stringify(selectors)};
        for (const [k, s] of Object.entries(sel)) { const e = document.querySelector(s); if (!e) { out[k] = null; continue; }
            const r = e.getBoundingClientRect(); out[k] = [r.left, r.top, r.width, r.height].map(v => Math.round(v * ${DPR})); }
        return out; })()`);
    const { data } = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(join(OUT, name + '.png'), Buffer.from(data, 'base64'));
    meta[name] = { w: 390 * DPR, h: 844 * DPR, rects };
    console.log('ok', name, JSON.stringify(rects));
}

const scrollTo = (sel, offset = 12) => js(`(() => { const e=document.querySelector('${sel}'); if (e) window.scrollTo(0, e.getBoundingClientRect().top + scrollY - ${offset}); return 1; })()`);

// ---------- 1. Коды ----------
await open('#/codes');
await snap('codes-empty', { input: '#code-input', search: '.app-find' });
await open('#/codes?code=44');
await js('window.scrollTo(0,0); 1');
await snap('codes-44', { input: '#code-input', theme: '#app-theme' });
await open('#/codes?code=44', { theme: 'dark' });
await js('window.scrollTo(0,0); 1');
await snap('codes-44-dark', { theme: '#app-theme' });

// ---------- 2. ИИ-диагностика (настоящий запрос к ИИ демо; SKIP_AI=1 — оставить прошлые снимки) ----------
if (!process.env.SKIP_AI) {
await open('#/ai');
await snap('ai-empty', { text: '#ai-text', code: '#ai-code', send: '#ai-send' });
await js(`(() => { const t=document.getElementById('ai-text'); t.value='Лифт стоит на 5 этаже, двери не закрываются до конца'; t.dispatchEvent(new Event('input',{bubbles:true}));
    const c=document.getElementById('ai-code'); if (c) { c.value='44 C6'; c.dispatchEvent(new Event('input',{bubbles:true})); }
    document.getElementById('ai-send').click(); return 1; })()`);
// Ждём, пока уйдёт «Ищем в базе, спрашиваем ИИ»
await sleep(2000);
for (let i = 0; i < 70; i++) {
    if (!(await js(`!!document.querySelector('.app-ai-wait')`))) break;
    await sleep(1000);
}
await sleep(800);
await js('window.scrollTo(0, document.body.scrollHeight); 1');
await snap('ai-answer', {});
await js('window.scrollTo(0, 0); 1');
await snap('ai-answer-top', {});
}

// ---------- 3. Заявки 1С и заявка от мастера ----------
// Свежая заявка мастера Петрову: «Лифт стоит»
const lifts = (await apiCall('GET', '/api/lifts')).data.items;
const lesnaya = lifts.find((p) => p.mechanic_id === PETROV && /Лесная 7/.test(p.address));
const liftId = lesnaya.lifts[0].id;
const created = await apiCall('POST', '/api/repairs', { lift_id: liftId, info: 'Лифт стоит на 3 этаже, двери не открываются', codes: '44 C6', stopped: true, as: 'master' }, 'dvideo-master', null);
const callId = created.data.item.id;

await open('#/repairs', { role: 'master', user: 'dvideo-master', mechanic: '' });
await js(`document.getElementById('rep-new').click(); 1`);
await sleep(1500);
await js(`(() => { const s=document.getElementById('rep-new-lift'); s.value='${liftId}'; s.dispatchEvent(new Event('change',{bubbles:true}));
    const f=document.getElementById('rep-new-form'); f.querySelector('[name=info]').value='Лифт стоит на 3 этаже, двери не открываются';
    f.querySelector('[name=codes]').value='44 C6'; document.getElementById('rep-new-stopped').click(); return 1; })()`);
await sleep(400);
await snap('master-new-call', { submit: '#rep-new-submit', stopped: '#rep-new-stopped' });

await open('#/repairs');
await scrollTo('.app-req-switch');
await snap('repairs-list', { first: '.app-rep-manual .app-rep', accept: '.app-rep-manual [data-repair-accept]', close: '.app-rep-manual [data-repair-close]', tabs: '.app-tabs' });

await apiCall('POST', `/api/repairs/${callId}/accept`, {});
await open('#/repairs');
await scrollTo('.app-req-switch');
await snap('repairs-accepted', { first: '.app-rep-manual .app-rep', accepted: '.app-rep-manual .app-rep-accepted', close: '.app-rep-manual [data-repair-close]' });

await js(`document.querySelector('.app-rep-manual [data-repair-close]').click(); 1`);
await sleep(2200);
await js(`(() => { const w=[...document.querySelectorAll('.app-sheet textarea')][3]; if (w) { w.value='Отрегулирован замок двери шахты, проверена цепь безопасности'; } return 1; })()`);
await sleep(300);
await snap('repairs-close-sheet', { submit: '#save-submit' });

const entry = await apiCall('POST', '/api/journal', { lift_id: liftId, codes: '44 C6', title: 'Заявка мастера: код 44 C6, двери не открываются', work_done: 'Отрегулирован замок двери шахты, проверена цепь безопасности', status: 'resolved' });
await apiCall('POST', `/api/repairs/${callId}/journal`, { entry_id: entry.data.item.id });
await open('#/repairs');
await scrollTo('.app-req-switch');
await snap('repairs-after', { list: '.app-rep-list' });

// ---------- 4. Запчасти: одна заявка по статусам ----------
const req = await apiCall('POST', '/api/requests', { title: 'Ролик двери кабины', quantity: '2 шт', urgent: false, lift_id: liftId, body: 'Ролик стёрся, дверь идёт с ударом', photos: [] });
const reqId = req.data.item.id;
await open(`#/requests?id=${reqId}`);
await snap('part-new', { steps: '.app-steps' });
const steps = [['accepted', 'Принял, уточняю у поставщика'], ['ordered', 'Заказал, будет в четверг'], ['ready', 'Пришли, лежат в мастерской']];
for (const [status, body] of steps) {
    await apiCall('POST', `/api/requests/${reqId}/messages`, { status, body, as: 'master' }, 'dvideo-master', null);
    await open(`#/requests?id=${reqId}`);
    await js('window.scrollTo(0, document.body.scrollHeight); 1');
    await snap('part-' + status, { steps: '.app-steps' });
}

// ---------- 5. Чат: кто в сети, лайки, непрочитанные ----------
for (const [u, m] of [['dvideo-sid', SIDORENKO], ['dvideo-kov', KOVAL]]) {
    await apiCall('GET', '/api/updates', null, u, m);
}
const chat = (await apiCall('GET', '/api/chat')).data.items;
const target = chat[chat.length - 1];
for (const [u, m] of [['dvideo-sid', SIDORENKO], ['dvideo-kov', KOVAL]]) {
    await apiCall('POST', `/api/chat/${target.id}/like`, {}, u, m);
}
await open('#/chat', { wait: 3000 });
await js('window.scrollTo(0, document.body.scrollHeight); 1');
await snap('chat', { online: '#chat-online', like: `[data-like="${target.id}"]` });
await apiCall('POST', '/api/chat', { body: 'Кто сегодня на Садовой? Нужна помощь с тросом' }, 'dvideo-sid', SIDORENKO);
await apiCall('POST', '/api/chat', { body: 'Подъеду после обеда' }, 'dvideo-kov', KOVAL);
await apiCall('GET', '/api/updates', null, 'dvideo-sid', SIDORENKO);
// Память браузера не чистим: приложение помнит, до какого сообщения чат прочитан
await open('#/codes', { wait: 6000, keep: true });
await snap('tabs-unread', { tabs: '.app-tabs', chat: '.app-tabs [data-page=chat]' });

const metaFile = join(OUT, '..', 'screens.json');
let old = {};
try { old = JSON.parse((await import('node:fs')).readFileSync(metaFile, 'utf-8')); } catch { /* первый раз */ }
writeFileSync(metaFile, JSON.stringify({ ...old, ...meta }, null, 1));
ws.close();
edge.kill();
process.exit(0);
