# Die öffentlichen Sites anbinden und pflegen

Betreiberhandbuch für die drei öffentlichen Properties — `tracht-digital.de`,
`blog.tracht-digital.de` und `tools.tracht-digital.de`. Es beantwortet drei
Fragen: **wie eine Site an diese API kommt**, **wo welcher Inhalt bearbeitet
wird**, und **wie eine Änderung live geht**.

Die beiden Panels (Verwaltung, Kundenportal) binden sich nach einem anderen
Modell an dieselbe API. Der Vergleich steht in [§1.5](#15-die-beiden-panels-binden-sich-anders--und-nicht-hier);
die Anleitung im jeweiligen Produkt-Repo.

Für Entwicklungsdetails: `AGENTS.md` im jeweiligen Repo. Für die Einrichtung des
Hosts: `tds-gateway-api/DEPLOY-PLESK.md`.

---

## Das Wichtigste in drei Sätzen

Die drei Sites rendern seit dem 24.08.2026 **auf Anfrage** (Astro SSR unter
Node) und legen jede gerenderte Seite als gewöhnliche Datei ab, die der
Webserver direkt ausliefert. Ein Treffer ist damit **genauso schnell wie der
frühere statische Build** — es ist dieselbe Datei.

Inhalt zu ändern kostet deshalb **kein Repo-Deploy mehr**, sondern einen
Cache-Neubau der betroffenen Seiten: Sekunden statt Minuten. Ein Deploy braucht
nur noch, wer **Code oder Gestaltung** ändert.

---

## 1. Eine Site anbinden

Vier Schritte, in dieser Reihenfolge. Die Reihenfolge ist nicht Geschmack:
Schritt 3 kann Schritt 2 nicht ersetzen, und Schritt 4 prüft beide.

### 1.1 Site-Key ausstellen

**Einstellungen → Site-Verbindungen → Schlüssel ausstellen**, Site auswählen
(`landingpage`, `blog`, `tools`).

Der Schlüssel wird **einmal** angezeigt und nur als SHA-256-Hash gespeichert —
verloren heißt neu ausstellen, nicht nachschlagen. Er identifiziert die Site
gegenüber den öffentlichen Lese-Routen (`/content/*`, `/tools/catalog`,
`/tools/guides`).

### 1.2 Cache-Token setzen

Der Site-Key taugt hier **nicht**: er ist nur als Hash gespeichert, die API
könnte ihn also gar nicht senden. Die Richtung ist umgekehrt — hier ruft die API
die Site.

| Site | Wo |
|---|---|
| Landingpage | Website-CMS → Site öffnen → **Seiten-Cache** → Adresse; Token unter Einstellungen → *Website-CMS* |
| Blog | Blog-CMS → Blog öffnen → **Seiten-Cache** → Adresse; Token unter Einstellungen → *Blog-CMS* |
| Tools | Einstellungen → *Tools / AdSense* → **Seiten-Cache: Basis-URL** + **Token** |

Die **Adresse** ist die Herkunft der öffentlichen Site
(`https://blog.tracht-digital.de`), ohne Pfad. Das **Token** ist frei gewählt und
muss auf dem Host als Umgebungsvariable `TDS_CACHE_TOKEN` der Node-App stehen.

> **Ohne Token antwortet die Kontrollebene der Site mit `503` und tut nichts.**
> Das ist Absicht: ein unauthentifizierter Rebuild-Endpunkt auf einer
> öffentlichen Domain ist eine kostenlose Render-Verstärkung.

### 1.3 Auf dem Host eintragen

In der Node-App der Site (Plesk → *Node.js* → *Custom environment variables*):

| Variable | Wert |
|---|---|
| `TDS_SITE_KEY` | der Schlüssel aus 1.1 |
| `TDS_CACHE_TOKEN` | dasselbe Token wie in 1.2 |
| `TDS_CACHE_DIR` | Ablage der gerenderten Seiten, **außerhalb** des Git-Checkouts |
| `TDS_CACHE_META_DIR` | Ablage der Metadaten, ebenfalls außerhalb |

> **Beides gehört NICHT in `tds-runtime.json`.** Diese Datei liegt öffentlich im
> Docroot; ein Token darin wäre veröffentlicht. Der `/install`-Assistent
> schreibt sie und kennt diese Schlüssel bewusst nicht.

Details zu App-Root, DocumentRoot und Startdatei: `DEPLOY-PLESK.md`.

### 1.4 Nachweisen, dass es wirklich geht

```sh
# Zweimal dieselbe Seite. Beim zweiten Mal muss HIT stehen.
curl -sI https://blog.tracht-digital.de/ | grep -i x-tds-cache

# Die Kontrollebene, mit dem Token aus 1.2.
curl -s -H 'x-tds-cache-token: …' https://blog.tracht-digital.de/tds/cache/status
```

`status` nennt Verzeichnis, Anzahl der Einträge, ältesten und neuesten Stand.
**Antwortet es `401`, stimmt das Token nicht; `503` heißt, auf dem Host ist gar
keins gesetzt.** Beides sind Konfigurationsfehler, keine Ausfälle.

### 1.5 Die beiden Panels binden sich anders — und nicht hier

Verwaltung (`management.tracht-digital.de`) und Kundenportal
(`app.tracht-digital.de`) sprechen mit derselben API, aber **die vier Schritte
oben gelten für sie nicht**. Der Unterschied ist keine Inkonsistenz, sondern
folgt aus dem, was die beiden Sorten sind.

| | Öffentliche Sites | Panels |
|---|---|---|
| Wann die Anbindung entschieden wird | zur **Laufzeit** | zur **Build-Zeit** |
| Wie | `/install` koppelt, Zugangsdaten liegen außerhalb des Checkouts | `PUBLIC_API_BASE` / `PUBLIC_AUTH_API_URL` / `PUBLIC_LOGIN_URL` beim Bauen |
| Umhängen kostet | einen Assistentenlauf | einen Neubau **und** ein Deployment |
| Wer sich ausweist | die **Site** (Site-Key) | die **Person** (Sitzungs-Cookie) |
| Seiten-Cache | ja, dateibasiert | nein, jede Seite ist besucherindividuell |

Ein Panel liest `tds-runtime.json` bewusst **nie**: die Shell des Hosts
schreibt `<meta name="tds-api-base">`, und `runtimeConfig()` in
`tds-shared/api` bricht ab, sobald dieses Tag vorhanden ist. Ohne diese Bremse
würde jede Navigation im Panel einen garantierten 404 für eine Datei auslösen,
die nur die öffentlichen Sites besitzen.

Anleitung im jeweiligen Repo: `tds-admin-frontend/INSTALL.md` §5.2 bzw.
`tds-customer-frontend/README.md` → *Bind it to an API*.

#### Was ein Panel von dieser API zusätzlich braucht: seine Herkunft in CORS

Ein Panel ruft über Herkunftsgrenzen hinweg und **mit Anmeldedaten**
(`credentials: "include"`). Dafür muss die Antwort die Herkunft des Panels
**exakt** nennen — der `*`-Platzhalter ist zusammen mit Anmeldedaten verboten.

Drei Ebenen liefern diese Liste, und sie werden **vereinigt, nicht
übersteuert**:

1. eine eingebaute Grundmenge (die eigenen Produktionsdomains),
2. `CORS_ALLOWED_ORIGINS` aus der `.env` des Hosts,
3. was ein Admin unter **Einstellungen → CORS** einträgt.

Die Vereinigung ist Absicht: könnte Ebene 3 die anderen ersetzen, würde ein
Tippfehler im Panel das Panel aussperren, das ihn korrigieren müsste.

> **Der Fehler sieht nach keinem Fehler aus.** Fehlt die Herkunft, verwirft der
> **Browser** die Antwort, bevor Anwendungscode sie sieht. Auf dieser Seite
> passiert nichts Auffälliges: kein Fehlerstatus, kein Logeintrag. Im Panel
> erscheint genau die Darstellung, die auch „keine Daten vorhanden" bedeutet.
> Wer eine leere Liste sieht, prüft deshalb zuerst das Netzwerk-Panel des
> Browsers auf `Access-Control-Allow-Origin` — nicht die Datenbank.


---

## 2. Wo welcher Inhalt bearbeitet wird

| Was | Wo im Panel | Wirkt auf |
|---|---|---|
| Sektionen der Startseite und der Preisseite | **Website** → Site → Sektionen | `/`, `/preise` (+ `/en/…`) |
| Impressum, Datenschutzerklärung | **Website** → Site → Sektionen → `legal_impressum` / `legal_datenschutz` | `/legal/impressum`, `/legal/datenschutz` |
| AGB (PDF) | **Website** → Site → Rechtsdokumente | `/legal/agb`, `/legal/agb.pdf` |
| Blogartikel | **Blog** → Blog → Beiträge | Artikel, Druckansicht, Index, Archiv, Kategorie, Tags, Autorenseite, Feed, Sitemap |
| Blog-Autoren | **Blog** → Autoren | Autorenseiten |
| Tool-Katalog (sichtbar, Login, Premium, Preis) | **Tools** → Tabelle | Katalogseiten und die betroffene Tool-Seite |
| Tool-Texte und Ratgeber | **Tools** → *Texte der Tool-Seiten* | die jeweilige Tool-Seite |
| Hilfe-Artikel und FAQs | **Wiki-Inhalte** | Kunden-Wiki und das Chat-Widget |

### Die Regel, die man einmal verstanden haben muss

**Alles, was im Panel steht, ist eine ÜBERSTEUERUNG.** Die Texte, die mit dem
Repository ausgeliefert wurden, bleiben die Quelle: ein leeres Feld heißt „nimm
den mitgelieferten Text", nicht „hier steht nichts".

Das hat zwei Konsequenzen, die im Alltag zählen:

- Eine **leere oder nicht erreichbare Datenbank kann keine Seite leeren**. Im
  schlimmsten Fall ist eine Seite veraltet, nie weg. Bei einer
  Datenschutzerklärung ist das der ganze Punkt.
- **Ein Feld zu leeren nimmt die Bearbeitung zurück.** Es gibt keinen zweiten
  Mechanismus dafür, und es braucht keinen.

---

## 3. Wie eine Änderung live geht

### Der Normalfall: gar nichts tun

Speichern löst den Cache-Neubau der betroffenen Seiten selbst aus. Für einen
Blogartikel sind das seine eigene Seite, die Druckansicht, der Index, das
Archiv, seine Kategorie, jeder seiner Tags, die Autorenseite, der Feed, der
„Für Sie"-Index und die Sitemap.

### Der Knopf: „Seiten-Cache neu bauen"

Für den Fall, dass etwas dazwischenkam — die Site war beim Speichern nicht
erreichbar, oder es wurde direkt in der Datenbank geändert. In der Blog-Liste
gibt es ihn **pro Artikel**.

### Der andere Knopf: „Jetzt neu bauen"

Das ist etwas völlig anderes: ein **CI-Build**, der Code ausliefert. Beim Blog
laufen dabei zusätzlich die DeepL-Übersetzungen erneut und je Beitrag wird eine
OG-Karte neu gerendert. Minuten statt Sekunden.

**Nötig für:** Gestaltungsänderungen, neue Seiten, neue Tools, ein Update der
geteilten Bibliothek. **Nicht nötig für:** Inhalt.

### Was ein Cache-Neubau NICHT ist

Er **löscht nichts**. Eine Seite wird gerendert und erst danach ausgetauscht.
Das ist der Grund, warum ein Neubau auch dann sicher ist, wenn die API gerade
klemmt: im schlimmsten Fall bleibt der alte Stand stehen.

Ein *Purge* (nur über die API, nicht im Panel) löscht wirklich — und ist genau
deshalb während einer Störung gefährlich: alle Inhaltsabrufe der Sites sind
absichtlich fehlertolerant, ein Neu-Rendern würde also **erfolgreich** die
mitgelieferten Platzhalter einbacken.

---

## 4. Wenn etwas nicht stimmt

| Beobachtung | Wahrscheinliche Ursache |
|---|---|
| Änderung im Panel gespeichert, Seite zeigt weiter das Alte | Cache-Adresse fehlt (der Abschnitt meldet das in der Seite) oder das Token auf dem Host weicht ab |
| `/tds/cache/status` antwortet `401` | Token im Panel ≠ `TDS_CACHE_TOKEN` auf dem Host |
| `/tds/cache/status` antwortet `503` | auf dem Host ist gar kein `TDS_CACHE_TOKEN` gesetzt |
| `X-TDS-Cache` steht nie auf `HIT` | die `.htaccess` greift nicht, oder das Cache-Verzeichnis ist nicht beschreibbar — die Site funktioniert weiter, rendert aber jedes Mal neu |
| Site zeigt überall die mitgelieferten Texte | Site-Key falsch oder abgelehnt; die Site cached eine solche Antwort bewusst **nicht** |
| Eine Kategorieseite listet einen umkategorisierten Artikel weiter | bekannt und bewusst: das Ereignis kennt den *vorherigen* Wert nicht. „Alles neu bauen" räumt es auf |

**Nichts davon ist laut.** Alle Inhaltsabrufe sind fehlertolerant, damit eine
Störung der API keine Site abschaltet — der Preis ist, dass eine
Fehlkonfiguration wie „alles in Ordnung, nur alt" aussieht. Deshalb ist Schritt
1.4 kein optionaler Abschluss.

---

## 5. Was wo liegt (für den Notfall)

```
<vhost>/httpdocs/            ← App-Root der Node-App
  app.cjs                    ← Startdatei (CommonJS, siehe DEPLOY-PLESK.md)
  server/entry.mjs           ← der SSR-Server. NICHT über das Web erreichbar
  client/                    ← DocumentRoot
    .htaccess                ← Cache-First-Regeln
    _tds-cache -> …          ← Symlink auf die Ablage, bei jedem Start erneuert
  node_modules/              ← vorgebaut, ohne Erstanbieter-Pakete
  tmp/restart.txt            ← anfassen startet die App neu
<vhost>/tds-cache/pages/     ← die gerenderten Seiten
<vhost>/tds-cache/meta/      ← Metadaten, absichtlich außerhalb des Web-Baums
```

Der Speicher liegt **außerhalb** des Git-Checkouts, weil ein Deploy alles
entfernen darf, was er nicht kennt. Zerstört ein Deploy den Symlink, legt ihn
der nächste Start neu an — und ein Neustart gehört ohnehin zu jedem Deploy.

Das Cache-Verzeichnis lässt sich jederzeit gefahrlos leeren: die nächste Anfrage
rendert neu.
