# FIN-INC-56 — completed wallet top-up correction (2026-11-08)

## Historical incident and outcome

- Original top-up #56, user #494, wallet #2393: 9,000,000 tomans were credited against an operator-confirmed 900,000-toman deposit.
- A one-off correction of 8,100,000 tomans was applied on 2026-11-08 in Production as balanced journal **#183**.
- Verification showed two opposite postings of 81,000,000 rials, one audit record in `billing_topup_amount_corrections`, and final user wallet balance 542,500 tomans (at verification time).
- The original verified receipt, payment reference, customer purchases, and journal remain unchanged. No provider action was involved.

## Permanent accounting records

The applied migration `2026_11_08_230001_create_billing_topup_amount_corrections_table` and the immutable correction record are **retained** for financial integrity. The migration's rollback refuses to drop a nonempty audit table. `ReadAdminCashReceipts` must continue subtracting recorded corrections from historical cash reports, and wallet history must continue labeling correction journals accurately.

## Retired one-off remediation

The incident-specific Artisan command `finance:correct-topup-56` and its `CorrectVerifiedTopupAmount` application class were removed after the operator verified the applied financial correction. These commands must not be recreated or replayed as part of deploys or operational recovery.

## Permanent prevention

Wallet top-up approvals now require an independently entered bank amount that exactly equals the requested top-up, with validation in `ReviewTopup` at settlement time. Both photo-based and legacy reference-based Admin review paths pass this amount. The existing direct Admin verified-credit path supplies its independently entered amount. Old tokens without a verified amount fail closed. This is a source-code safeguard against amount mismatch and is not automatic verification of bank receipts.

## Production and audit boundaries

- Do not delete historical journals/postings, original top-up/receipt records, the correction audit or its migration.
- Do not create new financial postings or contact the provider as a deployment step.
- Keep independent bank evidence private; after rollout, verify one matched and one mismatched amount in controlled Admin Bot acceptance without real bank postings unless separately authorized.
