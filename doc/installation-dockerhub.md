# AvalynxOffice mit Docker installieren — eine Datei genügt

Diese Anleitung zeigt Schritt für Schritt, wie du AvalynxOffice mit **einer
einzigen Datei** und dem fertigen Docker-Image aus Docker Hub betreibst —
ohne Git, ohne Quellcode, ohne Bauen.

**Zeitbedarf:** ca. 10 Minuten

---

## Was passiert hier überhaupt?

Du legst eine Datei mit dem Namen `docker-compose.yml` an. Darin stehen zwei
Container:

1. **`app`** — das fertige AvalynxOffice-Image aus Docker Hub. Beim Start
   richtet es sich **selbst** ein: Es wartet auf die Datenbank, legt alle
   Tabellen an und erstellt deinen Administrator-Benutzer.
2. **`db`** — die PostgreSQL-Datenbank.

Daten (E-Mails, Kontakte, Anhänge) liegen in Docker-„Volumes" und überleben
Neustarts. Mehr brauchst du nicht — alles Weitere passiert von selbst.

---

## Voraussetzungen

- [Docker](https://docs.docker.com/get-docker/) ist installiert und läuft
  (`docker compose version` zeigt eine Version)
- Mindestens 4 GB freien Arbeitsspeicher für Docker
- Einen Editor (z. B. Notepad/Texteditor) für eine Textdatei

---

## Schritt 1: Ordner anlegen

Lege dir einen beliebigen leeren Ordner an, z. B. `avalynxoffice` auf dem
Desktop, und öffne ihn. In diesem Ordner liegt gleich die eine Datei.

---

## Schritt 2: Die Datei `docker-compose.yml` anlegen

Erstelle in diesem Ordner eine Textdatei mit dem Namen `docker-compose.yml`
(Endung `.yml`, nicht `.txt`!). Füge folgenden Inhalt ein:

```yaml
services:
    db:
        image: postgres:18-alpine
        environment:
            POSTGRES_DB: avalynxoffice
            POSTGRES_USER: avalynxoffice
            POSTGRES_PASSWORD: HIER_DB_PASSWORT
        volumes:
            - db-data:/var/lib/postgresql

    app:
        image: jbsnewmedia/avalynxoffice:latest
        depends_on:
            - db
        ports:
            - "80:80"
        environment:
            DATABASE_URL: postgresql://avalynxoffice:HIER_DB_PASSWORT@db:5432/avalynxoffice?serverVersion=18&charset=utf8
            APP_SECRET: HIER_APP_SECRET
            AVALYNXOFFICE_ENCRYPTION_KEY: HIER_ENCRYPTION_KEY
            OFFICE_ADMIN_EMAIL: admin@example.com
            OFFICE_ADMIN_PASSWORD: HIER_ADMIN_PASSWORT
        volumes:
            - app-var:/app/var
            - app-data:/app/data
            - app-css:/app/assets/css

volumes:
    db-data:
    app-var:
    app-data:
    app-css:
```

---

## Schritt 3: Die fünf `HIER_...`-Platzhalter ersetzen

Öffne ein Terminal und führe die drei Befehle einzeln aus. Notiere dir die
Ausgaben:

```bash
openssl rand -base64 18
openssl rand -hex 16
openssl rand -base64 32
```

| Platzhalter | Trage dort ein | Anzahl |
|---|---|---|
| `HIER_DB_PASSWORT` | Ausgabe des **ersten** Befehls | **zweimal** (identisch!) |
| `HIER_APP_SECRET` | Ausgabe des **zweiten** Befehls | einmal |
| `HIER_ENCRYPTION_KEY` | Ausgabe des **dritten** Befehls | einmal |
| `HIER_ADMIN_PASSWORT` | Dein gewünschtes Administrator-Passwort | einmal |
| `admin@example.com` | Deine E-Mail-Adresse für den Login | einmal |

> **Wichtig:** Bei jedem Platzhalter muss `HIER_...` **komplett ersetzt**
> werden — auch der Text `HIER_` selbst verschwindet.

---

## Schritt 4: Starten

Öffne im Ordner der Datei ein Terminal und führe aus:

```bash
docker compose up -d
```

**Was passiert?** Docker lädt beide Images aus dem Internet und startet sie.
Der erste Start dauert 1–2 Minuten — die Anwendung wartet selbst, bis die
Datenbank bereit ist, richtet alle Tabellen ein und legt den Administrator
an. Mit `docker compose logs -f app` siehst du, wann alles bereit ist
(Strg + C beendet die Anzeige).

---

## Schritt 5: Im Browser öffnen

```
http://localhost
```

Melde dich mit der E-Mail-Adresse und dem Passwort aus Schritt 3 an.
Fertig! 🎉

---

## Tägliches Arbeiten

| Befehl | Was er macht |
|---|---|
| `docker compose stop` | Container stoppen (Daten bleiben erhalten) |
| `docker compose start` | Container wieder starten |
| `docker compose down` | Container entfernen (Daten bleiben in den Volumes) |
| `docker compose logs -f app` | Live-Protokolle der Anwendung |
| `docker compose pull && docker compose up -d` | Auf ein neues Image aktualisieren |

---

## Häufige Fehler

**„address already in use"** — Port 80 ist belegt. Ändere in der Datei die
Zeile `ports:` auf z. B. `"8080:80"` und führe `docker compose up -d` erneut
aus.

**Anwendung zeigt „Service Unavailable"** — die Anwendung richtet sich noch
ein. Warte eine Minute und lade die Seite neu; Details siehe
`docker compose logs -f app`.

**Passwort vergessen?** Trage in der Datei eine neue
`OFFICE_ADMIN_PASSWORD` ein und führe `docker compose up -d` aus — der
Eintrag wird beim Neustart aktualisiert.

**„invalid interpolation" oder ähnliches beim Start** — eine der fünf
`HIER_...`-Platzhalter ist noch nicht ersetzt (oder das `$` einer
Passwort-Sonderzeichen-Störung): Platzhalter prüfen und ggf. ein Passwort
ohne Sonderzeichen wählen.

---

## AktiveSync für Handys

Der obige Aufbau enthält nur den Web-Client. Geräte-Synchronisation
(iPhone/Android/Outlook) bietet das separate Projekt
[avalynxofficeeas](https://github.com/jbsnewmedia/avalynxofficeeas) —
fertig kombiniert mit Web-Client, ActiveSync und nginx in der
All-in-one-Variante unter
[github.com/jbsnewmedia/avalynxofficedocker](https://github.com/jbsnewmedia/avalynxofficedocker).
