# Mailing — working notes for AI assistants

Mass-mailing platform for Soportic's projects (mailing.soportic.es, CloudPanel).
Plain PHP 8.1+ and MySQL, **no Composer or Node dependencies** (shared hosting,
OTA copies files only). UI in Catalan; code and commits in English.

## Rules

- Never push to `main` without asking: every merge to `main` with a higher
  `version.txt` is offered as an OTA update to production.
- Bump `version.txt` (semver) in every change meant for production.
- Migrations in `database/migrations/*.sql` are additive and idempotent
  (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`). Never edit an
  applied migration: add a new file. A failed migration rolls the OTA back.
- Everything is scoped by `project_id`. Use `Controller::own()` to load a row
  of the current project; never trust ids from the request.
- The editor is a port of the Tauler's Mailing editor (iSEM,
  `bedesk-plugins/tauler`): `src/Editor/MailDoc.php`, `MailRenderer.php`,
  `RichText.php`, `public/assets/editor.js`. Keep them close to the original so
  fixes can be shared. Documents are multi-language envelopes
  (`src/Editor/Document.php`); the HTML is always rendered on the server.
- Secrets (API keys, remote DB passwords) are stored with `Crypto` (APP_KEY).
- External base URLs (`MAILJET_BASE`, `AI_ANTHROPIC_URL`, `AI_OPENAI_URL`,
  `UPDATE_RAW_BASE`, `UPDATE_API_BASE`) are only for tests; never set them in
  production.

## Tests

```bash
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
bash tests/run.sh   # fresh install + end-to-end, local MariaDB/MySQL as root
```
