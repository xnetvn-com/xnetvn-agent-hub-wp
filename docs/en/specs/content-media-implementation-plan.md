# Content and media implementation plan

Status: execution authorized on 2026-10-08; acceptance remains open.

## Goal and authority

Implement the 19 content and 7 media tools in the 2026-10-08 feature request.
The owner explicitly approved E-01 through E-08 and starting implementation
before independent M1 acceptance. The security gate remains mandatory before
release. Consolidate the roadmaps; do not restore the historical Vietnamese M2
request. Do not commit, push, merge or publish without separate authorization.

Execute inline with `superpowers:executing-plans`; use TDD and retain evidence.
No reference-library code or new runtime dependency is needed.

## Architecture

Keep authentication, policy, private registry and the output pipeline as the
only execution boundary. Packs implement `AbilityPack`, consume shared contracts,
and dispatch reviewed internal REST requests without changing the actor. Resolve
post types at invocation time. Only ServerGateway imports adapter classes.

Use a shared safe reason mapper and REST helper; content and media have separate
strict schemas and projections. Uploads use raw REST request bodies, never fake
multipart uploads. Remote fetch remains disabled by default and must fail closed
when pre-send IP pinning cannot be demonstrated. Expensive work uses atomic,
per-key connection-owned MySQL slots with cleanup in finally blocks.

## Constraints

- PHP >=8.2, WordPress >=6.9, MCP Adapter >=0.7.0 <0.8.0.
- Bootstrap and uninstall syntax remains PHP 7.2 compatible.
- English source, descriptions and canonical docs; Vietnamese translations.
- Private abilities, strict object schemas, fixed safe errors, no argument values
  in logs, no permission or transport bypass.
- No services or browsers locally. All integration/HTTP/E2E run through rtl.py.
- No claim of production readiness or milestone acceptance from local tests.

## Review focus

1. Core drops unknown meta silently: explicitly reject unregistered keys before
   mutation; retain per-key core authorization and sanitize output.
2. Projection after a successful mutation can fail: report an unknown/partial
   result without retrying or claiming rollback.
3. HTTP filters can alter transport or headers: prove pinning before transmission,
   never infer safe transport from a hook that ran after network access.
4. A stale lease must not let an earlier request release a new request's slot:
   use ownership tokens and atomic storage rather than transient read/write locks.
5. Core trash, attachment parent and registered-meta behavior vary by version:
   inspect installed controllers and pin behavior on both test fixtures.

## Ordered tasks

Each behavior task follows RED (new assertion fails), GREEN (minimal production
change), REFACTOR (preserve passing suite). No commit step is authorized.

- [x] Inspect git, current spec, architecture, ADRs, registry, settings and lab.
- [x] Baseline composer check: 177 tests, 50,416 assertions; PHPStan clean.
- [x] Verify installed WordPress 6.9/7.1.2 and adapter 0.7.0 on the lab.
- [ ] Record ADRs 0013 (types/DTO/conflict), 0014 (media/fetch/settings/budget),
  0015 (safe errors); create M3-media.md; update M2 and catalog.
- [ ] Safe errors: add `Abilities/ReasonMapper.php`; tests in
  `tests/Unit/Abilities/ReasonMapperTest.php` and pipeline/gateway integration.
  Interface: `error(string): WP_Error`, `sanitize(WP_Error): WP_Error`,
  `reason(string, int): string`. Preserve allowlisted reason only;
  replace upstream messages/data. Cover CT-38/39 and every output entrypoint.
- [ ] REST helper: `Abilities/RestBackedAbility.php` exposes
  `dispatch(string, string, array): WP_REST_Response|WP_Error` and
  `collection(WP_REST_Response, int, Closure): array|WP_Error`.
  Tests cover real core permission callbacks, invalid schema, missing routes,
  safe pagination headers and filter errors on both versions.
