# AvalynxOffice mit Docker aus dem Quellcode installieren

Diese Anleitung zeigt Schritt für Schritt, wie du AvalynxOffice auf deinem
Rechner mit Docker startest — z. B. zum Ausprobieren oder für die Entwicklung.
Der Quellcode wird per **Git** geklont und die Container werden lokal gebaut.

> **Zwei weitere Wege:** ein fertiges Image aus Docker Hub (siehe
> [`installation-dockerhub.md`](installation-dockerhub.md)) oder klassisches
> Webhosting (siehe [`installation-webhosting.md`](installation-webhosting.md)).

**Zeitbedarf:** ca. 15–30 Minuten (je nach Internetgeschwindigkeit)

---

## Was passiert hier überhaupt?

AvalynxOffice besteht aus zwei Teilen, die Docker für dich startet:

1. **Web-Container** — enthält die AvalynxOffice-Anwendung (PHP).
2. **PostgreSQL-Container** — die Datenbank, in der alle E-Mails, Kontakte
   und Termine gespeichert werden.

Das Skript `core.sh` steuert beide Container. Du musst dir die Befehle nicht
merken — du tippst sie einfach ab.

---

## Voraussetzungen

- [Git](https://git-scm.com/) ist installiert (`git --version` zeigt eine Version)
- [Docker](https://docs.docker.com/get-docker/) ist installiert und läuft
  (`docker --version` zeigt eine Version)
- Mindestens 4 GB freien Arbeitsspeicher für Docker
- Ein Terminal (Linux/macOS: Terminal-App; Windows: PowerShell oder WSL)

---

## Schritt 1: AvalynxOffice herunterladen

```bash
git clone https://github.com/jbsnewmedia/avalynxoffice.git
cd avalynxoffice
```

**Was passiert?** Der komplette Quellcode wird von GitHub auf deinen Rechner
kopiert, in einen neuen Ordner `avalynxoffice`. Mit `cd` wechselst du in
diesen Ordner — alle weiteren Befehle laufen dort.

---

## Schritt 2: Container bauen und starten

```bash
./core.sh up
```

**Was passiert?** Docker lädt und baut die Container-Images und startet
Web-Server und Datenbank. **Der erste Start dauert mehrere Minuten** — das
ist normal. Danach siehst du die Container-Statusliste.

> **Hinweis:** Wenn der Befehl „Permission denied“ meldet, mache die Datei
> zuerst ausführbar: `chmod +x core.sh` — dann erneut versuchen.

---

## Schritt 3: PHP-Abhängigkeiten installieren

```bash
./core.sh composer install
```

**Was passiert?** AvalynxOffice benutzt fertige Programmbibliotheken
(„Packages“). Composer lädt alle davon in den Ordner `vendor/`. Auch das
dauert beim ersten Mal etwas.

---

## Schritt 4: Datenbank einrichten und Administrator anlegen

```bash
./core.sh console app:init
```

**Was passiert?** Dieser eine Befehl erledigt den kompletten ersten Start:

1. Er legt alle Datenbank-Tabellen an („Migrationen“).
2. Er fragt dich, ob du **Testdaten** erzeugen willst — für den Anfang
   antwortest du mit `n` (nein).
3. Er legt deinen **Administrator-Benutzer** an. Folge den Eingabeaufforderungen:
   E-Mail-Adresse und Passwort eingeben. **Notiere dir beides gut** — damit
   meldest du dich gleich an.

---

## Schritt 5: Im Browser öffnen

Öffne im Browser:

```
http://localhost
```

Du siehst die Login-Seite von AvalynxOffice. Melde dich mit der
E-Mail-Adresse und dem Passwort aus Schritt 4 an. Fertig! 🎉

---

## Tägliches Arbeiten — die wichtigsten Befehle

| Befehl | Was er macht |
|---|---|
| `./core.sh down` | Container stoppen (Daten bleiben erhalten) |
| `./core.sh up` | Container wieder starten |
| `./core.sh status` | Zeigen, ob alles läuft |
| `./core.sh logs` | Live-Protokolle ansehen (bei Fehlern hilfreich) |
| `./core.sh shell` | Bash im Web-Container öffnen |
| Strg + C | Laufende Log-Ausgabe beenden |

---

## Häufige Fehler

**„address already in use“ beim Start** — Port 80 ist auf deinem Rechner
schon belegt. Trage in der Datei `.env` z. B. `HTTP_PORT=8080` ein, starte
danach mit `./core.sh down && ./core.sh up` neu und öffne
`http://localhost:8080`.

**„could not find driver“ oder Datenbankfehler** — die Datenbank war noch
nicht bereit. Warte 10 Sekunden und führe den Befehl erneut aus.

**Passwort vergessen?** Neuen Administrator anlegen mit:

```bash
./core.sh console app:user:create --admin
```

---

## Nächste Schritte

- Fertiger All-in-one-Stack (Office + ActiveSync + nginx, immer aktuelles
  Image): siehe [`installation-dockerhub.md`](installation-dockerhub.md).
- Echte Postfächer anbinden: im Web-Client unter *Einstellungen* einen
  Connector anlegen (POP3-Host, Benutzer, Passwort — dieselben Daten wie in
  deinem E-Mail-Programm).
- Produktiv im Internet betreiben: siehe
  [`doc/installation-webhosting.md`](installation-webhosting.md).