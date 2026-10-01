# MVP Step 6: Receive client heartbeats and show each instance's heartbeat status

## Context

Step 5 (`docs/plans/mvp-step-5.md`) lists a project's instances live from the Compute Engine API on `/admin/projects/{id}`. Nothing about instances is stored. `/api/client/heartbeat` exists only as a placeholder behind `FailClosedMiddleware`, which rejects every request with `401`. Errors anywhere in the app, `/api/client/*` included, are rendered by `ErrorPageRenderer` as HTML (or by Slim's JSON renderer when the request sends `Accept: application/json`).

Step 6 is: "Receive client heartbeats and show each instance's heartbeat status" (design section 15). To get there it needs:

1. **Picking instances** (design 8.1 item 2), deferred from step 5 to come with enrollment. Fleet's first stored instances.
2. **Enrollment** (design 5.6 and 8.1 item 3): one-time tokens bound to one instance, exchanged for a permanent HMAC secret. The **Clients** context's first code.
3. **Secrets at rest** (design 9.3): the HMAC secret is the first secret the server must keep in its original form, so the sodium key file and its encryption helper arrive now.
4. **HMAC signing with replay protection** (design 5.5), plus nonce storage and cleanup and the per-client rate limit from design 10.2.
5. **Machine-readable errors** under `/api/client/*` instead of the HTML error pages.
6. **The first code in `shared/`** (protocol constants and the signing code) and **in `client/`** (enroll and send heartbeats).
7. **Heartbeat status in the web app.**

Relevant design sections: 2.1 (contexts and the rules between them), 3 and 3.1 (layout), 4 (versioning and protocol version), 5.2 (heartbeat), 5.4 (client privileges), 5.5 (HMAC), 5.6 (enrollment), 8 and 8.1 (GCP, administrator workflow), 9 and 9.3 (storage, secrets at rest), 10.2 (surfaces and middleware), 11.3 (hardening) and 12.2.1 (CLI subcommands).

## Is this too large for one step?

Yes. It touches four contexts or components (Fleet, Clients, `shared/`, `client/`), adds three security mechanisms (enrollment tokens, encryption at rest, HMAC with replay protection) and a new error surface. As one commit it would be hard to review and hard to bisect.

**Proposed split**, in this order. Each sub-step is one commit, passes both test suites and leaves the app working:

| Sub-step | Delivers | Depends on |
|---|---|---|
| **6.1** Pick instances and issue enrollment tokens | Admin side only: `fleet_instances`, an "Enroll" button per listed instance, a one-time token and the command to run | Step 5 |
| **6.2** Client API foundations and enrollment exchange | JSON errors under `/api/client/*`, the secret key file and sodium encryption, `shared/` protocol constants, `POST /api/client/enroll` | 6.1 |
| **6.3** Signed heartbeats and heartbeat status | HMAC signing in `shared/`, verification middleware with nonces and per-client rate limit, `POST /api/client/heartbeat`, heartbeat status on the dashboard and project page | 6.2 |
| **6.4** The client | `client/`: `enroll`, `heartbeat` and `run` commands, end-to-end test against the real server app | 6.3 |

Why this order:

- 6.1 needs no client-facing code and can be tried in the browser right away.
- 6.2 before 6.3 because HMAC verification needs stored, encrypted client secrets, which only enrollment creates.
- 6.4 last because the client is the consumer of everything above. Splitting server and client keeps each commit reviewable, and the server side is fully testable with `shared/` code alone.

I would stop after each sub-step for your review before starting the next.

An alternative is three sub-steps, merging 6.2 into 6.3. It saves one round of review but makes 6.3 the biggest and most security-sensitive commit of the project so far. Not recommended.

## Interpretations (confirmed)

**I1. "Heartbeat status" is last contact, not the heartbeat-age check.** Design 6.2 lists "heartbeat age per instance" as a server-side **check**, which belongs to Monitoring and would open incidents. This step only **shows** when each enrolled instance last sent a valid heartbeat and whether that is recent. It stores the time in Clients (protocol-level metadata about the client) and creates no Monitoring code, no check results and no incidents. The heartbeat-age check comes with checks and incidents later and will read the time through `ClientsApi`.

**I2. Picking and issuing a token are one action.** The only reason to pick an instance is to enroll it (step 5, scope reading A). So the project page has an "Enroll" button per instance; pressing it stores the instance (if not already stored) and issues its token. There is no separate "pick" without a token.

**I3. One instance at a time.** Each "Enroll" press shows the command for that one instance. No multi-select.

**I4. Installation commands come with packaging.** Design 5.6 item 2 says the page shows commands to install the keyring package, install the client and enroll. There are no packages yet (packaging is not an MVP step). In this step the page shows only the `enroll` command, plus a sentence saying the client is run from a source checkout for now. The install lines are added when `client/debian/` exists. Showing `apt install` lines for packages that do not exist would mislead.

