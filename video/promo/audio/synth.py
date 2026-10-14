"""Звук ролика синтезом: 8-битная музыка и эффекты точно в кадр. Время событий — из cues.json,
те же константы, что у картинки. Чужих треков нет.

    python audio/synth.py   → assets/sound-raw.wav (громкость выравнивает ffmpeg loudnorm)

До поворота (заявки 1С, ~30 с) музыка тихая и холодная: пэд и бас. После — ярче: арпеджио,
ударные, мелодия. Ночь (тёмная тема) — без ударных. Финал — аккорд и затухание.
"""
import json
import wave
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
CUES = json.loads((ROOT / 'cues.json').read_text(encoding='utf-8'))
HIT = CUES['hits']
SC = CUES['scenes']
SR = 48000
DUR = CUES['duration']
N = int(SR * DUR)
out = np.zeros((N, 2), dtype=np.float64)
rnd = np.random.default_rng(42)  # зерно: каждый раз тот же звук

BPM = 120
BEAT = 60 / BPM
TURN = HIT['push1']


def hz(note):
    """Нота MIDI → частота."""
    return 440.0 * 2 ** ((note - 69) / 12)


def tone(freq, length, kind='square', duty=0.25, vol=0.2, attack=0.004, release=0.06, slide=0.0):
    n = int(SR * length)
    t = np.arange(n) / SR
    f = freq * (1 + slide * t / max(length, 1e-6))
    phase = np.cumsum(f) / SR
    if kind == 'square':
        w = np.where((phase % 1) < duty, 1.0, -1.0)
    elif kind == 'tri':
        w = 2 * np.abs(2 * (phase % 1) - 1) - 1
    elif kind == 'sine':
        w = np.sin(2 * np.pi * phase)
    else:
        w = rnd.uniform(-1, 1, n)
    env = np.ones(n)
    a = max(1, int(SR * attack))
    r = max(1, min(n, int(SR * release)))
    env[:a] = np.linspace(0, 1, a)
    env[-r:] *= np.linspace(1, 0, r)
    return w * env * vol


def put(at, sig, pan=0.0):
    i = int(at * SR)
    if i >= N or i + len(sig) <= 0:
        return
    j = min(N, i + len(sig))
    s = sig[: j - i]
    out[i:j, 0] += s * (1 - max(0, pan))
    out[i:j, 1] += s * (1 + min(0, pan))


def noise_burst(at, length, vol, hp=True):
    s = rnd.uniform(-1, 1, int(SR * length))
    if hp:
        s = np.diff(s, prepend=0)  # высокие частоты — «хэт»
    env = np.exp(-np.linspace(0, 6, len(s)))
    put(at, s * env * vol)


def kick(at, vol=0.55):
    n = int(SR * 0.18)
    t = np.arange(n) / SR
    f = 120 * np.exp(-t * 28) + 42
    s = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 18)
    put(at, s * vol)


# ---------- музыка ----------
# Аккорды по 4 с: Am – F – C – G (до поворота), после поворота ярче: C – G – Am – F
COLD = [(57, [57, 60, 64]), (53, [53, 57, 60]), (48, [52, 55, 60]), (55, [55, 59, 62])]
WARM = [(48, [60, 64, 67]), (55, [59, 62, 67]), (57, [60, 64, 69]), (53, [57, 60, 65])]
night = SC['theme']
end_music = SC['poster'][0]

t = 0.0
bar = 0
while t < end_music:
    warm = t >= TURN - 0.5
    root, chord = (WARM if warm else COLD)[bar % 4]
    # Бас: четверти
    for k in range(8):
        bt = t + k * BEAT
        if bt >= end_music:
            break
        put(bt, tone(hz(root - 12 + (12 if k % 2 else 0)), BEAT * 0.9, 'tri', vol=0.20, release=0.08))
    # Пэд: весь такт, тише после поворота
    for nte in chord:
        put(t, tone(hz(nte), 4.0, 'tri', vol=0.035 if warm else 0.05, attack=0.6, release=1.2), pan=0.2 if nte % 2 else -0.2)
    # Арпеджио: с кодов, восьмые
    if t >= SC['codes'][0]:
        for k in range(16):
            at = t + k * BEAT / 2
            if at >= end_music:
                break
            in_night = night[0] <= at < night[1]
            nte = chord[k % len(chord)] + (12 if warm and k % 4 == 3 else 0)
            put(at, tone(hz(nte + 12), BEAT * 0.4, 'square', duty=0.125, vol=0.045 if warm and not in_night else 0.03, release=0.05), pan=0.3 if k % 2 else -0.3)
    # Ударные: после поворота, кроме «ночи»
    if warm:
        for k in range(8):
            at = t + k * BEAT
            if at >= end_music or night[0] <= at < night[1]:
                continue
            if k % 2 == 0:
                kick(at)
            else:
                noise_burst(at, 0.12, 0.12)  # малый — шум
            noise_burst(at + BEAT / 2, 0.04, 0.05)  # хэт
    # Мелодия после поворота: короткие фразы
    if warm and not (night[0] <= t < night[1]):
        phrase = [0, 2, 4, 7, 4, 2] if bar % 2 == 0 else [7, 9, 7, 4, 2, 0]
        base = chord[0] + 12
        for k, step in enumerate(phrase):
            at = t + 1.0 + k * BEAT / 2
            if at < end_music:
                put(at, tone(hz(base + step), BEAT * 0.45, 'square', duty=0.25, vol=0.05, release=0.06))
    t += 4.0
    bar += 1

