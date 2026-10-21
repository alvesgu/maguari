# Phase 2: More checks

## Context

Phase 1 is finished: every check outcome change is an event, Remediation turns failures into incidents and Notifications shows them and sends alert emails. Phase 2 of the road to 1.0 (design section 17) adds the checks that make the dashboard answer "are instances, databases and web apps responsive?" (design section 1, goal 1):

- **Remote HTTP checks** of public web apps, from the server (design section 6.2).
- **Service state, local HTTP and database checks**, on the client (design section 6.1).

Decided before this plan:

- **D1.** Client checks are defined in the web app, per instance, and sent to the client in heartbeat responses.
- **D2.** Database credentials stay in a file on the instance, readable only by the client's user, never sent to or stored on the server. The web app refers to a database by name ("check the database named geonode"); only the instance knows how to log in.
- **D3.** Check settings travel to a client only when they change, so heartbeat responses stay `204` normally.
- **D4.** The server strictly validates what it may ask a client to check: a service name must be a valid systemd unit name and a local URL must point at the instance itself.
- **D5.** Every new check feeds Phase 1's incidents through the existing events.
- **D6.** Remote HTTP checks cost egress, so each has an interval with a sensible default, and their monthly total stays well within the free tier's 1 GB.
- **D7.** PostgreSQL is supported first (GeoNode). Database drivers are not a hard dependency of the client.

This plan proposes how. It was approved with the answers at the end; each step records what it decides in `docs/DESIGN.md` in the same commit.

## Steps

Each step is one commit that passes both test suites, followed by a review before the next step starts.

| Step | What | Visible result |
|---|---|---|
| 2.1 | Remote HTTP checks | "Web apps" on the instance page with interval and egress estimate; a new per-minute timer runs due checks; failures open incidents |
| 2.2 | The check settings channel and service checks | "Services" on the instance page; the client receives settings only when they change, checks `systemctl` state every heartbeat and reports results |
| 2.3 | Local HTTP checks | "Local HTTP" on the instance page; the client requests loopback URLs |
| 2.4 | Database checks | `maguari-client set-database`; "Databases" on the instance page; PostgreSQL checks, driver optional |

2.1 is independent of the client and could swap places with 2.2. It goes first because it is the smaller step and lays down what the others reuse: check results recorded outside the daily job, the dashboard's "Checks" column and the new check labels. 2.3 and 2.4 depend on 2.2.

Out of scope for phase 2: response times as metrics or charts, matching response content, custom expected status codes, remediation (phase 3) and the egress indicator itself (phase 4).

## Proposals

### P1. Who owns what

1. **Monitoring owns every check definition**, server-side and client-side, as it already owns certificate hostnames (design section 6.3.2): they are check configuration. New tables, prefixed as usual:
   - `monitoring_http_checks(id, instance_id, url, interval_seconds, added_at, next_due_at)`, unique by instance and URL.
   - `monitoring_client_checks(id, instance_id, type, target, host_name, added_at)`, with `type` `service`, `local_http` or `database`, `target` the unit name, URL or database name. `host_name` is set only for `local_http` (P6). Unique by instance, type, target and host name.
