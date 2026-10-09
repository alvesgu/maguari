# Phase 1: Incidents and alerts

## Context

The MVP (design section 15) is finished. Phase 1 of the road to 1.0 (design section 17) turns check results into incidents, notifications and alert emails.

Decisions already recorded in the design:

- **7.4:** an incident is about one check of one instance and one subject; several can be open on one instance. A failed check opens one; "not checked" never does. Heartbeat age and the daily job's checks all qualify. It resolves only after its check has passed for 5 minutes. Incidents belong to Remediation, which gets layers like Monitoring (3.1 item 7); the incident is an aggregate.
- **2.1 rule 5:** contexts react to each other through domain events in an outbox, written in the same transaction as the change and delivered later, never inside a heartbeat or "Run now" request.
- **10.1:** one per-minute command, the tick, judges heartbeat ages in Monitoring (replacing `ClientsApi::LATE_AFTER_SECONDS`, 5.2) and then delivers pending events.
- **13.3:** one alert email when an incident opens, one when it resolves, a summary email instead when many instances fail at once (the circuit breaker's 50%, at least 2), sent to every administrator.
- **10.4:** the dashboard lists notifications of open incidents, each with "Hide"; a new Logs screen lists all of them.
- **16, 18 item 5:** Google sign-in is a phase 5 item.

This plan proposes how. The proposals were approved with three fixes (A, B and C), recorded where they apply; the answers are at the end.

## Steps

Each step is one commit that passes both test suites, followed by a review before the next step starts.

| Step | What | Visible result |
|---|---|---|
| 1.1 | Domain events and the tick | `maguari-server tick` runs and delivers events (none are written yet) |
| 1.2 | Check outcomes as events, and Monitoring's heartbeat-age check | The dashboard's "On time"/"Late" comes from Monitoring; check outcome changes are written as events |
| 1.3 | Incidents in Remediation | The tick opens and resolves incidents; the instance page lists them |
| 1.4 | Notifications on the dashboard and the Logs screen | Notifications with "Hide"; `/admin/logs` |
| 1.5 | Alert emails | Emails on open and resolve, the summary email, retries |

Steps 1.2 to 1.5 each depend on the one before. Phase 1 has no production install (deployment is phase 4). Events written in step 1.2 before Remediation subscribes in step 1.3 are delivered to nobody; Remediation's reconciliation (P7 item 6) opens the incidents they would have opened on the first tick after step 1.3. Running the daily job again would not, because unchanged results write no events.

## Proposals

### P1. The outbox lives in Kernel (step 1.1)

The outbox and its delivery are generic machinery with no Maguari meaning, so they belong in `Kernel/Events/`:

```
server/src/Kernel/Events/Event.php           type (string), payload (array), occurred_at
server/src/Kernel/Events/EventOutbox.php     record(Event): only inside an open transaction, else it throws
server/src/Kernel/Events/EventDelivery.php   delivers pending events to the subscribers it was given
server/src/Kernel/Events/Subscriber.php      interface: handles(type), handle(Event)
server/src/Kernel/Migrations/0001_kernel_events.sql
```

```
kernel_events(id, type, payload, occurred_at, delivered_at, failed_attempts)
```

1. `type` is `<context>.<name>`, for example `monitoring.check_failed`. `payload` is JSON with IDs and values only (design 2.1 rule 3).
2. Each context's published events are classes in `<Context>/Event/`, public like `Exception/`: they build an `Event` and read one back. Subscribers in other contexts use only those classes. Design 2.1 rule 1 and 3.1 item 7 get this.
3. Kernel never names a context: subscribers are handed to `EventDelivery` where the app is wired (`bin/maguari-server`), the same way the migration runner finds folders without naming them.
4. `kernel_events` is the second table without a context prefix, next to `kernel_migrations`. Design 3.1 item 2 gets this.

*Alternative:* one outbox table per publishing context (`monitoring_events`, `remediation_events`), each delivered through its own public interface. Stricter ownership, but three copies of the same machinery for no gain: an outbox has no context-specific meaning.

### P2. Delivery: one event, one transaction, in order (step 1.1)

1. The tick takes pending events (`delivered_at IS NULL`) in `id` order. For each, in one `BEGIN IMMEDIATE` transaction, every subscriber that handles its type runs and the event is marked delivered. Subscribers only write to the database (alerts are sent separately, P9), so each event's effects happen exactly once.
2. A subscriber may write new events in that transaction (Remediation does). The tick keeps delivering until nothing is pending, at most 1,000 events per tick, so a loop cannot run forever.
3. **A failing subscriber** (a bug) rolls its event back. The failure is logged (`The event 123 (monitoring.check_failed) failed: <class>: <message>`) and `failed_attempts` grows. Delivery stops at that event for this tick, so the order holds. After it has failed on 3 ticks it is skipped, so one bug cannot stop all alerts for good. Skipped events stay in the table (`delivered_at` NULL, `failed_attempts` 3) for diagnosis.
4. Delivered events are deleted 7 days after delivery, by the tick.

*Alternative to item 1:* a delivery row per subscriber, so each subscriber advances on its own. Only needed when a subscriber has side effects outside the database, and P9 keeps every subscriber inside it.

### P3. The tick command (step 1.1)

`maguari-server tick`, refusing root like the other database commands (design 12.2.1).

1. **Order:** (a) Monitoring judges heartbeat ages (from step 1.2); (b) events are delivered; (c) Remediation resolves incidents whose 5 minutes have passed and, once a day, reconciles (P7 item 6) (from step 1.3); (d) events are delivered again; (e) Notifications sends pending alerts (from step 1.5).
2. **One tick at a time:** an exclusive `flock()` on a lock file next to the database (`maguari.sqlite.tick-lock`). A second tick that finds it held exits with 0 and one line on stderr. The lock goes away with the process, so a killed tick blocks nothing, unlike a database row. systemd already never starts a running oneshot service twice; the lock covers manual runs.
3. **Output:** nothing when nothing happened, otherwise one line per delivered event, opened or resolved incident and sent alert, so the journal stays quiet. Errors as one line on stderr with exit code 1, never a stack trace.
4. **Units** in `server/systemd/` until packaging: `maguari-server-tick.service` (as the daily job's: `Type=oneshot`, as `maguari-server`, `ProtectSystem=strict`, only `/var/lib/maguari` writable) and `maguari-server-tick.timer` (`OnCalendar=*-*-* *:*:00 UTC`, `AccuracySec=1s`, no `Persistent=`: a missed minute is not worth running late).
5. **Development:** run it by hand, or every minute in a spare terminal:

```
while true; do server/bin/maguari-server tick; sleep 60; done
```

### P4. Monitoring records heartbeat times itself (step 1.2)

Monitoring needs each instance's last heartbeat. `ClientsApi` already depends on `MonitoringApi`, so Monitoring asking Clients would make a cycle.

Proposed: `ClientsApi::recordHeartbeat()` calls `MonitoringApi::recordHeartbeat($instanceId, $at, $readings)` (renamed from `recordReadings()`), which stores the time in Monitoring's own `monitoring_heartbeats(instance_id, last_at)` in the transaction that stores the runs, even when there are no readings. Clients keeps its own `last_heartbeat_at` and the client version for display. The two times are written in two transactions (they already are today for runs), so they can differ by milliseconds; Monitoring's is the one judged.

*Alternative:* the tick asks `ClientsApi` for last heartbeat times and passes them to `MonitoringApi`. No new table, but the tick then holds logic that joins two contexts.

### P5. The heartbeat-age check (step 1.2)

1. Check name `heartbeat_age`, empty subject, judged by a `Domain/HeartbeatAgeRule` with the instance's interval: fail when the last heartbeat is more than `RunRule`'s limit old (1.5 times the interval, 90 seconds today), pass otherwise. The interval is a per-instance lookup that returns `MonitoringApi::EXPECTED_INTERVAL_SECONDS` until intervals are configurable (design 7.3, 18 item 3).
2. Judged for every picked instance that has a row in `monitoring_heartbeats`. An instance that never sent a heartbeat is not checked. A re-enrolled instance whose new client has not reported yet is judged on the old client's last heartbeat, which is right: nothing is reporting.
3. **Server downtime is not instance downtime.** If the server was off, or the tick was not running, every heartbeat looks late on the first tick back, which would open an incident on every instance (and send a summary email). Monitoring stores the last tick time; when the previous tick ran more than 3 minutes ago, ages are measured from the time the ticks resumed, not from the last heartbeat. So an instance only fails after the server has been up and ticking for longer than the limit.
4. The dashboard and the project page show the heartbeat from `MonitoringApi::heartbeatStatuses()`: "No heartbeat yet", "On time" or "Late", the same words. `ClientsApi::LATE_AFTER_SECONDS`, `HeartbeatState` and the TEMPORARY notes go away. The page computes the state at view time with the same rule, so it does not wait for the tick.

### P6. Monitoring emits outcome changes, not every result (step 1.2)

1. Monitoring keeps the current outcome per instance, check and subject in `monitoring_check_states(instance_id, check_name, subject, outcome, since)`. A result that changes it (fail to pass, pass to fail, or a first pass or fail) updates the row and writes `monitoring.check_failed` or `monitoring.check_passed` in the same transaction. "Not checked" changes nothing and writes nothing.
2. The daily job writes them in the transaction that stores its results; the tick writes heartbeat-age ones in its own transaction. Neither delivers anything.
3. The payload is the instance ID, the check name, the subject, the time and the result's sentence (for the alert email, P9 item 4).
4. **Subjects that disappear.** A hostname removed from an instance, or a certificate no longer reported, would leave its incident open forever, because nothing ever passes again. When a successful daily run has no result for a subject that has a state row, Monitoring deletes the row and writes `monitoring.check_withdrawn`. Removing a hostname does the same at once. Remediation then closes the incident as withdrawn (P7 item 4).
5. **Fix B: only while the instance is reporting.** A certificate stops being reported both when it was removed and when the instance went silent. So a missing result withdraws its subject only while the instance's heartbeat-age check passes. When the instance is silent (heartbeat age failing, or no heartbeat at all), its missing results count as not checked: the state row and any incident stay. Removing a hostname is an administrator's decision and withdraws at once in either case.

*Alternative:* an event for every result, including every heartbeat-age pass each minute. Simpler and self-correcting, but about 14,000 events a day for 10 instances, almost all saying nothing new.

### P7. The incident aggregate (step 1.3)

```
server/src/Remediation/
  RemediationApi.php                  openIncidents(instanceIds), incidents(instanceId), resolveDue()
  Domain/Incident.php                 the aggregate: open, failedAgain, passed, resolveIfDue, withdraw
  Domain/IncidentKey.php              instance ID, check name, subject
  Domain/ResolutionRule.php           RESOLVE_AFTER_SECONDS = 300
  Event/IncidentOpened.php, Event/IncidentResolved.php
  Application/CheckEventSubscriber.php  reacts to Monitoring's events
  Infrastructure/IncidentRepository.php
  Migrations/0001_remediation_incidents.sql
server/tests/Remediation/LayersTest.php   the same rule as Monitoring's
```

```
remediation_incidents(id, instance_id, check_name, subject, opened_at, passing_since, resolved_at, resolution)
```

1. Unique open incident per key: a partial unique index on `(instance_id, check_name, subject) WHERE resolved_at IS NULL`.
2. `check_failed` with no open incident opens one and writes `remediation.incident_opened`. With one open, it clears `passing_since`.
3. `check_passed` sets `passing_since` on the open incident (if any). Step (c) of the tick resolves incidents whose `passing_since` is at least 5 minutes old, with `resolution` `passed`, and writes `remediation.incident_resolved`. A daily check that passes on "Run now" therefore resolves on one of the next ticks, without another run.
4. `check_withdrawn` resolves the open incident at once with `resolution` `withdrawn` and writes `remediation.incident_resolved` with that reason.
5. The instance page lists the instance's open incidents and the last 20 resolved ones, with the check, subject, times and resolution. The dashboard gets nothing yet: its notifications come in step 1.4.
6. **Fix A: daily reconciliation.** Events only on changes means an event lost to a bug (P2 item 3) or written before Remediation subscribed would leave Remediation and Monitoring disagreeing for good. Once a day, Remediation compares its open incidents with Monitoring's current failing states, read through a new `MonitoringApi::failingChecks()` (instance, check, subject and since):
   - A failing state with no open incident opens one, as `check_failed` would, and writes `remediation.incident_opened`.
   - An open incident whose key Monitoring does not report as failing is stale. If Monitoring reports it passing for at least 5 minutes, or not at all, it resolves with `resolution` `reconciled` and writes `remediation.incident_resolved`; if passing for less, it gets `passing_since` and resolves through the normal rule.
   - It runs as step (f) of the tick when the last reconciliation is more than 24 hours old (stored in `remediation_reconciliations(id, ran_at, opened, resolved)`), so the first tick after step 1.3 runs it at once. Each incident it opens or resolves prints a line: in normal operation it should find nothing, so anything it finds points at a lost event.

Not in phase 1: consecutive-failure counters, the escalation step and anything else of phase 3. The table gets those columns then.

### P8. Notifications on the dashboard and the Logs screen (step 1.4)

```
notifications_notifications(id, incident_id, instance_id, check_name, subject, opened_at, resolved_at)
notifications_hidden(notification_id, administrator_id, hidden_at)
```

1. Notifications subscribes to `remediation.incident_opened` (insert) and `remediation.incident_resolved` (set `resolved_at`).
2. **Dashboard:** above the instances, "Open incidents": each unresolved notification that the signed-in administrator has not hidden, newest first, with the instance (linked to its page), the check, the subject and when it opened, and a "Hide" button (`POST /admin/notifications/{id}/hide`, CSRF, `303` back). The check's human name and the instance names come from Notifications' own labels and `FleetApi`, at view time.
3. **Hide is per administrator** (see question 4). Today there is one administrator; with Google sign-in in phase 5 there will be several, and one administrator hiding something should not hide it from the others.
4. **Logs screen** (`/admin/logs`, linked from the dashboard): every notification, newest first, with opened and resolved times and whether this administrator hid it, 100 per page with "Older" and "Newer" links (`?before=<id>`). In step 1.5 each row also shows its alerts and their outcome.
5. A notification's text is built from facts at view time, never stored as a sentence, so wording can change later.

### P9. Alert emails (step 1.5)

Sending is separated from event delivery, so SMTP can never hold up incidents or notifications:

```
notifications_alerts(id, notification_id, kind, created_at, sent_at, attempts, last_failure, summary_id)
```

1. The same subscriber that writes the notification writes one pending alert (`kind` `opened` or `resolved`) in the same transaction.
2. Step (e) of the tick sends pending alerts, outside any transaction, then records each outcome in a short transaction. At-least-once: a tick killed between sending and recording can send one email twice. That is the accepted trade-off; the alternative is losing it.
3. **Recipients:** every administrator's email address, from a new `AccessApi::administratorEmails()`, as one email with every address in `To`. Separate emails would show each administrator only their own address, but multiply SMTP time; with one administrator today the difference does not show.
4. **Content:** plain text like the test email (design 13.2). Subject `[Maguari] Incident on web-1: certificate example.com expires in 13 days` or `[Maguari] Resolved on web-1: ...`; the body has the instance, project, zone, check, subject, the times in UTC, the sentence from Monitoring's event where it applies and a link to the instance page from the base URL.
5. **Summary email:** when the pending `opened` alerts at step (e) cover at least 50% of picked instances and at least 2 instances (one constant, `RemediationApi::MASS_FAILURE_SHARE` and `MASS_FAILURE_MIN_INSTANCES`, which phase 3's circuit breaker reuses), one email lists them all instead (`summary_id` links them to it). The same rule applies to pending `resolved` alerts, so a recovered network sends one email, not one per instance.
6. **Failures:** an alert that fails stays pending and is tried on each tick for up to 60 minutes, then given up with its last failure sentence (design 13.2's stage sentences). With email not set up, alerts are given up at once with "Email is not set up". The Logs screen shows either. Nothing is retried when SMTP is configured later, so no old alerts arrive in a burst.
7. **Time limit:** step (e) stops starting new emails after 40 seconds, so a slow SMTP server cannot make one tick run into the next; the rest go on the next tick.

**Known limit of item 5:** heartbeat ages cross the limit at different seconds per instance, so a server-side network failure can spread over two ticks, and the first tick may send one or two individual emails before the summary. *Alternative:* hold `opened` alerts for one extra tick so the batch is complete, which delays every alert by up to a minute. Proposed: accept the limit.

## Changes to `docs/DESIGN.md` in the steps

- 1.1: section 2.1 rule 1 (`Event/` is public), 3.1 items 2 and 7 (`kernel_events`, `Event/`), 9.2 (the outbox table), 10.1 (the tick's units), 12.2.1 (`tick`).
- 1.2: sections 5.2 (the temporary note removed), 6.2 (P5), 6.3 (outcome changes), 9.2 (the two new tables).
- 1.3: sections 3.1 item 7 (Remediation's layers), 7.4 (withdrawn incidents, reconciliation), 9.2.
- 1.4: sections 10.2 (the new routes), 10.4, 9.2.
- 1.5: sections 13.3, 9.2.
- Section 17: phase 1 lists its steps as they are done.

## Not in phase 1

- Actions, safeguards, modes and the circuit breaker's own behavior (phase 3). Phase 1 only shares its threshold.
- System-condition notifications (seed file, egress): later phases (design 10.4).
- Per-administrator alert choices (phase 5, design 13.3 item 3) and configurable thresholds (design 18 item 3).
- New checks (phase 2). They open incidents through the same events with no change here.

## Tests (per step)

1. **1.1:** `EventOutbox` refuses to write outside a transaction; delivery in `id` order, one transaction per event, rolled back on a failing subscriber; events written by a subscriber are delivered in the same tick; the 1,000 limit; skipping after 3 failed ticks; pruning after 7 days; the tick lock (a second tick exits 0); `tick` refuses root and prints nothing when idle.
2. **1.2:** `HeartbeatAgeRule` at the limit and one second past it; no heartbeat is not checked; the downtime rule (a tick after a 4-minute gap judges nothing late); outcome changes write exactly one event and repeats write none; "not checked" writes none; withdrawn subjects (removed hostname, certificate gone from a successful run, not from a failed run, not while the instance is silent); the dashboard and project page show the same states as before.
3. **1.3:** the aggregate's rules in `Domain/` tests; the partial unique index; open, fail again, pass, fail within 5 minutes, resolve at exactly 5 minutes; withdrawn; two incidents on one instance; reconciliation (opens a missing incident, resolves a stale one, waits for a recent pass, runs once a day and at once when it never ran); `LayersTest` for Remediation.
4. **1.4:** notifications follow incidents; Hide is per administrator, needs CSRF and `POST`; hidden and resolved ones leave the dashboard but stay on Logs; paging.
5. **1.5:** one alert per open and resolve; recipients; the summary rule at 49%, 50% and with 1 instance; retries up to 60 minutes; not set up; the 40-second limit with a slow fake mailer; no sending inside a heartbeat or "Run now" request (the fake mailer is never called there).

## Verification (each step)

From `server/`:

```
vendor/bin/phpunit
```

From the repository root:

```
scripts/test-ubuntu-22.04.sh
```

Each step's manual check follows the pattern of earlier steps: stop the development client for two minutes and watch the heartbeat-age failure, its incident, its notification and its email (with a local SMTP catcher), then start the client again and see it resolve 5 minutes later.

**Fix C: the tick loop must run continuously** during every manual check from step 1.2 on (the loop in P3 item 5). Because of the downtime rule (P5 item 3), a tick run by hand after a gap of more than 3 minutes treats the ticks as resuming and judges no heartbeat late, so occasional manual ticks never show a failure. Each step's manual check says so.

## Answers

1. **Outbox:** in Kernel, `kernel_events` (P1). Approved.
2. **Events:** outcome changes only (P6). Approved, with fixes A (daily reconciliation, P7 item 6) and B (withdraw only while the instance is reporting, P6 item 5).
3. **Heartbeat times:** Monitoring records them itself (P4). Approved.
4. **Hide:** per administrator (P8 item 3).
5. **Alert retries:** 60 minutes, then given up; nothing sent retroactively (P9 item 6).
6. **The summary's known limit:** accepted (P9).
7. **5 minutes** to resolve: confirmed, and no longer marked proposed in design section 7.4.
8. **Fix C:** every manual check from step 1.2 on runs the tick loop continuously (Verification).

## Found while implementing

### Step 1.1

- `PDO::inTransaction()` does not see transactions opened with `exec('BEGIN IMMEDIATE')`, so `EventOutbox` could not tell whether it was inside one. `Database` now tracks it itself (`Database::inTransaction()`).
- A failing event stops delivery only while it can still be retried. On its third failure it is skipped and delivery goes on in the same tick, so `DeliveryReport` holds a list of failures, of which only the last can be unskipped.
- The tick exits with 1 when an event failed, so systemd marks the run failed and the failure is visible with `systemctl status`.
- Delivery marks the event delivered before running its subscribers, inside the same transaction, so a second delivery of the same event (which the lock already prevents) would change nothing.

#### Manual check (step 1.1)

In each terminal, from the repository root:

```
source scripts/dev-env.sh
```

Apply the new migration:

```
server/bin/maguari-server migrate
```

With nothing pending, the tick prints nothing:

```
server/bin/maguari-server tick
```

Write an event by hand, as a context will from step 1.2:

```
sqlite3 server/var/dev.sqlite "INSERT INTO kernel_events (type, payload, occurred_at) VALUES ('monitoring.check_failed', '{\"instance_id\":1}', strftime('%s','now'))"
```

The next tick delivers it (to nobody yet) and prints `Delivered event <id> (monitoring.check_failed).`:

```
server/bin/maguari-server tick
```

Start the tick loop, which every later step's manual check needs running (Fix C):

```
while true; do server/bin/maguari-server tick; sleep 60; done
```

## Status

Approved. Step 1.1 implemented, waiting for review.
