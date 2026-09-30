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

## Decisions needed before implementation

The design does not settle the points below. Each has a recommendation. Please confirm or change them; the agreed answers go into `docs/DESIGN.md` in the implementation commit.

**D1. How the setup token is issued in this step.** Design 12.2 has `maguari-server setup --domain <domain> --email <email>`, which runs certbot, verifies HTTPS and then prints the setup URL. certbot and HTTPS verification are packaging work and cannot be tested locally.
- *Recommendation:* add a separate subcommand `maguari-server issue-setup-token --base-url=<url>` that only migrates the database, issues the token and prints the URL. Later, `setup` calls the same code after certbot succeeds. The same subcommand also matches the recovery path in design 11.1 item 4 ("issuing a new one-time setup token").
- *Alternative:* implement `setup --domain --email` now with the certbot part stubbed out.

**D2. Setup token lifetime.** The design says one-time and invalidated when setup finishes, but gives no expiry.
- *Recommendation:* expires 24 hours after issue. Only one token exists at a time: issuing a new one replaces the old one. Stored as a SHA-256 hash, never in plain text.

**D3. Issuing a token when an administrator already exists.** The wizard only creates the first administrator.
- *Recommendation:* the CLI refuses with a clear message, and `/auth/setup` returns 404 once an administrator exists. Recovery (design 11.1 item 4) is a later step.

**D4. Seed config file.** Design 11.5.1 item 7 says applying seeded values happens "once SQLite storage exists (a later MVP step)". This step introduces SQLite.
- *Recommendation:* still defer applying the seed, to keep this step small. The wizard prefills the name and email fields from the seed file when it is present and valid (read only, nothing marked as seeded). The allowlist is only used by Google sign-in, which is after the MVP.
- *Alternative:* apply the seed in this step (write administrator name, email and allowlist to settings, record that seeding is done).

**D5. Session storage.**
- *Recommendation:* PHP's native sessions with the default file handler, driven by a small `SessionMiddleware`. The middleware turns off PHP's own cookie handling (`session.use_cookies=0`, `session.cache_limiter=''`), reads the session ID from the request cookie and sets the cookie on the PSR-7 response itself, with `HttpOnly`, `Secure`, `SameSite=Lax` and `Path=/`. This keeps the cookie flags explicit and testable, and `slim/csrf` works with it unchanged (it uses `$_SESSION` by default).
- *Alternative:* sessions stored in SQLite (`access_sessions`, session ID hashed). Easier to revoke and no global state in tests, but more custom code and a custom storage adapter for `slim/csrf`.

**D6. Session lifetime.**
- *Recommendation:* 2 hours idle timeout and 12 hours absolute timeout, both enforced by timestamps kept in the session. The session ID is regenerated at login and at setup completion (design 11.3). Sign-out destroys the session.

**D7. Rate limiting on `/auth/*` (design 10.2 and 11.3).** Step 2 left it out because there was nowhere to keep counters. This step adds both the database and the first password form on the public internet.
- *Recommendation:* include it, scoped to failed attempts: at most 10 failed login or setup submissions per IP address in 15 minutes, then `429` until the window passes. Counters in `access_login_attempts`. The IP comes from `REMOTE_ADDR`, which is correct because Cloudflare runs DNS-only (design 11.4). fail2ban remains packaging work.
- *Alternative:* defer rate limiting to its own step and accept an unthrottled login form until then.

**D8. CSRF and sessions on `/auth/*`.** Design 10.2 lists session and CSRF middleware only for `/admin/*`, but design 11.3 requires CSRF on every state-changing request, and the setup and login forms are state-changing (login CSRF).
- *Recommendation:* `/auth/*` also gets the session and CSRF middleware. Update the table in design 10.2 accordingly.

**D9. Timestamp column convention.** This step creates the first tables, so it sets the convention for all later ones.
- *Recommendation:* integer Unix seconds (always UTC by definition), column names ending in `_at` (`created_at`, `expires_at`). Compact and cheap to compare, which suits the e2-micro and the run arithmetic in design 9.1. The `start_ts` and `end_ts` names in design 9.1 stay as they are.
- *Alternative:* ISO 8601 UTC text (`2026-09-30T14:00:00Z`), easier to read in the `sqlite3` shell.

