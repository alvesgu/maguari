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

Slightly. It has a new context, a new dependency, a settings page with an encrypted secret, a seed file section and the sending itself. **Split (confirmed)** into two sub-steps, each one commit that passes both test suites, with a review stop after each:

| Sub-step | Delivers | Depends on |
|---|---|---|
| **10.1** SMTP settings | The Notifications context, its table with the encrypted password, the "Email" page to view and save settings (showing the recipient), the seed file's `[smtp]` section and its import, `MAGUARI_SEED_FILE`, masking in `check-seed-config` | Step 9 |
| **10.2** The test email | PHPMailer, the sending adapter, the "Send test email" button, the error sentences, a fake SMTP server for tests | 10.1 |

10.1 is usable on its own (settings are stored and visible), like 7.1 and 9.2 were.

## Interpretations (confirmed)

All interpretations and decisions were approved, with the changes recorded in "Answers" at the end of this plan.

**I1. "The administrator" is the signed-in administrator, for now.** The MVP has exactly one administrator (local login, no allowlist sign-in yet). The test email goes to the email address of whoever pressed the button, read from Access, and the Email page shows that address. Notifications receives the address as a plain string and never reads `access_administrators` (design 2.1 rule 2). **With multiple administrators, the test email goes to the alert recipients instead**, so the test proves the path alerts take (design 17 item 3: which alerts each administrator receives).

**I2. Only a test email.** No alert templates, no queue, no retries, no record of sent emails in the database. The test email is sent inside the request and its outcome is shown on the page.

**I3. Settings are global.** One SMTP configuration for the server. Per-administrator alert preferences (design 17 item 3) are later.

## Decisions (confirmed)

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
| Port | 1 to 65535, default 587 (an empty port means 587). Port 465 uses implicit TLS (D5). Port 25 is refused: "Compute Engine blocks outbound port 25. Use port 587." |
| Username | Optional, up to 254 bytes, no control characters. Empty means no authentication (Google Workspace SMTP relay by IP address). |
| Password | Required when a username is set, up to 1,024 bytes, no control characters. Never shown again after saving. An empty password keeps the stored one only while the username is unchanged (review of 10.1): a new username needs its password, "Enter the password again: the username changed." |
| From address | Required, a valid email address (`FILTER_VALIDATE_EMAIL`), lowercased. Gmail only sends from the account's own address or a verified alias. |

Each refusal has its own sentence, shown next to its field (`422`), like the setup wizard.

**Change to design 13 (confirmed):** the port is a setting with default 587, instead of being fixed. Reasons: tests need a fake server on a random port, development needs a local server on an unprivileged port (Verification below), and Gmail and the Google Workspace relay also listen on 465.

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

1. The password field on the page is always empty (`autocomplete="new-password"`). Leaving it empty keeps the stored password while the username stays the same; typing one replaces it. Clearing the username clears the password too. The page says "Stored (encrypted)" when there is one.
2. The plaintext exists only in memory while saving and while sending. It is never logged, never in an exception message and never rendered. Method parameters that carry it are marked `#[\SensitiveParameter]`, as in `SecretBox`.
3. A stored password the key cannot decrypt (the key file was replaced) is not a crash: the page says "The stored SMTP password cannot be decrypted with this server's secret key. Enter the password again and save." Sending refuses with the same sentence.

**D5. Encryption follows the port, and is required except for `localhost`** (`SmtpEncryption`, derived from host and port, never stored):

