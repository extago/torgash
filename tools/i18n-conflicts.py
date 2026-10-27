"""
Lists English keys translated differently in two lang files of one language.

All JSON translation files — core's lang/, every module's and theme's — are
merged into ONE table per language, so the same English string can have only
one translation: whichever file loads last wins, silently. Run after adding
strings; a conflict is fixed by making the English say which one it means.

    python tools/i18n-conflicts.py [ru]
"""
import io, json, glob, os, sys, collections

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
locale = sys.argv[1] if len(sys.argv) > 1 else 'ru'
seen = collections.defaultdict(dict)   # en -> {translation: [files]}
files = glob.glob(f'lang/{locale}.json') + glob.glob(f'modules/*/lang/{locale}.json') + glob.glob(f'themes/*/lang/{locale}.json')
for f in files:
    for en, tr in json.load(io.open(f, encoding='utf-8')).items():
        seen[en].setdefault(tr, []).append(f.replace('\\', '/'))
out = []
for en, trs in sorted(seen.items()):
    if len(trs) > 1:
        out.append(en)
        for tr, where in trs.items():
            out.append(f'    {tr}  <- {", ".join(where)}')
sys.stdout.buffer.write(('\n'.join(out) + '\n').encode('utf-8'))
sys.exit(1 if out else 0)
