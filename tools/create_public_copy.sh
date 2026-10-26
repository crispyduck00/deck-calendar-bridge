#!/bin/sh
# Export a verified version into a new public repository, with no old Git/PR history.
set -eu
if [ "${GH_HOST:-github.com}" != github.com ]; then echo 'Use the github.com GitHub CLI host for this tool' >&2; exit 2; fi
if [ "$#" -lt 2 ] || [ "$#" -gt 4 ]; then
    echo 'Usage: create_public_copy.sh OWNER/SOURCE OWNER/NEW-PUBLIC-REPOSITORY [v1.1.3] [--apply]' >&2
    exit 2
fi
dcb_source=$1
dcb_target=$2
dcb_ref=${3:-v1.1.3}
dcb_mode=${4:---dry-run}
if [ "$dcb_source" = "$dcb_target" ]; then echo 'Source and new public target must differ' >&2; exit 2; fi
case "$dcb_mode" in --dry-run|--apply) ;; *) echo 'Use --apply as the fourth argument to publish' >&2; exit 2 ;; esac
for dcb_command in gh git python3 tar; do command -v "$dcb_command" >/dev/null || { echo "Missing: $dcb_command" >&2; exit 1; }; done
python3 - "$dcb_source" "$dcb_target" "$dcb_ref" <<'PY'
import re,sys
assert all(re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*',v) for v in sys.argv[1:3]),'Use OWNER/REPOSITORY names'
assert re.fullmatch(r'v[1-9][0-9]*\.[0-9]+\.[0-9]+',sys.argv[3]),'Use an official stable tag, such as v1.1.3'
PY
gh auth status
# Nothing is deleted from the source. Temporary clones/exports are retained for review.
dcb_work=$(mktemp -d "${TMPDIR:-/tmp}/deck-calendar-public.XXXXXX")
chmod 0700 "$dcb_work"
git -c credential.helper= -c 'credential.helper=!gh auth git-credential' clone --depth=1 --branch "$dcb_ref" "https://github.com/$dcb_source.git" "$dcb_work/source"
python3 - "$dcb_work/source" "$dcb_source" "$dcb_ref" <<'PY'
import json,subprocess,sys
repo=sys.argv[2]; ref=sys.argv[3]
def api(path):return json.loads(subprocess.check_output(['gh','api','repos/'+repo+'/'+path]))
obj=api('git/ref/tags/'+ref)['object']
for _ in range(10):
    if obj['type']=='commit':break
    assert obj['type']=='tag','Unexpected tag object'
    obj=api('git/tags/'+obj['sha'])['object']
else:raise RuntimeError('Too many nested tag objects')
head=subprocess.check_output(['git','-C',sys.argv[1],'rev-parse','HEAD'],text=True).strip()
assert head==obj['sha'],'The cloned branch does not match the exact requested tag'
PY
mkdir "$dcb_work/public"
git -C "$dcb_work/source" archive HEAD | tar -x -C "$dcb_work/public"
python3 - "$dcb_work/public" "$dcb_source" "$dcb_target" "$dcb_ref" <<'PY'
from pathlib import Path
import sys,xml.etree.ElementTree as E
root=Path(sys.argv[1]); source=sys.argv[2]; target=sys.argv[3]
info=E.parse(root/'appinfo/info.xml').getroot()
assert info.findtext('id')=='deckcalendarbridge','Wrong source application'
assert int(info.findtext('version').split('.')[0])>=1,'Use an official version 1.0 or later'
assert info.findtext('version')==sys.argv[4].removeprefix('v'),'Tag and application version differ'
for path in root.rglob('*'):
    assert not path.is_symlink(),'Unexpected source symlink'
    if path.is_file():
        content=path.read_text()
        replaced=content.replace('https://github.com/'+source,'https://github.com/'+target)
        if replaced!=content:path.write_text(replaced)
assert not (root/'.git').exists(),'Export must not contain old Git history'
PY
if [ -n "${DCB_DENY_FILE:-}" ]; then
    python3 "$dcb_work/public/tools/check_public_tree.py" --tree "$dcb_work/public" --exported --deny-file "$DCB_DENY_FILE"
else
    python3 "$dcb_work/public/tools/check_public_tree.py" --tree "$dcb_work/public" --exported
fi
printf 'Reviewed source: %s at %s\nNew public target: %s\nExport to inspect: %s\n' "$dcb_source" "$dcb_ref" "$dcb_target" "$dcb_work/public"
if [ "$dcb_mode" != --apply ]; then
    echo 'Dry-run: no repository was created and nothing was published. Review the export before using --apply.'
    exit 0
fi
# Creation refuses an existing target; no force-push or visibility change is used.
gh repo create "$dcb_target" --public --description 'Link Nextcloud Deck cards to calendar events with bidirectional times and optional external-calendar invitations'
git -C "$dcb_work/public" init -b main
git -C "$dcb_work/public" add -A
git -C "$dcb_work/public" -c user.name='Project contributors' -c user.email=contributors@example.invalid -c commit.gpgsign=false commit -m 'Initial official public source release'
git -C "$dcb_work/public" -c credential.helper= -c 'credential.helper=!gh auth git-credential' push "https://github.com/$dcb_target.git" main:main
gh repo edit "$dcb_target" --default-branch main
echo "Published clean source to https://github.com/$dcb_target. Its CI must pass before its release asset is published."
echo "The original repository is unchanged. Keep its historical branches, PRs, logs and backups private."
