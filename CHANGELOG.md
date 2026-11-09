# Changelog

Alle bisherigen Stände sind Entwicklungsstände vom 11. Oktober 2026 und nicht veröffentlicht. Ein Veröffentlichungsdatum wird erst mit einem Release im App Store eingetragen.

Seit 0.6.3 gibt es nur noch eine Datenbankmigration (Grundschema). Die Entwicklungsstände bis 0.6.2 wurden nie installiert; ihre Migrationen und die Übernahme älterer Einkaufsstände sind entfallen. Ein Update von diesen Ständen ist nicht vorgesehen. Hinweise unten zur Übernahme alter Daten betreffen nur diese nie installierten Stände.

## 0.17.1 – erste öffentliche Beta

Erste Version im Nextcloud App Store. Die App ist im öffentlichen Test; Rückmeldungen bitte in den Bugtracker. 0.17.0 war ein interner Teststand; die neue Nummer sorgt dafür, dass Nextcloud auch dort aktualisiert (Migrationen, neue Skripte im Browser), wo 0.17.0 schon eingespielt war.

### Behoben (CI auf GitHub)
- Ein Test-Hilfsmethode hieß `status()` und kollidierte mit der finalen Methode `TestCase::status()` von PHPUnit; PHPUnit brach deshalb beim Laden ab. Umbenannt; alle 314 Tests laufen mit PHPUnit 11.5.
- Integrationstest: Skripte werden über `bash` gestartet und brauchen kein Ausführbar-Recht mehr (das unter Windows verloren gehen kann).
- `.gitattributes`: Zeilenenden im Repository immer LF.

### Rückmeldung aus dem Test
- Assistent: Hinweis, wenn für die Kinder und Jugendlichen keine oder nur eine leitende Person eingetragen ist oder mehr als acht Teilnehmende auf eine Leitung kommen (Faustregel, keine Sperre); im Schritt „Teilnehmende“ und in „Entwurf prüfen“.

