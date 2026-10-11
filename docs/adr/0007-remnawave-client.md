# ADR 0007 — Remnawave Contract-Mapped HTTP Client

**Date:** 2026-10-11
**Status:** Proposed in F10-A PR; live-panel contract verification pending.

## Context
SkyNetwork uses Laravel 13/PHP 8.4+ and maintains commercial orders and wallet in separate modules. Remnawave has a versioned OpenAPI contract with 218 operations (v3.4.4). SDKs for other languages would add runtime complexity without a PHP/Laravel benefit.

## Decision
Use Laravel's HTTP Client with a pinned operation catalog under Provisioning/Infrastructure. Map `operationId` to method/path and minimal request metadata; defer complex request/response DTOs and domain operations until concrete use cases. The transport must enforce HTTPS, bearer auth, bounded timeouts, redirect denial, and fail-fast HTTP errors. Never auto-retry an ambiguous mutating request.

## Consequences
The catalog makes route coverage auditable, but does not imply 218 fully validated high-level SDK methods. Authorization, financial idempotency, ownership, retry reconciliation, webhook authentication, and privileged admin confirmations must be implemented in the appropriate application boundaries. Keep this transport private to server-side code. Test against an isolated real panel before enabling customer purchases.

## Sources
- https://docs.rw/api/
- https://raw.githubusercontent.com/Jolymmiles/remnawave-api-go/v3.4.4/specs/3.4.4.json
