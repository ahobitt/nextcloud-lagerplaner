#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Legt Testpersonen, Gruppen und Testdaten für den Lagerplaner an. NUR für Testinstanzen.
# Kann mehrfach laufen; Vorhandenes bleibt.
#
# Auf dem Testserver (im Ordner mit compose.yaml):
#   ./setup-testdata.sh
# Mit anderer Compose-Datei:
#   COMPOSE="docker compose -f compose.mariadb.yaml" ./setup-testdata.sh
set -euo pipefail

COMPOSE=${COMPOSE:-docker compose}
PASS=${TEST_PASSWORD:-Lagerplaner-Test-2026}
APP_DIR=${APP_DIR:-/var/www/html/custom_apps/lagerplaner}

occ() { $COMPOSE exec -T -u33 app ./occ "$@"; }

echo "App aktivieren (führt die Migrationen aus)"
occ app:enable lagerplaner
occ background:cron
occ config:system:set default_language --value=de >/dev/null
occ config:system:set default_locale --value=de_DE >/dev/null

echo "Testpersonen: anna (legt Lager an), ben und carla (Leiterrunde), dora (Küchenteam), emil (ohne Zugriff)"
for entry in "anna:Anna Adler" "ben:Ben Berg" "carla:Carla Christ" "dora:Dora Dorn" "emil:Emil Eck"; do
  uid=${entry%%:*}
  name=${entry#*:}
  if occ user:info "$uid" >/dev/null 2>&1; then
    echo "  $uid gibt es schon"
  else
    $COMPOSE exec -T -u33 -e OC_PASS="$PASS" app ./occ user:add --password-from-env --display-name="$name" "$uid" >/dev/null
    echo "  $uid angelegt"
  fi
done

echo "Gruppen: leiterrunde (ben, carla), kueche (dora)"
for group in "leiterrunde:Leiterrunde" "kueche:Küchenteam Stamm"; do
  gid=${group%%:*}
  occ group:info "$gid" >/dev/null 2>&1 || occ group:add --display-name="${group#*:}" "$gid" >/dev/null
done
occ group:adduser leiterrunde ben || true
occ group:adduser leiterrunde carla || true
occ group:adduser kueche dora || true

echo "Lagerplätze: erfundener Datenstand unter der Quelle testdaten (die Campsitemap kommt mit der App)"
$COMPOSE exec -T -u33 app sh -c "cp '$APP_DIR/tests/fixtures/sites-snapshot.json' /tmp/testplaetze.json"
occ lagerplaner:sites:import /tmp/testplaetze.json
occ config:app:set lagerplaner home_coordinates --value="51.06,7.06" >/dev/null

if [ "${COOKBOOK:-no}" = "yes" ]; then
  echo "Nextcloud Cookbook installieren (braucht Internetzugang zum App Store) und Annas Rezeptordner festlegen"
  occ app:list | grep -q "cookbook:" || occ app:install cookbook || true
  occ app:enable cookbook || true
  occ user:setting anna cookbook folder "/Rezepte"
fi

# Die Spielesuche ist ab 0.12.0 eingeschaltet und lädt den Katalog von pfadfinder-spiele.de.
# GAMES=no schaltet sie ab, etwa für automatische Tests ohne Abhängigkeit von fremden Seiten.
if [ "${GAMES:-yes}" = "no" ]; then
  echo "Spielesuche abschalten"
  occ config:app:set lagerplaner games_enabled --value=no >/dev/null
fi

echo "Fertig. Passwort aller Testpersonen: $PASS"