2. **Clients owns the protocol**: the new request and response fields (P3) and the client's last reported settings version (`clients_clients.check_settings_version`) so the instance page can say whether the client has the current settings. It gets the settings from `MonitoringApi::clientCheckSettings($instanceId)` and hands results to `MonitoringApi::parseCheckResults()` and `recordHeartbeat()`, like readings today.
3. **Check names** (also the subjects' meaning): `remote_http` (subject: the URL), `service` (the unit name), `local_http` (the URL, followed by the host name in parentheses when one is set) and `database` (the database name). Monitoring's and Notifications' `CheckLabels` get "Web app", "Service", "Local HTTP" and "Database", so no notification says "Other check".
4. **Results are recorded through `CheckStates::record()`**, exactly like the daily job's and the heartbeat-age check's (D5): a changed outcome writes `monitoring.check_failed` or `monitoring.check_passed` in the same transaction, Remediation opens or resolves the incident and Notifications sends the alert. Removing a check in the web app withdraws its outcome at once (`monitoring.check_withdrawn`), as removing a hostname does, so its incident resolves as `withdrawn`. Nothing new in Remediation or Notifications beyond the labels.
5. **No result history table.** These checks run every minute (client) or every few minutes (remote), so a row per result would break the "runs, never one row per reading" rule. The current outcome and sentence live in `monitoring_check_states`, which gets a `checked_at` column (the latest result's time, so pages can show "checked 40 seconds ago" and spot a check that stopped reporting). History is the incidents.
6. **Sentences are always Maguari's own.** The server builds every sentence from fixed reason codes and validated values (P4), never from text the client, a web app or a database sent.

### P2. Remote HTTP checks (step 2.1)

1. **Definition:** on the instance page, "Web apps": a URL and an interval, each with a "Remove" button, at most 10 per instance. Rules:
   - `https://` or `http://`, no user, password or fragment, at most 2,048 bytes; path and query allowed.
   - The host follows the hostname rules of design section 6.3.2 (DNS names only, no IP addresses, no `localhost`, at least two labels) and additionally refuses names ending in `.internal`, so the server cannot be pointed at Compute Engine's metadata server by name. A port is allowed.
2. **The request:** `GET`, `Connection: close`, `User-Agent: Maguari/<server version> (health check)`, no redirects followed, the certificate verified against the system's CA store. 5 seconds to connect and complete the handshake (the remote certificate check's limit) and 10 seconds in all. Only the status line and headers are read; the connection is then closed without reading the body, which keeps both time and the server's ACKs (egress) small.
3. **Outcome:** 2xx or 3xx passes ("Answered 200 in 340 ms."; a 3xx counts because redirects are not followed, and a redirect to a login page means the app is up); 4xx and 5xx fail ("Answered 503."); no answer, refused connection, failed name resolution and an untrusted or mismatched certificate fail, each with its own sentence. Never "not checked": if the server's own network is down, every check fails at once and the summary email (design section 13.3 item 9) says it looks like the server's network.
4. **Scheduling:** a new command, `maguari-server run-http-checks`, on its own per-minute timer (`maguari-server-http-checks.service` and `.timer`, the tick's settings) with its own lock file. It runs the checks whose `next_due_at` has passed, one after another, outside any transaction, and stores each result in a short transaction. `next_due_at` advances by the interval from the previous due time, skipping slots that passed, like the client's schedule. A new check is due at once. No new check starts after 30 seconds; the rest wait for the next minute. The tick delivers the events within a minute.

   *Alternative:* a step in the tick. One command fewer, and events delivered in the same tick, but slow or unreachable web apps would then compete with alert sending (40 seconds) for the tick's minute.
5. **Interval:** 1 to 60 minutes, **default 10 minutes**. The local HTTP check (P6) already watches the app every minute at no egress cost; the remote check adds what only the outside sees (DNS, firewall, load balancer, certificate), which changes rarely. A shorter interval is allowed within the budget (P2 item 6).
6. **Egress budget** (D6). Measured from the server, a check costs only what the server sends: TCP SYN and ACKs, the TLS ClientHello and Finished, the request and the close, about 1.5 KB with a modern TLS 1.3 handshake. The estimate uses **4 KiB per check** (`HttpChecks::EGRESS_BYTES_PER_CHECK`), more than twice that, for retransmissions and larger ClientHellos. Step 2.1 measures the real figure in development (the transmit counter of `/proc/net/dev` before and after 100 checks) and records it in the design; the constant changes only if the measurement is higher. Per check, in a 31-day month:

   | Interval | Checks per month | Egress per month |
   |---|---|---|
   | 1 min | 44,640 | 183 MB |
   | 5 min | 8,928 | 37 MB |
   | 10 min (default) | 4,464 | 18 MB |
   | 30 min | 1,488 | 6 MB |
   | 60 min | 744 | 3 MB |

   All remote HTTP checks together get a **200 MB monthly budget** (`HttpChecks::MONTHLY_EGRESS_BUDGET_BYTES`). Adding a check or shortening an interval that would take the estimate above it is refused with a sentence giving both numbers ("These checks would use about 219 MB a month, above the 200 MB set aside for them. Use a longer interval or remove a check."). At the default interval that is 10 web apps. The instance page shows the fleet's total ("Web app checks use about 92 MB of their 200 MB a month."). The month's whole picture for 10 instances: heartbeats about 430 MB (design section 14), remote HTTP checks at most 200 MB, the dashboard and emails, leaving more than 25% of the 1 GB free. Check settings in heartbeat responses (P3) are a few hundred bytes per change, too little to count.

### P3. The check settings channel (step 2.2)

1. **The version.** `MonitoringApi::clientCheckSettings($instanceId)` returns the instance's client checks in a fixed order and their version: the first 16 hex characters of the SHA-256 of their canonical JSON. A hash rather than a counter: nothing extra to store or keep in step, and removing a check and adding it back returns to the same version.
2. **The request** gets an optional `check_settings_version`: the version the client has, or `null` when it has none. Sending the field at all says the client understands `check_settings`.
3. **The response:** when the field is present and differs from the current version, the answer is `200` with
   ```
   {"check_settings": {"version": "3f9a0c1d2e4b5a69", "checks": [
       {"type": "service", "unit": "nginx.service"},
       {"type": "local_http", "url": "https://127.0.0.1/health", "host_name": "geonode.example.com"},
       {"type": "database", "name": "geonode"}
   ]}}
   ```
   Otherwise `204`, as today (D3). An instance with no client checks has a version too, so a new client gets one small response once and `204` after that.
4. **Protocol version stays 1** (design section 4 item 4): both fields are optional. Older clients never send `check_settings_version`, so they never get a body they would treat as an error (today's client accepts only `204` or an empty `200`). Newer clients talking to an older server get `204` and run no checks.
5. **The client** validates the settings with the same shared code the server used (P4), skips any check it does not understand or that fails validation (logging one line), stores them atomically in `check-settings.json` next to its credentials (mode 0600, the same write as the credentials) and reports the new version from the next heartbeat on. Settings that are not valid JSON or lack a version are ignored and the old ones kept, so the server sends them again next time. A restart keeps them.
6. **Running checks:** before each heartbeat the client runs every check in its settings and sends the results in `checks`. Service checks take milliseconds. Local HTTP and database checks have 5-second timeouts and at most 5 of each per instance; the client starts no new one after 20 seconds and reports the rest as not checked ("The client ran out of time for its checks."), so the heartbeat always leaves on time.
7. **Results:**
   ```
   "checks": [
       {"type": "service", "subject": "nginx.service", "outcome": "fail", "reason": "inactive", "state": "failed"},
       {"type": "local_http", "subject": "https://127.0.0.1/health (geonode.example.com)", "outcome": "pass", "status": 200, "ms": 41},
       {"type": "database", "subject": "geonode", "outcome": "not_checked", "reason": "driver_missing"}
   ]
   ```
   Monitoring validates them before anything is written (`parseCheckResults()`): a malformed result, an unknown reason for a known type, a subject sent twice or more than 50 results make the whole heartbeat `bad_request`, as readings do. Results of unknown types are ignored (newer clients). A result for a check the instance no longer has is ignored (the client may be one heartbeat behind a removal). The server's receive time is the result's time.
8. **The instance page** shows, under each client check, its outcome and sentence with "checked 40 seconds ago". It says "Waiting for the client" instead while the client's reported version is not the current one, and "This client does not run checks; it needs version X or later" when its last heartbeat had no `check_settings_version`.

### P4. Validation (D4)

The rules live in `shared/src/`, so the server validates what it stores and the client validates again what it receives, with the same code. The client runs nothing that fails them.

1. **Service names** (`shared/src/UnitName.php`): systemd's unit name rules restricted to services: `<name>.service` or `<name>@<instance>.service`, at most 255 bytes, characters `A-Z a-z 0-9 : _ . \ -` (and one `@` before a non-empty instance), not starting with `-` or `.`. A name typed without a suffix gets `.service` appended. Templates without an instance (`getty@.service`) are refused, because they cannot be active.
2. **Local URLs** (`shared/src/LocalUrl.php`): `http://` or `https://`, host exactly `127.0.0.1`, `[::1]` or `localhost`, an optional port, a path and query, no user, password or fragment, at most 2,048 bytes. The client connects to the literal address (`localhost` means `127.0.0.1`, never resolved), so neither DNS nor `/etc/hosts` can send the request elsewhere. Redirects are not followed, so a response cannot send it elsewhere either.
3. **Host names for local HTTP** (optional): a DNS name with the rules of design section 6.3.2. It only sets the `Host` header and the TLS name (SNI and certificate verification); the connection still goes to loopback. Many sites sit behind an nginx `server_name`, so `127.0.0.1` alone would reach the default site. `https://` requires a host name, so the certificate can be verified against it.
4. **Database names** (`shared/src/DatabaseName.php`): 1 to 63 bytes of lowercase letters, digits, `_` and `-`, starting with a letter or digit. It is a key into the instance's own file (P7), nothing more.
5. **How the client runs a service check:** `proc_open()` with an argument array, never a shell: `systemctl show --property=LoadState,ActiveState,SubState -- nginx.service`. Unprivileged (design section 5.4); no sudoers entry.

### P5. Service checks (step 2.2)

1. `ActiveState` `active` passes ("nginx.service is active (running)."). `reloading` passes too.
2. `inactive`, `failed`, `activating` and `deactivating` fail ("nginx.service is failed."), reason `inactive` with the state.
3. `LoadState` `not-found` fails ("There is no unit nginx.service on the instance."), reason `not_found`: a typo in the web app should be noticed.
4. `systemctl` missing or failing to run: not checked, reason `systemctl_failed`.

*Alternative to item 2:* `activating` as not checked, since a service restarting takes a moment. Rejected: one that stays in `activating` (a crash loop with `Restart=`) is exactly what should fail.

### P6. Local HTTP checks (step 2.3)

1. Defined on the instance page, "Local HTTP": URL and optional host name (P4 items 2 and 3), at most 5 per instance.
2. Same request and outcome as remote checks (P2 items 2 and 3) through the client's own stream code: `GET`, headers only, no redirects, 5 seconds to connect and 5 for the headers. The client sends `status` and `ms`, or a reason (`timeout`, `refused`, `tls_failed`).
3. No egress cost: results ride in the heartbeat request, which is ingress for the server.

### P7. Database checks (step 2.4)

1. **The file** (D2): `/var/lib/maguari-client/databases.json`, next to the credentials, in the directory the client already keeps at mode 0700, written and read with the same rules as `credentials.json`: mode 0600, written atomically under a `0077` umask and refused when group or others can access it. `MAGUARI_CLIENT_DIR` moves it in development. The passwords are stored as plain text: on the instance, an encryption key would have to sit next to the file, which protects nothing.
   ```
   {"geonode": {"type": "postgresql", "host": "127.0.0.1", "port": 5432, "database": "geonode", "user": "maguari_check", "password": "..."}}
   ```
   A host starting with `/` is a Unix socket directory, where the password may be empty (peer authentication for a role named `maguari-client`).
2. **Commands, so nobody edits JSON or permissions by hand**, all run as the client's user (`sudo -u maguari-client maguari-client ...`):
   - `set-database <name> --type=postgresql --host=127.0.0.1 --port=5432 --database=geonode --user=maguari_check` reads the password from standard input (without echo on a terminal), never from the command line, where it would show in `ps` and shell history. It warns when the driver for the type is not installed.
   - `remove-database <name>`, `list-databases` (names, types and hosts, never passwords) and `check-database <name>`, which runs the check once and prints its result, for trying a setup before adding it in the web app.
3. **The check:** connect with PDO, run `SELECT 1`, disconnect. For PostgreSQL: `connect_timeout=5` in the DSN and `statement_timeout` of 5 seconds. The design's read-only user (design section 5.4) is documented with the minimum it needs: `CREATE ROLE maguari_check LOGIN PASSWORD '...'` and `GRANT CONNECT ON DATABASE geonode TO maguari_check`; `SELECT 1` reads no table.
4. **Outcomes and reasons:**

   | Situation | Outcome | Reason |
   |---|---|---|
   | `SELECT 1` answered | Pass, with the time | |
   | Connection refused, timeout, the server starting up or shutting down, the query failing | Fail | `unreachable`, `timeout`, `query_failed` |
   | Login refused | Fail | `login_refused` |
   | No database of that name in the file | Not checked: "No database named geonode is set up on the instance. Run maguari-client set-database geonode there." | `not_set_up` |
   | The file is missing, unreadable, too open or malformed | Not checked | `file_unusable` |
   | The driver is not installed | Not checked: "The PostgreSQL driver is not installed on the instance. Install it with: sudo apt install php-pgsql" | `driver_missing` |

   A refused login fails rather than "not checked" so it gets noticed; its own reason lets phase 3 avoid restarting a database whose only problem is the check's password.
5. **Drivers are optional** (D7). `maguari-client` keeps depending on `php-cli` only and gets `Suggests: php-pgsql, php-mysql`; apt does not install suggested packages by default, unlike recommended ones. The client looks for the PDO driver with `extension_loaded()` when a check of that type runs, so an instance without database checks never needs it. Packaging records the `Suggests:` line in design section 12.1 now and writes it in phase 4.
6. **Types:** PostgreSQL, MySQL and MariaDB in this step (Q1). MySQL and MariaDB share one type, `mysql`, through `pdo_mysql` (`php-mysql`), with `PDO::ATTR_TIMEOUT` of 5 seconds and `max_execution_time` for the query. The missing-driver sentence names the package for the type. PostgreSQL stays the one tested against a real server in development.
7. **Tests:** unit tests through a fake connector (every outcome above, the driver check and the file's permission rules). An integration test against a real PostgreSQL runs only when `MAGUARI_TEST_POSTGRESQL_DSN` is set, so neither test suite needs a database server.

### P8. The dashboard

From step 2.1, the instances table gets a "Checks" column: the worst current outcome of the instance's remote HTTP, service, local HTTP and database checks (fail over not checked over pass), "Pass (5)" with the number when all pass, its (i) icon listing each check with its sentence. The cell is empty for an instance without any. Each later step feeds it with its own check.

## Known limits

- **Docker deployments** (common for GeoNode): service checks see `docker.service`, not the individual containers, because access to the Docker socket is effectively root and the client must never have it. Database checks work only when the database's port is published to the host (or reachable from it). The local HTTP check still covers the application as a whole, through the port the containers publish. Recorded in design section 6.1.

## Answers

1. **Q1, MySQL and MariaDB:** yes, in step 2.4, next to PostgreSQL (P7 item 6).
2. **Q2, confirming failures:** yes. The remote HTTP check retries once, 5 seconds later, before a failure counts (step 2.1), and client checks need 2 failed results in a row (step 2.2). Recorded as a new rule in design section 6.3 item 7, the remote part in step 2.1 and the client part in step 2.2.
3. **Q3, interval and budget:** a 10-minute default and the 200 MB monthly budget (P2 items 5 and 6).
4. **Q4, scheduling:** `run-http-checks` on its own timer (P2 item 4).
5. **Q5, the database file:** in `/var/lib/maguari-client/`, managed by commands (P7 items 1 and 2).
6. **Docker:** documented as a known limit (above).

## Found while implementing

### Step 2.1

- **Egress measured:** 100 requests each to three public sites (www.example.com, www.google.com, github.com) from the Ubuntu 22.04 test image (OpenSSL 3.0), in a container with its own network namespace so `/proc/net/dev` counted nothing else: 1,455 to 1,539 bytes and 12 to 14 packets sent per request. The 4 KiB estimate stays, more than 2.5 times that; post-quantum key shares in newer OpenSSL versions add about 1.2 KB to the ClientHello and still fit.
- **Only the status line is read**, not the headers (P2 item 2 said both). Nothing in a header changes the outcome, and closing earlier sends fewer ACKs.
- **The request is a new Kernel helper, `Kernel/HttpProbe/StreamHttpProbe`**, on stream sockets like the TLS certificate reader, rather than the stream wrapper of `StreamHttpClient`: it reads only the status line, bounds the handshake by a deadline and tells name resolution, refused connections, timeouts and TLS failures apart for the sentences. Monitoring's `Domain/` has its own `HttpFailure` enum, because `Domain/` uses nothing from Kernel.
- **The User-Agent is `Maguari health check`**, without a version: the server has no `VERSION` file yet (packaging, phase 4).
- **`checked_at`** came with this step as P1 item 5 said. Every result now writes its check state, because the time changes even when nothing else does.
- **The interval can be changed** on each row, not only set when adding (P2 item 6 implied it). A shorter one brings the next check forward to at most one interval from now; a longer one keeps the next check.

#### Manual check (step 2.1)

In each terminal, from the repository root:

```
source scripts/dev-env.sh
```

Apply the new migration:

```
server/bin/maguari-server migrate
```

Start the web app in one terminal:

```
cd server && php -S localhost:8080 -t public
```

Keep the tick running in a second terminal:

```
while true; do server/bin/maguari-server tick; sleep 60; done
```

And the remote HTTP checks in a third:

```
while true; do server/bin/maguari-server run-http-checks; sleep 60; done
```

On an instance's page, add `https://www.example.com/` under "Web apps" with the default interval. Within a minute the third terminal prints `Pass. Answered 200 in ... ms.`, and the dashboard's Checks column shows "Pass (1)". Then add `https://maguari-test.invalid/` with an interval of 2 minutes: the next run prints the failure ("did not resolve in DNS. Tried twice, 5 seconds apart."), the tick opens the incident and its notification, and with SMTP set up the alert email arrives with the "Web app" label. Remove that check: the incident resolves as withdrawn on the next tick. Finally, change the example.com check to 1 minute: with the other at 10 minutes that is about 201 MB a month, so the change is refused with that number.

## Design changes once approved

Sections 5.2 (the new request and response fields), 6.1 and 6.2 (the checks), 9.2 (the tables and `checked_at`), 10.1 (the new timer), 12.1 (`Suggests:`), 12.2.1 (`run-http-checks`), 14 (the egress budget) and 17 (phase 2's steps), each in the step that implements it.
