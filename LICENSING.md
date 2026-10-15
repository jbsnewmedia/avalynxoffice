# Licensing

**Status: LEGAL_REVIEW_REQUIRED — all license texts in this repository are
drafts pending review by legal counsel.**

AvalynxOffice is a self-hosted groupware (e-mail, calendars, contacts) licensed as
**source-available** software:

| Model | Seats | Price |
|---|---|---|
| Community | 1–3 counted user accounts | free of charge |
| Commercial (Team/Enterprise) | 3 + additional seats per license | see `COMMERCIAL-LICENSE.md` |

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

## Why source-available?

The project has not yet been publicly released. The licensing model is
designed around three goals:

1. **Free productive use for small installations** — up to 3 counted user
   accounts, unlimited in time, with all functions (no feature tiers).
2. **Fair compensation for larger installations** — from 4 counted user
   accounts a commercial license is required. Licenses are additive: each
   valid license adds seats on top of the 3 free seats.
3. **A path to open source** — every published version is intended to change
   to an OSI-approved license after a fixed period (see "BSL 1.1 evaluation"
   below).

## Evaluation of the Business Source License 1.1 (BSL 1.1)

BSL 1.1 was evaluated as the basis for the source license. Key BSL 1.1
properties:

- The Licensor grants usage, copying, modification, creation of derivative
  works and production use **with restrictions specified as "Additional Use
  Grant"**.
- **Change Date**: no later than four years after first publication, the
  Licensed Work converts to the **Change License** (a broadly recognized
  open-source license or a "Later Applicable License").
- The Licensor may **not** use the Licensed Work under any non-open terms
  during the Change License window.
- The BSL text must be used **unchanged**; only Licensor, Licensed Work,
  Additional Use Grant, Change License and Change Date are specified.

### Conflicts with the project requirements

- **Additional Use Grant vs. technical enforcement.** The BSL 1.1 Additional
  Use Grant can express "production use with up to three user accounts is
  permitted; use with more requires a commercial license". This matches the
  model well.
- **Change License choice.** The Change License must be an open-source
  license. Whether AGPL-3.0, MIT, Apache-2.0 or another license is the right
  Change License depends on product strategy (copyleft for the server product
  vs. permissive licensing). This decision is **not** made by the technical
  implementation and must be reviewed by counsel:

  > **LEGAL_REVIEW_REQUIRED:** Do not adopt MIT or Apache automatically as
  > Change License. If BSL 1.1 is used, its official text must be included
  > verbatim; the Change Date must be no later than four years after first
  > publication.

- **Draft license in `LICENSE`.** The current `LICENSE` file is a custom
  source-available draft with an explicit change-to-OSL commitment. If the
  BSL 1.1 is adopted, replace it with the official BSL 1.1 text plus the
  parameters above. Both variants satisfy "source-available with Change
  Date/Change License".

## Seat counting (technical model)

```
allowedSeats = 3 + sum(validLicenses.additionalSeats)
```

- Counted are **activated personal user accounts**. Not counted: e-mail
  addresses, mailboxes, calendars, contacts, deactivated or deleted accounts.
- Technical service accounts without personal access are excluded (no
  distinguishing data model exists yet; see `docs/licensing.md`).
- Licenses of different organizations must not be combined. Keys with
  identical `license_id` count only once.
- Expired keys provide no quota; a future-dated key is not yet active.

## License states

| State | Meaning |
|---|---|
| `COMMUNITY` | up to 3 seats used, no commercial license needed |
| `VALID` | usage within the licensed seats |
| `GRACE_PERIOD` | quota exceeded; 30 days to remediate (fully functional) |
| `READ_ONLY` | grace period expired; user-initiated writes blocked, e-mail reception and reading continue |

The grace period persists in the database (`license_state`) and survives
restarts. See `docs/licensing.md` for the complete technical documentation.

## Links

- Commercial licensing: `COMMERCIAL-LICENSE.md`
- Legal notices / third-party components: `NOTICE.md`
- Technical documentation: `docs/licensing.md`
- Issuance tool (issuer side only): `bin/license-tool`

---

**LEGAL_REVIEW_REQUIRED:** Nothing in this file constitutes legal advice or a
binding license offer. All texts require review before any public release.
