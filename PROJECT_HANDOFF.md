# MirzaBot project handoff

Updated: 2026-08-30

## Repository

- Local path: `D:\coding\bot\mirzabot`
- Branch: `main`
- HEAD: `07fe887 Refactor report topic setup to prevent concurrent updates and improve error handling`
- `origin`: `https://github.com/infowild/mirzabot.git`
- `upstream`: `https://github.com/mahdiMGF2/mirzabot.git`
- Do not change `origin` back to `Samr002`.
- Do not commit or push without explicit user permission.

## Production

- Host project path: `/var/www/mirzabot`
- Public endpoint: `https://bot.wilduser.org:8443`
- Apache is dedicated to TLS port `8443`.
- Ports `80` and `443` must not be used by MirzaBot.
- Port `443` is reserved for Paqet.
- PHP version: 8.2
- Database: MySQL
- Certificate flow: DNS-01 only

Do not store credentials in this file. An admin API token was previously printed in chat/output and should be rotated.

## Completed work already on main

- `07fe887`: report topics are probed before creation and setup is serialized to prevent duplicate topics.
- `249a9e9`: duplicate configuration loading/redeclaration was fixed.
- `58cf430`: MirzaBot moved exclusively to HTTPS port 8443.
- Installer/removal and port-conflict safety were improved in earlier commits.
- The restored production panel previously returned HTTP 302 for `/panel/` and the application root returned HTTP 200 after the PHP redeclaration repair.

## Current user request

The user wants every pending bulk/broadcast message cancelled, while preserving the separate direct message to one user. They also want the broadcast delivery bug fixed.

The legacy direct-message path sends immediately through the Telegram API; it is not stored in the broadcast files. Do not automatically replay the old direct message because that can create a duplicate. If it did not arrive, verify first and then ask the user to send it once after deployment.

## Uncommitted local changes

The following changes are intentionally unstaged and uncommitted:

- `admin.php`
  - Bulk cancellation writes `cronbot/sendmessage.cancel` before removing the queue.
  - New bulk jobs clear a stale cancellation marker.
  - Direct admin messages capture Telegram's response and report success only when `ok=true`; failures show the Telegram error.
- `cronbot/sendmessage.php`
  - Uses a non-overlapping worker lock.
  - Handles cancellation without allowing an older worker to recreate the queue.
  - Validates queue JSON.
  - Fixes the batch loop that skipped users and read invalid indexes.
  - Writes queue progress atomically through a temporary file.
- `install.sh`
  - Installs the previously missing once-per-minute PHP 8.2 broadcast cron job.
- `.gitignore`
  - Excludes runtime broadcast queue, lock, cancellation, and temporary files.

Root causes found:

1. The installer did not register `cronbot/sendmessage.php`, so queued broadcasts could remain pending indefinitely.
2. The worker removed/reindexed the array while increasing the index, which skipped users and produced invalid indexes for short queues.
3. Cancellation wrote an unrelated root `users.json` and could race with the worker.
4. Direct messages always displayed a success confirmation even when Telegram returned an error.

## Cancel the existing production broadcast before enabling the repaired cron

Run this on the server. It archives only the bulk queue and does not touch the direct-message path:

```bash
cd /var/www/mirzabot

archive="/root/mirzabot-cancelled-broadcast-$(date +%Y%m%d-%H%M%S)"
install -d -m 700 "$archive"

for file in cronbot/info cronbot/users.json; do
  if [ -f "$file" ]; then
    mv -- "$file" "$archive/$(basename "$file")"
  fi
done

echo "Broadcast queue cancelled."
echo "Backup: $archive"
```

Move `cronbot/info` first, as shown, because its absence prevents future worker invocations from sending the archived broadcast.

## Required checks before deployment

Local checks already completed:

- `bash -n install.sh`: passed.
- `git diff --check`: passed (Git only reported Windows LF/CRLF conversion warnings).
- PHP lint could not run locally because PHP is not installed on the Windows/WSL environment.

Run before deployment on a PHP 8.2 host:

```bash
cd /var/www/mirzabot
php8.2 -l admin.php
php8.2 -l cronbot/sendmessage.php
bash -n install.sh
```

After deployment:

1. Confirm the old queue files are absent.
2. Install/verify the `mirzabot-sendmessage.lock` cron line from `install.sh`.
3. Create a harmless small test broadcast to test accounts only.
4. Confirm the remaining-user counter reaches zero without skipped recipients.
5. Send one direct test message and ensure a failed Telegram response is not reported as success.
6. Inspect the application error log for Telegram or queue JSON errors.

## Safe next step

Open the repository in VS Code with the official Codex extension, read this file and `AGENTS.md`, inspect the unstaged diff, run the missing PHP lint, and report the result. Do not commit, push, deploy, enable the broadcast cron, or resend the direct user message until the user explicitly authorizes the relevant action.
