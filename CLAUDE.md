# Mailing — working notes for AI assistants

Mass-mailing platform for Soportic's projects (mailing.soportic.es, CloudPanel).
Plain PHP 8.1+ and MySQL, **no Composer or Node dependencies** (shared hosting,
OTA copies files only). UI in Catalan; code and commits in English.

## Rules

- Never push to `main` without asking: every merge to `main` with a higher
  `version.txt` is offered as an OTA update to production.
- Bump `version.txt` (semver) in every change meant for production.
- Migrations in `database/migrations/*.sql` are additive and idempotent
  (`CREATE TABLE IF NOT EXISTS`). MySQL 8 has no `ADD COLUMN IF NOT EXISTS`:
  check `information_schema` and `PREPARE` the `ALTER` as in
  `2026_11_11_000001_automations.sql`. Never edit an applied migration: add a
  new file. A failed migration rolls the OTA back.
- Everything is scoped by `project_id`. Use `Controller::own()` to load a row
  of the current project; never trust ids from the request.
- The editor is a port of the Tauler's Mailing editor (iSEM,
  `bedesk-plugins/tauler`): `src/Editor/MailDoc.php`, `MailRenderer.php`,
  `RichText.php`, `public/assets/editor.js`. Keep them close to the original so
  fixes can be shared. Documents are multi-language envelopes
  (`src/Editor/Document.php`); the HTML is always rendered on the server.
- Automations (`src/Services/Automations.php`, `public/assets/flow.js`, a
  dependency-free port of the Torns panel's FlowBuilder; see
  `docs/automatitzacions.md`): their emails are `campaigns` rows with
  `status = 'auto'` and `automation_id`; keep them out of campaign listings.
- Secrets (API keys, remote DB passwords) are stored with `Crypto` (APP_KEY).
- External base URLs (`MAILJET_BASE`, `AI_ANTHROPIC_URL`, `AI_OPENAI_URL`,
  `UPDATE_RAW_BASE`, `UPDATE_API_BASE`) and `PUSH_ALLOW_HTTP` are only for
  tests; never set them in production.
- PWA, push notices and the adaptive menus: `docs/app-i-avisos.md`. Notices go
  through `Notify` (never push from a request that sends email); Web Push is
  done with OpenSSL in `WebPush` (no libraries).

- `docs/format-plantilles.md` is generated: after changing `MailDoc` or
  `TemplateFormat`, run `php bin/console.php template-format > docs/format-plantilles.md`
  (CI fails if it is out of date).

## Tests

```bash
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
bash tests/run.sh   # fresh install + end-to-end, local MariaDB/MySQL as root
```
