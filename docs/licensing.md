# Lizenzierung (technische Dokumentation)

Diese Seite dokumentiert die technische Umsetzung des Lizenzsystems.

## Modell

```
allowedSeats = 3 + sum(validLicenses.additionalSeats)
```

- Gezählt werden **aktivierte persönliche Benutzerkonten** (`app_user.active = true`).
  Deaktivierte und gelöschte Konten zählen nicht. E-Mail-Adressen, Postfächer,
  Kalender und Kontakte zählen nicht.
- Technische Servicekonten ohne persönlichen Zugang sind ausgenommen; das
  bestehende Datenmodell enthält noch kein unterscheidbares Servicekonto-Konzept.
  Existiert später eines (z. B. Flag `isServiceAccount`), ist es in
  `UserRepository::countActiveUsers()` zu ergänzen.
- Lizenzen unterschiedlicher Organisationen werden nicht kombiniert; Schlüssel
  mit identischer `license_id` werden nur einmal gezählt.

## Schlüsselformat

```
WOL1.PAYLOAD.SIGNATURE
PAYLOAD   = Base64URL(JSON-Payload)
SIGNATURE = Base64URL(Ed25519-Signatur über "WOL1.PAYLOAD")
```

Payload-Schema (Version 1, strikt validiert):

```json
{
  "version": 1,
  "key_id": "issuer-2026-01",
  "license_id": "LIC-2026-000001",
  "customer_id": "CUSTOMER-001",
  "organization_id": "org-example",
  "additional_seats": 7,
  "valid_from": "2026-10-13T00:00:00Z",
  "expires_at": "2027-10-13T00:00:00Z",
  "installation_id": null
}
```

`expires_at` ist exklusiv. Die Signatur wird über die **originalen signierten
Bytes** (`WOL1.PAYLOAD`) geprüft (`sodium_crypto_sign_verify_detached`), ohne
das JSON neu zu serialisieren.

## Schlüsselrotation

Mehrere öffentliche Schlüssel werden über `key_id` unterstützt:

```dotenv
AVALYNXOFFICE_LICENSE_PUBLIC_KEYS='{"issuer-2026-01":"<base64-public-key>","issuer-2027-01":"<base64-public-key>"}'
```

Nur bekannte `key_id`-Werte werden akzeptiert; die Zuordnung stammt ausschließlich
aus dieser vertrauenswürdigen Konfiguration, nie aus Lizenzdaten.

## Konfiguration

```dotenv
AVALYNXOFFICE_LICENSE_KEYS=""          # Komma- oder zeilenumbruchgetrennte Schlüssel
AVALYNXOFFICE_LICENSE_FILE=""          # optionale Datei mit weiteren Schlüsseln
AVALYNXOFFICE_LICENSE_PUBLIC_KEYS=""   # JSON: key_id => öffentlicher Ed25519-Schlüssel
AVALYNXOFFICE_ORGANIZATION_ID=""       # Organisation der Installation
```

- Fehlende ENV-Werte sind kein Fehler; die Installation läuft im Community-Modus.
- `AVALYNXOFFICE_ORGANIZATION_ID` wird beim ersten Start dauerhaft in der Tabelle
  `license_state` initialisiert (oder auf den ENV-Wert gesetzt) und wechselt
  danach bei Neustarts nicht.
- Zusätzlich können Schlüssel über die Administrationsoberfläche
  (Rolle `ROLE_ADMIN`) in der Datenbank (`license_key`) gepflegt werden.
  ENV-/Datei-Schlüssel sind dort sichtbar, aber nicht löschbar.

## Lizenzquellen

`App\Licensing\Provider\LicenseKeyProviderInterface` mit:

- `EnvLicenseKeyProvider` (ENV)
- `FileLicenseKeyProvider` (Datei)
- `DatabaseLicenseKeyProvider` (Administrationsoberfläche)
- `CompositeLicenseKeyProvider` (Vereinigung + Deduplizierung)

## Zustände und Kulanzfrist

`App\Licensing\Service\LicenseStatusResolver`:

- `COMMUNITY` – höchstens 3 Konten, keine kommerzielle Lizenz nötig.
- `VALID` – Nutzung innerhalb der lizenzierten Plätze.
- `GRACE_PERIOD` – Kontingent überschritten; Frist 30 Tage ab erster
  Feststellung der Überschreitung. Wiederholtes kurzzeitiges Hin- und Her
  verschiebt den Fristbeginn nicht: Solange eine Überschreitung besteht,
  bleiben Start und Ende unverändert. Erst nach vollständiger Wiederherstellung
  der Konformität wird der Verstoß zurückgesetzt; eine erneute Überschreitung
  startet eine neue Frist.
- `READ_ONLY` – Frist abgelaufen, Nutzung weiterhin über dem Kontingent. Der
  Zustand endet erst, wenn die Nutzung wieder lizenzkonform ist (Benutzer
  deaktivieren oder Lizenz erweitern) — nicht allein durch Zeitablauf.

