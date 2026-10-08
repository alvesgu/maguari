# MVP Step 10: Send a test email to the administrator

## Context

Step 9 (`docs/plans/mvp-step-9.md`) finished the daily job's certificate checks. Every result so far is shown, not acted on: no incident, alert or notification exists yet.

Step 10 is: "Send a test email to the administrator" (design section 15). The design says:

- **13:** "SMTP on port 587 through a relay (Google Workspace SMTP relay or Gmail SMTP). Compute Engine blocks outbound port 25."
- **2.1:** Notifications owns "Alerts (email), notifications (dashboard), SMTP". It is a **generic** context: "a solved problem everywhere. Use libraries and keep it simple." Generic contexts stay flat (3.1 item 7).
- **9.3:** the SMTP password cannot be hashed, so it is encrypted with `sodium_crypto_secretbox` and the key in `/etc/maguari/secret.key`, stored as nonce plus ciphertext in a `BLOB` column.
- **11.5:** the seed config file seeds settings once, then "the web app owns all settings". Applying seeded values is still deferred (11.5.1 item 7), and if secret fields are added, CLI output must mask them (11.5.1 item 8).
- **Glossary:** an **alert** is an email sent to the administrator. A test email is not an alert (no incident), so this plan calls it a "test email" everywhere.

This is the first code in `server/src/Notifications/`. Nothing here sends alerts: that needs incidents (Remediation), which are not an MVP step. Step 10 proves the path works so alerts can use it later.

Relevant design sections: 2.1, 3.1, 9.2, 9.3, 10.2, 11.3, 11.5, 12.2.1, 13.

## Is this too large for one step?

Slightly. It has a new context, a new dependency, a settings page with an encrypted secret, a seed file section and the sending itself. **Proposed split** into two sub-steps, each one commit that passes both test suites, with a review stop after each:

| Sub-step | Delivers | Depends on |
|---|---|---|
| **10.1** SMTP settings | The Notifications context, its table with the encrypted password, the "Email" page to view and save settings, the seed file's `[smtp]` section and its import, masking in `check-seed-config` | Step 9 |
| **10.2** The test email | PHPMailer, the sending adapter, the "Send test email" button, the error sentences, a fake SMTP server for tests | 10.1 |

10.1 is usable on its own (settings are stored and visible), like 7.1 and 9.2 were.

*Alternative:* one commit. Fine if you prefer it; the split mainly keeps the secret handling reviewable apart from the network code.

## Interpretations (to confirm)

**I1. "The administrator" is the signed-in administrator.** The MVP has exactly one administrator (local login, no allowlist sign-in yet). The test email goes to the email address of whoever pressed the button, read from Access. Notifications receives the address as a plain string and never reads `access_administrators` (design 2.1 rule 2).

**I2. Only a test email.** No alert templates, no queue, no retries, no record of sent emails in the database. The test email is sent inside the request and its outcome is shown on the page.

**I3. Settings are global.** One SMTP configuration for the server. Per-administrator alert preferences (design 17 item 3) are later.

## Decisions (proposed)

### The library

**D1. PHPMailer (`phpmailer/phpmailer`, `^7.1`).** The current release is 7.1.1 (May 2026).

| | PHPMailer 7.1 | Symfony Mailer | Hand-written SMTP |
|---|---|---|---|
| PHP 8.1 | Yes (requires PHP 5.5 or later) | Only the 6.4 LTS line: 7.x needs PHP 8.2 and 8.x needs PHP 8.4. 6.4's bug fixes end in November 2026 and security fixes in November 2027 | Yes |
| Packages pulled in | None. Needs `ext-ctype`, `ext-filter` and `ext-hash`, all part of PHP on Ubuntu | About ten (`symfony/mime`, `symfony/event-dispatcher`, `egulias/email-validator`, `psr/log`, mbstring and IDN polyfills, ...) | None |
| STARTTLS and AUTH | Yes, through `ext-openssl`, which the server already requires | Yes | Ours to write and get right (TLS, AUTH, dot-stuffing, MIME, line lengths) |
| Maintenance | Maintained since 2001, regular releases, bundled by WordPress core | Excellent, but we would be pinned to an ageing line | Ours forever |
| Licence | LGPL 2.1 | MIT | MIT |

