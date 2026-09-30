# MVP Step 3: Setup and local login

## Context

Step 2 (`docs/plans/mvp-step-2.md`) built the Slim app with the three route groups. `/admin/*` and `/api/client/*` are fail-closed (401) and `/auth/login` returns 501. There is no database yet.

Step 3 is: "Setup and local login: one-time setup token, setup wizard creating the local administrator (Argon2id), sessions and CSRF; introduces SQLite" (design section 15). It belongs to the **Access** context (design section 2.1), which owns administrators, the setup token and wizard, the seed config file and sessions.

Relevant design sections: 9 (SQLite, Argon2id), 10.2 (route groups and middleware), 11.1 (admin login), 11.2 (initial setup), 11.3 (hardening), 11.5 (seed config file) and 12.2 (installation and CLI).

What this step delivers, end to end:

1. A CLI subcommand creates the database (if needed), issues a one-time setup token and prints the setup URL.
2. Opening that URL shows the setup wizard. Submitting it creates the local administrator (password hashed with Argon2id), invalidates the token and signs the administrator in.
3. `/auth/login` shows a login form. A correct email and password open a session.
4. `/admin` shows a minimal signed-in page with a sign-out button. Every state-changing request is CSRF-protected.
5. `/api/client/*` stays fail-closed (HMAC is step 6).

## Decisions (confirmed)

The first version of this plan listed these as open questions with recommendations. The answers below are confirmed and are recorded in `docs/DESIGN.md` in the implementation commit.

**D1. Issuing the setup token.** A separate subcommand, `maguari-server issue-setup-token --base-url=<url>`, migrates the database, issues the token and prints the URL. It rejects a base URL that is not `https://`, except for the host `localhost` (for local development). Issuing a token invalidates any previous one. Later, `setup --domain --email` calls the same code after certbot succeeds, and the same subcommand serves the recovery path in design 11.1 item 4.

**D2. Setup token lifetime.** 1 hour. Only one token exists at a time. Stored as a SHA-256 hash, never in plain text.

**D3. Once an administrator exists,** `issue-setup-token` refuses with a clear message and `/auth/setup` returns 404. Recovery is a later step.

**D4. Seed config file.** Applying the seed stays deferred. The wizard prefills the name and email fields from the seed file when it is present and valid (read only, nothing is marked as seeded).

**D5. Sessions.** PHP's native sessions with the file handler, driven by `SessionMiddleware`:
- PHP's own cookie handling is off (`session.use_cookies=0`, `session.use_trans_sid=0`, `session.cache_limiter=''`). The middleware reads the session ID from the request cookie and sets the cookie on the PSR-7 response itself, with `HttpOnly`, `Secure`, `SameSite=Lax` and `Path=/`.
- `session.use_strict_mode=1`, so an unknown session ID is replaced instead of adopted.
- A dedicated `session.save_path` (a `sessions/` directory next to the database, `/var/lib/maguari/sessions` in production, created with mode 0700) with `session.gc_maxlifetime` set to the 12 hour absolute timeout. Ubuntu disables PHP's own session garbage collection (`session.gc_probability=0`) and cleans only the default save paths from a cron job, so for the dedicated path the middleware enables PHP's probabilistic garbage collection (`gc_probability=1`, `gc_divisor=100`).
- Idle (2 hours) and absolute (12 hours) timeouts are enforced by the middleware from timestamps stored in the session, not left to garbage collection.
- The session ID is regenerated at login and at setup completion. Sign-out destroys the session.
- `slim/csrf` uses the session (`$_SESSION`) as its storage, in persistent token mode (one token per session, so several tabs and the back button keep working).

**D6. Session lifetime.** 2 hours idle, 12 hours absolute (see D5).

**D7. Rate limiting on `/auth/*`.** At most 10 failed login or setup submissions per IP address in 15 minutes, then `429` until the window passes. Counters in `access_login_attempts`. The IP comes from `REMOTE_ADDR`, which is correct because Cloudflare runs DNS-only (design 11.4). Only POSTs are counted, so no GET changes state. fail2ban remains packaging work.

**D8. CSRF and sessions on `/auth/*`.** The forms under `/auth/*` (setup and login) also get the session and CSRF middleware. Design 10.2 says CSRF applies to forms under `/auth/*`, because the future OAuth callback is protected by the `state` parameter instead.

**D9. Timestamps.** Integer Unix seconds (UTC by definition), column names ending in `_at`. `start_ts` and `end_ts` in design 9.1 are renamed to `start_at` and `end_at`.

**D10. Password rules.** Minimum 12 characters, maximum 1,024, no composition rules. Hashed with `password_hash($password, PASSWORD_ARGON2ID)` and default cost parameters.

