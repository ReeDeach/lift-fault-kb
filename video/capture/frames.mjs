// Лист ключевых кадров ролика: открывает promo/index.html и рисует draw(t) для заданных моментов.
//   node capture/frames.mjs 0,3,6,10 out.png
import { spawn } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = dirname(fileURLToPath(import.meta.url));
const times = (process.argv[2] || '0,2.5,5.5,9,11,14,18,22,26,30,31.5,33.5').split(',').map(Number);
const out = process.argv[3] || join(ROOT, 'frames.png');
const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const PORT = 9335;
const profile = join(ROOT, '.profile-frames');
rmSync(profile, { recursive: true, force: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const edge = spawn(EDGE, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--allow-file-access-from-files', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
let targets;
for (let i = 0; i < 50; i++) { try { targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json(); if (targets.length) break; } catch { } await sleep(200); }
const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let seq = 0; const pending = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } });
const send = (method, params = {}) => new Promise((res, rej) => { const id = ++seq; pending.set(id, (m) => (m.error ? rej(new Error(m.error.message)) : res(m.result))); ws.send(JSON.stringify({ id, method, params })); });
const js = async (e) => { const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 400)); return r.result.value; };

await send('Emulation.setDeviceMetricsOverride', { width: 1080, height: 1920, deviceScaleFactor: 1, mobile: false });
await send('Page.navigate', { url: pathToFileURL(join(ROOT, '..', process.env.COMP || 'promo', 'index.html')).href });
await sleep(2500);
await js('document.fonts.ready.then(() => 1)');
// Лист: кадры по 4 в ряд, каждый 270×480, подпись — время
const cols = 4, w = 270, h = 480;
const rows = Math.ceil(times.length / cols);
const parts = [];
for (const t of times) {
    await js(`window.LFT_DRAW(${t}); 1`);
    parts.push(await js(`document.getElementById('stage').toDataURL('image/png')`));
}
const sheet = await js(`(async () => {
    const urls = ${JSON.stringify(parts)}, times = ${JSON.stringify(times)};
    const c = document.createElement('canvas'); c.width = ${cols * (w + 6)}; c.height = ${rows * (h + 30)};
    const x = c.getContext('2d'); x.fillStyle = '#555'; x.fillRect(0, 0, c.width, c.height);
    for (let i = 0; i < urls.length; i++) {
        const im = new Image(); im.src = urls[i]; await im.decode();
        const cx = (i % ${cols}) * ${w + 6}, cy = Math.floor(i / ${cols}) * ${h + 30};
        x.drawImage(im, cx, cy + 26, ${w}, ${h}); x.fillStyle = '#fff'; x.font = '18px monospace'; x.fillText(times[i] + ' с', cx + 4, cy + 18);
    }
    return c.toDataURL('image/png');
})()`);
writeFileSync(out, Buffer.from(sheet.split(',')[1], 'base64'));
console.log('ok', out);
ws.close(); edge.kill(); process.exit(0);
