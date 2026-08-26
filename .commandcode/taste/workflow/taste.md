# Workflow Preferences

- Prefers step-by-step incremental implementation — one step per prompt so each can be reviewed independently before proceeding. Confidence: 0.9
- Prefers verification/testing after each implementation step before moving on to the next step. Confidence: 0.85
- Prefers a clean database reset (drop & recreate) when schema conflicts exist, rather than using an alternate database name. Confidence: 0.7
- Prefers database schema changes documented as version-controlled SQL migration files (e.g., `database/migration_status.sql`) rather than ad-hoc ALTER TABLE commands. Confidence: 0.85
- For background queue processing, prefers a dedicated CLI worker script with `--once` (single pass) and `--loop` (continuous) modes rather than inline-only or cron-only approaches. Confidence: 0.7
- On this Windows/Laragon environment, schedules periodic background worker execution via Windows Task Scheduler (not cron). Confidence: 0.7
- Before final verification/commit, lints ALL new and modified PHP files together in a single `&&` chain ending with `echo ALL_LINT_OK` to confirm every touched file passes at once. Confidence: 0.75
- When shell quoting for inline `php -r` inspection commands becomes too complex (nested SQL queries, PHP object property access, multiple quote types), writes a temporary `*_tmp.php` script file instead, runs it, then `rm -f` it afterward — explicitly stated as preferring this when `php -r` quoting is "ribet" (too cumbersome). Confidence: 0.85
- For diagnosing external-service/integration failures (e.g., "notification not arriving"), inspects the app's own DB audit columns — the queue table's `status`, `error` message, and `upaya` (retry counter) — rather than assuming a code bug; the recorded HTTP error code (e.g., `HTTP 404`) pinpoints whether the root cause is app-side or on the external service/config side (as when a n8n `webhook-test/` URL vs production `webhook/` URL was the culprit), and the assistant guides the user to fix the configuration rather than silently patching code. Confidence: 0.8
- Provides API keys and other credentials directly inline in chat message text (e.g., pasting an OpenRouter key within the question itself) rather than through config files, environment variables, or secure storage, expecting the assistant to wire them up. Confidence: 0.65
