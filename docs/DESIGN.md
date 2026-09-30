# Maguari Design

Maguari is a lightweight, self-hosted watchdog for **Ubuntu** VM instances on Google Compute Engine, spread across multiple GCP projects. It is built for Ubuntu only: the server, the client and the updater all run on Ubuntu and are distributed as Ubuntu packages. Other distributions are not supported.

A server app shows a daily dashboard, raises alerts by email and fixes what it can automatically (restarting services through a client, or resetting an instance through the Compute Engine API). A small client runs on each monitored instance.

The name comes from the maguari stork, a bird of the Pantanal (Mato Grosso do Sul, Brazil) that soars at great heights on thermals.

Maguari is not an official Google product. "Google Cloud" and "Compute Engine" appear only in descriptions, never in names.

## Requirements

### Server (`maguari-server`)

- Ubuntu 22.04 or later, on a **dedicated** instance (designed for the free tier e2-micro)
- PHP 8.1 or later with `php-fpm` and `php-sqlite3` (the sodium extension is built into PHP)
- SQLite 3
- nginx (ports 80 and 443 must be free; see section 12.2)
- certbot with the nginx plugin (`python3-certbot-nginx`), from the Ubuntu archive
- A domain name pointing at the instance (Cloudflare DNS in DNS-only mode; see section 11.4)
- A service account attached to the instance, with the access scope described in section 8

### Monitored instances (`maguari-client` and `maguari-updater`)

- Ubuntu 22.04 or later
- PHP 8.1 or later (`php-cli` only; installed automatically by apt if missing)
- No web server required. Any web server already running (nginx, Apache or none) is fine, because the client never listens on a port.

## How to read this document

Items marked **(proposed)** were suggested but not explicitly confirmed. Confirm them before implementing, or change them here first. Everything else is decided. If code and this document disagree, fix one of them in the same change.

## Glossary

Every term below has exactly one meaning, used identically in conversation, documentation, code (class, table and route names) and the UI.

Maguari follows GCP's own wording: the Compute Engine console calls them "VM instances," and Maguari calls them **instances**. The word "VM" appears only in explanatory text (tooltips, documentation), and always next to "instance," so users learn the term Maguari uses.

| Term | Meaning |
|---|---|
| Administrator | A person allowed to use the web app |
| Project | A GCP project added to Maguari |
| Instance | A Compute Engine VM instance inside a project |
| Discovered instance | An instance listed from the API but not yet enrolled |
| Enrolled instance | An instance whose client has completed enrollment |
| Server instance | The instance running `maguari-server` |
| Client | The software running on an enrolled instance (not the instance itself) |
| Heartbeat | A signed message from a client to the server |
| Metric | The kind of thing measured (for example disk used on `/`) |
| Reading | One measured value at one moment |
| Run | A stored stretch of consecutive equal readings |
| Check | A test with a pass or fail result (HTTP, database, heartbeat age) |
| Incident | An ongoing problem on one instance, from its first failed check until recovery |
| Action | Something Maguari does to fix a problem: restart, reboot or reset |
| Restart | Restarting one service through the client |
| Reboot | Rebooting the OS through the client |
| Reset | Resetting the instance through the Compute Engine API |
| Command | An action delivered to a client inside a heartbeat response |
| Alert | An email sent to the administrator |
| Notification | An item shown in the web app about an incident or a system condition (section 10.4) |
| Mode | An instance's remediation setting: automatic, alert-only or maintenance |

## 1. Goals and non-goals

### Goals

1. One daily screen answering: are instances, databases and web apps responsive? Is disk usage safe? Are certificates far from expiry?
2. Automatic remediation with strong safeguards: restart a service through the client, reset an instance through the API only as a last resort.
3. Email alerts to the administrator.
4. Dynamic configuration through the web app (GCP projects, instances, checks, thresholds), with instance lists fetched from each project.
5. Very light: the server runs on a free tier e2-micro instance.
6. Secure by default: the admin surface is public on the internet, so it must be hardened.
7. As easy as possible for administrators: install with apt, configure in the web app.
8. Published on GitHub as a public open-source project under the MIT license.

### Non-goals (for now)

1. Distributions other than Ubuntu.
2. Multi-cloud or non-Compute Engine targets.
3. Log aggregation, tracing or APM.
4. Full application reset and uninstall (planned after 1.0; see Roadmap).
5. Multiple user roles. Every allowed user is an administrator.

