# Licensing (technical documentation)

This page documents the technical implementation of the license system.

## Model

```
allowedSeats = 3 + sum(validLicenses.additionalSeats)
usedSeats    = count(active users with last_auth_at >= now - 30 days)
```

- **Active Seats** are counted (APL 1.0, § 1): activated personal users
  (`app_user.active = true`) who have performed at least one successful
  authentication (login/sync) within the last 30 calendar days
  (`app_user.last_auth_at`). The window is rolling and inclusive at the
  30-day boundary (`>= cutoff`).
- Email addresses, mailboxes, calendars and contacts do not count; an account
  counts as exactly one seat, regardless of the number of linked mailboxes.
- Accounts that have never logged in (e.g. freshly created accounts) do not
  consume a seat yet; the seat comes into existence with the first successful
  authentication.
- The `app_user.last_auth_at` column is written by:
    - `AvalynxOffice\EventSubscriber\LastAuthenticationSubscriber` on every
      successful web login (including remember-me, event
      `security.interactive_login`),
    - `AvalynxOfficeEas\Service\LastAuthenticationRecorder` in the
      ActiveSync project after every successful device login (`Logon()`),
      i.e. every device sync keeps the seat active.
  Both write throttled (at most once per hour), so device syncs do not create
  write load per request. Writing this timestamp is not a license-relevant
  write operation and remains active in read-only mode.
- Technical service accounts without personal access are exempt; the existing
  data model does not yet contain a distinguishable service-account concept.
  If one is introduced later (e.g. an `isServiceAccount` flag), it must be
  added to `UserRepository::countActiveSeats()`.
- Licenses of different organizations are not combined; keys with an identical
  `license_id` are counted only once.

## Key format

```
WOL1.PAYLOAD.SIGNATURE
PAYLOAD   = Base64URL(JSON payload)
SIGNATURE = Base64URL(Ed25519 signature over "WOL1.PAYLOAD")
```

Payload schema (version 1, strictly validated):

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

`expires_at` is exclusive. The signature is verified against the **original
signed bytes** (`WOL1.PAYLOAD`) using `sodium_crypto_sign_verify_detached`,
without re-serializing the JSON.

## Key rotation

Multiple public keys are supported via `key_id`:

```dotenv
AVALYNXOFFICE_LICENSE_PUBLIC_KEYS='{"issuer-2026-01":"<base64-public-key>","issuer-2027-01":"<base64-public-key>"}'
```

Only known `key_id` values are accepted; the mapping comes exclusively from
this trusted configuration, never from license data.

## Configuration

```dotenv
AVALYNXOFFICE_LICENSE_KEYS=""          # comma- or newline-separated keys
AVALYNXOFFICE_LICENSE_FILE=""          # optional file with additional keys
AVALYNXOFFICE_LICENSE_PUBLIC_KEYS=""   # JSON: key_id => Ed25519 public key
AVALYNXOFFICE_ORGANIZATION_ID=""       # organization of the installation
```

- Missing ENV values are not an error; the installation runs in community mode.
- `AVALYNXOFFICE_ORGANIZATION_ID` is initialized permanently in the
  `license_state` table on first start (or set to the ENV value) and does not
  change afterwards across restarts.
- Additionally, keys can be managed via the administration area (role
  `ROLE_ADMIN`) in the database (`license_key`). ENV/file keys are visible
  there but cannot be deleted.

## License key sources

`AvalynxOffice\Licensing\Provider\LicenseKeyProviderInterface` with:

- `EnvLicenseKeyProvider` (ENV)
- `FileLicenseKeyProvider` (file)
- `DatabaseLicenseKeyProvider` (administration area)
- `CompositeLicenseKeyProvider` (union + deduplication)

## States and grace period

`AvalynxOffice\Licensing\Service\LicenseStatusResolver`:

- `COMMUNITY` – at most 3 active seats, no commercial license required.
- `VALID` – usage within the licensed seats.
- `GRACE_PERIOD` – quota exceeded; deadline is 30 days from the first
  detection of the overage. Repeated short back-and-forth does not shift the
  deadline start: as long as an overage persists, start and end remain
  unchanged. Only after compliance is fully restored is the violation reset;
  a renewed overage starts a new deadline.
- `READ_ONLY` – deadline expired, usage still above the quota. The state ends
  only when usage becomes license-compliant again (deactivate users or extend
  the license) — not by the passage of time alone.

Persistence: `license_state` table (exactly one row, `id = 1`) with
`violation_started_at`, `grace_period_ends_at`, `last_known_status`,
`last_checked_at`. A container or application restart does not reset the
deadline.

**Clock rollbacks:** if the system time is set back before the recorded
deadline start, the recorded start is used for comparisons — the deadline is
neither extended nor restarted. A manipulated server clock cannot be fully
prevented with offline verification; the behaviour is deterministic and
traceable in the `license_state` record (diagnosis via the administration
area).

**Race conditions:** user activation and seat counting run in one transaction
with a pessimistic row lock (`SELECT … FOR UPDATE` on `license_state`).
Concurrent activations are serialized; see
`LicenseEnforcementService::assertUserSeatAvailable()` and
`tests/Licensing/Service/LicenseEnforcementServiceTest.php`.

## Read-only enforcement

Central checkpoint: `AvalynxOffice\Licensing\Service\LicenseEnforcementService` with the
enum `LicenseOperation` (`MAIL_SEND`, `MAIL_MODIFY`, `MAIL_DELETE`,
`CALENDAR_WRITE`, `CONTACT_WRITE`, `USER_CREATE`, `SETTINGS_WRITE`).

When an operation is blocked, `LicenseReadOnlyException` is thrown; the
`LicenseExceptionSubscriber` responds with HTTP **403** and the error code
`LICENSE_READ_ONLY` (JSON for XHR/Accept: application/json, otherwise HTML).

