# Maguari

Lightweight, self-hosted watchdog for Ubuntu VM instances on Google Compute Engine across multiple GCP projects. Server app (dashboard, alerts, remediation) plus a push-only client on each instance. **Ubuntu only:** server, client and updater all target Ubuntu. Do not add support or workarounds for other distributions.

## Requirements

- **Server:** Ubuntu 22.04+ on a dedicated instance, PHP 8.1+ (`php-fpm`, `php-sqlite3`, built-in sodium), SQLite 3, nginx, certbot with `python3-certbot-nginx`, domain on Cloudflare DNS in DNS-only mode
- **Monitored instances:** Ubuntu 22.04+, PHP 8.1+ (`php-cli` only). No web server required; the client never listens on a port.

## Development environment

- Development runs natively on Ubuntu 24.04 with PHP 8.3. The supported minimum is PHP 8.1 on Ubuntu 22.04.
- `scripts/test-ubuntu-22.04.sh` runs the server PHPUnit suite inside an `ubuntu:22.04` Podman container with the PHP packages the project needs. The first run builds a cached image; pass `--rebuild` after changing the package list. Other arguments go to PHPUnit.
- Before each commit, run both the normal tests (`vendor/bin/phpunit` in `server/`) and `scripts/test-ubuntu-22.04.sh`. Both must pass.
- After committing, push to main when both test suites pass.

## Before writing any code

1. Read `docs/DESIGN.md`. It is the source of truth for architecture and decisions.
2. Items marked **(proposed)** in the design are not confirmed. Ask before implementing them.
3. Ask before deviating from the design. If a change is agreed, update `docs/DESIGN.md` in the same change.
4. Work on one MVP step at a time (design section 15). Do not add features outside the current step.

## Repository layout

```
server/             Slim 4 app, dashboard, SQLite, scheduler
server/src/<Context>/ one folder per bounded context (design section 2.1)
server/debian/      packaging for maguari-server
client/             reporter and command executor
client/debian/      packaging for maguari-client
updater/            small, stable update script
updater/debian/     packaging for maguari-updater
keyring/            maguari-archive-keyring package
shared/             protocol definitions and HMAC code
scripts/            build and release scripts
docs/               DESIGN.md
```

## Conventions

- Use the glossary terms in `docs/DESIGN.md` for class, table, route and UI names. Say "instance," never "VM," except in explanatory text (tooltips, docs) where "instance" also appears.
- `declare(strict_types=1);` in every PHP file. PSR-12 style.
- Server: Slim 4, `slim/csrf`, SQLite, uPlot in the browser. No other frameworks without asking.
- The client never listens on a port. Everything goes through the heartbeat.
- The client runs as an unprivileged user and executes only allowlisted actions. Root actions go through generated, scoped sudoers entries. Never arbitrary shell.
- The client never reads `/etc/letsencrypt`. A root-owned scanner publishes certificate expiry dates for it (design section 6.1.1).
- Secrets that cannot be hashed are encrypted at rest with sodium (design section 9.3).
- All timestamps in UTC.
- Readings are stored as runs (`monitoring_metric_runs`), never as one row per reading.
- Server contexts: Monitoring, Remediation, Fleet, Clients, Notifications, Access (design section 2.1). A context calls another only through its public interface class, never reads another context's tables and refers to other contexts' things by ID. Tables are prefixed with the context name.
- `server/src/Http/` stays thin. `server/src/Kernel/` holds only truly generic helpers.
- No state-changing GET routes.
- Secrets never go in the repository, logs or error messages.
- Each component has its own `VERSION` file and prefixed tags (`server-vX.Y.Z`, `client-vX.Y.Z`, `updater-vX.Y.Z`, `keyring-vX.Y.Z`). Protocol changes follow design section 4.

## Working agreements

- State assumptions explicitly.
- If multiple interpretations exist, present them instead of picking one silently.
- If a simpler approach exists, say so and push back.
- If something is unclear, stop, name what is confusing and ask before proceeding.
- Think through a full command sequence before suggesting it. Never suggest a command and then retract it in the same response. If uncertain, say so first.
- Put each shell command in its own code block.
- For editing files on Ubuntu with GNOME, recommend `gnome-text-editor`. Never recommend `nano`, `vi` or `gedit`.
- In prose and documentation: no Oxford commas and no em dashes.