Why PHPMailer:

1. It supports the project's PHP 8.1 floor on its current major, not on a line close to end of life.
2. It adds one package and no transitive dependencies, which suits "very light" (design goal 5) and keeps `composer.lock` small.
3. Its `SMTP` class is public and documented, which gives a clean way to tell failures apart (D8).

The LGPL licence is compatible with an MIT project that uses PHPMailer unmodified, as a separate library. Packaging must ship its `LICENSE` file, which Composer already places in `vendor/phpmailer/phpmailer/`. The README's licence section will mention it.

PHPMailer only *suggests* mbstring, which is not a server dependency (see `AccessApi::characterCount()`). Without it, non-ASCII header text is the weak spot, so headers stay ASCII: a fixed subject, no display name on the recipient and "Maguari" as the sender's name. The body may contain UTF-8 (quoted-printable needs no mbstring).

*Alternative:* Symfony Mailer 6.4. Its exceptions carry the SMTP reply code, which is nice, but the version pin and dependency tree outweigh that.

### Where the settings come from

**D2. Five settings, owned by Notifications:**

| Setting | Rule |
|---|---|
| Host | A DNS name (letters, digits and hyphens in dot-separated labels, at most 253 bytes, lowercased), or exactly `localhost`. No IP addresses, schemes or ports. |
| Port | 1 to 65535, default 587. Port 25 is refused: "Compute Engine blocks outbound port 25. Use port 587." |
| Username | Optional, up to 254 bytes, no control characters. Empty means no authentication (Google Workspace SMTP relay by IP address). |
| Password | Required when a username is set, up to 1,024 bytes, no control characters. Never shown again after saving. |
| From address | Required, a valid email address (`FILTER_VALIDATE_EMAIL`), lowercased. Gmail only sends from the account's own address or a verified alias. |

Each refusal has its own sentence, shown next to its field (`422`), like the setup wizard.

**Deviation from design 13 (to confirm):** the port becomes a setting with default 587, instead of being fixed. Reasons: tests need a fake server on a random port, development needs a local server on an unprivileged port (Verification below), and the Google Workspace relay also listens on 465 and 25, which some administrators may try. 465 (implicit TLS) is still not supported: only STARTTLS (D5). *Alternative:* keep 587 fixed in production and allow another port only through a development environment variable. More rules, less useful.

**D3. Storage:** one table, one row, in `server/src/Notifications/Migrations/0001_notifications_smtp_settings.sql`:

```
notifications_smtp_settings(
    id INTEGER PRIMARY KEY CHECK (id = 1),
    host TEXT NOT NULL,
    port INTEGER NOT NULL,
    username TEXT NOT NULL,          -- '' for no authentication
    password_ciphertext BLOB,        -- NULL when username is ''
    from_address TEXT NOT NULL,
    updated_at INTEGER NOT NULL
)
```

No row means email is not set up. A typed single row instead of a key-value table like `access_settings`, because the password is a `BLOB` and the five values are saved together.

**D4. The SMTP password is encrypted** with `Kernel\Secrets\SecretBox`, exactly as `clients_clients.secret_ciphertext` (design 9.3): a new 24-byte nonce for each save, nonce plus ciphertext in `password_ciphertext`. `NotificationsApi` receives the `SecretBox` in its constructor, like `ClientsApi`.

1. The password field on the page is always empty (`autocomplete="new-password"`). Leaving it empty keeps the stored password; typing one replaces it. Clearing the username clears the password too. The page says "A password is stored" when there is one.
2. The plaintext exists only in memory while saving and while sending. It is never logged, never in an exception message and never rendered. Method parameters that carry it are marked `#[\SensitiveParameter]`, as in `SecretBox`.
3. A stored password the key cannot decrypt (the key file was replaced) is not a crash: the page says "The stored SMTP password cannot be decrypted with this server's secret key. Enter the password again and save." Sending refuses with the same sentence.

**D5. STARTTLS is required, except for `localhost`.** For any host but exactly `localhost`, the adapter sets `SMTPSecure = 'tls'` (STARTTLS), so a server that does not offer STARTTLS, or whose certificate does not verify against the system's CA store and match the host, fails before the password is sent. Certificate verification is never turned off. For `localhost` only, the connection is plain, for a development server (Verification below). This is the same rule as `--base-url` and `--server`, where `http://` is accepted only for `localhost` (design 5.6).

