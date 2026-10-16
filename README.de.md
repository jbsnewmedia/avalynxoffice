# AvalynxOffice

[English](README.md)

Ein selbst gehostetes Mail-Office auf Basis von Symfony 8: Abrufen (POP3),
Verwalten und Versenden (SMTP) von E-Mails — mit Web-Client, Kalender,
Kontakten, Admin-Bereich, Lizenzsystem und Plugin-Architektur.

## Lizenz

AvalynxOffice ist unter der **AvalynxOffice Public License (APL) Version 1.0**
lizenziert (`LICENSE`, `LICENSING.md`):

- Die Nutzung mit **bis zu 3 Active Seats ist kostenlos** (Community). Ein
  Active Seat ist ein aktiviertes Benutzerkonto mit mindestens einer
  erfolgreichen Authentifizierung (Login oder Geräte-Sync) innerhalb der
  letzten 30 Kalendertage.
- Ab dem 4. Active Seat ist eine **kommerzielle Lizenz erforderlich**
  (`COMMERCIAL-LICENSE.md`).
- Lizenzschlüssel werden über die Administrationsoberfläche (*Lizenz & Nutzung*)
  oder per ENV (`AVALYNXOFFICE_LICENSE_KEYS`, `AVALYNXOFFICE_LICENSE_FILE`) konfiguriert;
  die technische Dokumentation steht in `docs/licensing.md`.
