# Презентация приложения (HyperFrames)

Ролик 80 с, 9:16, светлая тема. Всё — код: кадр рисуется функцией `draw(t)` в
`promo/main.js` на холсте, звук синтезируется в `promo/audio/synth.py`. Время событий —
одно на двоих, в `promo/cues.json`. Экраны — снимки демо (данные вымышленные).

```bash
cd video && npm install                       # HyperFrames 0.8.157
node capture/capture.mjs                      # экраны демо (SKIP_AI=1 — не спрашивать ИИ заново)
cd promo && python build.py                   # cues.js из cues.json и screens.json
python audio/synth.py                         # звук → assets/sound-raw.wav
ffmpeg -y -i assets/sound-raw.wav -af loudnorm=I=-15:TP=-1.5:LRA=11 -ar 48000 assets/sound.wav
node ../capture/frames.mjs 0,10,30,79.9 ../capture/sheet.png   # лист ключевых кадров для проверки
npx --prefix .. hyperframes render -o ../out/lft-promo.mp4 -f 24 -q high
```

Правила (из методички): всё от времени t, случайность только с зерном, рисунок 12 раз в секунду,
поворот сюжета на ~40 % (заявки 1С), последние 3 с — постер, нижние 15 % кадра без текста,
громкость −16…−14 LUFS, пик не выше −1 dBFS.
