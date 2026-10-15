# AvalynxOffice

Ein Symfony 8 basiertes System zum Abrufen (POP3), Verwalten und Versenden (SMTP) von E-Mails.

## Lizenz

AvalynxOffice ist **Source-Available** lizenziert (`LICENSE`, `LICENSING.md`):

- Die Nutzung mit **bis zu 3 aktivierten Benutzerkonten ist kostenlos** (Community).
- Ab 4 Benutzerkonten ist eine **kommerzielle Lizenz** erforderlich (`COMMERCIAL-LICENSE.md`).
- Lizenzschlüssel werden über die Administrationsoberfläche (*Lizenz & Nutzung*)
  oder per ENV (`AVALYNXOFFICE_LICENSE_KEYS`, `AVALYNXOFFICE_LICENSE_FILE`) konfiguriert;
  die technische Dokumentation steht in `docs/licensing.md`.
- Exchange ActiveSync (iOS/Android/Outlook-Sync) ist ein **separates, optional
  installierbares Plugin** (`avalynxofficeeas`, AGPL-3.0, eigenes Repository)
  und kein Bestandteil dieses Projekts.

## Features

- **POP3 Import**: Automatischer Abruf von E-Mails aus konfigurierten Postfächern.
- **SMTP Versand**: Versenden von ausgehenden E-Mails über konfigurierbare SMTP-Server.
- **Datenbank-Struktur**: Umfassendes Datenmodell für E-Mails, Anhänge, Ordner und Kontakte.
- **Docker Ready**: Vorkonfigurierte Umgebung mit PHP und PostgreSQL.

## Voraussetzungen

- Docker & Docker Compose
- PHP 8.5+ (falls lokal ausgeführt)
- Composer

## Installation

1. Repository klonen
2. Docker Container starten:
   ```bash
   docker-compose up -d
   ```
3. Abhängigkeiten installieren:
   ```bash
   docker-compose exec web composer install
   ```
4. Datenbank-Migrationen ausführen:
    ```bash
    docker-compose exec web bin/console doctrine:migrations:migrate
    ```
5. Fixture-Daten laden:
    ```bash
    docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "php bin/console doctrine:fixtures:load --no-interaction"
    ```

## Production-Image

Das Produktiv-Image (`docker/prod/Dockerfile`, gebaut vom `docker-image`
Workflow als `jbsnewmedia/avalynxoffice`) bringt einen eigenen
Container-Startablauf mit:

1. Warten bis die über `DATABASE_URL` konfigurierte Datenbank erreichbar ist
2. Ausführen der mit dieser Image-Version ausgelieferten Migrationen
   (idempotent – Doctrine wendet nur ausstehende Versionen an)
3. Anlegen des Administrators, wenn `OFFICE_ADMIN_EMAIL` und
   `OFFICE_ADMIN_PASSWORD` gesetzt sind (idempotent – existiert der
   Benutzer bereits, wird er übersprungen)
4. Start des Webservers

| Variable | Bedeutung |
|---|---|
| `DATABASE_URL` | PostgreSQL-Verbindung (zwingend) |
| `APP_SECRET` | Symfony-Secret, zwingend (z. B. `openssl rand -hex 16`) |
| `OFFICE_ADMIN_EMAIL` | E-Mail des Administrators, der beim Start angelegt wird (optional) |
| `OFFICE_ADMIN_PASSWORD` | Passwort des Administrators (optional, nur zusammen mit der E-Mail) |

## Command Referenz

Das System bietet verschiedene Konsolenbefehle zur Verwaltung der Mailboxen und E-Mails.

### 1. Mailbox hinzufügen
Legt eine neue Mailbox für POP3-Abruf und SMTP-Versand an.

```bash
bin/console app:mailbox:add <EMAIL> <PASSWORD> --host=<HOST> --user=<USER> --name="<NAME>" --port=995 --security=ssl
```

**Beispiel All-Inkl:**
```bash
bin/console app:mailbox:add test@jbs-newmedia.de "WZ6TA7auCZjieu@KXQbP" --host=w019df8c.kasserver.com --user=m079cb9d --name="JBS Newmedia Test" --port=995 --security=ssl
```
*Hinweis: Wenn keine spezifischen SMTP-Optionen angegeben werden, übernimmt das System automatisch die POP3-Daten für SMTP.*

### 2. SMTP Konfiguration anpassen
Aktualisiert die SMTP-Einstellungen einer bestehenden Mailbox.

```bash
bin/console app:mailbox:configure-smtp <ID-oder-Email> --host=<SMTP-HOST> --port=465 --user=<USER> --password=**** --security=ssl
```

### 3. E-Mails abrufen (POP3)
Ruft neue E-Mails ab.

```bash
# Alle Mailboxen abrufen
bin/console app:mail:fetch

# Bestimmte Mailbox abrufen
bin/console app:mail:fetch --mailbox=test@jbs-newmedia.de

# Mit Limit und Löschen auf dem Server
bin/console app:mail:fetch --limit=50 --delete-after
```

### 4. E-Mails versenden (SMTP)
Versendet alle Mails, die in der Datenbank als "ausgehend" (isOutgoing) markiert, aber noch nicht versendet sind. Im lizenzbedingten Read-Only-Modus werden wartende Nachrichten angehalten (`license_hold`) und erst nach Freigabe versendet.

```bash
bin/console app:mail:send

# Angehaltene Nachrichten nach Lizenzwiederherstellung freigeben
bin/console app:license:release-mail
```

### 5. Datenbank bereinigen
Löscht alle E-Mail-bezogenen Daten aus der Datenbank (Vorsicht!).

