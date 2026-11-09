# Mitgelieferte Datenstände des Lagerplatzverzeichnisses

Für Installationen ohne Netzzugang bringt die App einen Stand jeder Datenquelle mit. Die App übernimmt ihn bei Installation und Update für Quellen, die noch nie abgeglichen wurden; danach gleicht ein Hintergrundjob die Quelle wöchentlich ab (abschaltbar unter Verwaltung → Lagerplaner).

| Datei | Quelle | Einträge |
| --- | --- | --- |
| `schwarzzelt_it.csv` | [Schwarzzelt IT Campsitemap](https://github.com/Schwarzzelt-IT/campsites) (`sites.csv`, Stand 1. November 2026, mit den Korrekturen von Schwarzzelt IT) | 192 |
| `schwarzzelt_it.enrichment.json` | Lagerplaner-Anreicherung, auf den aktuellen Index ausgerichtet (Stand 1. November 2026) | 192 |

Die Anreicherung ist Lagerplaner-eigen und ergänzt Postleitzahl und Ort (aus den Koordinaten), Ausstattung, Kapazität, Betten und Betriebsstatus; ihre Vorschläge zu Stammdaten (Name, URL, Kategorie) übernimmt die App nicht. Sie entstand KI-gestützt anhand öffentlicher Betreiber- und Informationsseiten; auffällige Fälle wurden von Hand plausibilisiert. Regeln: `true` nur bei bestätigtem Vorhandensein, `false` nur bei ausdrücklicher Verneinung, sonst `null`; eine nicht erreichbare Website bedeutet nicht „geschlossen“. Die App übernimmt sie als Angaben „laut Website“ mit Prüfdatum, Beleg und Sicherheit; von Hand gepflegte Angaben haben Vorrang.

Die Campsitemap steht unter der MIT-Lizenz. Schwarzzelt IT hat die Nutzung erlaubt, wenn Schwarzzelt IT als Quelle genannt und möglichst verlinkt wird; die App zeigt die Quelle mit Link bei jedem Platz. Die Campsitemap ist nur für die Stammdaten zuständig (Name, Kategorie, Koordinaten, Website). Geprüfte Rückmeldungen zu diesen Stammdaten (Schließung, neuer Platz, falscher Name, falsche Lage, Website, Betreiberwechsel) sammelt die App unter Verwaltung → Lagerplaner als „Mögliche Korrekturen für die Campsitemap“; das Repository ändert sie nicht selbst.

Das Release-Skript prüft für jede mitgelieferte Quelle eine Zeile mit dem Datum der Zustimmung:

Zustimmung schwarzzelt_it: 2026-10-31
