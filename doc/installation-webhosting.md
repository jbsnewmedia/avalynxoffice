# AvalynxOffice auf einem Webhosting installieren (z. B. All-Inkl)

Diese Anleitung zeigt Schritt für Schritt, wie du AvalynxOffice bei einem
klassischen Webhoster (hier am Beispiel **All-Inkl**) betreibst — mit
MariaDB, eigener Domain und HTTPS.

**Zeitbedarf:** ca. 30–45 Minuten

---

## Was passiert hier überhaupt?

AvalynxOffice ist eine PHP-Anwendung. Sie braucht auf dem Webspace drei
Dinge:

1. Die **Programmdateien** (die ZIP aus einem GitHub-Release).
2. Eine **Datenbank** (MariaDB — legst du im KAS an).
3. Eine **Konfigurationsdatei** `.env.local` mit deinen Zugangsdaten.

Die Domäne zeigt später auf den Ordner `public/` — dort liegt die einzige
Datei, die im Internet erreichbar ist. Alles andere bleibt geschützt.

---

## Voraussetzungen

- All-Inkl-Webspace (z. B. Tarif **Private Plus**) mit:
  - **SSH-Zugang** (im KAS aktiviert)
  - **PHP 8.5** für deine Domain einstellbar
  - **MariaDB-Datenbank** anlegbar
