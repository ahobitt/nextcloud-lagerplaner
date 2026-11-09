#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Trägt GitHub-Repository, Name und E-Mail des Herausgebers in appinfo/info.xml ein,
# einmalig vor dem ersten Release. Mehrfach aufrufbar.
#
#   tools/set-repository.sh <konto/repository> "<Name>" <e-mail>
#   tools/set-repository.sh ahobitt/nextcloud-lagerplaner "Erika Muster" lagerplaner@example.org
set -euo pipefail
if [ $# -ne 3 ]; then
  echo "Aufruf: tools/set-repository.sh <konto/repository> \"<Name>\" <e-mail>" >&2
  exit 2
fi
REPO=$1 NAME=$2 MAIL=$3
cd "$(dirname "$0")/.."
case "$REPO" in */*) ;; *) REPO="$REPO/lagerplaner";; esac
case "$REPO" in *[!A-Za-z0-9._/-]*|*/*/*) echo "Ungültiges Repository: $REPO (erwartet konto/repository)" >&2; exit 2;; esac
case "$MAIL" in *@*.*) ;; *) echo "Ungültige E-Mail-Adresse: $MAIL" >&2; exit 2;; esac
python3 - "$REPO" "$NAME" "$MAIL" <<'PY'
import sys, re, html
repo, name, mail = sys.argv[1:]
p = 'appinfo/info.xml'
s = open(p, encoding='utf-8').read()
# Repository-Links (Website, Bugtracker, Repository, Doku, Screenshots), auch wenn schon eines eingetragen ist
def links(line):
    if not re.search(r'<(website|bugs|repository|admin|screenshot)\b', line):
        return line
    line = re.sub(r'https://github\.com/[^/<"]+/[^/<"#]+', f'https://github.com/{repo}', line)
    return re.sub(r'https://raw\.githubusercontent\.com/[^/<"]+/[^/<"]+/', f'https://raw.githubusercontent.com/{repo}/', line)
s = '\n'.join(links(line) for line in s.split('\n'))
s = re.sub(r'<author mail="[^"]*">[^<]*</author>', f'<author mail="{html.escape(mail)}">{html.escape(name)}</author>', s)
open(p, 'w', encoding='utf-8').write(s)
PY
if grep -q "OWNER\|REPLACE-ME" appinfo/info.xml; then
  echo "Achtung: In appinfo/info.xml stehen noch Platzhalter:" >&2
  grep -n "OWNER\|REPLACE-ME" appinfo/info.xml >&2
  exit 1
fi
echo "Eingetragen: github.com/$REPO, $NAME <$MAIL>"
