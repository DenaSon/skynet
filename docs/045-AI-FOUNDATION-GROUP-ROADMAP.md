# AI-01 / AI-02 — GapGPT foundation and Admin group chat

**Status (2026-11-09): approved planning only; not implemented.** Two phases, three small independent PRs. Explicit operator approval is required to start each implementation scope. `master` auto-deploys to Production.

## Contract and design

- Reuse Laravel 13 Modular Monolith: `app/Modules/AI/Application` for use cases/model allowlist; `app/Interfaces/Telegram` for Admin-only group presentation. No separate service or generic AI gateway.
- Use the official `laravel/ai` SDK with a named `openai-compatible` provider if its **installed** version and GapGPT response format pass verification. GapGPT documents OpenAI `chat.completions`, Bearer API key and `https://api.gapgpt.app/v1` (optional alternate: `https://api.gapapi.com/v1`). Verify the final SDK configuration and real request before activation.
- Initial *candidates*, not proven-live allowlist: `gpt-6-luna`, `gapgpt-deepseek-v4.1-flash`, `gpt-6.1-terra`, `gpt-6.1-sol`. Only enable IDs that return valid live text responses. No model-specific clients or automatic unverified model discovery.
- Secrets and infrastructure URL in deployment ENV; separate `DENAGATE_AI_ENABLED=false` global kill switch and trusted `DENAGATE_AI_GROUP_CHAT_ID`. Reject missing/invalid settings, group IDs and unknown models. Never put API keys, prompts or provider payloads in logs.
- Reuse existing Redis/queue and Identity Actions. No business SQL access, Provider tools, SSH, payment actions or user-specific account-data tools. Never give the model an executable or privileged capability.

## AI-01 — Foundation (PR 1)

- Install a compatible, reviewed `laravel/ai` release, lock Composer dependencies, and configure the GapGPT provider in `config/ai.php` (verify actual package schema); keep operator configuration minimal in `config/denagate_ai.php`.
- Add focused `AskAssistant` and `ModelCatalog` Application responsibilities: allowlisted text-only models, bounded input/output, safe response extraction and sanitized errors. The application entrypoint is transport-agnostic; no Telegram integration yet.
- No new business tables. Do not publish/run conversation-store migrations unless proven necessary and separately approved; no permanent history in this phase.
- Test missing key, disabled switch, unknown model, malformed/empty response, timeout, 429/5xx, sensitive log redaction and ordinary success with fakes. Separate controlled **real** GapGPT smoke of at least two approved model IDs; no live key in CI or Git.
- Exit: reproducible supported SDK contract, offline CI green, real provider behavior documented separately. No Production key configuration or enablement as part of source PR.

## AI-02 — Admin Telegram group conversation (PR 2 + PR 3)

**AI-02A / PR 2 — isolated group intake and first reply**

- Register only Admin Bot `/ai` and explicit reply-to-bot continuation. Do not change or weaken `PrivateTelegramUser`, legacy command/menu precedence, Sales Bot routes or existing financial/provider authorization.
- Accept only the configured Telegram `group`/`supergroup` chat ID and a fresh active, allowlisted Super Admin using the existing Identity checks. Validate numeric `from.id`; reject anonymous admins, channels, other groups, users, bots and unrelated group messages. Keep Telegram Privacy Mode enabled when sufficient; confirm actual Nutgram route precedence with real group smoke.
- Reuse `TelegramUpdateReceipts`; guard one AI intent per Admin Bot + chat + message identity before dispatch. The webhook only validates/enqueues; any ambiguous upstream delivery must not blindly re-run billable requests. Output replies in the same chat/topic, with escaped Telegram text and bounded message size.
- Use existing queue connection but dedicated queue name `ai`: one separately supervised worker; `--queue=ai --tries=1 --timeout=45 --max-time=3600`. Check the effective `retry_after` is greater than worker timeout, HTTP timeout is shorter than worker timeout, worker/replica count, and the default queue remains untouched. Reject sync/null connections for billable AI jobs. A new Runflare Supervisor program is an **operator-approved deployment step**, not part of documentation.

**AI-02B / PR 3 — models, limited context, cost/reliability**

- Add `/aimodel` (allowlist selection), `/ainew` (clear context) and `/aistatus` (safe model/limit overview); no free-form model identifier from chat.
- Use existing Redis Cache for bounded short-lived message context, scoped by Admin Bot + chat + topic + actor. Suggested initial limits to tune after smoke: up to 6 messages / 30-minute TTL, 2,000 input characters, 5 requests per actor per 10 minutes and 50 per group per day. Never persist raw conversation history indefinitely.
- Revalidate group, actor authorization, kill switch and model at job execution; enforce bounded concurrency, response length and timeouts. Track redacted counts/latency and provider-reported usage if present; do not infer exact billing from absent/ambiguous usage.
- Test callback/command conflicts, privacy/group/role denial, duplicate updates/jobs, thread and actor isolation, model changes, expired history, queue outage, upstream failure and Telegram send ambiguity. Real group/Topic smoke and permission-revocation checks are distinct from CI.

## Release gates, rollback and deferred scope

- Each PR: smallest code change, focused Pest tests, PHPStan/Pint and current CI matrix; explicitly record PHP 8.5 evidence where applicable. No mocks presented as real API or Telegram acceptance.
- Enable only after valid Secret, authorised group, running `ai` worker, negative authorization tests and bounded real GapGPT/Telegram smoke. Roll back by disabling the AI switch and stopping the AI worker; retain original Admin/Sales routes and `default` queue.
- **Future work, not authorised here:** customer wallet/subscription read-only tools, sales assistant, FAQ/Knowledge Base, RAG/embeddings, image/voice generation, admin operations, and access to financial or Remnawave mutations.

References: [Laravel AI SDK](https://laravel.com/framework/docs/13.x/ai-sdk), [GapGPT quickstart](https://gapgpt.app/platform-v2/docs/quickstart), [architecture](002-ARCHITECTURE.md), [roadmap index](005-ROADMAP.md).
