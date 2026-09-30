# MVP Step 4: Add one GCP project

## Context

Step 3 (`docs/plans/mvp-step-3.md`) added SQLite, the setup wizard, local login, sessions and CSRF. `/admin` shows a signed-in placeholder page.

Step 4 is: "Add one GCP project" (design section 15). It belongs to the **Fleet** context (design section 2.1), which owns projects, instances and the GCP API adapter. Nothing exists in `server/src/Fleet/` yet.

Relevant design sections: 2.1 (contexts and the rules between them), 8 (GCP integration), 8.1 (administrator workflow, item 1), 9 (storage) and 11.3 (hardening).

What this step delivers, end to end:

1. A signed-in administrator opens `/admin/projects`, types a project ID and submits.
2. The server obtains an access token, calls the Compute Engine API to list instances in that project (one result is enough) and, only if the call succeeds, stores the project.
3. If the call fails, the page says why in plain words (project not found, Compute Engine API not enabled, missing permission, missing access scope, credentials unavailable) and nothing is stored.
4. `/admin/projects` lists the projects added so far.

Listing and picking instances is step 5. This step only proves that access works and records the project.

## The credentials problem

Design section 8 item 2: in production the server gets access tokens from the **metadata server** of the instance it runs on, using the attached service account. No key files.

The development machine is not a Compute Engine instance, so there is no metadata server. The plan puts token retrieval behind one interface with three implementations:

| Implementation | Used in | Where the token comes from |
|---|---|---|
| `MetadataServerTokenSource` | Production (the default) | `http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token`, header `Metadata-Flavor: Google` |
| `ApplicationDefaultCredentialsTokenSource` | Development only | The developer's own gcloud login: `gcloud auth application-default login` writes `~/.config/gcloud/application_default_credentials.json`, and the server exchanges its refresh token at `https://oauth2.googleapis.com/token` |
| `FakeTokenSource` (in `tests/Support/`) | Tests | A fixed token, or a configured failure. No network. |

Everything above the interface (the Compute Engine adapter, `FleetApi`, the controller) is the same in all three cases.

### How the implementation is chosen

One environment variable, `MAGUARI_GCP_CREDENTIALS`, read in `App::fromEnvironment()`:

- unset or `metadata`: the metadata server
- `application-default`: the developer's ADC file
- anything else: the app refuses to start with a clear message

This follows the existing pattern of `MAGUARI_DATABASE` ("for development and tests only", design section 9).

### Why this cannot bring key files into production