**I5. End-to-end verification is local.** The development server runs on `http://localhost` and is not reachable from GCE instances, and the client is not packaged. So in 6.4 the client runs on the development machine against the local server, using a token issued for a real listed instance. The binding to that instance is real on the server side; the client simply is not running on it. A real instance is first enrolled when packaging and a deployed server exist.

## Decisions (confirmed)

All interpretations and decisions were approved as proposed, with three additions recorded in D10, D20 and "Out of scope": `create-secret-key` refuses root and creates the key with mode 0600; the client's stored secret is a file readable only by the client's own user; nginx-level rate limiting for `/api/client/*` belongs to packaging.

### Fleet: picking (6.1)

**D1. `fleet_instances` table.**

```
fleet_instances(
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES fleet_projects(id),
    gcp_instance_id TEXT NOT NULL,
    zone TEXT NOT NULL,
    name TEXT NOT NULL,
    picked_at INTEGER NOT NULL,
    UNIQUE (project_id, zone, name)
)
```

- Keyed on project, zone and name, which is exactly what design 5.6 item 2 binds a token to. `gcp_instance_id` is stored (as text, step 5 D4) and refreshed when the instance is picked again, so an instance deleted and recreated with the same name updates the row instead of creating a second one.
- The foreign key stays inside Fleet. Other contexts refer to `fleet_instances.id` without a foreign key (design 2.1 rules 2 and 3).
- Removing or renaming instances is out of scope.

**D2. The pick is confirmed with `instances.get`.** The form posts `zone` and `name`. The server validates both against GCP's formats (zone `^[a-z]+-[a-z]+[0-9]+-[a-z]$`, name `^[a-z]([-a-z0-9]{0,61}[a-z0-9])?$`) before they go anywhere near a URL, then calls

```
GET https://compute.googleapis.com/compute/v1/projects/{project}/zones/{zone}/instances/{name}
```

which needs `compute.instances.get`, already in the custom role (design 8 item 4). This gives the authoritative `gcp_instance_id` and refuses instances that do not exist, instead of trusting form data. A `404` shows "This instance was not found in the project." Other failures reuse the step 4 sentences through the same error mapping in `ComputeEngine`.

**D3. `FleetApi` additions.**

- `pickInstance(int $projectId, string $zone, string $name): Instance`
- `instance(int $instanceId): Instance`
- `pickedInstances(?int $projectId = null): Instance[]` for the dashboard and the project page

`Instance` is a new value object (`id`, `projectId`, `gcpProjectId`, `gcpInstanceId`, `zone`, `name`, `pickedAt`). `DiscoveredInstance` stays as it is for the live list.

### Clients: enrollment tokens (6.1)

**D4. `clients_enrollment_tokens` table.**

```
clients_enrollment_tokens(
    token_hash TEXT PRIMARY KEY,
    instance_id INTEGER NOT NULL UNIQUE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
)
```

- 32 random bytes, base64url, shown once. Only the SHA-256 hash is stored, as with setup tokens.
- **Lifetime: 1 hour**, the same as the setup token (design 11.2). The administrator issues it and runs it on the instance right away. Pressing "Enroll" again replaces the instance's previous token (the `UNIQUE` on `instance_id`), so a lost token is never a problem. Expired rows are deleted whenever a token is issued.
- Issuing a token for an instance that is already enrolled is allowed (for example after rebuilding the instance). The existing client keeps working until the new token is used (D9).

**D5. Showing the token.** `POST /admin/projects/{id}/instances` (fields `zone`, `name`, CSRF) picks the instance, issues the token and responds `200` with a page showing the command, instead of redirecting. Redirecting would need the plain token stored somewhere to show it after the redirect. The page is sent with `Cache-Control: no-store`. Reloading asks the browser to resubmit, which just issues a fresh token.

The command shown (I4):

```
maguari-client enroll --server=https://maguari.example.com --token=<token>
```

The server URL comes from configuration (D22), never from the request: 6.1 first used the request's scheme, host and port, and 6.2 replaces that. The token on the command line ends up in the shell history and is briefly visible in the process list. That is acceptable because it is single-use, expires in an hour, is bound to one instance and is consumed within a second; after use it is worthless.

**D6. `ClientsApi`** is the Clients context's public interface. In 6.1 it gains `issueEnrollmentToken(int $instanceId): IssuedEnrollmentToken` and `enrollmentStates(int[] $instanceIds)` (no token, token pending, enrolled), used by the project page. The controller calls `FleetApi::pickInstance()` then `ClientsApi::issueEnrollmentToken()`. That is the only cross-context orchestration in the step, and it is two calls, so it stays in the controller rather than making Clients depend on Fleet.

### Machine-readable errors under `/api/client/*` (6.2)

**D7. Every response under `/api/client/*` is JSON**, whatever the `Accept` header, including routing errors and uncaught exceptions. Body shape:

```
{"error": "<code>"}
```

with `Content-Type: application/json` and `Cache-Control: no-store`. Codes are fixed strings the client can act on. Messages for humans are not sent: the client logs its own sentence per code, and error details never leave the server (same rule as the HTML pages).

| Status | Code | When |
|---|---|---|
| 400 | `bad_request` | Malformed JSON, missing or wrongly typed fields, a query string on a signed route (D13), `client_id` in the body not matching the header |
| 400 | `unsupported_protocol` | `protocol_version` not supported (design 4 item 5) |
| 401 | `unauthorized` | Missing or malformed signature headers, unknown client or wrong signature (not distinguished) |
| 401 | `clock_skew` | Timestamp more than 5 minutes from the server clock. The body also carries `"server_time"` (Unix seconds), so the client can log how far off it is. |
| 401 | `replayed_request` | Nonce already seen within the window |
| 401 | `invalid_token` | Enrollment token unknown, used or expired (not distinguished) |
| 404 | `not_found` | Unknown route under `/api/client/` |
| 405 | `method_not_allowed` | For example `GET /api/client/heartbeat` |
| 413 | `payload_too_large` | Body over 64 KiB |
| 429 | `rate_limited` | Per-client limit reached (D15), with `Retry-After` |
| 500 | `server_error` | Anything uncaught |
| 503 | `unavailable` | Database missing or not migrated; secret key file missing (D10) |

**How:** Slim's `ErrorMiddleware` is app-wide and picks a renderer by `Accept`, which is the wrong criterion here. `App` registers one small error handler (`Http/SurfaceErrorHandler`) as both the default and the routine (404 and 405) handler. It looks at the request path and delegates to a Slim `ErrorHandler` configured with `forceContentType('application/json')` and Maguari's `ClientApiErrorRenderer` for paths under `/api/client`, and to the existing HTML handlers otherwise. Logging rules stay as they are: 404 and 405 are not logged, everything else is. Middleware rejections (401, 429 and so on) are built directly by the middleware through one shared helper (`Http/ClientApiResponse::error()`), so the format exists in one place.

`FailClosedMiddleware` is deleted in 6.3, when real verification replaces it. Until then it answers in the JSON format.

### Secrets at rest (6.2)

**D8. Sodium encryption in Kernel.** `Kernel/Secrets/SecretBox` encrypts with `sodium_crypto_secretbox` (design 9.3). Stored form: 24-byte random nonce followed by the ciphertext, in a `BLOB` column. Design 3.1 already lists "secrets encryption" as a Kernel helper.

