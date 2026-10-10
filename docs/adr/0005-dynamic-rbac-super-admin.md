# ADR 0005 — Dynamic RBAC and Protected Super Admin

**Date:** 2026-10-11
**Status:** Accepted for F5, pending CI and PR merge

## Context
The initial enum `admin/member` cannot represent custom reseller/support roles or multi-role users. SkyNet's Telegram-first interface needs privileged operator protection before building management menus.

## Decision
- Install Spatie Laravel Permission v8 under the existing `web` guard, and migrate legacy role assignments without dropping existing users.
- Reserve `member`, `admin` and `super-admin`. Normal roles can be created from a trusted Super Admin Action and DB permissions; permissions are stable code-defined capabilities.
- Require **all** for elevated Super Admin Gate checks: active internal account, Spatie DB `super-admin` role, existing numeric Telegram binding, Telegram ID in environment allowlist, and at least two unique configured IDs.
- ENV entries **never** assign roles automatically. Promote a linked eligible user from an explicitly confirmed private operator CLI only. Root role may not be assigned, removed, or disabled through HTTP or bot UI. A second root can be enrolled independently; operations remain deny-by-default if allowlist config is missing.
- Normal admins can manage members; role creation/privilege assignment and peer-admin modification require a trusted Super Admin. Role/status changes get append-only audit records.
- Maintain a compatibility `role` field in current JSON responses while also returning `roles`. Do not force a public API contract or Livewire implementation.

## Trade-offs
Temporary single-admin setups during migration/initial enrollment are supported, but production should enroll two valid roots before using privileged workflows. ENV allowlist is a **guardrail**, not MFA against a fully compromised machine or Telegram account; use access controls on server and secrets management. Incident recovery and break-glass procedures require separate review. Multi-role access without per-resource ownership policies does not automatically confer permission to other users' data.

## Deferred
Full role UI via Telegram, granular reseller authorization, ownership-scoped permissions, second-factor authentication, SSH/network commands, and irreversible root recovery.