- [ ] `Content/PostTypeResolver.php`: `resolve(string): WP_Post_Type|WP_Error`,
  routes resolve through core at invocation time; exact default controller, denied system
  types, late CPT registration, deny-only hook. Cover CT-06..11/40.
- [ ] Content schemas and `ContentPack.php`: definitions through AbilityFactory;
  extend PostProjection only for approved E-01. Contract-test 19 names, tiers,
  visibility, explicit annotations and strict schemas/examples.
- [ ] Read tools: types/statuses/list/get/search/meta. Cover CT-05/14..16/20/33..35,
  pagination, no draft search leaks, raw refusal and registered-meta projection.
- [ ] Write tools: create/update/trash/restore/delete; UTC date handling, future
  validation, best-effort expected_modified; cover CT-01..04/22/28/30..32/42.
- [ ] Revisions, thumbnail and sticky: parent binding and exact object rights;
  cover CT-12/13/27/29. Direct restore checks before mutation and rereads via REST.
- [ ] Duplicate/bulk/meta writes: allowlist, protected-key exclusion, no author
  copying, maximum 50 unique IDs, honest per-ID outcomes. Cover CT-17..21/41.
- [ ] Register ContentPack in Plugin.php; test HTTP identity, scope/tier/site
  ceilings, lost/recovered role and multisite isolation (CT-23..26/37).
- [ ] Settings E-04: WpSiteSettings, SettingsController, Pages; shared numeric
  ranges including redirects=0, denylist validation, cookie/nonce boundaries;
  generate/document all real defaults in ENVIRONMENT.md.
- [ ] Media schemas, MediaProjection and MediaPack read/update/delete/limits;
  strict nested media_details, no EXIF/absolute paths, parent permissions and
  irreversible deletion. Cover MD-02/11..19/29/31.
- [ ] UploadPolicy and raw-body data upload: canonical base64, pre-decode bounds,
  unsafe filename components, actual MIME/core intersection, image budgets,
  temporary cleanup. Cover MD-01/03..10/28/32..35, including outside DIR_TESTDATA.
- [ ] Atomic costly-operation budget in the registry; at most two in-flight
  expensive calls per key, release on every exit, site/key isolation and expired
  lease recovery. Cover MD-30 and bulk budget consumption.
- [ ] DNS/URL policy and SafeRemoteFetcher; injectable DNS for unit tests, actual
  HTTPS-only cURL pinning for runtime, manual bounded redirects, stream limit,
  no credentials/proxy escape, cleanup and total deadline. Cover SS-01..24 and
  MD-20..28. Keep disabled when U-FETCH-1/2 proof is unavailable.
- [ ] Update all public docs/changelogs, ADR results, operations, adapter notes,
  threat model and roadmap; map C-01..18 to resolved or owner-approved exclusion.
- [ ] Remote acceptance: integration 6.9/7.1.2 and multisite, full N-01..35,
  CT-01..42, MD-01..35, SS-01..24, browser settings accessibility, real MCP
  clients, real 31-tool list timing, Plugin Check, composer check and build.
- [ ] Independent security review; fix findings with red/green tests. Report
  local/static, remote runtime, hosted CI, production and publishing separately.

## Sources and baseline observations

Verified 2026-10-08 using official docs and the installed remote source:

- [Internal REST dispatch](https://developer.wordpress.org/reference/functions/rest_do_request/)
- [Raw attachment upload](https://developer.wordpress.org/reference/classes/wp_rest_attachments_controller/upload_from_data/)
- [URL validation and its filters](https://developer.wordpress.org/reference/functions/wp_http_validate_url/)
- [Official adapter ability guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md)

The runtime fixture source, rather than moving trunk documentation, decides exact
adapter/Requests APIs. `ServerGateway::tool_result()` also currently collapses
safe errors; C-17 must cover it as well as OutputPipeline and Registry.

The lab currently runs this project's five healthy containers (2.75 GiB limits),
with roughly 8 GiB available RAM and 218 GiB free disk. Two stale firewall rules
outside the namespace were reported; no server-wide repair is authorized or
needed for this lab network. Rerun preflight before deployment/E2E.

## Evidence ledger

2026-10-08: clean starting tree at 3c86e69; feature branch
`feat/content-media-mcp-2026-10-08`; no product source changes at baseline.
Local composer emits system-Composer deprecation notices; project checks passed.
Full-codebase audit, runtime acceptance and independent review remain in progress.

## Verified progress checkpoint — 2026-10-08 resume

- Local composer check passed before the latest receipt/file-policy refinements:
  PHPCS/bootstrap compatibility, PHPStan level8 with no baseline,184 unit tests,
  50,478 assertions. Rerun after the final source changes.
- WordPress7.1.2 single-site integration passed128 tests/940 assertions with four
  intentional multisite skips before the latest receipt refinements.
- Original HTTP N-01..35 passed35/35 with no remaining IDs.
- Real Content/Media HTTPS matrix passed54 assertions, exactly31 tools, all26 new
  openWorldHint annotations, cross-owner/Contributor/read-key denials, matching
  UTC conflict token, raw-body PNG upload outside DIR_TESTDATA, update/delete.
- Browser baseline passed3 cases; a fourth media-settings accessibility/range
  case has been added and must be executed on the final source.
- Root cause of the HTTP-only upload failure: root-owned755 uploads directories
  on the lab prevented Apache writes. Fixed only this project's uploads volumes
  to www-data and added scoped startup ownership preparation. CLI upload probes
  and all temporary production/fixture exception instrumentation were removed.
- Independent review identified missing mutation receipts after projection and
  final output validation. MutationResponse retains the core-observed ID;
  MutationResult remains internal until OutputPipeline validates/redacts/bounds
  output. Tests now cover erased response ID, oversized rendered output, metadata
  projection, media projection exception and revision-readback failure.
- Valid filenames with spaces and canonical WAV detector alias were reproduced
  as failing tests and fixed; both targeted remote cases now pass.

Historical remaining-work list at that checkpoint (superseded where later evidence is recorded): final full6.9/7.1.2 single/multisite,
all expanded CT/MD/SS cases and controlled DNS/redirect/encoded-stream fixtures,
final browser run, public-trust certificate provisioning for published real-client
HTTP apps, packaged Plugin Check/build, complete EN/VI synchronization and a final
independent review of the revised mutation boundary. No commit/push/publication
has been performed. The full goal remains incomplete.

## Native TLS and published clients checkpoint

Owner instructed autonomous certificate creation and confirmed no controlled public
HTTPS endpoint was available. A seven-day test certificate was generated inside
this project's wp69 container, outside its document root; its private key was never
exported. Only the public certificate was transferred through rtl.py into the
root-only toolbox trust directory. The fixture listens on container443 with no
additional host port mapping. The lab-only bootstrap recognizes Apache's native
TLS separately from proxy headers. Hestia's public certificates were not replaced.

Published Inspector and FastMCP CLI apps passed verified-TLS scoped listing,
owner identity, permitted synthetic read, hidden higher tiers and revocation
denial. Published FastMCP additionally passed real create/update, cross-owner
denial, PNG upload, featured media and permanent delete through the same product
MCP route. This is native TLS inside the remote lab network, not a desktop GUI or
Hestia-client compatibility claim. Hestia ingress is separately covered by the
real HTTP matrix. Four browser cases passed, including media settings bounds and
automated accessibility; artifacts are in the remote-test20261008T111845Z folder.

A separate required review regression reproduced RELEASE_LOCK failure after a
successful mutation. Cleanup now preserves the result and closes further costly
work; its three budget tests pass. Release structure rejects SUPPORT.md after
Plugin Check flagged that unnecessary root release document. The latest packaged
checkpoint before that exclusion had0 errors/22 visible warnings (21 known
baseline findings plus SUPPORT.md). Final artifact validation must rerun after
source changes. Controlled DNS/redirect/encoded-stream/cleanup acceptance remains
incomplete; use the owned TLS fixture for the next deterministic network layer.


## Native transfer abort and regression checkpoint — 2026-10-08

The owned TLS fixture flushed its first2048 body bytes immediately; throwing in
Requests request.progress still returned only after its two-second tail. This
was reproduced with callback timing, not inferred from the failing test. Native
CURLOPT_XFERINFOFUNCTION now returns1 on overflow, while max_bytes bounds stored
bytes to the same ceiling. The original fixture and1.5-second assertion remain.
No deferred PHP exception or CURLE_WRITE_ERROR retry is used to stop the transfer.
The headers fixture has a positive control proving explicit synthetic
Authorization/Cookie headers are detected before asserting production omission.

Current-source checks:

- composer check:185 unit tests/50,482 assertions; PHPCS, bootstrap PHP7.2 and
  PHPStan level8 passed without a baseline.
- Integration:147 tests on each WordPress6.9/7.1.2 fixture, single-site1,019
  assertions with5 intentional multisite skips; multisite1,045 assertions,
  no skips. This includes exhausted-quota checks for both upload paths.
- Owned real TLS/network:6 tests/56 assertions on each core version, including
  pinning an otherwise unresolved hostname, manual redirect visibility, gzip
  preservation, immediate byte abort, fractional deadline and credential omission.
- Deterministic orchestration: mixed-address DNS rebinding on a later hop,
  private/downgrade/userinfo/non443/oversized redirects, bounded loops, intermediate
  cleanup, provider errors/exceptions, empty/status/encoded responses and explicit
  successful-file cleanup. Provider messages/data remain redacted.
- N-01..35 HTTP regression:35 passed. Published FastMCP rerun passed create/update,
  cross-owner denial plus reconciliation, PNG upload, featured media and deletion
  through verified owned-container TLS.
- Independent read-only review found no new concrete blocker in the revised
  transport/budget boundary; it did not accept the entire SSRF matrix or milestone.

The suite is split deliberately: public URL policy is never weakened to permit
private test IPs. Native transport tests use internal prevalidated test targets;
policy/orchestration tests inject DNS and a deterministic transport. This is not
proof of every named CT/MD/SS case or a live public DNS-rebinding fixture.
U-FETCH-4 PHP8.3/8.4 runtime checks, the remaining exact acceptance-case mapping,
complete C-01..18/L-01..20 evidence, EN/VI consistency and final milestone review
remain open. Packaging and HTTPS tool-list evidence are recorded after their
separate checks. No hosted-CI, production, commit, push or publishing claim follows.


## Follow-up content checks and documentation guard

Two further RED/GREEN regressions closed search widening through core query
filters (recheck actual ID/type/publish before projection) and wrong-type raw
requests (not_found precedes object raw permission). Missing attachment parent
data now remains omitted; only explicit core null normalizes to0. Independent
read-only review found no concrete blocker in these changes.

Latest product-source integration passed149 tests on all four version/site
combinations:1,023 assertions plus5 intentional skips on single-site;1,049
assertions without skips on multisite. composer check passed186 unit tests and
50,485 assertions. check:docs now compares the30 EN/VI settings/defaults against
actual PHP constants; intentional default drift was rejected and restoration
passed. No case-insensitive documentation filename collisions remain. EN/VI file
symmetry is not complete: the old VI M2 request remains absent by owner decision,
the active VI feature request still needs its English counterpart, and the new
VI Comments request is outside this task and remains untouched.

Latest HTTPS matrices each passed54 checks/31 tools and50,722 descriptor bytes.
WP6.9 list p50/p95:57.826168/60.494067ms; WP7.1.2:61.285566/63.619065ms.
These whole-request measurements exceed50ms; they do not isolate policy cost.
The package checker now removes only its disposable prior plugin extraction
before unzipping: overlay extraction had retained excluded SUPPORT.md from an
older run. Clean extraction returns0 errors/21 known baseline warnings. Exact
artifact hashes are checkpoint-specific and must be read from the current build.
