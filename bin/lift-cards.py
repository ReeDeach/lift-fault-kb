"""Карточки лифтов (выгрузка Брестлифта по маршрутам, файлы Word 97–2003 .doc) → JSON для
bin/import-cards.php. Только стандартная библиотека Python: разбирает контейнер OLE и текст
документа, ничего не выполняет.

    python bin/lift-cards.py private/brigade/*.doc > private/brigade/cards.json

Строка карточки: номер, модель (П/Г/Б — пассажирский, грузовой, больничный; М/Г — малый
грузовой), адрес с подъездом, этажность, вид обслуживания (П/Ч), дата установки, заказчик.
Файл замещения — в нём под заголовком маршрута подзаголовки других механиков: owner в строке.
"""
import json
import re
import struct
import sys


ENDOFCHAIN = 0xFFFFFFFE


class Cfb:
    def __init__(self, data):
        if data[:8] != bytes.fromhex('D0CF11E0A1B11AE1'):
            raise ValueError('не OLE-файл')
        self.d = data
        self.ss = 1 << struct.unpack_from('<H', data, 30)[0]
        self.mss = 1 << struct.unpack_from('<H', data, 32)[0]
        nfat, = struct.unpack_from('<I', data, 44)
        self.dir_start, = struct.unpack_from('<I', data, 48)
        self.cutoff, = struct.unpack_from('<I', data, 56)
        minifat_start, nminifat = struct.unpack_from('<II', data, 60)
        difat_start, ndifat = struct.unpack_from('<II', data, 68)
        fat_sectors = list(struct.unpack_from('<109I', data, 76))
        s = difat_start
        for _ in range(ndifat):
            if s >= ENDOFCHAIN:
                break
            vals = struct.unpack_from('<%dI' % (self.ss // 4), self.sector(s))
            fat_sectors += vals[:-1]
            s = vals[-1]
        fat_sectors = [x for x in fat_sectors[:nfat] if x < ENDOFCHAIN]
        self.fat = []
        for fs in fat_sectors:
            self.fat += struct.unpack_from('<%dI' % (self.ss // 4), self.sector(fs))
        self.minifat = []
        if nminifat and minifat_start < ENDOFCHAIN:
            raw = self.chain(minifat_start)
            self.minifat = list(struct.unpack_from('<%dI' % (len(raw) // 4), raw))
        self.entries = {}
        raw = self.chain(self.dir_start)
        for i in range(len(raw) // 128):
            e = raw[i * 128:(i + 1) * 128]
            nlen, typ = struct.unpack_from('<HB', e, 64)
            name = e[:max(0, nlen - 2)].decode('utf-16-le', 'replace')
            start, size = struct.unpack_from('<II', e, 116)
            if typ in (1, 2, 5):
                self.entries[name] = (typ, start, size)
        root = self.entries.get('Root Entry')
        self.ministream = self.chain(root[1])[:root[2]] if root and root[1] < ENDOFCHAIN else b''

    def sector(self, n):
        off = (n + 1) * self.ss
        return self.d[off:off + self.ss]

    def chain(self, start):
        out, s, seen = [], start, set()
        while s < ENDOFCHAIN and s not in seen and s < len(self.fat):
            seen.add(s)
            out.append(self.sector(s))
            s = self.fat[s]
        return b''.join(out)

    def stream(self, name):
        typ, start, size = self.entries[name]
        if size < self.cutoff:
            out, s, seen = [], start, set()
            while s < ENDOFCHAIN and s not in seen and s < len(self.minifat):
                seen.add(s)
                out.append(self.ministream[s * self.mss:(s + 1) * self.mss])
                s = self.minifat[s]
            return b''.join(out)[:size]
        return self.chain(start)[:size]


def doc_text(data):
    cfb = Cfb(data)
    wd = cfb.stream('WordDocument')
    flags, = struct.unpack_from('<H', wd, 0x0A)
    table = cfb.stream('1Table' if flags & 0x0200 else '0Table')
    fc_clx, lcb_clx = struct.unpack_from('<II', wd, 0x01A2)
    clx = table[fc_clx:fc_clx + lcb_clx]
    i = 0
    while i < len(clx) and clx[i] == 0x01:
        cb, = struct.unpack_from('<H', clx, i + 1)
        i += 3 + cb
    if i >= len(clx) or clx[i] != 0x02:
        raise ValueError('нет таблицы фрагментов')
    lcb, = struct.unpack_from('<I', clx, i + 1)
    plc = clx[i + 5:i + 5 + lcb]
    n = (lcb - 4) // 12
    cps = struct.unpack_from('<%dI' % (n + 1), plc, 0)
    parts = []
    for k in range(n):
        fc, = struct.unpack_from('<I', plc, 4 * (n + 1) + 8 * k + 2)
        count = cps[k + 1] - cps[k]
        if fc & 0x40000000:
            off = (fc & ~0x40000000) // 2
            parts.append(wd[off:off + count].decode('cp1252', 'replace'))
        else:
            parts.append(wd[fc:fc + 2 * count].decode('utf-16-le', 'replace'))
    text = ''.join(parts)
    # \x07 — конец ячейки (два подряд — конец строки таблицы), \r — абзац
    text = text.replace('\x07\x07', '\n').replace('\x07', '\t').replace('\r', '\n')
    return ''.join(ch for ch in text if ch in '\t\n' or ch >= ' ')


def parse_cards(text):
    rows = []
    # Колонка «Об» — П или Ч; модель бывает без скорости («П-320»), этажность и «Ч» слитно («10ч»)
    # Виды: П — пассажирский, Г — грузовой, Б — больничный, М/Г — малый грузовой (номер «М479»)
    ROW = re.compile(r'^(?P<reg>М?\d{3,5}[нН]?)?\s+(?P<model>(?:[ПГБпгб][ЭE]?[- ]?\d{3,4}(?:[- ]\d[.,]\d{1,2}\s?м?)?|М/[Гг](?:-\d+)?))\s+(?P<addr>.+?)\s+'
                     r'(?P<floors>\d{1,2})\s*(?P<svc>[ПЧпч])\s+(?P<inst>[\d./]*)\s*(?P<client>.*)$')
    HEAD = re.compile(r'^(?:\d+)?\s*(\d{4})\s+([А-ЯЁ][а-яё]+\s*[А-Я]\.\s*[А-Я]\.?)\s*$')
    SUB = re.compile(r'^([А-ЯЁ][а-яё]+ [А-Я]\.\s?[А-Я]\.)$')

    for f in text.split('===== ')[1:]:
        name, _, body = f.partition('\n')
        route = mech = owner = None
        # Две записи в одной строке: «… ЗАО "Брест.научн      2385н  П-1000-1,0 …»
        lines = []
        for raw in body.split('\n'):
            lines += re.split(r'\s{6,}(?=\d{4}[нН]?\s+П)', raw)
        for line in lines:
            s = line.strip()
            h = HEAD.match(s)
            if h:
                route, mech = h.group(1), re.sub(r'\s+', ' ', h.group(2))
                owner = mech
                continue
            # Подзаголовок «Фицук А. Б.» в файле замещения: дальше его часть маршрута
            sub = SUB.match(s)
            if sub:
                owner = sub.group(1)
                continue
            # Лифт, вписанный одним адресом (без номера и модели): «          Пионерская 50»
            bare = re.match(r'^\s{12,}(?P<addr>[А-ЯЁа-яё][А-ЯЁа-яё.\- ]+\s\d+\S*)(?:\s+(?P<floors>\d{1,2})\s+(?P<svc>[ПЧ])\s+(?P<client>.*))?\s*$', line)
            if bare and route:
                d = bare.groupdict()
                rows.append({
                    'file': name, 'route': route, 'mechanic': mech, 'owner': owner, 'reg': '', 'model': '',
                    'address': d['addr'].strip(), 'floors': int(d['floors']) if d['floors'] else 0,
                    'service': d['svc'] or '', 'installed': '', 'client': (d['client'] or '').strip(),
                })
                continue
            r = ROW.match(line)
            if r:
                d = r.groupdict()
                rows.append({
                    'file': name, 'route': route, 'mechanic': mech, 'owner': owner,
                    'reg': (d['reg'] or '').rstrip('нН'), 'model': d['model'], 'address': d['addr'].strip().rstrip(','),
                    'floors': int(d['floors']), 'service': d['svc'].upper(), 'installed': d['inst'], 'client': d['client'].strip(),
                })
    return rows


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    texts = []
    for path in sys.argv[1:]:
        with open(path, 'rb') as f:
            texts.append('===== ' + path.replace('\\', '/').rsplit('/', 1)[-1] + '\n' + doc_text(f.read()))
    json.dump(parse_cards('\n'.join(texts)), sys.stdout, ensure_ascii=False, indent=0)
