# ADR 0002 — PHP Baseline and Developer Tooling

**Date:** 2026-10-11  
**Status:** Accepted for Foundation F2

## Context
Laravel 13 supports PHP 8.3, but the initial project `composer.lock` already selected Symfony 8 dependencies requiring PHP >=8.4.1. The original lock file also encoded a local Composer mirror and was not portable to hosted CI. SkyNet needs one deterministic baseline for contributors and CI.

## Decision
- Require PHP >=8.4.1 in the project and set Composer's platform PHP version to 8.4.1 when resolving dependencies.
- Use Pest 5 (Laravel plugin) with transitive PHPUnit 13, Larastan 3 / PHPStan level 5, and Laravel Pint.
- Retain PHPUnit-format legacy smoke tests; add new Pest architecture tests for concrete boundaries.
- Run unit/feature/architecture tests, static analysis and Pint in CI on PHP 8.4/8.5.
- Keep `composer.lock` committed and portable; do not persist environment-specific Composer mirror URLs.

## Trade-offs
PHP 8.3 installations require an upgrade; future restoration of 8.3 support would be a separate ADR and dependency re-lock. Start with conservative static analysis to avoid an unmaintainable baseline. CI validation remains a requirement before merging, not an assumed outcome.

## Unrelated decisions
No network/protocol/topology or business module decisions are made by this ADR.
