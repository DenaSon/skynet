# Vision and Scope

## Purpose
Build **SkyNet** as a maintainable Laravel successor to NVL, not as a PHP translation of its Python implementation. The application should eventually support multiple delivery interfaces without duplicating business logic.

## First UI channel
The first interactive SkyNet interface is a **Telegram bot**. Livewire/Mary UI and HTTP APIs are deferred presenters that can call the same Application actions when needed. Telegram is an adapter, not the domain or the persistence owner.

## Foundation goals
- A single, easy-to-run and easy-to-deploy Laravel application.
- Clear module boundaries that allow incremental feature delivery.
- Reliable tests, reviewable changes, and durable architecture context.
- Small documentation files aligned with actual implementation.

## Current scope
Framework bootstrap, directory conventions, explicit module provider registration, baseline CI/test smoke checks, and documentation workflow.

## Explicitly out of scope for the foundation
Network protocols and topology; WireGuard/Outline/SSH tunnel selection; Core/Edge; VPN account provisioning; payments; reseller logic; full web UI, server operations, public registration and payment. Telegram transport is introduced in F4 only as a secure foundation.

## Principles
Prefer Laravel conventions, simple implementations and measured abstractions. Future features must be driven by concrete use cases and their domain boundaries rather than speculative infrastructure.
