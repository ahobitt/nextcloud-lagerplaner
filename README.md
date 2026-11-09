# Lagerplaner

Nextcloud-App zur Planung von Zeltlagern, Fahrten und Gruppenveranstaltungen. Ein Lagerassistent stellt sechs kurze Fragen und erzeugt daraus einen bearbeitbaren Entwurf mit Programm, Mahlzeiten und Aufgaben.

Stand: **0.17.1, öffentliche Beta**. Grundlage ist das Gesamtkonzept v0.4.

## Voraussetzungen

| Komponente | Version |
| --- | --- |
| Nextcloud | 33 bis 35 |
| PHP | 8.2 oder neuer (Nextcloud 35: 8.3 oder neuer) |
| Node.js (nur zum Bauen) | 24 |

## Installation zum Testen

1. Den Ordner `lagerplaner` nach `apps/` oder `custom_apps/` der Nextcloud kopieren. Das ZIP enthält die gebaute Oberfläche bereits.
2. `php occ app:enable lagerplaner` ausführen. Dabei legt die Migration die Tabellen `lp_*` an.
3. In Nextcloud erscheint „Lagerplaner“ in der App-Leiste.

## Lagerrollen und Teilen

Über „Team“ im Lager teilt die Lagerleitung ein Lager mit einzelnen Personen oder ganzen Nextcloud-Gruppen, zum Beispiel der Leiterrunde. Es gelten die Freigabe-Einstellungen der Nextcloud: Wer dort nicht als Freigabeziel erlaubt ist, kann auch nicht ins Team aufgenommen werden.

| Rolle | Darf |
| --- | --- |
| Lagerleitung | alles, auch Team, Assistent, Aufgaben zuweisen, Merkliste der Lagerplätze und Löschen |
| Programmteam | Programm bearbeiten, Spiele einplanen, gelöschte Vorschläge wiederherstellen, Material planen, packen, Aufgaben übernehmen und abhaken |
| Küchenteam | Ernährungsangaben, Menüvarianten, Rezepte, Einkauf, packen, Aufgaben übernehmen und abhaken |
| Materialteam | Material planen und packen, Aufgaben übernehmen und abhaken |
| Mitarbeitende | Planung ansehen, Material packen, Aufgaben übernehmen und abhaken |
| Lesezugriff | Planung ansehen, ohne Ernährungsangaben |

## Nextcloud Cookbook

