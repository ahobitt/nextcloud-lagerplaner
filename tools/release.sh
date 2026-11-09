#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Baut das Release-Paket für den Nextcloud App Store: nur Laufzeitdateien, mit
# Code-Signatur (appinfo/signature.json), als .tar.gz mit Store-Signatur.
#
#   tools/release.sh --docker /opt/lagerplaner-test     # signieren mit dem Docker-Testserver
#   tools/release.sh --occ "php8.3 /pfad/zur/nextcloud/occ"   # signieren mit einer Nextcloud auf diesem Rechner
#   tools/release.sh --unsigned                         # Probepaket ohne Signaturen, nicht für den Store
#
# Weitere Schalter: --without <quelle> (Paket ohne den mitgelieferten Stand einer Quelle), --skip-build (Oberfläche nicht neu bauen).
# Schlüssel und Zertifikat: ~/.nextcloud/certificates/lagerplaner.key und .crt (anders: CERTS=/pfad).
set -euo pipefail

APP=lagerplaner
CERTS=${CERTS:-$HOME/.nextcloud/certificates}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DOCKER_DIR='' OCC='' UNSIGNED=0 WITHOUT='' SKIP_BUILD=0
while [ $# -gt 0 ]; do
  case "$1" in
    --docker) DOCKER_DIR=$2; shift 2;;
    --occ) OCC=$2; shift 2;;
    --unsigned) UNSIGNED=1; shift;;
    --without) WITHOUT="$WITHOUT $2"; shift 2;;
    --skip-build) SKIP_BUILD=1; shift;;
    *) echo "Unbekannter Schalter: $1" >&2; exit 2;;
  esac
done
fail() { echo "FEHLER: $*" >&2; exit 1; }
cd "$ROOT"

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)
[ -n "$VERSION" ] || fail "Keine Version in appinfo/info.xml."
echo "== Lagerplaner $VERSION"

# --- Prüfungen vor dem Bauen
if grep -q "OWNER\|REPLACE-ME" appinfo/info.xml; then
  fail "In appinfo/info.xml stehen noch Platzhalter. Einmalig: tools/set-repository.sh <github-konto> \"<Name>\" <e-mail>"