| Host and port | Encryption |
|---|---|
| Port 465, any host | Implicit TLS (PHPMailer's `SMTPSecure = 'ssl'`) |
| `localhost`, any other port | None, for a development server (Verification below) |
| Any other host and port | STARTTLS (`SMTPSecure = 'tls'`) |

A server that does not offer STARTTLS, or whose certificate does not verify against the system's CA store and match the host, fails before the password is sent. Certificate verification is never turned off. The `localhost` exception is the same rule as `--base-url` and `--server`, where `http://` is accepted only for `localhost` (design 5.6). The Email page shows the encryption next to the saved settings.

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
3. `check-seed-config` prints the section with the password masked (`SMTP password: (set, hidden)` or `(not set)`, never its length), as design 11.5.1 item 8 requires. A password that is not text (left unquoted) is refused without repeating its value.
4. `server/config/seed.ini.example` gets the section with example values and comments, like its `[administrator]` section.
5. `MAGUARI_SEED_FILE` overrides the seed file's path, for development and tests only, like the other `MAGUARI_*` variables. `scripts/dev-env.sh` sets it to `server/var/seed.ini`. The web app and `check-seed-config` both use it; `--path` still wins on the command line.

Why a button and not automatic: design 11.5.1 item 7 still defers applying seed values, and "first start" has no defined moment yet. A button applies only this section, explicitly, without putting the password into the page (prefilling a password field would send it to the browser).

*Alternative A:* prefill only host, port, username and from address from the seed file and have the administrator type the password. Simpler, but then the password never comes from the seed file.
*Alternative B:* apply `[smtp]` automatically on the first view of the Email page. Saves a click, but a page view writing settings is a state-changing GET (forbidden, design 11.3).

**Known gap, unchanged by this step:** the seed file now can hold the SMTP password, and the warning while it exists (design 10.3, 11.5 item 3) is not built yet. It is added to design section 17 (required before 1.0).

### Triggering the test email

**D7. The Email page** (`/admin/email`, linked from the dashboard next to "Projects"):

| Route | Does |
|---|---|
| `GET /admin/email` | Shows the recipient ("Emails go to jane@example.com, the address you signed in with."), the saved settings with their encryption, the settings form (password never shown), the import button when it applies (D6) and, from 10.2, the "Send test email" button |
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
| Connect (DNS, refused, timeout, a refused greeting) | "Could not connect to smtp.gmail.com on port 587, or it did not answer. Check the host and port, and that this server can reach them." On port 465: "... on port 465 with TLS, or it did not answer. Check the host and port, that this server can reach them and that the host name matches the server's certificate." |
| STARTTLS not offered, TLS handshake failed or certificate not verified | "smtp.gmail.com did not set up an encrypted connection, so no password or message was sent. Check the port (587 uses STARTTLS, 465 uses TLS from the start) and that the host name matches the server's certificate." |
| AUTH refused | "smtp.gmail.com did not accept the username and password (reply 535). For Gmail, use an app password, not the account password." |
| MAIL FROM refused with 530 (authentication required) | "smtp.gmail.com requires a username and password." |
| MAIL FROM refused otherwise | "smtp.gmail.com refused the sender address alerts@example.com (reply 553). Use an address this account is allowed to send from." |
| RCPT TO refused | "smtp.gmail.com refused the recipient address jane@example.com (reply 550)." |
| DATA refused | "smtp.gmail.com refused the message (reply 554)." |
| Anything else | "The test email could not be sent. The details are in the server's error log." |
| Success | "Sent a test email to jane@example.com through smtp.gmail.com at 14:03 UTC. If it does not arrive within a few minutes, check the spam folder." |

- "(reply 535)" is the SMTP reply code, added only when there is one (a timeout has none). The Gmail hint is added only for authentication failures.
- Every failure also writes one line to PHP's error log, for the administrator: `The test email failed at <stage>: <first line of the server's reply>` (the socket error for a failed connection, the exception's class and message for "anything else"). For AUTH only the reply code, because some servers repeat the username in their text. No transcript, no password.
- *Found in 10.2:* PHPMailer sends `QUIT` inside its own connect step after a refused greeting, so a refused greeting is a connect failure without a reply code. And `quoted_printable_encode()` encodes a bare `\n` as `=0A`, so the body is converted to CRLF line breaks first.
- Success means the server accepted the message, not that it arrived. The success sentence says so.

### Code layout

**D9. Notifications stays flat** (generic context, design 3.1 item 7):

```
server/src/Notifications/
  NotificationsApi.php          smtpSettings(), saveSmtpSettings(), importSmtpSettings(), sendTestEmail()
  Exception/                    InvalidSmtpSettings (sentences by field), EmailNotSetUp, EmailNotSent (one sentence)
  SmtpSettingsInput.php         what the form or the seed file gave, before validation
  SmtpSettingsSummary.php       the stored values as the page shows them, without the password
  SmtpEncryption.php            D5: implicit TLS, STARTTLS or none, from host and port
  SmtpSettingsRules.php         D2's validation, used by the form and the seed import
  SmtpSettingsRepository.php    SQL only; NotificationsApi encrypts with SecretBox
  Mailer.php                    interface: send(SmtpSettings, Email, local hostname); throws SendFailure
  SmtpMailer.php                the PHPMailer adapter (D5, D7 timeouts)
  StageRecordingSmtp.php        D8's subclass of PHPMailer\PHPMailer\SMTP
  SendFailure.php               stage and reply code
  SendFailureSentence.php       D8's table
  Email.php                     recipient, subject, body
  SmtpSettings.php              the settings with the decrypted password, in memory for one send
  SmtpStage.php                 connect, STARTTLS, AUTH, MAIL FROM, RCPT TO, DATA
  Migrations/0001_notifications_smtp_settings.sql
```

Plus `Http/Controller/EmailController.php` (thin), `templates/email.php`, routes in `Http/App.php`, the dashboard link, `Access/SeedSmtp.php`, the `[smtp]` parsing and `fromEnvironment()` in `SeedConfigReader` and `Cli/SeedConfigReport.php` for `check-seed-config`'s masked lines. In 10.2, `composer.json` requires `phpmailer/phpmailer`.

## Tests

**10.1**

1. Rules: every accepted and refused value of D2 with its sentence; port 25; username without password; `localhost`; D5's encryption for each host and port.
2. Repository: the password is stored encrypted (the column is not the plaintext and decrypts with the key); a new nonce on every save; an empty password field keeps the stored one; clearing the username clears it; a ciphertext from another key gives the "cannot be decrypted" sentence.
3. Seed: the `[smtp]` section parsed, absent and invalid (types); an unquoted password refused without repeating it; `MAGUARI_SEED_FILE`; `check-seed-config` never prints the password; the import button only while nothing is stored; import validates with the same rules.
4. Page: needs a session; posts need CSRF; the password never appears in any response, including `422`; `GET` on the post routes is `405`.
5. Migration applies on top of step 9's.

**10.2**

1. Sentences: each row of D8, with and without a reply code.
2. Adapter against a fake SMTP server: a PHP fixture like `tests/fixtures/tls-server.php` (`tests/fixtures/smtp-server.php`), started with a script of replies, speaking STARTTLS or implicit TLS with a test certificate for `127.0.0.1`, trusted through `SMTPOptions` (tests only). Cases: success over STARTTLS and over implicit TLS (the received message has the headers and body of D7); no STARTTLS offered (and the password never sent); an untrusted certificate; AUTH 535; MAIL 530; RCPT 550; DATA 554; a server that never answers (the 10 second timeout, shortened in the test); nothing listening.
3. `localhost` on a port other than 465 connects unencrypted; any other host never does.
4. The error log line never contains the password or the username.
5. Page: "Send test email" goes to the signed-in administrator, uses saved settings, `409`, `502` and `200` with their sentences (with a fake `Mailer`).
6. The Ubuntu 22.04 run confirms PHPMailer 7.1 on PHP 8.1 and OpenSSL 3.0.

## Changes to `docs/DESIGN.md`

- **This plan's answers:** section 15, step 10 names the sub-steps and links this plan.
- **10.1:** section 9.2 (`notifications_smtp_settings`); section 9.3 (the SMTP password's column); section 9 (`MAGUARI_SEED_FILE` among the development variables); section 11.5.1 (the `[smtp]` section, its import button and masking); section 12.2.1 (`check-seed-config` prints the masked section); section 13 (the settings, the port default, 465 and the 25 refusal, encryption with the `localhost` exception, the Email page and its recipient); section 17 (the seed file warning).
- **10.2:** section 13.2 (PHPMailer and why, the test email, timeouts, the error sentences, no debug transcript); section 13.1 (the password needs entering again when the username changes); README licence note for PHPMailer.

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

### Manual check after 10.1

In each terminal, from the repository root:

```
source scripts/dev-env.sh
```

Then from `server/`, apply the new migration:

```
bin/maguari-server migrate
```

Copy the example seed file to the development path (`MAGUARI_SEED_FILE`):

```
cp config/seed.ini.example var/seed.ini
```

Check what it would seed. The password line must say `(set, hidden)`:

```
bin/maguari-server check-seed-config
```

Start the app and sign in:

```
php -S localhost:8080 -t public
```

1. The dashboard links to "Email". The Email page (`http://localhost:8080/admin/email`) says "Email is not set up yet", names your address as the recipient and offers "Use the SMTP settings from the seed file".
2. Press it. The saved settings show `smtp.gmail.com`, port 587, STARTTLS and "Stored (encrypted)", and the button is gone. The password never appears in the page source.
3. Change the port to 25 and save: "Compute Engine blocks outbound port 25. Use port 587." Change it to 465 and save: the encryption becomes "TLS from the start (port 465)".
4. Set host `localhost`, port `1025`, an empty username, from `maguari@example.test` and save: the encryption is "none (allowed only for localhost)" and no password is stored. These are the settings for the aiosmtpd check after 10.2.

The stored password is encrypted, not plain text:

```
sqlite3 var/dev.sqlite "SELECT host, port, username, length(password_ciphertext), from_address FROM notifications_smtp_settings"
```

### Manual check after 10.2

With the development environment loaded as above.

**Without a real mail account**, with a local SMTP server that prints every message it receives. `aiosmtpd` is in Ubuntu's archive:

```
sudo apt install python3-aiosmtpd
```

In a second terminal:

```
python3 -m aiosmtpd -n -l localhost:1025
```

In the Email page, with host `localhost`, port `1025`, no username and from `maguari@example.test` saved, press "Send test email". The message appears in the aiosmtpd terminal. Stop aiosmtpd and press it again: "Could not connect to localhost on port 1025."

**With Gmail**, to exercise STARTTLS and authentication for real. Create an app password for a Google account with 2-Step Verification (Google Account, Security, App passwords). In the Email page, set host `smtp.gmail.com`, port `587`, username and from address the account's address, and the app password. Press "Send test email" and check the inbox. Repeat with port 465. Then save a wrong password and press it again: "smtp.gmail.com did not accept the username and password (reply 535). ..."

## Answers

1. **Library and port:** PHPMailer 7.1 as proposed. The port is a setting with default 587; port 465 uses implicit TLS, every other port requires STARTTLS, and 25 is refused (D2, D5).
2. **Encryption:** STARTTLS (or implicit TLS on 465) is required, except for `localhost` (D5).
3. **Seed file:** applied with a button (D6), and `MAGUARI_SEED_FILE` added for development and tests only (D6 item 5).
4. **Seed file warning:** added to design section 17, noting that the seed file can now contain the SMTP password.
5. **Split:** 10.1 and 10.2. The test email goes to the signed-in administrator for now, the Email page shows the recipient, and with multiple administrators it goes to the alert recipients (I1).

## Status

- **10.1:** implemented and reviewed. One change after review: an empty password keeps the stored one only while the username is unchanged.
- **10.2:** implemented, waiting for review.