```bash
bin/console app:mail:clear
```

## Entwicklungstools (Docker)

Die Entwicklungstools können direkt über Docker ausgeführt werden. Es wird empfohlen, `docker-compose exec` zu verwenden:

```bash
# Code Style Fixer (ECS) ausführen
docker-compose exec web composer run bin-ecs-fix

# Statische Analyse (PHPStan)
docker-compose exec web composer run bin-phpstan

# Tests ausführen
docker-compose exec web composer run test

# Alle CI-Checks (Fix)
docker-compose exec web composer run ci-fix
```

Hinweis: Die Test-Kommandos laden nach jedem Lauf automatisch wieder die Doctrine-Fixtures, damit die gemeinsam genutzte lokale Datenbank danach direkt wieder mit Demo-Daten gefuellt ist.

## Live-Test mit echtem Postfach

Wenn du den aktuellen Stand gegen ein echtes Postfach pruefen willst, nimm am besten **ein eigenes Test-Postfach** und **arbeite anfangs nie mit `--delete-after`**.

1. **Container starten und Grunddaten herstellen**
   ```bash
   docker compose up -d
   docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "php bin/console doctrine:migrations:migrate --no-interaction"
   docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "php bin/console doctrine:fixtures:load --no-interaction"
   ```
2. **Im Browser anmelden**
   - URL: `http://localhost/`
   - Login aus den Fixtures: `admin@example.com`
   - Passwort: `admin`
3. **Echtes Test-Postfach als Mailbox anlegen**
   ```bash
   docker exec --user=application -w /app avalynxoffice-web-1 bash -lc 'php bin/console app:mailbox:add mailbox@example.com "MAILBOX_PASSWORT" --name="Live Test" --host=pop.example.com --user=mailbox@example.com --port=995 --security=ssl'
   ```
4. **Falls SMTP andere Zugangsdaten oder einen anderen Host hat, SMTP separat setzen**
   ```bash
   docker exec --user=application -w /app avalynxoffice-web-1 bash -lc 'php bin/console app:mailbox:configure-smtp mailbox@example.com --host=smtp.example.com --port=465 --user=mailbox@example.com --password="SMTP_PASSWORT" --security=ssl'
   ```
5. **Ersten Abruf vorsichtig mit Limit testen**
   ```bash
   docker exec --user=application -w /app avalynxoffice-web-1 bash -lc 'php bin/console app:mail:fetch --mailbox=mailbox@example.com --limit=10'
   ```
6. **Import im UI pruefen**
   - Browser neu laden
   - In der linken Spalte die neue Mailbox auswaehlen
   - Inbox, Mailbox und Anhaenge pruefen
7. **Eine echte Testmail versenden**
   - Im UI auf **Neue E-Mail** gehen
   - An eine zweite, externe Testadresse senden
   - Danach den Versand anstossen:
     ```bash
     docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "php bin/console app:mail:send"
     ```
8. **Rueckweg testen**
   - Auf die gesendete Mail von der externen Adresse antworten
   - Danach erneut abrufen:
     ```bash
     docker exec --user=application -w /app avalynxoffice-web-1 bash -lc 'php bin/console app:mail:fetch --mailbox=mailbox@example.com --limit=10'
     ```
   - Im UI pruefen, ob Antwort, Absender, Empfaenger und Zuordnung zur Mailbox stimmen
9. **Erst ganz zum Schluss optional mit Server-Loeschung testen**
   ```bash
   docker exec --user=application -w /app avalynxoffice-web-1 bash -lc 'php bin/console app:mail:fetch --mailbox=mailbox@example.com --limit=10 --delete-after'
   ```

Empfohlene Reihenfolge fuer den Live-Test: zuerst **Abruf**, dann **Anzeige im UI**, dann **Versand**, dann **Antwort auf Versand**, und `--delete-after` wirklich erst am Ende.

## Projektstruktur

- `src/Entity/`: Datenmodelle (Email, Mailbox, Address, Attachment, etc.)
- `src/Command/`: Konsolenbefehle für die Verwaltung (`app:license:release-mail` inklusive).
- `src/Service/Mail/`: Logik für POP3-Abruf (`Pop3Fetcher`) und Ordnerverwaltung (`FolderManager`).
- `src/Licensing/`: Lizenzsystem (Key-Validierung, Kontingente, Kulanzfrist, Read-Only-Durchsetzung).
- `docker/`: Docker-Konfigurationen für Webserver und Datenbank (`docker/web` = Dev, `docker/prod` = Produktions-Image).

## Wichtige Pfade

- **Anhänge**: Werden als echte Dateien im konfigurierten Attachment-Verzeichnis gespeichert (`ATTACHMENT_STORAGE_DIR`, standardmäßig `data/attachments`) und per UUID in drei Ebenen segmentiert.
- **Mail Dumps**: Original-E-Mails können zur Fehlersuche in `mail_dumps/` gespeichert werden.

## Attachment-Konfiguration

Die Attachment-Ablage wird über `.env` gesteuert:

```dotenv
ATTACHMENT_STORAGE_DIR=data/attachments
ATTACHMENT_STORAGE_SEGMENT_LENGTH=3
ATTACHMENT_STORAGE_SEGMENT_DEPTH=3
ATTACHMENT_STORAGE_MAX_BYTES=65536
```

Standardmäßig entsteht damit eine Struktur wie `data/attachments/abc/def/ghi/<uuid>.pdf`. Größere Anhänge als das konfigurierte Limit werden beim Import übersprungen.
