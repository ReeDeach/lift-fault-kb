"""Звук примера (14 с) синтезом: мягкий пэд, щелчки расшифровки, ноты узлов, «бегущая» точка,
шорох маски, сигнал уведомления, клик, финальный аккорд. Время — из cues.json, как у картинки.

    python audio/synth.py → assets/sound-raw.wav (громкость выравнивает ffmpeg loudnorm)
"""
import json
import wave
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
CUES = json.loads((ROOT / 'cues.json').read_text(encoding='utf-8'))
H = CUES['hits']
SR = 48000
DUR = CUES['duration']
N = int(SR * DUR)
out = np.zeros((N, 2))
rnd = np.random.default_rng(14)


def hz(n):
    return 440.0 * 2 ** ((n - 69) / 12)


def tone(f, length, kind='sine', duty=0.25, vol=0.2, attack=0.005, release=0.08, slide=0.0):
    n = int(SR * length)
    t = np.arange(n) / SR
    ph = np.cumsum(f * (1 + slide * t / max(length, 1e-6))) / SR
    if kind == 'square':
        w = np.where((ph % 1) < duty, 1.0, -1.0)
    elif kind == 'tri':
        w = 2 * np.abs(2 * (ph % 1) - 1) - 1
    else:
        w = np.sin(2 * np.pi * ph)
    env = np.ones(n)
    a, r = max(1, int(SR * attack)), max(1, min(n, int(SR * release)))
    env[:a] = np.linspace(0, 1, a)
    env[-r:] *= np.linspace(1, 0, r)
    return w * env * vol


def put(at, s, pan=0.0):
    i = int(max(0, at) * SR)
    if at < 0:
        s = s[int(-at * SR):]
    j = min(N, i + len(s))
    if j <= i:
        return
    out[i:j, 0] += s[: j - i] * (1 - max(0, pan))
    out[i:j, 1] += s[: j - i] * (1 + min(0, pan))


# Пэд: Am → F → C, мягко, весь ролик
for k, chord in enumerate([[57, 60, 64], [53, 57, 60], [48, 55, 64], [55, 59, 62]]):
    for n in chord:
        put(k * 3.5, tone(hz(n), 4.2, 'tri', vol=0.045, attack=0.8, release=1.4), pan=0.25 if n % 2 else -0.25)
# Пульс баса после появления схемы
for k in range(int((H['poster'][0] - H['nodes'][0]) / 0.5)):
    at = H['nodes'][0] + k * 0.5
    put(at, tone(hz(45 if at < 7 else 48), 0.45, 'tri', vol=0.14, release=0.1))

# Расшифровка: частые тихие щелчки
for at in np.arange(H['decode'][0], H['decode'][1], 0.045):
    put(float(at), tone(hz(90 + int(rnd.integers(0, 10))), 0.02, 'square', duty=0.5, vol=0.035, release=0.015), pan=float(rnd.uniform(-0.5, 0.5)))
put(H['decode'][1], tone(hz(76), 0.25, 'square', vol=0.06, release=0.2))
put(H['titleUp'][0], tone(500, 0.6, 'sine', vol=0.05, slide=0.6))  # уезжает вверх
for k, at in enumerate(H['nodes']):
    put(at, tone(hz(69 + [0, 3, 7, 10][k]), 0.18, 'square', duty=0.25, vol=0.07, release=0.12))
# Точка бежит: скользящая нота
put(H['dot'][0], tone(hz(81), H['dot'][1] - H['dot'][0], 'sine', vol=0.03, attack=0.3, release=0.5, slide=-0.3))
for at in np.arange(H['term'][0] + 0.3, H['term'][1], 0.06):
    put(float(at), tone(1500, 0.012, 'square', duty=0.5, vol=0.03, release=0.01))
# Маска раскрывает экран: шорох
n = int(SR * 1.0)
s = np.diff(rnd.uniform(-1, 1, n), prepend=0) * np.sin(np.linspace(0, np.pi, n)) * 0.07
put(H['maskGrow'][0], s)
# Уведомление
for k, nn in enumerate([76, 79, 84]):
    put(H['push'] + k * 0.1, tone(hz(nn), 0.35, 'sine', vol=0.16, release=0.3))
put(H['zoom'][0], tone(220, 0.8, 'sine', vol=0.05, slide=0.8, attack=0.2))
# Касание и превращение
put(H['tap'], tone(1800, 0.025, 'square', duty=0.5, vol=0.16, release=0.02))
put(H['tap'] + 0.01, tone(900, 0.03, 'square', duty=0.5, vol=0.1, release=0.02))
put(H['morph'][0], tone(hz(72), 0.3, 'sine', vol=0.08, slide=0.5))
for k, nn in enumerate([72, 76, 79]):
    put(H['toMaster'][1] - 0.2 + k * 0.1, tone(hz(nn), 0.35, 'sine', vol=0.14, release=0.3))
# Финал
for nn in [48, 60, 64, 67, 72]:
    put(H['poster'][0], tone(hz(nn), DUR - H['poster'][0], 'tri', vol=0.07, attack=0.02, release=1.4))

out = np.tanh(out * 1.3)
f = int(SR * 0.4)
out[-f:] *= np.linspace(1, 0, f)[:, None]
pcm = (np.clip(out, -1, 1) * 32767).astype('<i2')
dst = ROOT / 'assets' / 'sound-raw.wav'
with wave.open(str(dst), 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes(pcm.tobytes())
print('ok', dst)