**Allowed (also in read-only mode):**

- Login/logout and recording of seat activity (`app_user.last_auth_at`),
  password changes, license key management, deactivation of users, technical
  system operations.
- Fetching external mail accounts (POP3), MIME processing, local storage of
  messages and attachments, duplicate detection, folder synchronization —
  mail import is deliberately **not** affected by license enforcement.
- Reading, searching, viewing emails, calendars and contacts.
- Attachment download, print view, search, export of existing data.

**Blocked (read-only):** sending mail (web UI and queue worker
`app:mail:send`), reply/forward, creating or modifying drafts, deleting/moving
emails, changing read/favourite flags, changing mail folders,
mailbox/connector management, calendar CRUD, contact CRUD.

**Device sync (AvalynxOfficeEAS):** Exchange ActiveSync is a separate
AGPL-3.0 project (`avalynxofficeeas`, own container/process). It mirrors the
read-only state: before every device-initiated write operation the plugin
reads `license_state.last_known_status` and rejects mutations in read-only
mode. Actual mail delivery never happens there either — the plugin only puts
the message into the core queue; the core worker checks the license again
right before the SMTP handover (second line of defence). Additionally, the
plugin records seat activity after every successful device login
(`app_user.last_auth_at`, see "Model"); an account that only syncs via the
device therefore remains an active seat.

The read status is **not** set automatically when an email is opened (even
outside read-only mode) — marking happens only explicitly via the action
buttons.

There is **no** blanket Doctrine write lock: internal system processes
(import, folder sync, license state persistence) keep writing.

CalDAV/CardDAV does not exist in this application; the sync protocol is
Exchange ActiveSync, covered in the core via the web/SMTP paths and in the
plugin (avalynxofficeeas) via the read-only mirror.

## SMTP delivery and license hold

- `app:mail:send` (the only SMTP delivery path) checks the status immediately
  before the SMTP handover.
- In read-only mode, waiting messages are held with the explicit flag
  `email.license_hold = true` (not deleted, not sent).
- After the license is restored, these messages are **not** sent
  automatically; controlled release happens via
  `php bin/console app:license:release-mail` (only possible outside read-only
  mode).
- System and security messages (e.g. password reset) run via the Symfony
  `Mailer` with the configured `MAILER_DSN` and are technically separated from
  user-initiated mail delivery (mailbox SMTP connectors). Password reset does
  not yet exist in the current application; if it is added later, it must not
  call `LicenseEnforcementService`.

## Mail import safety (existing architecture)

The POP3 import (`Pop3Fetcher`) persists messages completely locally
(email, addresses, body, attachments, folder assignment, `flush()`) **before**
the message is removed from the source server. Idempotency via `import_key`
(connector ID + POP3 UIDL, DB-unique) and `content_hash` (SHA-256, DB-unique)
plus catching `UniqueConstraintViolationException` — parallel fetches do not
create duplicates, and on errors the message stays on the source server. The
license system does not intervene at this point.

## Administration area

- Route `/admin/license` (`ROLE_ADMIN`): status, active seats, detected
  licenses with diagnostics, grace period, key management (database), users
  with activate/deactivate and last authentication.
- Route `/license` (logged in): status and license information for users.
- Complete license keys are never displayed; diagnostics only use previews
  (`WOL1.first24Characters…`).
- All admin actions are CSRF-protected.

## Issuance tool (issuer only)

`bin/license-tool` — standalone CLI without Symfony dependencies:

```bash
php bin/license-tool keygen --output-dir keys --key-id issuer-2026-01
php bin/license-tool issue --secret-key keys/issuer-2026-01.secret \
    --key-id issuer-2026-01 --license-id LIC-2026-000001 \
    --customer-id CUSTOMER-001 --organization-id org-example \
    --additional-seats 7
php bin/license-tool decode  --license WOL1.…
php bin/license-tool verify  --license WOL1.… --public-key keys/issuer-2026-01.public
```

Private signing keys must never end up in the application, in public Docker
images or in the Git repository. The application contains only public
verification keys.

## Device sync (AvalynxOfficeEAS, separate project)

Exchange ActiveSync runs in the separate AGPL-3.0 project `avalynxofficeeas`
(own container/process, shared database):

- own DB models on the core schema (schema = interface, no core classes);
- `license_state` is only mirrored read-only;
- device-initiated write operations are rejected in read-only mode;
- SendMail only creates queue entries (or `license_hold` messages); delivery
  including license checking stays in the core (`app:mail:send`).

Deployment via the Compose stack in the `avalynxofficedocker` project
(nginx routes `/Microsoft-Server-ActiveSync` to the plugin).

## ENV variables summarized

| Variable | Meaning |
|---|---|
| `AVALYNXOFFICE_LICENSE_KEYS` | license keys (comma/newline separated) |
| `AVALYNXOFFICE_LICENSE_FILE` | file path with additional keys |
| `AVALYNXOFFICE_LICENSE_PUBLIC_KEYS` | JSON `key_id => public key` |
| `AVALYNXOFFICE_ORGANIZATION_ID` | organization binding (stably initialized) |

## Migrations

`Version20261013080000`: tables `license_state`, `license_key`, column
`app_user.active`, column `email.license_hold`.

`Version20261016080000`: column `app_user.last_auth_at` for the Active Seat
definition (APL 1.0). Existing accounts start without a timestamp and are
counted as active seats again with their next successful authentication.

## Legal notice

The basis is the AvalynxOffice Public License (APL) version 1.0 (`LICENSE`;
the German version is legally binding for the DACH region). The technical
enforcement matches the conditions agreed there (see `COMMERCIAL-LICENSE.md`,
section "Term, expiry and the READ-ONLY rule").
