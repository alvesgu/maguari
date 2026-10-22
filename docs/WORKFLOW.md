# How Maguari is built

This document describes how development works, so any new conversation (in claude.ai or Claude Code) can pick up where the last one stopped. `docs/DESIGN.md` says **what** Maguari is; this file says **how** we work on it.

## Sources of truth

Everything that matters lives in the repository, never only in a chat:

- `docs/DESIGN.md`: the design, every decision and its reasons. If code and design disagree, one of them is fixed in the same change.
- `docs/plans/`: one plan per MVP step or phase (`mvp-step-N.md`, `phase-N.md`), with the decisions, the steps and what was "Found while implementing".
- `CLAUDE.md`: conventions and working agreements for Claude Code, loaded automatically in every session.
- This file.

Chats are disposable. When a chat gets long or has been idle for days, start a new one; nothing is lost.

## Roles

- **Gustavo** decides, tests as a user and relays between the two tools.
- **claude.ai chat** is where design questions are discussed, plans are reviewed and prompts for Claude Code are written. It reads the repository through raw GitHub links pasted in the chat, for example `https://raw.githubusercontent.com/alvesgu/maguari/main/docs/DESIGN.md`.
- **Claude Code** (VS Code on Gustavo's Ubuntu 24.04 desktop) implements, tests, commits and pushes.

## The cycle

1. **Discuss first.** Before a phase starts, the questions that shape it are settled in the claude.ai chat.
2. **Plan without code.** Claude Code writes `docs/plans/phase-N.md` with steps, proposals and open questions, commits and pushes it, and writes no implementation code.
3. **Review the plan.** Gustavo pastes the raw link to the plan in the claude.ai chat; the answers go back to Claude Code as one message.
4. **One step at a time.** Claude Code implements one step, updates `docs/DESIGN.md` and the plan in the same commit, pushes and stops for review.
5. **Review each step** in the claude.ai chat, then send "Implement step N.M" with any corrections.

## Sessions and prompts

- **A new Claude Code session per phase**, and whenever a session has been idle for a long time (re-caching a long session costs hundreds of thousands of tokens). A new session needs no history: its first prompt starts with "Read `CLAUDE.md`" and names the plan file.
- **Type at least one sentence of your own** when pasting a long prompt. A paste alone arrives as an attachment, and Claude Code then asks before acting on it (for example before pushing).
- **End prompts with "Commit and push"** when that is wanted. `CLAUDE.md` also says to push after committing when both test suites pass.

## Testing

- **Before every commit:** the normal test suite (`vendor/bin/phpunit` from `server/`).
- **Once per step, before its final push:** the Ubuntu 22.04 container suite (`scripts/test-ubuntu-22.04.sh`), which runs the tests on PHP 8.1, the minimum supported version.
- **By hand, once per phase:** Gustavo tests the whole phase as a user, following a combined routine prepared in the claude.ai chat.

## Development environment

Development runs on native Ubuntu 24.04 (PHP 8.3), with the repository in `~/src/maguari`. Every terminal starts with:

```bash
cd ~/src/maguari
```

```bash
source scripts/dev-env.sh
```

Without it, commands fall back to the production paths (`/var/lib/maguari`, `/var/lib/maguari-client`) and fail with a hint.

The long-running programs, one per terminal, as each test needs them:

| Program | Command |
|---|---|
| Server | `server/bin/maguari-server migrate`, then `cd server` and `php -S localhost:8080 -t public` |
| Client | `client/bin/maguari-client run` |
| Tick | `while true; do server/bin/maguari-server tick; sleep 60; done` |
| Web app checks | `while true; do server/bin/maguari-server run-http-checks; sleep 60; done` |
| Mail catcher | `python3 -m aiosmtpd -n -l localhost:1025` (Email page: host `localhost`, port `1025`, no username) |

Notes:

- After pulling new code, restart the client and the loops: PHP loads code when a process starts.
- The tick must run continuously while testing heartbeat ages: after more than 3 minutes without ticks, the first ticks judge nothing late (the downtime rule).
- `php -S localhost:8080` listens on IPv6 (`::1`) on Ubuntu 24.04.
- Gustavo's desktop runs its own PostgreSQL on port 5432, so test containers use another port (for example 55432). Podman runs them without root.
- GCP access in development uses Application Default Credentials impersonating the `maguari-server` service account (design section 8). The development project is `pessoal-303713`; its instance `instance-sig` hosts a real site and must never be reset (design section 17, phase 3 rule).

## Gustavo's preferences

- Replies in the language Gustavo writes in.
- No Oxford commas and no em dashes in prose and documentation.
- Each shell command in its own code block.
- On Ubuntu with GNOME, recommend `gnome-text-editor`; never `nano`, `vi` or `gedit`.
- State assumptions, present interpretations instead of picking one silently, push back when a simpler approach exists, and ask when something is unclear.
- Never suggest a command and then retract it in the same reply.

## Where we are

See `docs/DESIGN.md` section 17 (road to 1.0) for the phases. As of 2026-10-22:

- MVP: done.
- Phase 1 (incidents and alerts): done and tested.
- Phase 2 (more checks): done and tested; two small fixes were requested (local HTTP trying `127.0.0.1` then `::1` for `localhost`, and keeping the latest "not checked" result for display).
- Next: Phase 3 (remediation), planned in the claude.ai chat first.
