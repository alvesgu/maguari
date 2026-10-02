# MVP Step 8: Daily scheduled job with a "Run now" button

## Context

Step 7 (`docs/plans/mvp-step-7.md`) delivered disk readings: every 60 seconds the client sends `disk_used_bytes:<mount point>` and `disk_total_bytes:<mount point>` for each real local filesystem, and Monitoring stores them as runs in `monitoring_metric_runs`. Nothing reads them back yet.

Step 8 is: "Daily scheduled job with a 'Run now' button" (design section 15). Design 6.3 says the job runs slow or daily-by-nature checks:

- Certificate expiry (local and remote)
- Compute Engine disk size compared with the filesystem size reported by the client (a grown disk whose filesystem was never extended)

Design 10.1 says scheduled work runs from systemd timers. Design 2.1 puts "checks and their results, the daily job" in Monitoring.

This step needs:

1. **The job itself** in Monitoring: one entry point, used by both the timer and the button, that runs the checks and stores their results.
2. **The first check**: the disk size comparison, which needs disk sizes from the Compute Engine API (Fleet) and filesystem sizes from the stored runs (Monitoring).
3. **Scheduling**: a CLI command, and a systemd service and timer for production.
4. **The dashboard**: the latest results per instance and the "Run now" button.

Relevant design sections: 2.1 (contexts and the rules between them), 6.1.1 (certificate scanner), 6.2 and 6.3 (server-side checks, daily job), 8 (GCP integration), 9.1 (runs), 10.1 (stack), 10.2 and 11.3 (routes, CSRF, no state-changing GET) and 12.2.1 (CLI subcommands).

## Certificates: now or later? (confirmed: a new step 9)

The disk size check uses only data that already exists. The certificate checks do not:

| Certificate check | What it needs that does not exist yet |
|---|---|
| Local (design 6.1.1) | The root-owned scanner, its systemd timer and the certbot deploy hook, all shipped by the client package (design 12.1), which does not exist: the client runs from a source checkout. A file format for `certificates.json`. A wire format for sending expiry dates in the heartbeat (`checks`, accepted and ignored so far, or readings such as `certificate_expires_at:<domain>`). A new client release. |
| Remote (design 6.2) | A list of hostnames to connect to for each instance. Per-instance check configuration (design 8.1 item 4) does not exist. The hostnames could come from the local scanner's certificates, which ties remote to local. A TLS client in `Kernel/` that reads the served certificate. |

Each of those is a decision of its own, about as large as step 7. **Split out (confirmed).** Step 8 builds the job, the schedule, the button and the disk size check, so the job is proven end to end with one real check. Certificates become a new MVP step right after this one ("Certificate expiry: the client's certificate scanner and local and remote checks in the daily job"), with its own plan, and steps 9 and 10 become 10 and 11. The scanner's timer and deploy hook are packaging work, so in that step the scanner would run by hand (as root) in development, the way the client runs from the source tree now.

*Alternative (not chosen):* put the certificate step after the MVP, next to packaging, since the local check cannot work unattended without the client package. Goal 1 (design section 1) names certificates in the daily screen, and the remote check does not depend on packaging.

## Is this too large for one step?

Without certificates it is moderate. It still has two halves with separate decisions (what the job checks and how it is scheduled; what the dashboard shows and how the button behaves). **Split (confirmed)**, each one commit that passes both test suites:

| Sub-step | Delivers | Depends on |
|---|---|---|
| **8.1** The job and the disk size check | Disk sizes from Fleet, the check rule, job and result tables, `maguari-server run-daily-job`, the systemd service and timer files | Step 7 |
| **8.2** Dashboard and "Run now" | Latest results on the dashboard, the job's last run, the button | 8.1 |

8.1 is fully usable from the CLI. Implementation stops for review after 8.1.

## Interpretations (confirmed)

**I1. Results are shown, not acted on.** A failed check appears on the dashboard and in the database. No incident, no alert and no notification: Remediation and Notifications have no code yet, and email is a later step. A grown disk with an unextended filesystem is not something remediation could fix anyway.

**I2. One job, two triggers.** The timer and the button run exactly the same code (`MonitoringApi::runDailyJob()`), so "Run now" proves what the timer will do. The only difference is the recorded trigger (`scheduled` or `manual`).

**I3. The seed config check stays out.** Design 10.3 says the daily job also checks whether the seed config file still exists. That check produces a notification (design 10.4), and notifications do not exist yet. It joins the job when notifications arrive.

