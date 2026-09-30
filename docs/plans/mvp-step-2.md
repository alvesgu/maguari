# MVP Step 2: Run the website (Slim app skeleton with the three route groups)

## Context

Step 1 (`docs/plans/mvp-step-1.md`) established `server/`'s skeleton, PSR-4 autoloading and the Access context's seed config reader. No HTTP layer exists yet. Step 2 is: "Run the website (Slim app skeleton with the three route groups)" (design section 15).

Design section 10.2 already fully specifies the three route groups and their middleware:

| Group | Middleware |
|---|---|
| `/admin/*` | Session check, CSRF check, security headers |
| `/api/client/*` | HMAC verification, per-client rate limit |
| `/auth/*` | IP rate limiting |

None of the real middleware can be built yet: sessions and CSRF need the Access context's login flow (not an MVP step on its own; see open question below), HMAC verification needs client secrets from the Clients context (MVP step 6), and both forms of rate limiting need somewhere to keep counters. Building any of that now would go beyond this step, per `CLAUDE.md` rule 4. Section 10.2 itself needs no changes: it already describes the target state this step is working towards, so no `docs/DESIGN.md` edits are needed for this step.

Decisions confirmed with the user:
- **Middleware scope:** wire the three route groups with one placeholder route each. Apply only security headers now (self-contained, no dependencies). `/admin/*` and `/api/client/*` get a temporary fail-closed middleware that rejects every request with `401` until the real session/CSRF and HMAC middleware replace it in the steps that need them. No always-pass stubs. `/auth/*` gets no fail-closed middleware; its placeholder route returns `501 Not Implemented` directly.
- **SQLite:** deferred entirely. This step is pure HTTP/routing skeleton, no database. SQLite is introduced in whichever step first needs to persist something (expected to be step 3 (now step 4), adding a GCP project).
- **Local verification:** development runs natively on Ubuntu 24.04 with PHP 8.3, so all commands and paths below are Linux-style. The PHP 8.1 minimum is checked by running the suite on Ubuntu 22.04 with `scripts/test-ubuntu-22.04.sh`. Verification uses PHP's built-in server (`php -S`) against `server/public/`. nginx and php-fpm configuration remain entirely a packaging concern (design section 12.2), not part of this step.

## Repository scaffolding (new)

```
server/
  composer.json                          add "slim/slim" and "slim/psr7" to require
  public/
    index.php                            front controller: builds the Slim app and runs it
  src/
    Http/
      App.php                            builds and returns the configured Slim App (used by public/index.php and by tests)
      Middleware/
        SecurityHeadersMiddleware.php     adds CSP, HSTS and frame-ancestors headers to every response (design 11.3)
        FailClosedMiddleware.php          temporary: rejects every request with 401; attached to /admin and /api/client groups only
  tests/
    Http/
      AppTest.php                        one test per placeholder route, plus a security-headers assertion and a 404 check
```

No changes to `server/bin/maguari-server`, `server/src/Access/`, or `docs/DESIGN.md`.

## Implementation details

**`server/composer.json`**: add `"slim/slim": "^4.12"` and `"slim/psr7": "^1.8"` (the plan originally said `^3.7`, but slim/psr7 has no 3.x release) to `require`. `slim/csrf` is not added yet: nothing uses it until real CSRF checks land alongside session support.

**`server/src/Http/App.php`**: a small factory, `App::create(): \Slim\App`.
- Builds the app with Slim's `AppFactory` (using `slim/psr7` as the PSR-7 implementation, no PSR-7 choice ambiguity).
- Adds `SecurityHeadersMiddleware` globally, so every response carries the headers, including the 401 and 501 placeholders.
- Adds Slim's routing middleware and a basic error-handling middleware (so unmatched routes 404 cleanly and uncaught exceptions don't leak stack traces).
- Defines the three route groups:
  - `/admin`: `FailClosedMiddleware` on the group, one placeholder route `GET /admin` underneath it (unreachable for now, but establishes the shape).
  - `/api/client`: `FailClosedMiddleware` on the group, one placeholder route `POST /api/client/heartbeat` underneath it (named after the design's actual heartbeat endpoint, design section 5.2, even though it does nothing yet).
  - `/auth`: no group middleware, one placeholder route `GET /auth/login` that returns `501 Not Implemented` directly (named after the eventual Google sign-in entry point, design section 11.1).

**`SecurityHeadersMiddleware`**: sets, on every response, `Content-Security-Policy`, `Strict-Transport-Security` and `X-Frame-Options`/`frame-ancestors` per design section 11.3. Values are conservative defaults suitable for a skeleton (e.g. `frame-ancestors 'none'`); they can be tightened when the admin UI has real asset and script sources to allow.

**`FailClosedMiddleware`**: always returns a `401` response with a short plain-text body (for example `Not available yet`). One short comment explains why it exists and that it is meant to be deleted, not extended, once the step that needs real session/CSRF or HMAC middleware lands (steps that add Access login and Clients heartbeat verification).

**`server/public/index.php`**: `declare(strict_types=1);`, requires `vendor/autoload.php`, calls `Http\App::create()->run()`. This is the only file nginx/php-fpm will ever point at (design section 3.1), so it stays a few lines.

## Verification

1. `cd server && composer require slim/slim slim/psr7`
2. `vendor/bin/phpunit`: `AppTest` passes.
   - `GET /admin` → `401`, body `Not available yet`, security headers present.
   - `POST /api/client/heartbeat` → `401`, same body, security headers present.
   - `GET /auth/login` → `501`, security headers present.
   - `GET /nonexistent` → `404` (Slim's default routing failure).
   - `scripts/test-ubuntu-22.04.sh` (from the repository root): the same suite passes on Ubuntu 22.04 with PHP 8.1.
3. Manual smoke test with the built-in server, from `server/`:
   ```
   php -S 127.0.0.1:8080 -t public
   ```
   In another terminal:
   ```
   curl -i http://127.0.0.1:8080/admin
   curl -i -X POST http://127.0.0.1:8080/api/client/heartbeat
   curl -i http://127.0.0.1:8080/auth/login
   curl -i http://127.0.0.1:8080/nonexistent
   ```
   Confirm the status codes and headers match step 2 above.

## Open question for a future step (answered: new step 3, setup and local login)

The MVP list (design section 15) has no explicit step for admin login (Google sign-in, sessions, CSRF), yet step 3 (now step 4) ("Add one GCP project") implies an authenticated admin UI to add it from. Worth deciding, before step 3 (now step 4) starts, whether login is folded into step 3 (now step 4) or needs its own step inserted first. Not blocking for step 2, since `/admin/*` stays fail-closed either way.
