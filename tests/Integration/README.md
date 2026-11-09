# Integrationstest gegen eine echte Nextcloud

Die Unit-Tests prüfen die Fachlogik gegen nachgebildete Nextcloud-Dienste. Dieser Test startet eine echte Nextcloud im Container und prüft, was nur dort sichtbar wird: Migrationen auf MariaDB und PostgreSQL, Gruppen und Freigaben, Benachrichtigungen, Hintergrundjobs, den `occ`-Befehl für Lagerplätze und Fehler im Nextcloud-Log.

```sh
npm ci && npm run build                                   # Oberfläche bauen
tests/Integration/run.sh                                  # Nextcloud 34 mit MariaDB
NC_IMAGE=nextcloud:33-apache DB=pgsql tests/Integration/run.sh
KEEP=1 tests/Integration/run.sh                           # Container danach behalten (http://127.0.0.1:8081)
```

| Datei | Zweck |
| --- | --- |
| `run.sh` | startet Nextcloud, legt Testdaten an, prüft die Abläufe, lässt Hintergrundjobs laufen, prüft das Log |
| `setup-testdata.sh` | Testpersonen anna, ben, carla, dora, emil; Gruppen leiterrunde und kueche; erfundene Lagerplätze unter der Quelle `testdaten` (die Plätze der Schwarzzelt IT Campsitemap bringt die App selbst mit); `GAMES=no` schaltet die Spielesuche ab; auch für einen manuellen Testserver |
| `smoke.py` | Abläufe über die API: Lager, Team und Rechte, Aufgaben mit Benachrichtigung, Material, Lagerplätze, Vorlagen, geschützte Unterlagen, erneuter Assistentenlauf |
| `check_log.py` | schlägt fehl, wenn der Lagerplaner Fehler ins Nextcloud-Log schreibt |
| `compose.mariadb.yaml`, `compose.pgsql.yaml` | Testumgebungen; Datenbank im Arbeitsspeicher |

In GitHub Actions läuft der Test für Nextcloud 33 und 34 mit beiden Datenbanken (`.github/workflows/integration.yml`); Nextcloud 35 läuft mit, darf aber scheitern, solange es kein offizielles Image gibt.

`smoke.py --harness` läuft gegen den Entwicklungs-Testaufbau ohne Nextcloud und prüft dort nur die Abläufe, nicht Benachrichtigungen und Hintergrundjobs.
