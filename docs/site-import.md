# Lagerplatzdaten

Die Lagerplatzsuche arbeitet nur mit Daten in der eigenen Datenbank. Datenquellen werden regelmäßig abgeglichen; ist eine Quelle nicht erreichbar, bleibt der letzte Stand nutzbar.

## Aufbau

```
Datenquelle (SiteSource)          lib/Sites/SchwarzzeltItSource.php, später weitere
    ↓ Stand im Austauschformat
Abgleich (SiteSynchronizer)       lib/Service/SiteSynchronizer.php
    ↓
lp_sites, lp_site_facts, lp_site_sources
    ↓
Lagerplatzsuche (SiteDirectoryService, SiteQuery)
```

Eine Quelle liefert ihren Stand im internen Austauschformat (siehe unten); Spaltennamen der Quelle bleiben in ihrem Baustein. Der Synchronizer ist die einzige Stelle, die importierte Plätze schreibt; auch der Import einer Datei läuft über ihn.

| Tabelle | Inhalt |
| --- | --- |
| `lp_sites` | Plätze mit Stammdaten, Adresse, Kapazität, Betten, Preis und Ausstattung in eigenen Spalten (`feature_water` usw.: 1 vorhanden, 0 nicht vorhanden, null unbekannt) |
| `lp_site_sources` | Datenquellen mit Name und Link für die Quellenangabe, letzter Abgleich, Ergebnis |
| `lp_site_facts` | Herkunft einzelner Angaben, wenn sie nicht aus der Quelle stammen: Methode (`website`, `manual`), Beleg-URL, Prüfdatum, Sicherheit, wer, wann zurückgemeldet |

## Schwarzzelt IT Campsitemap

