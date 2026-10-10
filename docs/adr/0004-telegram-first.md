# ADR 0004 — Telegram-first Presentation

**Date:** 2026-10-11  
**Status:** Accepted (F4; CI validated PHP 8.4/8.5)

## Context
SkyNet needs a fast operational interface while retaining a modular Laravel core. The initially planned Livewire administration shell is not needed for the first release. Future web/API presenters should not duplicate business logic.

## Decision
- Implement Telegram first as a presentation/transport adapter under `app/Interfaces/Telegram`, with `nutgram/laravel` ^1.7.1.
- Keep Identity ownership of user roles and bindings. Resolve the Telegram actor through an Identity Application action returning a limited immutable DTO.
- Use a separately configured webhook secret header, fail closed when absent, and validate it before calling Nutgram. Disable overlapping Nutgram vendor safe_mode, which derives a different secret from APP_KEY. Register via SkyNet's `telegram:webhook:set`, *not* the package's built-in command.
- Persist unique Telegram update IDs with a short lease. Return HTTP 503 for an in-flight duplicate so Telegram retries; acknowledge already-processed updates. Failures free the receipt for retry.
- Link an *existing* SkyNet user from an operator CLI command using a random 48-hex-character one-time credential, SHA-256 at rest, 10-minute TTL; require a private chat and one-to-one mapping. There is no auto-registration.
- Present only `/start`, `/menu`, `/account`, `/admin`, `/link` and inline buttons now. Management operations and direct server access are deferred.

## Consequences and limits
- No public HTTP REST API required: a future Livewire component calls the same Application actions directly.
- Simple bot updates run synchronously. Future slow work must use durable queues and operation tracking.
- DB receipt deduplication is best effort. It cannot ensure exactly-once external sends if a failure occurs after Telegram accepted a reply but before the receipt commits.
- Privilege checks must be repeated on each command/callback, not inferred from old inline keyboards.
- Web session routes created in F3 remain for now; they are not used to authenticate Telegram.
- Production requires HTTPS, Telegram BotFather credentials, scheduler and a managed webhook secret.

## Non-decisions
No SSH, server agent, WireGuard, Outline, Core/Edge, payment, VPN provisioning, full Telegram Mini App or Livewire UI.
