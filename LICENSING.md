# Licensing

AvalynxOffice is a self-hosted groupware (e-mail, calendars, contacts) licensed
under the **AvalynxOffice Public License (APL), version 1.0** (see `LICENSE`).
The license is source-available and source-code based; the German version is
legally binding for the DACH region.

| Model | Seats | Price |
|---|---|---|
| Free Community | up to 3 Active Seats | free of charge |
| Commercial | from the 4th Active Seat (all seats) | see `COMMERCIAL-LICENSE.md` |

## Active Seats (APL 1.0, § 1)

An **Active Seat** is a user account that

1. is not deactivated, and
2. has performed at least one successful authentication (web login or device
   sync) within the last **30 calendar days**.

One account counts as one (1) seat, regardless of the number of mailboxes or
calendars linked to it. E-mail addresses, mailboxes, calendars and contacts do
not count. Accounts that never signed in (for example freshly provisioned
accounts) and accounts whose last authentication is more than 30 days old do
not count as seats until their next successful authentication.

## Architecture: core and ActiveSync extension

This repository contains the **core** (web UI, POP3 import, SMTP sending,
licensing system). Exchange ActiveSync device synchronization is **not part
of this repository**: it is provided by the separate project
**avalynxofficeeas** (`avalynxofficeeas`, AGPL-3.0, based on Z-Push), which
runs as its own process/container against the same database.

The AGPL-3.0 license of that extension covers only the extension project —
the aggregation rule of the AGPL applies because both programs are separate
works (no shared code, communication via the database schema only). The core
license is unaffected. Deployment is documented in the `avalynxofficedocker`
compose stack.

## Technical model

```
allowedSeats = 3 + sum(validLicenses.additionalSeats)
usedSeats    = count(active users with last_auth_at >= now - 30 days)
```

- Web logins (including remember-me) refresh `app_user.last_auth_at` via the
  `LastAuthenticationSubscriber`.
- Device syncs refresh the same column: every successful
  `AvalynxBackend::Logon()` in avalynxofficeeas records the authentication
  (throttled, at most once per hour).
- Expired keys provide no quota; a future-dated key is not yet active.
- Licenses of different organizations must not be combined. Keys with
  identical `license_id` count only once.

## License states

| State | Meaning |
|---|---|
| `COMMUNITY` | up to 3 Active Seats used, no commercial license needed |
| `VALID` | usage within the licensed seats |
| `GRACE_PERIOD` | quota exceeded; 30 days to remediate (fully functional) |
| `READ_ONLY` | grace period expired; user-initiated writes blocked, e-mail reception and reading continue |

The grace period persists in the database (`license_state`) and survives
restarts. See `docs/licensing.md` for the complete technical documentation.

## Links

- License text (APL 1.0, German and English): `LICENSE`
- Commercial licensing: `COMMERCIAL-LICENSE.md`
- Legal notices / third-party components: `NOTICE.md`
- Technical documentation: `docs/licensing.md`
- Issuance tool (issuer side only): `bin/license-tool`

---

The German version of the APL 1.0 in `LICENSE` is legally binding for the
DACH region; the English version serves international users (e.g. on GitHub).
