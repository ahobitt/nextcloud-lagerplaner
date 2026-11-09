#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Integrationstest: startet eine echte Nextcloud im Container, aktiviert die App,
# legt Testdaten an und prüft die Kernabläufe über die API.
#
#   tests/Integration/run.sh                          # Nextcloud 34, MariaDB
#   NC_IMAGE=nextcloud:33-apache DB=pgsql tests/Integration/run.sh
#   KEEP=1 tests/Integration/run.sh                   # Container danach nicht entfernen
#
# Voraussetzungen: Docker mit Compose, Python 3, gebaute Oberfläche (npm ci && npm run build).
set -euo pipefail
cd "$(dirname "$0")"

DB=${DB:-mariadb}
export NC_IMAGE=${NC_IMAGE:-nextcloud:34-apache}
export NC_PORT=${NC_PORT:-8081}
export COMPOSE="docker compose -p lagerplaner-it-$DB -f compose.$DB.yaml"
# Ohne Abhängigkeit von pfadfinder-spiele.de testen, außer ausdrücklich gewünscht
export GAMES=${GAMES:-no}
STATE=$(mktemp)

if [ ! -f ../../js/lagerplaner-main.mjs ]; then
  echo "Die Oberfläche ist nicht gebaut. Bitte zuerst: npm ci && npm run build" >&2
  exit 2
fi

cleanup() {
  status=$?
  rm -f "$STATE"
  if [ "$status" -ne 0 ]; then
    echo "== Fehlgeschlagen; letzte Einträge im Nextcloud-Log:"
    $COMPOSE exec -T app sh -c 'tail -n 100 /var/www/html/data/nextcloud.log' 2>/dev/null || true
  fi
  if [ "${KEEP:-0}" != "1" ]; then
    $COMPOSE down -v >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT

echo "== $NC_IMAGE mit $DB starten"
$COMPOSE up -d
for _ in $(seq 1 120); do
  if $COMPOSE exec -T -u33 app ./occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
    break
  fi
  sleep 5
done
$COMPOSE exec -T -u33 app ./occ status

echo "== Testdaten"
COMPOSE="$COMPOSE" bash ./setup-testdata.sh

echo "== Abläufe über die API"
python3 smoke.py --base "http://127.0.0.1:$NC_PORT" --state "$STATE"

echo "== Hintergrundjobs (Erinnerungen) über cron.php"
$COMPOSE exec -T -u33 app php -f /var/www/html/cron.php
$COMPOSE exec -T -u33 app php -f /var/www/html/cron.php
python3 smoke.py --base "http://127.0.0.1:$NC_PORT" --state "$STATE" --after-cron

echo "== Fehler im Nextcloud-Log"
$COMPOSE exec -T app sh -c 'cat /var/www/html/data/nextcloud.log 2>/dev/null || true' | python3 check_log.py

echo "== Alles bestanden ($NC_IMAGE, $DB)"