## 2. Components

| Component | Runs on | Role |
|---|---|---|
| `maguari-server` | The dedicated server instance | Web dashboard, ingestion API, check scheduler, remediation engine, email, GCP API calls |
| `maguari-client` | Each monitored instance | Collects readings, runs local checks, sends heartbeats, executes allowlisted commands received in heartbeat responses |
| `maguari-updater` | Each monitored instance | Installs a specific client version with apt when requested, verifies the new client works and rolls back if not |
| `maguari-archive-keyring` | Server and monitored instances | Installs the APT repository's signing key and source file, so users never handle keys manually |
| `shared/` | Both sides (not a package) | Protocol definitions and HMAC signing code used by server and client |

All components are PHP.

### 2.1 Server bounded contexts

The server is a **modular monolith**: one application, split into bounded contexts. Each context owns its own model, its own code and its own tables. Contexts are PHP namespaces and folders, not separate services.

| Context | Owns | Type |
|---|---|---|
| **Monitoring** | Metrics, readings, runs, checks and their results, the daily job, the egress indicator | Core |
| **Remediation** | Incidents, actions, modes, safeguards, escalation ladder, circuit breaker | Core |
| **Fleet** | Projects, instances (discovered and enrolled), the GCP API adapter | Supporting |
| **Clients** | Heartbeat protocol, HMAC verification, enrollment, command delivery, real-time mode, update rollout | Supporting |
| **Notifications** | Alerts (email), notifications (dashboard), SMTP | Generic |
| **Access** | Administrators, Google sign-in, allowlist, setup token and wizard, seed config file, sessions | Generic |

- **Core:** what makes Maguari worth building. Gets the most care and the tactical DDD patterns.
- **Supporting:** necessary and specific to Maguari, but not special.
- **Generic:** a solved problem everywhere. Use libraries and keep it simple.

Fleet and Clients are separate because they change for different reasons: Fleet when GCP's API changes, Clients when the protocol changes.

#### Rules between contexts

1. A context calls another context only through that context's public interface (one class per context, for example `Fleet\FleetApi`). Nothing else in a context is used from outside.
2. A context never reads or writes another context's tables.
3. Contexts refer to each other's things by ID only (an incident stores an instance ID, not an instance object).
4. The GCP word "instance" in API responses is translated into Fleet's own model inside the GCP adapter. No other context sees raw GCP API data.

#### Flow of one failing heartbeat

1. **Clients** receives the heartbeat, verifies the HMAC and passes readings and check results to Monitoring.
2. **Monitoring** stores readings as runs and evaluates checks. A check fails.
3. **Remediation** learns about the failure, opens an incident and decides the next action on the escalation ladder.
4. For a restart or reboot, Remediation asks **Clients** to queue a command. For a reset, it asks **Fleet** to call the GCP API.
5. **Notifications** learns the incident opened, sends the alert and shows the notification.

Monitoring does not know Remediation exists, and Remediation does not know how emails are sent. Steps 3 and 5 will use domain events (a later design step). Until then, contexts use direct calls to public interfaces.

## 3. Repository layout

One repository (monorepo), because server and client share a protocol that must change atomically. Each component keeps its Debian packaging next to its source.

```
server/             Slim 4 app, dashboard, SQLite, scheduler
server/debian/      packaging for maguari-server
client/             reporter and command executor
client/debian/      packaging for maguari-client
updater/            small, stable update script
updater/debian/     packaging for maguari-updater
keyring/            maguari-archive-keyring package
shared/             protocol definitions and HMAC code
scripts/            build and release scripts
docs/               DESIGN.md and other documentation
```

The client package contains only `client/` plus `shared/`. Instances never download server code.

### 3.1 Inside `server/`

```
server/src/Monitoring/       Core context
server/src/Remediation/      Core context
server/src/Fleet/            Supporting context
server/src/Clients/          Supporting context
server/src/Notifications/    Generic context
server/src/Access/           Generic context
server/src/Kernel/           Truly generic helpers only (clock, IDs, secrets encryption)
server/src/Http/             Slim wiring: routes, route groups, middleware, thin controllers
server/templates/            Plain PHP templates for the web app (no template engine)
server/public/               index.php only (web root)
```

