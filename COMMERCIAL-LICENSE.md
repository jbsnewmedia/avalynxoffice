# Commercial License

> This is not a binding offer. The individually agreed commercial terms take
> precedence.

The basis for all licensing is the **AvalynxOffice Public License (APL),
version 1.0** (see `LICENSE`). The commercial license extends the free
Community use (3 Active Seats) with additive seat packages:

| Package | Additional seats | Total seats |
|---|---|---|
| Community | — | 3 Active Seats |
| Team 5 | +2 | 5 |
| Team 10 | +7 | 10 |
| Team 20 | +17 | 20 |
| Individual | any | on request |

Seats are counted as **Active Seats** (APL 1.0 § 1): activated user accounts
with at least one successful authentication (login or device sync) within the
last 30 calendar days. Several valid licenses of the same organization can be
combined; the seats are added together. Licenses of different organizations
must not be combined.

## What a commercial subscription includes

- Right to operate AvalynxOffice with more than three Active Seats for
  the term of the agreement.
- Access to license keys signed by the copyright holder
  (`WOL1.PAYLOAD.SIGNATURE`, Ed25519).
- Optional: update and support services (separately agreed).

## Term, expiry and the READ-ONLY rule

A commercial license key contains an exclusive expiry date
(`expires_at`). After expiry the key no longer contributes seats; the
installation automatically re-evaluates the remaining entitlement:

- If at most three Active Seats are used, Community use continues without
  interruption.
- If more Active Seats are used than licensed, the 30-day grace period begins
  (the software remains fully functional during the grace period).
- If the over-usage persists after the grace period, the software switches to
  the restricted (READ-ONLY) mode: user-initiated write operations (sending
  e-mails, modifying e-mails/calendar/contacts, settings changes) are
  disabled; receiving, storing and reading e-mails continue to work.

**Important distinction — subscription vs. copyright:**

An expired commercial subscription must not be equated with the expiry of all
copyright usage rights. The urheberrechtlich granted rights under the APL 1.0
remain unaffected; only the additional seats of the expired commercial key
cease. Contractually, an expired subscription triggers a duty to reduce the
number of Active Seats or to renew, with a separately agreed remedy. The
technical implementation follows the product policy documented in
`docs/licensing.md`.

## How to order

Contact **licensing@jbs-newmedia.com** with:

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
