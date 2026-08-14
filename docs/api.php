<?php
/**
 * API documentation for the base kernel's own routes.
 *
 * The base is not a `Module`, so it cannot implement `ApiDocSource` — this file
 * is the equivalent, loaded by {@see \Tds\CoreFrontendApi\Service\ApiReference}.
 * Every composed extension ships the same file at `php/docs/api.php`.
 *
 * `pattern` must match the Slim pattern in `Bootstrap::createApp()` VERBATIM,
 * inline regex included: it is the join key for the route introspection, and a
 * prettified path silently produces an orphan doc plus an undocumented route.
 * `tests/ApiReferenceTest.php` fails the build on either.
 */

declare(strict_types=1);

return [
    [
        'method' => 'GET',
        'pattern' => '/healthz',
        'tag' => 'Betrieb',
        'summary' => 'Health-Check des Frontend-Service',
        'description' => 'Antwortet immer mit 200 und JSON — das Gateway aggregiert die '
            . 'Health-Antworten aller Services und darf hier nicht auf einen 5xx laufen. '
            . '`db` ist nur enthalten, wenn `DB_NAME` gesetzt ist: ein Boot ganz ohne '
            . 'Datenbank ist ein unterstützter Zustand (lokale Entwicklung, erster Start '
            . 'vor der .env) und darf das Gateway nicht auf 503 kippen.',
        'auth' => 'public',
        'responses' => [
            [
                'status' => 200,
                'description' => 'Status, komponierte Module und — falls konfiguriert — der DB-Zustand '
                    . '(`ok` / `no-schema` / `down`).',
                'example' => '{"status":"ok","modules":["time-tracker","customers"],"db":"ok"}',
            ],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/permissions',
        'tag' => 'Verwaltung',
        'summary' => 'Zusammengeführter RBAC-Rechtekatalog aller Module',
        'description' => 'Die Liste, aus der der Nutzer-Editor die zuweisbaren Rechte baut. '
            . 'Rein katalogisch — die eigentliche Rechteprüfung passiert in den Routen '
            . 'der jeweiligen Module.',
        'auth' => 'public',
        'responses' => [
            [
                'status' => 200,
                'description' => 'Array aus `{id, label, group}`, in Modul-Reihenfolge.',
                'example' => '[{"id":"time:read","label":"Zeiten ansehen","group":"time-tracker"}]',
            ],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/me/dashboard-layout',
        'tag' => 'Eigenes Konto',
        'summary' => 'Widget-Anordnung des angemeldeten Nutzers lesen',
        'description' => 'Pro Nutzer gespeichert (Schlüssel ist die User-Id aus dem JWT), '
            . 'kein Admin-Gate. Leeres Layout bedeutet „Standardanordnung".',
        'auth' => 'session',
        'responses' => [
            ['status' => 200, 'description' => '`{layout: [{widget_id, visible, sort}]}`'],
            ['status' => 401, 'description' => 'Keine oder ungültige Sitzung.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/me/dashboard-layout',
        'tag' => 'Eigenes Konto',
        'summary' => 'Widget-Anordnung des angemeldeten Nutzers speichern',
        'description' => 'Ersetzt das Layout vollständig. Einträge ohne gültige `widget_id` '
            . '(Muster `[a-z0-9:_-]{1,64}`) werden verworfen statt die Anfrage abzulehnen; '
            . '`sort` wird aus der Reihenfolge im Array neu vergeben.',
        'auth' => 'session',
        'params' => [
            [
                'in' => 'body',
                'name' => 'layout',
                'type' => 'array',
                'required' => true,
                'description' => 'Liste aus `{widget_id, visible}` in gewünschter Reihenfolge.',
            ],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, count: <übernommene Einträge>}`'],
            ['status' => 401, 'description' => 'Keine oder ungültige Sitzung.'],
            ['status' => 422, 'description' => '`layout` fehlt oder ist kein Array.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/me/preferences',
        'tag' => 'Eigenes Konto',
        'summary' => 'Oberflächen-Einstellungen des angemeldeten Nutzers lesen',
        'description' => 'Theme, Sprache und Benachrichtigungs-Schalter, pro Nutzer '
            . 'gespeichert (Schlüssel ist die User-Id aus dem JWT), kein Admin-Gate. '
            . 'Das Panel hält das Theme zusätzlich im `localStorage` — das ist der '
            . 'Pre-Paint-Cache, den das No-Flash-Bootstrap vor dem ersten Rendern liest; '
            . 'diese Route ist die Quelle, die der Wahl über **Geräte hinweg** folgt. '
            . 'Antwortet `no-store`: Nutzer-spezifischer Zustand hinter einem geteilten '
            . 'Gateway darf nie zwischengespeichert werden.',
        'auth' => 'session',
        'responses' => [
            ['status' => 200, 'description' => '`{preferences: {theme?, locale?, notify_toast?, notify_email?}}`'],
            ['status' => 401, 'description' => 'Keine oder ungültige Sitzung.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/me/preferences',
        'tag' => 'Eigenes Konto',
        'summary' => 'Oberflächen-Einstellungen des angemeldeten Nutzers speichern',
        'description' => '**Teil-Schreibvorgang**: nicht genannte Schlüssel bleiben stehen, '
            . 'damit der Tab „Darstellung" beim Speichern des Themes nicht die '
            . 'Benachrichtigungs-Schalter löscht, die er nie angezeigt hat. Schlüssel und '
            . 'Werte laufen gegen eine geschlossene Whitelist (`theme`: `light|dark|system`, '
            . '`locale`: `de|en`, `notify_toast`/`notify_email`: `0|1`); Unbekanntes wird '
            . 'still verworfen statt die Anfrage abzulehnen — dieselbe Konvention wie beim '
            . 'Dashboard-Layout, damit ein neueres Panel gegen ein älteres Backend nicht '
            . 'den ganzen Speichervorgang verliert.',
        'auth' => 'session',
        'params' => [
            [
                'in' => 'body',
                'name' => 'preferences',
                'type' => 'object',
                'required' => true,
                'description' => 'Objekt aus erlaubten Schlüssel/Wert-Paaren.',
            ],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, saved: [<übernommene Schlüssel>]}`'],
            ['status' => 401, 'description' => 'Keine oder ungültige Sitzung.'],
            ['status' => 422, 'description' => '`preferences` fehlt oder ist kein Objekt.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/me/notifications',
        'tag' => 'Eigenes Konto',
        'summary' => 'Zusammengeführter Benachrichtigungs-Feed aller Module',
        'description' => 'Die EINE Route, die die Shell auf jeder Seite pollt. Jedes Modul, '
            . 'das `NotificationSource` implementiert, liefert eigene Ereignisse; der '
            . 'Cursor ist ein undurchsichtiger Sammel-Cursor über alle Module. '
            . '**Der erste Aufruf (ohne `since`) liefert nur den Cursor und keine '
            . 'Einträge** — sonst würde jeder frisch geöffnete Tab den gesamten '
            . 'Rückstand als Toasts anzeigen. Höchstens 20 Einträge pro Aufruf, die '
            . 'jüngsten gewinnen.',
        'auth' => 'session',
        'params' => [
            [
                'in' => 'query',
                'name' => 'since',
                'type' => 'string',
                'description' => 'Der Cursor aus der vorigen Antwort. Weglassen = Erstaufruf.',
            ],
        ],
        'responses' => [
            [
                'status' => 200,
                'description' => '`{cursor, items: [{id, module, kind, message, href, variant, created_at}]}`, '
                    . 'älteste zuerst.',
            ],
            ['status' => 401, 'description' => 'Keine oder ungültige Sitzung — die Shell stellt das Pollen dann ein.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/settings/{ns:[a-z0-9-]+}',
        'tag' => 'Einstellungen',
        'summary' => 'Laufzeit-Einstellungen eines Namensraums lesen (maskiert)',
        'description' => 'Geheimnisse werden **nie** im Klartext zurückgegeben, sondern nur '
            . 'als `configured` + `last4`. Werte kommen DB-first mit env als Rückfallebene.',
        'auth' => 'admin',
        'params' => [
            [
                'in' => 'path',
                'name' => 'ns',
                'type' => 'string',
                'required' => true,
                'description' => 'Namensraum, z. B. `live-chat-cta`, `billing`, `blog-cms`.',
            ],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{settings: [{key, label, secret, value|configured, last4}]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/settings/{ns:[a-z0-9-]+}',
        'tag' => 'Einstellungen',
        'summary' => 'Laufzeit-Einstellungen eines Namensraums speichern',
        'description' => 'Ein **leer** übergebenes Geheimnis heißt „bestehenden Wert behalten" — '
            . 'so muss die maskierte Oberfläche das Geheimnis nie zurückschicken. '
            . 'Schlüssel, die nicht auf `[a-z0-9_]{1,96}` passen, werden übersprungen. '
            . 'Geheimnisse liegen AES-256-GCM-verschlüsselt in der Datenbank.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'path', 'name' => 'ns', 'type' => 'string', 'required' => true, 'description' => 'Namensraum.'],
            [
                'in' => 'body',
                'name' => 'settings',
                'type' => 'array',
                'required' => true,
                'description' => 'Liste aus `{key, value, secret}`.',
            ],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, written: <Anzahl geschriebener Schlüssel>}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => '`settings` fehlt oder ist kein Array.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/mail',
        'tag' => 'Einstellungen',
        'summary' => 'Effektive SMTP-Konfiguration lesen',
        'description' => 'Was **tatsächlich** verschickt, nicht nur was gespeichert ist: '
            . '`source` sagt, ob die Datenbank (`db`), die `MAIL_DSN` aus der Umgebung '
            . '(`env`) oder nichts (`none`) den Transport stellt. Enthält bewusst kein '
            . 'Geheimnis — nur `password_configured`, weil der DSN das SMTP-Passwort '
            . 'einbetten kann.',
        'auth' => 'admin',
        'responses' => [
            [
                'status' => 200,
                'description' => '`{configured, source, host, port, security, user, '
                    . 'password_configured, from_email, from_name}`',
                'example' => '{"configured":true,"source":"db","host":"smtp.example.net","port":587,'
                    . '"security":"tls","user":"noreply@example.net","password_configured":true,'
                    . '"from_email":"no-reply@tracht-digital.de","from_name":"Tracht Digital Solutions"}',
            ],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/mail/test',
        'tag' => 'Einstellungen',
        'summary' => 'Testmail über die aktuelle SMTP-Konfiguration senden',
        'description' => 'Ohne `to` geht die Mail an die Adresse der angemeldeten Administration. '
            . 'SMTP scheitert an Dingen, die ein Formular nicht prüfen kann (falscher Port, '
            . 'verweigertes Relay, falsche Zugangsdaten) — „gespeichert" ist deshalb nicht '
            . 'dasselbe wie „verschickt". Fehlermeldungen des Servers werden durchgereicht, '
            . 'Zugangsdaten darin vorher entfernt.',
        'auth' => 'admin',
        'params' => [
            [
                'in' => 'body',
                'name' => 'to',
                'type' => 'string',
                'description' => 'Empfänger. Weglassen = eigene Adresse aus der Sitzung.',
            ],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, to}` — die Mail wurde dem SMTP-Server übergeben.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => 'Kein SMTP konfiguriert oder keine gültige Empfängeradresse.'],
            ['status' => 502, 'description' => 'Der SMTP-Server hat den Versand abgelehnt (`error` enthält den Grund).'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/modules/check',
        'tag' => 'Module',
        'summary' => 'Verfügbare Modulversionen bei der Registry abfragen',
        'description' => 'POST statt GET, weil die Paketliste die **Eingabe** ist: welche Module '
            . 'komponiert sind, ist eine Eigenschaft des Frontend-Builds, die diese API '
            . 'nicht kennt. Liefert außerdem den Zustand der unbeaufsichtigten '
            . 'Aktualisierung und die Backend-Paketversionen. Der Registry-Token '
            . 'verlässt den Server dabei nie.',
        'auth' => 'admin',
        'params' => [
            [
                'in' => 'body',
                'name' => 'inventory',
                'type' => 'array',
                'description' => 'Die gebackene Inventarliste des Produkts (`{pkg, range, installed}`). '
                    . 'Wird gemerkt, weil die gepinnten Bereiche nur in der package.json '
                    . 'des Produkts stehen und der Auto-Updater sie braucht.',
            ],
            [
                'in' => 'body',
                'name' => 'packages',
                'type' => 'string[]',
                'description' => 'Alternativ nur die Paketnamen. Nicht freigegebene Namen werden verworfen.',
            ],
        ],
        'responses' => [
            [
                'status' => 200,
                'description' => '`{auto, versions, registry: {configured, error}, targets, backend, checked_at}`. '
                    . '`registry.error` wird wörtlich durchgereicht — „Token abgelehnt" und '
                    . '„Paket unbekannt" brauchen völlig verschiedene Gegenmaßnahmen.',
            ],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/modules/auto-update',
        'tag' => 'Module',
        'summary' => 'Unbeaufsichtigte Aktualisierung sofort ausführen',
        'description' => 'Das „Jetzt prüfen und aktualisieren" der Modulseite. Läuft auch, '
            . 'wenn die Automatik ausgeschaltet ist, damit man sie vor dem Aktivieren '
            . 'testen kann. Berücksichtigt nur Versionen **innerhalb** des gepinnten '
            . 'Bereichs und stößt ausschließlich den Frontend-Build an.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{report, auto}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/modules/deploy',
        'tag' => 'Module',
        'summary' => 'Deploy-Pipeline eines Ziels auslösen',
        'description' => 'Was „Modul aktualisieren" tatsächlich tut: es gibt keinen '
            . 'Laufzeit-Modultausch, Komposition ist ein Build-Schritt. Da CI mit '
            . '`npm install --no-package-lock` installiert, löst **ein** Rebuild ALLE '
            . 'Caret-Bereiche neu auf — nicht nur den eines Moduls.',
        'auth' => 'admin',
        'params' => [
            [
                'in' => 'body',
                'name' => 'target',
                'type' => 'string',
                'required' => true,
                'description' => 'Schlüssel eines konfigurierten Ziels (Repo + Workflow).',
            ],
        ],
        'responses' => [
            ['status' => 202, 'description' => 'Pipeline angestoßen: `{ok, target, repo, workflow, message}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => 'Unbekanntes oder nicht konfiguriertes Ziel.'],
            ['status' => 502, 'description' => 'Der Dispatch selbst ist upstream fehlgeschlagen — die Anfrage war in Ordnung.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/wiki.json',
        'tag' => 'Betrieb',
        'summary' => 'Diese API-Referenz',
        'description' => 'Die Routen kommen aus der Introspektion der komponierten '
            . 'Slim-Routen, die Beschreibungen aus `ApiDocSource` der Module. '
            . 'Gruppiert wird nach dem Modul, das die Route registriert hat — nicht '
            . 'nach Pfadsegment. Eine undokumentierte Route erscheint trotzdem '
            . '(`documented: false`); ein Doku-Eintrag ohne passende Route landet in '
            . '`stats.orphan_docs`.',
        'auth' => 'admin',
        'responses' => [
            [
                'status' => 200,
                'description' => '`{generated_at, version: 2, modules: [{id, routes}], stats}`',
            ],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
];