1. PHP namespace root: `Maguari\Server\` mapped to `server/src/` (PSR-4).
2. Each context keeps its database migrations inside its own folder, as numbered `.sql` files in `server/src/<Context>/Migrations/`. A runner in `Kernel/Database/` finds them by scanning those folders (so Kernel never names a context), applies pending ones and records them in `kernel_migrations`, the one table without a context prefix. Migrations run from the CLI (`maguari-server migrate`), never on a web request.
3. Table names are prefixed with the context name (for example `fleet_instances`, `monitoring_metric_runs`), so ownership is visible in SQLite.
4. `Http/` stays thin: controllers translate HTTP into calls on a context's public interface and nothing more.
5. `Kernel/` is not a dumping ground. Anything with Maguari-specific meaning belongs in a context.
6. Internal structure of each context (layers) is decided in a later design step, starting with the core contexts.

## 4. Versioning

Versioning is explicit and per component.

1. Each component follows Semantic Versioning (`MAJOR.MINOR.PATCH`).
2. Each component has its own `VERSION` file in its directory.
3. Each component has its own prefixed Git tags: `server-v1.0.0`, `client-v1.3.0`, `updater-v1.0.0`, `keyring-v1.0.0`.
4. A separate integer **protocol version** is sent in every heartbeat. It increments only on breaking changes to the client/server message format (for example renaming a field). Adding an optional field that old clients can ignore does not increment it.
5. The server supports the current protocol version and the previous one, so a fleet can be mid-rollout safely.

## 5. Communication model

### 5.1 Push only

The client never listens on any port. All communication starts with the client sending a heartbeat over HTTPS to the server. The server delivers anything it needs from the client inside the heartbeat **response** (similar to a LoRaWAN Class A downlink window).

Consequences:

1. No inbound firewall rules on monitored instances.
2. The server IP can change without breaking clients, because clients target a domain.
3. Worst-case command latency equals the heartbeat interval (60 s by default). This is acceptable.

Unresponsiveness is detected by combining: missing heartbeats, server-side HTTP checks of public web apps and the instance status from the Compute Engine API.

### 5.2 Heartbeat

Default interval: 60 seconds (limits in section 7.3).

Request payload:

- `protocol_version`, `client_version`, `client_id`
- `sent_at` (UTC)
- `readings`: disk used and total per filesystem, plus any other metrics
- `checks`: results of local checks (services, local HTTP, database query, local certificate files)
- `command_results`: outcomes of previously received commands, by command ID

Total disk size is sent in every heartbeat together with used space (both come from the same system call). Storage cost is negligible because of run-length storage (section 9), and percentage alerts always use the current total.

Response payload:

- `commands`: list of `{id, action, args, expires_at}`
- `report_interval`: optional `{seconds, until}` to temporarily increase reporting frequency (real-time mode). It must always have an end time.
- `upgrade`: optional `{version}` for the updater (section 12.5)

When there is nothing to send, the response body is empty. This matters for the free tier egress limit (section 14).

### 5.3 Commands

1. Every command has a unique ID. The client reports its result on the next heartbeat.
2. Every command has an expiry (limits in section 7.3). A client that comes back online after an outage discards expired commands instead of running stale actions.
3. The client only executes actions from a **local allowlist** in its own configuration, mapping action names to fixed commands (for example `restart-php-fpm` maps to `systemctl restart php8.1-fpm`). The server can never send arbitrary shell.

### 5.4 Client privileges

The client runs as an unprivileged system user (`maguari-client`). Only the actions that truly need root go through **scoped sudoers entries**, which allow exactly those commands and nothing else.

| Task | Needs root? |
|---|---|
| Installing the client | No (the administrator runs apt) |
| Disk usage, service state (`systemctl is-active`) | No |
| Local HTTP and database checks | No (the database check uses a read-only database user) |
| Reading certificate expiry dates | No (a root-owned scanner publishes them; section 6.1) |
| Restarting allowlisted services, rebooting the OS | Yes, through sudoers |
| Updating the client | No (the updater runs separately as root; section 12.5) |

The package generates the sudoers file (`/etc/sudoers.d/maguari-client`) from the client's allowlist and validates it before installing it. A command that is not in the allowlist can never appear in sudoers.

### 5.5 Client authentication (HMAC)

Each client has its own secret, known only to that client and the server. Every request is signed individually.

- Signature: `HMAC-SHA256(secret, method + "\n" + path + "\n" + timestamp + "\n" + nonce + "\n" + sha256(body))`
- Headers: `X-Maguari-Client`, `X-Maguari-Timestamp`, `X-Maguari-Nonce`, `X-Maguari-Signature`
- Replay protection: the server rejects requests with a timestamp more than 5 minutes away from its own clock, and nonces already seen within that window.
- Comparison uses `hash_equals()`.

This is per-request signing, not a bearer token like JWT. An intercepted request cannot be altered or reused.

### 5.6 Enrollment

1. The administrator adds a GCP project first, then picks instances from that project's list (section 8.1).
2. For each chosen instance, the server generates a one-time enrollment token **bound to that instance** (project, zone and instance name) and shows the exact commands to paste on the instance: install the keyring package, install the client and enroll.
3. On first contact, the client exchanges the token for its permanent HMAC secret. The token then becomes invalid.
4. Because tokens are bound to one instance, a client can never claim to be a different instance.

## 6. Checks

### 6.1 Client side (local)

- Disk used and total per filesystem (every heartbeat)
- Service state (for example `systemctl is-active`)
- Local HTTP health checks (request to a local endpoint with timeout, expecting success)
- Database health (a query such as `SELECT 1` with timeout)
- Local Let's Encrypt certificate expiry, read from a file published by the certificate scanner (section 6.1.1)

Low CPU usage is never a failure signal on its own.

#### 6.1.1 Certificate scanner

`/etc/letsencrypt` is often readable only by root, and it also holds private keys, so its permissions are never changed.

1. The client package installs a systemd timer that runs a small scanner **as root**. It reads each certificate's expiry date (public information) and writes only that to a file the client can read (for example `/var/lib/maguari-client/certificates.json`).
2. The scanner runs once during package installation (so existing certificates appear immediately) and daily after that.
3. The package also installs a certbot deploy hook that runs the scanner after each successful renewal. The timer is the main mechanism; the hook only makes renewals show up sooner.
4. The client itself stays unprivileged and never reads `/etc/letsencrypt`.

### 6.2 Server side (remote)

- Heartbeat age per instance
- HTTP checks of public web apps
- Remote TLS certificate expiry (checks the certificate actually served, catching a renewed certificate that was never reloaded)
- Instance status from the Compute Engine API

Certificate expiry is checked both locally and remotely.

### 6.3 Daily job

A scheduled daily job runs slow or daily-by-nature checks, with a "Run now" button in the dashboard:

- Certificate expiry (local and remote)
- Compute Engine disk size compared with the filesystem size reported by the client (detects a grown disk whose filesystem was never extended)

## 7. Remediation and safeguards

### 7.1 Escalation ladder

1. Restart the failing service through the client.
2. Retry the service restart once.
3. Reboot the OS through the client.
4. Reset the instance through the Compute Engine API.

Each step has its own wait period. Service restarts have their own limits, looser than instance resets.

### 7.2 Safeguards

1. **Consecutive failure threshold** before any action.
2. **Boot grace period** after a reset, during which failures are ignored.
3. **Cooldown** between resets.
4. **Maximum resets per 24 hours.** When reached, stop acting and email the administrator.
5. **Mass-failure circuit breaker.** If a large share of instances fail at once (and at least 2 instances), assume the problem is the server's own network. Take no action and send an alert.
6. **Instance status check** before resetting. Do not reset an instance that is `TERMINATED`, `STOPPING` or in maintenance.
7. **Self-exclusion.** The server never resets its own instance.
8. **Per-instance modes:** automatic, alert-only or maintenance (snoozed).
9. **Global dry-run mode** for tuning thresholds.

### 7.3 Parameters

Configurable per instance, with global defaults. These values are easy to change later.

| Setting | Hard range | Soft range (slider) | Default |
|---|---|---|---|
| Consecutive failures before action | 2 to 60 | 2 to 10 | 3 |
| Boot grace period | 1 to 30 min | 1 to 15 min | 5 min (7 min for the GeoNode instance) |
| Instance reset cooldown | 5 to 1,440 min | 5 to 120 min | 10 min |
| Max instance resets per 24 h | 1 to 10 | 1 to 6 | 3 |
| Mass-failure circuit breaker | 20% to 100% (min 2 instances) | 30% to 100% | 50% |
| Service restart cooldown | 1 to 60 min | 2 to 30 min | 5 min |
| Max service restarts per hour | 1 to 20 | 1 to 10 | 3 |
| Heartbeat interval | 15 to 900 s | 30 to 300 s | 60 s |
| Real-time mode interval | 5 to 60 s | 5 to 30 s | 10 s |
| Real-time mode duration | 1 to 60 min | 1 to 15 min | 10 min |
| Command expiry | 1 to 60 min | 1 to 30 min | 5 min |

Two tiers of limits:

1. **Soft limits** define the slider range. A value typed outside them in the number input saves with a warning.
2. **Hard limits** and **coherence rules** are always enforced, including in the number input. Values breaking them are rejected.

Coherence rules (rejected):

- Instance reset cooldown must be greater than or equal to the boot grace period.
- Service restart cooldown must be less than or equal to the instance reset cooldown (otherwise the escalation ladder stalls).

Coherence rules (warning only):

- Max resets per 24 h combined with the cooldown cannot fit in 24 hours (for example 10 resets with a 180-minute cooldown). Not dangerous, just ineffective.

### 7.4 Incidents

1. An incident opens when a check on an instance fails and no incident is already open for that instance.
2. Everything that happens during the problem belongs to the incident: failed checks, actions taken, alerts sent and notifications shown.
3. Safeguard counters that describe "one ongoing problem" (consecutive failures, escalation step) live in the incident.
4. The incident resolves when the failing checks pass again. Resolving it also resolves its notification.

## 8. GCP integration

1. The server calls the **Compute Engine REST API** directly. The gcloud CLI is not used by the application.
2. Authentication uses the server instance's attached service account, with access tokens from the metadata server. No key files.
3. The server instance must have an access scope that allows Compute Engine API calls (for example the `cloud-platform` scope). The default scopes on a new instance do not include Compute Engine write access.
4. In each monitored project, the service account gets a **custom role** with exactly:
   - `compute.instances.list`
   - `compute.instances.get`
   - `compute.instances.reset`
   - `compute.disks.get`

### 8.1 Administrator workflow

1. **Add a project:** enter the project ID. The server verifies it can list instances there (this confirms the custom role is granted).
2. **Pick instances:** choose from the list fetched from that project.
3. **Enroll each instance:** follow the commands shown for that instance (section 5.6).
4. **Configure checks and safeguards** per instance.

## 9. Storage

SQLite, stored outside the web root with restrictive file permissions: `/var/lib/maguari/maguari.sqlite`, mode 0600, in a directory with mode 0700. The `MAGUARI_DATABASE` environment variable overrides the path for development and tests only. SQLite runs in WAL mode with a 5 second busy timeout and foreign keys on. The web app never creates the database: while it is missing or not fully migrated, `/admin/*` and `/auth/*` return `503`.

### 9.1 Readings as runs

Readings are stored as **runs** instead of individual points (run-length storage, similar to "report by exception" in industrial historians):

```
monitoring_metric_runs(id, instance_id, metric, value, start_at, end_at)
```

1. When a reading equals the current run's value, update `end_at`.
2. When it differs, insert a new run.
3. When the gap since `end_at` exceeds 1.5 times the expected interval, insert a new run even if the value is unchanged. This keeps outages visible instead of hiding them inside a flat line.
4. No deletes are needed.

Implications:

- Charts must use step rendering, not linear interpolation.
- Averages are time-weighted.

All timestamps are stored in UTC, as integer Unix seconds, in columns whose names end in `_at` (for example `created_at`, `expires_at`).

### 9.2 Other tables

- Projects and instances
- Events: alerts, remediation actions, commands and their results
- Audit log: logins, configuration changes, manual actions
- Settings and secrets

### 9.3 Secrets at rest

Secrets that the server must use in their original form cannot be hashed: the SMTP password, the OAuth client secret and every client's HMAC secret. These columns are **encrypted** with PHP's built-in sodium extension (`sodium_crypto_secretbox`), using a key stored in a separate file readable only by the app user (for example `/etc/maguari/secret.key`, mode 0600).

This protects against leaks of the database file alone (a stray backup, a shared disk snapshot, a copied file). It does not protect against a full server compromise, where both files can be read.

Password hashes (local login during setup) use Argon2id.

## 10. Web application

### 10.1 Stack

- nginx with php-fpm
- Slim 4 with `slim/csrf`
- SQLite
- uPlot for charts (runs in the browser, drawing JSON returned by the server)
- systemd timers for scheduled work (check evaluation, daily job)

### 10.2 Surfaces and middleware

Routes are grouped by surface. Each group has its own middleware chain, so new routes inherit protection automatically.

| Group | Middleware |
|---|---|
| `/admin/*` | Session check, CSRF check |
| `/api/client/*` | HMAC verification, per-client rate limit |
| `/auth/*` | IP rate limiting on form submissions; session and CSRF check on forms (setup, local login) |

Security headers are set on every response, whatever the group. CSRF applies to the forms under `/auth/*` only: the future OAuth callback is protected by the OAuth `state` parameter instead.

A stolen client secret cannot open the admin UI, and an admin session cannot impersonate a client.

### 10.3 UI requirements

Settings sliders (section 7.3):

1. Each slider shows its current value in a number input next to it. Advanced administrators can type values directly.
2. Each row has a reset-to-default button.
3. A reset-all-to-defaults button resets every slider.
4. Each slider has an (i) icon with a tooltip explaining the setting with examples.
5. Out-of-soft-range values warn. Hard limit or coherence violations are rejected. No separate advanced screen.

Seed config file warning (section 11.5):

1. A pop-up with "Close" (hides it until the next login) and "Don't show again".
2. A persistent notice on the Logs screen that stays until the file is actually removed.
3. The warning condition is the file's existence (and unsafe permissions), checked on start and by the daily job.

Egress indicator:

1. Shows this month's outbound traffic against the free tier's 1 GB (section 14).
2. Measured from the network interface's transmit counters (`/proc/net/dev`), accumulated and reset monthly. This slightly overestimates (it counts all outbound traffic), which errs on the safe side.
3. Emails the administrator when usage crosses 80% of the monthly allowance.

### 10.4 Notifications

Alerts (emails) are not the only way problems surface. Notifications show them inside the web app.

1. **Sources:** every incident creates a notification. System conditions also create notifications: the seed config file still present (section 11.5) and egress above 80% (section 10.3).
2. **Dashboard (main screen):** a notification stays visible until the administrator hides it or its incident is resolved (or its system condition clears).
3. **Logs screen:** every notification always appears there, hidden or not, resolved or not.

## 11. Security

### 11.1 Admin login

1. **Sign in with Google** (OAuth) is the primary login. The OAuth consent screen is "External" because administrators belong to more than one Google Workspace organization.
2. Access is decided by an **email allowlist** in settings. The app also requires the email to be verified.
3. The local password login exists only during initial setup, or for installs that never configure Google. It is **disabled once Google sign-in is configured**.
4. Recovery is a command run over SSH on the server (for example temporarily re-enabling local login or issuing a new one-time setup token). SSH access acts as the second factor, so there is no TOTP.

### 11.2 Initial setup

1. Installation happens over SSH (section 12.2).
2. HTTPS must work **before** any setup token exists, so the token never travels over plain HTTP.
3. The setup command prints a URL containing a random **one-time setup token**. There are no default credentials. The token:
   - is valid for 1 hour;
   - is stored only as a SHA-256 hash;
   - replaces any previous token when a new one is issued, so only one exists at a time;
   - can only be issued while no administrator exists. Once one exists, the command refuses and `/auth/setup` returns 404.
   The URL must use HTTPS. Plain HTTP is accepted only for `localhost`, for local development. `maguari-server issue-setup-token` (section 12.2.1) does this part on its own; `setup` runs it after certbot succeeds.
4. The setup wizard creates the local admin account and optionally configures Google OAuth and the email allowlist.
5. The setup token is invalidated when setup finishes. The wizard then signs the new administrator in.
6. The wizard prefills the administrator's name and email from the seed config file when it is present and valid (section 11.5.1).

### 11.3 Hardening

- Rate limiting on login, setup and OAuth callback routes: at most 10 failed login or setup submissions per IP address in 15 minutes, then `429`. The IP is `REMOTE_ADDR`, which is the real client IP because Cloudflare runs in DNS-only mode (section 11.4).
- fail2ban on nginx logs
- Session cookies with `HttpOnly`, `Secure` and `SameSite=Lax` (`Strict` would break the OAuth redirect)
- Sessions are PHP's native file sessions, with PHP's own cookie handling off: the app reads and sets the cookie itself. Strict mode is on, so an unknown session ID is never adopted. Sessions live in a dedicated directory (`/var/lib/maguari/sessions`, mode 0700) with `session.gc_maxlifetime` matching the absolute timeout. Ubuntu turns PHP's session garbage collection off and cleans only the default save paths from cron, so the app turns it back on for its own directory.
- Session timeouts: 2 hours idle and 12 hours absolute, enforced by the app from timestamps stored in the session
- Local passwords: 12 to 1,024 characters, no composition rules, hashed with Argon2id
- CSRF tokens on every state-changing request
- No state-changing GET routes
- Security headers: `Content-Security-Policy`, `Strict-Transport-Security`, `frame-ancestors`, `Referrer-Policy: no-referrer` (keeps the setup token out of `Referer` headers)
- Session ID regeneration at login and at setup completion
- Secrets encrypted at rest (section 9.3)

### 11.4 Cloudflare DNS-only

The server's domain uses Cloudflare in **DNS-only** mode (grey cloud), permanently. Clients and administrators connect directly to the instance.

1. certbot's HTTP challenge works normally for issuance and every renewal.
2. fail2ban and IP rate limiting see real client IPs.
3. Trade-off: the instance's IP is public and there is no Cloudflare DDoS protection. The hardening in section 11.3 covers the realistic threats for this app.

Running behind the Cloudflare proxy is not supported for now. If added later as an advanced option, it would require certbot's Cloudflare DNS plugin (or an origin certificate) and rate limiting based on the `CF-Connecting-IP` header, trusted only from Cloudflare's published IP ranges.

### 11.5 Seed config file

1. An optional config file is read **once**, on first start, to seed settings. After that, the web app owns all settings.
2. Reading it again requires a full application reset, which is out of scope until after 1.0.
3. Because the file contains secrets, the app warns while it still exists (section 10.3).

#### 11.5.1 Format

1. Path: `/etc/maguari/seed.ini`. INI, parsed with `parse_ini_file($path, true, INI_SCANNER_TYPED)`. A fully commented example ships at `server/config/seed.ini.example`.
2. Every value must be quoted in double quotes. With `INI_SCANNER_TYPED`, an unquoted `yes`, `no`, `true`, `false`, `on`, `off`, `none` or `null` is read as a boolean or `null` instead of text, which fails validation. The example file documents this.
3. `[administrator]` section, required: `name` (non-empty string) and `email` (a valid email address, the alert recipient).
4. `[access]` section, optional: `allowlist[]`, repeated for multiple addresses. Defaults to `[administratorEmail]` when omitted or empty. Every entry is lowercased and the list deduplicated, so `Jane@Example.com` and `jane@example.com` count as one entry. The administrator email is lowercased the same way.
5. No password field. Local credentials are created only by the setup wizard (section 11.2), not seeded.
6. **Absent versus invalid:** a missing file means nothing to seed, not an error. A present but invalid file (unparsable INI, or a missing or malformed required field) is an error.
7. **Applying values is deferred.** Reading and validating the file works from MVP step 1 (section 15) with no database. Actually applying the seeded values, and marking seeding as done, is still deferred to a later step, although SQLite exists since MVP step 3. Until then, the setup wizard only uses the file to prefill the administrator's name and email (section 11.2).
8. If secret fields are ever added to this file, any CLI output that prints the parsed file must mask them.

## 12. Packaging, distribution and updates

### 12.1 Packages

| Package | Architecture | Key dependencies |
|---|---|---|
| `maguari-server` | all | `php-fpm`, `php-sqlite3`, `nginx`, `certbot`, `python3-certbot-nginx` |
| `maguari-client` | all | `php-cli` |
| `maguari-updater` | all | (none beyond base Ubuntu) |
| `maguari-archive-keyring` | all | (none) |

The client package ships its systemd service and the certificate scanner timer and deploy hook (section 6.1.1), creates its system user and generates its sudoers file (section 5.4).

### 12.2 Server installation

The server instance must be dedicated to Maguari. nginx needs ports 80 and 443, so another web server (for example Apache) on the same instance would conflict. The post-install step checks both ports and stops with a clear message if they are taken.

Installation runs in two steps because HTTPS must work before setup (section 11.2):

1. **Install:** `apt install maguari-server`. The package creates the system user, data directory, secrets key file and systemd timers, and configures nginx for HTTP only (enough for certificate issuance). It then prints the next step.
2. **Set up:** `sudo maguari-server setup --domain <domain> --email <email>`. This runs certbot non-interactively for the domain, verifies HTTPS works and only then prints the one-time setup URL.

Prerequisites for step 2: the domain's DNS record points at the instance in DNS-only mode (section 11.4), and the instance's firewall allows ports 80 and 443.

#### 12.2.1 CLI subcommands

`maguari-server` is a small CLI dispatcher. Subcommands so far:

| Subcommand | Purpose |
|---|---|
| `setup --domain <domain> --email <email>` | Runs certbot, verifies HTTPS, prints the one-time setup URL (above) |
| `check-seed-config [--path=/etc/maguari/seed.ini]` | Reads and validates the seed config file (section 11.5.1) and prints what would be seeded, without applying anything. `--path` is a testing convenience, not a production option. |
| `migrate` | Creates the database if needed and applies pending migrations (section 3.1) |
| `issue-setup-token --base-url=<url>` | Migrates the database, issues a one-time setup token and prints the setup URL (section 11.2). Refuses once an administrator exists. |

### 12.3 APT repository

Hosted on GitHub Pages, signed with a GPG key that lives only on the developer machine (later in GitHub Actions secrets). The key is never stored on the Maguari server.

### 12.4 Release phases

1. **MVP:** a local script builds the `.deb` files. Install with `apt install ./file.deb`.
2. **Before 1.0:** the local script also publishes the signed APT repository to GitHub Pages.
3. **Around 1.0:** GitHub Actions takes over building, signing and publishing. Workflows are filtered by path so unrelated changes never trigger a client release.

### 12.5 Updates

1. The server decides when each instance upgrades. The heartbeat response includes `upgrade: {version}`.
2. The client writes the target version into a request file (for example `/var/lib/maguari/upgrade-request`). This is the client's only involvement.
3. A **systemd path unit** watches that file and starts the updater service, which runs as root. The client needs no extra privileges.
4. The updater validates the version string, then installs that exact version with apt. apt verifies the repository signature.
5. After upgrading, the updater waits for the new client to complete a successful heartbeat. If it does not within a set time, the updater installs the previous version.
6. Rollout starts with a canary instance.
7. The APT repository keeps the last few versions so rollback is always possible.
8. `unattended-upgrades` must not upgrade Maguari packages on its own. By default it only handles security updates from official archives, so no configuration change should be needed.
9. The updater is separate from the client and changes rarely, so a broken client release cannot break updates.

## 13. Email

SMTP on port 587 through a relay (Google Workspace SMTP relay or Gmail SMTP). Compute Engine blocks outbound port 25.

## 14. Infrastructure

1. The server runs on a free tier e2-micro (regions `us-west1`, `us-central1` or `us-east1`) with a standard persistent disk up to 30 GB.
2. The free tier does not charge for the external IP address. The IP is ephemeral, and clients reach the server by domain through Cloudflare DNS (DNS-only mode).
3. The free tier includes only 1 GB of egress from North America per month. Keep heartbeat responses minimal and watch the egress indicator (section 10.3). Estimate: about 430 MB per month for 10 instances at one heartbeat per minute, before dashboard use and real-time mode.

## 15. MVP scope

Implement in this order, one step at a time:

1. Read the seed config file with administrator info.
2. Run the website (Slim app skeleton with the three route groups).
3. Setup and local login: one-time setup token, setup wizard creating the local administrator (Argon2id), sessions and CSRF; introduces SQLite.
4. Add one GCP project.
5. List the project's instances from the API.
6. Receive client heartbeats and show each instance's heartbeat status.
7. Receive disk used and total every minute.
8. Daily scheduled job with a "Run now" button.
9. Send a test email to the administrator.
10. Store every reading as runs (section 9.1).
11. Read stored runs through an API endpoint for future charts.

First steps after the MVP: the egress indicator (section 10.3) and Google sign-in.

## 16. Open questions

None at the moment. New questions go here as they come up.

## 17. Roadmap after 1.0

1. Uninstall support.
2. Full application reset (which would also allow reading the seed config file again).
3. Move releases fully to GitHub Actions.