**D10. Password rules.**
- *Recommendation:* minimum 12 characters, maximum 1,024 (to bound hashing cost), no composition rules. Hashed with `password_hash($password, PASSWORD_ARGON2ID)` and default cost parameters.

**D11. Database location and migrations.**
- *Recommendation:* the database lives at `/var/lib/maguari/maguari.sqlite`, created with mode 0600. For development and tests only, the path can be overridden with the `MAGUARI_DATABASE` environment variable. Each context keeps its migrations as numbered `.sql` files in `server/src/<Context>/Migrations/` (design 3.1 item 2). A small runner in `Kernel/` applies pending ones and records them in `kernel_migrations`. Migrations run from the CLI (`maguari-server migrate`, and implicitly from `issue-setup-token`), never on a web request. If the database is missing or not migrated, web routes that need it return `503` with a short message.

## Out of scope for this step

- Google sign-in, the email allowlist and the "local login is disabled once Google is configured" rule (after the MVP).
- certbot, HTTPS verification, nginx, php-fpm and packaging (the `setup` subcommand).
- Recovery commands (re-enabling local login, resetting a password).
- The audit log (design 9.2), the secrets key file and sodium encryption (nothing secret needs encrypting yet).
- Any real admin UI beyond the signed-in placeholder page. No CSS or JavaScript (the CSP from step 2 stays strict).
- HMAC and the `/api/client/*` group, which stays fail-closed.

## Repository changes

