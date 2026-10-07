#!/usr/bin/env python3
"""Generates languages/searchpilot-pro.pot from PHP and JavaScript sources.

A dependency-free alternative to `wp i18n make-pot . languages/searchpilot-pro.pot`
(which is preferred when WP-CLI is available). Recognises __(), _e(), _x(), _ex(),
_n(), _nx(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e(), esc_html_x(),
esc_attr_x() with the 'searchpilot-pro' text domain, plus translator comments.

Usage: python bin/make-pot.py
"""
import datetime
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = 'searchpilot-pro'
FUNCS = r'(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)'
STRING = r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*\""""
CALL = re.compile(r'(?<![\w$>])(' + FUNCS + r')\s*\(\s*((?:(?:' + STRING + r')\s*,\s*)+)(?:[^,()]+,\s*)?[\'"]' + DOMAIN + r'[\'"]\s*\)', re.S)
TRANSLATORS = re.compile(r'/\*\s*translators:(.*?)\*/', re.S)


def unquote(s):
    q = s[0]
    body = s[1:-1]
    if q == "'":
        return body.replace("\\'", "'").replace('\\\\', '\\')
    return bytes(body, 'utf-8').decode('unicode_escape').encode('latin-1').decode('utf-8', 'replace')


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


def scan(path, rel, entries):
    with open(path, encoding='utf-8') as fh:
        text = fh.read()
    for match in CALL.finditer(text):
        func = match.group(1)
        strings = [unquote(s) for s in re.findall(STRING, match.group(2))]
        if not strings:
            continue
        line = text.count('\n', 0, match.start()) + 1
        before = text[max(0, match.start() - 400):match.start()]
        comments = TRANSLATORS.findall(before)
        comment = comments[-1].strip() if comments and before.rfind('*/') > before.rfind(';') else ''
        msgid = strings[0]
        plural = strings[1] if func in ('_n', '_nx') and len(strings) > 1 else None
        context = None
        if func in ('_x', '_ex', 'esc_html_x', 'esc_attr_x') and len(strings) > 1:
            context = strings[1]
        if func == '_nx' and len(strings) > 2:
            context = strings[2]
        key = (context, msgid)
        entry = entries.setdefault(key, {'refs': [], 'plural': plural, 'comment': comment, 'context': context})
        entry['refs'].append('%s:%d' % (rel, line))
        if comment and not entry['comment']:
            entry['comment'] = comment


def main():
    entries = {}
    for base, dirs, files in os.walk(ROOT):
        rel_base = os.path.relpath(base, ROOT).replace('\\', '/')
        if rel_base.split('/')[0] in ('vendor', 'node_modules', 'tests', 'bin', 'dist', '.git'):
            continue
        for name in sorted(files):
            if name.endswith(('.php', '.js')):
                rel = name if rel_base == '.' else rel_base + '/' + name
                scan(os.path.join(base, name), rel, entries)
    now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M+0000')
    out = [
        '# Copyright (C) %d sombentur' % datetime.datetime.now(datetime.timezone.utc).year,
        '# This file is distributed under the MIT license.',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: SearchPilot Pro 1.1.9\\n"',
        '"Report-Msgid-Bugs-To: \\n"',
        '"POT-Creation-Date: %s\\n"' % now,
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"X-Domain: %s\\n"' % DOMAIN,
        '',
    ]
    for (context, msgid), entry in sorted(entries.items(), key=lambda kv: kv[1]['refs'][0]):
        if entry['comment']:
            out.append('#. translators: ' + ' '.join(entry['comment'].split()))
        out.append('#: ' + ' '.join(entry['refs'][:6]))
        if context:
            out.append('msgctxt "%s"' % po_escape(context))
        out.append('msgid "%s"' % po_escape(msgid))
        if entry['plural']:
            out.append('msgid_plural "%s"' % po_escape(entry['plural']))
            out.append('msgstr[0] ""')
            out.append('msgstr[1] ""')
        else:
            out.append('msgstr ""')
        out.append('')
    os.makedirs(os.path.join(ROOT, 'languages'), exist_ok=True)
    target = os.path.join(ROOT, 'languages', DOMAIN + '.pot')
    with open(target, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(out))
    print('Wrote %s (%d strings)' % (target, len(entries)))


if __name__ == '__main__':
    main()
