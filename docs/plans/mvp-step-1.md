# MVP Step 1: Read the seed config file with administrator info

## Context

The repository currently contains only `CLAUDE.md`, `README.md`, `LICENSE` and `docs/DESIGN.md`, no `server/`, `client/`, or `shared/` code exists yet. Per `CLAUDE.md`, work proceeds one MVP step at a time (design section 15), and step 1 is: "Read the seed config file with administrator info." This belongs to the **Access** context (generic context, section 2.1), which owns administrators, the allowlist and the seed config file (section 11.5).

The design document did not pin down the seed file's format, exact fields, or how step 1 (with no database yet) should be verified. These were clarified with the user and will be recorded in `docs/DESIGN.md` as part of this change, per CLAUDE.md rule 3 ("if a change is agreed, update docs/DESIGN.md in the same change").

Decisions confirmed with the user:
- **Format:** INI at `/etc/maguari/seed.ini`, parsed with `parse_ini_file($path, true, INI_SCANNER_TYPED)`. Ship a commented example file documenting every key, noting that values with special characters must be quoted.
- **Fields for step 1:** administrator name and email (the alert recipient), plus an optional Google sign-in allowlist that defaults to the administrator's email if omitted. No password: local credentials are created only by the setup wizard (later step).
- **Presence rules:** file absent means nothing to seed (not an error). File present but invalid (unparsable, or missing/malformed required fields) is an error.
- **Scope boundary:** step 1 only reads and validates the file. Applying the values and marking seeding as done happens later, once SQLite storage exists (a later MVP step).
- **Verification:** PHPUnit tests covering absent/valid/malformed/invalid-required-field cases, plus a real CLI entry point `server/bin/maguari-server` with a first subcommand that validates the seed file and prints what would be seeded, without applying anything. Any future secret fields added to the seed file must be masked in this output (none exist yet in step 1).

Note: the user asked to pause once we reach the Remediation context so they can continue with tactical DDD patterns via claude.ai in the browser. Remediation is unrelated to this step; this is just recorded for when that context comes up.

## Repository scaffolding (new)

Since no server code exists yet, this step also establishes the minimal `server/` skeleton required to hold it, following the layout already specified in `CLAUDE.md` and design section 3.1:

```
server/
  composer.json              PSR-4: Maguari\Server\ -> src/, dev autoload for tests, phpunit dev dependency
                             (pinned to "phpunit/phpunit": "^10.5", with config.platform.php set to
                             8.1.0 so dependency resolution matches the minimum supported PHP version)
  phpunit.xml                Bootstraps vendor/autoload.php, one testsuite
  bin/
    maguari-server           Executable PHP CLI entry point (shebang #!/usr/bin/env php)
  config/
    seed.ini.example         Fully commented example seed file, shipped for administrators to copy/edit
  src/
    Access/
      AccessApi.php          Context's public interface (design section 2.1, rule 1)
      SeedConfig.php          Immutable value object: administratorName, administratorEmail, allowlist
      SeedConfigReader.php     Locates, parses and validates /etc/maguari/seed.ini
      Exception/
        InvalidSeedConfig.php  Thrown when the file exists but is invalid
  tests/
    Access/
      SeedConfigReaderTest.php
```

Add a `server/.gitignore` for `vendor/`.

## Implementation details

