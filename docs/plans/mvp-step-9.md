# MVP Step 9: Certificate expiry, local and remote

## Context

Step 8 (`docs/plans/mvp-step-8.md`) delivered the daily job: `MonitoringApi::runDailyJob()`, run by the systemd timer at 06:00 UTC and by the "Run now" button, with the boot disk size check as its only check. Results go to `monitoring_check_results` and the dashboard shows them from the last successful run.

Step 9 is: "Certificate expiry: the client's certificate scanner (section 6.1.1) and the local and remote certificate checks in the daily job" (design section 15). The design says:

- **6.1.1:** `/etc/letsencrypt` is readable only by root and holds private keys, so a small scanner runs **as root** from a systemd timer, reads each certificate's expiry date and writes only that to a file the unprivileged client can read. It runs at package installation, daily and from a certbot deploy hook. The client never reads `/etc/letsencrypt`.
- **6.2:** the server checks the certificate actually served, which catches a renewed certificate that was never reloaded. "Certificate expiry is checked both locally and remotely."
- **6.3:** both certificate checks run in the daily job.
- **3.1 item 7:** Monitoring's internal layers are decided in this step, "when it has two kinds of checks and two sources of input."

The step 8 plan listed what is missing:

| Check | Missing |
|---|---|
| Local | The scanner, its timer and deploy hook, a file format, a wire format for the heartbeat, a new client release |
| Remote | The hostnames to connect to, per instance, and where administrators set them. A TLS client that reads the served certificate. |

Packaging is not an MVP step (design 12.4). As in step 8, unit files are written and checked but not installed by anything, and in development the scanner runs by hand.

Why it matters now: Let's Encrypt stopped sending expiry reminder emails in 2025, and it plans to shorten its default certificate lifetime from 90 days to 45. A renewal that silently fails, or succeeds without the web server reloading, is now noticed only by visitors.

Relevant design sections: 2.1 (contexts), 3.1 (layout, item 7), 5.2 (heartbeat and readings), 5.4 (client privileges), 6.1.1, 6.2, 6.3, 9.1 (runs), 9.2 (tables), 10.1 (systemd), 10.2 and 11.3 (forms, CSRF), 12.1 (client package contents).

## Is this too large for one step?

Yes. It has a refactor, a root-owned program, a wire change with a client release, a new check, a configuration page and a TLS client. **Split (confirmed)** into four sub-steps, each one commit that passes both test suites, with a review stop after each:

| Sub-step | Delivers | Depends on |
|---|---|---|
| **9.1** Monitoring's layers | Monitoring's files moved into the layers decided here (D1). No behavior change. | Step 8 |
| **9.2** Local expiry dates reach the server | The scanner, its unit files and deploy hook, the certificates file, `certificate_expires_at` readings from the client, the server accepting and storing them as runs | 9.1 |
| **9.3** The local certificate check | The check in the daily job, a `subject` column on results, the "Certificates" column on the dashboard, the CLI output | 9.2 |
| **9.4** The remote certificate check | Hostnames per instance on a new instance page, a TLS certificate reader in `Kernel/`, the remote check in the daily job, the "not reloaded" hint | 9.3 |

9.2 is usable on its own (the runs are visible in SQLite), like 7.1 was. 9.4 does not depend on the scanner, so 9.4 could come before 9.2 and 9.3 if remote checks are wanted sooner; this order was chosen because 9.4's suggestions and hint (D13, D15) build on local results.

*Alternative:* fold 9.1 into 9.3. Not recommended: a move-only commit is easy to review, and mixing it with new code is not.

## Interpretations (confirmed)

**I1. Results are shown, not acted on**, as in step 8 (I1 there): no incident, alert or notification. Email is step 10.

**I2. The client does not judge.** The client reports expiry dates; the server decides pass or fail in the daily job. So the threshold lives in one place and changes without a client release, and the local check uses the same rule as the remote one.

**I3. Remote checks belong to an instance.** Check results have an `instance_id` (design 9.2), and incidents are per instance (design 7.4). A hostname is checked as part of the instance that serves it. A hostname behind a load balancer in front of several instances is set on one of them.

**I4. Remote means through public DNS.** The server connects to the hostname as visitors do (DNS, port 443, SNI), not to the instance's IP. That is what "the certificate actually served" means, and it works when the instance sits behind a load balancer.

