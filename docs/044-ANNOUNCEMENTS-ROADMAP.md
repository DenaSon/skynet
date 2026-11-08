# ANN-01 / ANN-02 — DenaGate announcements

Decision: 2026-11-08. Scope-first plan for customer-facing, pull-only announcements. Phase 1 implementation is isolated in `feature/ann-01-admin-announcements`; phase 2 requires separate authorization and PR. No group broadcasts, recipient tracking, scheduled dispatch, provider operations, or Mini App support.

## ANN-01: private Super Admin management

- One additive `announcements` table: title, body, draft/published status, published timestamp, creator, optimistic revision, timestamps. Index public published lookup. No destructive rollback of populated data.
- Reuse existing Sales Application layer for shared actions, existing Identity trusted-root guard, Nutgram, Cache and Telegram Rich-first/fallback primitives. Do not add packages, permissions, settings or queues. `Administration` module's current architecture boundaries remain intact.
- Admin Bot: **کنترل پنل → مدیریت اعلانات**. Paginated list, detail, two-step title/body input, preview, save draft, publish, edit title/body and unpublish. One-use actor-scoped confirmation for publication transitions; keep data when unpublishing. Only current trusted Super Admin in private Admin Bot may mutate or read Admin pages.
- Validate title 1–120 and body 1–1500 characters, escape all Telegram HTML, preserve multiline text, bound form TTL to ten minutes and confirm TTL to three minutes, and check optimistic row revision under a database transaction. Retry/replay must not create duplicate records or toggle publication.
- `ReadPublishedAnnouncements` is shared read-only data access; phase 1 creates **no** customer entry point.
- Reuse existing text wizard safeguards. The new wizard must not consume SSH credentials, finance forms or unrelated privileged input. The existing buttons and routes remain registered.
- Source-level gates: focused DB and Fake Telegram tests, PHP 8.4/8.5, PHPStan, Pint, architecture regressions. CI is not proof of live Telegram/Production acceptance. Do not merge without explicit review.

## ANN-02: customer presentation — separate stacked PR

- **Sales Bot only:** show the persistent `📢 اطلاعیه‌ها` and Rich-home entry **only while at least one announcement is currently published**. Hide both shortcuts when the table is empty, contains only drafts/future-dated rows, or when the last published row is withdrawn. Refresh on each rendered keyboard/home (no stale visibility cache). Preserve the empty-state page for taps on old Telegram keyboards. When available: three most recent published announcements ordered by `published_at DESC, id DESC`, short summaries, full detail, back/home.
- Customer reads must re-check published status for each click; old callbacks for unpublished announcements must fail closed.
- Preserve buy/services first row, role-based existing buttons, private active buyer access, RTL Rich-first and same-authorized-snapshot native fallback.
- No schema/migration, outbox, scheduled/automatic broadcast, read receipts, categories, expiry, attachments, link CTA, pagination, or Mini App surface in ANN-02.
- Implementation isolated in `feature/ann-02-sales-announcements`, based on the green ANN-01 commit (`db7e1b33`). PR targets ANN-01 until the prerequisite is merged. This does not grant authorization to merge either phase; real Telegram device QA remains separate.
- Sales list: 0–3 latest published notices; each has a trimmed 120-character one-line teaser, Tehran publication date and an owner-gated detail callback. Detail displays the complete 1500-character body with safe HTML and multiline preservation. A withdrawn announcement cannot be reopened via a stale callback. Both list and detail have native Rich-first + parity HTML fallback.

## Release and rollback

ANN-01 and ANN-02 are independent gated PRs. ANN-01 DB migration is additive; on rollback of an already populated deployment, revert code and preserve data rather than dropping records. After both phases, perform explicit operator device checks for Admin and Sales iOS/Android flows. Production deploy occurs only after approved merge of the relevant phase.
