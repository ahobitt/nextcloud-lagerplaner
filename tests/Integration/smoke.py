#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Prüft die Kernabläufe des Lagerplaners über die API einer laufenden Nextcloud.

Erwartet die Testdaten aus setup-testdata.sh (anna, ben, carla, dora, emil; Gruppen
leiterrunde und kueche; erfundene Lagerplätze). Nur Standardbibliothek, Python 3.9+.

  python3 smoke.py --base http://127.0.0.1:8081
  python3 smoke.py --base http://127.0.0.1:8081 --state /tmp/s.json --after-cron
"""
import argparse
import base64
import datetime
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

import os

# Passwort der Testpersonen; abweichend über --password oder TEST_PASSWORD
PASSWORD = os.environ.get('TEST_PASSWORD', 'Lagerplaner-Test-2026')
# Zusätzliche Header, etwa um einen vorgeschalteten Verzeichnisschutz zu passieren (--header)
EXTRA_HEADERS = {}
CAMP = {
    'name': 'Integrationstest Sommerlager',
    'start': '2027-07-24',
    'end': '2027-08-04',
    'organizer': 'Stamm Beispiel',
    'campType': 'tent',
    'groups': [
        {'key': 'woelflinge', 'count': 10}, {'key': 'jungpfadfinder', 'count': 12},
        {'key': 'pfadfinder', 'count': 8}, {'key': 'rover', 'count': 7}, {'key': 'leitende', 'count': 5},
    ],
    'location': {'known': False, 'region': 'Süddeutschland'},
    'program': {'theme': 'Wikinger', 'hike': True, 'wideGame': True, 'freeTime': 'medium'},
    'catering': {'selfCatering': True, 'meals': ['breakfast', 'lunch', 'dinner'], 'kitchenTeam': 'unknown'},
    'organisation': {'materialResponsible': 'yes'},
}


class Client:
    def __init__(self, base, user, password, harness):
        self.base = base.rstrip('/')
        self.user = user
        self.headers = {'Accept': 'application/json', 'Content-Type': 'application/json', 'OCS-APIRequest': 'true', **EXTRA_HEADERS}
        if harness:
            self.headers['Cookie'] = f'lp_user={user}'
        else:
            token = base64.b64encode(f'{user}:{password}'.encode()).decode()
            self.headers['Authorization'] = f'Basic {token}'

    def dav(self, method, path, body=None):
        """WebDAV im eigenen Dateibereich, etwa um eine recipe.json wie Cookbook abzulegen."""
        url = f"{self.base}/remote.php/dav/files/{self.user}/{urllib.parse.quote(path)}"
        headers = {k: v for k, v in self.headers.items() if k != 'Content-Type'}
        request = urllib.request.Request(url, data=body, method=method, headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                return response.status, response.read().decode(errors='replace')
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode(errors='replace')[:300]

    def call(self, method, path, body=None, ocs=False):
        url = self.base + (path if ocs else '/index.php/apps/lagerplaner/api' + path)
        data = None if body is None else json.dumps(body).encode()
        request = urllib.request.Request(url, data=data, method=method, headers=self.headers)
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                raw = response.read().decode() or 'null'
                return response.status, json.loads(raw)
        except urllib.error.HTTPError as error:
            raw = error.read().decode()
            try:
                return error.code, json.loads(raw)
            except json.JSONDecodeError:
                return error.code, raw[:300]


class Checks:
    def __init__(self):
        self.failed = 0
        self.passed = 0

    def ok(self, name, condition, detail=''):
        if condition:
            self.passed += 1
            print(f'  ok    {name}')
        else:
            self.failed += 1
            print(f'  FEHLER {name} {detail}'[:600])
        return condition

    def status(self, name, result, expected):
        status, data = result
        self.ok(name, status in (expected if isinstance(expected, tuple) else (expected,)), f'(HTTP {status}: {str(data)[:300]})')
        return data


def notifications(client):
    status, data = client.call('GET', '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', ocs=True)
    if status != 200 or not isinstance(data, dict):
        return []
    return [n for n in data.get('ocs', {}).get('data', []) if n.get('app') == 'lagerplaner']


def main_flow(args, check):
    anna = Client(args.base, 'anna', PASSWORD, args.harness)
    ben = Client(args.base, 'ben', PASSWORD, args.harness)
    dora = Client(args.base, 'dora', PASSWORD, args.harness)
    emil = Client(args.base, 'emil', PASSWORD, args.harness)

    print('Lager anlegen (Assistent, Migrationen, Speichern)')
    check.status('Vorgaben des Assistenten', anna.call('GET', '/wizard/defaults'), 200)
    camp = check.status('Lager anlegen', anna.call('POST', '/wizard/camps', {'input': CAMP}), (200, 201))
    camp_id = camp['id']
    program = check.status('Programm lesen', anna.call('GET', f'/camps/{camp_id}/program'), 200)
    check.ok('Programm hat Vorschläge', isinstance(program, list) and len(program) > 30, f'({len(program)})')
    check.ok('Schlechtwetteralternativen vorgeschlagen', any(i.get('weatherAlternative') for i in program))
    conflicts = check.status('Zeitkonflikte prüfen', anna.call('GET', f'/camps/{camp_id}/program/conflicts'), 200)
    check.ok('keine Zeitkonflikte im Entwurf', conflicts in ({}, []), str(conflicts)[:200])

    print('Team und Rechte (Gruppen, Freigabe-Einstellungen)')
    found = check.status('Gruppe suchen', anna.call('GET', f'/camps/{camp_id}/members/search?q=leiter'), 200)
    check.ok('Leiterrunde gefunden', any(e.get('id') == 'leiterrunde' for e in found), str(found)[:200])
    check.status('Leiterrunde als Mitarbeitende', anna.call('POST', f'/camps/{camp_id}/members', {'type': 'group', 'memberId': 'leiterrunde', 'roles': ['mitarbeit']}), (200, 201))
    check.status('Küchenteam als Küche', anna.call('POST', f'/camps/{camp_id}/members', {'type': 'group', 'memberId': 'kueche', 'roles': ['kueche']}), (200, 201))
    camps_ben = check.status('Ben sieht seine Lager', ben.call('GET', '/camps'), 200)
    check.ok('geteiltes Lager bei Ben', any(c.get('id') == camp_id for c in camps_ben))
    check.status('Ben ohne Küchenzugriff', ben.call('GET', f'/camps/{camp_id}/catering'), 403)
    check.status('Emil ohne Zugriff', emil.call('GET', f'/camps/{camp_id}'), 404)

    print('Aufgaben, Verantwortliche, Benachrichtigung')
    due = (datetime.date.today() + datetime.timedelta(days=1)).isoformat()
    task = check.status('Aufgabe mit Verantwortlichem', anna.call('POST', f'/camps/{camp_id}/tasks', {'fields': {'title': 'Bus anfragen', 'assignees': ['ben'], 'dueDate': due, 'priority': 'high'}}), (200, 201))
    if not args.harness:
        assigned = [n for n in notifications(ben) if 'Bus anfragen' in n.get('subject', '')]
        check.ok('Ben wurde benachrichtigt', len(assigned) >= 1, str(notifications(ben))[:300])
    other = check.status('Aufgabe ohne Verantwortliche', anna.call('POST', f'/camps/{camp_id}/tasks', {'fields': {'title': 'Kühlschrank leihen'}}), (200, 201))
    claimed = check.status('Ben übernimmt', ben.call('POST', f"/tasks/{other['id']}/claim"), 200)
    check.ok('Ben ist verantwortlich', claimed.get('assignees') == ['ben'])
    done = check.status('Ben hakt ab', ben.call('PUT', f"/tasks/{other['id']}", {'fields': {'status': 'done'}}), 200)
    check.ok('erledigt von Ben', done.get('completedBy') == 'ben')
    check.status('Ben darf nicht zuweisen', ben.call('PUT', f"/tasks/{other['id']}", {'fields': {'assignees': ['carla']}}), 403)

    print('Speiseplan und Mahlzeiten im Programm')
    proposed = check.status('Speiseplan vorschlagen', anna.call('POST', f'/camps/{camp_id}/menu/propose'), 200)
    check.ok('Mahlzeiten geplant', proposed.get('planned', 0) > 20, str(proposed))
    dishes = check.status('Gerichte für Mitarbeitende', ben.call('GET', f'/camps/{camp_id}/dishes'), 200)
    check.ok('Gerichte sichtbar', isinstance(dishes, dict) and 'Spaghetti mit Tomatensoße' in dishes.values(), str(dishes)[:200])
    meals = check.status('Mahlzeiten lesen', ben.call('GET', f'/camps/{camp_id}/meals'), 200)
    first_lunch = next(m for m in meals if m['mealType'] == 'lunch')
    check.status('Ben darf keine Mahlzeit abwählen', ben.call('PUT', f"/meals/{first_lunch['id']}/cancelled", {'cancelled': True}), 403)
    check.status('Mahlzeit entfällt', anna.call('PUT', f"/meals/{first_lunch['id']}/cancelled", {'cancelled': True}), 200)

    print('Verschieben mit Zeitanpassung')
    program = check.status('Programm für das Verschieben', anna.call('GET', f'/camps/{camp_id}/program'), 200)
    morning = next(i for i in program if i['title'] == 'Morgenrunde' and i['day'] == '2027-07-26')
    moved = check.status('Morgenrunde vor das Frühstück', anna.call('POST', f'/camps/{camp_id}/schedule/move', {'kind': 'program', 'entryId': morning['id'], 'day': '2027-07-26', 'time': '08:00'}), 200)
    check.ok('Frühstück rutscht mit', any(c['kind'] == 'meal' and c['to'] == '08:15' for c in moved.get('changes', [])), str(moved)[:200])
    check.status('rückgängig', anna.call('POST', f'/camps/{camp_id}/schedule/restore', {'changes': moved.get('changes', [])}), 200)
    check.status('Ben darf nicht verschieben', ben.call('POST', f'/camps/{camp_id}/schedule/move', {'kind': 'program', 'entryId': morning['id'], 'day': '2027-07-26', 'time': '07:00'}), 403)

    print('Material und Packliste')
    material = check.status('Material anlegen', anna.call('POST', f'/camps/{camp_id}/material', {'material': {'name': 'Seile', 'category': 'ropes', 'unit': 'Stück', 'quantity': 10}}), (200, 201))
    check.status('Ben packt', ben.call('PUT', f"/material/{material['id']}/status", {'status': 'packed'}), 200)
    check.status('Ben ändert kein Material', ben.call('PUT', f"/material/{material['id']}", {'material': {'name': 'X'}}), 403)
    overview = check.status('Materialliste', ben.call('GET', f'/camps/{camp_id}/material'), 200)
    check.ok('Seile gepackt', any(i['name'] == 'Seile' and i['status'] == 'packed' for i in overview.get('items', [])))

    print('Lagerplätze (Import per occ, Suche, Merkliste)')
    sites = check.status('Plätze suchen', anna.call('GET', '/sites?persons=40'), 200)
    check.ok('importierte Plätze gefunden', sites.get('total', 0) >= 2, str(sites)[:200])
    if sites.get('items'):
        site_id = sites['items'][0]['id']
        check.status('auf die Merkliste', anna.call('POST', f'/camps/{camp_id}/sites', {'siteId': site_id}), (200, 201))
        shortlist = check.status('Merkliste lesen', anna.call('GET', f'/camps/{camp_id}/sites'), 200)
        entry = shortlist['items'][0]['entryId']
        check.status('als Lagerort festlegen', anna.call('POST', f'/camp-sites/{entry}/adopt'), 200)
        detail = check.status('Lager lesen', anna.call('GET', f'/camps/{camp_id}'), 200)
        check.ok('Lagerort übernommen', detail['camp'].get('locationKnown') is True)
        closed = check.status('auch geschlossene Plätze', anna.call('GET', '/sites?closed=1&unknown=1'), 200)
        check.ok('Schalter für geschlossene Plätze wirkt', closed.get('total', 0) >= sites.get('total', 0))
        check.status('Feedback zu einem Platz', ben.call('POST', f'/sites/{site_id}/feedback', {'kind': 'capacity', 'values': {'capacity': '45'}, 'message': 'Rauchtest'}), 201)
        check.status('ungültiges Feedback abgelehnt', ben.call('POST', f'/sites/{site_id}/feedback', {'kind': 'website', 'values': {'homepage': 'keine-adresse'}}), 400)
        check.status('fehlenden Platz melden', ben.call('POST', '/sites/feedback', {'values': {'name': 'Rauchtest-Wiese', 'type': 'tent', 'location': '50.5, 9.5'}, 'message': 'Rauchtest'}), 201)

    print('Vorlagen')
    template = check.status('Tag als Vorlage', anna.call('POST', '/templates/day', {'campId': camp_id, 'day': '2027-07-26', 'name': 'Integrationstest-Tag', 'shared': False}), (200, 201))
    applied = check.status('Vorlage einfügen', anna.call('POST', f"/templates/{template['id']}/apply", {'campId': camp_id, 'day': '2027-07-27'}), 200)
    check.ok('Programmpunkte eingefügt', applied.get('created', 0) > 0)
    check.status('Vorlage löschen', anna.call('DELETE', f"/templates/{template['id']}"), 200)

    print('Unterlagen (geschützte Küchenexporte)')
    document = check.status('Lagerprogramm', anna.call('GET', f'/camps/{camp_id}/export/program'), 200)
    check.ok('Programm enthält das Lager', CAMP['name'] in document.get('html', ''))
    check.status('Ben bekommt keinen Speiseplan', ben.call('GET', f'/camps/{camp_id}/export/meals'), 403)
    check.status('Küchenteam bekommt den Speiseplan', dora.call('GET', f'/camps/{camp_id}/export/meals'), 200)
    check.status('Einkaufsliste', anna.call('GET', f'/camps/{camp_id}/shopping'), 200)

    if not args.harness:
        cookbook_flow(anna, camp_id, check)

    print('Assistent erneut ausführen')
    shorter = dict(CAMP, end='2027-08-03')
    preview = check.status('Vorschau', anna.call('POST', f'/camps/{camp_id}/wizard/preview', {'input': shorter}), 200)
    check.status('übernehmen', anna.call('POST', f'/camps/{camp_id}/wizard/apply', {'input': shorter, 'token': preview.get('token', ''), 'groupDecisions': {}}), 200)

    if args.cleanup and not args.state:
        check.status('Testlager löschen', anna.call('DELETE', f'/camps/{camp_id}'), 200)
    return {'campId': camp_id, 'taskId': task['id']}


def cookbook_flow(anna, camp_id, check):
    status, library = anna.call('GET', f'/cookbook?campId={camp_id}')
    if status != 200 or library.get('status') != 'ready':
        print(f"Cookbook übersprungen (Status: {library.get('status') if isinstance(library, dict) else status})")
        return
    print('Nextcloud Cookbook (echtes Dateisystem)')
    recipe = {
        '@context': 'http://schema.org', '@type': 'Recipe', 'name': 'Integrationstest-Dal', 'recipeYield': 4, 'keywords': 'vegan',
        'recipeIngredient': ['250 g rote Linsen', '1 Dose Kokosmilch', '2-3 Zwiebeln', 'Salz'],
        'recipeInstructions': [{'@type': 'HowToStep', 'text': 'Alles kochen.'}],
        'dateCreated': '2026-10-01T10:00:00+00:00', 'dateModified': '2026-10-01T10:00:00+00:00',
    }
    anna.dav('MKCOL', 'Rezepte')
    anna.dav('MKCOL', 'Rezepte/Integrationstest-Dal')
    status, _ = anna.dav('PUT', 'Rezepte/Integrationstest-Dal/recipe.json', json.dumps(recipe).encode())
    check.ok('Rezept per WebDAV wie Cookbook abgelegt', status in (201, 204), f'(HTTP {status})')
    library = check.status('Cookbook-Sammlung lesen', anna.call('GET', f'/cookbook?campId={camp_id}'), 200)
    found = [i for i in library.get('items', []) if i['name'] == 'Integrationstest-Dal']
    if not check.ok('Rezept in der Sammlung gefunden', len(found) == 1, str(library)[:300]):
        return
    preview = check.status('Vorschau', anna.call('GET', f"/cookbook/{found[0]['id']}"), 200)
    check.ok('unsichere Zeile markiert', any(not line['certain'] for line in preview.get('lines', [])))
    imported = check.status('ins Lager übernehmen', anna.call('POST', f'/camps/{camp_id}/cookbook', {'cookbookId': found[0]['id']}), (200, 201))
    check.status('keine Änderung erkannt', anna.call('GET', f'/camps/{camp_id}/cookbook/updates'), 200)
    check.status('verknüpftes Rezept nicht doppelt speichern', anna.call('POST', f"/recipes/{imported['id']}/cookbook"), 400)
    own = check.status('eigenes Rezept', anna.call('POST', f'/camps/{camp_id}/recipes', {'recipe': {'title': 'Lager-Pfannkuchen', 'servings': 10, 'diet': 'vegetarian', 'contains': ['gluten', 'egg', 'lactose', 'milk_protein'],
        'ingredients': [{'name': 'Mehl', 'quantity': 1, 'unit': 'kg'}], 'steps': ['Ausbacken.']}}), (200, 201))
    check.status('eigenes Rezept in Cookbook speichern', anna.call('POST', f"/recipes/{own['id']}/cookbook"), 201)
    status, body = anna.dav('GET', 'Rezepte/Lager-Pfannkuchen/recipe.json')
    check.ok('recipe.json im Rezeptordner', status == 200 and '1 kg Mehl' in body, f'(HTTP {status}: {body[:200]})')


def after_cron(args, check, state):
    ben = Client(args.base, 'ben', PASSWORD, args.harness)
    print('Erinnerung nach den Hintergrundjobs')
    reminders = [n for n in notifications(ben) if 'Bus anfragen' in n.get('subject', '') and n.get('subject') != '']
    check.ok('Erinnerung an die Frist (zweite Nachricht zur Aufgabe)', len(reminders) >= 2, str(reminders)[:400])
    if args.cleanup:
        anna = Client(args.base, 'anna', PASSWORD, args.harness)
        check.status('Testlager löschen', anna.call('DELETE', f"/camps/{state['campId']}"), 200)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default='http://127.0.0.1:8081')
    parser.add_argument('--state', help='Datei für Zwischenstand zwischen den Läufen')
    parser.add_argument('--after-cron', action='store_true')
    parser.add_argument('--cleanup', action='store_true')
    parser.add_argument('--harness', action='store_true', help='Anmeldung über Cookie gegen den Entwicklungs-Testaufbau')
    parser.add_argument('--password', help='Passwort der Testpersonen (Standard: TEST_PASSWORD oder Lagerplaner-Test-2026)')
    parser.add_argument('--header', action='append', default=[], metavar='"Name: Wert"',
                        help='zusätzlicher HTTP-Header, mehrfach möglich; etwa für eine Ausnahme im Verzeichnisschutz')
    args = parser.parse_args()
    for header in args.header:
        name, _, value = header.partition(':')
        if not name.strip() or not value.strip() or name.strip().lower() == 'authorization':
            parser.error(f'ungültiger Header: {header} (Authorization ist für die Nextcloud-Anmeldung reserviert)')
        EXTRA_HEADERS[name.strip()] = value.strip()
    if args.password:
        global PASSWORD
        PASSWORD = args.password
    check = Checks()
    if args.after_cron:
        with open(args.state, encoding='utf-8') as handle:
            state = json.load(handle)
        after_cron(args, check, state)
    else:
        state = main_flow(args, check)
        if args.state:
            with open(args.state, 'w', encoding='utf-8') as handle:
                json.dump(state, handle)
    print(f'{check.passed} bestanden, {check.failed} fehlgeschlagen')
    sys.exit(1 if check.failed else 0)


if __name__ == '__main__':
    main()