**I4. Monitoring stays flat.** The step 7 decision (design 3.1 item 7) said layers are decided "when checks arrive and Monitoring has more than one concern." This step brings the first check, but it is small: one rule class, one job class and two repositories. Monitoring stays flat once more; the layers are decided with the certificate step, when Monitoring has two kinds of checks and two sources of input.

## Decisions (confirmed)

All interpretations and decisions were approved as proposed, with these answers: certificates become a new step 9 and the later steps are renumbered; the step is split into 8.1 and 8.2 with a review between; the disk size check compares the boot disk only, with the 10% rule; the timer runs at 06:00 UTC with its unit files in `server/systemd/`; "Run now" runs inside the request, with the nginx limit noted in D9; Monitoring stays flat. Covering attached disks is recorded in the design as required before 1.0.

### The disk size check (8.1)

**D1. Disk sizes come from the instance list Fleet already reads.** The Compute Engine instance resource lists its attached disks (`disks[]`), each with `boot`, `deviceName` and `diskSizeGb`. So one aggregated instances call per project (the call design 8.1 already uses, up to 10 pages) gives every picked instance's disk sizes, with no extra permission and no call per disk. `compute.disks.get`, already in the custom role (design 8), stays unused for now.

*Verified (2026-10-02)* against a real project with the aggregated call Fleet uses: each instance has `disks[]`, and the boot disk reads `{"deviceName": "instance-1", "boot": true, "diskSizeGb": "15", ...}`. `diskSizeGb` is a decimal string (int64 in JSON), like the instance `id`. No `compute.disks.get` fallback is needed.

- `ComputeEngine` reads `disks[]` into Fleet's own model (design 2.1 rule 4). `diskSizeGb` is in GiB (Compute Engine's "GB" is 2^30 bytes), so Fleet converts it to bytes and no other context sees the API's unit.
- New `FleetApi::diskSizes(): array<int, InstanceDisks|ProjectAccessProblem>`, keyed by Fleet instance ID, for every picked instance: one listing per project that has picked instances, matched by zone and name (the key in `fleet_instances`, so an instance recreated under the same name still matches). An instance missing from the listing gets "not found". A project whose listing fails gives every one of its instances that project's problem, using the fixed sentences of design 8.1 item 1.
- Not inside a transaction: the API calls take seconds (same reason as `addProject`).

**D2. Only the boot disk is compared.** The client reports mount points and sizes but not which disk each filesystem lives on, so a filesystem on an attached disk cannot be matched to its disk. The boot disk can: on Compute Engine Ubuntu images, `/`, `/boot` (24.04 images) and `/boot/efi` live on it. A grown boot disk is also the common case (small instances that run out of space).

*Required before 1.0* (design section 17): the client reports each filesystem's Compute Engine device name (likely from the `google-<deviceName>` links in `/dev/disk/by-id/`), which matches `deviceName` in D1 and covers attached disks. That is a client change and a new wire field, so not now.

**D3. The rule.** For each picked instance, with the boot disk's size `D` (bytes, from D1) and the sum `F` of the current `disk_total_bytes` runs for `/`, `/boot` and `/boot/efi` (whichever exist):

| Situation | Result |
|---|---|
| `D - F` is at most 10% of `D` | **Pass** |
| `D - F` is more than 10% of `D` | **Fail**, with both sizes: "The boot disk is 20.0 GiB, but its filesystems total 9.6 GiB. Rebooting usually extends them (cloud-init); otherwise run growpart and resize2fs." |
| No `disk_total_bytes:/` run that ended in the last 24 hours | **Not checked**: "No disk readings in the last 24 hours." |
| The Compute Engine listing failed, the instance was not found or it has no boot disk | **Not checked**, with Fleet's fixed sentence |

