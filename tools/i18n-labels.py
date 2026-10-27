"""
Turns declared {en, ru} label maps into English strings + lang/ru.json entries.

    python tools/i18n-labels.py report            # what would change, as JSON
    python tools/i18n-labels.py apply             # rewrite files, fill lang files

Declarations only: a map literal whose values are plain string literals, in
PHP under app/, modules/*/src, and in manifest.json / theme.json at the keys a
declaration uses (see JSON_KEYS). Migrations, tests and anything the shop
types (default block settings, seeded rows) are left alone — those are
translatable CONTENT, read through t(), not labels.

One-off tool, kept for a module written before the rule (docs/DECISIONS.md).
"""
import io, json, os, re, sys, glob, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

# A PHP single- or double-quoted literal with no interpolation.
SQ = r"'(?:[^'\\]|\\.)*'"
DQ = r'"(?:[^"\\$]|\\.)*"'
LIT = f'(?:{SQ}|{DQ})'
PAIR = rf"'([a-z]{{2}})'\s*=>\s*({LIT})"
MAP = re.compile(rf"\[\s*{PAIR}(?:\s*,\s*{PAIR})*\s*,?\s*\]", re.S)
PAIR_RE = re.compile(PAIR, re.S)
KEY_BEFORE = re.compile(r"""(?:'([\w.]+)'|"([\w.]+)")\s*=>\s*$|(\w+)\s*:\s*$|(\w+)\s*\(\s*$""")

def php_unquote(lit):
    q, body = lit[0], lit[1:-1]
    if q == "'":
        return body.replace("\\'", "'").replace('\\\\', '\\')
    return bytes(body, 'utf-8').decode('unicode_escape').encode('latin-1').decode('utf-8') if '\\' in body else body

def php_quote(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"

def owner(path):
    p = path.replace('\\', '/')
    m = re.match(r'modules/([^/]+)/', p)
    if m:
        return f'modules/{m.group(1)}/lang/ru.json'
    m = re.match(r'themes/([^/]+)/', p)
    if m:
        return f'themes/{m.group(1)}/lang/ru.json'
    return 'lang/ru.json'

def php_files():
    out = glob.glob('app/**/*.php', recursive=True)
    out += glob.glob('modules/*/src/**/*.php', recursive=True)
    out += glob.glob('modules/*/routes*.php')
    return sorted(f.replace('\\', '/') for f in out)

def scan_php(path):
    text = io.open(path, encoding='utf-8', newline='').read()
    found = []
    for m in MAP.finditer(text):
        pairs = {k: php_unquote(v) for k, v in PAIR_RE.findall(m.group(0))}
        if 'en' not in pairs:
            continue
        before = text[max(0, m.start() - 80):m.start()]
        km = KEY_BEFORE.search(before)
        key = next((g for g in (km.groups() if km else ()) if g), None)
        line = text.count('\n', 0, m.start()) + 1
        found.append({'file': path, 'line': line, 'key': key, 'start': m.start(), 'end': m.end(), 'pairs': pairs})
    return text, found

# JSON: which keys of which objects are declarations.
def walk_json(node, path, hits):
    if isinstance(node, dict):
        if set(node) and set(node) <= {'en', 'ru', 'uk', 'de'} and all(isinstance(v, str) for v in node.values()) and 'en' in node:
            hits.append((path, node))
            return
        for k, v in node.items():
            walk_json(v, path + [k], hits)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            walk_json(v, path + [i], hits)

CONTENT_PATH = re.compile(r"(default_blocks|blocks|keywords)")

def json_declaration(path):
    # default_blocks / preset blocks are block SETTINGS (content the shop owns
    # once laid out); keywords are search words per language, not translations.
    return not any(isinstance(p, str) and CONTENT_PATH.fullmatch(p) for p in path)

def json_files():
    return sorted([f.replace('\\', '/') for f in glob.glob('modules/*/manifest.json') + glob.glob('themes/*/theme.json')])

def collect():
    report = []
    for f in php_files():
        _, found = scan_php(f)
        for h in found:
            report.append({'file': f, 'line': h['line'], 'key': h['key'], 'en': h['pairs']['en'], 'ru': h['pairs'].get('ru')})
    for f in json_files():
        data = json.load(io.open(f, encoding='utf-8'))
        hits = []
        walk_json(data, [], hits)
        for path, node in hits:
            report.append({'file': f, 'path': '.'.join(map(str, path)), 'decl': json_declaration(path), 'en': node['en'], 'ru': node.get('ru')})
    return report

def set_path(data, path, value):
    for p in path[:-1]:
        data = data[p]
    data[path[-1]] = value

def apply(skip):
    translations = collections.defaultdict(dict)   # lang file -> en -> ru
    conflicts = []

    def remember(file, en, ru):
        if not ru or ru == en:
            return
        target = owner(file)
        prev = translations[target].get(en)
        if prev and prev != ru:
            conflicts.append({'file': target, 'en': en, 'ru': [prev, ru]})
        translations[target].setdefault(en, ru)

    for f in php_files():
        text, found = scan_php(f)
        if not found:
            continue
        out, pos = [], 0
        for h in found:
            if (f, h['line']) in skip or h['key'] in SKIP_KEYS:
                continue
            out.append(text[pos:h['start']])
            out.append(php_quote(h['pairs']['en']))
            pos = h['end']
            remember(f, h['pairs']['en'], h['pairs'].get('ru'))
        out.append(text[pos:])
        new = ''.join(out)
        if new != text:
            io.open(f, 'w', encoding='utf-8', newline='').write(new)

    for f in json_files():
        raw = io.open(f, encoding='utf-8', newline='').read()
        data = json.loads(raw, object_pairs_hook=collections.OrderedDict)
        hits = []
        walk_json(data, [], hits)
        changed = False
        for path, node in hits:
            if not json_declaration(path):
                continue
            set_path(data, path, node['en'])
            remember(f, node['en'], node.get('ru'))
            changed = True
        if changed:
            nl = '\r\n' if '\r\n' in raw else '\n'
            io.open(f, 'w', encoding='utf-8', newline='').write(json.dumps(data, ensure_ascii=False, indent=4).replace('\n', nl) + nl)

    for target, entries in translations.items():
        existing = collections.OrderedDict()
        if os.path.exists(target):
            existing = json.load(io.open(target, encoding='utf-8'), object_pairs_hook=collections.OrderedDict)
        for en, ru in entries.items():
            if en in existing and existing[en] != ru:
                conflicts.append({'file': target, 'en': en, 'ru': [existing[en], ru], 'kept': 'existing'})
                continue
            existing[en] = ru
        os.makedirs(os.path.dirname(target) or '.', exist_ok=True)
        io.open(target, 'w', encoding='utf-8', newline='\n').write(json.dumps(existing, ensure_ascii=False, indent=4) + '\n')

    return conflicts

# Keys under which an {en, ru} map is CONTENT, not a declaration — filled in
# after reading the report.
SKIP_KEYS = set()

if __name__ == '__main__':
    mode = sys.argv[1] if len(sys.argv) > 1 else 'report'
    out = sys.argv[2] if len(sys.argv) > 2 else None
    if mode == 'report':
        data = collect()
    else:
        skip = set()
        if out and os.path.exists(out):
            skip = {(r['file'], r['line']) for r in json.load(io.open(out, encoding='utf-8'))}
            out = None
        data = apply(skip)
    text = json.dumps(data, ensure_ascii=False, indent=1)
    if out:
        io.open(out, 'w', encoding='utf-8').write(text)
    else:
        sys.stdout.buffer.write(text.encode('utf-8'))