fi
grep -q "^## $VERSION" CHANGELOG.md || fail "CHANGELOG.md hat keinen Abschnitt „## $VERSION …“; der Store zeigt ihn als Änderungen."
# Jede mitgelieferte Quelle braucht das Datum der Zustimmung in data/sites/README.md
for file in data/sites/*.csv data/sites/*.json; do
  [ -f "$file" ] || continue
  key=$(basename "$file"); key=${key%%.*}
  case " $WITHOUT " in *" $key "*) continue;; esac
  grep -Eq "^Zustimmung $key: 20[0-9]{2}-[0-9]{2}-[0-9]{2}" data/sites/README.md \
    || fail "Für $file fehlt das Datum der Zustimmung in data/sites/README.md (Zeile „Zustimmung $key: JJJJ-MM-TT“). Ohne Zustimmung: --without $key."
done
if [ "$UNSIGNED" = 0 ]; then
  [ -n "$DOCKER_DIR$OCC" ] || fail "Zum Signieren --docker <ordner> oder --occ \"<befehl>\" angeben (oder --unsigned für ein Probepaket)."
  [ -r "$CERTS/$APP.key" ] && [ -r "$CERTS/$APP.crt" ] || fail "Schlüssel oder Zertifikat fehlt: $CERTS/$APP.key und $CERTS/$APP.crt"
fi

# --- Oberfläche bauen
if [ "$SKIP_BUILD" = 0 ]; then
  echo "== Oberfläche bauen"
  npm ci --no-audit --no-fund >/dev/null
  npm run build >/dev/null
fi
[ -f js/lagerplaner-main.mjs ] || fail "Gebaute Oberfläche fehlt (js/lagerplaner-main.mjs)."

# --- Nur Laufzeitdateien zusammenstellen
echo "== Paket zusammenstellen"
BUILD="$ROOT/build"
rm -rf "$BUILD"
mkdir -p "$BUILD/$APP"
cp -r appinfo lib templates img l10n js css data CHANGELOG.md README.md COPYING "$BUILD/$APP/"
find "$BUILD/$APP/js" -name '*.map' -delete
rm -f "$BUILD/$APP/appinfo/signature.json"
for key in $WITHOUT; do
  rm -f "$BUILD/$APP/data/sites/$key".*
  echo "   ohne mitgelieferten Stand von $key"
done
for forbidden in src tests tools node_modules .git .github package.json vite.config.js composer.json; do
  [ ! -e "$BUILD/$APP/$forbidden" ] || fail "$forbidden gehört nicht ins Paket."
done

# --- Code-Signatur (appinfo/signature.json)
if [ "$UNSIGNED" = 0 ]; then
  echo "== Code signieren (occ integrity:sign-app)"
  if [ -n "$DOCKER_DIR" ]; then
    COMPOSE="docker compose --project-directory $DOCKER_DIR"
    $COMPOSE exec -T -u0 app rm -rf /tmp/lp-release
    $COMPOSE cp "$BUILD/$APP" app:/tmp/lp-release
    $COMPOSE cp "$CERTS/$APP.key" app:/tmp/lp-release.key
    $COMPOSE cp "$CERTS/$APP.crt" app:/tmp/lp-release.crt
    $COMPOSE exec -T -u0 app chown -R 33:33 /tmp/lp-release /tmp/lp-release.key /tmp/lp-release.crt
    $COMPOSE exec -T -u33 app php occ integrity:sign-app --privateKey=/tmp/lp-release.key --certificate=/tmp/lp-release.crt --path=/tmp/lp-release
    $COMPOSE cp app:/tmp/lp-release/appinfo/signature.json "$BUILD/$APP/appinfo/signature.json"
    $COMPOSE exec -T -u0 app rm -rf /tmp/lp-release /tmp/lp-release.key /tmp/lp-release.crt
  else
    $OCC integrity:sign-app --privateKey="$CERTS/$APP.key" --certificate="$CERTS/$APP.crt" --path="$BUILD/$APP"
  fi
  [ -s "$BUILD/$APP/appinfo/signature.json" ] || fail "appinfo/signature.json wurde nicht erzeugt."
fi

# --- Archiv und Store-Signatur
TARBALL="$BUILD/$APP-$VERSION.tar.gz"
tar --sort=name --owner=0 --group=0 --numeric-owner -czf "$TARBALL" -C "$BUILD" "$APP"
echo "== Archiv: $TARBALL ($(du -h "$TARBALL" | cut -f1))"
if [ "$UNSIGNED" = 0 ]; then
  openssl dgst -sha512 -sign "$CERTS/$APP.key" "$TARBALL" | openssl base64 -A > "$TARBALL.sig"
  # Gegenprobe mit dem öffentlichen Schlüssel aus dem Zertifikat
  openssl x509 -in "$CERTS/$APP.crt" -pubkey -noout > "$BUILD/pub.pem"
  openssl base64 -d -A -in "$TARBALL.sig" > "$BUILD/sig.bin"
  openssl dgst -sha512 -verify "$BUILD/pub.pem" -signature "$BUILD/sig.bin" "$TARBALL" >/dev/null || fail "Store-Signatur passt nicht zum Zertifikat."
  rm -f "$BUILD/pub.pem" "$BUILD/sig.bin"
  REPO=$(sed -n 's:.*<repository[^>]*>https\://github.com/\([^<]*\)</repository>.*:\1:p' appinfo/info.xml | head -1)
  echo
  echo "Fertig. Nächste Schritte:"
  echo "1. GitHub-Release v$VERSION anlegen und $APP-$VERSION.tar.gz anhängen."
  echo "2. Unter https://apps.nextcloud.com/developer/apps/releases/new eintragen:"
  echo "   Download: https://github.com/$REPO/releases/download/v$VERSION/$APP-$VERSION.tar.gz"
  echo "   Nightly: nicht ankreuzen"
  echo "   Signatur (Inhalt von $TARBALL.sig):"
  cat "$TARBALL.sig"; echo
else
  echo "Probepaket ohne Signaturen; nicht für den Store."
fi