1. **The ADC source accepts only user credentials.** It reads the file's `type` field and accepts `authorized_user` (and `impersonated_service_account` if D3 is confirmed). A `service_account` file, which is what a key file is, is rejected with a message saying key files are not supported. There is no code path that signs a JWT with a private key.
2. **It reads one fixed path,** `$HOME/.config/gcloud/application_default_credentials.json`. It does not honor `GOOGLE_APPLICATION_CREDENTIALS`, which is the conventional way to point at a key file.
3. **Production never selects it by accident.** The default is the metadata server. php-fpm starts pools with `clear_env = yes` by default, so a variable set in a shell or `/etc/environment` does not reach the web app. `sudo -u maguari-server maguari-server ...` resets the environment too (sudo's default `env_reset`). The package will not set the variable.
4. **Nothing is stored.** Neither the refresh token nor any access token is written to the database, a file or a log. Tokens live only in memory for the duration of one request.

### Alternatives considered

- **The `google/auth` Composer library.** Handles every credential type, but pulls in Guzzle, `firebase/php-jwt` and PSR cache packages, and supports key files by design. Heavier than the whole app so far, and against "no other frameworks without asking." Not recommended.
- **Shelling out to `gcloud auth print-access-token` in development.** Simplest, but design section 8 item 1 says the application does not use the gcloud CLI, and it would put a shell call in the server. Not recommended.
- **A static token in an environment variable** (`MAGUARI_GCP_ACCESS_TOKEN=$(gcloud auth print-access-token)`). Also simple, but tokens expire after an hour, so development breaks silently and has to be restarted. Not recommended, although it is the fallback if D3 is rejected and you want the smallest possible change.

## Decisions (proposed, need confirmation)

**D1. HTTP client.** Calls go through a small `HttpClient` interface in `Kernel/HttpClient/`, implemented with PHP's built-in HTTPS stream wrapper (`fopen` with a stream context, headers read from `stream_get_meta_data()`, no redirects followed, 5 second connect and 15 second total timeout, TLS peer verification on). This needs only the `openssl` extension, which Ubuntu's PHP builds include. The alternative, `ext-curl`, is not installed by `php-cli` or `php-fpm` alone (it is missing from the Ubuntu 22.04 test image) and would add `php-curl` to the server's package dependencies. The fake used in tests implements the same interface.

**D2. Token caching.** None in this step. Adding a project is one token request plus one Compute Engine call. The metadata server is local and caches tokens itself. Caching across requests would mean storing a secret (encrypted, design 9.3) for no real gain yet. Revisit when the scheduler calls the API every minute.

**D3. Service account impersonation in development.** Recommended: yes. With plain `authorized_user` credentials the developer's own account is used, which is usually a project Owner, so development never exercises the custom role from design section 8. With `gcloud auth application-default login --impersonate-service-account=<service-account-email>`, the ADC file has type `impersonated_service_account`, and the source additionally calls `iamcredentials.googleapis.com` `generateAccessToken` for that service account. Development then sees exactly the permissions production sees. Cost: about 40 more lines and one more test. The developer needs `roles/iam.serviceAccountTokenCreator` on that service account.

**D4. Verification call.** `GET https://compute.googleapis.com/compute/v1/projects/{project}/aggregated/instances?maxResults=1&returnPartialSuccess=true`. It needs only `compute.instances.list` (design section 8 item 4) and is the same endpoint step 5 will use for listing. The response body is not used beyond a success check.

**D5. Error mapping.** The adapter translates API failures into a small set of reasons, and the page shows a fixed sentence for each. Raw GCP messages are not shown or logged in full.

| Condition | Message shown |
|---|---|
| Token source failed (metadata server unreachable, ADC file missing, refresh rejected) | Maguari could not obtain Google Cloud credentials. Plus one hint per source: the instance's service account for production, `gcloud auth application-default login` for development. |
| `404` | Project not found. |
| `403` with reason `accessNotConfigured` or `SERVICE_DISABLED` | The Compute Engine API is not enabled in this project. |
| `403` with reason `ACCESS_TOKEN_SCOPE_INSUFFICIENT` or `insufficientPermissions` | The server instance's access scopes do not allow Compute Engine API calls (design section 8 item 3). |
| Any other `403` | The service account cannot list instances in this project. Grant it the custom role. |
| `401`, `5xx`, timeout, invalid JSON | Google Cloud returned an unexpected response. Try again. |

Google also answers `403` for projects that exist but that the caller cannot see, so "not found" versus "no permission" is best effort.

**D6. Project ID format.** Standard GCP project IDs only: 6 to 30 characters, lowercase letters, digits and hyphens, starting with a letter and not ending with a hyphen. The input is trimmed and lowercased first. Legacy domain-scoped IDs (`example.com:my-project`) are rejected. Validation happens before any API call.

**D7. Table.** One table, `fleet_projects`. The GCP project ID column is called `gcp_project_id`, so that later tables can use `project_id` for the foreign key to `fleet_projects.id` without ambiguity. Adding a project that already exists is rejected with a message, not an error page.

**D8. Routes.** `GET /admin/projects` (list and form) and `POST /admin/projects` (add). Both sit in the existing `/admin` group, so they inherit the session, CSRF and administrator checks. A successful add redirects (303) to `GET /admin/projects`. A failed add re-renders the form with status 422 and the typed project ID. The `/admin` page gets a link to `/admin/projects`.

**D9. No new CLI subcommand.** A `check-gcp-credentials` command would be convenient for diagnosing credentials over SSH, but it is not needed for this step. Proposed for later, not implemented now.

## Out of scope for this step

- Listing, picking or storing instances (step 5).
- Removing or renaming a project.
- Showing the service account email on the page, so the administrator knows whom to grant the custom role. Useful, but it needs one more metadata call and has no ADC equivalent. Later.
- Token caching (D2) and the `check-gcp-credentials` subcommand (D9).
- The audit log (design 9.2). Adding a project will be an audit event once the audit log exists.
- Packaging (access scope checks at install time, php-fpm pool configuration).
- Any CSS or JavaScript.

## Repository changes

```
server/
  composer.json                              add "ext-json" and "ext-openssl"
  src/
    Kernel/
      HttpClient/
        HttpClient.php                       interface: send(HttpRequest): HttpResponse
        HttpRequest.php                      method, URL, headers, body
        HttpResponse.php                     status, headers, body
        StreamHttpClient.php                 D1
        HttpClientFailure.php                exception: connection, TLS or timeout failure
    Fleet/
      FleetApi.php                           public interface: listProjects(), addProject(string $gcpProjectId)
      Project.php                            value object: id, gcpProjectId, createdAt
      ProjectRepository.php
      ProjectId.php                          validation and normalization (D6)
      Exception/
        InvalidProjectId.php
        ProjectAlreadyAdded.php
        ProjectNotAccessible.php             carries one reason from D5
      Gcp/
        AccessToken.php                      value object: token, expiresAt; hides the token from var_dump and print_r
        AccessTokenSource.php                interface: accessToken(): AccessToken
        AccessTokenUnavailable.php           exception; message never contains a token or refresh token
        MetadataServerTokenSource.php        production
        ApplicationDefaultCredentialsTokenSource.php   development only (and D3)
        AccessTokenSourceFactory.php         reads MAGUARI_GCP_CREDENTIALS
        ComputeEngine.php                    the adapter: canListInstances(gcpProjectId), error mapping (D5)
      Migrations/
        0001_fleet_projects.sql
    Http/
      App.php                                takes FleetApi as well; adds the two routes
      Controller/ProjectsController.php      GET and POST /admin/projects
  templates/
    projects.php                             list and add form
    admin.php                                link to /admin/projects
  tests/
    Support/
      FakeTokenSource.php
      FakeHttpClient.php                     records requests, returns queued responses
      TestEnvironment.php                    builds FleetApi with the fakes
    Kernel/HttpClient/StreamHttpClientTest.php
    Fleet/ProjectIdTest.php
    Fleet/FleetApiTest.php
    Fleet/Gcp/MetadataServerTokenSourceTest.php
    Fleet/Gcp/ApplicationDefaultCredentialsTokenSourceTest.php
    Fleet/Gcp/AccessTokenSourceFactoryTest.php
    Fleet/Gcp/ComputeEngineTest.php
    Http/ProjectsFlowTest.php
docs/DESIGN.md                               record the confirmed decisions (see below)
```

`FleetApi` is the only Fleet class used outside Fleet (design 2.1 rule 1). The controller never sees `ComputeEngine`, tokens or raw GCP responses (rule 4).

## Table

```
fleet_projects(id INTEGER PRIMARY KEY, gcp_project_id TEXT NOT NULL UNIQUE, created_at INTEGER NOT NULL)
```

## Flows

**Add a project.**
1. `POST /admin/projects`: CSRF and session checks (existing middleware).
2. `ProjectId` validates and normalizes the input (D6). On failure, 422 with the message. No API call.
3. If the project is already in `fleet_projects`, 422 with "This project is already added." No API call.
4. `ComputeEngine` gets a token from the configured `AccessTokenSource` and makes the call in D4 with `Authorization: Bearer <token>`.
5. On success, insert the row and redirect (303) to `/admin/projects`. On failure, 422 with the D5 message.

**Metadata server token.** `GET` the token URL above with `Metadata-Flavor: Google`, plain HTTP (the metadata server is link-local and does not serve HTTPS). Short timeouts (2 seconds), because on a machine that is not an instance the host does not resolve or does not answer. The response must carry `Metadata-Flavor: Google` and JSON with `access_token` and `expires_in`.

**ADC token (development).**
1. Read `$HOME/.config/gcloud/application_default_credentials.json`. Missing or unreadable: `AccessTokenUnavailable` with the hint to run `gcloud auth application-default login`.
2. `type` must be `authorized_user` (or `impersonated_service_account` with D3). `service_account` and anything else: rejected, key files are not supported.
3. `POST https://oauth2.googleapis.com/token` with `grant_type=refresh_token`, `client_id`, `client_secret` and `refresh_token`. An `invalid_grant` answer means the gcloud login expired: the hint says to log in again.
4. With D3: `POST https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/<email>:generateAccessToken` with scope `https://www.googleapis.com/auth/cloud-platform`, authenticated with the token from step 3. The service account email comes from the file's `service_account_impersonation_url`, validated against the expected URL shape.
5. The file's `quota_project_id` is ignored. Compute Engine does not need a quota project for user credentials.

## Tests

No test touches the network, except `StreamHttpClientTest`, which starts PHP's built-in server on `127.0.0.1` with a small fixture router.

1. **StreamHttpClient:** sends method, headers and body; returns status, headers and body for 2xx and 4xx alike; does not follow redirects; a refused connection throws `HttpClientFailure`.
2. **ProjectId:** valid and invalid IDs from D6, trimming and lowercasing.
3. **MetadataServerTokenSource** (with `FakeHttpClient`): sends `Metadata-Flavor: Google`; parses the token and expiry from the fixed clock; a missing response header, a non-200 status or a connection failure becomes `AccessTokenUnavailable`.
4. **ApplicationDefaultCredentialsTokenSource** (with a temporary `$HOME` and `FakeHttpClient`): refresh request carries the right form fields; `service_account` files are rejected; missing file and `invalid_grant` give the right hints; the refresh token and client secret never appear in exception messages; with D3, the impersonation call uses the refreshed token and the right service account.
5. **AccessTokenSourceFactory:** unset and `metadata` give the metadata source; `application-default` gives the ADC source; any other value is rejected.
6. **ComputeEngine:** the request URL and bearer header; each row of the D5 table maps to its reason.
7. **FleetApi:** adds a project when the call succeeds; stores nothing when it fails; rejects duplicates without calling the API; lists projects in order of addition.
8. **Projects flow (HTTP):** `/admin/projects` requires sign-in; happy path from form to list; invalid ID and API failures return 422 with the message and store nothing; missing CSRF token returns 400; the page never contains the fake token.
9. **Existing `AppTest`:** `/admin/projects` returns 503 while the database is not ready.

## Changes to `docs/DESIGN.md`

In the implementation commit, once the decisions above are confirmed:

1. Section 8 item 2: add that development may use the developer's Application Default Credentials (user or impersonated service account, never key files), selected with `MAGUARI_GCP_CREDENTIALS`, and that production always uses the metadata server.
2. Section 8.1 item 1: add the verification call (D4) and the error reasons (D5).
3. Section 9: mention `MAGUARI_GCP_CREDENTIALS` next to `MAGUARI_DATABASE` as a development-only variable.

## Verification

1. Run the normal suite from `server/`:
   ```
   vendor/bin/phpunit
   ```
2. Run the suite on Ubuntu 22.04 with PHP 8.1 from the repository root (confirms the stream client works without `php-curl`):
   ```
   scripts/test-ubuntu-22.04.sh
   ```
3. Manual check against a real project in development. Log in to create the ADC file (with D3, add `--impersonate-service-account=<service-account-email>`):
   ```
   gcloud auth application-default login
   ```
   Then, from `server/`, with a database from step 3 that already has an administrator:
   ```
   export MAGUARI_DATABASE="$PWD/var/dev.sqlite"
   ```
   ```
   export MAGUARI_GCP_CREDENTIALS=application-default
   ```
   ```
   bin/maguari-server migrate
   ```
   ```
   php -S localhost:8080 -t public
   ```
   Sign in at `http://localhost:8080/auth/login`, open `/admin/projects` and add a project where the Compute Engine API is enabled. Then try a project ID that does not exist and one where the API is disabled, and confirm the messages.
4. Manual check of the default: stop the server, clear the variable, start it again and try to add a project. The page must say credentials are unavailable (no metadata server on the development machine), not crash.
   ```
   unset MAGUARI_GCP_CREDENTIALS
   ```
   ```
   php -S localhost:8080 -t public
   ```
5. Production check on the server instance happens with packaging, later.
