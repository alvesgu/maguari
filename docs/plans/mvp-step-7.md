# MVP Step 7: Receive disk used and total every minute and store them as runs

## Context

Step 6 (`docs/plans/mvp-step-6.md`) delivered enrollment, signed heartbeats and the client. Every 60 seconds the client sends `POST /api/client/heartbeat` with `readings`, `checks` and `command_results` as empty lists. The server checks that they are lists and ignores them (`Clients\HeartbeatRequest`). It records the server's receive time as the last heartbeat and answers `204`.

Step 7 is now: "Receive disk used and total every minute and store them as runs (section 9.1)" (design section 15). It merges the former steps 7 and 10, so readings are never stored any other way. It needs:

1. **The client measures disk used and total** per filesystem and puts them in `readings`.
2. **A wire format for readings** that both sides agree on, in `shared/`.
3. **The Monitoring context's first code**: validating readings and storing them as runs in `monitoring_metric_runs`.
4. **The hand-off from Clients to Monitoring** (design 2.1, "Flow of one failing heartbeat", step 1).

Relevant design sections: 2.1 (contexts and the rules between them), 3.1 (layout and migrations), 4 (versioning), 5.2 (heartbeat), 5.4 (client privileges), 6.1 (client-side checks), 9 and 9.1 (storage, runs) and 14 (egress).

## Is this too large for one step?

It is smaller than step 6, but it still has two independent halves, each with its own decisions (how runs are stored on the server; which filesystems the client measures and how). **Split (confirmed)**, each one commit that passes both test suites:

| Sub-step | Delivers | Depends on |
|---|---|---|
| **7.1** Store readings as runs | `shared/` metric names, the Monitoring context (`MonitoringApi`, `monitoring_metric_runs`), the hand-off from Clients, validation | Step 6 |
| **7.2** The client measures disk usage | Reading `/proc/self/mounts`, measuring each filesystem, sending the readings; end-to-end test | 7.1 |

7.1 is fully testable with hand-built signed heartbeats. Old clients (empty `readings`) keep working throughout. Implementation stops for review after 7.1.

## Interpretations (confirmed)

**I1. Receive and store only.** No dashboard display, no percentages, no thresholds, no alerts and no incidents. Reading the runs back is step 10. The only way to see the stored runs in this step is the database itself (see Verification).

**I2. "Every minute" is the existing heartbeat.** Readings travel in every heartbeat (design 5.2, 6.1). There is no separate schedule. The interval stays fixed at 60 seconds (`Protocol::HEARTBEAT_INTERVAL_SECONDS`).

**I3. Monitoring keeps the flat structure of the other contexts.** Design 3.1 item 7 says the internal structure of each context is "decided in a later design step, starting with the core contexts." Monitoring is core, and this is its first code, so this is the natural moment. Layers are **not** decided yet: one context folder like Fleet and Clients (a public `MonitoringApi`, small classes, `Migrations/`), with the run rule written as a pure class with no database access, so it is tested on its own. Layers are decided when checks arrive and Monitoring has more than one concern.

## Decisions (confirmed)

All interpretations and decisions were approved as proposed, with these answers: D7 is option B (the deadband, measured against the value that started the run, applied to disk used only, with total kept exact); the step is split into 7.1 and 7.2 with a review between; Monitoring stays flat with the run rule as a pure class (I3); and the gap limit in D6 is computed as 1.5 times the expected interval, never hard-coded.

### Wire format (7.1)

**D1. Each reading is one metric and one value.** In the heartbeat body:

```
"readings": [
    {"metric": "disk_used_bytes:/", "value": 8123456512},
    {"metric": "disk_total_bytes:/", "value": 10213466112},
    {"metric": "disk_used_bytes:/boot", "value": 112345088},
    {"metric": "disk_total_bytes:/boot", "value": 919158784}
]
```