### Lagerplätze: Schwarzzelt IT Campsitemap als erste Datenquelle
- Die Lagerplatzsuche nutzt die [Schwarzzelt IT Campsitemap](https://github.com/Schwarzzelt-IT/campsites) (`sites.csv`, 192 Plätze im Stand vom 1. November 2026, auch außerhalb Deutschlands). Jeder Platz zeigt „Quelle: Schwarzzelt IT Campsitemap“ mit Link; die Website des Platzes steht getrennt daneben.
- ScoutNet ist vollständig entfernt: Datenstand, Exportskript, Texte. Beim Update löscht eine Migration vorhandene ScoutNet-Plätze, ihre Merklisten-Einträge und Einstellungen.
- Neue Architektur ohne Sonderwege: Datenquelle (`SiteSource`, erste Umsetzung `SchwarzzeltItSource`) → Abgleich (`SiteSynchronizer`, auch für Datei-Importe) → einheitliches Modell → Suche. Weitere Quellen brauchen nur eine neue `SiteSource`.
- Abgleich wöchentlich im Hintergrund (abschaltbar), auf Knopfdruck in der Verwaltung und mit `occ lagerplaner:sites:sync`. Wiederholbar ohne Dubletten; umbenannte Plätze werden über Website oder Lage wiedererkannt; fehlende als „nicht mehr in der Quelle“ markiert. Ein mitgelieferter Stand füllt das Verzeichnis ohne Netzzugang.
- Herkunft einzelner Angaben: Die Administration ergänzt Kapazität, Betten, Ausstattung und Preis importierter Plätze mit Beleg; die Detailansicht nennt die Herkunft. Solche Angaben überschreibt der Abgleich nicht. Vorbereitet für automatisch von der Website erkannte Angaben mit Sicherheit und Prüfdatum.
- Rückmeldung an die Campsitemap: Die Verwaltung zählt lokal neue Plätze und Korrekturen, bereitet einen Issue-Text im CSV-Format vor und markiert ihn als gemeldet. Die App ändert das Repository nicht selbst.
- Unbekannte Angaben bleiben `null`; Filter zeigen sie nur mit „Auch Plätze zeigen, bei denen diese Angaben unbekannt sind“. Kein Land wird mehr voreingestellt.

### Angereicherte Daten und Rückmeldungen
- Mitgeliefert: Lagerplaner-Anreicherung für alle 192 Plätze des aktuellen Index (Stand 1. November 2026): Postleitzahl, Ort und Land aus den Koordinaten, Ausstattung, Kapazität, Betten, Betriebsstatus mit Wirksamkeit, offene Prüfungen. Entfernte Plätze und Dubletten des alten Stands sind nicht mehr enthalten. Übernahme bei Installation und Update als Angaben „laut Website“ mit Prüfdatum, Beleg und Sicherheit; von Hand gepflegte Angaben haben Vorrang.
- Betriebsstatus: geschlossene Plätze sind in der Suche ausgeblendet („Auch geschlossene Plätze zeigen“), bei schließenden, vorübergehend nicht verfügbaren und unklaren Plätzen steht ein Hinweis.
- „Feedback / Angaben ergänzen“ bei jedem Platz: Angaben stimmen nicht mehr, Website geändert, Ausstattung, Kapazität, Platz geschlossen; in der Suche „Lagerplatz fehlt? Melden“. Die Administration übernimmt, erledigt oder lehnt ab; Übernommenes fließt in die Rückmeldung an die Campsitemap.

### Abgrenzung Campsitemap und Lagerplaner (nach Rückmeldung von Schwarzzelt IT)
- Mitgelieferter Index auf den aktuellen Stand von Schwarzzelt IT gezogen (192 Einträge, mit den dort übernommenen Korrekturen); die Anreicherung ist darauf neu ausgerichtet.
- Der direkte Knopf „Fehler an … melden“ (GitHub-Issue) entfällt; Rückmeldungen laufen nur über „Feedback“, Prüfung und „Mögliche Korrekturen für die Campsitemap“.
- Die Campsitemap ist nur Stammdatenquelle: Name, Kategorie, Koordinaten, Betreiber-/Infoseite. Alles andere (Postleitzahl und Ort, Kapazität, Betten, Ausstattung, Betriebsstatus, Hinweise) ist Lagerplaner-eigene Anreicherung; der Abgleich schreibt diese Angaben nicht.
- Die Anreicherung korrigiert keine Stammdaten mehr (eine Weiterleitung auf eine Unterseite ist keine URL-Korrektur, eine zusätzliche Zeltwiese ändert die Kategorie nicht). Frühere Korrekturen daran werden zurückgenommen; der nächste Abgleich holt die Stammdaten vollständig von der Campsitemap.
- Vorrang: von Hand (Administration) vor übernommener Rückmeldung vor Anreicherung vor Campsitemap. Stammdaten aktualisiert die Campsitemap wieder, außer bei einer bewussten Korrektur von Hand.
- Herkunftsarten: Quelle, Website, Geodaten (Postleitzahl und Ort aus den Koordinaten), Rückmeldung, von Hand. Geänderte Herkunft (Methode, Beleg, Sicherheit, Prüfdatum) wird auch bei gleichem Wert übernommen.
- Rückmeldungen sind eingeordnet (`upstream_relevant`): Schließung, neuer Platz, falscher Name, falsche Lage, Website, Betreiberwechsel betreffen die Campsitemap; Ausstattung, Kapazität, Betten, Postleitzahl und Ort nicht. Neue Arten: Name falsch, Lage falsch, Postleitzahl/Ort falsch, Betreiber gewechselt. Die Administration kann die Einordnung beim Übernehmen ändern.
- „Mögliche Korrekturen für die Campsitemap“ statt Rückgabe aller angereicherten Angaben: nur geprüfte, übernommene Rückmeldungen mit `upstream_relevant`, als Text mit Zeilen im Format der `sites.csv`.
- Bearbeiten eigener Plätze behält Koordinaten und Land, kennt „teilweise vorhanden“ und speichert 0 Betten.
- Bundesland-Filter nur, wenn es Bundesländer gibt.
- Koordinaten auf 7 Nachkommastellen (etwa 1 cm), damit ein unveränderter Abgleich auch auf echter Datenbank nichts schreibt.

### Nach Code-Review
- Kapazität und Betten aus Formularen kommen als Text („50“) oder Zahl an und gehen nicht mehr verloren; 0 Betten ist eine gültige Angabe.
- Postleitzahl, Ort und Land aus der Anreicherung (eigene Sicherheit der Ortsangabe); die Suche nach Postleitzahl und Ort findet die Plätze der Campsitemap.
- „Teilweise vorhanden“ ist ein eigener Zustand (Datenbank: 2). Ein Filter gilt damit nicht als erfüllt: Der Platz erscheint nur mit „Auch Plätze zeigen, bei denen diese Angaben unbekannt sind“ und ist als „Nur teilweise“ gekennzeichnet.
- Vorrang: Von Hand korrigierte Angaben bleiben immer. Angaben „laut Website“ bleiben, solange die Campsitemap unverändert ist oder nichts liefert; ändert die Campsitemap einen Wert, gilt sie wieder (`lp_site_facts.source_value`).
- Neue Plätze legt nur die Administration direkt an; alle anderen melden sie über „Lagerplatz fehlt? Melden“. Übernommene Meldungen werden ohne Eigentümer von der Administration verwaltet; wer gemeldet hat, steht als Herkunft dabei.
- Merklisten lösen zusammengeführte Dubletten auf den kanonischen Platz auf, beim Hinzufügen, beim Anzeigen und beim Zusammenführen.
- Datumsangaben müssen echte Kalenderdaten sein; eine fehlende Sicherheit bleibt leer statt „mittel“; die Statistik der Anreicherung zählt auch geänderte Prüfhinweise.
- Node 24 für Build und CI (verlangt von `@nextcloud/files` über `@nextcloud/dialogs`); die ungenutzte direkte Abhängigkeit `@nextcloud/files` ist entfernt.

### Behoben
- Neue Datensätze speicherten Felder mit Vorgabewert nicht, wenn der Setter denselben Wert setzte (Nextcloud markiert nur geänderte Felder). Auf SQLite, PostgreSQL und MariaDB im strikten Modus schlug deshalb etwa das Anlegen eines Lagers fehl. Alle Entitäten speichern ihre Vorgabewerte jetzt beim Einfügen.

### Datenbank
- `lp_sites`: Ausstattung in eigenen Spalten `feature_water`, `feature_sanitary`, `feature_kitchen`, `feature_electricity`, `feature_fireplace`, `feature_accessible` (aus `features_json` übertragen, das danach entfällt); `country` optional; neu `reported_at`.
- Neue Tabellen `lp_site_sources` (Quellen mit Name, Link, letztem Abgleich) und `lp_site_facts` (Herkunft einzelner Angaben, Rückmeldestatus).
- `lp_sites`: Betriebsstatus mit `status_from` und `status_until`, `review_note`, `capacity_note`, `merged_into`; `lp_site_facts.note`.
- Neue Tabellen `lp_site_feedback` und `lp_site_feedback_values` für Rückmeldungen.
- `lp_site_facts.source_value`: Wert der Quelle bei Angaben „laut Website“.
- `lp_site_feedback`: `upstream_relevant`, `reported_at`.
- Migrationen `Version001200Date20261031120000`, `Version001201Date20261031120100`, `Version001202Date20261101120000`, `Version001203Date20261102120000` und `Version001204Date20261103120000`.

### Veröffentlichung
- Beschreibung, Screenshots und Links für den App Store, auf Deutsch und Englisch; Lizenzangabe als `AGPL-3.0-or-later`.
- `tools/release.sh` baut das Store-Paket nur aus Laufzeitdateien, signiert es und erzeugt die Store-Signatur; `tools/set-repository.sh` trägt Repository und Herausgeber ein.
- Ein Paket mit dem mitgelieferten Stand einer Quelle entsteht nur, wenn in `data/sites/README.md` das Datum der Zustimmung steht (`Zustimmung <quelle>: JJJJ-MM-TT`).
- Integrationstests für Nextcloud 33, 34 und 35 sind Pflicht.

### Dokumentation
- README: Abschnitt „Datenschutz und Aufbewahrung“ zu Ernährungsangaben, Löschung und Verbindungen nach außen; überholte Einschränkungen entfernt.

## 0.16.0 – Entwicklungsstand, nicht veröffentlicht

### ScoutNet-Plätze mit Koordinaten, ohne occ
- Mitgeliefert ist der ScoutNet-Stand vom 19. Oktober 2026: 322 Plätze, 288 davon mit Koordinaten aus den Karten der ScoutNet-Detailseiten. Damit zeigt die Lagerplatzsuche für die meisten Plätze Entfernungen.
- Geprüft wurde, ob die Koordinaten zu Bundesland und Postleitzahl passen. Beim Pfadfinderhaus Nöggenschwiel zeigt die ScoutNet-Karte auf Weilheim in Oberbayern statt Weilheim (Baden); dort sind die Koordinaten entfernt.
- Unter Verwaltung → Lagerplaner: „Mitgelieferte Datenstände“ mit Stand und „Jetzt übernehmen“, und „Datenstand hochladen“ für eigene Dateien im Format lagerplaner-sites/1 (bis 10 MB). occ ist dafür nicht mehr nötig.
- Bei Installation und Update übernimmt die App den neuen mitgelieferten Stand weiterhin automatisch, auch über die Update-Seite im Browser.

## 0.15.0 – Entwicklungsstand, nicht veröffentlicht

### Neu: Stammesheime je Stamm
- Für Clouds mehrerer Stämme, etwa einer Diözese: Unter Verwaltung → Lagerplaner legt die Verwaltung Nextcloud-Gruppen als Stämme an, mit Namen und Standort des Stammesheims (Koordinaten oder Kartenlink). Der bisherige Standort bleibt als Standard für alle ohne eigenen Stamm.
- Die Lagerplatzsuche und die Merkliste rechnen Entfernungen ab dem Stammesheim der eigenen Gruppe. Wer in mehreren Stämmen ist, wählt unter „Entfernung ab“; der Browser merkt sich die Wahl.
- Die Gruppenadministration eines Stammes (Nextcloud-Gruppenadmin) ändert den Standort ihres Stammes selbst in der Lagerplatzsuche, ohne Zugang zur Verwaltung. Welche Gruppen Stämme sind, legt nur die Verwaltung fest.

## 0.14.0 – Entwicklungsstand, nicht veröffentlicht

Rückmeldungen aus dem ersten Praxistest.

### Programm
- Verschieben per Ziehen: Programmpunkte und Mahlzeiten lassen sich auf eine andere Zeile ziehen und übernehmen deren Startzeit; eine Ablagezone nimmt sie auch am Tagesende auf. Was sich danach überschneidet, rutscht nach, bis eine Lücke die Verschiebung auffängt. Beispiel: Morgenrunde vor das Frühstück um 8:00 ergibt Frühstück 8:15, das Vormittagsprogramm um 9:30 bleibt.
- Parallel laufende Punkte anderer Altersstufen bleiben stehen; Schlechtwetteralternativen wandern mit ihrem Programmpunkt; Mahlzeiten unterwegs werden weder geschoben noch schieben sie. Über Mitternacht hinaus wird nicht verschoben.
- Jede Verschiebung meldet, wie viele Punkte mitgerutscht sind, und lässt sich rückgängig machen. Ohne Maus: „15 Minuten früher/später“ im Aktionsmenü, auch für Mahlzeiten.
- Lücken ab 30 Minuten, in denen für niemanden etwas geplant ist, erscheinen als „Freie Zeit“.
- Hinweis am Programmpunkt, wenn die eingeplanten Spiele zusammen länger dauern als der Programmpunkt.

### Essenszeiten
- In der Küche unter „Essenszeiten“ eine Mahlzeitart für alle Tage auf eine neue Uhrzeit setzen, mit derselben Zeitanpassung und Rückgängig. Die Küche verschiebt dabei nur Mahlzeiten; Programmpunkte rutschen nur mit, wenn man das Programm bearbeiten darf.

### Assistent
- Tagesveranstaltungen haben Beginn und Ende (ohne Angabe 9:30 bis 17:30, mindestens drei Stunden); Mahlzeiten und Programmblöcke passen sich an.
- Im Gruppenhaus gibt es keine Schlechtwetteralternativen. Der Hinweis zu Schlechtwetteralternativen entfällt; beim Zeltlager bleibt die Aufgabe.
- „Entwurf prüfen“ zeigt nur die eingegebenen Daten und die Hinweise, keinen Beispieltag.
- Die Aufgabe „Angaben des Lagerplatzes beim Betreiber bestätigen lassen“ ist in „Lagerplatz anfragen und verbindlich buchen“ aufgegangen.

### Lagerplatzsuche
- Die Ergebnisliste zeigt die volle Anschrift (Straße, Postleitzahl, Ort, Bundesland).
- Findet eine fünfstellige Postleitzahl keinen Platz, zeigt die Suche Plätze aus der PLZ-Region (erst drei, dann zwei Ziffern), mit Hinweis.
- Test mit den echten ScoutNet-Daten: Suche nach Postleitzahl und Ort findet die Plätze.

## 0.13.0 – Entwicklungsstand, nicht veröffentlicht

### Neu: Einstellungen in der Nextcloud-Verwaltung
- Abschnitt „Lagerplaner“ unter Verwaltung: Standort des Stammesheims, Spielesuche an oder aus, Quellen des Lagerplatzverzeichnisses ein- oder ausblenden. Dafür braucht niemand mehr occ.
- Das Stammesheim lässt sich als Koordinaten oder als Link von OpenStreetMap, Google Maps oder Apple Karten eintragen; bei Google zählt die Ortsmarke, nicht die Kartenmitte. Adressen werden nicht umgewandelt (kein externer Dienst); dann erscheint ein Hinweis, und der gespeicherte Standort bleibt.
- Delegierbar: Die Administration kann den Abschnitt einer Gruppe freigeben, etwa dem Stammesvorstand, ohne volle Administrationsrechte.
- Die Quellenübersicht zeigt Einträge, davon mit Koordinaten und nicht mehr in der Quelle, sowie den mitgelieferten Datenstand.

### Exportskript für ScoutNet: Koordinaten aus der Karte
- `tools/scoutnet_export.py` liest Koordinaten aus der eingebetteten Karte der Detailseite: OpenStreetMap- und Google-Maps-Einbettungen, Kartenlinks, Leaflet- und OpenLayers-Skripte, Geo-Metadaten und data-Attribute. Übernommen werden nur Werte in Deutschland; offensichtlich vertauschte Breite und Länge werden gedreht.
- `--offline` erzeugt den Datenstand allein aus dem Zwischenspeicher, ohne Anfrage an scoutnet.de. `--inspect <ID>` zeigt die Kartenstellen einer gespeicherten Seite.

## 0.12.0 – Entwicklungsstand, nicht veröffentlicht

### Lagerplätze von ScoutNet mitgeliefert
- Die App bringt den ScoutNet-Datenstand vom 16. Oktober 2026 mit: 322 Häuser und Zeltplätze aus allen Bundesländern, ohne Kontaktangaben von Personen (`data/sites/scoutnet.json`).
- Ein Reparaturschritt übernimmt mitgelieferte Datenstände bei Installation und Update, sobald sich ihre Version geändert hat. Ein später von Hand eingespielter Stand bleibt erhalten, bis eine App-Version einen neuen mitbringt.
- Test: Alle 322 Einträge gehen durch den Import, haben eindeutige Kennungen und enthalten keine Kontaktdaten.

### Spielesuche immer eingeschaltet
- Die Spielesuche mit Pfadfinder-Spiele.de ist ohne Einstellung eingeschaltet; der Katalog wird nach Installation und Update im Hintergrund geladen. Abschalten: `occ config:app:set lagerplaner games_enabled --value=no`.

### Testwerkzeuge
- `setup-testdata.sh` legt die erfundenen Plätze unter der Quelle `testdaten` an, damit sie die ScoutNet-Plätze nicht als „nicht mehr in der Quelle“ markieren; `GAMES=no` schaltet die Spielesuche ab. Der automatische Integrationstest läuft ohne Spielesuche.

## 0.11.0 – Entwicklungsstand, nicht veröffentlicht

### Neu: Ankunft und Abreise mit Uhrzeit
- Der Assistent fragt bei mehrtägigen Lagern nach Ankunft (6 bis 20 Uhr) und Abreise (10:30 bis 18 Uhr). Ohne Angabe bleibt der bisherige Ablauf (10 und 14 Uhr).
- Erster Tag ab der Ankunft: Mahlzeiten erst ab 45 Minuten nach der Ankunft, abends kürzerer Aufbau; passt das Kennenlernen nicht mehr in den Nachmittag, geht es in der Lagereröffnung auf. Ab einer Ankunft gegen 19 Uhr entfällt das Abendessen mit Hinweis.
- Letzter Tag bis zur Abreise: Der Abbau endet vor Mittagessen oder Abschlussrunde; reicht die Zeit nicht, beginnt er direkt nach dem Frühstück, mit Hinweis. Bei später Abreise kommt ein Abschlussprogramm dazu.
- Geprüft: Alle 1856 Kombinationen aus Ankunft, Abreise (Halbstundentakt), Zelt oder Haus, mit und ohne Zwischenmahlzeit ergeben keine Überschneidung.

### Neu: Mahlzeiten im Programm
- Mahlzeiten stehen in der Programmansicht zwischen den Programmpunkten, mit Gericht und Hinweisen wie „unterwegs“ oder „entfällt“, für alle im Team und ohne Varianten oder Ernährungsangaben.

### Neu: Einfacher Speiseplan
- Option im Assistenten (vorab angekreuzt) und Knopf „Speiseplan vorschlagen“ in der Küche: Jede selbst gekochte Mahlzeit bekommt ein Gericht für alle. Mittags wechseln sich warme Gerichte ab, abends gibt es Abendbrot nach einem warmen Mittagessen, sonst warm; unterwegs ein Lunchpaket.
- Wer zum Gericht nicht passt, bekommt eine eigene Variante mit einem passenden Gericht, etwa „Glutenfrei“ mit Reis und Gemüsepfanne. Allergenangaben bleiben ungeprüft, bis die Küche sie bestätigt.
- Gericht direkt in der Mahlzeitenliste wechseln; die Varianten öffnen sich nur noch für Sonderfälle.
- Menüvarianten kennen eine Variante „für alle“: Wer keiner anderen zugeordnet ist, isst sie; die Prüfung meldet weiterhin, wenn jemand dort nicht hinpasst.
- Acht neue Grundrezepte (CC0): Gemüseeintopf, Reis mit Gemüsepfanne, Wraps mit Bohnen und Gemüse, Frühstück mit Brot und Müsli, Abendbrot, Brotzeit glutenfrei, Lunchpaket, Obst und Reiswaffeln.
- Ein erneuter Assistentenlauf plant neue Mahlzeiten ebenfalls ein; von der Küche gewählte Gerichte bleiben.

### Neu: Mahlzeit entfällt
- Einzelne Mahlzeiten lassen sich in der Küche abwählen, etwa wenn alle ein Lunchpaket mitbringen. Sie zählen nicht für Mengen und Einkauf, machen die Einkaufsliste nicht vorläufig und stehen im Programm und in Ausdrucken als „entfällt“.

### Außerdem
- `tools/scoutnet_export.py`: einmaliger Abzug der Häuser und Zeltplätze von scoutnet.de im Importformat, ohne Kontaktdaten.
- Lagerplätze: Der Import entfernt auch verschleierte E-Mail-Adressen wie „name(at)domain.de“ oder „name [at] domain(dot)de“.
- Rauchtest: Option `--header` für einen vorgeschalteten Verzeichnisschutz, Option `--password`; neue Prüfungen für Speiseplan und Mahlzeiten.
- Behoben: Beim erneuten Ausführen des Assistenten brach die Oberfläche ab, wenn keine Ankunftszeit gespeichert war.

## 0.10.0 – Entwicklungsstand, nicht veröffentlicht

### Neu: Nextcloud Cookbook (Konzept 9.6)
- „Aus Cookbook“ im Rezeptbereich der Küche: Rezepte der eigenen Cookbook-Sammlung durchsuchen, mit Vorschau ansehen und ins Lager übernehmen.
- Zutaten in Freitext („500 g Mehl“, „1/2 TL Zimt“, „1 ½ l Milch“, „4 cl Rum“, „2 Zehen Knoblauch, gepresst“) werden in Menge, Einheit, Name und Anmerkung gelesen. Unsichere Zeilen wie Mengenbereiche („2-3 Zwiebeln“, übernommen mit der größeren Menge) oder Zeilen ohne Menge sind mit dem Originaltext zur Prüfung markiert.
- Sichere Voreinstellungen: Cookbook kennt keine Allergene, übernommene Rezepte gelten deshalb als ungeprüft. Vegetarisch oder vegan nur bei eindeutigen Stichwörtern, sonst „mit Fleisch oder Fisch“. Fehlt die Portionenzahl, gelten 4 Portionen mit Hinweis.
- Verknüpfung: Das Lager arbeitet mit einer Kopie. Ändert sich das Rezept in Cookbook, steht „in Cookbook geändert“ mit „Aktualisieren“ daneben. Beim Aktualisieren bleiben Ernährungsweise und Allergene aus dem Lager erhalten, die Bestätigung der Allergene entfällt. Aktualisieren kann, wer das Rezept aus der eigenen Sammlung übernommen hat.
- „In Cookbook speichern“: Lagerrezepte, auch Grundrezepte, kommen als recipe.json in die eigene Sammlung und stehen für künftige Lager bereit. Das Format ist so gewählt, dass Cookbook die Datei beim Einlesen nicht umschreibt.
- Zugriff nur auf die Sammlung der handelnden Person über die Datei-Schnittstellen von Nextcloud; keine internen Klassen von Cookbook. Ohne Cookbook oder ohne Rezeptordner erklärt die Oberfläche, was fehlt.
- Integrationstest: Mit `COOKBOOK=yes` installiert das Testskript Cookbook; der Rauchtest legt per WebDAV ein Rezept an, übernimmt es und speichert eines zurück.

## 0.9.1 – Entwicklungsstand, nicht veröffentlicht

### Neu: Integrationstest gegen eine echte Nextcloud
- `tests/Integration/run.sh` startet Nextcloud im Container (MariaDB oder PostgreSQL), aktiviert die App, legt Testdaten an und prüft die Kernabläufe über die API: Lager, Team und Rechte, Aufgaben mit Benachrichtigung und Fristerinnerung, Material, Lagerplätze und Merkliste, Vorlagen, geschützte Küchenunterlagen, erneuter Assistentenlauf. Fehler des Lagerplaners im Nextcloud-Log lassen den Test scheitern.
- `tests/Integration/setup-testdata.sh` legt Testpersonen, Gruppen und erfundene Lagerplätze an, auch für einen manuellen Testserver.
- GitHub Actions: Nextcloud 33 und 34 mit MariaDB und PostgreSQL; Nextcloud 35 läuft mit, darf aber scheitern, bis es ein offizielles Image gibt.

## 0.9.0 – Entwicklungsstand, nicht veröffentlicht

### Neu: Unterlagen zum Drucken und als PDF (Konzept 19)
- Menü „Drucken“ im Lager: gesamtes Lagerprogramm, Programm eines Tages, Programm einer Altersstufe, Spielanleitungen mit Urheber- und Lizenzhinweis, Aufgabenübersicht; für Lagerleitung und Küche zusätzlich Speiseplan (mit Menüvarianten und Bedarfskombinationen, ohne Namen) und Rezepte mit Mengen für das Lager.
- Der Server erzeugt druckfertige A4-Dokumente; der Druckdialog des Browsers speichert sie als PDF.
- Allgemeine Unterlagen nennen Mahlzeiten nur mit Art und Uhrzeit (Konzept 19.3); Küchenunterlagen prüft der Server gegen die Berechtigung.
- Packliste und Einkaufslisten bleiben in ihren Reitern; der Knopf der Einkaufsliste heißt jetzt „Einkaufslisten drucken“.

### Neu: Vorlagen (Konzept 6.4)
- Programmpunkte (mit ihrem Material) und ganze Tage als Vorlage speichern, mit allen in der Nextcloud teilen und in jedes Lager einfügen.
- Keine personenbezogenen Daten: keine Verantwortlichen, Aufgaben oder Ernährungsangaben. Punkte für Altersstufen, die im Ziellager fehlen, entfallen beim Einfügen.
- Versionen: Eine Vorlage lässt sich aktualisieren; eingefügte Punkte tragen ihre Herkunft mit Version.
- „Vorlagen“ in der Navigation: Übersicht, Inhalt ansehen, umbenennen, teilen, löschen.

### Verbesserter Planungsassistent
- Tagesvorlage für normale Lagertage: Der Assistent übernimmt den eigenen Tagesablauf; Wanderung, Geländespiel und freier Nachmittag behalten ihre Tage, Mahlzeiten werden wie gewohnt geplant. Ein erneuter Lauf erkennt geänderte Vorlagenversionen.
- Konkrete Schlechtwetteralternativen für Programmpunkte im Freien (Workshops, Stationenspiel, Spieleabend, kürzere Runde) statt des Hinweises „fehlt“.

## 0.8.0 – Entwicklungsstand, nicht veröffentlicht

### Neu: Lagerplatzverzeichnis (Konzept 5)
- „Lagerplätze“ in der Navigation: Zeltplätze und Gruppenhäuser suchen nach Name, Ort, Postleitzahl, Bundesland, Art, Personen, Betten und Ausstattung (Trinkwasser, Sanitär, Küche, Strom, Feuerstelle, Barrierefreiheit).
- Unbekannte Angaben bleiben unbekannt: Sie schließen einen Platz nicht aus, sondern werden je Treffer genannt („Unbekannt: Kapazität“); auf Wunsch blendet die Suche solche Plätze aus.
- Detailansicht mit Quellenangabe nach Konzept 5.6: Quelle, ursprünglicher Eintrag, Stand laut Quelle, Importdatum und Datenstand, Betreiberbestätigung. Angaben älter als ein Jahr und nicht mehr in der Quelle enthaltene Einträge sind deutlich gekennzeichnet. Preise erscheinen als unverbindlicher Text. Hinweis, dass ein Eintrag nichts über die Verfügbarkeit sagt.
- Entfernung als Luftlinie vom Stammesheim, wenn die Administration dessen Koordinaten hinterlegt; Link zu OpenStreetMap je Platz.
- Eigene Plätze anlegen (Konzept 5.10), sichtbar für alle in der Nextcloud; ändern dürfen die anlegende Person und die Administration.
- Import von Datenständen im neutralen Format `lagerplaner-sites/1` mit `occ lagerplaner:sites:import` (docs/site-import.md). Stabile IDs über Importe hinweg, fehlende Einträge werden gekennzeichnet statt gelöscht, ein Datenstand ohne gültigen Eintrag wird abgelehnt. E-Mail-Adressen und Telefonnummern in Freitexten werden entfernt. Quellen lassen sich abschalten (`sites_disabled`).
- Vorbereitet für ScoutNet: Sobald Nutzungsrechte und Schnittstelle feststehen, übersetzt ein Adapter in dieses Format.

### Neu: Merkliste im Lager (Konzept 5.9)
- Reiter „Lagerplatz“: Kandidaten sammeln (Suche mit vorbelegter Personenzahl und Lagerart), nebeneinander vergleichen, bevorzugte Option markieren, Anfragestatus von „interessant“ bis „verbindlich gebucht“ oder „abgesagt“, eigene Notizen je Platz.
- Plätze, die für die Personenzahl zu klein sind, werden markiert.
- „Als Lagerort festlegen“ übernimmt Name und Region ins Lager; ein erneuter Assistentenlauf behält diesen Ort.
- Pflegen darf die Lagerleitung; alle anderen im Team sehen die Merkliste.

### Geändert
- Testaufbau: Der nachgebildete Speicher vergibt IDs je Tabelle wie eine echte Datenbank.

## 0.7.0 – Entwicklungsstand, nicht veröffentlicht

### Neu: Material und Packliste (Konzept 11)
- Reiter „Material“ für alle im Team. Ein Materialposten hat Gruppe (Zelte und Planen, Seile, Werkzeug, Bastel-, Spiel-, Küchenmaterial, Erste Hilfe, Sonstiges), Einheit, Status (benötigt, vorhanden, gepackt, transportiert, zurückgebracht, fehlt oder beschädigt), verantwortliche Person und Notiz.
- Einsätze für einzelne Programmpunkte oder das ganze Lager, jeweils mit Menge oder „Menge offen“.
- Gesamtbedarf nach Konzept 11.3: Verbrauchsmaterial wird addiert; Wiederverwendbares zählt nur so oft, wie es gleichzeitig gebraucht wird (höchste gleichzeitige Nutzung plus lagerweiter Bedarf).
- Vorschläge aus eingeplanten Spielen (etwa „Papier & Stifte“): übernehmen oder als „nicht nötig“ verwerfen; Verworfenes kommt nicht wieder. Gleichnamiges Material wird zusammengeführt.
- Filter nach Status und eigener Verantwortung; Packliste drucken.
- Programmpunkte zeigen ihr eingeplantes Material.
- Rechte: Planen dürfen Lagerleitung, Material- und Programmteam; den Status setzen alle außer Lesezugriff.

### Neu: Verantwortliche für Aufgaben (Konzept 12.1)
- Bis zu zehn Verantwortliche je Aufgabe aus allen Personen mit Zugriff auf das Lager, auch über Gruppen.
- Priorität (wichtig, normal, kann warten), Beschreibung und Zuordnung zu einem Programmpunkt.
- Nextcloud-Benachrichtigung an neu Zugewiesene; Erinnerung zwei Tage vor der Frist, einmal je Frist (Hintergrundjob). Erledigte und gelöschte Aufgaben räumen ihre Benachrichtigungen ab.
- „Übernehmen“: Mitarbeitende können eine Aufgabe selbst übernehmen. Festgehalten wird, wer eine Aufgabe erledigt hat.
- Filter: alle, meine, ohne Verantwortliche oder je Person; erledigte ein- und ausblenden. Überschrittene Fristen sind markiert.
- Links aus Benachrichtigungen öffnen direkt den Aufgaben-Reiter des Lagers.

### Geändert
- Programmpunkte mit Material oder zugeordneten Aufgaben gelten für den Assistenten als bearbeitet, wie Programmpunkte mit Spielen. Wird ein Programmpunkt endgültig gelöscht, entfallen seine Materialeinsätze; zugeordnete Aufgaben bleiben ohne Verweis bestehen.
- Neuer Migrationsschritt für Material und Aufgabenfelder (aufbauend auf dem Grundschema aus 0.6.3).

## 0.6.3 – Entwicklungsstand, nicht veröffentlicht

Korrekturen aus dem Review der allgemeinen Lagerplanung von 0.6.1. Jeder Befund hat einen Regressionstest.

### Behoben
- **Zeitkonflikte im Assistenten (P2):** Programmblöcke dürfen höchstens so lang sein, wie bis zum Mittagessen Platz ist (180 Minuten, an eintägigen Lagern 150). Ein Test sichert ab, dass die längsten erlaubten Blöcke in keiner Kombination aus freier Zeit, Lagerdauer und getrennten Gruppenprogrammen Überschneidungen erzeugen. Der Assistent prüft seinen Entwurf zusätzlich mit dem Zeitachsen-Prüfer und meldet Überschneidungen als Hinweis.
- **Zeitkonflikte in der Programmplanung (P2):** Überschneidungen innerhalb einer Gruppe oder mit Mahlzeiten erscheinen am Programmpunkt, etwa „Überschneidet sich mit: Mittagessen 12:30–13:00“. Parallele Programme verschiedener Gruppen, Schlechtwetteralternativen und Lunchpakete unterwegs gelten nicht als Konflikt. Gewollte Überschneidungen bleiben möglich.
- **Entfernte Altersstufen (P2):** Hat eine Altersstufe künftig keine Teilnehmenden mehr (entfernt oder auf 0 gesetzt), listet die Vorschau ihre bestätigten und manuellen Programmpunkte. Die Lagerleitung entscheidet je Punkt: anderer Gruppe zuordnen, für alle Gruppen oder entfernen. Ohne vollständige Entscheidung wird nichts übernommen. Programmpunkte, die noch auf eine entfernte Gruppe verweisen, zeigen „entfernte Gruppe“ statt der technischen Kennung.
- **Spiele ohne Altersangabe (P2):** Bei gesetztem Altersfilter als „Alterseignung unbekannt“ gekennzeichnet; sie lassen sich ausblenden.
- **Erstimport der Spieldatenbank (P2):** läuft als Hintergrundaufgabe statt im Aufruf der Suche. Die Suche zeigt währenddessen einen Hinweis und aktualisiert sich selbst; ein veralteter Katalog bleibt nutzbar, während er im Hintergrund aktualisiert wird.
- **Wiederherstellen gelöschter Vorschläge (P2):** Festgelegt und dokumentiert: Das darf, wer das Programm bearbeiten darf (Lagerleitung und Programmteam), weil diese Rollen auch löschen dürfen.
- **Gruppen mit Rolle Lagerleitung:** Die Teamverwaltung weist darauf hin, dass alle jetzigen und künftigen Gruppenmitglieder volle Rechte erhalten, auch zum Löschen des Lagers.
- Mahlzeiten unterwegs (Lunchpaket) werden als solche gespeichert, damit sie nicht als Konflikt mit der Wanderung gelten.
- Ein Assistentenlauf mit fehlenden Entscheidungen lässt die Vorschau gültig; erst nach vollständiger Prüfung wird das Lager gesperrt und geschrieben.

## 0.6.2 – Entwicklungsstand, nicht veröffentlicht

Stabilitätsstand nach dem Review der Küchen- und Einkaufslogik von 0.6.1 und dem P1-Befund aus dem Review der allgemeinen Lagerplanung. Jeder Befund hat einen Regressionstest.

### Behoben
- **Einkäufe beim richtigen Termin (P1):** Gekauftes deckt zuerst die Position seines eigenen Einkaufstermins. Ein Überschuss wird nur auf spätere Termine übertragen und dort als „Aus früherem Einkauf“ ausgewiesen; was später gekauft wird, deckt nie eine frühere Mahlzeit.
- **Keine prozentuale Toleranz (P1):** Als gedeckt gilt nur, was tatsächlich gedeckt ist; toleriert werden allein Rechenungenauigkeiten. Bei 50 kg Bedarf und 49,2 kg Einkauf fehlen 800 g.
- **Varianten ohne Rezept (P1):** Hat eine Menüvariante Personen, aber kein Rezept, meldet die Küchenplanung das, und die Einkaufsliste ist vorläufig. Ebenso, solange selbst gekochte Mahlzeiten noch keine Menüvarianten haben.
- **Schwere Allergien in der Einkaufsprüfung (P1):** Widersprüche zwischen gemeldeten schweren Allergien und Bedarfskombinationen machen die Einkaufsliste vorläufig.
- **Eingeplante Spiele bei der Neuberechnung (P1, allgemeines Review):** Wer ein Spiel einplant, bearbeitet den Programmpunkt; der Assistent ändert oder entfernt ihn nicht mehr. Das gilt auch für Programmpunkte aus 0.6.1, die schon ein Spiel haben. Entfernt der Assistent einen Programmpunkt, werden seine Spielverknüpfungen mit entfernt.
- **Positionsschlüssel (P2):** Hash der Produkteigenschaften, 40 Zeichen; keine Längenprobleme und keine Verwechslung durch Trennzeichen in Namen oder Hinweisen.
- **Korrigierbarkeit nach dem Löschen eines Termins (P2):** Einkäufe wandern mit den eigenen Posten zum vorherigen Termin und lassen sich dort aufheben. Abgehakte Posten, die an ihrem Termin nicht mehr eingeplant sind, erscheinen dort als „Hier gekauft, nicht mehr eingeplant“.
- **Altbestände (P2):** Einkaufsstände und Vorräte aus 0.6.0 und 0.6.1 werden übernommen, zählen aber erst nach Bestätigung. Nicht eindeutig lesbare lassen sich nur verwerfen.
- **Geänderter Lagerzeitraum (P2):** Der unbearbeitete Standard-Großeinkauf zieht mit; die Vorschau des Assistenten zeigt das. Bearbeitete Termine bleiben unverändert und werden in Vorschau und Einkaufsliste als zu prüfen gemeldet.

## 0.6.1 – Entwicklungsstand, nicht veröffentlicht

Korrekturstand nach dem Code-Review von 0.6.0. Jeder Befund hat einen Regressionstest.

### Behoben
- **Produktidentität (P1):** Zutaten werden nur zusammengelegt, wenn vollständiger Name (einschließlich Klammerzusätzen), Zutatenhinweis, Allergen-Zusagen der Menüvariante und Einheit übereinstimmen. Zutaten für eine glutenfreie Variante sind eigene Positionen mit dem Hinweis „nur für: glutenfrei“.
- **Mehrbedarf im Ausdruck (P1):** Steigt der Bedarf nach dem Einkauf, steht der Rest als eigene, offene Zeile „(noch nötig)“ auf der gedruckten Liste.
- **Vorräte mit Menge (P1):** „Haben wir schon“ erfasst die vorhandene Menge; der Restbedarf bleibt auf der Liste. Ohne Menge markierte Vorräte aus 0.6.0 lassen sich nicht übernehmen und müssen neu erfasst werden.
- **Einkaufsstände bei Terminänderungen (P1):** Gekaufte Mengen und Vorräte werden als Fakten je Zutat gespeichert und bei jedem Abruf den Positionen in zeitlicher Reihenfolge zugeteilt. Verschieben oder Löschen eines Termins verliert nichts; eigene Posten wandern beim Löschen zum vorherigen Termin, nach ausdrücklicher Bestätigung.
- **Allergenangaben von Rezepten (P1):** Drei Zustände (nicht geprüft, geprüft ohne, geprüft mit Allergenen). Ungeprüfte Rezepte können keine Variante tragen, die Freiheit von einem Allergen zusagt. Grundrezepte gelten nach der Übernahme als ungeprüft; jede Änderung hebt die Bestätigung auf.
- **Freigaberichtlinien beim Hinzufügen (P2):** Auch über die API lassen sich nur Personen und Gruppen hinzufügen, mit denen die Nextcloud-Einstellungen das Teilen erlauben.
- **Vorläufige Einkaufslisten (P2):** Weichen Ernährungsangaben und Anmeldungen ab, gibt es ungeklärte Angaben oder fehlerhafte Menüvarianten, ist die Liste deutlich als vorläufig gekennzeichnet, auch im Ausdruck.
- **Einkauf nach dem Bedarfstag (P2):** Mindestens ein Einkauf muss spätestens am ersten Lagertag liegen; Positionen mit zu spätem Einkauf werden markiert.
- **Formulare nach Fehlern (P2):** Formulare der Einkaufsliste bleiben offen, wenn das Speichern scheitert.
- **Standard-Einkauf (P2):** entsteht beim Anlegen des Lagers; bestehende Lager ergänzt die Migration. Lesende Aufrufe schreiben nichts mehr.
- **Changelog (P3):** keine zukünftigen Daten mehr.

## 0.6.0 – Entwicklungsstand, nicht veröffentlicht

### Neu
- Reiter „Einkauf“ mit Einkaufslisten aus Speiseplan, Menüvarianten und Rezepten (Konzept 10).
- Gleiche Zutaten werden über alle Mahlzeiten zusammengefasst; Gewichte und Volumen werden summiert, gerundet wird erst am Ende und nur nach oben. Unvereinbare Einheiten derselben Zutat werden zur Prüfung markiert, nicht umgerechnet.
- Sortierung nach Warengruppen (Obst und Gemüse, Milchprodukte und Eier, Brot, Trockenware, Konserven, Gewürze und Öl, Sonstiges).
- Einkaufstermine: Standard ist ein Großeinkauf am Vortag; weitere Termine wie ein Frischeeinkauf lassen sich ergänzen. Haltbares kommt in den ersten Einkauf, Frisches in den letzten vor dem Tag, an dem es gebraucht wird.
- Abhaken, „Haben wir schon“ und eigene Posten wie Brötchen oder Spülmittel.
- Mehrbedarf nach dem Einkauf (Konzept 10.4): Steigt der Bedarf nach dem Abhaken, erscheint die fehlende Menge getrennt; Gekauftes wird nicht überschrieben.
- Große Löffelmengen zeigen zusätzlich ein ungefähres Volumen; Leitungswasser steht nicht auf der Liste.
- Hinweis, wie viele selbst gekochte Mahlzeiten noch kein Rezept haben und deshalb fehlen.
- Drucken: ein eigenes, schlichtes Dokument mit einer Seite je Einkauf; über den Druckdialog auch als PDF.

### Geändert
- Grundrezepte: Bei Gemüsebrühe steht jetzt „fertige Brühe; Pulver oder Würfel auf Allergene prüfen“.

## 0.5.0 – Entwicklungsstand, nicht veröffentlicht

### Neu
- Rezepte je Lager in der Küche: Titel, Standardportionen, Zutaten mit Menge und Einheit, Arbeitsschritte, Ernährungsweise, Allergene und Kategorie. Ansehen dürfen alle mit Zugriff auf die Küche, bearbeiten Lagerleitung und Küchenteam.
- Zehn Grundrezepte für Lagerklassiker (unter anderem Chili sin Carne, Linseneintopf, Kartoffelsuppe, Milchreis, Pfannkuchen, Kaiserschmarrn, Haferbrei, Stockbrot), eigens geschrieben und frei verwendbar unter CC0. Sie werden als bearbeitbare Kopie ins Lager übernommen.
- Hochrechnen auf beliebige Portionenzahlen: Gramm und Milliliter werden zu Kilogramm und Litern, Löffelmaße auf halbe Löffel gerundet, Zählbares aufgerundet und als gerundet markiert.
- Je Menüvariante ein Rezept: Die App rechnet die Mengen je Variante hoch und prüft das Rezept gegen die Zusagen der Variante (zum Beispiel „glutenfrei“, aber Spaghetti).
- Laktose und Milcheiweiß sind als Allergene getrennt, damit Rezepte mit laktosefreier Milch richtig eingeordnet werden.
- Enthält ein Rezept ein gemeldetes schweres Allergen, zeigt die Mahlzeit einen Prüfhinweis zum Allergieverfahren (Konzept 9.10).
- Hinweis auf die Arbeitshilfe „Kochen für Nachhaltigkeit“ der DPSG Köln als weiterführende Quelle (verlinkt, nicht übernommen).

## 0.4.0 – Entwicklungsstand, nicht veröffentlicht

### Neu
- Lagerrollen (Konzept 18.1): Lagerleitung, Programmteam, Küchenteam, Materialteam, Mitarbeitende, Lesezugriff. Eine Person kann mehrere Rollen haben; die anlegende Person ist immer Lagerleitung.
- Teilen mit ganzen Nextcloud-Gruppen, zum Beispiel der Leiterrunde. Wer in die Gruppe kommt, hat sofort Zugriff; wer sie verlässt, verliert ihn. Rollen aus eigener Mitgliedschaft und Gruppen werden zusammengeführt.
- Team-Dialog im Lager: Personen und Gruppen suchen, Rollen vergeben und ändern, Einträge entfernen. Die Suche nutzt die Personensuche der Nextcloud-Freigaben und beachtet deren Einstellungen.
- Jede Aktion prüft die nötige Berechtigung. Ernährungsangaben sehen nur Lagerleitung und Küchenteam (Konzept 18.3); Abhaken dürfen alle außer Lesezugriff, Titel und Fristen ändern nur die Lagerleitung.
- Die Oberfläche zeigt nur, was die eigene Rolle erlaubt. Geteilte Lager haben in der Navigation ein Gruppensymbol und zeigen die eigene Rolle.
- Verständlicher Hinweis, wenn ein Lager nicht (mehr) geteilt ist.

### Behoben
- Ein Klick auf ein Lager in der Navigation öffnete die Übersicht statt des Lagers (seit 0.1.0).

## 0.3.0 – Entwicklungsstand, nicht veröffentlicht

### Neu
- Reiter „Küche“ mit aggregierten Ernährungsangaben als Bedarfskombinationen: Jede Person zählt genau einmal, die Summe wird mit den angemeldeten Personen abgeglichen.
- Schwere Allergien je Allergen, nur als Anzahl. Sobald eine gemeldet ist, entsteht die Pflichtaufgabe „Allergieverfahren einrichten“ (Konzept 9.10); entfällt die Meldung, wird eine unbearbeitete Aufgabe wieder entfernt.
- Prüfung, ob jede schwere Allergie in den Bedarfskombinationen vorkommt (zum Beispiel Erdnussallergie ohne Kombination „ohne Erdnüsse“).
- Menüvarianten je Mahlzeit mit Zuordnung der Bedarfskombinationen, Personenzahl je Variante und Prüfung ohne Doppelzählung. Vorschlag „je Bedarfskombination eine Variante“ als Ausgangspunkt.
- Variantenplan auf alle Mahlzeiten gleicher Art oder alle Mahlzeiten übertragen; vorhandene Pläne bleiben unberührt.
- Standardportionen des Lagers aus den Portionsfaktoren der Gruppen.
- Eine Mahlzeit mit Variantenplan gilt als bearbeitet und wird von einem erneuten Assistentenlauf nicht verändert oder entfernt.

## 0.2.0 – Entwicklungsstand, nicht veröffentlicht

### Neu
- Spieleanbindung an Pfadfinder-Spiele.de über die Standard-REST-API von WordPress, ohne eigenes Plugin.
- Lokaler Spielekatalog mit täglichem Abgleich (Hintergrundjob) und Abgleich beim ersten Gebrauch; bei Ausfall wird mit dem letzten Stand gearbeitet.
- Spielesuche mit Filtern nach Name, alternativen Namen, Alter, Dauer, Gruppengröße und Umgebung; Gruppengröße aus dem Programmpunkt vorbelegt.
- Spiele an Programmpunkten einplanen oder beim Anlegen eines Programmpunkts übernehmen. Gespeichert wird eine Kopie mit Material, Link, Lizenz und Namensnennung (CC BY-NC-SA 4.0, Dorothea Schümann, Pfadfinder-Spiele.de).
- Spieltexte werden serverseitig auf einfache Auszeichnung bereinigt; die eingebettete Sternebewertung wird entfernt.
- Die Anbindung ist erst nach Aktivierung durch die Administration aktiv: `occ config:app:set lagerplaner games_enabled --value=yes`.

### Behoben
- Platzhalter in übersetzten Texten wurden doppelt maskiert („Papier &amp; Stifte“). Betraf auch Programmtitel mit „&“, Anführungszeichen oder spitzen Klammern.

## 0.1.1 – Entwicklungsstand, nicht veröffentlicht

Korrekturstand nach dem Code-Review von 0.1.0. Jeder Befund ist durch einen Regressionstest abgesichert.

### Behoben
- Vorschau und Übernahme eines erneuten Assistentenlaufs sind an dieselben Eingaben und denselben Lagerstand gebunden (Prüfschlüssel über Eingaben, Lagerversion und Änderungen).
- Änderungen nur an Lagerdaten oder Gruppen werden in der Vorschau angezeigt und lassen sich übernehmen.
- Gleichzeitige Übernahmen derselben Vorschau: nur die erste gelingt (optimistisches Sperren über eine Versionsnummer je Lager). Jede Bearbeitung macht eine offene Vorschau ungültig.
- Gelöschte Vorschläge des Assistenten kommen bei einem erneuten Lauf nicht wieder; sie lassen sich wiederherstellen.
- Eine nur als vegan gekennzeichnete Menüvariante gilt nicht mehr automatisch als milch-, laktose- oder eifrei. Allergen- und Unverträglichkeitsfreiheit muss ausdrücklich angegeben werden.
- Vollständige Eingabeprüfung im Assistenten: Typen, Ja/Nein-Werte („false“ als Text wurde als ja gelesen), Textlängen passend zu den Datenbankspalten, höchstens 12 Gruppen, 2000 Personen je Gruppe und 5000 insgesamt, Programmblöcke von 30 bis 360 Minuten.
- Bestätigte Programmpunkte außerhalb eines verkürzten Zeitraums erscheinen unter „Außerhalb des Lagerzeitraums“ und lassen sich verschieben oder löschen.
- Gruppen werden bei einem erneuten Lauf anhand ihrer Kennung aktualisiert statt ersetzt.
- Der Nachmittagssnack ist eine Pause vor dem Nachmittagsprogramm; eine Zeitachsenprüfung stellt sicher, dass sich erzeugte Punkte nicht überschneiden.
- Fristen werden auf gültige Kalendertage geprüft (2027-02-30 wird abgelehnt).
- Der Assistentenentwurf im Browser ist je Person getrennt und verfällt nach 30 Tagen.

### Neu
- Eigene Programmpunkte anlegen, mit Tag, Zeit, Dauer, Gruppen und Schlechtwetteralternative.
- Aufgaben anlegen, bearbeiten und löschen. Pflichtaufgaben lassen sich erledigen, aber nicht löschen.
- CI prüft gegen die Nextcloud-API 33, 34 und 35.

## 0.1.0 – Entwicklungsstand, nicht veröffentlicht

Erster Prototyp (Phase 0 des Gesamtkonzepts).

- Lagerassistent mit sechs Schritten, Vorschau und Zwischenspeicherung im Browser
- Regelbasierter Lagerentwurf (Vorlagen `core-de/2026.1`): Tagesstruktur, Programmblöcke je Altersstufe, Tageswanderung, Geländespiel, Mahlzeiten, Aufgaben mit Fristen, Hinweise
- Herkunfts- und Bearbeitungsfelder; erneuter Assistentenlauf mit Vorschau, der bestätigte und bearbeitete Einträge nie verändert
- Lageransicht mit Tagesplan, Bestätigen, Bearbeiten, Löschen, Aufgaben und Mahlzeiten
- Fachlogik für Bedarfskombinationen, Menüvarianten, Portionen und Pflichtaufgabe bei schweren Allergien (noch ohne Oberfläche)
- Deutsche Übersetzung