**D6. The seed file gets an optional `[smtp]` section:**

```
[smtp]
; Optional. Applied from the Email page while no SMTP settings are stored.
host = "smtp.gmail.com"
port = "587"
username = "alerts@example.com"
password = "the app password"
from = "alerts@example.com"
```

1. Access still owns the seed file (design 2.1). `SeedConfigReader` checks only that the section's values are strings (port also accepts an integer, in case it is left unquoted) and returns them as a `SeedSmtp` value inside `SeedConfig` (null when the section is absent). Notifications validates them with the same rules as the form (D2), so there is one set of rules.
2. **Applying it.** The Email page shows "Use the SMTP settings from the seed file" as a button (`POST /admin/email/import-seed`) only while no SMTP settings are stored and the seed file has an `[smtp]` section. Pressing it reads the file, validates and stores the settings (password encrypted). Once settings are stored, the button disappears and the web app owns them, as design 11.5 item 1 says. A seed section that fails validation shows its sentences and stores nothing.
3. `check-seed-config` prints the section with the password masked (`password = (set, 12 characters hidden)` or `(not set)`), as design 11.5.1 item 8 requires.
4. `server/config/seed.ini.example` gets the section with example values and comments, like its `[administrator]` section.

Why a button and not automatic: design 11.5.1 item 7 still defers applying seed values, and "first start" has no defined moment yet. A button applies only this section, explicitly, without putting the password into the page (prefilling a password field would send it to the browser).

*Alternative A:* prefill only host, port, username and from address from the seed file and have the administrator type the password. Simpler, but then the password never comes from the seed file.
*Alternative B:* apply `[smtp]` automatically on the first view of the Email page. Saves a click, but a page view writing settings is a state-changing GET (forbidden, design 11.3).

**Known gap, unchanged by this step:** the seed file now can hold a secret, and the warning while it exists (design 10.3, 11.5 item 3) is not built yet. Question Q3 asks whether to add it to section 17.

### Triggering the test email

**D7. The Email page** (`/admin/email`, linked from the dashboard next to "Projects"):

| Route | Does |
|---|---|
| `GET /admin/email` | Shows the settings form (password never shown), the import button when it applies (D6) and the "Send test email" button with the recipient: "Sends a test email to jane@example.com with the saved settings." |
| `POST /admin/email` | Validates and saves. `422` with the sentences and what was typed (except the password), or `303` back to the page. |
| `POST /admin/email/test` | Sends the test email with the **saved** settings (never unsaved form values) to the signed-in administrator, inside the request. The response is the page itself with the outcome (no redirect, as with the enrollment token page): `200` when sent, `409` when email is not set up, `502` when sending failed. |
| `POST /admin/email/import-seed` | D6 item 2. `303` back on success, `422` with sentences otherwise. |

All under `/admin`, with session and CSRF (design 10.2). No state-changing GET.

**The test email:**