- The glossary defines a metric as "the kind of thing measured (for example disk used on `/`)", so the metric string names both the kind and the filesystem: `<kind>:<mount point>`. The same string is stored in `monitoring_metric_runs.metric`, so the wire format, the table and the glossary say the same thing.
- The kind never contains `:`, so splitting at the **first** `:` is unambiguous even for mount points that contain one.
- Kinds in this step: `disk_used_bytes` and `disk_total_bytes`. Values are integer bytes. The unit is in the name so later metrics cannot be misread.
- Kind names and the separator live in `shared/src/Metric.php`, used by both sides.
- Readings carry no timestamp of their own (D4).
- Flat objects keep the body within the existing JSON depth limit of 4 (`RequestFields::decodeObject`).

*Alternative:* structured readings (`{"metric": "disk_used_bytes", "mount": "/", "value": ...}`). Clearer per field, but `mount` only makes sense for disks, and the server would have to build the stored key anyway. Not recommended.

**D2. No protocol version change.** `readings` was already an optional list that the server accepted (design 4 item 4: adding optional data old peers can ignore is not breaking). Clients 0.1.0 keep sending empty lists and keep working. `client/VERSION` becomes `0.2.0` in 7.2.

### Validation (7.1)

**D3. What the server accepts.** Monitoring validates the `readings` list that Clients hands over:

| Input | Result |
|---|---|
| An item that is not an object, or has no string `metric` or no integer `value` | `bad_request` |
| A `value` below 0 | `bad_request` |
| An unknown kind (for example `load_average:1m` from a newer client) | Ignored, so newer clients keep working with older servers |
| A known disk kind whose mount point does not start with `/`, is longer than 1,024 bytes or contains control characters | `bad_request` |
| The same metric twice in one heartbeat | `bad_request` |
| More than 100 readings | `bad_request` (bounds the database writes per heartbeat; the client sends at most 40, D11) |
| `disk_used_bytes` without a `disk_total_bytes` for the same mount point in the same heartbeat | `bad_request` (the deadband needs the total, D7) |

**Nothing is written when the heartbeat is rejected**, the last heartbeat time included. A rejected heartbeat makes the instance show "Late", which is visible, and the client logs a sentence for `bad_request` as it already does.

### Storage (7.1)

**D4. The time of a reading is the server's receive time**, the same clock as `last_heartbeat_at` (design 5.2). The client's `sent_at` is not trusted for storage: it can be up to 5 minutes off and still pass the clock check. The client never resends skipped heartbeats, so readings never arrive late in bulk.

**D5. `monitoring_metric_runs` table** (design 9.1), in `server/src/Monitoring/Migrations/0001_monitoring_metric_runs.sql`:

```
monitoring_metric_runs(
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,
    metric TEXT NOT NULL,
    value NUMERIC NOT NULL,
    start_at INTEGER NOT NULL,
    end_at INTEGER NOT NULL
)
CREATE INDEX monitoring_metric_runs_series ON monitoring_metric_runs (instance_id, metric, start_at);
```

- `instance_id` is `fleet_instances.id`, with no foreign key (design 2.1 rules 2 and 3). Runs belong to the instance, not the client, so re-enrolling keeps the history.
- `value` is `NUMERIC` so later metrics can be fractional. Disk values are integers and stay integers in SQLite.
- The index serves both finding the current run (D6) and the time-range reads of step 10.

**D6. The run rule, exactly.** For each reading of metric `m` on instance `i` at server time `t`, the current run is the one with the latest `start_at` (ties broken by the highest `id`):

