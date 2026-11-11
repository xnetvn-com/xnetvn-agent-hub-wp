# ADR-0014: Bounded media and remote fetching

- Status: Accepted
- Date: 2026-10-08
- Authority: explicit owner approval in the implementation session.

## Decision

E-03 through E-07 are accepted by the owner on 2026-10-08. Add get-media-upload-limits, seven strictly validated media settings, deny-only hooks and bounded costly-operation leases. Upload decoded/validated bytes using raw internal REST bodies. Remote download backing is Direct; persistence is REST. HTTPS public names only, all A/AAAA addresses checked, pre-send IP pinning, no credentials, manual redirects, streaming byte and image-processing budgets, cleanup on every exit. Remote fetching defaults off and remains unavailable when transport pinning cannot be proved. All numeric defaults remain provisional until runtime measurements.

## Verification

Implementation and runtime acceptance are tracked separately in the content/media plan. Independent security review remains required before release.

## Security review ruling: request-private transport

The initial wp_safe_remote_get/global-hook recipe was rejected during independent review: mutable WordPress HTTP hooks can change filename, URL or CONNECT_TO before pinning is proved. Use the installed core-bundled Requests API with an explicit Curl transport and a private Hooks collection. Pin before curl_exec; reset destination URL/CONNECT_TO, disable proxies and decompression, retain core CA verification and streamed byte bounds. No global HTTP hooks are registered. Own URL/DNS policy and wp_http_validate_url remain mandatory. This changes an implementation mechanism, not public tool authority.

## Budget cleanup ruling

Lock-release storage errors must not replace a completed operation result. An unconfirmed release retains process-local ownership and latches further expensive work closed until request teardown. Registry keeps the trusted mutation envelope until after the budget returns, then performs final output validation. This requires no extra public hook and never treats unavailable storage as an empty slot.

## Investigation 2026-11-06: high-level WordPress HTTP API equivalence

- Status: investigation appended to the accepted ruling; it does not supersede the 2026-10-08 decision.
- Question: can the current request-private downloader be replaced by the high-level WordPress HTTP API without weakening H-01 through H-14?
- Decision: **NOT EQUIVALENT** for the inspected compatibility versions and current contracts. The request-private transport remains the accepted mechanism.
- Authority: source observation, inference and unverified runtime property are labelled separately. No runtime test, lab run, hosted CI, real-client test or production acceptance was performed for this investigation.
- Project source snapshot: `aaf3e4d83e2786c3af4016aa99de5e2f19413215`.
- Inspection date: 2026-11-06.

### Sources and versions

| Surface | WordPress 6.9 | WordPress 7.1.2 |
|---|---|---|
| High-level helper | `wp-includes/http.php:81-85` | `wp-includes/http.php:81-85` |
| HTTP request lifecycle | `wp-includes/class-wp-http.php:169-468` | `wp-includes/class-wp-http.php:169-469` |
| Core URL validation | `wp-includes/http.php:563-653` | `wp-includes/http.php:559-670` |
| Requests hook bridge | `wp-includes/class-wp-http-requests-hooks.php:51-77` | `wp-includes/class-wp-http-requests-hooks.php:51-77` |
| Bundled Requests | 2.0.11 | 2.0.17 |
| Requests dispatch | `wp-includes/Requests/src/Requests.php:451-469,656-689` | same lines |
| cURL transport | `wp-includes/Requests/src/Transport/Curl.php:171-217,362-460,533-559` | `:172-218,366-464,537-563` |
| Core source reference | `https://core.svn.wordpress.org/tags/6.9/wp-includes/` | `https://core.svn.wordpress.org/tags/7.1.2/wp-includes/` |

The 6.9 and 7.1.2 tags are project compatibility fixtures, not claims that 7.1.2 is the current release. WordPress 7.1.4 and 6.9.11 were current on the inspection date but were not inspected for this decision; the conclusion is bounded to the pinned tags above.

### Observed request lifecycle

The sequence is the same in both inspected versions. Unlabelled `class-wp-http.php` line numbers are 6.9 positions; from line 302 onward the 7.1.2 file is one line later (for example hooks `:344`/`:345`, redirect registration `:352`/`:353`, proxy `:400`/`:401`, `Requests::request()` `:415`/`:416`, response hooks `:440`/`:441` and `:467`/`:468`). The cURL offsets are shown in the source table.