Persistenz: Tabelle `license_state` (genau eine Zeile, `id = 1`) mit
`violation_started_at`, `grace_period_ends_at`, `last_known_status`,
`last_checked_at`. Ein Container- oder Anwendungsneustart setzt die Frist
nicht zurück.

**Zeitrückstellungen:** Wird die Systemzeit hinter den aufgezeichneten
Fristbeginn zurückgestellt, wird der aufgezeichnete Beginn für Vergleiche
verwendet — die Frist wird dadurch nicht verlängert und nicht neu gestartet.
Eine manipulierte Serverzeit kann bei Offline-Prüfung nicht vollständig
verhindert werden; das Verhalten ist aber deterministisch und im
`license_state`-Datensatz nachvollziehbar (Diagnose über die
Administrationsoberfläche).

**Race Conditions:** Benutzeraktivierung und Sitzzählung laufen in einer
Transaktion mit pessimistischem Zeilenschloss (`SELECT … FOR UPDATE` auf
`license_state`). Parallele Aktivierungen serialisieren sich damit; siehe
`LicenseEnforcementService::assertUserSeatAvailable()` und
`tests/Licensing/Service/LicenseEnforcementServiceTest.php`.

## Read-Only-Durchsetzung

Zentrale Prüfstelle: `App\Licensing\Service\LicenseEnforcementService` mit dem
Enum `LicenseOperation` (`MAIL_SEND`, `MAIL_MODIFY`, `MAIL_DELETE`,
`CALENDAR_WRITE`, `CONTACT_WRITE`, `USER_CREATE`, `SETTINGS_WRITE`).

Bei blockierter Operation wird `LicenseReadOnlyException` geworfen; der
`LicenseExceptionSubscriber` liefert bei HTTP **403** mit dem Fehlercode
`LICENSE_READ_ONLY` (JSON bei XHR/Accept: application/json, sonst HTML).

**Erlaubt (auch im Read-Only-Modus):**

- Abruf externer Mailkonten (POP3), MIME-Verarbeitung, lokale Speicherung von
  Nachrichten und Anhängen, Duplikaterkennung, Ordner-Synchronisation —
  der Mail-Import ist von der Lizenzprüfung **bewusst nicht** betroffen.
- Lesen, Durchsuchen, Anzeigen von E-Mails, Kalendern und Kontakten.
- Anhang-Download, Druckansicht, Suche, Export vorhandener Daten.
- Login/Logout, Passwortänderungen, Lizenzschlüsselverwaltung,
  Deaktivierung von Benutzerkonten, technische Systemoperationen.

**Gesperrt (Read-Only):** Mailversand (Web-UI und Queue-Worker
`app:mail:send`), Antworten/Weiterleiten, Entwürfe erstellen oder
ändern, E-Mails löschen/verschieben, Gelesen-/Favoriten-Flags ändern,
Mailordner ändern, Mailbox-/Connector-Verwaltung,
Termin-CRUD, Kontakt-CRUD, Newsletter-Einstellung.

**Geräte-Sync (avalynxofficeeas):** Exchange ActiveSync ist als separates
AGPL-3.0-Projekt ausgelagert (`avalynxofficeeas`, eigener Container/Prozess).
Es spiegelt den Read-Only-Status: vor jeder geräteinitiierten Schreiboperation
liest das Plugin `license_state.last_known_status` und lehnt Mutationen im
Read-Only-Modus ab. Der eigentliche Mailversand läuft auch dort nie direkt —
das Plugin legt die Nachricht nur in die Core-Queue; der Core-Worker prüft die
Lizenz erneut unmittelbar vor der SMTP-Übergabe (zweite Verteidigungslinie).

Der Gelesen-Status wird beim Öffnen einer E-Mail **nicht** automatisch
gesetzt (auch außerhalb des Read-Only-Modus nicht) — Markierungen erfolgen nur
explizit über die Aktionsschaltflächen.

Es gibt **keine** pauschale Doctrine-Schreibsperre: interne Systemprozesse
(Import, Ordner-Sync, Lizenzstatus-Persistenz) schreiben weiter.

CalDAV/CardDAV existiert in dieser Anwendung nicht; das Sync-Protokoll ist
Exchange ActiveSync und wird im Core über die Web-/SMTP-Pfade und im Plugin
(avalynxofficeeas) über den Read-Only-Spiegel abgedeckt.

## SMTP-Versand und Lizenz-Halt

- `app:mail:send` (einziger SMTP-Versandpfad) prüft den Status unmittelbar
  vor der SMTP-Übergabe.
- Im Read-Only-Modus werden wartende Nachrichten mit dem expliziten Flag
  `email.license_hold = true` angehalten (nicht gelöscht, nicht gesendet).