Ist [Cookbook](https://apps.nextcloud.com/apps/cookbook) installiert, übernimmt die Küche Rezepte aus der eigenen Cookbook-Sammlung ins Lager und speichert Lagerrezepte dorthin zurück. Das Lager arbeitet mit einer Kopie; Änderungen in Cookbook werden erkannt und lassen sich übernehmen. Cookbook kennt keine Allergene, übernommene Rezepte gelten deshalb als ungeprüft. Ohne Cookbook bleiben die einfachen Rezepte im Lagerplaner.

## Lagerplatzverzeichnis

„Lagerplätze“ in der Navigation durchsucht Zeltplätze und Gruppenhäuser; im Lager sammelt der Reiter „Lagerplatz“ Kandidaten zum Vergleich. Unbekannte Angaben werden nie als „nicht vorhanden“ gewertet.

Die Plätze stammen aus der [Schwarzzelt IT Campsitemap](https://github.com/Schwarzzelt-IT/campsites) (192 Zeltplätze und Gruppenhäuser, auch außerhalb Deutschlands), angezeigt mit Quellenangabe und Link. Die Campsitemap liefert nur die Stammdaten (Name, Kategorie, Koordinaten, Website); eine mitgelieferte Lagerplaner-Anreicherung ergänzt Postleitzahl und Ort (aus den Koordinaten), Ausstattung, Kapazität, Betten und Betriebsstatus; geschlossene Plätze blendet die Suche aus. Bei jedem Platz gibt es „Feedback / Angaben ergänzen“, in der Suche „Lagerplatz fehlt? Melden“; die Administration prüft die Rückmeldungen. Die App bringt einen Stand mit, übernimmt ihn bei der Installation und gleicht danach wöchentlich mit GitHub ab; unter Verwaltung → Lagerplaner lässt sich das abschalten oder sofort abgleichen. Eigene Listen lassen sich als Datei hochladen. Die Administration kann Angaben importierter Plätze ergänzen; diese bleiben beim Abgleich erhalten, und die App bereitet eine Rückmeldung an die Campsitemap vor.

```sh
php occ lagerplaner:sites:sync                                              # Quellen jetzt abgleichen
php occ lagerplaner:sites:import /pfad/zur/liste.json                       # eigene Liste
php occ config:app:set lagerplaner home_coordinates --value="50.94,6.96"    # Stammesheim für Entfernungen
```

Aufbau, Abgleich und Format stehen in `docs/site-import.md`. Die Testdaten unter `tests/fixtures` sind erfunden.

## Einstellungen in der Verwaltung

Unter Verwaltung → Lagerplaner stellt die Administration ohne occ ein:

- **Standort des Stammesheims** für Entfernungen in der Lagerplatzsuche: Koordinaten („51.0625, 7.0612“) oder ein Link von OpenStreetMap, Google Maps oder Apple Karten. Adressen werden nicht umgewandelt, weil das einen externen Dienst bräuchte.
- **Stämme mit eigenem Stammesheim**, für Clouds mehrerer Stämme (etwa einer Diözese): Jeder Stamm ist eine Nextcloud-Gruppe. Mitglieder sehen Entfernungen ab ihrem Stammesheim, wer in mehreren Stämmen ist, wählt in der Suche; für alle anderen gilt der Standard-Standort. Die Gruppenadministration eines Stammes ändert dessen Standort selbst in der Lagerplatzsuche.
- **Spielesuche** an oder aus, mit Stand des Katalogs.
- **Quellen des Lagerplatzverzeichnisses** ein- oder ausblenden, mit Anzahl der Einträge und davon mit Koordinaten.

Die Seite lässt sich unter Verwaltung → Administrationsrechte einer Gruppe delegieren, etwa dem Stammesvorstand, ohne volle Administrationsrechte. Die occ-Befehle funktionieren weiterhin.

## Spieleanbindung (Pfadfinder-Spiele.de)

Die Spielesuche ist ohne weitere Einstellung eingeschaltet. Der öffentliche Katalog von Pfadfinder-Spiele.de wird nach Installation und Update im Hintergrund geladen und danach täglich abgeglichen; beides läuft mit dem Nextcloud-Cron. Suchbegriffe, Personen- oder Lagerdaten gehen dabei nicht an Pfadfinder-Spiele.de. Wer keine Verbindung zu Pfadfinder-Spiele.de möchte, schaltet die Spielesuche ab:

```sh
php occ config:app:set lagerplaner games_enabled --value=no
```

Ohne Cron dauert der erste Import beim Öffnen der Spielesuche einige Minuten. Abrufe erfolgen serverseitig. Die Spieltexte stehen unter CC BY-NC-SA 4.0 (Dorothea Schümann, Pfadfinder-Spiele.de); die App zeigt die Namensnennung bei jedem Spiel. Diese Inhaltslizenz gilt unabhängig von der AGPL des Codes.

## Nächste Schritte

Nach den Reviews von 0.6.1 gilt diese Reihenfolge (0.6.2 bis 0.9.0 sind umgesetzt):

| Version | Schwerpunkt |
| --- | --- |
| 1.0.0 | Praxistests und Stabilisierung des Kernumfangs |

Hitobito und Nextcloud Cookbook können parallel entstehen. Vor allem steht ein Test auf einer echten Nextcloud-Instanz aus.

## Integrationstest

`tests/Integration/run.sh` prüft die App in einer echten Nextcloud im Container (Docker und Python 3 nötig); Einzelheiten in `tests/Integration/README.md`.

## Entwicklung

```sh
npm ci
npm run build      # oder: npm run watch
composer install
composer run test
composer run lint
```

## Aufbau

| Ordner | Inhalt |
| --- | --- |
| `lib/Domain` | Fachlogik ohne Nextcloud-Abhängigkeit: Assistent, Neuberechnung, Verpflegung |
| `lib/Db` | Entitäten, Mapper, Herkunftsfelder (`HasProvenance`) |
| `lib/Service` | Anwendungsfälle mit Transaktionen und Zugriffsprüfung |
| `lib/Controller` | JSON-API, Routen in `appinfo/routes.php` |
| `lib/Games`, `lib/Domain/Games` | Spielequelle, Feldzuordnung, Bereinigung der Spieltexte, Suche |
| `lib/BackgroundJob` | Täglicher Abgleich des Spielekatalogs |
| `lib/Access`, `lib/Domain/Access` | Rollen, Berechtigungen, Benutzer und Gruppen |
| `lib/Migration` | Datenbankschema |
| `src` | Oberfläche mit Vue 3 und `@nextcloud/vue` 9 |
| `l10n` | Übersetzungen; Quelltexte der Oberfläche sind englisch |
| `tests/Unit` | Fachlogik, Controller und kompletter Ablauf mit In-Memory-Mappern |
| `tests/fixtures` | Nachgebildete API-Antworten mit erfundenen Spieltexten |
| `data/starter-recipes.json` | Grundrezepte, CC0 1.0 |
| `lib/Domain/Recipes`, `lib/Recipes` | Rezepte, Hochrechnen, Prüfung gegen Menüvarianten |
| `lib/Domain/Shopping` | Einkaufslisten: Zusammenfassen, Warengruppen, Einkaufstermine, Mehrbedarf |
| `lib/Domain/Material` | Material: Gesamtbedarf bei gleichzeitiger Nutzung, Vorschläge aus Spielen |
| `lib/Domain/Sites`, `lib/Command` | Lagerplatzverzeichnis: Datenmodell, Import, Suche; occ-Befehl für den Import |
| `lib/Domain/Templates` | Programm- und Tagesvorlagen |
| `lib/Domain/Cookbook`, `lib/Cookbook` | Cookbook: Zutatenzeilen lesen, recipe.json in beide Richtungen, Zugriff auf die Sammlung |
| `lib/Service/ExportService.php` | druckfertige Unterlagen (Programm, Spiele, Aufgaben, Küche) |
| `docs/` | Importformat für Lagerplätze |

## Wichtige Regeln

- Alles, was der Assistent erzeugt, startet als **Vorschlag**.
- Ein erneuter Assistentenlauf ändert oder löscht nur **unveränderte Vorschläge**. Bestätigte oder bearbeitete Einträge bleiben erhalten. Weicht der neue Entwurf ab, entsteht bei Programmpunkten eine Alternative daneben (Konzept 3.10, 17.6).
- Gelöschte Vorschläge bleiben als Ausschluss gespeichert und werden nicht erneut angelegt, bis die Lagerleitung sie wiederherstellt.
- Die Neuberechnung wird erst als Vorschau gezeigt. Der Prüfschlüssel umfasst Eingaben, Lagerversion und Änderungen. Hat sich etwas geändert, lehnt der Server die Übernahme ab (HTTP 409).
- Allergen- und Unverträglichkeitsfreiheit einer Menüvariante wird nie aus einer Ernährungsweise abgeleitet.
- Ernährungsanforderungen werden als **Bedarfskombinationen** verarbeitet, damit niemand doppelt gezählt wird (Konzept 8.5, 9.8).

## Datenschutz und Aufbewahrung

- **Ernährung und Allergien:** Gespeichert werden nur Anzahlen je Bedarfskombination (etwa „2 Personen glutenfrei“), keine Namen. Sehen und bearbeiten können sie nur Lagerleitung und Küchenteam; Ausdrucke für alle enthalten nur Gerichte. In kleinen Gruppen kann eine Anzahl trotzdem auf einzelne Kinder schließen lassen; behandelt die Angaben deshalb wie Gesundheitsdaten.
- **Aufbewahrung:** Eine automatische Löschfrist gibt es noch nicht. Legt fest, wer nach dem Lager die Ernährungsangaben auf null setzt oder das Lager löscht. Das Löschen eines Lagers entfernt alle seine Daten: Programm, Aufgaben, Material, Küche, Einkauf, Merkliste und Team.
- **Verbindungen nach außen:** zu Pfadfinder-Spiele.de für den öffentlichen Spielekatalog und wöchentlich zu GitHub (raw.githubusercontent.com) für die Schwarzzelt IT Campsitemap; beides abschaltbar unter Verwaltung → Lagerplaner. Personen-, Lager- oder Suchdaten werden nicht übertragen.
- **Lagerplätze:** Die Campsitemap enthält keine Kontaktangaben von Personen; Telefonnummern und E-Mail-Adressen in Freitexten werden auch bei eigenen Importen entfernt.

## Bekannte Einschränkungen

- Die Vorlagenregeln stehen im Code (`DraftGenerator`). Eine bearbeitbare Vorlagenbibliothek folgt.
- Der Assistentenentwurf wird im Browser zwischengespeichert (je Person, 30 Tage), noch nicht serverseitig (`CampWizardDraft`).
- Aufgaben haben noch keine Anhänge; die Deck-Anbindung (Konzept 12.3) folgt.
- Eine lagerübergreifende Inventarverwaltung (Konzept 11.4) gibt es noch nicht; Material gilt je Lager.
- Lagerplätze: noch keine eingebettete Karte und keine Entfernung zu beliebigen Orten, nur vom Stammesheim (je Stamm einstellbar). Postleitzahl und Ort ermittelt der Lagerplaner aus den Koordinaten; Bundesländer fehlen noch, der Bundesland-Filter erscheint deshalb nur bei eigenen Listen. Rückmeldungen erzeugen noch keine Benachrichtigung für die Administration; offene Rückmeldungen zählt die Verwaltungsseite. Die Suche lädt das ganze Verzeichnis; für einige tausend Einträge reicht das, darüber hinaus braucht es eine Suche in der Datenbank.
- Notizen zu Lagerplätzen gibt es je Lager, noch nicht stammesweit.
- PDF entsteht über den Druckdialog des Browsers; eine serverseitige PDF-Datei (etwa zum Ablegen in Nextcloud Files) gibt es noch nicht.
- Neue Teammitglieder werden noch nicht benachrichtigt; das geteilte Lager erscheint bei ihnen in der Navigation. Benachrichtigungen gibt es für zugewiesene Aufgaben und nahende Fristen.
- Alle Mahlzeiten rechnen mit allen Teilnehmenden; einzelne Mahlzeiten lassen sich abwählen („entfällt“), abweichende Personenzahlen je Mahlzeit (Konzept 9.4) folgen.
- Die Warengruppen werden über Stichwörter im Zutatennamen vorgeschlagen und lassen sich bei berechneten Positionen noch nicht ändern.
- Rezepte gelten je Lager; lagerübergreifend sammelt man sie in Nextcloud Cookbook (übernehmen und zurückspeichern).
- Die Portionen je Variante sind Schätzwerte (Personen mal durchschnittlicher Portionsfaktor), weil die Bedarfskombinationen nicht nach Altersstufen getrennt sind.
- Allergenangaben in Rezepten ersetzen nicht die Prüfung der gekauften Produkte. Die Bestätigung „geprüft“ gibt die Küche selbst ab.
- Automatische Löschfristen für Ernährungsangaben nach dem Lager (Konzept 18.5) sind noch nicht umgesetzt; siehe „Datenschutz und Aufbewahrung“.
- Fehlermeldungen des Servers sind nur deutsch.
- `l10n/de_DE` ist vorerst eine Kopie der Du-Fassung.
- In `appinfo/info.xml` ist die Repository-Adresse noch ein Platzhalter (`OWNER`).
- Material eingeplanter Spiele erscheint als Hinweis; Mengen trägt man selbst ein, weil die Website nur Kategorien liefert.

## Lizenz

AGPL-3.0-or-later, siehe `COPYING`.

Die Grundrezepte in `data/starter-recipes.json` sind für dieses Projekt geschrieben und stehen unter CC0 1.0 (gemeinfrei). Rezepte aus fremden Kochbüchern werden nur mit freier Lizenz aufgenommen.
