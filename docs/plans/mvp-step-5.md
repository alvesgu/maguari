# MVP Step 5: List the project's instances

## Context

Step 4 (`docs/plans/mvp-step-4.md`) added the Fleet context's first table (`fleet_projects`), the `/admin/projects` page, the `ComputeEngine` adapter and the token sources (metadata server in production, Application Default Credentials in development, a fake in tests). A project is stored only after `ComputeEngine::verifyCanListInstances()` succeeds.

Step 5 is: "List the project's instances from the API" (design section 15). It belongs to **Fleet** (design section 2.1), which owns projects, instances and the GCP API adapter.

Relevant design sections: 2.1 (contexts, rule 4: raw GCP data never leaves the adapter), 8 (GCP integration), 8.1 (administrator workflow, item 2), glossary ("Instance", "Discovered instance") and 11.3 (no state-changing GET routes).

What this step delivers, end to end:

1. On `/admin/projects`, each project's ID links to that project's page.
2. The project page calls the Compute Engine API with the same token sources and HTTP client as step 4, and shows the project's instances: name, zone, status and machine type.
3. If the call fails, the page shows the same fixed sentences as step 4 (design 8.1 item 1) and no list.
4. If some zones could not be reached, the page lists what it got and names the zones it could not reach.

## Scope question (please confirm)

Design section 8.1 item 2 says "Pick instances: choose from the list fetched from that project." Section 15 step 5 says only "List the project's instances from the API." Two readings:

- **A. List only (recommended).** Show the live list. Nothing is stored and nothing is picked. Picking belongs with enrollment (design 5.6), because the only reason to pick an instance is to generate its enrollment token, and step 6 ("receive client heartbeats") is the first step that needs an enrolled instance. Picking without enrollment would create stored rows with no use yet.
- **B. List and pick.** Also add a `fleet_instances` table and a POST route to pick instances, storing them as discovered instances. More work now, and the table's columns would be guessed before enrollment defines what it needs.

The rest of this plan assumes **A**. If you prefer B, I will extend the plan before writing code.

## Decisions (proposed)

**D1. Reuse from step 4, unchanged.** `AccessTokenSource` and its three implementations, `AccessTokenSourceFactory` (`MAGUARI_GCP_CREDENTIALS`), `HttpClient` and `StreamHttpClient`, `FakeTokenSource` and `FakeHttpClient`. No new credentials code and no new environment variables. One token is fetched per page view and used for every page of results (step 4 D2, no caching).

**D2. API call.** The same endpoint step 4 uses to verify access (step 4 D4), so no new permission is needed (`compute.instances.list`, design section 8 item 4):

```
GET https://compute.googleapis.com/compute/v1/projects/{project}/aggregated/instances?maxResults=500&returnPartialSuccess=true[&pageToken=...]
```

- `nextPageToken` is followed until it is absent, up to **10 pages** (5,000 instances). Beyond that the page lists the first 5,000 and says the list was cut short. This bounds the page to about 10 API calls, each with the existing 10 second timeout.
- No `fields` parameter. It would shrink responses, but the partial-response syntax for aggregated maps is one more thing to get wrong, and the traffic is inbound (free tier egress is not affected). Revisit if memory on the e2-micro becomes a concern.

**D3. Refactor `ComputeEngine`, do not duplicate it.** Its token handling, request sending and error mapping (step 4 D5) move into one private method used by both `verifyCanListInstances()` and the new `listInstances()`. Existing `ComputeEngineTest` cases keep passing unchanged. Error sentences stay in `ProjectAccessProblem`, and failures still throw `ProjectNotAccessible`.

**D4. Fleet's own model (design 2.1 rule 4).** The adapter translates each API item into a `DiscoveredInstance` value object. The glossary defines a discovered instance as "listed from the API but not yet enrolled," which is exactly every listed instance in this step. Fields:

| Field | From the API | Notes |
|---|---|---|
| `gcpInstanceId` | `id` | Kept as a string: Compute Engine IDs are unsigned 64-bit and can exceed `PHP_INT_MAX`. Not shown yet; enrollment will need it. |
| `name` | `name` | |
| `zone` | last segment of the `zone` URL | For example `us-central1-a` |
| `status` | `status` | Translated into a Fleet `InstanceStatus` enum (below) |
| `machineType` | last segment of the `machineType` URL | For example `e2-micro` |

`InstanceStatus` has one case per Compute Engine status (`PROVISIONING`, `STAGING`, `RUNNING`, `STOPPING`, `STOPPED`, `SUSPENDING`, `SUSPENDED`, `REPAIRING`, `TERMINATED`) plus `Unknown` for any value Google adds later, so a new status never breaks the page. Each case has a label for the UI ("Running", "Terminated"). Remediation will later need this enum for the instance status check (design 7.2 item 6), which is why it is an enum and not a string.

An item missing `id`, `name`, `zone` or `status`, or with values of the wrong type, makes the whole response an unexpected response (`ProjectAccessProblem::UnexpectedResponse`). A missing `machineType` is shown as empty.

**D5. Partial success.** With `returnPartialSuccess=true`, Google lists what it can and reports the rest in `unreachables` (zone or region names) and in per-zone `warning` entries. The adapter:

- ignores the warning code `NO_RESULTS_ON_PAGE` (a zone with no instances, which is normal);
- collects the zone names from `unreachables` and from any other warning, and returns them next to the instances.

The page then says "Some zones could not be reached: us-east1-b, ...". Zone names are shown; warning messages from Google are not (design 8.1 item 1: no raw API messages).

**D6. Result object.** `FleetApi::listInstances(int $projectId): InstanceList`, where `InstanceList` holds the `DiscoveredInstance[]`, the unreachable zone names and a `truncated` flag (D2). Instances are sorted by name, then zone. `$projectId` is `fleet_projects.id` (design 2.1 rule 3: refer to things by ID).

**D7. Route.** `GET /admin/projects/{id}` in the existing `/admin` group (session, CSRF and administrator checks inherited). `{id}` is `fleet_projects.id`, digits only in the route pattern. An unknown ID returns 404 through Slim's `HttpNotFoundException`. The route only reads from Google, so it is a safe GET (design 11.3). The page shows the GCP project ID as its heading, a link back to `/admin/projects` and the table or the error sentence. An API failure returns status 200 with the sentence, because the page itself exists; this differs from step 4's 422, which was for a rejected form. There is no "Refresh" button: reloading the page lists again.

**D8. No storage, no migration.** Nothing new is written to SQLite in this step (scope reading A).

## Out of scope for this step

- Picking, storing or enrolling instances (scope reading A; enrollment is design 5.6).
- Marking the server instance in the list (needs its own instance ID from the metadata server, with no ADC equivalent; comes with self-exclusion, design 7.2 item 7).
- Detecting whether an instance runs Ubuntu (possible from boot disk licenses, but not needed until enrollment).
- Showing IP addresses, labels, creation times or links to the Cloud console.
- Caching the list, token caching and the `check-gcp-credentials` subcommand (step 4 D2 and D9).
- Removing or renaming a project.
- Any CSS or JavaScript.

## Repository changes

```
server/
  src/
    Fleet/
      FleetApi.php                           add listInstances(int $projectId): InstanceList
      DiscoveredInstance.php                 value object (D4)
      InstanceStatus.php                     enum with labels (D4)
      InstanceList.php                       instances, unreachable zones, truncated flag (D6)
      ProjectRepository.php                  add find(int $id): ?Project
      Exception/
        ProjectNotFound.php                  unknown fleet_projects.id
      Gcp/
        ComputeEngine.php                    add listInstances(gcpProjectId); shared request and error mapping (D3); pagination (D2); partial success (D5)
    Http/
      App.php                                add GET /admin/projects/{id:[0-9]+}
      Controller/ProjectsController.php      add instances(); ProjectNotFound becomes HttpNotFoundException
  templates/
    projects.php                             project IDs link to their page
    project.php                              the instance list for one project
  tests/
    Fleet/Gcp/ComputeEngineTest.php          listInstances cases
    Fleet/InstanceStatusTest.php
    Fleet/FleetApiTest.php                   listInstances cases
    Http/ProjectsFlowTest.php                project page cases
docs/DESIGN.md                               record the confirmed decisions (see below)
```

