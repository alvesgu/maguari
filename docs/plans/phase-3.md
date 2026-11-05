# Phase 3: Remediation

## Context

Phase 2 is finished: every check feeds incidents, notifications and alert emails, but Maguari only reports. Phase 3 of the road to 1.0 (design section 17) makes it act: the escalation ladder (design section 7.1) and its safeguards (design section 7.2), through a command channel to the client and the reset call to the Compute Engine API.

Decided before this plan, in the claude.ai chat:

- **D1. Aggregates.** Remediation gets a second aggregate next to Incident, one per instance (working name `InstanceRemediation`). Incident owns what belongs to one problem: its step on the ladder, when it started passing and that a resolved incident never changes. The instance aggregate owns what crosses incidents: the mode, cooldowns, the limits per hour and per 24 hours, the boot grace period and "this action was already requested". Actions are entities inside the instance aggregate for now; revisit only if their lifecycle grows too much. Deciding an action changes both aggregates in one `BEGIN IMMEDIATE` transaction. This breaks the usual "one aggregate per transaction" rule on purpose, and the design records the exception and its reason: this is a monolith on SQLite, and eventual consistency here would let a second incident see no recent restart and request another.
- **D2. Time instead of counting.** Act when the incident has been open for at least 3 minutes and its check is still failing. Monitoring only emits events on changes, so Remediation cannot count failures, and checks run at very different intervals. This replaces "Consecutive failures before action" in design section 7.3.
- **D3. What each check may trigger.**
  - `service`: the full ladder, restarting that unit (the check's subject).
  - `local_http` and `database`: the ladder only when the administrator associates a unit with that check on the instance page; otherwise alert only. A database failure with reason `login_refused` never acts.
  - `heartbeat_age`: the client is silent, so skip restart and reboot and go straight to a reset.
  - `remote_http`, `local_certificate`, `remote_certificate` and disk size: alert only.
- **D4. The allowlist lives on the instance.** It is owned by root and edited over SSH with a command run through sudo (a separate executable, like `maguari-certificate-scanner`, because `maguari-client` refuses root). That command also regenerates and validates the sudoers file. The server can only ask for what the instance allows. The client reports the allowed units in its heartbeat so the instance page can show them. The web app never edits the list: a compromised server must not control what the fleet restarts.
- **D5. Four steps.** Restart, restart again, reboot, reset. The reboot stays because a reset is like pulling the plug, which is riskier for databases. The client records a pending reboot in a file before rebooting, so it can report the result after boot. The boot grace period applies after a reboot as well as after a reset.
- **D6. A reset happens at most once per request.** Record "reset requested" before calling the API, call it outside any transaction, and if the tick dies in between, never repeat it. The page shows "Reset requested at ..., result unknown". A further reset is only possible through the ladder after the reset cooldown. (Answer 1 narrows this: one reset per incident, so a further reset takes a new incident.)
- **D7. Timing.** The first action after 3 minutes (D2), the service restart cooldown (5 minutes) between restarts, the boot grace period after a reboot or reset. For `heartbeat_age`: about 90 seconds to fail the check plus 3 minutes, then the reset. (Answer 2 changes the wait between steps: it depends on outcomes and results, not on a fixed 5 minutes.)
- **D8. Watching a restart.** The client watches the unit apart from the heartbeat loop, which it must never delay (a long restart such as GeoNode's, 3 to 5 minutes, would look like a dead instance). It polls the unit's state every 5 seconds until active, for at most 5 minutes, then reports the outcome and how long it took in `command_results` of the next heartbeat. The server moves to the next step as soon as it knows the outcome. Whether to turn on real-time mode meanwhile is proposed in P6.
- **D9. Configuration.** The section 7.3 settings stay named constants with the design's defaults, because the global, project and instance hierarchy arrives in phase 5 (section 18 item 3) and sliders would be built twice. The boot grace period default becomes 7 minutes for every instance, which covers GeoNode. Only two things get a UI now: each instance's mode and a global dry-run mode (Maguari decides and records "I would restart nginx.service now" but does nothing).
- **D10. Modes.** A newly picked instance starts in alert-only. Maintenance has a required end time, like a snooze; during it Maguari takes no actions and sends no emails, but incidents are still opened and shown. An instance setup wizard goes into the road to 1.0 with the layout redesign of phase 7 (recorded in design sections 17 and 18 by this plan's commit).
- **D11. Emails.** Actions show on the instance page and on the Logs screen, and the resolved email lists the actions taken with their times and outcomes. Only three cases get an email of their own: the ladder is exhausted, the reset limit for 24 hours is reached and the circuit breaker is tripped.
- **D12. The circuit breaker** only stops actions, on every instance, while it is tripped. Incidents, notifications and emails go on as usual, including the summary email. It counts only `heartbeat_age` incidents (corrected by answer 11; it first said `remote_http` too): if the server's own network fails, heartbeats stop arriving from every instance, which `heartbeat_age` alone detects. An expiring certificate, an unpaid domain or a domain that stops resolving on many instances at once must alert, not trip the breaker.
- **D13. "Never reset".** A per-instance option in the web app. In production the server also excludes its own instance (from the metadata server). In development there is no metadata server, so the option covers self-exclusion there and protects instance-sig.
- **D14. Unanswered commands.** A command the client never answers before it expires is logged and shown on the instance page, for the administrator to check by hand. Nothing more in this phase.
- **D15. Testing.** In development the sudoers entry is installed by hand, with the steps documented in `docs/WORKFLOW.md`. Resets are tested only on a disposable instance in a separate GCP project, never on instance-sig (design section 17).
- **D16. Steps,** from safest to riskiest, each one commit reviewed before the next (below). The disk usage check was added later as step 3.4, and the later steps were renumbered (answer 16).

Recorded for later, not in this phase: choosing which kinds of alert each administrator receives belongs to section 18 item 3 (phase 5). This plan's commit adds three kinds to that item: a client that does not answer a command, a service restarted by `maguari-client` and an instance reset by `maguari-server`.

This plan proposes the rest. It was approved with the answers at the end (two rounds), and the proposals below already follow them. Each step records what it decides in `docs/DESIGN.md` in the same commit.

## Steps

Each step is one commit that passes both test suites, followed by a review before the next step starts.

| Step | What | Visible result | Client |
|---|---|---|---|
| 3.1 | The ladder decides and records, executing nothing; modes; `InstanceRemediation` | The mode on the instance page, the check-to-unit association, "I would restart nginx.service now" on the instance page and the Logs screen | |
| 3.2 | The command channel and the allowlist, still executing nothing | `maguari-allowed-units`; allowed units and the suggested command on the instance page; signed responses; a dry-run decision travels to the client and comes back "the client could do this" or "not allowed"; unanswered commands shown | 0.7.0 |
| 3.3 | Real service restarts with the watch of D8 and the extra heartbeat (P6 item 6) | The global dry-run switch; a test unit restarted for real; outcome and duration on the page and in the resolved email | 0.8.0 |
| 3.4 | The disk usage check (P11) | Disk usage on the dashboard and the instance page; warning and critical incidents with their emails, the critical one marked in its subject | |
| 3.5 | The reboot, with "Never reboot" | A disposable instance reboots, and reports the result after boot; the boot grace period holds later actions; a critical `/` or `/boot` blocks the reboot | 0.9.0 |
| 3.6 | The reset through the API, with the status check and "Never reset" | A disposable instance is reset; "Reset requested at ..., result unknown" after a killed tick | |
| 3.7 | The circuit breaker and the emails of D11 | No action while many instances fail at once; the three emails | |

The reset limit, the cooldowns and the boot grace period are decided from 3.1 on, so dry-run shows them working before anything real happens. Real actions exist from 3.3 but the breaker only from 3.7. That is acceptable because nothing is deployed until phase 4 and in development only test instances are set to automatic.

Out of scope: sliders and the settings hierarchy (phase 5), per-administrator alert choices (phase 5), the setup wizard (phase 7), real-time mode (P6), restarting containers inside Docker (design section 6.1).

## Aggregates in this phase

**First problem: two incidents, one nginx.** nginx dies on an instance. Two checks notice: the service check of `nginx.service` and a local HTTP check of `https://127.0.0.1/health`, which the administrator has associated with `nginx.service`. So two incidents are open. Three minutes later, the tick looks at the first incident: open long enough, no restart yet, so restart nginx. Then it looks at the second: also open long enough, and if an incident only knows its own history, it also finds no restart yet. Result: two restarts of nginx, moments apart, and the second one interrupts the first. For GeoNode, whose restart takes minutes, that starts the wait all over again.

The fact "nginx was just restarted" does not belong to either incident. It belongs to the instance.

**Second problem: 3 resets in 24 hours.** The safeguard says an instance gets at most 3 resets in 24 hours. Suppose the instance goes silent, gets reset, comes back and the incident resolves. An hour later it goes silent again: a new incident, whose own count of resets is 0. If every incident counts its own resets, each one always sees 0 or 1, the limit is never reached and a sick instance is reset all day. So the count cannot live in an incident either. It outlives them.

**What prevents both: a single place all actions on an instance must go through.** Instead of each incident deciding alone, the tick loads one object per instance, `InstanceRemediation`, holding its mode, its grace period and its recent actions, and asks it: "these incidents want these things; what may actually happen?"

- First problem: the object sees the two requests name the same unit and allows one restart, linked to both incidents. A later incident that asks while that restart is running or cooling down gets nothing new, because the object can see the earlier action.
- Second problem: the object counts the resets of the last 24 hours from its own list of actions, whichever incidents they served. The fourth request gets "limit reached", however the incidents came and went.

No other code writes an action, so no code path can skip the rules. That is what an **aggregate** is: a group of data that changes only through one object, so a rule covering the whole group can never be broken halfway. It is the idea behind the `Incident` class too, on a smaller scale: its rule is "a resolved incident never changes", and it keeps that rule because all changes go through it.

**Why two aggregates and not one.** The incident keeps what only concerns one problem: which step of the ladder it reached and when its check started passing. Its code does not need to know about limits, and a resolved incident stays frozen as a record. The instance object keeps what crosses problems. An action sits inside the instance aggregate as an **entity**: it has an identity and its state changes (requested, started, finished), but nothing changes it except through `InstanceRemediation`.

**The rule we break on purpose.** The usual advice is one aggregate per transaction, with others catching up later through events. Here that would mean: a restart is recorded on the instance, an event goes out and the incident advances its step a moment later. In that moment a second look could see an incident still at step 0 and ask again. The advice exists because aggregates in big systems may live on separate databases, where a shared transaction is costly or impossible. Maguari is one application on one SQLite file, so a single `BEGIN IMMEDIATE` costs almost nothing and closes the gap. The exception is written down in the design so it stays a decision, not an accident.

## Proposals

### P1. Who owns what

1. **Remediation** owns the decisions and the record of actions. **Clients** owns delivery: the command queue, what travels in heartbeats and what each client reports. **Fleet** owns the reset call and the status check. Monitoring stays unaware of all of it, except that it now keeps the failure's reason (P3 item 6).
2. **Direction of calls** (design section 2.1): Remediation calls `ClientsApi::queueCommand()` inside its decision transaction and `FleetApi::instanceStatus()` and `resetInstance()` outside any transaction. Transactions do not nest (`Kernel/Database`), so `queueCommand()` writes in the caller's transaction and refuses to run outside one, as `EventOutbox` does. Clients tells Remediation what happened only through events, because heartbeats never deliver. No cycle: Clients still knows nothing about Remediation.
3. **New tables:**
   - `remediation_instances(instance_id, mode, mode_changed_at, maintenance_until, never_reboot, never_reset)`: no row means alert-only (D10), so Remediation needs no event from Fleet when an instance is picked. Maintenance is kept apart from the mode, like a snooze over it: when it ends, the instance is back in the mode it had.
   - `remediation_actions(id, instance_id, action, unit, dry_run, state, outcome, detail, decided_at, started_at, finished_at, duration_seconds, command_id)`: `action` is `restart`, `reboot` or `reset`; `state` is `requested`, `started` or `finished`.
   - `remediation_action_incidents(action_id, incident_id)`: one action can serve several incidents.
   - `remediation_check_units(instance_id, check_name, subject, unit)`: the association of D3.
   - `remediation_settings(id, dry_run)`: at most one row; on by default (P9).
   - `remediation_circuit_breaker(id, tripped_at, cleared_at)`: one row per trip (step 3.7).
   - `remediation_incidents` gets `ladder_step` and `exhausted_at`.
   - `clients_commands(id, instance_id, action, args, dry_run, created_at, expires_at, last_sent_at, started_at, finished_at, outcome)`, deleted 7 days after they end. `clients_clients` gets `allowed_units` (JSON), `reboot_allowed` and `commands_supported`.
   - `monitoring_check_states` gets `reason`. It already has `checked_at`, which P5 item 5 relies on.
4. **New events:**
   - `clients.command_started`, `clients.command_finished` and `clients.command_unanswered`, written in the heartbeat's transaction or by the tick's expiry, read by Remediation.
   - `remediation.ladder_exhausted`, `remediation.reset_limit_reached`, `remediation.circuit_breaker_tripped` and `remediation.circuit_breaker_cleared`, read by Notifications.
   - `remediation.incident_opened` and `incident_resolved` get `in_maintenance` (P8 item 3).
5. **New classes:**
   - `Remediation/Domain/`: `InstanceRemediation` (aggregate), `Action` (entity), `Ladder` (which step a check may reach, D3), `RemediationLimits` (the section 7.3 constants, D9), `Mode`, `Decision`, `CircuitBreaker`, `AllowedUnitsSuggestion` (P4 item 7).
   - `Remediation/Application/`: `ActionDecisions` (the tick's step), `CommandEventSubscriber`.
   - `Remediation/Infrastructure/`: `InstanceRemediationRepository`.
   - `shared/src/`: `Command` (actions, outcomes and the validation both sides use).
   - `client/src/`: `AllowedUnitsFile`, `CommandsFile`, `CommandExecutor`, `PendingRebootFile`. `client/bin/maguari-allowed-units`.

### P2. The protocol: commands and command_results

1. **Protocol version stays 1**, as with `check_settings` (design section 4 item 4). The **minimum client version** is 0.7.0, and the signal is a new request field, sent in every heartbeat:
   ```
   "remediation": {"allowed_units": ["nginx.service", "php8.3-fpm.service"], "reboot_allowed": false}
   ```
   Sending the field at all says the client understands `commands`. The server sends commands only to a client whose latest heartbeat had it. `command_results` cannot be the signal, because today's client already sends it empty. It costs only ingress. At most 50 units, the same cap the command enforces (P4).
2. **The response,** in a `200` body next to `check_settings`:
   ```
   "commands": [
       {"id": "9f0c6e2a41d7b358c0a1e47f6b2d9385", "action": "restart", "args": {"unit": "nginx.service"}, "expires_at": 1793800000, "dry_run": false},
       {"id": "3be1a07d52c94f68a9d01c7e8f4b2d16", "action": "reboot", "args": {}, "expires_at": 1793800000, "dry_run": false}
   ]
   ```
   The ID is 16 random bytes in lowercase hex. `dry_run` is required: a command without it is refused with `bad_command`, so a mistake can never turn into a real action. Heartbeats stay `204` when nothing is pending.
3. **Delivery is at least once.** The server sends a command in every response until the client reports it `started` or it expires, so a lost response costs one heartbeat. The client writes the ID to `commands.json` (`PrivateFile`: mode 0600, next to the credentials) before acting and ignores an ID it has seen, so a repeat never restarts twice. A restart of the client keeps the file.
4. **The results,** in the next request's `command_results`:
   ```
   {"id": "9f0c...", "status": "started"}
   {"id": "9f0c...", "status": "finished", "outcome": "restarted", "seconds": 184}
   {"id": "9f0c...", "status": "finished", "outcome": "failed", "state": "failed", "seconds": 12}
   ```
   The client keeps a result in `commands.json` until a heartbeat carrying it is accepted, so an outage loses none. `seconds` is measured by the client; all times the server stores are its own receive times (as with readings, design section 5.2).

   | Outcome | Meaning | Did the action run? |
   |---|---|---|
   | `restarted` | The unit became active again, started after the command | Yes |
   | `failed` | The unit reached `failed` with no job pending | Yes |
   | `timed_out` | Not active after 5 minutes, with its `state` | Yes |
   | `rebooted` | The boot ID changed | Yes |
   | `reboot_failed` | The boot ID did not change within 5 minutes | Yes |
   | `would_run` | Dry run: allowed, and sudo would permit it | Yes, for the ladder (P5) |
   | `not_allowed` | Not in the allowlist on the instance | No |
   | `sudo_failed` | sudo refused or could not run | No |
   | `expired` | Past `expires_at` when received | No |
   | `bad_command` | Unknown action, invalid unit or missing field | No |

5. **A malformed result is ignored with a line in the log,** instead of refusing the heartbeat as a malformed reading does. Refusing would make a client with a bug look silent, and a silent client leads to a reset. A result for an unknown ID is ignored as well, which makes reporting safe to repeat. At most 50 results per heartbeat.
6. **Expiry and D14.** `expires_at` is 5 minutes after the decision (section 7.3). "Unanswered" has two forms, both judged by the tick (`ClientsApi::expireCommands()`), logged and shown on the page: no `started` by `expires_at`; or `started` but no `finished` within 5 minutes plus 2 heartbeat intervals (the watch's limit and some margin) of it. A late result is still recorded and corrects the page. For a reboot, the second limit covers the reboot itself.
7. **Signed responses.** Requests are signed (design section 5.5), but responses rely on TLS alone. That was fine while they carried no orders. Now anyone who can present a valid certificate for the server's name could order restarts and reboots. So a `200` body carries `X-Maguari-Signature: HMAC-SHA256(secret, "response\n" + request nonce + "\n" + sha256(body))`, with the same `shared/src/Signature.php`. The nonce ties it to this request, so an old response cannot be replayed. The client ignores `commands` from a response whose signature is missing or wrong. About 100 bytes of egress, only on the rare responses with a body. Comes in step 3.2, with the channel.

### P3. How the tick evaluates the ladder

1. **The decision is a tick step, not an event subscriber.** The 3 minutes and the cooldowns pass without any event, so something must look at the clock anyway, and subscribers must never touch the network (design section 2.1 rule 5). Since `/admin` requests also deliver events, a subscriber would make resets possible inside a web request. So the decisions are polled once per tick, and they are idempotent: a tick that finds nothing due does nothing. The design's "Flow of one failing heartbeat" (section 2.1) changes accordingly.
2. **The new order of the tick** (design section 10.1): (a) judge heartbeat ages; **(a2) expire unanswered commands**; (b) deliver events, including what the clients reported; (c) resolve incidents and reconcile; **(c2) decide actions**; (d) deliver events; (e) send alert emails. Delivering before deciding means a pass or a restart outcome from the latest heartbeat is seen before the decision.
3. **Step (c2),** `RemediationApi::decideActions()`, for each instance that is automatic, not in maintenance and has open incidents:
   1. In one `BEGIN IMMEDIATE`: load `InstanceRemediation` (its mode, every action of the last 24 hours and every unfinished one) and its open incidents, ask it to `decide()`, store the actions and the new ladder steps, queue the commands, write the events and commit. One short transaction per instance, so heartbeats wait little.
   2. If the decision was a reset, carry it out right away, outside any transaction (P7), before moving to the next instance.
   
   No new instance is started after 20 seconds (`ActionDecisions::TIME_LIMIT_SECONDS`); the rest wait for the next tick, so the emails still get their time. Deciding and carrying out instance by instance means the time limit can never leave a reset recorded but not attempted.
4. **`decide()` is pure** (`Domain/`, section 3.1 item 7): it takes the incidents with the time and reason of their checks' latest results (P5 item 5), the unit each may restart, the allowed units and whether reboots are allowed, whether the heartbeat is on time, the breaker's state, dry-run and the time. It returns decisions and reasons for not acting. So every timing rule is tested with plain numbers.
5. **"Still failing"** means `passing_since` is null on the incident. A client check whose client has gone silent would look failing forever, on stale results, and a command could not reach it anyway. So restart and reboot are decided only while the heartbeat is on time; if it is late, the `heartbeat_age` incident takes over.
6. **Reasons reach Remediation through Monitoring.** `login_refused` (D3) and `not_found` (answer 10) are known only to Monitoring, and a failure's reason can change while the outcome stays fail, which writes no event. So `monitoring_check_states` keeps the latest failure's `reason`, and `MonitoringApi::failingChecks()` returns it with `checked_at`. `decide()` reads them fresh on every tick, so a reason that changes to one that may act lets the ladder go on, and the reverse stops it.

### P4. The allowlist on the instance (D4)

1. **`maguari-allowed-units`,** a separate executable in the client package. `set` must run as root and refuses otherwise:
   - `sudo maguari-allowed-units set nginx.service php8.3-fpm.service --allow-reboot` replaces the whole list and the reboot permission in one go, so setting up an instance takes one command, run once. Without `--allow-reboot`, reboots are not allowed. Without units, the list is emptied. Everything is validated before anything is written, and running it twice changes nothing, so phase 4 may fold it into installation.
   - `maguari-allowed-units list` prints the units and the reboot permission. The file is readable by everyone, so this one needs no root.

   No `add` or `remove`: `set` covers them, and the page always has the full command ready.
2. **The file** is `/etc/maguari-client/allowed-units.json`, owned by root with mode 0644, in a root-owned directory (not the client's: section 6.1.1 explains why). It holds no secrets. The client refuses a file that is not owned by root or that others can write, and treats it as an empty list. `MAGUARI_ALLOWED_UNITS_FILE` overrides the path for development and tests only.
3. **Validation:** units follow `shared/src/UnitName.php`. At most 50. It refuses `maguari-client.service`, because a client restarting itself would kill its own watch.
4. **Sudoers:** every change rewrites `/etc/sudoers.d/maguari-client` (mode 0440):
   ```
   maguari-client ALL=(root) NOPASSWD: /usr/bin/systemctl restart --no-block -- nginx.service
   maguari-client ALL=(root) NOPASSWD: /usr/bin/systemctl reboot
   ```
   The reboot line is there only with `--allow-reboot`.

   The new content goes to a temporary file that sudo ignores (a name with a dot, in the same directory), is checked with `visudo -cf` and is renamed into place only if it passes. Otherwise the old files stay. Unit names may contain `:` and `\`, which sudoers treats as special, so they are escaped (a test covers every allowed character against the real `visudo`). This replaces the package's generation of sudoers in design section 5.4, and section 5.3 item 3 changes too: there is one action, `restart`, whose argument must be in the file, instead of an action name per service.
5. **Two gates on the client:** the unit must pass `UnitName` and be in the file. sudo is the third gate, and the server's knowledge of the allowed units a fourth (P5 item 4).
6. **The client's systemd unit** (phase 4) must not set `NoNewPrivileges=yes`, because it would make sudo fail. The design records this now so that packaging does not copy the scanner's hardening blindly.
7. **The instance page suggests the command** (step 3.2), ready to copy, in "Allowed units on the instance". `RemediationApi::suggestedAllowedUnits()` builds it from the instance's service checks (`MonitoringApi`), the units associated with its local HTTP and database checks (its own table) and the units the client already reports as allowed (`ClientsApi`). Those last ones are kept because `set` replaces the whole list, so a suggestion without them would silently remove units someone allowed by hand. `--allow-reboot` is always included, with a line saying that it may be removed, and that without it the ladder stops after the second restart (P5 item 8). `maguari-client.service` is left out, since the command would refuse it, and a name with a character the shell treats as special (`\`) is quoted. When a check's unit is not allowed, the page says so next to the check and shows the updated command. The server only suggests: a person runs it with sudo, so D4 holds.

### P5. The ladder

1. **The steps,** on the incident's `ladder_step`, 0 before anything:

   | Next step | Action | Allowed when |
   |---|---|---|
   | 1 | Restart | Open for 3 minutes, counted from the latest of: opening, the instance turning automatic, the end of maintenance; failing |
   | 2 | Restart again | The first restart's outcome is `failed` or `timed_out`; or it is `restarted` and the check has a result newer than the restart's end (item 5); failing |
   | 3 | Reboot | The same, for the second restart; failing. Item 8 says when this step is skipped or the ladder stops before it |
   | 4 | Reset | The boot grace period (7 minutes) after the reboot has passed; failing |
   | End | Exhausted | The boot grace period after the reset has passed; failing. Its email goes out once, and nothing more happens on this incident (`RESETS_PER_INCIDENT = 1`) |

   `heartbeat_age` starts at step 4: open for 3 minutes, failing (D3). Counting the 3 minutes from the latest of those times means that turning an instance automatic, or a maintenance ending, never fires an action at once on an old incident.

2. **Worked timelines,** in minutes from the incident opening, with a check that never recovers. These are typical values: heartbeats and ticks each come once a minute, so each can add between 0 and 1 minute.

   | What happens | Unit fails at once (`failed`) | Unit comes back (`restarted`), check still fails |
   |---|---|---|
   | Restart | 3 | 3 |
   | Restart again | about 4 | about 5 |
   | Reboot command | about 5 | about 7 |
   | The client reports `started` and reboots (P7) | about 6 | about 8 |
   | The boot grace period ends: reset | about 13 | about 15 |
   | The boot grace period ends: exhausted, email | about 20 | about 22 |

   The next heartbeat brings the command, and the end of the watch sends the outcome at once (P6 item 6). So one restart takes about 1 minute when the unit fails at once, and about 2 when the check has to fail again, because that needs the next scheduled heartbeat's result (item 5). That is the floor while the client speaks once a minute, and it was accepted (answer 12). A slow restart adds its own length and never overlaps with the next one, because an unfinished restart blocks it.

   `heartbeat_age`: the check fails after about 90 seconds, so the reset comes about 4.5 to 6 minutes after the last heartbeat (D7).

3. **Rules that cross incidents,** all in `InstanceRemediation`:
   - **Already requested:** a restart of a unit is not requested while another of that unit is unfinished. An incident whose time comes while one is unfinished, was just decided or ended within the cooldown (5 minutes) links to it and takes the step with it, instead of asking for its own. The cooldown is per unit, so two different failing units do not hold each other up. With the rule in item 5, the cooldown no longer spaces out the ladder of one incident: it guards a new incident against a restart that just happened, and spaces out retries of an action that could not run (item 4).
   - **Instance-wide actions:** a reboot or a reset links every incident open at that moment that may act, and moves each one up to that step. An instance-wide action waits until restarts in progress finish, and only one is decided per tick.
   - **Boot grace period:** no action on the instance for 7 minutes after a reboot started or a reset was requested. It never ends early: the check results just after boot are the least reliable, and the reset cooldown's coherence rule already assumes it. A `heartbeat_age` incident that the reboot itself opens, with its emails, is expected (known limits).
   - **Limits:** at most 3 restarts per instance per hour; on reaching it, the ladder waits until the hour's window allows another, with no email (D11). At most 3 resets per instance in 24 hours, across incidents; on reaching it, no more resets and one email for that window (`remediation.reset_limit_reached`), and the same step is tried when the window allows. At most 1 reset per incident (`RESETS_PER_INCIDENT`). At most 3 reboots per instance in 24 hours (`MAX_REBOOTS_PER_24_HOURS`); on reaching it, the ladder is exhausted for that incident: its email goes out, with no reset. The reset cooldown (10 minutes) separates resets of different incidents on one instance, and retries of a reset that could not run.
   - **A new incident during a reset's silence:** when a `service` incident causes a reset, the client's silence opens a `heartbeat_age` incident that was not linked to it. If the instance has not come back when the reset cooldown ends, that incident gets its own reset. So one problem can use two resets, bounded by the limit of 3.
   - **Dry-run actions** count only against dry-run actions, so turning dry-run off starts clean.
4. **A step counts only if the action really ran** (the third column of P2 item 4's table). The server does not request a restart of a unit the client does not report as allowed: it records "nginx.service is not in the allowed units on the instance" once per incident, shown on the page. It then waits and acts on the first tick after root adds it. For `sudo_failed`, `expired` and unanswered commands, the same step is tried again after the cooldown, limited by the limits above. They never climb the ladder: a reboot asked of a client that does not answer would not work either, and a misconfigured sudoers file should not bring a healthy instance down. The cost is that such an instance stays broken until someone looks; the incident's opened email has told the administrator, and D14 adds the page.
5. **How Remediation knows a result is newer.** A repeated failure writes no event, so this cannot come from events, but the tick polls anyway (P3 item 1). Monitoring already updates `monitoring_check_states.checked_at` on every pass or fail, repeats included. So the rule compares two times, both the server's own receive times, which makes it safe from the client's clock:

   `checked_at > the action's finished_at`, with `passing_since` null on the incident.

   - **Strictly newer.** The heartbeat that brings the outcome also brings check results, and the client may have run those before the restart ended. They have the same time as `finished_at`, so they do not count, and the next heartbeat's result does. That also gives a slow app about a minute to warm up.
   - **The 2 failures in a row hold by themselves.** While the incident is open and its outcome is fail, one more failure is a repeat: it updates `checked_at` at once, without counting a streak (`CheckStates::recordConfirmed()`), so one result is enough. If the check passes first, `check_passed` sets `passing_since` and the rule does not apply. A later failure then counts as the first in a row and leaves the outcome and `checked_at` alone; only the second writes `check_failed`, which clears `passing_since`, and only then does the rule apply.
   - **"Not checked" does not count.** It updates only `monitoring_check_not_checked`, so the ladder waits, as it should with nothing known. A client that goes silent is left to the `heartbeat_age` incident (P3 item 5).
   - **`would_run`** in dry-run is treated like `restarted`, so dry-run follows the same path. Nothing changed on the instance, so its check keeps failing and the ladder moves on.

   Only `service`, `local_http` and `database` incidents use this, and all three report on every heartbeat.
6. **Check units (D3):** on the instance page, each `local_http` and `database` check gets a "Restart when failing" field, holding one unit name validated with `UnitName`, with a warning and the updated command if the client does not report it as allowed (P4 item 7); `POST /admin/instances/{id}/check-units`. A check's association is removed when `monitoring.check_withdrawn` arrives. One unit per check: a check depending on two services names the one that restarts first in a failure.
7. **Alert only (D3)** means `Ladder` returns no step: the incident never gets an action, whatever the mode. That includes a `database` failure with reason `login_refused` and a `service` failure with reason `not_found` (answer 10), since restarting cannot help a typo or a removed package, and the disk usage checks (P11).
8. **Which steps exist on an instance.** The server may only restrict what the instance allows, never grant more. A step turned off in the web app is skipped, and the ladder goes on to the next allowed step. A reboot not allowed on the instance stops the ladder, because whoever removed `--allow-reboot` did it on purpose. `heartbeat_age` only ever has the reset.

   | Allowed on the instance (`--allow-reboot`) | "Never reboot" | "Never reset" | After the second restart |
   |---|---|---|---|
   | Yes | Off | Off | Reboot, then reset |
   | Yes | On | Off | Reset |
   | Yes | Off | On | Reboot, then exhausted |
   | Yes | On | On | Exhausted |
   | No | Either | Either | Exhausted |

   "Exhausted" comes when the second restart's outcome allows the next step (item 5), with its email. Before step 3.2 nothing is reported, so step 3.1 assumes the instance allows reboots. "Never reboot" comes in step 3.5 and "Never reset" in step 3.6.
9. **A critical system disk blocks reboot and reset** (P11 item 6).

### P6. Watching a restart without blocking heartbeats (D8)

1. **A child process per command.** PHP has no threads, and a loop inside `Runner` would have its polls delayed by a heartbeat's up to 20 seconds of checks, and could have its heartbeat delayed by a stuck `sudo` or `systemctl` in turn (sudo can hang on name resolution, for one). So `Runner` starts `maguari-client execute-command <id>` with `proc_open()` and an argument array, keeps the handle and returns to its schedule. The child reads the command from `commands.json`, does the work and writes its result to its own file in `commands/`, atomically. The runner only collects result files before each heartbeat. A slow or stuck child can never touch the heartbeat. The child has a hard limit of 6 minutes and exits.
   
   *Alternative:* polling inside `Runner`'s loop, with `sleep` replaced by short naps. No child processes to look after, but it gives up the guarantee D8 asks for.
2. **The restart:** read `ActiveEnterTimestampMonotonic`, run `sudo -n /usr/bin/systemctl restart --no-block -- <unit>` and poll `systemctl show` every 5 seconds (the existing `Systemctl`, with more properties). `--no-block` returns as soon as systemd has queued the job, and the watch is what waits.
3. **"Active" must mean restarted.** Just after the command, the unit may still be active from before. `restarted` needs `ActiveState=active`, no job pending (`Job` empty) and an `ActiveEnterTimestampMonotonic` different from the one read before. `failed` with no job pending ends the watch early: nothing more is coming. Step 3.3 verifies the exact output of these properties on Ubuntu 22.04 and 24.04.
4. **A restart of the client during a watch:** at startup, the runner starts a new child for any command marked started without a result. It carries on from the stored start time, so the 5 minute limit holds. systemd kills children with the service by default, which is what we want here.
5. **Real-time mode stays out of this phase** (answer 13). `report_interval` is not implemented, and it would run the client's checks every 10 seconds on an instance already in trouble.
6. **One extra heartbeat when the watch ends** (answer 13, step 3.3). The child's result file appears, and the runner sends a heartbeat at once, outside its schedule, carrying the outcome. It then returns to its normal schedule: the fixed times do not move. To notice the file, `Runner` sleeps in steps of 1 second while a child is running, and until the next due time otherwise. The per-client rate limit (20 per 60 seconds, design section 10.2) allows it. Two things follow from it being a normal heartbeat. It runs the checks too, and since its results have the same receive time as the outcome, they do not count as newer (P5 item 5), so the next scheduled heartbeat's result is what moves the ladder on. And a failure in it counts towards the 2 failures in a row of a check that had passed, so those 2 can come closer together than a minute, which is accepted because the unit has just been restarted and is being watched closely anyway. A failed extra heartbeat is logged like any other, and the outcome goes with the next one.

### P7. Reboot and reset

**Reboot (step 3.5):**

**"Never reboot"** is a checkbox on the instance page, next to "Never reset" (`POST /admin/instances/{id}/never-reboot`), with the rules of P5 item 8.

1. The client reboots only after the server has acknowledged `started`: it writes `pending-reboot.json` (`PrivateFile`) with the command's ID, the current boot ID (`/proc/sys/kernel/random/boot_id`) and the time, reports `started` in the next heartbeat and, once that is accepted, runs `sudo -n /usr/bin/systemctl reboot`. So the server knows the reboot was received, and the boot grace period has a known start, at the cost of up to one heartbeat interval.
2. When the client starts, it compares boot IDs: different means `rebooted`; the same after 5 minutes means `reboot_failed`. The file is removed once its result is accepted.

**Reset (step 3.6),** carried out by the tick right after the decision's transaction (P3 item 3):

1. **Recorded first.** The transaction stores the action as `requested`, and that alone counts for the limit, the cooldown and the incident's one reset. Nothing ever picks up a `requested` reset again. If the tick dies, the page says "Reset requested at ..., result unknown" for good (D6), and the ladder is exhausted after its boot grace period if the check still fails. Not retrying it was accepted (answer 1).
2. **Status check:** `FleetApi::instanceStatus()` (`compute.instances.get`). Only `RUNNING` continues. Design section 7.2 item 6 names what to refuse, but an allowlist also covers `SUSPENDED`, `REPAIRING` and whatever Google adds. It also compares the GCP instance ID with Fleet's, so a recreated instance under the same name is not reset by mistake on old grounds. A refusal finishes the action as `not_running`. It could not run, so the same step is tried again (answer 5); it counts only for the cooldown, so a stopped instance costs one API call per 10 minutes.
3. **Self-exclusion:** the server's own instance is never reset. In production, the instance ID from the metadata server; if that cannot be read, no reset at all. In development (`MAGUARI_GCP_CREDENTIALS=application-default`) there is no metadata server, and "never reset" covers it (D13). The check sits in `FleetApi::resetInstance()`, the last gate, and is also applied at decision time so that the page can explain.
4. **The call:** `POST .../zones/{zone}/instances/{name}/reset`. Google's answer means "accepted", not "done": polling the operation would need one more permission, and the check passing again is the better proof. The result is stored in a short transaction: `accepted`, `refused` with a fixed sentence (section 8.1 item 1) when Google answered 4xx, or `unknown` for a timeout or a 5xx. `refused` could not run, so it counts only for the cooldown and is tried again, as with a role without the permission. `unknown` may have reached Google, so it counts like `accepted`: never repeated (D6).
5. **"Never reset"** is a checkbox on the instance page (`POST /admin/instances/{id}/never-reset`). For such an instance the ladder ends with the reboot, or after the second restart if there is no reboot either (P5 item 8), and is exhausted then. A `heartbeat_age` incident on it has no step at all, so it is exhausted when its 3 minutes pass.

### P8. Modes and dry-run (steps 3.1 and 3.3)

1. **Instance page:** "Remediation", with the mode (automatic or alert-only) and a form for maintenance with a required end time (at most 30 days ahead), plus "End maintenance". `POST /admin/instances/{id}/mode`, `POST /admin/instances/{id}/maintenance`, `POST /admin/instances/{id}/maintenance/end`. Below it, the instance's actions, newest first.
2. **Alert-only** records nothing. Only automatic instances are decided, also under dry-run, so dry-run shows what automatic would do.
3. **Maintenance and emails:** incidents open and resolve as usual, with `in_maintenance` in their events. Notifications writes the notification but gives up the alert at once with "The instance was in maintenance." When maintenance ends, an incident still open gets its `opened` email through the existing hourly reconciliation, "Detected late" (design section 13.3 item 5), which only needs to skip instances still in maintenance. No new mechanism, and the administrator hears about what is still broken.
4. **Global dry-run:** a small page, `/admin/remediation`, linked from the dashboard, with one switch (`POST /admin/remediation/dry-run`) and, from step 3.7, the breaker's state. The switch appears in step 3.3; before that, nothing can execute, so it would have no meaning. The dashboard shows one line while dry-run is on. It starts **on** in every new install (P9).
5. **Dry-run also exercises the channel (step 3.2).** A dry-run restart or reboot travels as a command with `dry_run: true`. The client does everything up to the action: checks the rules and asks sudo with `sudo -n -l <the same command>`, which runs nothing, then answers `would_run`, `not_allowed` or `sudo_failed`. This makes a dry run catch the real configuration problems, and gives 3.2 a visible result without any new feature. A dry-run reset checks the instance's status and stops there. D9 says dry-run "does nothing"; this does nothing to the instance, but it does reach it (answer 6). Step 3.2 verifies that `sudo -l` behaves this way with `NOPASSWD` on both Ubuntu versions.

### P9. The circuit breaker and emails (step 3.7)

1. **Evaluated at the start of step (c2),** once per tick: the instances with an open `heartbeat_age` incident, against the instances that check judges (picked and having sent a heartbeat, design section 6.2), leaving out those in maintenance on both sides, through the existing `RemediationApi::isMassFailure()` (50%, at least 2). Tripping writes a row and `remediation.circuit_breaker_tripped`; it clears at the first tick below the threshold. While tripped, `decide()` returns no action, records one reason on the page and leaves ladder steps alone. Because of the 3 minute wait, heartbeat ages that spread across two ticks (design section 13.3 item 9) are all counted before anything can act. `remote_http` does not count (D12, answer 11): it never triggers an action, so counting it would protect nothing, and a domain that stops resolving on several instances would stop every other repair meanwhile. Leaving instances that never reported out of the total keeps picked instances without a client from hiding a network failure.
2. **The three emails** are new alert kinds. `ladder_exhausted` and `reset_limit` belong to an incident's notification, so `notifications_alerts` gets those kinds and uses the same sending and retry rules. The breaker concerns no incident, so `notifications_system_alerts(id, kind, created_at, sent_at, given_up_at, attempts, last_failure)`, which the same sender handles. Section 10.4's future system conditions can reuse it. Nothing is sent for clearing: the summary email of recoveries (D12) covers it.
3. **The resolved email** gets "Actions taken" from `RemediationApi::actionsOfIncident()`: each with its time in UTC, its outcome and how long it took. Dry-run actions are labelled.
4. **Logs screen** (design section 10.4 item 7): a second list, "Actions", newest first with the same paging, from `RemediationApi::recentActions()`, which includes dry-run actions and unanswered commands.
5. **Dry-run on by default:** `remediation_settings` starts on, and step 3.3 adds the switch to turn it off, so a new install decides nothing real until an administrator has seen what it would do. Setting an instance to automatic is the second, separate choice.

### P10. Development and testing (D15)

1. **Unit tests** cover the domain with plain numbers (every row of P5, both aggregates' rules, the breaker), the protocol's formats and the client with fake `Systemctl` and sudo programs, as tests already do with `ProcessSystemctl`'s `$binary`.
2. **Real restarts:** a harmless test unit on the development desktop, `maguari-test.service`, with a sudoers entry for the developer's own user and only that unit, both installed by hand. Step 3.3 documents the exact steps in `docs/WORKFLOW.md`.
3. **The development client is enrolled as instance-sig** (it claims that instance's identity on the desktop). So a decision for instance-sig would restart units on the desktop, reboot the desktop and reset the real instance-sig. So instance-sig stays alert-only throughout this phase, gets "never reset" as soon as step 3.6 adds it, and the desktop's sudoers never has a reboot line.
4. **Reboot and reset** run only on a disposable instance in a separate project. The desktop is not a candidate for the reboot: it is triple-boot and might not come back into Ubuntu.
   - **The reboot (step 3.5):** the server runs on the desktop until phase 4, and a client only accepts `http://` for `localhost`. So the disposable instance runs the client from a source checkout under a temporary systemd unit, with an SSH reverse tunnel from the desktop (`-R 8080:localhost:8080`) reopened in a loop, so that after the reboot the client reaches the server on its own `localhost`. Step 3.4 documents this in `docs/WORKFLOW.md`.
   - **The reset (step 3.6):** before that step, the development service account's role in `pessoal-303713` loses `compute.instances.reset`, and only the disposable project's role keeps it. That is a second lock on instance-sig, outside Maguari's code. Step 3.5 documents this in `docs/WORKFLOW.md`.

### P11. The disk usage check (step 3.4)

1. **Why.** The client reports disk used and total for every filesystem every minute, but nothing judges whether a disk is full. Design section 1 goal 1 asks "Is disk usage safe?"
2. **Two check names, one per level:** `disk_usage_warning` and `disk_usage_critical`, labels "Disk usage (warning)" and "Disk usage (critical)", each with one result per filesystem and the mount point as the subject. Monitoring judges both on each heartbeat, from the readings that already arrive (`Domain/DiskUsageRule`), with no change to the client. **This is how warning to critical gets its own incident and email:** an outcome that stays fail writes no event, but these are two separate outcomes. At 90%, `warning` is fail and `critical` is pass. Crossing 95% changes only `critical`, which writes its own `monitoring.check_failed`, and so its own incident and email through what already exists. Going back down, each resolves on its own, after 5 minutes of passing (design section 7.4 item 5). A disk that jumps straight past 95% gets both emails.
   
   *Alternative:* one check with a "level" on its outcome, and a new event on a level change. Fewer rows, but Monitoring, Remediation and Notifications would all have to learn about levels, whereas with two names none of them needs to.
3. **Levels:** warning at 85% used and critical at 95% (`DiskUsageRule::WARNING_PERCENT`, `CRITICAL_PERCENT`), constants until phase 5 (design section 18 item 3). Used counts ext4's root reserve (design section 6.1.2), so 100% is when services get "No space left on device". The sentence says so: "/ is 96% full (9.6 GiB of 10.0 GiB). At 100%, services get "No space left on device"." The percentage is compared exactly and shown rounded down. A total of 0 gives no result.
4. **Failures are confirmed** like the client's other checks (`CheckStates::recordConfirmed()`, 2 in a row), so a large temporary file does not send an email. A filesystem that stops being reported is withdrawn by the daily job's rule for subjects that disappear (design section 6.3 item 8), not on the first heartbeat without it, because a filesystem that cannot be measured is simply skipped for that heartbeat (design section 6.1.2 item 5).
5. **Both are alert only** (D3): `Ladder` returns no step, and the breaker does not count them.
6. **While `/` or `/boot` is critical, no reboot and no reset on that instance:** a reboot or a reset with a full system disk can leave the instance unable to boot, and recovering it means attaching the disk to another instance. The rule lives in `InstanceRemediation`, which reads its own data: an open `disk_usage_critical` incident on `/` or `/boot`, with `passing_since` null. The ladder stops there and is exhausted, whichever incident was climbing it, `heartbeat_age` included: a silent client with a full disk is a likely pair, and the block keeps relying on the last reading. The page and the exhausted email say the full disk blocked it, and dry-run shows the block as well. Restarts are not blocked, and neither are incidents already past the reboot.
7. **The critical email** has its subject marked: `[Maguari] CRITICAL: disk / at 96% on geonode-1`. Phase 3 marks only the subject; importance levels chosen per kind of alert come in phase 5 (design section 18 item 3). The percentage reaches Notifications as a value in `monitoring.check_failed` and `remediation.incident_opened` (`percent`, optional), never parsed from a sentence. A critical email never goes into a summary (design section 13.3 item 9), so its subject is always seen. Its resolved email has the normal subject.
8. **Pages:** both levels appear in the instance page's checks and the dashboard's "Checks" column. The disk size check of the daily job (design section 6.3.1) is a different check and stays as it is.

## Known limits

- **Maguari's own actions can open incidents.** Restarting nginx for a local HTTP incident can fail nginx's own service check for 2 heartbeats, and a slow restart surely will. That opens a second incident and sends its emails. It does not cause a second restart (P5 item 3). Same for `heartbeat_age` during a reboot: it opens and resolves with its emails as usual (answer 8). Design section 7.2's "failures are ignored" during the grace period means no action, not no incident: Monitoring writes the failure only once, so an instance that never came back would stay unreported for up to an hour.
- **A dead client looks like a dead instance.** D3 sends silence straight to a reset, so a crashed client resets a healthy instance, even while its web app still answers. Accepted for now (answer 10): an app can answer while the instance is badly hurt. `Restart=always` in the client's unit (phase 4) makes this rare.
- **The clock:** `expires_at` is judged by the client's clock, which the server allows to be up to 5 minutes off (section 5.5), the same as the expiry. A client with a clock that far off answers `expired`, and the page shows it. Compute Engine instances keep time well, so this stays a limit.
- **A disk hovering around a level** opens and resolves incidents, at most about one pair every 7 minutes because of the confirmation and the 5 minutes of passing. There is no separate level for going back down. If it bothers in practice, phase 5 can add one.
- **Docker deployments:** a restart can only target `docker.service`, which restarts every container on the instance (design section 6.1). Associating it with a check is the administrator's choice.
- **Without the settings hierarchy,** the 7 minute grace period and the 5 minute watch are the same for all instances (D9). A service that takes more than 5 minutes to start reports `timed_out` and the ladder goes on, until phase 5 can raise it.

## Answers

1. **Q1, what "exhausted" means:** one reset per incident (`RESETS_PER_INCIDENT = 1`). If the check still fails after that reset's boot grace period, the ladder is exhausted and its email goes out. The limit of 3 resets per instance in 24 hours still applies across incidents. A reset lost to a dead tick is not retried: accepted. The number becomes configurable in phase 5 (design section 18 item 3, recorded by this commit).
2. **Q2, the next step's timing:** two restarts, without waiting when nothing can change. `failed` or `timed_out` moves on at once. `restarted` with a check still failing moves on as soon as the check has a result newer than the restart's end (P5 item 5). The limit of 3 restarts per instance per hour still applies.
3. **Q3, reboots:** opt-in on the instance, in one command run once: `set` replaces the list and the permission (P4 item 1). `add` and `remove` were optional and are left out. The instance page shows the command ready to copy (P4 item 7). Phase 4 may fold it into installation.
4. **Q4, reboot limit:** at most 3 reboots per instance in 24 hours (P5 item 3).
5. **Q5, an action that could not run:** wait and retry the same step; never climb the ladder (P5 item 4).
6. **Q6, dry-run:** dry-run commands reach the client (P8 item 5).
7. **Q7, testing:** the reverse SSH tunnel for the reboot on a disposable instance, and `compute.instances.reset` removed from the development service account's role in `pessoal-303713` (P10 item 4). The steps go in `docs/WORKFLOW.md` in the step that needs them.
8. **Q8, email during a reboot:** the `heartbeat_age` incident opens and resolves with its emails as usual (known limits).
9. **Q9, signed responses:** yes, in step 3.2 (P2 item 7).
10. **Q10:** a `service` failure with reason `not_found` is alert only, like `login_refused` (P5 item 7). A silent client on an instance whose web app still answers is a known limit.
11. **Correction to D12:** the circuit breaker counts only `heartbeat_age` incidents, not `remote_http` (P9 item 1). `remote_http` never triggers an action, so counting it protects nothing, and a domain that stops resolving on several instances would trip the breaker and stop every other repair meanwhile. If the server's own network fails, heartbeats stop arriving from every instance, which `heartbeat_age` alone detects.

### After the review of those answers

12. **Reboot timing:** accepted. About 2 minutes per restart is the floor while the client speaks once a minute. (The extra heartbeat of answer 13 brings a unit that fails at once down to about 1.)
13. **Real-time mode** stays out of this phase. When the watch of a restart ends, the client sends one extra heartbeat at once, outside its schedule, then returns to its normal schedule (P6 item 6).
14. **Two resets for one problem:** kept as planned (P5 item 3).
15. **Reboot not allowed on the instance:** the ladder stops after the second restart and is exhausted, with its email. "Never reboot" joins "Never reset" on the instance page. The server may only restrict what the instance allows, never grant more (P5 item 8). This replaces what Q3 proposed, going on to the reset.
16. **A disk usage check,** in phase 3, as its own step before the reboot, with warning at 85% and critical at 95%, both alert only, the critical email marked and a critical `/` or `/boot` blocking reboot and reset (P11). Design section 18 item 3 gets importance levels for alerts and names the two values.

## Found while implementing

### Step 3.1

- **No `remediation_settings` yet.** Nothing can execute before step 3.3, so a stored setting with no switch would mean nothing. `RemediationApi::DRY_RUN` is a constant until the switch comes with real restarts, and the table with it.
- **`remediation_actions` has no `state`, `started_at`, `duration_seconds`, `detail` or `command_id` yet** (P1 item 3). A dry-run action is finished when it is decided, and only `would_run` exists as an outcome. Steps 3.2 and 3.3 add them with what fills them, and so with `failed` and `timed_out` moving on at once and with the retries of an action that could not run.
- **`remediation_incidents.ladder_hold`** is new, so the page can say why the ladder is waiting on a limit ("The instance reached its limit of 3 restarts per hour."). P3 item 4 asked for reasons without saying where they live.
- **The time limit** is enforced between instances, as P3 item 3 says, and nothing else is deferred yet, because a dry run reaches no network.
- **The association of a unit with a check** is deleted on `monitoring.check_withdrawn`, which is written only for a check that had an outcome. A check removed before its first result leaves its row behind, and adding the same check again brings the association back. Harmless, so left as it is.
- **An incident opened in maintenance that resolves after it,** before the hourly reconciliation sent its late email, gives up its `resolved` email too: nobody was told it opened. P8 item 3 did not cover this.
- **Smoke run** with the real `maguari-server tick` on a temporary database: an automatic instance with a service incident open for 200 seconds printed "Dry run on instance 5: I would restart nginx.service now." and stored the action and step 1, and the next tick decided nothing more. The new migrations also applied to a copy of the development database.

#### Manual check (step 3.1)

Nothing here can touch an instance: every action is a dry run. In separate terminals with the development environment loaded, the web app, the tick loop and the client's loop as in `docs/WORKFLOW.md`. First apply the migrations:

```
server/bin/maguari-server migrate
```

1. On the development instance's page, "Remediation" says Alert-only. Under "Services", add a service that exists and that you can stop, for example `cron`. Stop it and wait for the incident, about 2 minutes. No action appears, however long you wait.
2. Set the mode to Automatic. About 3 minutes later the tick prints `Dry run on ...: I would restart cron.service now.`, the incident's Ladder column says Restart, and the sentence shows under "Actions" and on the Logs screen.
3. Keep it stopped. A minute or two later it says the same again and the column says Restart again, then "I would reboot the instance now.". Seven minutes later "I would reset the instance now.", and seven minutes after that the column says exhausted. None of these happened: check that the instance's uptime did not change.
4. Start the service again. The incident resolves 5 minutes later.
5. Under "Local HTTP", add `http://127.0.0.1:8099/` (nothing listens there). It only alerts. Name `cron` under "Restart when failing" and it climbs the ladder too. With the service check also failing, one restart serves both.
6. Add a service that does not exist, for example `maguari-test`. It fails and never acts.
7. Start a maintenance 10 minutes ahead and stop the service again. The incident opens and shows, no action appears, and the Logs screen shows its email given up with "The instance was in maintenance."
8. Press "End maintenance". The wait starts over, and the ladder acts 3 minutes later. Within the hour, the incident's email arrives, "Detected late".
9. Set the mode back to Alert-only. The development client is enrolled as instance-sig, which must stay alert-only from step 3.3 on (P10 item 3). Automatic is safe in this step only because nothing can execute.

## Design changes once approved

Sections 2.1 (the flow, the decision as a tick step), 3.1 item 7 (Remediation's layers, the second aggregate and the recorded exception of D1), 5.2 and 5.3 (the protocol, P2, and the extra heartbeat in step 3.3), 6.1.2 and 6.2 (the disk usage check, step 3.4), 5.4 and 12.1 (the allowlist command replaces the package's sudoers generation, P4), 5.5 (signed responses, in step 3.2), 7.1 to 7.3 (the ladder and how its steps follow outcomes and results, the safeguards, including the breaker counting only `heartbeat_age` incidents in section 7.2 item 5, D2's setting, D9's default, the new rows for resets per incident and reboots per 24 hours, and the cooldowns' narrower meaning), 7.4 item 4 (the failure counter there becomes the ladder step), 8 (the reset, the status check, self-exclusion), 9.2 (the tables), 10.1 (the tick's order), 10.4 and 13.3 (actions on the Logs screen, the new emails, maintenance) and 17 (phase 3's steps), each in the step that implements it. `docs/WORKFLOW.md` gets the hand-installed sudoers entry in step 3.3 (D15), the reverse tunnel in step 3.5 and the role change in step 3.6.

This plan's commit already records in the design what no step of this phase would: the three alert kinds in section 18 item 3, the setup wizard (sections 17 and 18 item 2), the plan's name in section 17 and, with the answers, the resets per incident, the disk usage levels and the importance levels in section 18 item 3.
