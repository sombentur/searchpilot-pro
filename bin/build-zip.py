#!/usr/bin/env python3
"""Builds an installable plugin zip: dist/searchpilot-pro-<version>.zip.

The archive contains a single top-level folder `searchpilot-pro/` with
forward-slash paths (required by WordPress → Plugins → Upload Plugin on
Linux servers). Development files (tests, bin, composer/phpunit/phpcs config)
are left out.

Usage: python bin/build-zip.py [output_dir]
"""
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SLUG = 'searchpilot-pro'

EXCLUDE_DIRS = {'tests', 'bin', 'vendor', 'node_modules', 'dist', '.git', '.github', '.idea', '.vscode'}
EXCLUDE_FILES = {
    'composer.json', 'composer.lock', 'phpunit.xml.dist', 'phpunit.xml', 'phpcs.xml.dist',
    '.distignore', '.gitignore', '.gitattributes', '.editorconfig', '.DS_Store', 'Thumbs.db',
}


def version():
    with open(os.path.join(ROOT, 'searchpilot-pro.php'), encoding='utf-8') as fh:
        match = re.search(r'^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)', fh.read(), re.M)
    return match.group(1) if match else 'dev'


def main():
    out_dir = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(ROOT), 'dist')
    os.makedirs(out_dir, exist_ok=True)
    target = os.path.join(out_dir, '%s-%s.zip' % (SLUG, version()))
    count = 0
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for base, dirs, files in os.walk(ROOT):
            rel_base = os.path.relpath(base, ROOT)
            parts = [] if rel_base == '.' else rel_base.replace('\\', '/').split('/')
            if parts and parts[0] in EXCLUDE_DIRS:
                dirs[:] = []
                continue
            dirs[:] = sorted(d for d in dirs if not (not parts and d in EXCLUDE_DIRS) and not d.startswith('.'))
            for name in sorted(files):
                if name in EXCLUDE_FILES or name.startswith('.') or name.endswith(('.zip', '.log', '.bak', '.orig')):
                    continue
                path = os.path.join(base, name)
                arc = '/'.join([SLUG] + parts + [name])
                zf.write(path, arc)
                count += 1
            # Prevent directory listings on servers that allow them.
            if parts and 'index.php' not in files:
                zf.writestr('/'.join([SLUG] + parts + ['index.php']), "<?php\n// Silence is golden.\n")
                count += 1
    print('Built %s (%d files, %.1f KB)' % (target, count, os.path.getsize(target) / 1024.0))


if __name__ == '__main__':
    main()
