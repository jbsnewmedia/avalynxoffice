# Commercial License

**Status: LEGAL_REVIEW_REQUIRED — draft for review by legal counsel. This is
not a binding offer.**

## Overview

The commercial license extends the free Community use (3 user accounts) with
additive seat packages:

| Package | Additional seats | Total seats |
|---|---|---|
| Community | — | 3 |
| Team 5 | +2 | 5 |
| Team 10 | +7 | 10 |
| Team 20 | +17 | 20 |
| Individual | any | on request |

Several valid licenses of the same organization can be combined; the seats are
added together. Licenses of different organizations must not be combined.

## What a commercial subscription includes

> **LEGAL_REVIEW_REQUIRED:** The following points are a proposal for the
> commercial terms and must be reviewed and completed by counsel before any
> offer is made.

- Right to operate AvalynxOffice with more than three counted user accounts for
  the term of the agreement.
- Access to license keys signed by the copyright holder
  (`WOL1.PAYLOAD.SIGNATURE`, Ed25519).
- Optional: update and support services (separately agreed).

## Term, expiry and the READ-ONLY rule

A commercial license key contains an exclusive expiry date
(`expires_at`). After expiry the key no longer contributes seats; the
installation automatically re-evaluates the remaining entitlement:

- If at most three seats are used, Community use continues without
  interruption.
- If more seats are used than licensed, the 30-day grace period begins
  (the software remains fully functional during the grace period).
- If the over-usage persists after the grace period, the software switches to
  the restricted (READ-ONLY) mode: user-initiated write operations (sending
  e-mails, modifying e-mails/calendar/contacts, settings changes) are
  disabled; receiving, storing and reading e-mails continue to work.

**Important distinction — subscription vs. copyright:**

> **LEGAL_REVIEW_REQUIRED:** An expired commercial subscription must not be
> equated with the expiry of all copyright usage rights. The urheberrechtlich
> granted rights under the source license remain unaffected; only the
> additional seats of the expired commercial key cease. The technical
> READ-ONLY rule must be reconciled with the actually agreed contract terms:
> contractually, an expired subscription should instead trigger a duty to
> reduce the number of active accounts or to renew, with a separately agreed
> remedy — not a unilateral technical lockdown that may conflict with the
> granted usage rights. The technical implementation follows the intended
> product policy documented in `docs/licensing.md` and must be adjusted if
> counsel requires different contract terms.

## How to order

Contact **licensing@jbs-newmedia.com** (placeholder — **LEGAL_REVIEW_REQUIRED**:
confirm the actual contact channel) with:

- your organization name and the `organization_id` of your installation
  (shown on the page *Lizenz & Nutzung* in the administration area),
- the desired package or seat count,
- the desired term.

You receive a signed license key that can be entered either via the
administration interface (*Lizenz & Nutzung* → *Lizenzschlüssel hinzufügen*)
or via the environment:

```dotenv
AVALYNXOFFICE_LICENSE_KEYS="WOL1.…"
# or
AVALYNXOFFICE_LICENSE_FILE="/etc/avalynxoffice/license.keys"
```

## Associations, non-profits and educational institutions

There is **no** automatic free special license for associations, non-profit
organizations or educational institutions. Individual special licenses can be
requested at the contact above and are granted at the sole discretion of the
copyright holder.

## Prices

Prices, payment terms and subscription conditions are part of the individual
commercial agreement. They are deliberately **not** part of the technical
license validation: the software only validates the signed seat entitlement.
