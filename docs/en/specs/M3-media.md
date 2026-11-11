# M3 — Media contract

Design authority: E-03 through E-07 approved on 2026-10-08. Implementation is
present; complete runtime acceptance and the independent security gate remain
open. Comments are a separate milestone and are outside this feature.

## Private execution boundary

All seven abilities use toolset `media` and visibility capability `upload_files`.
Visibility never replaces core object permissions. HTTP requires the verified
key owner's AuthContext; key/site tiers and allow/deny lists still intersect
current permissions. Packs never change the actor. The attachment route is
resolved by `rest_get_route_for_post_type_items('attachment')` at invocation time.

| Ability | Tier | Backing | Additional constraints |
|---|---|---|---|
| list-media | read | REST GET collection | Bounded filters/page; no trash status |
| get-media | read | REST GET item | Exact attachment ID; raw needs edit_post |
| update-media | write | REST POST item | At least one reviewed metadata field; parent edit permission |
| upload-media-from-data | write | REST raw-body POST | Canonical bounded base64; actual file inspection |
| upload-media-from-url | write | Direct download + REST raw-body POST | Off by default; public HTTPS; pinned private transport |
| delete-media | destructive | REST DELETE force=true | Permanent deletion, including generated sizes |
| get-media-upload-limits | read | Direct | Current-user core/plugin MIME and size intersection |

Each definition has explicit readonly/destructive/idempotent/openWorldHint
annotations. Only URL download has openWorldHint=true. Read tools are idempotent;
update-media is idempotent. Uploads and deletion are not declared idempotent.
All tool descriptions are fixed English and treat site content as untrusted data.

## Input contracts

Every root input is an object with additionalProperties=false. IDs are positive
integers; post=0 detaches an attachment. page>=1; per_page=1..100, default20.
content_format is raw/rendered/excerpt, default rendered. fields only narrows the
reviewed DTO; id/status always remain. Raw access must never fall back to rendered.

list-media accepts search<=200, media_type (up to5 reviewed categories),
mime_type (up to10 MIME strings), parent>=0, author IDs (up to20), after/before
ISO8601 with explicit timezone, status inherit/private, reviewed orderby/order,
page/per_page and content_format/fields. Core retains private-content permissions
and returns pagination totals. Unsupported or malformed filters are rejected.

update-media requires id and at least one of title<=1000, alt_text<=1000,
caption<=5000, description<=10000, post>=0. No author, status, slug, source_url,
guid, MIME or file replacement input exists. A nonzero parent must exist, must
not be an attachment/revision and must be editable by the current actor.

Data upload requires filename (1..255), declared mime_type<=127 and data_base64.
The static encoded ceiling is11184812 characters; the dynamic decoded limit is
checked before and after strict decoding. Noncanonical padding, whitespace and
data: prefixes are rejected. Upload metadata follows the update allowlist.

URL upload requires an HTTPS URL<=2048 and optionally filename plus reviewed
metadata. Omitted filename derives from the initial URL path, never an untrusted
Content-Disposition response. MIME is detected from downloaded bytes.

get-media-upload-limits has no input and reports max_upload_bytes,
data_upload_max_bytes, remote_fetch_enabled, remote_fetch_max_bytes, pixels_max
and sorted allowed_mime_types. It exposes no server paths or credentials.

## Output and mutation receipts

The strict attachment DTO permits id, date/date_gmt, modified/modified_gmt, slug,
status, link, title/caption/description, alt_text, media_type, mime_type,
source_url, author, post, media_details and optional truncated. UTC timestamps
have Z; unattached core post=null normalizes to0. Text follows content_format.
media_details only permits width/height/filesize, a safe relative file path and
sizes mapping to width/height/mime_type/source_url. No EXIF/image_meta, guid,
_links, _embedded, arbitrary metadata, absolute paths or file bytes are returned.
Collections use items,total,total_pages,page from core's pagination headers.
Deletion returns id,deleted=true; it cannot be undone and content links may break.

Every result passes OutputPipeline validation, redaction, byte bounding and final
validation. The internal MutationResponse/MutationResult envelope retains the
core-observed ID through projection and output validation; it is never serialized
on the wire. Failure after a confirmed write yields partial_failure with a trusted
created_id or affected_id. Arbitrary provider messages/data are discarded. Read
back and reconcile before retrying a partial or uncertain mutation.

## Upload safety