- Deine Domain (z. B. `example.de`) ist im KAS angelegt
- Ein SFTP-Programm (z. B. [FileZilla](https://filezilla-project.org/))
- Die aktuelle Release-Datei **`avalynxoffice-<version>.zip`** von der
  [GitHub-Releases-Seite](https://github.com/jbsnewmedia/avalynxoffice/releases)

> **Tipp:** Alle Befehle in dieser Anleitung wurden mit PHP **8.5** getestet.
> Stelle im KAS unter *Domain → PHP* für deine Domain mindestens PHP 8.5 ein.

---

## Schritt 1: Datenbank im KAS anlegen

1. KAS öffnen → **Datenbanken** → **MySQL/MariaDB-Datenbank anlegen**.
2. Notiere dir die drei Angaben, die das KAS dir zeigt:
   - **Datenbankname** (z. B. `d048db03`)
   - **Benutzername** (meist gleich dem Datenbanknamen)
   - **Passwort**

Diese drei Werte trägst du in Schritt 5 ein.

---

## Schritt 2: Domain vorbereiten

1. KAS → **Domains** → deine Domain bearbeiten:
   - **PHP-Version: 8.5** einstellen.
   - **Verzeichnis** (Document-Root) auf den späteren Unterordner
     `avalynxoffice/public` zeigen lassen
     (z. B. `/www/htdocs/w0XXXXXX/avalynxoffice/public`).
2. **SSL/Let's Encrypt** für die Domain aktivieren — dann ist die Seite
   automatisch per `https://` erreichbar.

> Der Ordner `avalynxoffice/public` existiert erst nach Schritt 3 — das KAS
> akzeptiert die Angabe trotzdem, oder du legst den Ordner vorher per FTP an.

---

## Schritt 3: Programmdateien auf den Webspace bringen

**Empfohlener Weg (per SSH, schnell):**

1. Per SSH verbinden (Zugangsdaten aus dem KAS):

   ```bash
   ssh benutzername@servername.kasserver.com
   ```

2. In den Webspace-Ordner wechseln und die hochgeladene ZIP-Datei entpacken.
   Lade vorher `avalynxoffice-<version>.zip` per SFTP in diesen Ordner hoch:

   ```bash
   cd /www/htdocs/w0XXXXXX
   unzip avalynxoffice-<version>.zip
   mv avalynxoffice-<version> avalynxoffice
   ```

**Was passiert?** Es entsteht der Ordner `avalynxoffice` mit allen
Programmdateien. Die Domain zeigt (siehe Schritt 2) auf `avalynxoffice/public`
— genau richtig.

> **Alternative ohne SSH:** ZIP lokal entpacken und den Ordnerinhalt per
> FileZilla hochladen. Das dauert bei ~40.000 kleinen Dateien deutlich
> länger.

---

## Schritt 4: Konfigurationsdatei `.env.local` anlegen

Die Anwendung liest geheime Einstellungen aus der Datei `.env.local` im
Ordner `avalynxoffice`. Lege sie per SSH an:

```bash
cd /www/htdocs/w0XXXXXX/avalynxoffice
umask 077
cat > .env.local << 'ENDE'
APP_ENV=prod
APP_SECRET=HIER_EIN_ZUFALLSWERT
DATABASE_URL="mysql://DATENBANKBENUTZER:PASSWORT@localhost:3306/DATENBANKNAME?serverVersion=10.6.23-MariaDB&charset=utf8mb4"
MAILER_DSN=null://null
AVALYNXOFFICE_ENCRYPTION_KEY=HIER_EIN_ZWEITER_ZUFALLSWERT
ENDE
chmod 600 .env.local
```

**Die vier HIER-Platzhalter ersetzt du vorher** (lokal in einer Textdatei
vorbereiten und dann einfügen ist am einfachsten):

| Platzhalter | So erzeugst du den Wert |
|---|---|
| `APP_SECRET` | Zufallsbuchstaben, z. B. Ausgabe von `openssl rand -base64 24` |
| `DATABASE_URL`-Teile | Benutzer/Passwort/Datenbankname aus Schritt 1 |
| `AVALYNXOFFICE_ENCRYPTION_KEY` | Ausgabe von `openssl rand -base64 32` |

**Was bedeuten die Zeilen?**

- `APP_ENV=prod` — die Anwendung läuft im Produktionsmodus (keine Debug-Ausgaben).
- `DATABASE_URL` — Verbindung zur MariaDB aus Schritt 1. `localhost:3306` ist
  bei All-Inkl korrekt, `serverVersion=10.6.23-MariaDB` passt zu deren
  MariaDB-Version (mit `SELECT VERSION();` in phpMyAdmin nachprüfbar).
- `MAILER_DSN=null://null` — E-Mail-Versand vorerst deaktiviert; später
  SMTP-Daten des All-Inkl-Postfachs eintragen.
- `AVALYNXOFFICE_ENCRYPTION_KEY` — verschlüsselt gespeicherte
  Connector-Passwörter. **Diesen Schlüssel sicher aufbewahren** — ohne ihn
  sind gespeicherte Passwörter nicht mehr lesbar!

---

## Schritt 5: Einrichtung per Konsole ausführen

Noch per SSH im Ordner `avalynxoffice` (alle Befehle einzeln ausführen):

```bash
php85 -d memory_limit=512M bin/console cache:warmup
php85 -d memory_limit=512M bin/console assets:install public
php85 -d memory_limit=512M bin/console assets:scss-compile
php85 -d memory_limit=512M bin/console assets:css-purge
php85 -d memory_limit=512M bin/console app:plugin:manifest
php85 -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction
php85 -d memory_limit=512M bin/console app:user:create --admin
```

**Was passiert hier?**

1. `cache:warmup` — die Anwendung bereitet ihre internen Dateien vor.
2. `assets:install` … `app:plugin:manifest` — Stylesheets, Skripte und die
   Plugin-Liste werden gebaut.
3. `doctrine:migrations:migrate` — alle Datenbank-Tabellen werden angelegt.
4. `app:user:create --admin` — dein Administrator-Benutzer wird angelegt
   (E-Mail und Passwort abfragen — **notieren!**).

> `php85` ist die PHP-Version 8.5 auf dem All-Inkl-Server — immer genau so
> eintippen, nicht nur `php` (das wäre eine ältere Version).

---

## Schritt 6: Login testen

Öffne im Browser:

```
https://deine-domain.de
```

Du siehst die Login-Seite. Melde dich mit dem Administrator-Benutzer aus
Schritt 5 an. Fertig! 🎉

---

## Updates später

1. Neues Release-ZIP von GitHub herunterladen.
2. Alte Dateien im Webspace-Ordner löschen (die `.env.local` **dabei lassen**
   — sie geht nicht verloren, wenn du sie vorher sicherheitshalber
   herunterlädst).
3. Neues ZIP hochladen und entpacken (siehe Schritt 3).
4. Per SSH ausführen:

   ```bash
   php85 -d memory_limit=512M bin/console cache:clear
   php85 -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction
   ```

5. Login testen.

---

## Häufige Fehler

**Fehler 500 (weiße/rote Fehlerseite)** — meist ein Tippfehler in der
`.env.local`. Prüfe die Datei auf die vier Platzhalter und führe
`php85 -d memory_limit=512M bin/console cache:clear` aus.

**Login-Seite lädt, aber Anmelden schlägt fehl** — Datenbank-Zugangsdaten in
`DATABASE_URL` prüfen (Benutzer, Passwort, Datenbankname aus Schritt 1).

**Alte Seite wird weiterhin angezeigt** — Browser-Cache mit
Strg + F5 neu laden.

**„Class not found"-Fehler** — der Cache ist veraltet:
`php85 -d memory_limit=512M bin/console cache:clear` ausführen.

---

## Nächste Schritte

- E-Mail-Versand aktivieren: in `.env.local` die Zeile `MAILER_DSN` ersetzen
  durch deine SMTP-Zugangsdaten, z. B.
  `MAILER_DSN=smtp://benutzer:passwort@mail.deine-domain.de:587`.
- E-Mails per ActiveSync aufs Handy: siehe das separate Projekt
  [avalynxofficeeas](https://github.com/jbsnewmedia/avalynxofficeeas)
  (Subdomain `eas.deine-domain.de` auf dessen `public/`-Ordner zeigen lassen).
- Regelmäßige Aufgaben (Mail-Abruf, Synchronisierung) per KAS-Cronjob
  einrichten — mit `/usr/bin/php85 bin/console app:sync:run` im
  Projektordner.
- Andere Installationswege: mit Docker aus dem Quellcode
  ([`installation-docker.md`](installation-docker.md)) oder mit dem fertigen
  Docker-Image ([`installation-dockerhub.md`](installation-dockerhub.md)).