- Nach Lizenzwiederherstellung werden diese Nachrichten **nicht** automatisch
  versendet; die kontrollierte Freigabe erfolgt per
  `php bin/console app:license:release-mail` (nur außerhalb des Read-Only-Modus
  möglich).
- System- und Sicherheitsnachrichten (z. B. Passwort-Reset) laufen über den
  Symfony-`Mailer` mit der konfigurierten `MAILER_DSN` und sind vom
  benutzerinitiierten Mailversand (Mailbox-SMTP-Connectoren) technisch getrennt.
  Passwort-Reset existiert in der aktuellen Anwendung noch nicht; bleibt er
  später hinzukommen, darf er `LicenseEnforcementService` nicht aufrufen.

## Mail-Import-Sicherheit (bestehende Architektur)

Der POP3-Import (`Pop3Fetcher`) persistiert Nachrichten vollständig lokal
(E-Mail, Adressen, Body, Anhänge, Ordnerzuordnung, `flush()`) **bevor** die
Nachricht vom Quellserver entfernt wird. Idempotenz über `import_key`
(Connector-ID + POP3-UIDL, DB-unique) und `content_hash` (SHA-256, DB-unique)
sowie Abfangen von `UniqueConstraintViolationException` — parallele Abrufe
erzeugen keine Duplikate, und bei Fehlern bleibt die Nachricht am Quellserver
erhalten. Die Lizenzierung greift an dieser Stelle nicht ein.

## Administrationsoberfläche

- Route `/admin/license` (`ROLE_ADMIN`): Status, Seats, erkannte Lizenzen mit
  Diagnose, Kulanzfrist, Schlüsselverwaltung (Datenbank), Benutzerkonten mit
  Aktivieren/Deaktivieren.
- Route `/license` (angemeldet): Status- und Lizenzinformationen für Benutzer.
- Vollständige Lizenzschlüssel werden niemals angezeigt; Diagnose nutzt nur
  Vorschauen (`WOL1.erste24Zeichen…`).
- Alle Admin-Aktionen sind CSRF-geschützt.

## Ausstellungswerkzeug (nur Herausgeber)

`bin/license-tool` — eigenständiges CLI ohne Symfony-Abhängigkeiten:

```bash
php bin/license-tool keygen --output-dir keys --key-id issuer-2026-01
php bin/license-tool issue --secret-key keys/issuer-2026-01.secret \
    --key-id issuer-2026-01 --license-id LIC-2026-000001 \
    --customer-id CUSTOMER-001 --organization-id org-example \
    --additional-seats 7
php bin/license-tool decode  --license WOL1.…
php bin/license-tool verify  --license WOL1.… --public-key keys/issuer-2026-01.public
```

Private Signaturschlüssel dürfen niemals in die Anwendung, in öffentliche
Docker-Images oder in das Git-Repository gelangen. Die Anwendung enthält nur
öffentliche Verifikationsschlüssel.

## Device-Sync (avalynxofficeeas, separates Projekt)

Exchange ActiveSync läuft im separaten AGPL-3.0-Projekt
`avalynxofficeeas` (eigener Container/Prozess, gemeinsame Datenbank):

- eigene DB-Models auf das Core-Schema (Schema = Interface, keine Core-Klassen);
- `license_state` wird nur lesend gespiegelt;
- geräteinitiierte Schreiboperationen werden im Read-Only-Modus abgelehnt;
- SendMail erzeugt nur Queue-Einträge (bzw. `license_hold`-Nachrichten);
  der Versand inklusive Lizenzprüfung bleibt im Core (`app:mail:send`).

Deployment über den Compose-Stack im Projekt `avalynxofficedocker`
(nginx routet `/Microsoft-Server-ActiveSync` auf das Plugin).

## ENV-Variablen zusammengefasst

| Variable | Bedeutung |
|---|---|
| `AVALYNXOFFICE_LICENSE_KEYS` | Lizenzschlüssel (Komma/Zeilenumbuch-getrennt) |
| `AVALYNXOFFICE_LICENSE_FILE` | Dateipfad mit weiteren Schlüsseln |
| `AVALYNXOFFICE_LICENSE_PUBLIC_KEYS` | JSON `key_id => öffentlicher Schlüssel` |
| `AVALYNXOFFICE_ORGANIZATION_ID` | Organisationsbindung (stabil initialisiert) |

## Migration

`Version20261013080000`: Tabellen `license_state`, `license_key`,
Spalte `app_user.active`, Spalte `email.license_hold`.

## Rechtlicher Hinweis

`LEGAL_REVIEW_REQUIRED`: Alle Lizenztexte (`LICENSE`, `LICENSING.md`,
`COMMERCIAL-LICENSE.md`, `NOTICE.md`) sind Entwürfe und vor Veröffentlichung
juristisch zu prüfen. Die technische Durchsetzung muss mit den final
vereinbarten Vertragsbedingungen übereinstimmen (siehe
`COMMERCIAL-LICENSE.md`, Abschnitt „Term, expiry and the READ-ONLY rule“).