1. `wp_safe_remote_get()` sets `reject_unsafe_urls=true` and delegates to `WP_Http::request()` (`http.php:81-85`). It installs no socket pinning, transport choice or request-private hook collection.
2. `WP_Http::request()` parses defaults, then runs `http_request_args` (`class-wp-http.php:252`) before any short-circuit, URL validation or transport selection. This filter receives the argument array; the URL is a separate by-value argument and is not changed by this filter.
3. It runs `pre_http_request` (`:277`). A non-false result returns without transmission; this filter can short-circuit but does not mutate the URL.
4. If `reject_unsafe_urls` is true, it calls `wp_http_validate_url()` (`:284-285`). That function resolves one IPv4 address with `gethostbyname()` and applies its own local-address checks; it does not enumerate all A/AAAA answers and has no IPv6 path (`http.php:563-653` in 6.9; `:559-670` in 7.1.2).
5. It applies the site block policy (`:301`) and resolves a streaming filename (`:310-323`).
6. It builds `WP_HTTP_Requests_Hooks` itself (`:340-345`) and registers redirect validation (`:351-353`), filename, redirect count, `max_bytes`, cookies, TLS verification, the `https_ssl_verify` filter and proxy configuration (`:355-409`).
7. It calls `WpOrg\Requests\Requests::request()` (`:415`).
8. `Requests::request()` merges defaults and calls `set_defaults()`, which registers auth, proxy and cookie handlers on the hook collection (`Requests.php:451-469,656-689`), then dispatches `requests.before_request` (`Requests.php:455`) and selects the transport. That hook receives the URL and `$options` by reference and is bridged as the global `requests-requests.before_request` action; it is the URL-mutation surface, not `http_request_args`. A global callback can also set `$options['transport']` or replace `$options['hooks']` before transport selection; that control is global, not request-scoped or exclusive.
9. `Curl::request()` calls `setup_handle()` (`Curl.php:171` in 6.9; `:172` in 7.1.2), then dispatches `curl.before_send` (`:173-174`; `:174-175`). The WordPress bridge runs its parent callbacks first, then `http_api_curl`, then the generic `requests-curl.before_send` action (`class-wp-http-requests-hooks.php:51-77`).
10. Only after that hook does `Curl` open the destination file (`Curl.php:175-182`; `:176-183`), initialize `max_bytes` (`:184-189`; `:185-190`), apply `verify`/`verifyname` TLS options (`:191-202`; `:192-203`) and call `curl_exec()` (`:204`; `:205`).
11. The transport dispatches `curl.after_send` (`:207`; `:208`), retries once on `CURLE_WRITE_ERROR` or `CURLE_BAD_CONTENT_ENCODING` (`:209-217`; `:210-218`), and processes the response.
12. `Curl::__construct()` sets `CURLOPT_ENCODING` to the empty string (`Curl.php:102-122`, line 110, in both tags), which enables cURL response decompression. `WP_Http`'s `decompress` argument is not forwarded to Requests in the inspected source.
13. `Curl::stream_body()` dispatches `request.progress` but ignores its return value, truncates the stored data at `max_bytes`, and returns the original chunk length (`:533-559`; `:537-563`). Truncation is not an early transfer abort.
14. After `Requests::request()` returns, `WP_Http::request()` fires the global `http_api_debug` action (`class-wp-http.php:440` in 6.9; `:441` in 7.1.2) and returns the response through the global `http_response` filter (`:467`; `:468`). Both are response-side writers relevant to H-10 and H-12.

The current request-private transport avoids this lifecycle: it constructs its own `WpOrg\Requests\Hooks`, registers `curl.before_send` directly, selects `WpOrg\Requests\Transport\Curl` explicitly, pins `CURLOPT_RESOLVE` before `curl_exec`, disables proxy/auth/decompression, and never registers a global WordPress HTTP callback (`src/Support/Http/RequestsDownloadTransport.php:59-140`).

### Candidate comparison

| Candidate | What it would do | Why it does not settle the contract |
|---|---|---|
| A. Plain `wp_safe_remote_get()` with global guards | Use the public helper and add `http_request_args`, `https_ssl_verify`, `http_api_curl` or `pre_http_request` filters | `http_request_args` has already run before the helper can reassert anything; `requests-requests.before_request` can still change the URL; a global callback can set `$options['transport']` or replace `$options['hooks']`, but that control is global and not request-scoped; later callbacks and the generic bridge can still change the request; the zero-global-filter test would fail |
| B. Scoped high-level bridge | Use `WP_Http` plus a late `http_api_curl` pin and late filters | The bridge cannot prove exclusive last control, cannot associate a nested identical URL with a request object, can replace the hook collection only globally without request identity, and cannot prevent earlier `http_request_args` or `requests-requests.before_request` mutation; `http_api_curl` is followed by `requests-curl.before_send` |
| C. Retain the request-private transport | Keep the accepted `RequestsDownloadTransport` and `SafeRemoteFetcher` | Preserves H-01 through H-14 but does not satisfy the requested high-level migration; this is the current accepted baseline, not a migration |