- Exchange ActiveSync (iOS/Android/Outlook-Sync) ist ein **separates, optional
  installierbares Projekt** ([avalynxofficeeas](https://github.com/jbsnewmedia/avalynxofficeeas),
  AGPL-3.0) und kein Bestandteil dieses Repositories.

## Funktionen

- **Web-Client**: Mail-Reader mit Postfächern/Ordnern, Lesen, Schreiben und
  Anhängen; Darkmode und mehrsprachige Oberfläche.
- **Mail-Engine**: POP3-Abruf-Worker für konfigurierte Postfächer, SMTP-Versand
  über Connector pro Postfach, Duplikaterkennung, Header-Sanierung für kaputte
  Nachrichten.
- **Kalender**: Termine mit erweiterbarem Action-Plugin-System (Bearbeiten, Löschen, ...).
- **Kontakte**: gemeinsamer Adresspool für Mail und Geräte-Synchronisation.
- **Admin-Bereich**: Postfach- und Connector-Verwaltung, Lizenz & Nutzung,
  Einstellungen, Datenbank-Setup.
- **Plugin-System**: Symfony-Bundles plus `plugins.json`-Manifest
  (mail-action-bundle, mail-view-bundle, bootstrap-bundle).
- **Lizenzierung**: Sitzungskontingente mit Read-Only-Durchsetzung — im
  eingeschränkten Modus wird ausgehende Post angehalten (`license_hold`) und
  über `app:license:release-mail` freigegeben.
- **Produktiv-Image**: wartet auf die Datenbank, führt die mitgelieferten
  Migrationen aus und legt den env-definierten Administrator an (siehe unten).
- **Qualitäts-Gates**: PHPStan (Level max), ECS, Rector, PHPUnit-Suite (649 Tests)
  abgesichert durch einen GitHub-Actions-Workflow mit PostgreSQL-Service.

## Voraussetzungen

- Docker & Docker Compose
- PHP 8.5+ und Composer (nur bei lokaler Ausführung)

## Schnellstart (Entwicklung)

```bash
docker compose up -d
docker compose exec web composer install
docker compose exec web php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec web php bin/console app:user:create --email=admin@example.com --password=admin
```

Die Weboberfläche ist danach unter `http://localhost/` erreichbar. Für
Demo-Daten (Postfächer, E-Mails, Kalender, Kontakte — Login
`admin@example.com` / `admin`) stattdessen die Fixtures laden statt des
Benutzers:

```bash
docker compose exec web php bin/console doctrine:fixtures:load --no-interaction
```

## Produktiv-Image

Das Produktiv-Image (`docker/prod/Dockerfile`, gebaut vom `docker-image`
Workflow als [`jbsnewmedia/avalynxoffice`](https://hub.docker.com/r/jbsnewmedia/avalynxoffice))
bringt einen eigenen Startablauf mit:

1. Warten, bis die über `DATABASE_URL` konfigurierte Datenbank erreichbar ist
2. Ausführen der mit dieser Image-Version ausgelieferten Migrationen
   (idempotent — Doctrine wendet nur ausstehende Versionen an)
3. Anlegen des Administrators, wenn `OFFICE_ADMIN_EMAIL` und
   `OFFICE_ADMIN_PASSWORD` gesetzt sind (idempotent — existierender Benutzer
   wird übersprungen)
4. Start des Webservers

| Variable | Bedeutung |
|---|---|
| `DATABASE_URL` | PostgreSQL-Verbindung (zwingend) |
| `APP_SECRET` | Symfony-App-Secret, zwingend (z. B. `openssl rand -hex 16`) |
| `OFFICE_ADMIN_EMAIL` | E-Mail des Administrators, der beim Start angelegt wird (optional) |
| `OFFICE_ADMIN_PASSWORD` | Passwort des Administrators (optional, nur zusammen mit der E-Mail) |

Die Dev-Variante `jbsnewmedia/avalynxoffice-dev` enthält zusätzlich die
Dev-Abhängigkeiten und das Composer-Bin-Tooling, sodass die kompletten
Qualitäts-Gates gegen eine gemountete Arbeitskopie laufen.

## Command-Referenz

### Postfächer

```bash
# Postfach für POP3-Abruf und SMTP-Versand anlegen (SMTP übernimmt ohne Optionen
# die POP3-Zugangsdaten)
bin/console app:mailbox:add <EMAIL> <PASSWORT> --host=<HOST> --user=<USER> --name="<NAME>" --port=995 --security=ssl

# SMTP-Einstellungen eines bestehenden Postfachs anpassen
bin/console app:mailbox:configure-smtp <ID-oder-Email> --host=<SMTP-HOST> --port=465 --user=<USER> --password=**** --security=ssl
```

### Mail abrufen und senden

```bash
# Alle Postfächer abrufen (oder ein einzelnes, optional mit Limit)
bin/console app:mail:fetch
bin/console app:mail:fetch --mailbox=test@example.com --limit=50

# Alle als ausgehend markierten Mails senden; nach Import auf dem Server löschen
bin/console app:mail:send
bin/console app:mail:fetch --delete-after

# Vom Lizenzmodus angehaltene Nachrichten freigeben
bin/console app:license:release-mail
```

### Wartung

```bash
# Interaktive Initialisierung (Migrationen + erster Benutzer)
bin/console app:init

# Administrator nicht-interaktiv anlegen (vom Container-Bootstrap verwendet)
bin/console app:user:create --email=admin@example.com --password=...

# Alle Mail-bezogenen Daten löschen (Vorsicht!)
bin/console app:mail:clear

# Assets
bin/console assets:scss-compile
bin/console app:list-css-classes
bin/console app:purge-css
```

## Entwicklungstools (Docker)

```bash
docker compose exec web composer run bin-ecs-fix   # Code-Style-Fixer
docker compose exec web composer run bin-phpstan   # statische Analyse (Level max)
docker compose exec web composer run test          # Tests (lädt danach Fixtures)
docker compose exec web composer run ci-fix        # alle CI-Checks mit Fixes
```

Die Test-Kommandos laden nach jedem Lauf automatisch die Doctrine-Fixtures,
sodass die gemeinsame lokale Datenbank wieder mit Demo-Daten gefüllt ist.

## Live-Test gegen ein echtes Postfach

Ein dediziertes Test-Postfach verwenden und **nie mit `--delete-after` starten**.

1. Stack starten, migrieren und Fixtures laden:
   ```bash
   docker compose up -d
   docker compose exec web php bin/console doctrine:migrations:migrate --no-interaction
   docker compose exec web php bin/console doctrine:fixtures:load --no-interaction
   ```
2. Unter `http://localhost/` anmelden (`admin@example.com` / `admin`).
3. Echtes Test-Postfach anlegen:
   ```bash
   docker compose exec web php bin/console app:mailbox:add mailbox@example.com "MAILBOX-PASSWORT" --name="Live-Test" --host=pop.example.com --user=mailbox@example.com --port=995 --security=ssl
   ```
4. Falls SMTP andere Zugangsdaten nutzt, separat konfigurieren:
   ```bash
   docker compose exec web php bin/console app:mailbox:configure-smtp mailbox@example.com --host=smtp.example.com --port=465 --user=mailbox@example.com --password="SMTP-PASSWORT" --security=ssl
   ```
5. Ersten Abruf vorsichtig mit Limit:
   ```bash
   docker compose exec web php bin/console app:mail:fetch --mailbox=mailbox@example.com --limit=10
   ```
6. Browser neu laden, neues Postfach auswählen und Inbox, Mail-Detail und
   Anhänge prüfen. Über die UI (**Neue E-Mail**) eine echte Testmail an eine
   externe Adresse schreiben und den Versand mit `app:mail:send` anstoßen.
7. Von der externen Adresse antworten, erneut abrufen und Absender, Empfänger
   und Postfach-Zuordnung in der UI prüfen.
8. Ganz am Ende optional die Server-Löschung mit `--delete-after` testen.

Empfohlene Reihenfolge: **Abruf** → **UI** → **Versand** → **Antwort** →
`--delete-after` zuletzt.

## Projektstruktur

- `src/Controller/`: Web-Client, Kalender, Kontakte, Admin-Bereich
- `src/Command/`: Konsolenbefehle (Postfächer, Mail-Engine, Assets, Lizenzierung)
- `src/Service/Mail/`: POP3-Abruf (`Pop3Fetcher`), Ordnerverwaltung (`FolderManager`), SMTP-Factory, Attachment-Speicher
- `src/Licensing/`: Lizenzsystem (Key-Validierung, Active Seats, Kulanzfrist, Read-Only-Durchsetzung)
- `src/Plugin/` + `plugins/jbsnewmedia/`: Plugin-Loading und First-Party-Bundles
- `docs/`: technische Dokumentation (z. B. `docs/licensing.md`)
- `docker/`: `docker/web` = Dev-Stack, `docker/prod` = Produktiv-Image, `docker/postgres` = Datenbank

## Attachment-Speicher

Konfiguration über `.env`:

```dotenv
ATTACHMENT_STORAGE_DIR=data/attachments
ATTACHMENT_STORAGE_SEGMENT_LENGTH=3
ATTACHMENT_STORAGE_SEGMENT_DEPTH=3
ATTACHMENT_STORAGE_MAX_BYTES=65536
```

Dadurch entsteht eine Struktur wie `data/attachments/abc/def/ghi/<uuid>.pdf`.
Größere Anhänge als das konfigurierte Limit werden beim Import übersprungen.