Approved types intersect get_allowed_mime_types for the current actor, then remove
the site denylist and deny-only MIME hook. Approved types: JPEG, PNG, GIF, WebP,
AVIF, PDF, MPEG/OGG/WAV audio, MP4/WebM video. The detector alias audio/x-wav
normalizes only to the equivalent canonical audio/wav; extension/core/caller
checks still apply. unfiltered_upload never bypasses this boundary.

Reject SVG/HTML/XML/JavaScript/CSS, executable/script/server configuration and
archive extensions, including dangerous components in double extensions. Refuse
slash, backslash, NUL, controls, '..' and oversized names. Core sanitizes harmless
spaces. The final extension must match real content and the approved core type.
Images require valid dimensions, the configured pixel ceiling and bounded
processing-memory estimate before core generates sub-sizes.

Use wp_tempnam-owned temporary files; remove them in finally. Persist only via
core raw-body attachment REST requests, with no fake $_FILES/get_file_params.
Core retains upload quotas, sanitization, permissions and hooks. Remove the
attachment-ID response header from the enclosing MCP response.

## Remote fetch safety

Remote fetching defaults off. Require cURL/Requests and pinning constants; fail
closed when unavailable. Validate HTTPS only, no userinfo, port443 only, DNS names
only, no single-label/local/internal/own-site host. Normalize IDN only with intl.
Check every A and AAAA answer against explicit prohibited ranges; empty, excessive
or mixed public/private answers are refused. WordPress URL validation is an
additional check and cannot widen the independent policy. The site's
`WP_HTTP_BLOCK_EXTERNAL` and `WP_ACCESSIBLE_HOSTS` settings are checked before DNS
resolution and again on every redirect hop; an accessible host never bypasses the
private-address and all-answer DNS policy.

ADR-0014 replaces the unsafe global HTTP-hook recipe with core-bundled Requests,
an explicit Curl transport and a private Hooks collection. Before transmission,
set the validated URL, clear CONNECT_TO/proxy, pin the checked IP, restrict
protocols to HTTPS, verify peer/host with core CA roots, clear arbitrary headers,
disable decompression and enforce time/stream byte bounds. No global HTTP hook is
registered. Redirects are manual; each hop repeats the complete policy and pin.
Accept only200, nonempty bounded bytes and no encoded response. Ignore remote
MIME and filename declarations. Delete every intermediate/error temporary file.
All policy refusals use the same remote_fetch_refused reason/message.

Requests max_bytes limits stored bytes but does not stop the transfer. Apply the
same ceiling to storage and use CURLOPT_XFERINFOFUNCTION returning1 on overflow;
CURLE_ABORTED_BY_CALLBACK avoids Requests' write-error retry. Do not rely on
throwing from request.progress: the installed PHP/cURL runtime deferred that
exception until the delayed response tail. A real TLS regression checks abort
before the tail, with no relaxation of the deadline or fixture size.

On multisite, both upload paths check is_upload_space_available before decoding
or fetching. Exhausted quota returns quota_exceeded rather than interpreting
core's zero upload-size ceiling as malformed input or oversized payload. Core
still checks quota again while persisting the attachment.

The expensive-work budget consumes costly() for both uploads and bulk content.
At most two connection-owned, nonblocking MySQL slots exist per site/key; STDIO
uses the explicit actor. Connection closure releases crashed-worker locks; local
ownership tracking prevents same-connection reentrant bypass. No TTL expires
while work is still running. Storage failure never becomes an available slot.

## Settings and acceptance

ENVIRONMENT.md is the canonical complete settings inventory. Media defaults:
remote enabled=false; remote max10485760 bytes (1024..67108864), timeout15 seconds
(3..30), redirects3 (0..5), data max1048576 bytes (1024..8388608), pixels40000000
(1000000..100000000), MIME denylist empty. Numeric defaults are provisional budgets,
not production capacity measurements. Persistence, management REST and UI share
these bounds; management remains cookie+nonce+capability protected.

Acceptance requires MD-01..35 and SS-01..24 from the approved request, both
WordPress6.9/7.1.2 fixtures and multisite, no-DIR_TESTDATA HTTP upload, all N-01..35
regressions, real MCP application behavior, browser accessibility, packaged Plugin
Check and independent security review. A successful public HTTPS sample does not
prove controlled DNS rebinding, redirect, compressed-stream or cleanup matrices.
Unverified gates remain explicit in the execution report.