```
server/
  composer.json                            add "slim/csrf": "^1.5" and "ext-pdo_sqlite"
  bin/maguari-server                       add "migrate" and "issue-setup-token" subcommands
  templates/                               plain PHP templates, no template engine
    layout.php
    setup.php                              wizard form: name, email, password, confirmation
    login.php
    admin.php                              "Signed in as ..." and a sign-out form
    message.php                            simple status pages (setup unavailable, not ready)
  src/
    Kernel/
      Clock.php                            interface: now(): int (Unix seconds, UTC)
      SystemClock.php
      Database/
        Connection.php                     opens PDO SQLite with foreign_keys=ON, journal_mode=WAL, busy_timeout
        Migrator.php                       applies pending per-context .sql migrations, tracks them in kernel_migrations
    Access/
      AccessApi.php                        extended: issueSetupToken, isSetupComplete, completeSetup, authenticate,
                                           administratorById, recordFailedAttempt, isRateLimited
      Administrator.php                    value object: id, name, email
      AdministratorRepository.php
      SetupTokens.php                      issue (random 32 bytes, base64url), verify by SHA-256 hash, consume
      PasswordHasher.php                   Argon2id hashing and verification, dummy verify for unknown emails
      LoginThrottle.php                    failed attempts per IP (D7)
      Exception/
        SetupNotAllowed.php
        InvalidSetupInput.php
      Migrations/
        0001_access_administrators.sql
        0002_access_setup_tokens.sql
        0003_access_login_attempts.sql
    Http/
      App.php                              takes its dependencies (AccessApi, Clock, session settings) as arguments;
                                           /admin loses FailClosedMiddleware and gets RequireAdministratorMiddleware
      View.php                             renders a template with htmlspecialchars escaping
      Controller/
        SetupController.php                GET and POST /auth/setup
        LoginController.php                GET and POST /auth/login
        AdminController.php                GET /admin, POST /admin/logout
      Middleware/
        SessionMiddleware.php              D5 and D6
        RequireAdministratorMiddleware.php redirects to /auth/login when not signed in
        RateLimitMiddleware.php            D7, on POST routes under /auth
        SecurityHeadersMiddleware.php      add Referrer-Policy: no-referrer (keeps the setup token out of Referer)
  public/index.php                         builds dependencies and passes them to App::create
  tests/
    Kernel/Database/MigratorTest.php
    Access/SetupTokensTest.php
    Access/AccessApiTest.php
    Access/LoginThrottleTest.php
    Http/AppTest.php                       updated for the new /admin behavior
    Http/SetupFlowTest.php
    Http/LoginFlowTest.php
docs/DESIGN.md                             record the agreed answers to D1 to D11
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
1. Opens or creates the database and runs pending migrations.
2. Refuses if an administrator exists (D3).
3. Replaces any existing token with a new one and prints `<base-url>/auth/setup?token=<token>` and its expiry time in UTC. The token is printed once and never logged.

**Setup wizard.**
1. `GET /auth/setup?token=...`: if setup is complete, 404. If the token is missing, unknown or expired, a generic "This setup link is invalid or expired" page with status 403. Otherwise the form, carrying the token and the CSRF fields as hidden inputs, with name and email prefilled from the seed file (D4). This GET changes nothing: it neither consumes the token nor records an attempt (only failed POSTs count towards the rate limit).
2. `POST /auth/setup`: CSRF check, rate limit check, token check again, then input validation (non-empty name, valid email, password rules D10, matching confirmation). On error the form comes back with messages and without the password values. On success, in one transaction: create the administrator and delete the token. Then regenerate the session ID, sign the administrator in and redirect (303) to `/admin`.

**Login.**
1. `GET /auth/login`: if no administrator exists yet, a page saying setup is not complete. If already signed in, redirect to `/admin`. Otherwise the form.
2. `POST /auth/login`: CSRF check, rate limit check, then look up the email and verify the password. An unknown email still runs a dummy Argon2id verification, so response time does not reveal which emails exist. A failure shows one generic message and records a failed attempt. A success regenerates the session ID, stores the administrator ID and timestamps, then redirects (303) to `/admin`.

**Admin.**
1. `GET /admin`: requires a valid session (else 303 to `/auth/login`). Shows "Signed in as <name>" and a sign-out form.
2. `POST /admin/logout`: CSRF check, destroy the session, expire the cookie, redirect (303) to `/auth/login`.

A CSRF failure returns 400 with a short plain message. No route changes state on GET.

## Tests

Tests use a temporary SQLite file per test, a fixed clock and a temporary session save path. Argon2id cost is not lowered in tests; if the suite gets slow, that will be raised as a question rather than changed silently.

1. **Migrator:** applies all migrations on an empty database, is a no-op when run again, applies only new ones.
2. **SetupTokens:** a token verifies before expiry and not after; only the hash is stored; a new token replaces the old one; a consumed token no longer verifies.
3. **AccessApi:** setup refuses when an administrator exists; passwords are stored as Argon2id hashes (`password_get_info`); authentication succeeds and fails as expected, and emails are matched case-insensitively.
4. **LoginThrottle:** the eleventh failure inside 15 minutes is limited; attempts older than the window no longer count.
5. **Setup flow (HTTP):** full happy path from GET with token to `/admin`; invalid and expired tokens; missing or wrong CSRF token; validation errors; setup returns 404 once complete; the token cannot be used twice.
6. **Login flow (HTTP):** happy path; wrong password and unknown email give the same message; session cookie has `HttpOnly`, `Secure`, `SameSite=Lax`; the session ID changes at login; idle and absolute timeouts; sign-out ends the session; `GET /admin` without a session redirects to login; rate limit returns 429.
7. **Existing `AppTest`:** `/api/client/heartbeat` stays 401; `/admin` now redirects to login instead of returning 401; security headers (including `Referrer-Policy`) on every response.

## Verification

1. From `server/`, add the dependency:
   ```
   composer require slim/csrf
   ```
2. Run the normal suite from `server/`:
   ```
   vendor/bin/phpunit
   ```
3. Run the suite on Ubuntu 22.04 with PHP 8.1 from the repository root (confirms Argon2id and `pdo_sqlite` are available there too):
   ```
   scripts/test-ubuntu-22.04.sh
   ```
4. Manual check with the built-in server, from `server/`. Use `localhost` rather than `127.0.0.1`, because browsers only send `Secure` cookies over plain HTTP to `localhost`:
   ```
   export MAGUARI_DATABASE="$PWD/var/dev.sqlite"
   ```
   ```
   bin/maguari-server issue-setup-token --base-url=http://localhost:8080
   ```
   ```
   php -S localhost:8080 -t public
   ```
   Open the printed URL in a browser, complete the wizard, confirm `/admin` shows the signed-in page, sign out, sign in again and confirm a second `issue-setup-token` is refused. `server/var/` is added to `server/.gitignore`.