`FleetApi` stays the only Fleet class used by `Http/`, and the controller and template see only `InstanceList`, `DiscoveredInstance` and `InstanceStatus`, never raw API data.

## Flow

**Show a project's instances.**

1. `GET /admin/projects/{id}`: session and administrator checks (existing middleware).
2. `FleetApi` looks up the project by ID. Unknown: `ProjectNotFound`, which the controller turns into a 404.
3. `ComputeEngine` gets one access token, then requests pages (D2) with `Authorization: Bearer <token>`. Any failed page fails the whole list with the step 4 error mapping; a half list with no warning would be misleading.
4. Items are translated into `DiscoveredInstance` objects (D4), unreachable zones collected (D5), and the list sorted (D6).
5. The page renders the table, or "No instances in this project." when the list is empty, or the error sentence.

## Tests

No test touches the network. All Compute Engine responses are queued on `FakeHttpClient`.

1. **ComputeEngine `listInstances`:** request URL, query parameters and bearer header; items from several zones are merged; `nextPageToken` is followed and passed on the next request; one token for all pages; the 10 page cap sets `truncated`; `NO_RESULTS_ON_PAGE` is ignored; `unreachables` and other warnings produce zone names; each status maps to its `InstanceStatus` and an unknown one to `Unknown`; a 64-bit ID above `PHP_INT_MAX` survives as a string; zone and machine type URLs are cut to their last segment; an item missing a required field gives `UnexpectedResponse`; each row of the step 4 D5 error table still maps to its reason for this call; a failure on page 2 fails the whole list.
2. **Existing ComputeEngine cases** for `verifyCanListInstances()` pass unchanged after the refactor (D3).
3. **InstanceStatus:** every Compute Engine status string maps to its case and label; unknown strings map to `Unknown`.
4. **FleetApi:** lists instances sorted by name then zone; unknown project ID throws `ProjectNotFound` without calling the API; nothing is written to the database.
5. **Projects flow (HTTP):** the projects list links to each project page; the project page requires sign-in; it shows the instances; an unknown ID and a non-numeric ID return 404; an API failure shows the fixed sentence and never the raw Google message; unreachable zones are named; the page never contains the fake token.
6. **Existing `AppTest`:** `/admin/projects/1` returns 503 while the database is not ready.

## Changes to `docs/DESIGN.md`

In the implementation commit:

1. Section 8.1 item 2: listing uses the same aggregated call as item 1, follows pages up to 5,000 instances, shows unreachable zones by name, and reuses item 1's error sentences. With scope reading A, add that picking instances happens together with enrollment (section 5.6), not in step 5.
2. Section 2.1 rule 4 needs no change; this step follows it.

## Verification

1. Run the normal suite from `server/`:
   ```
   vendor/bin/phpunit
   ```
2. Run the suite on Ubuntu 22.04 with PHP 8.1 from the repository root:
   ```
   scripts/test-ubuntu-22.04.sh
   ```
3. Manual check in development, with the same setup as step 4 (ADC login already done, database with an administrator and at least one project). From `server/`:
   ```
   export MAGUARI_DATABASE="$PWD/var/dev.sqlite"
   ```
   ```
   export MAGUARI_GCP_CREDENTIALS=application-default
   ```
   ```
   php -S localhost:8080 -t public
   ```
   Sign in at `http://localhost:8080/auth/login`, open `/admin/projects` and follow a project's link. Compare the list with the Compute Engine console (names, zones, statuses and machine types). Stop one instance in a test project, reload and confirm its status changes. Open `/admin/projects/999` and confirm a 404.
4. Production check on the server instance happens with packaging, later.