Candidate A is the simplest but fails the substantive safety contract. Candidate B is the only plausible bridge, but it fails the pre-send and hook-ownership requirements in the inspected versions. Candidate C is not a migration.

### Invariant matrix

| ID | Required property | Current mechanism | High-level candidate result | Disposition and evidence | Failure behavior and prospective test |
|---|---|---|---|---|---|
| H-01 | Site blocking before DNS and every redirect; deny-only extension; core validation never widens policy | `SafeRemoteFetcher` calls `SiteHttpPolicy::allows()` before `RemoteUrlPolicy::resolve()` and before each hop; `xnetvn_agent_hub_wp_remote_url_allowed` is deny-only (`SafeRemoteFetcher.php:66-80`) | Core block policy runs after `http_request_args`; our policy can be called before the helper, but `requests-requests.before_request` can change the URL afterwards and `http_request_args` can change redirect and option values; core's `wp_http_validate_url()` is not the same deny-only policy | **NOT PRESERVED.** The independent policy can be applied, but exclusive last control before transmission is unavailable. | Refuse before DNS; unit test with a fake site policy and redirect loop. |
| H-02 | DNS names only, HTTPS:443, no userinfo/local/own host, all A/AAAA public, nonempty and bounded, IDN only when supported | `RemoteUrlPolicy::resolve()` checks scheme, port, userinfo, host syntax, local suffixes, own hosts and every answer through `PublicAddressGuard` (`RemoteUrlPolicy.php:29-68`) | `wp_http_validate_url()` resolves one IPv4 with `gethostbyname()` and has no all-answer or IPv6 path (`http.php:563-653`/`:559-670`) | **NOT PRESERVED.** Core validation is weaker than the independent all-answer policy. | Refuse invalid host or answer; unit test mixed A/AAAA, IPv6, userinfo and port cases. |
| H-03 | Socket uses the validated IP before transmission; hostname remains for SNI/certificate identity; post-send checks are insufficient | `curl.before_send` sets `CURLOPT_RESOLVE` before `curl_exec` (`RequestsDownloadTransport.php:68-103`) | No public `WP_Http` argument exposes `CURLOPT_RESOLVE`; `http_api_curl` can set it, but the generic `requests-curl.before_send` action runs after it and the transport may not be cURL | **NOT PRESERVED.** Pre-send pinning cannot be guaranteed through the public path. | Fail closed when the pin is absent; network test against the controlled TLS fixture. |
| H-04 | Explicit transport capability and fail-closed availability; no automatic switch to an unpinned transport | `available()` requires cURL, the required constants, `WpOrg\Requests\Transport\Curl`, `Hooks` and a readable CA bundle; `Requests::request()` receives an explicit Curl transport (`RequestsDownloadTransport.php:47-49,106-126`) | `WP_Http` does not forward a caller `transport`; a global `requests-requests.before_request` callback can set `$options['transport']`, but that is global and not request-scoped; `http_api_curl` only fires for cURL | **NOT PRESERVED.** A non-cURL transport could be selected without a request-scoped fail-closed control. | Return `remote_fetch_disabled`; unit test missing cURL, constants or CA. |
| H-05 | No proxy, CONNECT_TO override, HTTP authentication, incoming bearer/cookies, ambient credentials or insecure TLS option reaches the connection | Transport sets empty proxy, `NOPROXY=*`, `HTTPAUTH=NONE`, no cookies, `SSL_VERIFYPEER=true`, `SSL_VERIFYHOST=2`, explicit CA (`RequestsDownloadTransport.php:73-99`) | `WP_Http` may add `WP_HTTP_Proxy` (including proxy auth), cookies and the `https_ssl_verify` filter; filtered `headers` or `http_api_curl` cURL options can add credentials; `http_request_args` can change `headers`, `cookies`, `filename`, `redirection` and `sslverify` (`class-wp-http.php:252,327-337,355-409`) | **NOT PRESERVED.** Global writers can reintroduce credentials, proxy or weaker TLS. | Refuse or strip proxy, auth, cookies and weak TLS; integration test with temporary filters. |
| H-06 | Redirects visible to `SafeRemoteFetcher`; fresh complete policy every hop; original aggregate deadline | `follow_redirects=false`; manual loop with one deadline and full policy per hop (`SafeRemoteFetcher.php:64-103`; `RequestsDownloadTransport.php:118`) | `redirection=0` and manual following are possible, but `http_request_args` can change the redirect count and `requests-requests.before_request` can change the URL; core redirect validation is not the independent per-hop policy | **PARTIAL.** The wrapper can retain manual redirects, but not exclusive control over the high-level request arguments. | Refuse the next hop on policy failure; integration test a redirect chain and the aggregate deadline. |
| H-07 | Stream only to a core-created owned temporary file; hooks cannot cause arbitrary filename writes; refuse unsafe mutation before file open or connection | `wp_tempnam()` creates the file; the private transport receives that exact path; the private pin hook runs before `fopen` (`SafeRemoteFetcher.php:84-89`; `Curl.php:173-182`) | `WP_Http` accepts `filename` through `http_request_args`; a filter can change it before the call; `http_api_curl` runs before `fopen`, but later global actions and argument writers are not removed | **NOT PRESERVED.** The filename is mutable before the high-level call reaches `fopen`. | Delete the temporary file and refuse before open; integration test a filename mutation filter. |
| H-08 | Bound stored bytes and terminate transfer on overflow; exact-limit success distinguishable from truncation; no automatic resend after deliberate abort | `DownloadBudget` returns 1 from the native progress callback; `max_bytes` is also set; the current code maps the abort to `payload_too_large` (`RequestsDownloadTransport.php:92-99,136-138`; `DownloadBudget.php:33-44`) | `limit_response_size` becomes `max_bytes`; `stream_body()` truncates and returns the original chunk length, and the `request.progress` return is ignored (`Curl.php:533-559`/`:537-563`); the transport may retry on write errors | **NOT PRESERVED.** Storage is bounded, but early transfer abort and no-resend semantics are not. | Return `payload_too_large`; network test exact limit, overflow and delayed tail. |
| H-09 | No decompression of remote bodies; encoded-response rejection; nonempty HTTP 200; safe `DownloadReply` | `Accept-Encoding: identity`, `CURLOPT_HTTP_CONTENT_DECODING=false`, and an explicit `content-encoding` check (`RequestsDownloadTransport.php:87-90,131-134`) | `Curl::__construct()` sets `CURLOPT_ENCODING` to empty, enabling decompression (`Curl.php:102-122`, line 110); `WP_Http`'s `decompress` argument is not forwarded in the inspected source | **NOT PRESERVED.** The high-level path can decompress before the wrapper sees the body. | Return `remote_fetch_failed`; network test gzip, encoded and empty bodies. |
| H-10 | Global argument, proxy, TLS, Requests, short-circuit and response hooks cannot bypass the reviewed boundary; identify later writers | No global HTTP callback is registered; the private `Hooks` collection is request-local (`RequestsDownloadTransport.php:65-104`) | `http_request_args`, `pre_http_request`, `https_ssl_verify`, `http_api_curl`, `requests-requests.before_request` (including `$options['transport']` and `$options['hooks']`), `requests-curl.before_send`, `http_api_debug` and `http_response` all execute; `http_api_curl` is followed by `requests-curl.before_send` (`class-wp-http-requests-hooks.php:51-77`; `class-wp-http.php:440,467`/`:441,468`) | **NOT PRESERVED.** The high-level path is defined by global extension points. | Deny before transfer; integration test the global argument, TLS, cURL and response hook matrix. |
| H-11 | Request association survives nested calls, including identical URLs; remove only owned callbacks in `finally`; preserve unrelated callbacks | The closure captures the validated target and a private `Hooks` object; no URL matching or shared boolean is used (`RequestsDownloadTransport.php:65-104`) | Global hooks are not request-scoped; URL matching cannot distinguish nested identical URLs; a shared boolean is insufficient | **NOT PRESERVED.** No request identity exists in the global hook API. | Remove only owned callbacks; integration test nested identical-URL requests. |
| H-12 | Every failure cleans intermediate files and preserves the public reason vocabulary; no URL/query/address/path/header/exception/payload leak | `SafeRemoteFetcher` uses `finally` to delete untransferred files; `FetchedFile` owns cleanup; `ReasonMapper` sanitizes reasons (`SafeRemoteFetcher.php:84-113`; `FetchedFile.php:36-43`) | A wrapper can clean its own temporary file and map reasons, but it cannot guarantee no intermediate file or exception text from core/hook writers | **PARTIAL.** Wrapper cleanup and reason mapping are possible; full boundary equivalence is not proved. | Delete untransferred files; unit/integration test error vocabulary and cleanup. |
| H-13 | State the achievable trust boundary; do not claim immunity to arbitrary equal-privilege PHP or a compromised runtime | The accepted ruling describes mutable global hooks as the reason for the request-private transport (`ADR-0014:15-17`) | The high-level path has more documented extension points; equal-privilege PHP remains outside any plugin boundary | **ACKNOWLEDGED.** No plugin can claim immunity to arbitrary equal-privilege PHP or a compromised WordPress runtime. | Document the trust limit; threat-model review with equal-privilege PHP cases. |
| H-14 | Preserve the no-global-HTTP-filter test and the ADR ruling, or obtain explicit authorization for an intentional integration change | `RemoteFetchTest::test_transport_is_private_from_global_http_filters()` asserts zero `http_request_args` calls (`tests/Integration/RemoteFetchTest.php:200-216`); the private transport meets it | Every `WP_Http::request()` call runs `http_request_args` at `class-wp-http.php:252`; the zero-call assertion cannot survive a high-level call without changing the test contract | **INCOMPATIBLE.** A high-level migration requires an explicitly authorized integration-behavior change; this investigation grants none. | Fail the integration test when a global filter runs; an intentional change needs explicit authorization. |

