#!/usr/bin/env python3
"""Keep user-visible version, release inputs and public documentation in agreement."""
from pathlib import Path
import re
import xml.etree.ElementTree as E

root = Path(__file__).resolve().parents[1]
info = E.parse(root/'appinfo/info.xml').getroot()
version = info.findtext('version')
assert re.fullmatch(r'[1-9][0-9]*\.[0-9]+\.[0-9]+',version)
assert info.findtext('id') == 'deckcalendarbridge'
assert info.findtext('licence') == 'AGPL-3.0-or-later'
assert info.findtext('bugs').startswith('https://github.com/')
assert 'NC35 · '+version in (root/'templates/admin.php').read_text()
assert ("'installed_version','"+version+"'") in (root/'lib/Controller/ControlController.php').read_text()
assert '## '+version+'\n' in (root/'CHANGELOG.md').read_text()
for name in ['AI_USAGE.md','CONTRIBUTING.md','SECURITY.md','docs/DEVELOPMENT.md','docs/WINDOWS_PUBLICATION.md','docs/VSCODE_PUBLICATION.md',
             'docs/INSTALLATION.md','docs/UNINSTALLATION.md','docs/CONFIGURATION.md','docs/WORKER_SYSTEMD.md']:
    assert (root/name).is_file(),name
assert (root/'tools/Publish-CleanRepository.ps1').read_text().isascii(),'PowerShell 5.1 source must be ASCII-compatible UTF-8'
print('PASS release metadata, public documentation and PowerShell source encoding')