1. No current run: insert a run with `start_at = end_at = t`.
2. `t < end_at` of the current run (the server's clock stepped back): ignore the reading, so runs never overlap or go backwards.
3. The value is within the deadband of the run's value (D7; exact equality for every kind but `disk_used_bytes`) and `t - end_at` is at most 1.5 times the expected interval: set `end_at = t`. The run keeps its value.
4. Otherwise (a value outside the deadband, or a longer gap): insert a new run with `start_at = end_at = t`.

- The gap limit is **computed** as 1.5 times the expected interval (design 9.1 item 3), never hard-coded. `RunRule` takes the expected interval as a constructor argument and compares `2 * gap <= 3 * interval` in integers. `MonitoringApi::EXPECTED_INTERVAL_SECONDS` is `Protocol::HEARTBEAT_INTERVAL_SECONDS` (60) for now, so the limit is 90 seconds today, the same as "On time" in step 6: a gap of exactly 90 seconds continues the run and 91 seconds starts a new one.
- All readings of one heartbeat are applied in one `BEGIN IMMEDIATE` transaction, so two concurrent requests from the same instance cannot both extend or both insert.
- The rule is a pure class (`RunRule` or similar) that decides "extend, insert or ignore" from the current run and the reading. The repository only runs the SQL. Most tests then need no database.
- A filesystem that disappears (unmounted) simply stops getting runs. Nothing is deleted (design 9.1 item 4).

**D7. A deadband for disk used (confirmed: option B).** This was the one point where the design as written might not do what it intends.

Design 9.1 starts a new run whenever a reading differs from the current run's value. Disk total never changes, so it compresses perfectly. **Disk used in bytes changes on almost every heartbeat** on any instance that writes logs, so nearly every reading becomes its own run. Rough numbers for 10 instances with 2 filesystems each: about 29,000 rows per day and about 10 million per year, roughly 0.5 to 1 GB per year with the index. The step 10 reads get slower with it. The "report by exception" idea the design cites from industrial historians normally includes a **deadband** for exactly this reason.

| Option | Rule for `disk_used_bytes` | Effect |
|---|---|---|
| **A. As written** | New run on any change | Exact values. Little compression for disk used. No design change. |
| **B. Deadband (recommended)** | Continue the run while the new value is within 0.1% of the filesystem's total from the run's value; the run keeps its first value | Stored value is at most 0.1% of total off (10 MB on a 10 GB disk), irrelevant for percentage alerts. A disk growing 1% a day makes about 10 runs a day instead of 1,440. The gap rule (D6 item 3) is unchanged, so outages stay visible. |
| C. Round on the client | Client rounds used to a fixed size | Same compression, but the policy is baked into each client release and the server cannot tighten it later. Not recommended. |

**Decision: B.** The deadband is a property of the metric kind, chosen by Monitoring: `disk_used_bytes` gets 0.1% of the `disk_total_bytes` reading for the same mount point in the same heartbeat (`intdiv(total, 1000)` bytes); every other kind (`disk_total_bytes` included) gets 0, which is the exact-equality rule of design 9.1, so total size stays exact. The deadband is measured against the **value that started the run**, not the latest reading, so a slow drift cannot stay in one run forever. D3 has the extra row for `disk_used_bytes` without a matching total. Design 9.1 item 1 describes the deadband.

### Hand-off from Clients to Monitoring (7.1)

**D8. Clients calls `MonitoringApi`**, as design 2.1 describes ("Clients receives the heartbeat, verifies the HMAC and passes readings and check results to Monitoring"). Monitoring does not know Clients exists. `ClientsApi::recordHeartbeat()`:

1. Parses the envelope as today (`HeartbeatRequest`), now keeping the raw `readings` list.
2. Calls `MonitoringApi::parseReadings(array $readings): Readings`, which validates (D3) and writes nothing. Its `InvalidReadings` is turned into `InvalidClientRequest`, so the controller is unchanged and still answers `bad_request`.
3. Records the last heartbeat time (one `UPDATE`, as today) and looks up the client's instance ID.
4. Calls `MonitoringApi::recordReadings(int $instanceId, int $at, Readings $readings)`, which applies D6 in its own transaction.

`Database::transaction()` does not nest, so steps 3 and 4 are two writes, not one transaction. Validation happens before either, so a malformed heartbeat writes nothing. Only a server error between them (a `500`) could leave a heartbeat time without its readings, and the next heartbeat is a minute away.

`ClientsApi`'s constructor gains a `MonitoringApi`. `App` builds `MonitoringApi` from the database and clock and passes it in; the readiness rule is unchanged (Monitoring's migration is one more pending migration).

*Alternative:* the controller calls Clients then Monitoring, as the enroll controller does with Fleet then Clients (step 6 D6). That puts the validation order in `Http/`, which should stay thin. Not recommended.

### The client (7.2)

**D9. Which filesystems.** The client reads `/proc/self/mounts` and keeps a mount when:

- its type is one of `ext2`, `ext3`, `ext4`, `xfs`, `btrfs` or `vfat` (real local filesystems on Ubuntu instances: `/`, `/boot` and `/boot/efi` on current GCE images, plus attached persistent disks), and
- its source is a device under `/dev/`.