- From: "Maguari" `<from address>`. To: the administrator's address (no display name, D1). Subject: `Maguari test email`.
- Plain text body: "This is a test email from Maguari at https://maguari.example.com, sent at 2026-10-08 14:03 UTC by Jane Doe. Alerts will be sent the same way." The address is the configured base URL from Access (never the request's `Host`); without one, that part is left out.
- PHPMailer's `Hostname` (used in EHLO and `Message-ID`) is the base URL's host, or `gethostname()` without one, never `$_SERVER['SERVER_NAME']`. `XMailer` is set to `Maguari`.
- Timeouts: PHPMailer and its SMTP class default to **300 seconds**. Both `Timeout` and `Timelimit` are set to 10 seconds (`SmtpMailer::TIMEOUT_SECONDS`), so a stalled server cannot hold a php-fpm worker for minutes. A test email has a handful of round trips, well inside nginx's 60 seconds in normal cases.
- `SMTPDebug` stays 0, always. PHPMailer's debug transcript includes the `AUTH` exchange, which is the password in base64.

*Alternative:* also a CLI command `maguari-server send-test-email` for SSH use. Not proposed: it is outside the step, and the page covers it. Easy to add later.

### Error sentences

**D8. Every failure is one fixed sentence**, never PHPMailer's or the server's message on the page (the same rule as Fleet's API errors and the daily job). To tell failures apart reliably, the adapter gives PHPMailer its own subclass of PHPMailer's `SMTP` class (`setSMTPInstance()`). The subclass only records the stage before calling the parent's public `connect()`, `startTLS()`, `authenticate()`, `mail()`, `recipient()` and `data()`. The stage plus the reply code from `getError()` pick the sentence. This depends only on the `SMTP` class's public API, not on PHPMailer's English error strings.

With host `smtp.gmail.com`, port 587, from `alerts@example.com` and recipient `jane@example.com`:

| Stage and situation | Sentence |
|---|---|
| Not set up | "Email is not set up yet. Fill in the SMTP settings and save them first." |
| Stored password unreadable | "The stored SMTP password cannot be decrypted with this server's secret key. Enter the password again and save." |
| Connect (DNS, refused, timeout) | "Could not connect to smtp.gmail.com on port 587. Check the host and port, and that this server can reach them." |
| STARTTLS not offered, handshake failed or certificate not verified | "smtp.gmail.com did not set up an encrypted connection (STARTTLS), so the password was not sent. Check that the port is the submission port, usually 587, and that the host name matches the server's certificate." |
| AUTH refused | "smtp.gmail.com did not accept the username and password (reply 535). For Gmail, use an app password, not the account password." |
| MAIL FROM refused with 530 (authentication required) | "smtp.gmail.com requires a username and password." |
| MAIL FROM refused otherwise | "smtp.gmail.com refused the sender address alerts@example.com (reply 553). Use an address this account is allowed to send from." |
| RCPT TO refused | "smtp.gmail.com refused the recipient address jane@example.com (reply 550)." |
| DATA refused | "smtp.gmail.com refused the message (reply 554)." |
| Anything else | "The test email could not be sent. The details are in the server's error log." |
| Success | "Sent a test email to jane@example.com through smtp.gmail.com at 14:03 UTC. If it does not arrive within a few minutes, check the spam folder." |

- "(reply 535)" is the SMTP reply code, added only when there is one (a timeout has none). The Gmail hint is added only for authentication failures.
- Every failure also writes one line to PHP's error log, for the administrator: `The test email failed at <stage>: <reply code> <first line of the server's reply>` (or the exception's class and message for "anything else"). No transcript, no password, no username.
- Success means the server accepted the message, not that it arrived. The success sentence says so.

### Code layout

**D9. Notifications stays flat** (generic context, design 3.1 item 7):

```
server/src/Notifications/
  NotificationsApi.php          smtpSettings(), saveSmtpSettings(), importSeedSmtp(), sendTestEmail()
  Exception/                    InvalidSmtpSettings (sentences by field), EmailNotSetUp, EmailNotSent (one sentence)
  SmtpSettings.php              the stored values; the password only in memory
  SmtpSettingsRules.php         D2's validation, used by the form and the seed import
  SmtpSettingsRepository.php    SQL, encrypts and decrypts with SecretBox
  Mailer.php                    interface: send(SmtpSettings, TestEmail); throws SendFailure
  SmtpMailer.php                the PHPMailer adapter (D5, D7 timeouts)
  StageRecordingSmtp.php        D8's subclass of PHPMailer\PHPMailer\SMTP
  SendFailure.php               stage and reply code
  SendFailureSentence.php       D8's table
  TestEmail.php                 recipient, subject, body
  Migrations/0001_notifications_smtp_settings.sql
```

Plus `Http/Controller/EmailController.php` (thin), `templates/email.php`, routes in `Http/App.php`, the dashboard link, `Access/SeedSmtp.php` and the `[smtp]` parsing in `SeedConfigReader`. `bin/maguari-server check-seed-config` masks the password. `composer.json` requires `phpmailer/phpmailer`.

## Tests

**10.1**

1. Rules: every accepted and refused value of D2 with its sentence; port 25; username without password; `localhost`.
2. Repository: the password is stored encrypted (the column is not the plaintext and decrypts with the key); a new nonce on every save; an empty password field keeps the stored one; clearing the username clears it; a ciphertext from another key gives the "cannot be decrypted" sentence.
3. Seed: the `[smtp]` section parsed, absent and invalid (types); `check-seed-config` never prints the password; the import button only while nothing is stored; import validates with the same rules.
4. Page: needs a session; posts need CSRF; the password never appears in any response, including `422`; `GET` on the post routes is `405`.
5. Migration applies on top of step 9's.

**10.2**

1. Sentences: each row of D8, with and without a reply code.
2. Adapter against a fake SMTP server: a PHP fixture like `tests/fixtures/tls-server.php` (`tests/fixtures/smtp-server.php`), started with a script of replies, speaking STARTTLS with a test certificate for `127.0.0.1`, trusted through `SMTPOptions` (tests only). Cases: success (the received message has the headers and body of D7); no STARTTLS offered (and the password never sent); an untrusted certificate; AUTH 535; MAIL 530; RCPT 550; DATA 554; a server that never answers (the 10 second timeout, shortened in the test); nothing listening.
3. `localhost` connects without STARTTLS; any other host never does.
4. The error log line never contains the password or the username.
5. Page: "Send test email" goes to the signed-in administrator, uses saved settings, `409`, `502` and `200` with their sentences (with a fake `Mailer`).
6. The Ubuntu 22.04 run confirms PHPMailer 7.1 on PHP 8.1 and OpenSSL 3.0.

## Changes to `docs/DESIGN.md`

- **This plan's answers:** section 15, step 10 names the sub-steps and links this plan.
- **10.1:** section 9.2 (`notifications_smtp_settings`); section 9.3 (the SMTP password's column); section 11.5.1 (the `[smtp]` section, its import button and masking); section 13 (the settings, the port default and the 25 refusal, STARTTLS with the `localhost` exception); section 3.1 (Notifications' files).
- **10.2:** section 13 (PHPMailer and why, the test email, timeouts, the error sentences, no debug transcript); README licence note for PHPMailer.

## Verification

For each sub-step, from `server/`:

```
vendor/bin/phpunit
```

and from the repository root:

```
scripts/test-ubuntu-22.04.sh
```

No `--rebuild` is needed: PHPMailer comes from Composer, not apt, and the script installs Composer packages on every run.

### Manual check in development

In each terminal, from the repository root:

```
source scripts/dev-env.sh
```

Then from `server/`, after pulling the step's migration:

```
bin/maguari-server migrate
```

**Without a real mail account**, with a local SMTP server that prints every message it receives. `aiosmtpd` is in Ubuntu's archive:

```
sudo apt install python3-aiosmtpd
```

In a second terminal:

```
python3 -m aiosmtpd -n -l localhost:1025
```

In the Email page (`http://localhost:8080/admin/email`), set host `localhost`, port `1025`, no username, from `maguari@example.test`, save, then press "Send test email". The message appears in the aiosmtpd terminal. Stop aiosmtpd and press it again: "Could not connect to localhost on port 1025."

**With Gmail**, to exercise STARTTLS and authentication for real. Create an app password for a Google account with 2-Step Verification (Google Account, Security, App passwords). In the Email page, set host `smtp.gmail.com`, port `587`, username and from address the account's address, and the app password. Press "Send test email" and check the inbox. Then save a wrong password and press it again: "smtp.gmail.com did not accept the username and password (reply 535). ..."

**The seed file:** the example file has an `[smtp]` section (D6 item 4):

```
bin/maguari-server check-seed-config --path=config/seed.ini.example
```

Expected: the `[smtp]` section printed with the password masked. Pressing the import button in development needs a seed file the web app can find; the path has no development override today (Q2).

## Questions

**Q1.** Confirm the library (D1, PHPMailer 7.1) and the port as a setting with default 587, refusing 25 (D2, a deviation from design 13).

**Q2.** Confirm the seed import as a button (D6), not prefilling (alternative A). For testing the import from the web page in development, the seed file's path needs a development override like the others: add `MAGUARI_SEED_FILE` (development and tests only) to `scripts/dev-env.sh`? Without it, the import is covered by automated tests only.

**Q3.** The seed file can now hold a secret, and its warning (design 10.3) is not built. Add "the seed file warning" to section 17 (required before 1.0), or leave it where it is?

**Q4.** Confirm the split into 10.1 and 10.2, and I1 (the signed-in administrator receives the test email).