**D11. Database location and migrations.** `/var/lib/maguari/maguari.sqlite`, created with mode 0600, overridable with the `MAGUARI_DATABASE` environment variable for development and tests only. SQLite runs in WAL mode with a busy timeout (5 seconds) and foreign keys on. Each context keeps its migrations as numbered `.sql` files in `server/src/<Context>/Migrations/` (design 3.1 item 2). A runner in `Kernel/Database/` finds them by scanning those folders (so Kernel never names a context), applies pending ones and records them in `kernel_migrations`. Migrations run from the CLI (`maguari-server migrate`, and implicitly from `issue-setup-token`), never on a web request. If the database is missing or not fully migrated, `/admin/*` and `/auth/*` return `503` with a short message.

**Referrer-Policy.** `Referrer-Policy: no-referrer` is sent on every response (set in `SecurityHeadersMiddleware`), which covers the setup pages and keeps the setup token out of `Referer` headers.

## Out of scope for this step

- Google sign-in, the email allowlist and the "local login is disabled once Google is configured" rule (after the MVP).
- certbot, HTTPS verification, nginx, php-fpm and packaging (the `setup` subcommand). File ownership of the database and session directory for the app user is a packaging concern.
- Recovery commands (re-enabling local login, resetting a password).
- The audit log (design 9.2), the secrets key file and sodium encryption (nothing secret needs encrypting yet).
- Any real admin UI beyond the signed-in placeholder page. No CSS or JavaScript (the CSP from step 2 stays strict).
- HMAC and the `/api/client/*` group, which stays fail-closed.

## Repository changes

```
server/
  composer.json                            add "slim/csrf": "^1.5" and "ext-pdo_sqlite"
  phpunit.xml                              bootstrap tests/bootstrap.php
  .gitignore                               add /var/ (local development database)
  bin/maguari-server                       add "migrate" and "issue-setup-token" subcommands
  templates/                               plain PHP templates, no template engine
    layout.php
    setup.php                              wizard form: name, email, password and confirmation
    login.php
    admin.php                              "Signed in as ..." and a sign-out form
    message.php                            simple status pages (invalid link, setup not complete, not ready)
  src/
    Kernel/
      Clock.php                            interface: now(): int (Unix seconds, UTC)
      SystemClock.php
      Database/
        Database.php                       database path, lazy PDO with WAL, busy timeout and foreign keys
        Migrator.php                       applies pending per-context .sql migrations, tracks them in kernel_migrations
    Access/
      AccessApi.php                        extended: setup, authentication, administrator lookup, login throttling
      Administrator.php                    value object: id, name, email
      AdministratorRepository.php
      IssuedSetupToken.php                 value object: token, expiresAt
      SetupTokens.php                      issue (random 32 bytes, base64url), verify by SHA-256 hash, consume
      PasswordHasher.php                   Argon2id hashing and verification, dummy verification for unknown emails
      LoginThrottle.php                    failed attempts per IP (D7)
      Exception/
        SetupNotAllowed.php
        InvalidSetupInput.php
      Migrations/
        0001_access_administrators.sql
        0002_access_setup_tokens.sql
        0003_access_login_attempts.sql
    Http/
      App.php                              takes AccessApi, session path and clock; /admin loses FailClosedMiddleware
      Session.php                          the current session as seen by controllers (sign in, sign out, administrator ID)
      View.php                             renders a template with htmlspecialchars escaping
      RequestIp.php                        the client IP (REMOTE_ADDR only, D7)
      Controller/
        SetupController.php                GET and POST /auth/setup
        LoginController.php                GET and POST /auth/login
        AdminController.php                GET /admin, POST /admin/logout
        FormInput.php                      reads string fields from query or form data
      Middleware/
        SessionMiddleware.php              D5 and D6
        CsrfMiddleware.php                 builds the slim/csrf Guard per request, after the session has started
        RequireAdministratorMiddleware.php redirects to /auth/login when not signed in
        RateLimitMiddleware.php            D7, on POST routes under /auth
        SecurityHeadersMiddleware.php      adds Referrer-Policy: no-referrer
  public/index.php                         unchanged apart from calling App::fromEnvironment()
  tests/
    bootstrap.php                          applies the session ini settings before PHPUnit prints anything
    Support/                               temporary environment (database, session path, fixed clock) and a cookie-keeping test browser
    Kernel/Database/MigratorTest.php
    Access/SetupTokensTest.php
    Access/AccessApiTest.php
    Access/LoginThrottleTest.php
    Http/AppTest.php                       updated for the new /admin behavior
    Http/SetupFlowTest.php
    Http/LoginFlowTest.php
docs/DESIGN.md                             record D1 to D11
```

## Tables

