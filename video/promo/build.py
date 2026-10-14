"""Сборка cues.js из cues.json (время событий) и assets/screens.json (положения элементов на снимках)."""
import json
from pathlib import Path

root = Path(__file__).parent
cues = json.loads((root / 'cues.json').read_text(encoding='utf-8'))
cues['screens'] = json.loads((root / 'assets' / 'screens.json').read_text(encoding='utf-8'))
(root / 'cues.js').write_text('window.CUES = ' + json.dumps(cues, ensure_ascii=False) + ';\n', encoding='utf-8')
print('cues.js', len(cues['screens']), 'экранов')