### Decision

**NOT EQUIVALENT.** Under WordPress 6.9 with Requests 2.0.11 and WordPress 7.1.2 with Requests 2.0.17, the public high-level path cannot preserve H-01, H-02, H-03, H-04, H-05, H-07, H-08, H-09, H-10, H-11 and H-14. H-06 and H-12 are only partially recoverable; neither restores the complete boundary. The blocking defects are pre-send IP pinning, request-scoped and exclusive transport selection, no-decompression, early abort, request identity, global hook control and the no-global-filter contract.

This is a negative result, not a vulnerability report. It does not prove that every imaginable bridge is impossible; it proves that no design available through the inspected public API and current contracts meets the invariant matrix. The accepted request-private transport remains in force. The dependent `REFACTOR_v1.0_2026-11-06_01_preserve-remote-fetch-safety-through-the-wordpress-http-api` must not be implemented from this result; its authorized reviewer should defer or cancel it.

### Prospective write set and test matrix

This investigation changes only the two ADR documents. If a future authorized design is proposed, the following tests are the minimum evidence; every item is **planned, not run** in this investigation.

| Test | Invariants | Environment |
|---|---|---|
| Site policy before DNS and on each manual redirect; deny-only filter result | H-01 | Unit with a fake `SiteHttpPolicy` and fake resolver |
| IPv4/IPv6 mixed answers, userinfo, non-443 port, local suffixes, own host | H-02 | Unit with a fake `DnsResolver` |
| Pre-send pin against a controlled TLS fixture; no fresh DNS resolution | H-03 | Network on the owned lab fixture |
| cURL absent, explicit transport unavailable, CA unreadable | H-04 | Unit plus controlled runtime availability |
| Proxy, auth, cookies and `https_ssl_verify` mutation attempts | H-05 | Integration/network with temporary filters |
| Redirect chain with a new DNS answer per hop and one aggregate deadline | H-06 | Integration with the owned fixture |
| Filename mutation before file open; hook attempts to change the destination | H-07 | Integration with a temporary filter |
| Exact byte limit, overflow with delayed tail, no resend after abort | H-08 | Network with the owned fixture |
| gzip/encoded body rejection and nonempty 200 requirement | H-09 | Network with the owned fixture |
| Global filter matrix: argument, pre-request, TLS, cURL and response hooks | H-10 | Integration |
| Nested identical-URL requests and callback ownership | H-11 | Integration |
| Error, timeout, redirect, cleanup and reason vocabulary | H-12 | Integration/unit |
| Threat-model review of documented extension points and equal-privilege PHP | H-13 | Documentation review |
| Zero-global-filter regression and ADR authority review | H-14 | Integration plus independent review |

The final migration would also need a separate planner-approved write set, a rollback that restores the request-private transport, and an independent T3 security review. No such write set is approved here.

### Rollback and residual uncertainty

Rollback for this investigation is to remove only the appended investigation sections from the two ADR files; the accepted 2026-10-08 ruling and all other content remain unchanged.

Residual uncertainty: the investigation is a source inspection, not a runtime campaign. It does not claim that a future WordPress release cannot expose a safe bridge; it records that the inspected 6.9/7.1.2 public surfaces do not. The current stable 7.1.4 and 6.9.11 were not inspected. WordPress hooks and bundled Requests behavior can change between releases, so a future proposal must repeat the source map and the test matrix at the then-current pinned versions.
