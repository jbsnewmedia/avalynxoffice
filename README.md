# AvalynxOffice

[Deutsch](README.de.md)

A self-hosted mail office built on Symfony 8: fetch (POP3), manage and send (SMTP)
email — with a web client, calendar, contacts, an admin area, a licensing system
and a plugin architecture.

## License

AvalynxOffice is licensed under the **AvalynxOffice Public License (APL)
version 1.0** (`LICENSE`, `LICENSING.md`):

- Usage with **up to 3 Active Seats is free** (community). An Active Seat is
  an activated user account with at least one successful authentication
  (login or device sync) within the last 30 calendar days.
- From the 4th Active Seat a **commercial license is required**
  (`COMMERCIAL-LICENSE.md`).
- License keys are configured in the admin area (*License & Usage*) or via ENV
  (`AVALYNXOFFICE_LICENSE_KEYS`, `AVALYNXOFFICE_LICENSE_FILE`); technical
  documentation lives in `docs/licensing.md`.
- Exchange ActiveSync (iOS/Android/Outlook sync) is a **separate, optionally
  installable project** ([avalynxofficeeas](https://github.com/jbsnewmedia/avalynxofficeeas),
  AGPL-3.0) and not part of this repository.

## Features

- **Web client**: mail reader with mailboxes/folders, reading, composing and
  attachments; dark mode and multi-language UI.
- **Mail engine**: POP3 fetch worker for configured mailboxes, SMTP sending via
  per-mailbox connectors, duplicate detection, header sanitization for broken
  messages.
- **Calendar**: events with an extensible action-plugin system (edit, delete, ...).
- **Contacts**: shared address pool used by mail and device synchronization.
- **Admin area**: mailbox and connector management, license & usage, settings,
  database setup.
- **Plugin system**: Symfony bundles plus a `plugins.json` manifest
  (mail-action-bundle, mail-view-bundle, bootstrap-bundle).
- **Licensing**: seat accounting with read-only enforcement — in restricted mode
  outgoing mail is held (`license_hold`) and released via `app:license:release-mail`.
- **Production image**: waits for the database, runs the bundled migrations and
  creates the env-defined administrator on start-up (see below).
- **Quality gates**: PHPStan (level max), ECS, Rector, PHPUnit suite (649 tests)
  enforced by a GitHub Actions workflow with a PostgreSQL service.

## Requirements

- Docker & Docker Compose
- PHP 8.5+ and Composer (only if running locally)

## Quick start (development)

```bash
docker compose up -d
docker compose exec web composer install
docker compose exec web php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec web php bin/console app:user:create --email=admin@example.com --password=admin
```

The web UI is then available at `http://localhost/`. For demo data (mailboxes,
emails, calendar, contacts — login `admin@example.com` / `admin`) load the
fixtures instead of creating the user manually:

```bash
docker compose exec web php bin/console doctrine:fixtures:load --no-interaction
```

## Production image

The production image (`docker/prod/Dockerfile`, built by the `docker-image`
workflow as [`jbsnewmedia/avalynxoffice`](https://hub.docker.com/r/jbsnewmedia/avalynxoffice))
ships its own start-up sequence:

1. Wait until the database configured via `DATABASE_URL` is reachable
2. Run the migrations bundled with this image version (idempotent — Doctrine
   only applies pending versions)
3. Create the administrator when `OFFICE_ADMIN_EMAIL` and
   `OFFICE_ADMIN_PASSWORD` are set (idempotent — an existing user is skipped)
4. Start the web server

| Variable | Meaning |
|---|---|
| `DATABASE_URL` | PostgreSQL connection (required) |
| `APP_SECRET` | Symfony app secret, required (e.g. `openssl rand -hex 16`) |
| `OFFICE_ADMIN_EMAIL` | Email of the administrator created on start-up (optional) |
| `OFFICE_ADMIN_PASSWORD` | Administrator password (optional, only together with the email) |

The dev variant `jbsnewmedia/avalynxoffice-dev` additionally ships the dev
dependencies and the composer-bin tooling, so the full quality gate set runs
against a mounted working copy.

## Command reference

### Mailboxes

```bash
# Add a mailbox for POP3 fetch and SMTP sending (SMTP falls back to the POP3
# credentials when no SMTP options are given)
bin/console app:mailbox:add <EMAIL> <PASSWORD> --host=<HOST> --user=<USER> --name="<NAME>" --port=995 --security=ssl

# Adjust the SMTP settings of an existing mailbox
bin/console app:mailbox:configure-smtp <ID-or-email> --host=<SMTP-HOST> --port=465 --user=<USER> --password=**** --security=ssl
```

### Fetch and send mail

```bash
# Fetch all mailboxes (or a single one, optionally with a limit)
bin/console app:mail:fetch
bin/console app:mail:fetch --mailbox=test@example.com --limit=50

# Send every mail marked as outgoing; delete on the server after import
bin/console app:mail:send
bin/console app:mail:fetch --delete-after

# Release messages held by the license restricted mode
bin/console app:license:release-mail
```

### Maintenance

```bash
# Interactive initialization (migrations + first user)
bin/console app:init

# Create an administrator non-interactively (used by the container bootstrap)
bin/console app:user:create --email=admin@example.com --password=...

# Delete all mail-related data (careful!)
bin/console app:mail:clear

# Assets
bin/console assets:scss-compile
bin/console app:list-css-classes
bin/console app:purge-css
```

## Development tools (Docker)

```bash
docker compose exec web composer run bin-ecs-fix   # code style fixer
docker compose exec web composer run bin-phpstan   # static analysis (level max)
docker compose exec web composer run test          # tests (re-loads fixtures afterwards)
docker compose exec web composer run ci-fix        # all CI checks with fixes
```

The test commands automatically re-load the Doctrine fixtures after every run,
so the shared local database is filled with demo data again.

## Live test against a real mailbox

Use a dedicated test mailbox and **never start with `--delete-after`**.

1. Start the stack, migrate and load fixtures:
   ```bash
   docker compose up -d
   docker compose exec web php bin/console doctrine:migrations:migrate --no-interaction
   docker compose exec web php bin/console doctrine:fixtures:load --no-interaction
   ```
2. Log in at `http://localhost/` (`admin@example.com` / `admin`).
3. Add the real test mailbox:
   ```bash
   docker compose exec web php bin/console app:mailbox:add mailbox@example.com "MAILBOX-PASSWORD" --name="Live test" --host=pop.example.com --user=mailbox@example.com --port=995 --security=ssl
   ```
4. If SMTP uses different credentials, configure it separately:
   ```bash
   docker compose exec web php bin/console app:mailbox:configure-smtp mailbox@example.com --host=smtp.example.com --port=465 --user=mailbox@example.com --password="SMTP-PASSWORD" --security=ssl
   ```
5. First fetch, carefully limited:
   ```bash
   docker compose exec web php bin/console app:mail:fetch --mailbox=mailbox@example.com --limit=10
   ```
6. Reload the browser, select the new mailbox and check inbox, mail detail and
   attachments. Send a real test mail from the UI (**New email**) to an external
   address, then trigger delivery with `app:mail:send`.
7. Reply from the external address, fetch again and verify sender, recipients
   and mailbox assignment in the UI.
8. Only at the very end, optionally test server deletion with `--delete-after`.

Recommended order: **fetch** → **UI** → **send** → **reply** → `--delete-after` last.

## Project structure

- `src/Controller/`: web client, calendar, contacts, admin area
- `src/Command/`: console commands (mailboxes, mail engine, assets, licensing)
- `src/Service/Mail/`: POP3 fetch (`Pop3Fetcher`), folder management (`FolderManager`), SMTP factory, attachment storage
- `src/Licensing/`: license system (key validation, Active Seat accounting, grace period, read-only enforcement)
- `src/Plugin/` + `plugins/jbsnewmedia/`: plugin loading and first-party bundles
- `docs/`: technical documentation (e.g. `docs/licensing.md`)
- `docker/`: `docker/web` = dev stack, `docker/prod` = production image, `docker/postgres` = database

## Attachment storage

Configured via `.env`:

```dotenv
ATTACHMENT_STORAGE_DIR=data/attachments
ATTACHMENT_STORAGE_SEGMENT_LENGTH=3
ATTACHMENT_STORAGE_SEGMENT_DEPTH=3
ATTACHMENT_STORAGE_MAX_BYTES=65536
```

This produces a structure like `data/attachments/abc/def/ghi/<uuid>.pdf`.
Attachments larger than the configured limit are skipped during import.