**D9. Key file.** 32 raw bytes at `/etc/maguari/secret.key`, mode 0600, readable only by the app user. `MAGUARI_SECRET_KEY_FILE` overrides the path for development and tests only, like `MAGUARI_DATABASE`. A file that is not exactly 32 bytes is an error, and so is one that group or others can access (added during 6.2, matching the client's rule for its credentials file in D20).

**D10. Who creates the key.** A new subcommand, `maguari-server create-secret-key`, creates the file with mode 0600 if it does not exist and **refuses to overwrite** an existing one, because a new key makes every stored client secret unreadable (every instance would have to enroll again). **It refuses to run as root, like `migrate`**, so the key file is never owned by root and the app user can read it. The file is **created with mode 0600** (under a `0077` umask, so it is never readable by others even for a moment) and its directory with mode 0700 if missing. Packaging will later create the file in its post-install step (design 12.2 item 1) and may revisit how.

The web app treats a missing or unreadable key like a database that is not ready: `/admin/*` and `/auth/*` return `503` with a message naming the command, and `/api/client/*` returns `503 unavailable`. One readiness rule is simpler than a half-working app. Existing development databases need one extra command, which the verification section lists.

*Alternative:* have `migrate` create the key. Fewer commands, but `migrate` would then also manage a file in `/etc`, and in production the app user cannot write there. Not recommended.

### Enrollment exchange (6.2)

**D11. `clients_clients` table.** The prefix rule applied literally to the glossary term "client."

```
clients_clients(
    client_id TEXT PRIMARY KEY,
    instance_id INTEGER NOT NULL UNIQUE,
    secret_ciphertext BLOB NOT NULL,
    enrolled_at INTEGER NOT NULL,
    last_heartbeat_at INTEGER,
    client_version TEXT,
    protocol_version INTEGER
)
```

- `client_id`: 16 random bytes as 32 lowercase hex characters, generated by the server. Random rather than the row number, so IDs reveal nothing about fleet size. It is an identifier, not a secret.
- The HMAC secret: 32 random bytes, encrypted with D8.
- `instance_id` is `fleet_instances.id`, with no foreign key (D1).

**D12. `POST /api/client/enroll`.** Not signed (the client has no secret yet); the enrollment token is the credential. Request body:

```
{"protocol_version": 1, "client_version": "0.1.0", "token": "<token>"}
```

The token travels in the body, never in the URL, so it stays out of access logs. Inside one `BEGIN IMMEDIATE` transaction: look up the token by hash, require it unexpired, delete it, delete any existing client for that instance (re-enrollment, D4; its nonces go with it) and insert the new client. Response `200`:

```
{"client_id": "<32 hex>", "secret": "<base64url of 32 bytes>"}
```

with `Cache-Control: no-store`. Because the instance comes from the token, a client can never choose which instance it claims to be (design 5.6 item 4).

The enroll route sits in `/api/client` but outside the HMAC middleware, in its own inner group. Design 10.2 is updated to say so. No IP rate limit on it in this step: the token has 256 bits, so guessing is infeasible, and fail2ban (design 11.3) will cover abuse later. Say if you want a per-IP limit like `/auth/*` anyway.

### HMAC signing and replay protection (6.3)

**D13. The signature, exactly.** In `shared/src/Signature.php`, used by both sides:

```
canonical = METHOD + "\n" + path + "\n" + timestamp + "\n" + nonce + "\n" + hex(sha256(body))
signature = hex(hmac_sha256(secret, canonical))
```

| Header | Format |
|---|---|
| `X-Maguari-Client` | `client_id`, 32 lowercase hex |
| `X-Maguari-Timestamp` | Unix seconds, decimal digits only |
| `X-Maguari-Nonce` | 16 random bytes, 32 lowercase hex, new per request |
| `X-Maguari-Signature` | 64 lowercase hex |

- `METHOD` uppercase. `path` is the request path as sent, for example `/api/client/heartbeat`.
- Signed routes take no query string. A request with one is rejected with `bad_request`, because the query would not be covered by the signature.
- Comparison with `hash_equals()` (design 5.5).

**D14. Verification order** in `Http/Middleware/ClientSignatureMiddleware`, which delegates the logic to `ClientsApi::authenticate()`:

1. Body over 64 KiB: `payload_too_large`. Headers missing or malformed: `unauthorized`. A query string: `bad_request`. No database access yet.
2. Timestamp more than 300 seconds from the server clock in either direction: `clock_skew`. Still no database access.
3. Look up the client and verify the signature with its decrypted secret: `unauthorized` on any failure.
4. In one `BEGIN IMMEDIATE` transaction:
   1. Per-client rate limit (D15): `rate_limited`.
   2. Insert the nonce. A primary key conflict means a replay: `replayed_request`.
   3. Delete expired nonces (D16).
5. Pass the authenticated `client_id` to the route as a request attribute.

Nonces are recorded only after the signature is verified, so unauthenticated requests can never write to the database or fill the nonce table.

**D15. Per-client rate limit** (design 10.2): at most **20 requests per 60 seconds** per client, counted from the nonce table (every accepted request has exactly one nonce row and a server-side `received_at`). No extra table. The fastest legitimate rate is real-time mode at 5 seconds (12 per minute, design 7.3), so 20 leaves headroom. `Retry-After: 60`.

**D16. Where nonces are stored and how they are cleaned up.**

```
clients_nonces(
    client_id TEXT NOT NULL REFERENCES clients_clients(client_id) ON DELETE CASCADE,
    nonce TEXT NOT NULL,
    received_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    PRIMARY KEY (client_id, nonce)
) WITHOUT ROWID;
CREATE INDEX clients_nonces_expires_at ON clients_nonces (expires_at);
```

- **Storage:** SQLite, in the Clients context. php-fpm workers share no memory and APCu is not a dependency, so the database is the only store every worker sees. Nonces are scoped per client.
- **Expiry:** a request with timestamp `t` passes the clock check while `now <= t + 300`. Its nonce must be remembered exactly that long, so `expires_at = t + 300`. After that, a replay fails the clock check anyway.
- **Cleanup:** every accepted request runs `DELETE FROM clients_nonces WHERE expires_at < :now` inside the same transaction (D14 step 4). The index makes it cheap. No timer is needed, and the daily job (step 8) does not have to exist first. Deleting a client cascades to its nonces.
- **Size:** at most about 10 minutes of requests per client (a timestamp can be up to 5 minutes ahead), so about 10 rows per instance at the 60 second interval and about 120 in real-time mode. A few thousand rows for a large fleet.

### Heartbeat endpoint and status (6.3)

**D17. `POST /api/client/heartbeat`.** Body per design 5.2. In this step the server requires `protocol_version` (supported: `1` only), `client_version`, `client_id` (must equal the header) and `sent_at` (integer), and accepts `readings`, `checks` and `command_results` as arrays, which it ignores until step 7. It stores `last_heartbeat_at` (the **server's** clock, not `sent_at`), `client_version` and `protocol_version`, and answers `204 No Content` (design 5.2: empty body when there is nothing to send). The client treats `204` and an empty `200` the same.

**D18. Heartbeat status in the web app.**

- `ClientsApi::heartbeatStatuses(int[] $instanceIds)` returns, per instance: not enrolled, waiting for enrollment (token issued), enrolled with no heartbeat yet or the last heartbeat time.
- Status labels: **On time** when the last heartbeat is at most 90 seconds old (1.5 times the 60 second interval, the same factor as design 9.1 item 3), **Late** otherwise. Display only, computed when the page renders; nothing is alerted.
- **Dashboard (`/admin`):** a table of picked instances (project, instance, zone, status, last heartbeat as UTC time with age, client version). It reads only SQLite, so it works when Google's API does not.
- **Project page:** the live list gains a column showing the same status for picked instances and the "Enroll" button (D5) for every instance.
- No auto-refresh (no JavaScript yet). Reload to update.

### `shared/` and `client/` (6.2 to 6.4)

**D19. `shared/`.** Namespace `Maguari\Shared\`, in `shared/src/`, PHP 8.1, no dependencies and no I/O. Contents: `Protocol` (protocol version `1`, header names, the 5 minute window, the 60 second default interval, error codes) and `Signature` (D13). The server autoloads it through `composer.json` (`"Maguari\\Shared\\": "../shared/src/"`). Shared tests live in `shared/tests/`.

**D22. The server's address comes from configuration (added after 6.1 review).** The `Host` header is chosen by whoever sends the request, so it must never decide where an enroll command points.

- Access stores the server's base URL (scheme, host and optional port, no path) in a new `access_settings` table (`name` primary key, `value`, `updated_at`), and exposes it as `AccessApi::baseUrl(): ?string`.
- `issue-setup-token --base-url=<url>` records it, so the domain given at setup is the configured one (design 12.2: `setup` runs certbot for the domain, then this command).
- A new subcommand, `maguari-server set-base-url --base-url=<url>`, sets or changes it later: on installs set up before this step (where `issue-setup-token` now refuses, because an administrator exists), when the domain changes and in development (`--base-url=http://localhost:8080`). Like `migrate`, it refuses root.
- Both commands validate with the same rule: `https://`, or `http://` only when the host is exactly `localhost`; no user, password, path, query or fragment. The rule lives in `shared/src/ServerUrl.php`, so `issue-setup-token`, `set-base-url` and the client's `--server` (D20) run the same code.
- When no base URL is configured, pressing "Enroll" issues no token and makes no API call. The page says the server's address is not configured and shows the `set-base-url` command to run (status `409`).
- No environment variable: `set-base-url` already covers development, and one source of truth avoids precedence rules between a stored value and the environment.

**D20. `client/`.** Namespace `Maguari\Client\`, PHP 8.1 `php-cli` only, **no Composer dependencies** at runtime (design 12.1). A small autoloader in `client/src/autoload.php` maps `Maguari\Client\` and `Maguari\Shared\`. `client/VERSION` starts at `0.1.0`.

Commands (`client/bin/maguari-client`):

| Command | Does |
|---|---|
| `enroll --server=<url> --token=<token>` | Exchanges the token (D12) and writes the credentials file. On failure the existing file, if any, is left untouched. |
| `heartbeat` | Sends one heartbeat and exits `0` on `204` or `200`, non-zero otherwise. For manual checks. |
| `run` | Sends a heartbeat every 60 seconds on a fixed schedule (no drift), logs failures to stderr and keeps going. The systemd service will run this later. |

- **Credentials file:** `/var/lib/maguari-client/credentials.json`, holding the server URL, `client_id` and secret. **Readable only by the client's own user: mode 0600**, and its directory mode 0700 when the client creates it. The temporary file is created under a `0077` umask and chmodded to 0600 before the secret is written, then renamed into place, so the secret is never readable by anyone else, even briefly. `MAGUARI_CLIENT_DIR` overrides the directory for development and tests only. When loading, the client refuses a credentials file that is readable or writable by group or others, with a message saying how to fix the mode.
- **Server URL:** the client **refuses a `--server` that is not `https://`, unless the host is exactly `localhost`** (added after 6.1 review). This is the same rule as `issue-setup-token --base-url`, enforced by the same code (`Maguari\Shared\ServerUrl`, D22). Hostnames are case-insensitive, so `LOCALHOST` counts as `localhost`; `127.0.0.1`, `::1` and `localhost.example.com` do not.
- **HTTP:** PHP's stream wrapper with peer verification, a 10 second timeout and no redirects followed. This duplicates a little of the server's `StreamHttpClient` on purpose: the client package never contains server code (design 3).
- Refuses to run as root (design 5.4: the client runs as an unprivileged user).
- Readings, checks, command results, the certificate scanner, the allowlist and sudoers are all later steps. The heartbeat sends empty arrays.

**D21. Tests for `shared/` and `client/` run from the server's PHPUnit.** `server/phpunit.xml` gains `shared` and `client` test suites (`../shared/tests`, `../client/tests`), and `autoload-dev` maps their test namespaces. That keeps "run `vendor/bin/phpunit` in `server/`" as the single command from CLAUDE.md. `scripts/test-ubuntu-22.04.sh` copies `shared/` and `client/` into the container next to `server/`. Only the client's tests use PHPUnit; the client itself never loads anything from `server/vendor/`, which a test checks by running `client/bin/maguari-client` in a separate process.

*Alternative:* separate `composer.json` and PHPUnit setups in `client/` and `shared/`. Cleaner isolation, but three test commands to remember. Worth revisiting when GitHub Actions takes over (design 12.4).

## Out of scope for this step

- Readings, checks and command results in the heartbeat (step 7 and later). Commands, `report_interval` and `upgrade` in the response.
- The heartbeat-age **check**, incidents and alerts (I1).
- Packaging (`client/debian/`, systemd units, the `maguari-client` system user, sudoers, the certificate scanner). The keyring and install commands on the enroll page (I4).
- Verifying on enrollment that the client runs on the instance it was enrolled for (the client could send its instance ID from the metadata server). Useful against pasting the command on the wrong instance; it would not work on the development machine, so it waits for packaging.
- Signing server responses. HTTPS authenticates the server; worth revisiting before commands are delivered.
- Removing instances, revoking a client without re-enrolling, rotating the secret key.
- Settings for the heartbeat interval (fixed at 60 seconds for now).
- IP rate limiting on `/api/client/enroll` (D12).
- **nginx-level rate limiting for `/api/client/*`** (`limit_req` per IP, request body size limits). It belongs to packaging, together with the rest of the nginx configuration (design 12.2). The application-level limits in this step (D12, D14 and D15) do not depend on it.
- Any CSS or JavaScript.

## Repository changes

### 6.1 Pick instances and issue enrollment tokens

```
server/
  src/
    Fleet/
      FleetApi.php                          pickInstance(), instance(), pickedInstances() (D3)
      Instance.php                          value object (D3)
      InstanceRepository.php
      Exception/InstanceNotFound.php
      Exception/InvalidInstanceName.php     zone or name format (D2)
      Gcp/ComputeEngine.php                 getInstance() through the shared request and error mapping (D2)
      Migrations/0002_fleet_instances.sql
    Clients/
      ClientsApi.php                        issueEnrollmentToken(), enrollmentStates() (D6)
      EnrollmentTokens.php
      IssuedEnrollmentToken.php
      Migrations/0001_clients_enrollment_tokens.sql
    Http/
      App.php                               POST /admin/projects/{id}/instances; ClientsApi wiring
      Controller/ProjectsController.php     enroll(); enrollment column on the project page
  templates/
    project.php                             Enroll button and enrollment column
    enroll.php                              the token and command (D5)
  tests/
    Fleet/FleetApiTest.php, Fleet/Gcp/ComputeEngineTest.php
    Clients/EnrollmentTokensTest.php, Clients/ClientsApiTest.php
    Http/ProjectsFlowTest.php
docs/DESIGN.md
```

### 6.2 Client API foundations and enrollment exchange

```
shared/
  src/Protocol.php                          (D19)
  src/ErrorCode.php                         the error codes of D7
  src/ServerUrl.php                         https, or http only for localhost (D22)
  tests/ServerUrlTest.php
server/
  composer.json                             autoload Maguari\Shared\ (D19)
  phpunit.xml                               shared test suite (D21)
  bin/maguari-server                        create-secret-key (D10); set-base-url and ServerUrl (D22)
  src/
    Access/AccessApi.php                    baseUrl(), setBaseUrl() (D22)
    Access/Migrations/0004_access_settings.sql
    Http/Controller/ProjectsController.php  enroll command uses the configured base URL (D22)
    Kernel/Secrets/SecretBox.php            (D8)
    Kernel/Secrets/SecretKeyFile.php        read, create, MAGUARI_SECRET_KEY_FILE (D9, D10)
    Clients/
      ClientsApi.php                        enroll() (D12)
      ClientRepository.php
      EnrolledClient.php
      Exception/InvalidEnrollmentToken.php
      Exception/UnsupportedProtocol.php
      Migrations/0002_clients_clients.sql
    Http/
      App.php                               readiness includes the key file; /api/client/enroll; error handler wiring
      SurfaceErrorHandler.php               JSON or HTML by path (D7)
      ClientApiErrorRenderer.php            (D7)
      ClientApiResponse.php                 one place for the JSON error format (D7)
      Controller/EnrollController.php
      Middleware/FailClosedMiddleware.php   answers in JSON until 6.3 deletes it
  tests/
    Kernel/Secrets/SecretBoxTest.php, Kernel/Secrets/SecretKeyFileTest.php
    Clients/ClientsApiTest.php
    Http/ClientApiErrorsTest.php, Http/EnrollFlowTest.php, Http/AppTest.php
    Support/TestEnvironment.php             creates a key file
scripts/test-ubuntu-22.04.sh                copy shared/ into the container (D21)
docs/DESIGN.md
```

### 6.3 Signed heartbeats and heartbeat status

```
shared/
  src/Signature.php                         (D13)
  tests/SignatureTest.php                   fixed test vectors
server/
  src/
    Clients/
      ClientsApi.php                        authenticate(), recordHeartbeat(), heartbeatStatuses() (D14, D17, D18)
      Nonces.php                            insert, rate count, cleanup (D15, D16)
      Heartbeat.php                         validated request body (D17)
      HeartbeatStatus.php
      Exception/ClientRejected.php          carries the error code
      Migrations/0003_clients_nonces.sql
    Http/
      App.php                               signed inner group; FailClosedMiddleware removed
      Middleware/ClientSignatureMiddleware.php
      Middleware/FailClosedMiddleware.php   deleted
      Controller/HeartbeatController.php
      Controller/AdminController.php        dashboard table (D18)
  templates/
    admin.php, project.php                  heartbeat status (D18)
  tests/
    Clients/NoncesTest.php, Clients/ClientsApiTest.php
    Http/HeartbeatFlowTest.php, Http/AdminDashboardTest.php
    Support/SignedRequest.php               builds signed requests with shared/ Signature
docs/DESIGN.md
```

### 6.4 The client

```
client/
  VERSION                                   0.1.0
  .gitignore                                var/ (development credentials)
  bin/maguari-client
  src/
    autoload.php
    Cli.php                                 command dispatch, root refusal
    Credentials.php                         the credentials file (D20)
    ServerUrl.php                           https, or http for localhost
    Transport.php                           interface
    StreamTransport.php                     stream wrapper, timeouts, no redirects
    Enroller.php
    HeartbeatSender.php                     signs with shared/ Signature
    Runner.php                              fixed-schedule loop
  tests/
    ...                                     unit tests with a fake transport
    EndToEndTest.php                        the real client against the real Slim app (D21)
server/
  phpunit.xml, composer.json                client test suite (D21)
scripts/test-ubuntu-22.04.sh                copy client/ into the container
docs/DESIGN.md
```

## Flows

**Enroll an instance (6.1 and 6.2).**

1. The administrator opens `/admin/projects/{id}` and presses "Enroll" next to an instance.
2. `POST /admin/projects/{id}/instances` (session, CSRF, administrator checks). Zone and name are validated, `instances.get` confirms the instance, `fleet_instances` gets or updates its row.
3. `ClientsApi` issues a token for that `fleet_instances.id`, replacing any earlier one. The page shows the command once.
4. On the instance (in this step, the development machine, I5), `maguari-client enroll --server=... --token=...` posts the token to `/api/client/enroll`.
5. The server consumes the token, creates the client with an encrypted secret and returns `client_id` and secret. The client writes its credentials file.

**Send a heartbeat (6.3 and 6.4).**

1. The client builds the JSON body, a fresh nonce and the current timestamp, signs them (D13) and posts to `/api/client/heartbeat`.
2. The middleware verifies in the order of D14, records the nonce and cleans up expired ones.
3. The controller validates the body (D17) and `ClientsApi` stores the heartbeat time. Response `204`.
4. Any failure returns a JSON error (D7); the client logs a sentence for the code and tries again at the next interval.

## Tests

No test touches the network. Compute Engine answers are queued on `FakeHttpClient`; the client talks to the Slim app through a transport that dispatches requests in-process.

**6.1**
1. Picking: zone and name formats are validated before any API call; `instances.get` URL and bearer header; a `404` gives the instance-not-found sentence; other failures map to the step 4 sentences; picking twice keeps one row and refreshes `gcp_instance_id`.
2. Tokens: only the hash is stored; issuing again replaces the instance's token; expired tokens are deleted on issue; tokens are 43 base64url characters.
3. HTTP: the Enroll button requires sign-in and CSRF; the response shows the token and `Cache-Control: no-store`; the token never appears in any other page; an unknown project returns 404; the enrollment column shows "waiting for enrollment".

**6.2**
1. `SecretBox`: round trip; a different key or a modified ciphertext fails to decrypt; each encryption uses a new nonce.
2. Key file: created with mode 0600 and 32 bytes; never overwritten; a wrong length is an error; `create-secret-key` refuses root (checked in a separate process, like `migrate`).
3. Readiness: a missing key file makes `/admin`, `/auth` and `/api/client` return 503 (JSON for the last one).
4. JSON errors: under `/api/client/` an unknown route gives `404 not_found`, a wrong method `405 method_not_allowed` and a thrown exception `500 server_error`, all JSON whatever `Accept` says, with no exception message in the body. Outside `/api/client/` the HTML pages are unchanged. Logging behavior is unchanged.
5. Enrollment: a valid token returns `client_id` and secret and is consumed; a second use, an expired token and an unknown token all give `invalid_token`; re-enrolling replaces the instance's client; the stored secret is encrypted (the plain secret is not in the database file); an unsupported protocol version gives `unsupported_protocol`; malformed bodies give `bad_request`.

**6.3**
1. `Signature`: fixed test vectors, so server and client cannot drift apart silently.
2. Middleware, one case per row of D7 that applies: missing headers, bad formats, query string, unknown client, wrong signature, a body changed after signing, a path changed after signing, timestamps 301 seconds early and late (rejected) and 300 seconds (accepted), a replayed nonce, the same nonce from two different clients (both accepted), the 21st request in a minute, a body over 64 KiB.
3. Unauthenticated requests never write a nonce row.
4. Cleanup: expired nonces are deleted on the next accepted request and unexpired ones are kept; deleting a client deletes its nonces.
5. Heartbeat: `204` and `last_heartbeat_at` set from the server clock; `client_id` mismatch gives `bad_request`.
6. Status: not enrolled, waiting for enrollment, no heartbeat yet, on time at 90 seconds and late at 91 seconds (with `FixedClock`); the dashboard works with a failing fake Compute Engine.

**6.4**
1. Enroll writes the credentials file with mode 0600 (and creates its directory with mode 0700); a credentials file with group or other permission bits is refused when loading; enroll leaves an existing file untouched on failure; `http://` is refused except for `localhost`.
2. Heartbeat requests are signed correctly, checked by the server's verifier.
3. The runner keeps its schedule after a failed heartbeat and logs a sentence per error code.
4. End to end: issue a token through `ClientsApi`, enroll with the real client against the Slim app, send a heartbeat and see the status change to "On time".
5. `client/bin/maguari-client` runs without `server/vendor/` and refuses root.

## Changes to `docs/DESIGN.md`

Each sub-step updates the design in its own commit:

- **6.1:** section 8.1 item 2 (picking happens with enrollment, one instance at a time, confirmed with `instances.get`); section 5.6 (token lifetime 1 hour, one token per instance, issuing again replaces it, re-enrollment allowed; install commands come with packaging).
- **6.2:** section 5.6 item 3 (the exchange, `POST /api/client/enroll`, token in the body); section 9 (`MAGUARI_SECRET_KEY_FILE`, key file format, readiness includes the key); section 9.3 (stored form of encrypted secrets); section 10.2 (JSON error format and codes under `/api/client/*`; the enroll route is outside the HMAC middleware); section 12.2.1 (`create-secret-key`, `set-base-url`, and `issue-setup-token` recording the base URL); section 5.6 item 2 (the enroll command's server URL comes from configuration, D22).
- **6.3:** section 5.5 (header formats, no query strings, verification order, nonce storage and cleanup, the 64 KiB limit); section 10.2 (per-client rate limit of 20 requests per minute); section 5.2 (`204` for an empty response; the server stores its own receive time).
- **6.4:** section 3 (how `client/` loads `shared/`; tests run from the server's PHPUnit); section 5.4 (client refuses root); the client credentials file and `MAGUARI_CLIENT_DIR`.

## Verification

For each sub-step:

1. Run the normal suite from `server/`:
   ```
   vendor/bin/phpunit
   ```
2. Run the suite on Ubuntu 22.04 with PHP 8.1 from the repository root:
   ```
   scripts/test-ubuntu-22.04.sh
   ```

Manual check after 6.4, in development, from `server/`. Once, create the key file (needed from 6.2 on):

```
export MAGUARI_DATABASE="$PWD/var/dev.sqlite"
```

```
export MAGUARI_SECRET_KEY_FILE="$PWD/var/secret.key"
```

```
bin/maguari-server create-secret-key
```

```
bin/maguari-server migrate
```

```
bin/maguari-server set-base-url --base-url=http://localhost:8080
```

Then start the server with the same exports plus `MAGUARI_GCP_CREDENTIALS=application-default`:

```
php -S localhost:8080 -t public
```

Open a project page, press "Enroll" on an instance and copy the token. In a second terminal, from the repository root:

```
export MAGUARI_CLIENT_DIR="$PWD/client/var"
```

```
client/bin/maguari-client enroll --server=http://localhost:8080 --token=<token>
```

```
client/bin/maguari-client run
```

Reload `/admin`: the instance shows "On time". Stop the client, wait two minutes, reload: "Late". Run the same `enroll` command again: `invalid_token`.

## Answers

All five recommendations were approved (split and order, I1 to I5, D10, D4, D11, D12), with the three additions above. Implementation starts with 6.1 and stops for review after it.

After the 6.1 review, two more additions: the enroll command's server address comes from configuration (D22), and the client refuses a non-HTTPS `--server` unless the host is exactly `localhost` (D20).
