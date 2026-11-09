# AI-01 / AI-02 — GapGPT foundation and Denix group chat

**Status (2026-11-09): AI-01, AI-02A and AI-02B merged (#425–#427); Deniz identity/knowledge update pending CI.** Two phases, three small independent PRs. Explicit operator approval is required to start each implementation scope. `master` auto-deploys to Production.

## Contract and design

- Reuse Laravel 13 Modular Monolith: `app/Modules/AI/Application` for use cases/model allowlist; `app/Interfaces/Telegram` for the separate Denix bot and Super Admin-only group presentation. No separate service or generic AI gateway.
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

### AI-01 source checkpoint

- Source PR adds `laravel/ai` v1.3.0 (`openai-compatible`) with locked `symfony/yaml`, a scoped text-only Application Action, explicit model allowlist, config flags and fake HTTP tests. No Telegram integration or new tables.
- Deployment ENV (future operator step): `DENAGATE_AI_ENABLED=false`, `GAPGPT_API_KEY` as a secret; optional `GAPGPT_BASE_URL` only for documented GapGPT endpoints. No key or real upstream call in CI.
- Real GapGPT API smoke succeeded for `gpt-6-luna` and `gapgpt-deepseek-v4.1-flash` (HTTP 200, text plus usage). Full Laravel-to-GapGPT and live Telegram acceptance still require operator-controlled activation.
- AI-01 was merged in PR #425; AI-02A was merged in PR #426. Subsequent AI-02B and activation remain separate gates.

## AI-02 — independent Denix Telegram bot (PR 2 + PR 3)

**Approved identity:** Denix (`@denixobot`) is a third Telegram bot in the same Laravel application; its BotFather creation is complete. Keep its token, independent webhook secret, Runflare ENV and webhook registration for the later operator-controlled activation stage; no token in Git or chat.

**AI-02A / PR 2 — isolated group intake and first reply**

- Add a dedicated Denix Nutgram runtime and handler registry, independent token and secret, a protected `/api/telegram/ai/webhook` route and explicit `bot_key=ai` (no unknown-key fallback to Admin). Register only Denix `/ai` and explicit reply-to-Denix continuation. Do not change or weaken `PrivateTelegramUser`, existing Admin/Sales handlers, menus or financial/provider authorization.
- Accept only the configured Telegram `group`/`supergroup` chat ID and a fresh active, allowlisted Super Admin using the existing Identity checks. Validate numeric `from.id`; reject anonymous admins, channels, other groups, users, bots and unrelated group messages. Keep Telegram Privacy Mode enabled when sufficient; confirm actual Nutgram route precedence with real group smoke.
- Reuse `TelegramUpdateReceipts`; guard one AI intent per Denix Bot + chat + message identity before dispatch. The webhook only validates/enqueues; any ambiguous upstream delivery must not blindly re-run billable requests. Output replies in the same chat/topic, with escaped Telegram text and bounded message size.
- Use existing queue connection but dedicated queue name `ai`: one separately supervised worker; `--queue=ai --tries=1 --timeout=45 --max-time=3600`. Check the effective `retry_after` is greater than worker timeout, HTTP timeout is shorter than worker timeout, worker/replica count, and the default queue remains untouched. Reject sync/null connections for billable AI jobs. A new Runflare Supervisor program is an **operator-approved deployment step**, not part of documentation.

### AI-02A deployment checkpoint (NOT activated)

- Third bot identity: `@denixobot`. Only its own `/api/telegram/ai/webhook` accepts `bot_key=ai`; Admin and Sales registrations are untouched.
- Stage 1 operator secrets/settings later: `TELEGRAM_DENIX_TOKEN`, `TELEGRAM_DENIX_WEBHOOK_SECRET` (distinct from both bots), `DENAGATE_AI_GROUP_CHAT_ID` (negative chat ID), optional `TELEGRAM_DENIX_BOT_USERNAME=denixobot`. Keep `TELEGRAM_DENIX_ENABLED=false` and `DENAGATE_AI_ENABLED=false` until final acceptance.
- Stage 2 operator queue: the dedicated Runflare Supervisor program **is already defined** (operator-reported): `queue:work --queue=ai --sleep=3 --tries=1 --timeout=45 --max-time=3600`, `numprocs=1`. Still verify it is running on a shared Redis/database Queue/Cache, with effective `retry_after > 45` and the `default` worker isolated. Register `php artisan telegram:webhook:set --bot=denix` only during the authorized rollout.
- Only trusted, linked, active Super Admins in the configured group can submit `/ai` and reply to Denix; seven-day replay fences require an atomic shared Redis/database Cache store and prevent duplicated billable work and uncertain Telegram resend. A minimal per-actor/group throttle is applied at intake. Full model selection and conversation memory remain AI-02B.
- No migrations or new infrastructure; GitHub CI is not real Telegram, GapGPT-through-Laravel, or Runflare queue acceptance. Test in the real group only after secret/worker/operator approvals.

**AI-02B / PR 3 — models, limited context, cost/reliability**

- Add `/aimodel` (allowlist selection), `/ainew` (clear context) and `/aistatus` (safe model/limit overview); no free-form model identifier from chat.
- Use existing shared Redis/database Cache for bounded short-lived message context, scoped by Denix Bot + chat + topic + actor: 30 alternating messages per manager/group/topic / sliding 30-minute TTL, up to 6,000 context characters, 2,000 input characters, 5 requests per actor per 10 minutes and 50 per group per day. Never persist raw conversation history indefinitely. Only two real-smoke-verified models are selectable via numeric choices; no raw model IDs from chat.
- Revalidate group, actor authorization, kill switch and model at job execution; lock the actor/topic session during a billable call and record memory only after successful Telegram delivery. Track only model, redacted latency and provider-reported token usage when nonzero; do not infer exact billing from missing/ambiguous usage.
- Test callback/command conflicts, privacy/group/role denial, duplicate updates/jobs, thread and actor isolation, model changes, expired history, queue outage, upstream failure and Telegram send ambiguity. Real group/Topic smoke and permission-revocation checks are distinct from CI.


### Deniz identity and economy candidates (2026-11-09)

- Customer-facing assistant name is **«دنیز» / Deniz**; internal `Denix*` names, BotFather username `@denixobot`, webhook and Redis keys remain unchanged for compatibility. Operator can update only the display name using BotFather.
- A short versioned `DenizKnowledge` prompt contains **fixed, public** product facts and assistant boundaries, not mutable prices, uptime, sales metrics or customer/provider data. It is sent with each request; Redis conversation context is separately capped at **30 alternating messages**, **6,000 characters**, and **30 minutes** (no new persistent store).
- Keep only the two real-smoke-tested model IDs selectable by default. New *candidates* `gemini-2.5-flash-lite` and `gpt-5-nano` stay OFF until their exact IDs, chat completion compatibility, real GapGPT price and response quality are verified. After verification, operators may opt in per exact ID via `DENAGATE_AI_VERIFIED_ECONOMY_MODELS` (comma-separated; unknown IDs are ignored). `gpt-5-nano` is deprecated upstream with a December 11, 2026 shutdown notice; avoid enabling it for a long-lived default. No automatic provider catalog or prices are assumed.
- No BotFather token/Webhook changes, no migrations, new dependencies, queue changes, extra Agent tools or new operational permissions. Existing `ai` worker and `default` worker remain separate.

## Release gates, rollback and deferred scope

- Each PR: smallest code change, focused Pest tests, PHPStan/Pint and current CI matrix; explicitly record PHP 8.5 evidence where applicable. No mocks presented as real API or Telegram acceptance.
- Enable only after valid Secret, authorised group, running `ai` worker, negative authorization tests and bounded real GapGPT/Telegram smoke. Roll back by disabling the AI switch and stopping the AI worker; retain original Admin/Sales routes and `default` queue.
- **Future work, not authorised here:** customer wallet/subscription read-only tools, sales assistant, FAQ/Knowledge Base, RAG/embeddings, image/voice generation, admin operations, and access to financial or Remnawave mutations.

References: [Laravel AI SDK](https://laravel.com/framework/docs/13.x/ai-sdk), [GapGPT quickstart](https://gapgpt.app/platform-v2/docs/quickstart), [architecture](002-ARCHITECTURE.md), [roadmap index](005-ROADMAP.md).
