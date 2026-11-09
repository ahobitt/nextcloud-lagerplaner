#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Liest das Nextcloud-Log (JSON je Zeile) von stdin und schlägt fehl, wenn der Lagerplaner
Fehler (Stufe 3 oder höher) protokolliert hat. Fehler anderer Apps werden nur angezeigt."""
import json
import sys

ours = []
others = 0
for line in sys.stdin:
    line = line.strip()
    if not line:
        continue
    try:
        entry = json.loads(line)
    except json.JSONDecodeError:
        continue
    if int(entry.get('level', 0)) < 3:
        continue
    text = json.dumps(entry, ensure_ascii=False)
    if entry.get('app') == 'lagerplaner' or 'Lagerplaner' in text or 'lagerplaner' in text:
        ours.append(entry)
    else:
        others += 1

print(f'Fehler anderer Apps im Log: {others} (nur zur Information)')
for entry in ours:
    message = entry.get('message', '')
    exception = entry.get('exception', {})
    print(f"FEHLER {entry.get('app', '?')}: {message} {exception.get('Message', '') if isinstance(exception, dict) else ''}"[:500])
if ours:
    print(f'{len(ours)} Fehler des Lagerplaners im Log')
    sys.exit(1)
print('Keine Fehler des Lagerplaners im Log')