- Why 10%: a filesystem is always smaller than its disk (partition table, the EFI partition, ext4's inode tables and journal). On a 10 GiB Ubuntu boot disk, `/` plus `/boot/efi` total about 9.6 GiB, about 4% less. Growing a 10 GiB disk to 11 GiB leaves about 13% unaccounted for, so even a 1 GiB growth of the smallest Ubuntu disk fails. A 100 GiB disk grown by less than about 6 GiB would pass, which is acceptable: the check is for forgotten resizes, not exact accounting.
- Known false positive: a boot disk with an extra partition the client does not report (for example swap). The message shows both sizes, so the cause is visible. Rare on Compute Engine.
- The rule is a pure class (`DiskSizeRule`), like `RunRule`, with no API or database access. The 10% and 24 hours are constants in it.
- Monitoring calls `FleetApi` (design 2.1 rule 1) and reads only its own runs. It checks every picked instance and does not ask Clients which ones are enrolled: an instance without readings is "Not checked".

### The job and its results (8.1)

**D4. Two tables** in `server/src/Monitoring/Migrations/0002_monitoring_daily_job.sql`:

```
monitoring_daily_job_runs(
    id INTEGER PRIMARY KEY,
    trigger TEXT NOT NULL,          -- 'scheduled' or 'manual'
    started_at INTEGER NOT NULL,
    finished_at INTEGER             -- NULL while running, or if it crashed
)

monitoring_check_results(
    id INTEGER PRIMARY KEY,
    job_run_id INTEGER NOT NULL REFERENCES monitoring_daily_job_runs (id),
    instance_id INTEGER NOT NULL,   -- Fleet's instance ID, no foreign key (design 2.1)
    check_name TEXT NOT NULL,       -- 'disk_size' for now
    outcome TEXT NOT NULL,          -- 'pass', 'fail' or 'not_checked'
    detail TEXT NOT NULL,           -- the fixed sentence shown on the dashboard
    checked_at INTEGER NOT NULL
)
```

- Every run and its results are kept: about one row per instance per check per day (a few thousand a year for 10 instances), so no retention is needed now. The history will serve the Logs screen later.
- `detail` holds only Maguari's own sentences and sizes, never raw API messages (design 8.1 item 1).
- Check results are not readings, so the "readings as runs" rule does not apply to them.

**D5. One job at a time.** In one `BEGIN IMMEDIATE` transaction, the job inserts its run row only if no run has `finished_at` NULL and `started_at` within the last 15 minutes; otherwise it refuses ("The daily job is already running, started at 06:00 UTC."). This covers the timer and the button starting at the same moment, as well as a double click. A run that crashed leaves `finished_at` NULL and stops blocking after 15 minutes. The checks then run outside the transaction (D1), the results are written in one transaction and `finished_at` is set.

**D6. CLI command `maguari-server run-daily-job`** (design 12.2.1):

- Runs the job with trigger `scheduled` and prints one line per instance and check, then a summary.
- Exit code 0 when the job ran, whatever the check outcomes (a failed check is a result, not a job failure). Exit code 1, with one line on stderr, when the job could not run: already running, database missing or not migrated (it names `migrate`, like the web app's 503) or any other error. So `systemctl status` shows a failure only when the job itself failed.
- Refuses to run as root, like `migrate` (the database would end up with root-owned WAL files).
- Builds `FleetApi` and `MonitoringApi` from the environment the same way `App` does (`MAGUARI_DATABASE`, `MAGUARI_GCP_CREDENTIALS`). It needs no secret key: neither context decrypts anything here.

### Scheduling in production (8.1)

**D7. A systemd service and timer** (design 10.1), installed by packaging later:

`maguari-server-daily-job.service`:

```
[Unit]
Description=Maguari daily job

[Service]
Type=oneshot
User=maguari-server
Group=maguari-server
ExecStart=/usr/bin/maguari-server run-daily-job
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
ProtectSystem=strict
ReadWritePaths=/var/lib/maguari
```

`maguari-server-daily-job.timer`:

```
[Unit]
Description=Run the Maguari daily job every day

[Timer]
OnCalendar=*-*-* 06:00:00 UTC
Persistent=true

[Install]
WantedBy=timers.target
```

- **06:00 UTC** (02:00 or 03:00 in Brazil, early morning in Europe), so results are ready before the administrator's daily look. Easy to change.
- `Persistent=true` runs a missed job after the server instance was off at 06:00.
- `Type=oneshot` with no overlap: systemd never starts the service while it is still running, and D5 covers the button.
- The hardening lines keep the job read-only everywhere except the data directory (the database and its WAL files). `/etc/maguari/` stays readable. The metadata server is reached over the network, which these lines do not restrict.
- **Where the files live:** `server/systemd/`, because `server/debian/` does not exist yet and two unit files alone would not make a package. Packaging moves or installs them from there. *Alternative:* create `server/debian/` now with just these two files.
- Packaging is not an MVP step (design 12.4), so in this step the files are written and checked (`systemd-analyze verify` in Verification) but not installed by anything.

**D8. Development: run the command, or press the button.** There is no timer in development. From `server/`, with `source ../scripts/dev-env.sh` loaded:

```
bin/maguari-server run-daily-job
```

It uses your Application Default Credentials (design 8), like the project pages. Nothing runs it on a schedule in development; a user timer (`systemd-run --user --on-calendar=...`) would work but is not worth documenting for a once-a-day job.

### Dashboard and "Run now" (8.2)

**D9. The button.** A form on the dashboard (`/admin`) posting to `POST /admin/daily-job` with the CSRF token (design 10.2, 11.3: no state-changing GET).

- It runs the job **synchronously** in the request, with trigger `manual`, then redirects (`303`) to `/admin`, which shows the results. The job makes one API call per project with picked instances (D1) plus a few SQLite queries, so it takes about a second per project; the existing HTTP timeouts bound each call.
- If the job is already running (D5), the redirect still happens and the dashboard says it is running.
- **Known limit:** nginx's usual proxy timeout for FastCGI (`fastcgi_read_timeout`, 60 seconds by default) bounds the request. Each project costs one listing of up to 10 pages, so with many projects, or slow Compute Engine answers, the button's request could be cut off while the job goes on in php-fpm. The run would still finish and be stored, but the administrator would see a `504`. If that happens, queued runs (the alternative below) are the fix.
- *Alternative (the fix for the limit above):* the button only records a request, and a timer that runs every minute picks it up. More robust once remote certificate checks make the job slow (one TLS connection per hostname), but it needs a second timer, which does not exist in development, and up to a minute of waiting. Not recommended now; revisit with the certificate step if the job becomes slow.

**D10. What the dashboard shows.**

- A "Daily job" section above the instances table with the "Run now" button and one status line: "Last run: 2026-10-02 06:00 UTC (scheduled), took 2 seconds", "Never run" or "Running since ...". When the last finished run is more than 25 hours old, it adds "The daily job is overdue. Check the maguari-server-daily-job timer." (in development this always shows, which is accurate).
- A "Disk size" column in the instances table with each instance's latest result: "Pass", "Fail" or "Not checked", with the detail sentence in a `title` tooltip and, for "Fail", also below the table so it is readable without hovering.
- Read from SQLite only, like the rest of the dashboard: opening it never calls the Compute Engine API.
- New `MonitoringApi::lastDailyJobRun(): ?DailyJobRun` and `MonitoringApi::latestCheckResults(int[] $instanceIds): array<int, CheckResult>`. `AdminController` gains `MonitoringApi`; `App` passes it in.

## Out of scope for this step

- Certificate checks, local and remote (proposed as their own step, above).
- Attached disks in the disk size check (D2).
- The seed config check and any notification, incident or alert (I1, I3).
- Installing the systemd units (packaging).
- Configuring the job's time or which checks run, in the web app.
- Retention of job runs and results.
- Monitoring's internal layers (I4).

## Repository changes

### 8.1 The job and the disk size check

```
server/
  bin/maguari-server                        run-daily-job (D6)
  systemd/
    maguari-server-daily-job.service        (D7)
    maguari-server-daily-job.timer          (D7)
  src/
    Fleet/
      FleetApi.php                          diskSizes() (D1)
      InstanceDisks.php                     boot disk size and other disks, in bytes
      AttachedDisk.php                      device name, boot, size in bytes
      DiscoveredInstance.php                carries its disks
      Gcp/ComputeEngine.php                 reads disks[] (D1)
    Monitoring/
      MonitoringApi.php                     runDailyJob() (D5)
      DailyJob.php                          runs the checks
      DiskSizeRule.php                      pass, fail or not checked (D3)
      CheckOutcome.php                      Pass, Fail, NotChecked
      CheckResult.php
      DailyJobRun.php
      DailyJobTrigger.php                   Scheduled, Manual
      DailyJobRepository.php                SQL only (D4, D5)
      MetricRunRepository.php               current runs for several metrics
      Exception/DailyJobAlreadyRunning.php
      Migrations/0002_monitoring_daily_job.sql
  tests/
    Fleet/ComputeEngineTest.php             disks[] parsed, GiB to bytes
    Fleet/FleetApiTest.php                  diskSizes(): matching, per-project failures
    Monitoring/DiskSizeRuleTest.php
    Monitoring/DailyJobTest.php
    Cli/MaguariServerCommandTest.php        run-daily-job
    Kernel/Database/MigratorTest.php        the new migration
docs/DESIGN.md
```

### 8.2 Dashboard and "Run now"

```
server/
  src/
    Monitoring/MonitoringApi.php            lastDailyJobRun(), latestCheckResults() (D10)
    Http/App.php                            route, MonitoringApi for the dashboard
    Http/Controller/AdminController.php     shows results; runDailyJob() (D9)
  templates/admin.php                       Daily job section, Disk size column
  public/assets/maguari.css                 if the new section needs styles
  tests/
    Http/DailyJobFlowTest.php
docs/DESIGN.md
```

## Flow

1. At 06:00 UTC, systemd starts `maguari-server-daily-job.service`, which runs `maguari-server run-daily-job` as `maguari-server`. Or an administrator presses "Run now", which posts to `/admin/daily-job`.
2. `MonitoringApi::runDailyJob()` claims the run (D5) or refuses.
3. It asks `FleetApi::diskSizes()` for every picked instance's boot disk size: one Compute Engine listing per project.
4. For each instance, it reads the current `disk_total_bytes` runs of `/`, `/boot` and `/boot/efi` and applies `DiskSizeRule`.
5. It stores the results in one transaction and marks the run finished.
6. The CLI prints the results; the button redirects to the dashboard, which shows them.

## Tests

No test touches the network. Compute Engine responses come from the fake HTTP client, as in step 4.

**8.1**
1. `ComputeEngine`: `disks[]` is read with boot flag, device name and size in bytes; a missing or malformed `diskSizeGb` gives no size, not an error for the whole listing; instances without `disks` still list (the project page keeps working).
2. `FleetApi::diskSizes()`: one call per project with picked instances, none for projects without; matching by zone and name; an instance missing from the listing; one project failing while another succeeds.
3. `DiskSizeRule`: exactly 10% passes, just above fails; `/boot` and `/boot/efi` are added when present; no `/` reading, a reading older than 24 hours, no boot disk and a Fleet problem each give "Not checked" with the right sentence.
4. Daily job: results are stored per instance with the run; a second run while one is running is refused; a run left unfinished for more than 15 minutes no longer blocks; a crash during the checks leaves no results and the run unfinished.
5. CLI: prints results and exits 0 with failing checks; exits 1 with one line when already running or the database is not migrated; refuses root.
6. Migration applies on top of step 7's.

**8.2**
1. `POST /admin/daily-job` without a session redirects to login; without the CSRF token is rejected; with both runs the job and redirects `303` to `/admin`. `GET /admin/daily-job` is `405`.
2. The dashboard shows "Never run", the last run, "Running since", the overdue warning and each instance's disk size result and detail.
3. Opening the dashboard makes no Compute Engine call.

## Changes to `docs/DESIGN.md`

- **This plan's answers:** section 15, step 8 names the disk size check and links this plan, a new step 9 for certificates, the later steps renumbered; new section 17, "Required before 1.0" (the roadmap becomes section 18), with attached disks in the disk size check.
- **8.1:** section 6.3 (which disk is compared and the rule, D2 and D3; certificates arrive in their own step); section 8 (disk sizes come from the instance listing, D1); section 9.2 (the job and result tables, D4); section 12.2.1 (`run-daily-job`, D6); section 10.1 or 12.1 (the timer, its time and where its files live, D7).
- **8.2:** section 6.3 ("Run now" runs synchronously, D9) and the dashboard contents (D10).
- **8.1:** section 3.1 item 7 (Monitoring stays flat until the certificate step, I4).

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

Manual check after 8.1, in development, with an enrolled client that has been running for a few minutes. In `server/`, after loading the environment:

```
source ../scripts/dev-env.sh
```

```
bin/maguari-server migrate
```

```
bin/maguari-server run-daily-job
```

Expected: one line per picked instance, "Pass" for an instance whose boot disk was never resized. To see a "Fail", grow a test instance's boot disk in the console without rebooting it, then run the command again.

Check the unit files (from the repository root; `verify` warns that `/usr/bin/maguari-server` does not exist yet, which is expected before packaging):

```
systemd-analyze verify server/systemd/maguari-server-daily-job.service server/systemd/maguari-server-daily-job.timer
```

```
systemd-analyze calendar "*-*-* 06:00:00 UTC"
```

Manual check after 8.2: start the app (`php -S localhost:8080 -t public` in `server/`), sign in, press "Run now" on the dashboard and check that the Daily job section and the Disk size column update.

## Answers

1. **Certificates:** a new step 9 right after this one; the later steps are renumbered.
2. **Split:** 8.1 and 8.2, with a review between.
3. **D2 and D3:** boot disk only, with the 10% rule. Attached disks are required before 1.0 (design section 17).
4. **D7:** 06:00 UTC, unit files in `server/systemd/`.
5. **D9:** "Run now" runs inside the request; nginx's usual 60 second limit is noted, with queued runs as the fix.
6. **I4:** Monitoring stays flat.
7. **Everything else** approved, including the design changes. D1 was verified against a real project before implementing.
