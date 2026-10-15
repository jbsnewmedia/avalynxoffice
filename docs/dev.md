# Entwickler-Anleitung

Diese Anleitung enthält alle wichtigen Informationen für die Entwicklung an diesem Projekt.

## 1. Docker-Umgebung starten

Das Projekt nutzt Docker Compose für die lokale Entwicklungsumgebung.

### Container starten
```bash
docker-compose up -d
```

### In den Container wechseln
Es gibt Hilfsskripte im `docker/` Verzeichnis, um schnell in den Web-Container zu gelangen:

- Als Standard-User (`application`):
  ```bash
  ./docker/bash.sh
  ```
- Als `root`:
  ```bash
  ./docker/root.sh
  ```

Der Web-Container ist unter `http://localhost` erreichbar.

---

## 2. Installation der Abhängigkeiten

Nachdem der Container gestartet ist, müssen die PHP-Abhängigkeiten installiert werden. Dies sollte **innerhalb** des Containers ausgeführt werden.

### Haupt-Abhängigkeiten
```bash
composer install
```

### Vendor-Bin Tools
Das Projekt verwendet `bamarni/composer-bin-plugin` zur isolierten Installation von Entwicklungs-Tools.

Um alle Tools zu installieren oder zu aktualisieren, können die Composer-Skripte genutzt werden:

```bash
COMPOSER_MEMORY_LIMIT=-1 composer run bin-ecs-install
COMPOSER_MEMORY_LIMIT=-1 composer bin-phpstan-install
COMPOSER_MEMORY_LIMIT=-1 composer bin-phpunit-install
COMPOSER_MEMORY_LIMIT=-1 composer bin-rector-install
```

---

## 3. Datenbank einrichten

Die Datenbank wird automatisch über Docker gestartet (PostgreSQL). Initial müssen die Migrationen ausgeführt werden.

```bash
bin/console doctrine:migrations:migrate --no-interaction
```

---

## 4. Testdaten erstellen

Um die Anwendung mit Testdaten zu füllen, werden Doctrine Fixtures und Zenstruck Foundry verwendet.

### Fixtures laden
Dieser Befehl leert die Datenbank und lädt die vordefinierten Testdaten aus `src/DataFixtures/`:

```bash
bin/console doctrine:fixtures:load --no-interaction
```
*(Option `--append` nutzen, wenn Daten nicht gelöscht werden sollen)*

**Hinweis:** Die Fixtures erstellen standardmäßig einen Admin-Benutzer:
- **E-Mail:** `admin@example.com`
- **Passwort:** `admin`
- **Rolle:** `ROLE_ADMIN`

Zusätzlich werden ca. 1000 Test-E-Mails, diverse Postfächer und Ordner generiert.

### Datenbank initialisieren (Migrationen & Fixtures)
Um die Datenbank komplett neu aufzusetzen (Migrationen + Fixtures), kann folgender Befehl genutzt werden:
```bash
bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:fixtures:load --no-interaction
```

### Mail-Daten manuell leeren
Es gibt einen spezifischen Befehl, um nur mail-bezogene Tabellen zu leeren:
```bash
bin/console app:mail:clear
```

---

## 5. Vendor-Bin Tools ausführen

Für die Code-Qualität und Tests stehen verschiedene Tools zur Verfügung. Diese können bequem über Composer-Skripte aufgerufen werden.

### Code Style (ECS / PHP-CS-Fixer)
- Prüfen: `composer bin-ecs`
- Automatisch fixen: `composer bin-ecs-fix`

### Statische Analyse (PHPStan)
- Analysieren: `composer bin-phpstan`

### Refactoring (Rector)
- Dry-run: `composer bin-rector`
- Änderungen anwenden: `composer bin-rector-process`

### Tests (PHPUnit)
- Alle Tests ausführen: `composer test`
- Mit Coverage (HTML): `composer test-coverage` (erzeugt Bericht in `var/coverage`)
- Vollständige Tests: `composer test-full`

---

## 6. Spezifische App-Befehle

Das Projekt enthält einige maßgeschneiderte Befehle für die Mail-Verarbeitung:

- **E-Mails abrufen (POP3):**
  ```bash
  bin/console app:mail:fetch
  ```
- **E-Mails versenden:**
  ```bash
  bin/console app:mail:send
  ```
- **Angehaltene (lizenzbedingte) Nachrichten freigeben:**
  ```bash
  bin/console app:license:release-mail
  ```
- **Mailbox hinzufügen:**
  ```bash
  bin/console app:mailbox:add
  ```
- **SMTP konfigurieren:**
  ```bash
  bin/console app:mailbox:configure-smtp
  ```

---

## 6a. Lizenzsystem

Die technische Dokumentation steht in `docs/licensing.md`; die rechtlichen
Texte in `LICENSE`, `LICENSING.md` und `COMMERCIAL-LICENSE.md`.

- **Status prüfen:** Administrationsoberfläche `/admin/license` (Rolle
  `ROLE_ADMIN`) bzw. `/license` für Benutzer.
- **ENV-Konfiguration:** `AVALYNXOFFICE_LICENSE_KEYS`, `AVALYNXOFFICE_LICENSE_FILE`,
  `AVALYNXOFFICE_LICENSE_PUBLIC_KEYS`, `AVALYNXOFFICE_ORGANIZATION_ID` (siehe `.env`).
- **Lizenztests ausführen:**
  ```bash
  docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "vendor-bin/phpunit/vendor/bin/phpunit --configuration=phpunit-no-coverage.xml.dist tests/Licensing"
  ```
- **Ausstellungswerkzeug (nur Herausgeber, nicht Bestandteil der
  Installation):** `bin/license-tool` (keygen / issue / decode / verify).

---

## 7. Assets & Frontend

### SCSS Kompilieren
```bash
bin/console bootstrap:compile
```

### CSS Purging (Unnötiges CSS entfernen)
```bash
bin/console assets:css-purge
```

---

## 8. Tools vom Host aus ausführen (ohne bash)

Wenn man nicht in den Docker-Container wechseln möchte, können die Befehle auch direkt vom Host-System aus gestartet werden:

### Code Style fixen
```bash
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-ecs-fix"
```

### Statische Analyse
```bash
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpstan"
```

### Rector (Refactoring)
```bash
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-rector-process"
```

### Tests
```bash
# Alle Tests
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit"

# Ohne Coverage
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit-no-coverage"

# Mit Coverage
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit-coverage"
```
