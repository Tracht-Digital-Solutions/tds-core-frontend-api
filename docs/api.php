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
        'method' => 'GET',
        'pattern' => '/admin/cors',
        'tag' => 'Einstellungen',
        'summary' => 'Effektive CORS-Freigabe lesen',
        'description' => 'Welche Browser-Origins diese API aufrufen dürfen — mit der **Ebene**, '
            . 'aus der jeder Eintrag stammt: `baseline` (fest eingebaute erste Partei, '
            . 'nicht entfernbar), `env` (`CORS_ALLOWED_ORIGINS` aus der `.env` des Hosts) '
            . 'oder `db` (im Panel gepflegt). Die drei Ebenen werden **vereinigt**, nie '
            . 'übersteuert: sonst könnte eine Änderung im Panel genau das Frontend '
            . 'aussperren, das sie zurücknehmen müsste.',
        'auth' => 'admin',
        'responses' => [
            [
                'status' => 200,
                'description' => '`{origins: [{origin, source}], custom, store_available}`',
                'example' => '{"origins":[{"origin":"https://app.tracht-digital.de","source":"baseline"},'
                    . '{"origin":"https://kunde.example","source":"db"}],'
                    . '"custom":["https://kunde.example"],"store_available":true}',
            ],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/cors',
        'tag' => 'Einstellungen',
        'summary' => 'Zusätzliche CORS-Origins speichern',
        'description' => 'Speichert **nur** die zusätzliche Liste; Baseline und `.env` bleiben '
            . 'unberührt. Jeder Eintrag wird auf die Form normalisiert, die ein Browser '
            . 'im `Origin`-Header sendet (Schema + Host + optional Port, kein Pfad, kein '
            . 'Schrägstrich am Ende) — der Vergleich ist ein exakter Zeichenkettenabgleich, '
            . 'ein knapp danebenliegender Wert würde also dauerhaft und lautlos nichts '
            . 'freischalten. Abgelehnte Einträge kommen mit Begründung zurück, statt '
            . 'stillschweigend zu verschwinden. `*` wird abgelehnt: zusammen mit '
            . '`Allow-Credentials` verbietet der Standard den Platzhalter.',
        'auth' => 'admin',
        'params' => [
            [
                'in' => 'body',
                'name' => 'origins',
                'type' => 'array',
                'required' => true,
                'description' => 'Liste von Origins (oder ein Text mit Zeilen-/Kommatrennung).',
            ],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, saved, rejected, origins, custom, store_available}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => '`origins` ist keine Liste.'],
            ['status' => 503, 'description' => 'Keine Datenbank konfiguriert — es gibt nichts zu speichern.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/sites',
        'tag' => 'Site-Verbindungen',
        'summary' => 'Verbundene Sites, ihre Keys und der Erzwingungsmodus',
        'description' => 'Der Bestand aller öffentlichen Sites (Landingpage, Blog, Tools, Login '
            . 'plus frei angelegte) mit ihren Site-Keys, dem CORS-Zustand jeder Origin, '
            . 'den von den Modulen gemeldeten geschützten Pfad-Präfixen und dem Zähler '
            . 'des `warn`-Modus. Ein Key erscheint nur mit Präfix, Erzeugung und '
            . '„zuletzt gesehen" — der Klartext existiert ausschließlich in der Antwort '
            . 'von `POST /admin/sites` und ist danach nicht wiederherstellbar. '
            . 'Widerrufene Keys bleiben gelistet und sind markiert: dass eine Site einen '
            . 'Key hatte und wann er widerrufen wurde, ist genau die Frage, für die es '
            . 'diese Seite gibt.',
        'auth' => 'admin',
        'responses' => [
            [
                'status' => 200,
                'description' => '`{sites, enforcement, modes, protected_routes, unkeyed, store_available}`',
                'example' => '{"sites":[{"id":"blog","label":"Blog","known":true,'
                    . '"origins":[{"origin":"https://blog.tracht-digital.de","cors":"baseline"}],'
                    . '"keys":[{"id":3,"key_prefix":"tdsk_blog_A1b2","last_used_at":"2026-08-23 09:14:02"}]}],'
                    . '"enforcement":"warn","protected_routes":["/content/blog"],"store_available":true}',
            ],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/sites',
        'tag' => 'Site-Verbindungen',
        'summary' => 'Site-Key erzeugen (Klartext nur hier)',
        'description' => 'Erzeugt einen Key für eine bekannte oder zuvor angelegte Site. '
            . 'Gespeichert wird nur ein SHA-256-Hash, der Klartext steht **einmalig** in '
            . 'dieser Antwort. Für eine unbekannte Site wird der Aufruf abgelehnt statt '
            . 'sie zu erfinden: ein Key ohne erklärte Site passt zu keinem Build und zu '
            . 'keiner Origin und stünde nur konfiguriert aussehend in der Liste.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'body', 'name' => 'site', 'type' => 'string', 'required' => true, 'description' => 'Site-Kennung, z. B. `blog`.'],
            ['in' => 'body', 'name' => 'label', 'type' => 'string', 'description' => 'Freier Name; leer = Name der Site.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{ok: true, id, site, key, key_prefix}` — `key` ist der Klartext.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => '`site` fehlt oder ist unbekannt.'],
            ['status' => 503, 'description' => 'Keine Datenbank konfiguriert.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/sites',
        'tag' => 'Site-Verbindungen',
        'summary' => 'Erzwingungsmodus und eigene Sites speichern',
        'description' => '`enforcement` ist absichtlich dreiwertig: `off` (Vorgabe, Verhalten '
            . 'jeder Installation vor diesem Feature), `warn` (wird ausgeliefert, aber '
            . 'gezählt und protokolliert) und `enforce` (401). Der direkte Sprung von '
            . '`off` auf `enforce` bricht genau die Site, die man vergessen hat — und '
            . 'zwar unsichtbar, weil jeder Build-Zeit-Abruf fail-soft ist und die '
            . 'gebackenen Rückfallinhalte ausliefert. `warn` ist der Weg dazwischen. '
            . 'Abgelehnte eigene Sites kommen mit Begründung zurück.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'body', 'name' => 'enforcement', 'type' => 'off|warn|enforce', 'description' => 'Neuer Modus.'],
            ['in' => 'body', 'name' => 'sites', 'type' => 'array', 'description' => 'Eigene Sites: `{id, label, origins}`.'],
            ['in' => 'body', 'name' => 'reset_unkeyed', 'type' => 'bool', 'description' => 'Setzt den `warn`-Zähler zurück.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, enforcement, sites, rejected}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => 'Unbekannter Modus oder `sites` ist keine Liste.'],
            ['status' => 503, 'description' => 'Keine Datenbank konfiguriert.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/admin/sites/{id:[0-9]+}',
        'tag' => 'Site-Verbindungen',
        'summary' => 'Site-Key widerrufen',
        'description' => 'Markiert den Key als widerrufen; die Zeile bleibt bestehen. Ab sofort '
            . 'wird er nicht mehr akzeptiert — der Build, der ihn noch trägt, scheitert '
            . 'beim nächsten Lauf laut, statt still auf Rückfallinhalte zu wechseln.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Zeilen-Id des Keys.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannt oder bereits widerrufen.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/sites/handshake',
        'tag' => 'Site-Verbindungen',
        'summary' => 'Site meldet sich mit ihrem Key an (öffentlich)',
        'description' => 'Der Schritt, den der `/install`-Assistent auf jeder öffentlichen Site '
            . 'ausführt. Öffentlich aus Notwendigkeit: er läuft im Browser des Betreibers '
            . 'auf der Domain der Site, bevor irgendetwas verbunden ist. Der Key steht im '
            . '**Body** — nicht im Header (kein zusätzlicher Preflight) und nicht in der '
            . 'Query (ein Zugangsdatum im Zugriffsprotokoll überlebt seinen Zweck). '
            . 'Vermerkt „zuletzt gesehen", die Origin und die veröffentlichte `apiBase`; '
            . 'das ist der einzige Moment, in dem die API erfährt, dass es diese Site '
            . 'gibt. `cors` meldet den Zustand der **anfragenden** Origin, nicht der beim '
            . 'Key hinterlegten — sonst wäre die Auskunft auf einem Staging-Host '
            . 'überzeugend falsch.',
        'auth' => 'token',
        'params' => [
            ['in' => 'body', 'name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Der Site-Key im Klartext.'],
            ['in' => 'body', 'name' => 'site', 'type' => 'string', 'description' => 'Erwartete Site-Kennung; passt sie nicht, wird abgelehnt.'],
            ['in' => 'body', 'name' => 'apiBase', 'type' => 'string', 'description' => 'Die von der Site veröffentlichte API-Basis.'],
        ],
        'responses' => [
            [
                'status' => 200,
                'description' => '`{ok: true, site, label, cors: "allowed"|"missing", origin}`',
                'example' => '{"ok":true,"site":"blog","label":"Blog","cors":"allowed",'
                    . '"origin":"https://blog.tracht-digital.de"}',
            ],
            ['status' => 401, 'description' => 'Key unbekannt, widerrufen oder für eine andere Site.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/sites/pairings/exchange',
        'tag' => 'Site-Verbindungen',
        'summary' => 'Einmalige Freigabe serverseitig gegen Site-Zugang tauschen',
        'description' => 'Wird ausschließlich vom Server der öffentlichen Site aufgerufen. '
            . 'Die zehn Minuten gültige Freigabe ist an Profil und HTTPS-Origin gebunden '
            . 'und kann nur einmal ausgetauscht werden. Die Antwort mit Site-Key und '
            . 'Cache-Token darf nie an einen Browser weitergereicht werden.',
        'auth' => 'pairing-token',
        'responses' => [
            ['status' => 200, 'description' => 'Private Verbindungsdaten plus Finalisierungs-Token.'],
            ['status' => 401, 'description' => 'Freigabe unbekannt.'],
            ['status' => 403, 'description' => 'Profil oder Origin passen nicht.'],
            ['status' => 409, 'description' => 'Freigabe bereits ausgetauscht oder verwendet.'],
            ['status' => 410, 'description' => 'Freigabe abgelaufen oder ersetzt.'],
            ['status' => 429, 'description' => 'Zu viele Versuche.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/sites/pairings/finalize',
        'tag' => 'Site-Verbindungen',
        'summary' => 'Geprüfte, dauerhaft geschriebene Site-Verbindung aktivieren',
        'description' => 'Aktiviert den neuen Schlüssel erst, nachdem die Site ihre private '
            . 'Datei atomar geschrieben und wieder gelesen hat. Ein wiederholter Aufruf '
            . 'ist idempotent; beim Reconnect bleibt die alte Verbindung bis dahin aktiv.',
        'auth' => 'finalize-token',
        'responses' => [
            ['status' => 200, 'description' => 'Die geheimnisfreie aktive Verbindung.'],
            ['status' => 401, 'description' => 'Finalisierungs-Token ungültig.'],
            ['status' => 409, 'description' => 'Freigabe wurde noch nicht ausgetauscht.'],
            ['status' => 410, 'description' => 'Freigabe abgelaufen oder ersetzt.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/modules',
        'tag' => 'Module',
        'summary' => 'Lokal installierte Backend-Paketversionen anzeigen',
        'description' => 'Reine lokale Bestandsaufnahme. Die laufende API fragt keine '
            . 'Paketregistry ab und löst weder Builds noch Deployments aus.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{modules, packages}` mit lokal installierten Versionen.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
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