```
kernel_migrations(context TEXT, name TEXT, applied_at INTEGER, PRIMARY KEY (context, name))
access_administrators(id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
                      password_hash TEXT NOT NULL, created_at INTEGER NOT NULL)
access_setup_tokens(token_hash TEXT PRIMARY KEY, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)
access_login_attempts(id INTEGER PRIMARY KEY, ip TEXT NOT NULL, attempted_at INTEGER NOT NULL)
```

Emails are stored lowercased, the same normalization as the seed file (design 11.5.1 item 4). `kernel_migrations` is the one table without a context prefix, because the migration runner is a generic helper, not a context.

## Flows

**Issue a setup token (CLI).** `maguari-server issue-setup-token --base-url=https://maguari.example.com`
1. Validates the base URL (D1).
2. Opens or creates the database and runs pending migrations.
3. Refuses if an administrator exists (D3).
4. Replaces any existing token with a new one and prints `<base-url>/auth/setup?token=<token>` and its expiry time in UTC. The token is printed once and never logged.

**Setup wizard.**
1. `GET /auth/setup?token=...`: if setup is complete, 404. If the token is missing, unknown or expired, a generic "This setup link is invalid or expired" page with status 403. Otherwise the form, carrying the token and the CSRF fields as hidden inputs, with name and email prefilled from the seed file (D4). This GET changes nothing: it neither consumes the token nor records an attempt.
2. `POST /auth/setup`: CSRF check, rate limit check, token check again (an invalid token counts as a failed attempt), then input validation (non-empty name, valid email, password rules D10, matching confirmation). On error the form comes back with messages and without the password values. On success, in one transaction: create the administrator and delete the token. Then regenerate the session ID, sign the administrator in and redirect (303) to `/admin`.

**Login.**
1. `GET /auth/login`: if no administrator exists yet, a page saying setup is not complete. If already signed in, redirect to `/admin`. Otherwise the form.
2. `POST /auth/login`: CSRF check, rate limit check, then look up the email and verify the password. An unknown email still runs a dummy Argon2id verification, so response time does not reveal which emails exist. A failure shows one generic message and records a failed attempt. A success regenerates the session ID, stores the administrator ID and timestamps, then redirects (303) to `/admin`.

**Admin.**
1. `GET /admin`: requires a valid session (else 303 to `/auth/login`). Shows "Signed in as <name>" and a sign-out form.
2. `POST /admin/logout`: CSRF check, destroy the session, expire the cookie, redirect (303) to `/auth/login`.

A CSRF failure returns 400 with a short plain message. No route changes state on GET.

## Tests

Tests use a temporary SQLite file per test, a fixed clock and one session save path for the whole suite (set in `tests/bootstrap.php`, because PHP refuses to change session settings once output has started). Argon2id cost is not lowered in tests.

1. **Migrator:** applies all migrations on an empty database, is a no-op when run again, reports whether the database is up to date.
2. **SetupTokens:** a token verifies before expiry and not after; only the hash is stored; a new token invalidates the old one; a consumed token no longer verifies.
3. **AccessApi:** setup refuses when an administrator exists; passwords are stored as Argon2id hashes (`password_get_info`); input validation; authentication succeeds and fails as expected, and emails are matched case-insensitively.
4. **LoginThrottle:** the eleventh failure inside 15 minutes is limited; attempts older than the window no longer count.
5. **Setup flow (HTTP):** full happy path from GET with token to `/admin`; invalid and expired tokens; missing or wrong CSRF token; validation errors; setup returns 404 once complete; the token cannot be used twice; `Referrer-Policy: no-referrer` on the setup page.
6. **Login flow (HTTP):** happy path; wrong password and unknown email give the same message; session cookie has `HttpOnly`, `Secure`, `SameSite=Lax`; the session ID changes at login; idle and absolute timeouts; sign-out ends the session; `GET /admin` without a session redirects to login; rate limit returns 429.
7. **Existing `AppTest`:** `/api/client/heartbeat` stays 401; `/admin` now redirects to login; `/admin` and `/auth/login` return 503 when the database is not ready; security headers on every response.

## Verification

1. Run the normal suite from `server/`:
   ```
   vendor/bin/phpunit
   ```
2. Run the suite on Ubuntu 22.04 with PHP 8.1 from the repository root (confirms Argon2id and `pdo_sqlite` are available there too):
   ```
   scripts/test-ubuntu-22.04.sh
   ```
3. Manual check with the built-in server, from `server/`. Use `localhost` rather than `127.0.0.1`, because browsers only send `Secure` cookies over plain HTTP to `localhost`:
   ```
   export MAGUARI_DATABASE="$PWD/var/dev.sqlite"
   ```
   ```
   bin/maguari-server issue-setup-token --base-url=http://localhost:8080
   ```
   ```
   php -S localhost:8080 -t public
   ```
   Open the printed URL in a browser, complete the wizard, confirm `/admin` shows the signed-in page, sign out, sign in again and confirm a second `issue-setup-token` is refused.
