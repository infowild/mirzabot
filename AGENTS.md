# MirzaBot project rules

These rules apply to every agent working in this repository.

## User and communication

- Communicate with the user in Persian unless they request another language.
- Lead with the outcome and keep operational instructions copy/paste friendly.
- Never commit or push unless the user gives explicit permission for that specific action.
- Preserve unrelated working-tree changes and never discard user data.

## Deployment constraints

- Production code lives at `/var/www/mirzabot`.
- MirzaBot's Apache virtual host must listen only on TLS port `8443`.
- MirzaBot must never bind or depend on ports `80` or `443`.
- Port `443` is reserved for Paqet.
- The public bot endpoint is `https://bot.wilduser.org:8443`.
- Use DNS-01 for certificate issuance and renewal; do not enable an HTTP challenge.
- Cron workers must run through PHP CLI and must not depend on an HTTP endpoint.

## Security

- Never copy tokens, passwords, database credentials, private keys, backups, or runtime queue data into commits, logs, handoff files, or chat.
- Treat any credential previously pasted into a chat as exposed and recommend rotating it.
- Runtime broadcast files under `cronbot/` must remain untracked.
- Prefer recoverable operations (archive/move) over deletion for production data.

## Verification

- Run `php8.2 -l` on every changed PHP file.
- Run `bash -n install.sh` after installer changes.
- Run `git diff --check` and inspect `git status --short`.
- For production changes, verify the webhook, Apache configuration, cron entry, and relevant logs without exposing secrets.

## Current handoff

Read `PROJECT_HANDOFF.md` before continuing current work.