**I5. Let's Encrypt only, locally.** The scanner reads certbot's layout (`/etc/letsencrypt/live/*/cert.pem`). Certificates elsewhere are covered only by the remote check. The remote check accepts any CA.

## Decisions (confirmed)

All interpretations and decisions were approved as proposed, with the answers at the end of this plan.

### Monitoring's layers (9.1)

**D1. Three folders plus the public interface.** Monitoring is a core context (design 2.1) and now has two kinds of input (heartbeat readings and the daily job's API and TLS calls) and three checks:

```
server/src/Monitoring/
  MonitoringApi.php       the public interface (design 2.1 rule 1), and its exceptions:
  Exception/              DailyJobAlreadyRunning, DailyJobFailed, InvalidReadings, ...
  Domain/                 pure: no database, no network, no clock. Rules and values:
                          Reading, Readings, RunRule, RunDecision, MetricRun,
                          CheckOutcome, CheckResult, DiskSizeRule, DailyJobTrigger,
                          DailyJobRun, DailyJobState, DailyJobSummary
  Application/            orchestration that uses the other two: DailyJob
  Infrastructure/         SQL only: MetricRunRepository, DailyJobRepository
  Migrations/
```

- Rule: `Domain/` uses nothing outside `Domain/`, Monitoring's `Exception/` and `shared/`, and no database, network, file or clock functions. A test enforces it by scanning the code's tokens, so it cannot drift. *Refined in 9.1:* the exceptions are allowed because `Readings` throws `InvalidReadings`, which `MonitoringApi` passes on.
- Namespaces follow the folders (`Maguari\Server\Monitoring\Domain\...`). Other contexts and `Http/` keep using only `MonitoringApi` and the types it returns, which move namespace; their `use` lines change and nothing else.
- Fleet, Clients, Access and the others stay flat: they are supporting or generic (design 2.1).

*Alternative A:* feature folders (`Runs/`, `DailyJob/`, `Checks/DiskSize/`, `Checks/Certificates/`). Groups by topic, but puts SQL and pure rules side by side again, which is what layers are meant to separate.

*Alternative B:* stay flat. Monitoring would reach about 35 files after this step. Workable, but the design asks for a decision now, and the purity of rules is a convention nobody checks.

### The scanner (9.2)

**D2. A separate executable, not a client subcommand.** `client/bin/maguari-certificate-scanner`, PHP, shipped in the client package and using the client's autoloader. It contains only what scanning needs (one small class plus `bin/`), so the code that runs as root stays small enough to read in one sitting. `maguari-client` keeps refusing root for every command (design 5.4).

- It reads only `cert.pem` in each directory under `/etc/letsencrypt/live/` (each certbot lineage). Never `privkey.pem`, `fullchain.pem` or `archive/`. Each `cert.pem` is a symlink into `archive/`, which it follows; files over 64 KiB are skipped.
- For each certificate it parses the first PEM block with `openssl_x509_parse()` (the openssl extension is built into Ubuntu's `php-cli`, so no new dependency) and keeps the lineage name, the DNS names from `subjectAltName` (or the subject CN when there is none) and `validTo_time_t`.
- A certificate that cannot be read or parsed is left out. With nothing readable (no certbot on the instance), it writes an empty list.
- *Refined in 9.2:* a `live/` directory that exists but cannot be listed is an error ("Run the certificate scanner as root."), not an empty list, so running it as the wrong user cannot look like "no certificates". Only a PEM certificate block is passed to `openssl_x509_parse()`, which would otherwise read a string starting with `file://` as a path.
- At most 20 certificates, in lineage name order.
- It does not require root: it needs read access to the directory. In tests and development it reads a fixture tree as the developer's user.
- Exit code 0 when it wrote the file, 1 with one line on stderr when it could not. Never a stack trace.

**D3. The certificates file.** `/var/lib/maguari-certificate-scanner/certificates.json`, in its own directory owned by root (mode 0755), file mode 0644:

```json
{
    "scanned_at": 1791000000,
    "certificates": [
        {"name": "example.com", "domains": ["example.com", "www.example.com"], "expires_at": 1797000000}
    ]
}
```

- **A separate directory, not `/var/lib/maguari-client/` as design 6.1.1 suggests.** That directory belongs to `maguari-client` (mode 0700, it holds the credentials). A root process writing into a directory an unprivileged user controls is the classic symlink attack. Here root writes only into a root-owned directory, and the client only reads.
- World-readable is fine: domain names and expiry dates are public (certificate transparency logs).
- Written to a temporary file in the same directory and renamed, so the client never reads half a file.
- `scanned_at` and `name` are for people reading the file; the client sends only domains and dates (D5).
- `MAGUARI_CERTIFICATES_FILE` overrides the path, for development and tests only (both the scanner and the client read it). `MAGUARI_LETSENCRYPT_DIR` overrides `/etc/letsencrypt` for the scanner, also development and tests only. `scripts/dev-env.sh` sets the first to `client/var/certificates.json`. *Refined in 9.2:* it also sets the second, to `client/var/letsencrypt`, so the manual check below needs no inline variable.

**D4. Unit files and the deploy hook**, in `client/systemd/` and `client/certbot/` until packaging installs them (like `server/systemd/` in step 8):

`maguari-certificate-scanner.service`:

```
[Unit]
Description=Maguari certificate scanner

[Service]
Type=oneshot
ExecStart=/usr/bin/maguari-certificate-scanner
StateDirectory=maguari-certificate-scanner
StateDirectoryMode=0755
UMask=0022
NoNewPrivileges=yes
PrivateNetwork=yes
PrivateTmp=yes
ProtectHome=yes
ProtectSystem=strict
CapabilityBoundingSet=CAP_DAC_READ_SEARCH
```

`maguari-certificate-scanner.timer`:

```
[Unit]
Description=Run the Maguari certificate scanner every day

[Timer]
OnCalendar=daily
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
```

Deploy hook, `client/certbot/maguari-certificate-scanner` (installed later as `/etc/letsencrypt/renewal-hooks/deploy/maguari-certificate-scanner`):

```sh
#!/bin/sh
exec systemctl start --no-block maguari-certificate-scanner.service
```

- The service runs as root but keeps only the capability to read any file (`CAP_DAC_READ_SEARCH`), with no network (`PrivateNetwork=yes`) and a read-only system except its state directory, which systemd creates with the right owner and mode.
- The hook starts the service instead of running PHP itself, so there is one code path with one set of protections. `--no-block` keeps certbot from waiting on it.
- "Runs once at installation" (design 6.1.1 item 2) is the package's `postinst` starting the service, so it comes with packaging.
- The timer's time does not matter much: expiry dates are absolute, so a day-old scan is still correct unless a renewal happened since, and the deploy hook covers renewals.

### Expiry dates in the heartbeat (9.2)

**D5. Readings, not check results.** Each certificate becomes one reading:

```
{"metric": "certificate_expires_at:example.com", "value": 1797000000}
```

- A new kind in `shared/src/Metric.php`: `certificate_expires_at`, integer Unix seconds, whose subject is the certificate's **first domain** (certbot puts the `-d` domains in order, and the first is also its CN).
- Why readings: they are stored as runs, and an expiry date changes only on renewal, so one certificate costs one run per renewal plus outages. Old servers ignore unknown kinds (design 5.2), so **no protocol version change**. `checks` would need the client to judge (against I2) and a new storage path.
- Why the first domain and not the lineage name: it is what administrators recognize, and it can be matched to remote hostnames (D13, D15). Lineage names such as `example.com-0001` are not domain names.
- **Duplicates:** two lineages can share a first domain (certbot creates `example.com-0001` when asked for a new lineage). The server rejects a heartbeat with the same metric twice (design 5.2), so the client keeps only the latest expiry for each domain. The newest one is the one in use; the old one would only cause a false failure.
- **Subject format:** lowercase, 1 to 253 bytes, letters, digits, `-`, `.` and a leading `*.` for wildcards. The client lowercases and skips anything else; the server rejects it like a bad mount point (design 5.2 item 3), so a client bug cannot store junk.
- The client reads the file on every heartbeat (a few hundred bytes). A missing or malformed file gives no certificate readings, silently, like an unreadable mounts file in step 7. At most 20.
- Client version 0.2.0 becomes 0.3.0.

**D6. The server stores them as runs** with exact equality (design 9.1 item 1: every kind but disk used). Nothing else changes in `recordReadings()`.

### The local check (9.3)

**D7. One rule for both checks.** `CertificateExpiryRule` in `Monitoring/Domain/`, pure:

| Situation | Result |
|---|---|
| At least 14 days left | **Pass**: "Valid until 2026-12-01 (60 days)." |
| Fewer than 14 days left | **Fail**: "Expires on 2026-10-10, in 8 days. certbot normally renews 30 days before expiry, so renewal has been failing." |
| Already expired | **Fail**: "Expired on 2026-09-30." |

- **Why 14 days:** certbot renews 90-day certificates when 30 days remain and tries twice a day, so 14 days left means about two weeks of failed renewals, which is a real problem and not a passing glitch. With 45-day certificates certbot renews at about a third of the lifetime (15 days), so 14 days would fail after about one day of failed renewals. It is a constant in the rule for now. *Confirmed:* when 45-day certificates arrive, it becomes a setting (design section 6.3).
- Days are whole days rounded down. Dates are UTC, like every timestamp.
- *Refined in 9.3:* the rule's `judge()` gives the sentences without any mention of certbot, so 9.4 can use it for any CA. The local check adds "certbot renews well before expiry, so renewal is failing on this instance." to its failures, instead of "certbot normally renews 30 days before expiry", which will be wrong for 45-day certificates. Less than a day left reads "in less than a day", and one day "in 1 day".

**D8. Which local certificates are checked.** For each picked instance, every `certificate_expires_at:*` current run that ended in the last 24 hours, the same window as the disk size check. A certificate removed from the instance stops being reported and drops out after a day. An instance that reports none gets no local result at all, not "Not checked": most instances may have no certificate, and a permanent "Not checked" on each would hide the ones that matter.

- *Refined in 9.3:* the 24 hours are one constant, `MetricRun::RECENT_FOR_SECONDS` with `MetricRun::isRecent()`, used by both the disk size check and this one (it was `DiskSizeRule::MAX_READING_AGE_SECONDS`), and the disk size sentences take their "24 hours" from it (design section 18 item 3: one named constant per threshold).
- **Known gap:** a broken scanner on an instance that does have certificates looks the same as no certificates. The remote check covers the certificates that are actually served. A stale scan errs towards failing: after a renewal, the old date stays until the next scan.

**D9. One result per certificate.** A `subject` column on `monitoring_check_results` (migration `0004_monitoring_check_results_subject.sql`, `TEXT NOT NULL DEFAULT ''`), holding the domain for certificate checks and `''` for the disk size check. Check names: `local_certificate` and `remote_certificate`. One row per certificate keeps the history per certificate, which incidents will need later.

*Alternative:* one row per instance with the worst outcome and every certificate in `detail`. No migration, but the history becomes a list of sentences.

**D10. Dashboard and CLI.**

- A **"Certificates"** column after "Disk size": the worst outcome among the instance's local and remote results (fail over not checked over pass), shown as "Pass (3)", "Fail" or "Not checked". The tooltip lists every certificate with its sentence. Empty when the instance has none. Failed certificates are also listed below the table, like disk size failures.
- `DailyJobSummary` gains the certificate results by instance, from the same last successful run.
- `run-daily-job` prints one line per certificate after each instance's disk size line, for example "my-project/us-east1-b/web: Certificate example.com: Pass. Valid until 2026-12-07 (60 days)." *Known gap in 9.3:* the CLI's tests run the real command, which cannot reach a fake Compute Engine, so they cover only runs without instances; the per-check lines are not exercised by a test (the disk size line was not either).

### The remote check (9.4)

**D11. Hostnames per instance, owned by Monitoring.** They are check configuration (design 8.1 item 4), so Monitoring owns them (design 2.1):

```
monitoring_certificate_hostnames(
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,   -- Fleet's instance ID, no foreign key (design 2.1)
    hostname TEXT NOT NULL,
    added_at INTEGER NOT NULL,
    UNIQUE (instance_id, hostname)
)
```

- Migration `0005_monitoring_certificate_hostnames.sql`.
- **Validation:** lowercase after trimming; 1 to 253 bytes; labels of 1 to 63 letters, digits and hyphens, not starting or ending with a hyphen; at least two labels; the last label not all digits (so no IP addresses); no wildcards. Internationalized names must be entered in their `xn--` form (the `intl` extension is not a dependency). Each rejection has its own fixed sentence.
- **Port 443 only.** *Alternative:* `hostname:port`. Not needed yet; easy to add later with a `port` column.
- **At most 10 hostnames per instance**, which bounds the job's time (D14).
- **Who can make the server connect where:** only administrators can, and only to port 443. The page shows only Maguari's own sentences, never what the other side sent. The "at least two labels" rule rejects `localhost`. That leaves names that resolve to private addresses, which are accepted: monitoring internal sites is legitimate, and the TLS handshake on port 443 reveals nothing but a date.

**D12. A new instance page**, `/admin/instances/{id}`. There is no per-instance page yet, and "configure checks per instance" (design 8.1 item 4) needs one. For this step it shows:

1. The instance (project, zone, name), from `FleetApi`.
2. **"Certificates"**: each certificate's latest result, local and remote, from the last successful run.
3. **"Hostnames checked remotely"**: the list, each with a "Remove" button, and a form to add one. Posts go to `POST /admin/instances/{id}/certificate-hostnames` and `POST /admin/instances/{id}/certificate-hostnames/{hostnameId}/remove` (session and CSRF like every `/admin` form; no state-changing GET), then redirect `303` back to the page, which says what changed or why a hostname was refused.

Instance names on the dashboard and on the project page link to it. Adding a hostname does not check it immediately: the next run (or "Run now") does. An instance that is not picked is a `404`.

*Refined in 9.4:* the page does not say what changed after a successful add or remove: there is no flash message mechanism, and the updated list shows it. A refused hostname renders the page again with its sentence and the typed value (`422`), like adding a project. Removing a hostname that is already gone just redirects back. At the limit of 10 the form is replaced by a sentence. Fleet gained `FleetApi::pickedInstance(int)` for the page's lookup.

**D13. Suggestions from local certificates.** Below the form, the page lists the instance's local certificate domains (D5) that are valid hostnames and not configured yet, each with an "Add" button (the same form, prefilled). One click covers the usual case, a site served from the instance that holds its certificate. Wildcards are not suggested. Nothing is added automatically.

*Alternative:* check every local certificate's domain remotely without configuration. Simpler for administrators, but a certificate for a name served elsewhere (or a mail server on another port) would fail falsely, with no way to turn it off.

**D14. Reading the served certificate.** A `TlsCertificateReader` interface in `Kernel/Tls/` (generic, like `Kernel/HttpClient/`) and `StreamTlsCertificateReader` on `stream_socket_client('ssl://host:443')` with SNI and `capture_peer_cert`, using the system's CA store:

*Refined in 9.4:* the reader connects over `tcp://` with the timeout and then runs the TLS handshake non-blocking against a deadline, because PHP's connect timeout does not bound the handshake (a server that accepts and never answers would otherwise hang the job). The timeout is passed per call, like Fleet's HTTP requests, so the 5 seconds are Monitoring's constant (`ServedCertificates::TIMEOUT_SECONDS`), not the wiring's. Name resolution is not bounded by it. The two-connection policy lives in `Monitoring/Application/ServedCertificates`; the reader only connects once, verified or not. Sentences leave out the hostname, which the page and the CLI already show next to them: "Could not connect on port 443." and "The served certificate is not trusted or does not match the hostname." The tests run the reader against a local server (`tests/fixtures/tls-server.php`) with a CA and certificates made at test time (`tests/Support/TestCertificateAuthority.php`), and the job's tests use a fake reader.

1. **Verified connection** (peer and name verified). If it succeeds, the result is the certificate's expiry, and D7 decides.
2. If the handshake fails, a **second connection without verification**, only to read the certificate:
   - it has expired: **Fail** "Expired on 2026-09-30.";
   - otherwise: **Fail** "The certificate served for example.com is not trusted or does not match the name.";
3. Neither connection works (DNS, refused, timeout): **Not checked** "Could not connect to example.com on port 443." Reachability belongs to the HTTP checks (design 6.2), not here.

- **5 second timeout** per connection, covering connect and handshake.
- Connections are made one after another, outside any transaction, like the Compute Engine calls (design 6.3 item 2).
- **Known limit, worse than in step 8:** with all hostnames reachable, each costs well under a second. Each unreachable one costs up to 10 seconds (two attempts). "Run now" runs inside the request with nginx's 60 second limit (design 6.3 item 5), so a handful of dead hostnames can produce a `504` while the run still finishes. Queued runs remain the fix; this plan does not add them (Question 6).
- Tests use a fake reader. The stream reader itself is tested against a local TLS server the test starts (`stream_socket_server('ssl://127.0.0.1:0')` in a child PHP process) with a CA and certificates generated at test time with `openssl_*`, so no key is ever committed. The reader takes an optional CA file, for tests only.

**D15. The "not reloaded" hint.** The design's reason for the remote check. When a remote result fails on expiry and the same instance reports a local certificate whose domain equals the hostname and that expires later, the sentence adds: "The instance has a renewed certificate (valid until 2026-12-01). Reload the web server." (*As built:* "The instance has a renewed certificate, valid until 2026-12-01: reload the web server.", and only a local certificate reported in the last 24 hours counts.) Matching is by exact domain; a hostname covered only by a wildcard or a later SAN gets no hint, which costs nothing but the hint.

**D16. The job's order.** For each picked instance: disk size, then local certificates (from runs, instant), then remote certificates. All Compute Engine and TLS calls happen before the one transaction that writes the results (design 6.3 item 2). A failure of one hostname is a result, never a job failure.

## Out of scope for this step

- Incidents, alerts and notifications for certificate failures (I1).
- Installing the scanner's units and hook, and running the scanner at package installation (packaging).
- Certificates outside `/etc/letsencrypt/live/` on the instance (I5).
- Ports other than 443 (D11) and other protocols (SMTPS, IMAPS).
- Queued runs for "Run now" (D14).
- Configurable thresholds and per-certificate muting.
- Checking a hostname the moment it is added.
- An audit log of hostname changes (design 9.2 lists an audit log; it does not exist yet).
- Other per-instance settings on the new instance page.

## Repository changes

### 9.1 Monitoring's layers

```
server/src/Monitoring/            files moved into Domain/, Application/, Infrastructure/ (D1)
server/bin/maguari-server                   use lines
server/src/Http/Controller/AdminController.php   use lines
server/templates/admin.php                  use lines
server/tests/Monitoring/                    moved to match, plus LayersTest (Domain/ purity)
server/tests/Http/DailyJobFlowTest.php      use lines
docs/DESIGN.md                              section 3.1 item 7
```

### 9.2 Local expiry dates reach the server

```
shared/src/Metric.php                       certificate_expires_at, isCertificateDomain()
shared/tests/MetricTest.php
client/
  VERSION                                   0.3.0
  bin/maguari-certificate-scanner           (D2)
  src/CertificateScanner.php                reads live/*/cert.pem, writes the file (D2, D3)
  src/CertificateExpiry.php                 the client's readings from the file (D5)
  src/CertificatesFile.php                  the file's path, shared by the scanner and the client
  src/HeartbeatSender.php, src/Cli.php      sends them
  systemd/maguari-certificate-scanner.service   (D4)
  systemd/maguari-certificate-scanner.timer     (D4)
  certbot/maguari-certificate-scanner       deploy hook (D4)
  tests/CertificateScannerTest.php          certificates generated at test time
  tests/CertificateExpiryTest.php
  tests/Support/TestCertificates.php        certificates generated at test time
  tests/ProcessTest.php                     the scanner from a copy of client/ and shared/
  tests/HeartbeatSenderTest.php, tests/EndToEndTest.php, CliTest, RunnerTest, EnrollerTest
server/
  src/Monitoring/Domain/Readings.php        accepts and validates the new kind (D5, D6)
  tests/Monitoring/Domain/ReadingsTest.php, tests/Monitoring/MonitoringApiTest.php
scripts/dev-env.sh                          MAGUARI_CERTIFICATES_FILE
docs/DESIGN.md
```

### 9.3 The local certificate check

```
server/
  src/Monitoring/
    Domain/CertificateExpiryRule.php        (D7)
    Domain/CheckResult.php                  subject (D9)
    Domain/CheckOutcome.php                 worst() (D10)
    Domain/MetricRun.php                    the 24 hour window, shared by both checks
    Domain/DiskSizeRule.php                 uses it
    Domain/DailyJobSummary.php              certificate results (D10)
    Application/DailyJob.php                runs the check (D8)
    Infrastructure/MetricRunRepository.php  current runs of one kind
    Infrastructure/DailyJobRepository.php   subject
    Migrations/0004_monitoring_check_results_subject.sql
  bin/maguari-server                        prints certificate lines
  templates/admin.php                       Certificates column (D10)
  tests/...                                 rule, job, CLI, dashboard, migration
docs/DESIGN.md
```

### 9.4 The remote certificate check

```
server/
  src/Kernel/Tls/TlsCertificateReader.php, StreamTlsCertificateReader.php, ...   (D14)
  src/Monitoring/
    MonitoringApi.php                       hostnames: list, add, remove, suggestions; takes the reader
    Exception/InvalidCertificateHostname.php
    Domain/CertificateHostname.php          validation, port and limit (D11)
    Domain/CertificateExpiryRule.php        checkRemote() with the reload hint (D14, D15)
    Domain/ServedCertificate.php
    Application/ServedCertificates.php      the two connections (D14)
    Application/CertificateHostnames.php    add, remove, suggestions (D11, D13)
    Application/DailyJob.php                remote checks (D16); local and remote results in the summary
    Infrastructure/CertificateHostnameRepository.php
    Migrations/0005_monitoring_certificate_hostnames.sql
  src/Fleet/FleetApi.php, InstanceRepository.php    pickedInstance()
  src/Cli/DailyJobReport.php                "on the instance" and "served for" lines
  src/Http/App.php, bin/maguari-server      routes, reader wiring
  src/Http/Controller/InstancesController.php   (D12)
  src/Http/Controller/ProjectsController.php    picked instances' IDs for links
  templates/instance.php                    (D12, D13)
  templates/admin.php, templates/project.php    links to the instance page; served results labelled
  tests/Kernel/Tls/StreamTlsCertificateReaderTest.php, tests/fixtures/tls-server.php
  tests/Support/TestCertificateAuthority.php, FakeTlsCertificateReader.php, TestEnvironment.php
  tests/Monitoring/Domain/CertificateHostnameTest.php, CertificateExpiryRuleTest.php
  tests/Monitoring/Application/RemoteCertificatesTest.php
  tests/Http/InstancePageFlowTest.php, AdminDashboardTest.php, DailyJobFlowTest.php
  tests/Cli/DailyJobReportTest.php
docs/DESIGN.md
```

## Flow

1. certbot renews a certificate on an instance; its deploy hook starts `maguari-certificate-scanner.service`. Also once a day from the timer.
2. The scanner, as root with only the read capability and no network, reads each `live/*/cert.pem` and rewrites `/var/lib/maguari-certificate-scanner/certificates.json`.
3. On every heartbeat, the client reads that file and adds a `certificate_expires_at:<domain>` reading per certificate.
4. The server validates the readings and stores them as runs.
5. At 06:00 UTC (or on "Run now") the daily job checks disk sizes, then each instance's recent local certificates, then each configured hostname over TLS. It stores one result per certificate.
6. The dashboard shows a "Certificates" column; the instance page shows each certificate and manages the hostnames.

## Tests

No test touches the network. Certificates and keys are generated at test time, never committed.

**9.1**
1. The existing suites pass unchanged apart from namespaces.
2. `Domain/` uses nothing outside `Domain/` and `shared/`.

**9.2**
1. Scanner: reads every lineage's `cert.pem`; ignores `privkey.pem` and files that are not directories under `live/`; skips unreadable, unparsable and oversized files; SANs, with CN as the fallback; at most 20; writes atomically with mode 0644; an empty or missing `live/` gives an empty list; exit codes and the one-line error.
2. Client: readings from the file; duplicates keep the latest expiry; wildcards kept, invalid names skipped; a missing or malformed file gives no certificate readings and the heartbeat is still sent.
3. Server: the new kind is stored as runs with exact equality; a bad subject or a negative value makes the heartbeat `bad_request`; an old-format heartbeat is unaffected.
4. End to end: the client from a copy of `client/` and `shared/` alone sends the readings.

**9.3**
1. Rule: exactly 14 days passes, 13 days and 23 hours fails, expired fails, the sentences and dates.
2. Job: one result per recent certificate; a run older than 24 hours is left out; no certificates gives no result; `subject` is stored.
3. Dashboard: the worst outcome per instance, the count, the tooltip, failures listed below; CLI lines.
4. Migration applies on top of step 8's.

**9.4**
1. Hostname validation: each accepted and each rejected form with its sentence; duplicates; the limit of 10.
2. Instance page: needs a session; posts need CSRF; add, remove, refuse, suggestions; `404` for an instance that is not picked; `GET` on the post routes is `405`.
3. Reader against a local TLS server: a trusted certificate gives its expiry; an untrusted one, a wrong name and an expired one each take the second connection and are described correctly; a closed port is "could not connect".
4. Job: remote results per hostname; the "not reloaded" hint when a local certificate with the same domain expires later; one unreachable hostname does not fail the run.

## Changes to `docs/DESIGN.md`

- **This plan's answers:** section 15, step 9 names the sub-steps and links this plan; section 6.1.1 (the scanner's own root-owned directory, D3); section 6.3 (14 days becomes a setting when 45-day certificates arrive, D7).
- **9.1:** section 3.1 item 7 (Monitoring's layers and the purity rule).
- **9.2:** section 5.2 (the new kind and its subject rule, duplicates, still protocol version 1); section 6.1.1 (separate executable, the file's path and format, unit hardening, the hook starting the service, files in `client/systemd/` and `client/certbot/`); section 12.1 (the scanner executable in the client package).
- **9.3:** section 6.3 (the certificate checks: the rule, 14 days, which certificates, one result per certificate, the dashboard column); section 9.2 (`subject`).
- **9.4:** section 6.2 (remote check: hostnames per instance, DNS and port 443, the two connections, the "not reloaded" hint); section 8.1 item 4 (the instance page is where checks are configured); section 9.2 (the hostnames table); section 6.3 item 5 (the "Run now" limit with TLS timeouts).

## Verification

For each sub-step, from `server/`:

```
vendor/bin/phpunit
```

and from the repository root:

```
scripts/test-ubuntu-22.04.sh
```

The Ubuntu 22.04 run also confirms that `openssl_x509_parse()` and `stream_socket_client('ssl://...')` behave the same on PHP 8.1 and OpenSSL 3.0.

Manual check after 9.2, in development, with an enrolled client. In each terminal, from the repository root:

```
source scripts/dev-env.sh
```

Make a fake certbot tree with a certificate expiring in 10 days:

```
mkdir -p client/var/letsencrypt/live/example.test
```

```
openssl req -x509 -newkey rsa:2048 -nodes -keyout /dev/null -subj /CN=example.test -addext subjectAltName=DNS:example.test,DNS:www.example.test -days 10 -out client/var/letsencrypt/live/example.test/cert.pem
```

Run the scanner on it:

```
client/bin/maguari-certificate-scanner
```

```
cat client/var/certificates.json
```

Then send a heartbeat and look at the stored run:

```
client/bin/maguari-client heartbeat
```

```
sqlite3 server/var/dev.sqlite "SELECT metric, value, start_at, end_at FROM monitoring_metric_runs WHERE metric LIKE 'certificate_expires_at:%'"
```

Check the unit files (`verify` warns that `/usr/bin/maguari-certificate-scanner` does not exist yet, which is expected before packaging):

```
systemd-analyze verify client/systemd/maguari-certificate-scanner.service client/systemd/maguari-certificate-scanner.timer
```

Manual check after 9.3: in `server/`, run `bin/maguari-server run-daily-job`. Expected: a "Fail" line for `example.test` with 9 or 10 days left (depending on the time of day). The dashboard's "Certificates" column shows "Fail".

Manual check after 9.4: open an instance's page, add a hostname you run with a valid certificate, plus `expired.badssl.com`, `wrong.host.badssl.com` and `self-signed.badssl.com`, press "Run now" and check each result: pass, expired, not trusted or wrong name, not trusted or wrong name. Add `does-not-exist.invalid` to see "Could not connect".

## Answers

1. **Split:** 9.1 to 9.4 in this order, refactor first, with a review after each.
2. **D1:** `Domain/`, `Application/` and `Infrastructure/`, with a test keeping `Domain/` free of database and network code.
3. **D3:** the scanner writes to its own root-owned directory, `/var/lib/maguari-certificate-scanner/`. Design 6.1.1 is updated.
4. **D8:** the local check considers only certificates reported in the last 24 hours, so a removed certificate does not fail forever.
5. **D7:** 14 days for now, for both checks. It becomes a setting when 45-day certificates arrive (noted in design 6.3).
6. **D14:** "Run now" stays synchronous, remote checks use a 5 second TLS timeout and the `504` limit stays documented.
7. **Everything else** approved as proposed.
