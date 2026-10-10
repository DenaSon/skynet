# ADR 0001 — Laravel Modular Monolith

**Date:** 2026-10-11  
**Status:** Accepted for the foundation

## Context
SkyNet is being built separately from the Python NVL implementation. It needs a lightweight, testable core that can grow without splitting deployment and operations across services too early.

## Decision
Use Laravel 13 as one application, one deployable and one primary datastore, with explicit domain-oriented module boundaries inside `app/Modules`. Register module providers explicitly in `bootstrap/providers.php`. Introduce module sublayers only for concrete code.

## Consequences
- Simple deployments and familiar Laravel tooling.
- Developers must enforce dependencies across module boundaries.
- No custom module discovery library or shared framework now.
- Modules may be refactored later as real use cases establish their responsibilities.

## Deliberately not decided
Networking, protocol choice (including WireGuard or Outline), Core/Edge layout, server agents, external APIs, and eventual production infrastructure.