**`SeedConfigReader`** (constructor takes the file path, defaulting to `/etc/maguari/seed.ini`, injectable for tests):
- `read(): ?SeedConfig`
- If the file does not exist, return `null`.
- Parse with `parse_ini_file($path, true, INI_SCANNER_TYPED)`, wrapped so a PHP warning on failure is captured (via a temporary error handler, or `@` plus `error_get_last()`) rather than surfacing raw PHP warning text, and turned into a clean `InvalidSeedConfig` message naming the file path.
- With `INI_SCANNER_TYPED`, unquoted `yes`, `no`, `true`, `false`, `on`, `off`, `none` and `null` are converted to booleans, `null`, or left as the literal string depending on the value, instead of the plain string an administrator likely intended. Every field the reader reads (`name`, `email`, each `allowlist[]` entry) must be validated as an actual string (`is_string($value)`) before further checks; a non-string value throws `InvalidSeedConfig` with a message naming the offending key and suggesting the value be quoted, for example `name = "no"`.
- Require `[administrator]` section with non-empty string `name` and a syntactically valid string `email` (`filter_var(..., FILTER_VALIDATE_EMAIL)`); throw `InvalidSeedConfig` with a clear message otherwise (no secrets to leak here, but keep the same non-secret-leaking discipline for when secret fields are added later).
- Read optional `[access]` section's `allowlist` (repeatable `allowlist[] = ...` INI syntax, normalized to a string array). Validate each entry is a string and a valid email; throw on an invalid one, with the same quoting suggestion when the value was typed-coerced. Lowercase every entry and the administrator email, then deduplicate the allowlist (`array_values(array_unique(...))`), so `Jane@Example.com` and `jane@example.com` count as one entry. Default to `[administratorEmail]` when omitted or empty after normalization.
- Return a `SeedConfig` value object.

**`AccessApi`**: thin public-interface wrapper around `SeedConfigReader`, exposing `readSeedConfig(): ?SeedConfig`. This is the only class other contexts (or the CLI) are allowed to depend on, per the cross-context rule in section 2.1, establishing the pattern correctly from the first context onward.

**`server/config/seed.ini.example`**, fully commented, for example:
```ini
; Maguari seed configuration.
; Read once, on first start, to seed initial settings. After that the
; web app owns all settings (see docs/DESIGN.md section 11.5).
; Quote every value in double quotes. Without quotes, values such as
; yes, no, true, false, on, off, none and null are read as booleans or
; null instead of text, which will fail validation.

[administrator]
name = "Jane Doe"
email = jane@example.com

[access]
; Optional. Defaults to the administrator's email above if omitted.
; Repeat the key for multiple addresses.
allowlist[] = jane@example.com
allowlist[] = ops@example.com
```

**`server/bin/maguari-server`**: minimal CLI dispatcher (no framework needed yet). Supports one subcommand: `check-seed-config`. Reads from `/etc/maguari/seed.ini` by default, with an optional `--path=` override for manual testing (documented as a testing convenience, not a production option). On:
- no file: prints "No seed config file found... Nothing to seed." and exits 0.
- invalid file: prints the error to stderr and exits 1.
- valid file: prints administrator name/email and the resolved allowlist, and states nothing was applied yet.

This naming is consistent with the CLI convention already implied by design section 12.2 (`maguari-server setup --domain ...`).

## docs/DESIGN.md updates (same change)

Expand section 11.5 with the confirmed seed file format: path, INI structure and sections and keys, the quoting rule (including the `INI_SCANNER_TYPED` boolean and null coercion for unquoted `yes`, `no`, `on`, `off`, `none` and `null`), the absent versus invalid distinction, allowlist lowercasing and deduplication, and the note that applying values is deferred until SQLite storage exists. Mention the `maguari-server check-seed-config` CLI subcommand alongside the existing `setup` subcommand reference in section 12.2 (or a new short subsection), so the CLI surface stays documented as it grows. No em dashes in this update, matching the project's documentation convention (`CLAUDE.md`).

## Verification

1. `cd server && composer install`
2. `vendor/bin/phpunit`: all `SeedConfigReaderTest` cases pass. Absent file, valid file with and without an explicit allowlist, unparsable INI (asserting the message is clean and does not leak the raw PHP warning), missing administrator section or fields, invalid email in administrator or allowlist, an unquoted `yes`/`no`/`none`/`null`-like value coerced away from a string (asserting the error suggests quoting), and a duplicate or mixed-case allowlist entry (asserting the result is lowercased and deduplicated).
3. Manual CLI check using the `--path=` override (since `/etc/maguari/seed.ini` doesn't exist on a dev machine):
   - `php server/bin/maguari-server check-seed-config --path=server/config/seed.ini.example` → prints the parsed administrator and allowlist.
   - Point `--path=` at a nonexistent file → prints "nothing to seed" and exits 0.
   - Point `--path=` at a deliberately broken copy (e.g. missing `email`) → prints an error to stderr and exits 1.