# Финал: аккорд C мажор, долгое затухание
for nte in [48, 60, 64, 67, 72]:
    put(end_music, tone(hz(nte), DUR - end_music, 'tri', vol=0.08, attack=0.01, release=2.6))
put(end_music, tone(hz(84), 0.5, 'square', duty=0.25, vol=0.05, release=0.4))

# ---------- эффекты ----------
def click(at, vol=0.18):
    put(at, tone(1800, 0.025, 'square', duty=0.5, vol=vol, release=0.02))
    put(at + 0.01, tone(900, 0.03, 'square', duty=0.5, vol=vol * 0.6, release=0.02))


def ping(at, base=79, vol=0.12):
    put(at, tone(hz(base), 0.12, 'square', duty=0.25, vol=vol))
    put(at + 0.08, tone(hz(base + 7), 0.2, 'square', duty=0.25, vol=vol))


def chime(at, notes, vol=0.16, gap=0.11):
    for k, nte in enumerate(notes):
        put(at + k * gap, tone(hz(nte), 0.35, 'sine', vol=vol, release=0.3))
        put(at + k * gap, tone(hz(nte + 12), 0.2, 'square', duty=0.25, vol=vol * 0.25, release=0.15))


# Сборка знака: пиксели «щёлкают» на подлёте
r2 = np.random.default_rng(7)
for k in range(28):
    at = 0.05 + k * 0.11
    put(at, tone(hz(84 + int(r2.integers(0, 12))), 0.03, 'square', duty=0.5, vol=0.05, release=0.02), pan=float(r2.uniform(-0.6, 0.6)))
chime(HIT['logoDone'], [72, 76, 79], vol=0.10)
for at in HIT['cardsIn']:
    put(at, tone(110, 0.12, 'square', duty=0.5, vol=0.12, release=0.1, slide=-0.4))
# Карточки рассыпаются: шумовой спад
n = int(SR * 1.3)
s = rnd.uniform(-1, 1, n) * np.linspace(1, 0, n) ** 2
put(HIT['cardsBreak'], np.diff(s, prepend=0) * 0.18)
# Набор текста
for a, b in (HIT['codeType'], HIT['aiType']):
    for at in np.arange(a, b, 0.075 if b - a > 1 else 0.12):
        click(float(at), 0.07)
for key in ['codeTap', 'aiTap', 'acceptTap', 'closeTap', 'submitTap', 'stoppedTap', 'sendTap', 'themeTap', 'themeBack']:
    click(HIT[key])
ping(HIT['codeOpen'])
ping(HIT['aiAnswer'], 76)
put(HIT['mailIn'], tone(hz(67), 0.25, 'tri', vol=0.12, slide=0.3))
for k, at in enumerate(HIT['rowsFly']):
    put(at, tone(hz(72 + k * 2), 0.08, 'square', duty=0.25, vol=0.08, slide=0.5))
# Поворот: уведомление — главный удар
kick(HIT['push1'], 0.7)
chime(HIT['push1'], [76, 79, 84], vol=0.2)
chime(HIT['closedPush'], [72, 76, 79], vol=0.16)
chime(HIT['readyPush'], [74, 78, 81], vol=0.16)
for k in range(2):
    put(HIT['urgentPush'] + k * 0.18, tone(hz(81), 0.12, 'square', duty=0.5, vol=0.12))
for k, at in enumerate(HIT['partSteps']):
    put(at, tone(hz(67 + k * 4), 0.16, 'square', duty=0.25, vol=0.1))
put(HIT['heartPop'], tone(hz(84), 0.09, 'sine', vol=0.16, slide=0.6))
put(HIT['badgePop'], tone(hz(88), 0.06, 'square', duty=0.5, vol=0.08))
put(HIT['themeTap'] + 0.15, tone(400, 0.6, 'sine', vol=0.08, slide=-0.6))  # «ночь» опускается
put(HIT['themeBack'] + 0.1, tone(300, 0.5, 'sine', vol=0.08, slide=0.8))
for k, at in enumerate(HIT['iconsFly']):
    put(at, tone(hz(72 + [0, 2, 4, 7, 9, 12][k]), 0.1, 'square', duty=0.25, vol=0.08))

# ---------- запись ----------
out = np.tanh(out * 1.2)  # мягкое ограничение
fade = int(SR * 0.6)
out[-fade:] *= np.linspace(1, 0, fade)[:, None]
pcm = (np.clip(out, -1, 1) * 32767).astype('<i2')
dst = ROOT / 'assets' / 'sound-raw.wav'
with wave.open(str(dst), 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes(pcm.tobytes())
print('ok', dst, round(len(pcm) / SR, 2), 'с')