Erste Datenquelle: [github.com/Schwarzzelt-IT/campsites](https://github.com/Schwarzzelt-IT/campsites), Datei `sites.csv`, MIT-Lizenz. Schwarzzelt IT hat die Nutzung erlaubt, wenn die Quelle genannt und möglichst verlinkt wird; die App zeigt bei jedem Platz „Quelle: Schwarzzelt IT Campsitemap“ mit Link. Die Website des Platzes ist davon getrennt.

| CSV | Lagerplatz |
| --- | --- |
| `name` | Name |
| `category` = `campsite` / `house` / `campsite house` | Zeltplatz / Gruppenhaus / Haus und Zeltplatz |
| `latitude`, `longitude` | Koordinaten (weltweit, keine Beschränkung auf Deutschland) |
| `url` | Website des Platzes |
| `id` (falls die CSV sie künftig enthält) | Kennung bei der Quelle |

Ohne `id`-Spalte entsteht eine technische Kennung aus dem Namen (`sz-` und Prüfwert des normalisierten Namens). Zeilen ohne Namen, mit unbekannter Kategorie oder ungültigen Koordinaten werden übersprungen und gemeldet. Ausstattung, Kapazität, Betten, Adresse und Preis führt die Campsitemap nicht; sie bleiben `null`.

## Zuständigkeiten

| Angabe | zuständig |
| --- | --- |
| Name, Kategorie, Koordinaten, Betreiber-/Infoseite | Campsitemap (Stammdaten) |
| Postleitzahl, Ort, Land | Lagerplaner, aus den Koordinaten (Geodaten) |
| Kapazität, Betten, Ausstattung, Betriebsstatus, Hinweise | Lagerplaner (Anreicherung, Rückmeldungen) |

Vorrang bei jeder Angabe: von Hand (Administration) vor übernommener Rückmeldung vor Anreicherung vor Campsitemap. Stammdaten aktualisiert die Campsitemap wieder, außer bei einer bewussten Korrektur von Hand. Jede Quelle erklärt ihre Zuständigkeit (`SiteSource::attributes()`); der Abgleich schreibt bei vorhandenen Plätzen nur diese Angaben.

## Anreicherung

`data/sites/schwarzzelt_it.enrichment.json` (Format `lagerplaner-campsites-enrichment/2`) legt geprüfte Angaben über die Plätze der Campsitemap. Die App übernimmt sie bei Installation und Update, sobald ihre `version` neu ist (`SiteEnricher`, über `BundledSites`).

| Feld | Lagerplaner |
| --- | --- |
| `original_name`, sonst `canonical_name` | Zuordnung zum Platz (wie die technische Kennung) |
| `canonical_name`, `canonical_url`, `category` | nicht übernommen: Stammdaten gehören der Campsitemap |
| `capacity_max`, sonst `capacity_min`; `capacity_text` | Kapazität; Text, wenn eine Zahl nicht alles sagt |
| `beds_total`, sonst `beds_max`, `beds_min` | Betten |
| `water` … `accessible`: `true`, `false`, `"partial"`, `null` | ja, nein, teilweise (eigener Zustand), unverändert |
| `postal_code`, `city`, `country`, `location_confidence` | Postleitzahl, Ort, Land mit eigener Sicherheit, Herkunft „Geodaten“ |
| `operating_status`, `status_effective_from`, `status_effective_until` | Betriebsstatus mit Wirksamkeit |
| `review_status` = `open`, `review_reason` | Hinweis „wird noch geprüft“ |
| `confidence`, `source_url`, `reviewed_at` | Sicherheit (ohne Angabe leer), Beleg, Prüfdatum |
| `aliases[]` | zusammengeführte Dubletten, in der Suche ausgeblendet |

Jede übernommene Angabe bekommt einen Herkunftseintrag „laut Website“. Leere Werte ändern nichts.

Vorrang: Von Hand korrigierte Angaben bleiben immer. Angaben „laut Website“ halten den Wert fest, den die Campsitemap bei der Ersetzung hatte (`source_value`); sie bleiben, solange die Campsitemap nichts liefert oder denselben Wert hat. Ändert die Campsitemap den Wert, gilt wieder sie, und der Website-Eintrag entfällt. „Teilweise“ erfüllt einen Ausstattungsfilter nicht eindeutig: Solche Plätze erscheinen nur mit „Auch Plätze zeigen, bei denen diese Angaben unbekannt sind“ und sind gekennzeichnet.

## Betriebsstatus

`active`, `closed`, `closing`, `temporarily_unavailable`, `unknown`, getrennt vom Status in der Quelle. Wirksam gerechnet wird mit dem Datum: „schließt“ gilt ab `status_from` als geschlossen, „vorübergehend nicht verfügbar“ nur bis `status_until`. Geschlossene Plätze blendet die Suche aus („Auch geschlossene Plätze zeigen“ holt sie zurück); bei den übrigen zeigt sie einen Hinweis.

## Feedback / Angaben ergänzen

Bei jedem Platz können alle, die den Lagerplaner nutzen, melden: Angaben stimmen nicht mehr, Website hat sich geändert, Ausstattung fehlt oder stimmt nicht, Kapazität hat sich geändert, Platz ist geschlossen (dauerhaft oder vorübergehend). In der Suche meldet „Lagerplatz fehlt? Melden“ einen neuen Platz mit Art, Lage (Koordinaten oder Kartenlink) und Website.

Rückmeldungen stehen in `lp_site_feedback` mit ihren vorgeschlagenen Werten in `lp_site_feedback_values`. Unter Verwaltung → Lagerplaner übernimmt die Administration sie (Werte werden „von Hand“ mit Vermerk der Rückmeldung gespeichert; ein fehlender Platz wird ein Eintrag ohne Eigentümer, den nur die Administration ändert, mit der meldenden Person als Herkunft), markiert sie als erledigt oder lehnt sie ab. Neue Plätze direkt anlegen kann nur die Administration. Übernommene Werte bleiben beim Abgleich erhalten und erscheinen in der Rückmeldung an die Quelle.

## Abgleich

```sh
php occ lagerplaner:sites:sync                    # alle Quellen aus dem Netz
php occ lagerplaner:sites:sync schwarzzelt_it --bundled   # mitgelieferter Stand, ohne Netz
```

Ein Hintergrundjob gleicht wöchentlich ab (Verwaltung → Lagerplaner, „Quellen wöchentlich abgleichen“); dort gibt es auch „Jetzt abgleichen“. Bei Installation und Update bekommt jede noch nie abgeglichene Quelle den mitgelieferten Stand aus `data/sites/`.

- Unveränderter Inhalt (gleicher Prüfwert) schreibt nichts.
- Bekannte Plätze werden über die Kennung aktualisiert; ändert sie sich, etwa durch einen neuen Namen, wird der Platz über gleiche Website oder Lage (unter 150 m) wiedererkannt. Merklisten bleiben verknüpft.
- Angaben mit Eintrag in `lp_site_facts` überschreibt der Abgleich nicht; er zählt sie als geschützt.
- Fehlt ein Platz im neuen Stand, gilt er als „nicht mehr in der Quelle“; gelöscht wird nichts.

## Herkunft einzelner Angaben

Die Detailansicht nennt bei Angaben, die nicht aus der Quelle stammen, ihre Herkunft („laut Website“, „aus den Koordinaten ermittelt“, „Rückmeldung von …“, „von Hand ergänzt“). Die Administration ergänzt Angaben importierter Plätze unter „Direkt korrigieren“; diese Angaben gehören dem Lagerplaner und bleiben bei Aktualisierungen der Quelle erhalten. Für eine künftige automatische Ergänzung steht `SiteDirectoryService::recordFact()` mit `FactMethod::Website` oder `FactMethod::Geocoding` und einer Sicherheit bereit.

## Mögliche Korrekturen für die Campsitemap

An die Campsitemap gehen nur geprüfte, übernommene Rückmeldungen, die ihre Stammdaten betreffen (`lp_site_feedback.upstream_relevant`): Schließung, neuer Platz, falscher Name, falsche Lage, Website, Betreiberwechsel. Ausstattung, Kapazität, Betten, Postleitzahl und Ort bleiben im Lagerplaner. Unter Verwaltung → Lagerplaner bereitet „Rückmeldung vorbereiten“ einen Text für ein GitHub-Issue vor, mit Zeilen im Format der `sites.csv`; „Als gemeldet markieren“ setzt `reported_at`. Die App ändert das Repository nicht selbst. Ein Betreiberwechsel ohne neue Website ändert im Lagerplaner nichts und wird nur für die Campsitemap vorgemerkt.

## Eine weitere Quelle anbinden

1. `SiteSource` umsetzen (Schlüssel, Name und Link für die Quellenangabe, `fetch()`, optional `bundled()` und `describe()`), die Rohdaten in einem eigenen Fachbaustein ins Austauschformat übersetzen.
2. In `SiteSources` eintragen. Suche, Verwaltung, Abgleich und Hintergrundjob kennen die Quelle danach ohne weitere Änderung.
3. Bei mitgeliefertem Stand in `data/sites/README.md` die Zeile `Zustimmung <schlüssel>: JJJJ-MM-TT` ergänzen; das Release-Skript prüft sie.

## Datei importieren (eigene Listen)

Eigene Listen kommen als JSON-Datei im Format `lagerplaner-sites/1` in die App, unter Verwaltung → Lagerplaner („Datenstand hochladen“, bis 10 MB) oder mit:

```sh
php occ lagerplaner:sites:import /pfad/zur/liste.json
php occ config:app:set lagerplaner sites_disabled --value=dioezese   # Quelle ausblenden
```

```json
{
  "format": "lagerplaner-sites/1",
  "source": "dioezese",
  "snapshotVersion": "dioezese-2026-11",
  "sites": [
    {
      "sourceId": "d1",
      "name": "Diözesanzeltplatz Waldblick",
      "type": "tent",
      "address": { "street": "Waldweg 3", "postalCode": "99084", "city": "Erfurt", "state": "Thüringen", "country": "DE" },
      "coordinates": { "lat": 50.98, "lon": 11.03 },
      "capacity": 200,
      "features": { "water": true, "sanitary": true, "kitchen": null }
    }
  ]
}
```

| Feld | Pflicht | Bedeutung |
| --- | --- | --- |
| `format` | ja | immer `lagerplaner-sites/1` |
| `source` | ja | Kennung der Quelle, Kleinbuchstaben, zum Beispiel `dioezese` (nicht `manual`) |
| `snapshotVersion` | nein | Version des Datenstands, höchstens 32 Zeichen |
| `sites[].sourceId` | ja | stabile Kennung bei der Quelle |
| `sites[].name` | ja | Name des Platzes oder Hauses |
| `sites[].type` | ja | `tent`, `house` oder `both` |
| `sites[].address` | nein | `street`, `postalCode`, `city`, `state` (Bundesland), `country` (ISO-Code, Standard `DE`) |
| `sites[].coordinates` | nein | `lat` und `lon` als Zahlen; fehlt eines, gelten beide als unbekannt |
| `sites[].capacity` | nein | Personen als ganze Zahl; Angaben wie „ca. 50–80“ bitte weglassen |
| `sites[].beds` | nein | Betten als ganze Zahl |
| `sites[].features` | nein | `water`, `sanitary`, `kitchen`, `electricity`, `fireplace`, `accessible` je `true`, `false` oder `null` |
| `sites[].priceText` | nein | Preisangabe als Text; wird nie als Filterwert genutzt |
| `sites[].description` | nein | Freitext, höchstens 5000 Zeichen |
| `sites[].homepage` | nein | Website des Betreibers (http oder https) |
| `sites[].sourceUrl` | nein | Detailseite bei der Quelle |
| `sites[].sourceDate` | nein | Stand laut Quelle, `JJJJ-MM-TT` |

## Regeln beim Einlesen

- Unbekannt bleibt unbekannt: Fehlende oder nicht eindeutig lesbare Werte werden nicht als „nicht vorhanden“ gespeichert. Nur `true` und `false` zählen bei der Ausstattung; Texte wie „ja“ gelten als unbekannt.
- E-Mail-Adressen und Telefonnummern in Freitexten werden durch „[Kontakt entfernt]“ ersetzt (Konzept 5.3). Personenbezogene Kontaktdaten sollten aber schon beim Erstellen des Datenstands entfallen.
- Ungültige Einträge (ohne Kennung, Namen oder gültige Art, doppelte Kennungen) werden übersprungen und vom Befehl einzeln gemeldet; die übrigen werden übernommen.