This leaves out `tmpfs`, `proc`, `sysfs`, `overlay`, network filesystems and `squashfs` (snaps, which are always 100% full). A device mounted more than once (bind mounts) is reported once, at its first mount point in the file. Mount points are decoded from the file's octal escapes (`\040` for a space). At most 20 filesystems are reported, so at most 40 readings.

`/boot` is worth keeping: it filling up with old kernels is a common Ubuntu failure.

**D10. What "used" means.** PHP's `disk_total_space()` and `disk_free_space()` call `statvfs()` and need no extra privileges (design 5.4). `disk_free_space()` returns the space **available to unprivileged users**, so the client sends:

- `disk_total_bytes` = `disk_total_space()`
- `disk_used_bytes` = `disk_total_space() - disk_free_space()`

This counts ext4's root reserve (usually 5%) as used, so it is a little higher than the "Used" column of `df`. That is deliberate: it reaches the total exactly when unprivileged services get "No space left on device", and `df`'s "Use%" reaches 100% at the same moment. Reading the free block count that `df` uses would need `exec()` of `df` or `stat`, which the client avoids. The design will state this definition (section 6.1).

A filesystem whose measurement fails (for example a mount point the client's user cannot reach) is skipped for that heartbeat. If none can be measured, the heartbeat is still sent with an empty list: liveness matters more than readings.

**D11. Client code.** A `DiskUsage` class with the mounts file path and a small `FilesystemStats` interface injected, so tests use fixture files and fake sizes. It is read on every heartbeat (a few `statvfs` calls; cheap). `HeartbeatSender` gets its readings from it. Egress is unaffected: the readings are in the request, and the response stays `204` (design 14).

## Out of scope for this step

- Showing disk usage anywhere in the web app; percentages, thresholds, checks, incidents and alerts (I1).
- Reading runs through an API (step 10) and charts.
- Other metrics. `checks` and `command_results` stay accepted and ignored.
- The heartbeat-age check moving to Monitoring (design 5.2, "Temporary (MVP)").
- A configurable heartbeat interval and real-time mode. The gap limit is computed from the expected interval, which is the fixed 60 seconds for now; once intervals vary, Monitoring must use the interval in effect.
- Comparing the Compute Engine disk size with the filesystem size (daily job, design 6.3).
- Retention or deletion of runs.

## Repository changes

### 7.1 Store readings as runs

```
shared/
  src/Metric.php                            kind names and the ":" separator (D1)
  tests/MetricTest.php
server/
  src/
    Monitoring/
      MonitoringApi.php                     parseReadings(), recordReadings() (D8)
      Reading.php                           metric, value
      Readings.php                          validated list (D3)
      MetricRun.php                         the current run of a metric
      RunRule.php                           extend, insert or ignore (D6, D7)
      RunDecision.php                       Insert, Extend or Ignore
      MetricRunRepository.php               SQL only
      Exception/InvalidReadings.php
      Migrations/0001_monitoring_metric_runs.sql
    Clients/
      HeartbeatRequest.php                  keeps the raw readings list
      ClientsApi.php                        calls MonitoringApi (D8)
      ClientRepository.php                  instance ID by client ID
    Http/App.php                            builds MonitoringApi
  tests/
    Monitoring/RunRuleTest.php, Monitoring/ReadingsTest.php, Monitoring/MonitoringApiTest.php
    Clients/ClientsApiTest.php
    Http/HeartbeatFlowTest.php
    Kernel/Database/MigratorTest.php        the new migration
    Support/TestEnvironment.php             builds MonitoringApi
docs/DESIGN.md
```

### 7.2 The client measures disk usage

```
client/
  VERSION                                   0.2.0
  src/
    DiskUsage.php                           mounts, filtering, measuring (D9, D10)
    FilesystemStats.php                     interface
    StatvfsFilesystemStats.php              disk_total_space(), disk_free_space()
    HeartbeatSender.php                     sends the readings
    Cli.php                                 wiring
  tests/
    DiskUsageTest.php
    fixtures/mounts-*                       sample /proc/self/mounts files
    HeartbeatSenderTest.php
    EndToEndTest.php                        runs are stored
docs/DESIGN.md
```

## Flow

1. Every 60 seconds the client reads its mounts, measures each kept filesystem and sends the readings in a signed heartbeat.
2. The signature middleware verifies the request as in step 6.
3. `ClientsApi` parses the envelope; `MonitoringApi` validates the readings. Anything invalid: `400 bad_request`, nothing written.
4. `ClientsApi` records the heartbeat time, then hands the instance ID, the receive time and the readings to `MonitoringApi`.
5. `MonitoringApi` applies the run rule to each reading in one transaction. Response `204`.

## Tests

No test touches the network.

**7.1**
1. Run rule (pure): first reading inserts; same value at 60 and at exactly 90 seconds extends; 91 seconds inserts; the limit follows other intervals (300 and 15 seconds); a different value inserts; a reading older than `end_at` is ignored; the same second with a new value inserts. Deadband: a change within 0.1% of total from the run's first value extends and keeps the run's value, a larger change inserts even when it is close to the latest reading, and `disk_total_bytes` still needs exact equality.
2. Readings: every row of D3, including unknown kinds ignored, a mount point containing `:` and a value of 0.
3. `MonitoringApi`: runs are separate per instance and per metric; the current run is found by latest `start_at`, then `id`.
4. Heartbeat flow: a signed heartbeat with readings stores runs and answers `204`; a malformed reading answers `bad_request` and writes neither runs nor the heartbeat time; a heartbeat with empty `readings` still works (client 0.1.0).
5. Re-enrolling an instance keeps its runs.

**7.2**
1. Mounts parsing with fixture files: allowed types kept; `tmpfs`, `squashfs`, `overlay` and network types dropped; bind mounts reported once; `\040` decoded; at most 20.
2. Used is total minus free from the fake stats; a failing filesystem is skipped; no filesystems gives an empty list.
3. The heartbeat body carries the readings in the D1 format.
4. End to end: the real client sends a heartbeat to the real Slim app and runs appear for the instance. It uses a fixture mounts file and fake stats, because the test container's `/` is `overlay` and would report nothing.

## Changes to `docs/DESIGN.md`

- **This plan's commit:** section 15, steps 7 and 10 merged and the later steps renumbered.
- **7.1:** section 5.2 (the readings format and validation, D1 and D3; the reading time is the server's, D4); section 9.1 (the table and index, the exact run rule including the gap boundary and a clock going backwards, D5 and D6; the deadband, D7); section 3.1 item 7 (Monitoring flat for now, I3).
- **7.2:** section 6.1 (which filesystems and what "used" means, D9 and D10).

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

Manual check after 7.2, in development. In `server/`, after loading the environment:

```
source ../scripts/dev-env.sh
```

Apply the new migration:

```
bin/maguari-server migrate
```

```
php -S localhost:8080 -t public
```

In a second terminal, from the repository root, with an already enrolled client:

```
source scripts/dev-env.sh
```

```
client/bin/maguari-client run
```

After a few minutes, from the repository root in a third terminal (after `source scripts/dev-env.sh`), list the runs. This uses PHP because the `sqlite3` command is not installed:

```
php -r '$db = new PDO("sqlite:" . getenv("MAGUARI_DATABASE")); foreach ($db->query("SELECT instance_id, metric, value, start_at, end_at FROM monitoring_metric_runs ORDER BY metric, start_at", PDO::FETCH_NUM) as $r) { echo implode("  ", $r), "\n"; }'
```

Expected: one `disk_total_bytes` run per filesystem whose `end_at` keeps advancing, and `disk_used_bytes` runs that change only when used space moves more than 0.1% of the total from the run's value. Stop the client for two minutes and start it again: every metric gets a new run.

## Answers

1. **D7:** option B, the 0.1% deadband, measured against the value that started the run (not the latest reading), applied to disk used only, with total size kept exact. Design 9.1 updated.
2. **Split:** two sub-steps, 7.1 server and 7.2 client, with a review between.
3. **I3:** Monitoring stays flat, with the run rule as a pure class.
4. **Everything else** approved, with one correction to D6: the 90 second gap is computed as 1.5 times the expected interval (60 seconds for now), per design 9.1, not hard-coded.
