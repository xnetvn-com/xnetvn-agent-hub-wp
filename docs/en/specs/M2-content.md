# M2 — Content, taxonomy and blocks

Status: Content implementation is present under the approved 2026-10-08 amendment; complete runtime acceptance and independent M1 security review remain open. Taxonomy and Blocks remain planned. The original 32-tool milestone roster is retained; this feature implements its 19 content tools only.

## Authority and boundaries

Use the private registry, current actor, tier/toolset policy and final output pipeline from M1. Packs depend on injected contracts, never adapter/auth/admin classes. Internal WordPress REST dispatch is the default; never start an HTTP request to the site's own REST URL. Every dispatch retains core validation and permission callbacks. Caller-supplied routes, namespaces, controller classes, SQL or arbitrary fields are prohibited.

Resolve post types and taxonomies only from the site's registered `show_in_rest` objects. Derive reviewed controller namespace/rest_base rather than assume a caller's string is a safe path. Validate positive IDs, match an existing item's actual type to the selected type, and bind revisions to their actual parent. Reject unavailable or unsupported controller shapes with safe errors.

## S01 — Internal REST helper

`RestBackedAbility` receives a trusted method/route and validated parameters, builds `WP_REST_Request`, calls `rest_do_request()` and maps `WP_Error` or status>=400 to safe tool execution errors. Preserve a reviewed reason code; omit SQL, stack traces, raw request data and arbitrary provider error details. Never bypass the handler's permission callback.

Support bounded collection pagination from the response headers. Output projection removes `_links`, `_embedded`, passwords and every field outside the explicit DTO. `fields` may narrow the declared output; mandatory identity fields remain. All output schemas are strict root objects. Logical collection arrays in the original table are represented as `{items:[...]}`; pagination is added where available, consistent with M1 structured-content requirements.

Default `content_format=rendered`; allowed formats are raw/rendered/excerpt. Request edit context only after the corresponding type edit capability succeeds. Raw post data additionally requires `edit_post` on that specific ID. Do not return raw title/excerpt/content by falling back from a refused edit request. Never remove core KSES filters or impersonate another actor.

## S02 — Content pack

Toolset `content`; visibility defaults to `edit_posts` while object/type-specific checks remain authoritative. Read/write/destructive tiers below are frozen. Input schemas reject unknown fields. Collection page>=1, per_page1..100, bulk IDs1..50 by default, positive object IDs, bounded strings and ISO8601 timestamps are mandatory. Creation defaults to draft; omission in update means preserve the existing field.

| Ability | Tier | Backing and additional checks | Logical output |
|---|---|---|---|
| list-post-types | read | GET types; only manageable REST-enabled types using exactly the default posts controller | items: slug,name,rest_base,hierarchical,supports |
| list-post-statuses | read | GET statuses | items: slug,name |
| list-posts | read | GET resolved type collection; reviewed status/search/author/categories/tags/date/order/page filters | items,total,total_pages,page |
| get-post | read | GET resolved type/ID; raw requires object edit permission | post |
| create-post | write | POST collection; status/publish/future, registered REST meta, featured media and terms retain core checks | post |
| update-post | write | POST item; object edit, status transitions and supplied fields only | post |
| trash-post | write | DELETE item without force; core trash semantics | id,status=trash |
| restore-post | write | POST item with draft/publish; verify it is trashed and core permits the transition | post |
| delete-post | destructive | DELETE item force=true; core delete permission | id,deleted=true |
| duplicate-post | write | Authorized get followed by authorized create; no password/private/meta forwarding outside allowlist | post |
| bulk-update-posts | write | Bounded repeated authorized item updates; no global transaction claim | updated,failed:id+safe code |
| list-revisions | read | GET actual parent revision collection | items:revision |
| get-revision | read | GET revision bound to requested parent | revision |
| restore-revision | write | Parent edit check, revision parent/type binding, then wp_restore_post_revision and safe re-read | post |
| set-featured-media | write | POST featured_media; retain attachment/type checks | post |
| stick-post | write | POST sticky; only supported post type and core sticky permission | post |
| get-post-meta | read | Authorized GET; registered show_in_rest keys only | meta object |
| update-post-meta | write | Authorized POST; registered schema and meta authorization only | meta object |
| search-content | read | GET search; reviewed type/subtype and bounded pagination | items:id,title,url,type,subtype |

Post DTO permits `id,type,status,title,slug,link,date,modified,author,excerpt,content,featured_media,categories,tags,sticky,meta` plus the declared truncation marker. Each selected field has an explicit type. IDs and parent relations never come from untrusted content. Registered metadata is projected through its declared schema and the sensitive-output pipeline; no generic option/user-meta accessor is introduced. Descriptions state that site content is untrusted data and core may filter HTML according to `unfiltered_html`.

Bulk failure reporting preserves per-item success/failure, with no fake zero/success for provider errors. Do not retry unknown mutation outcomes blindly. Duplicate/restore/term assignment operations must report partial failure honestly and avoid widening the source item's permissions or copying protected secrets.

## S03 — Taxonomy pack

Toolset `taxonomy`: list-taxonomies/read, list-terms/read, get-term/read, create-term/write, update-term/write, assign-post-terms/write, delete-term/destructive. Resolve only registered REST-enabled taxonomy routes. Validate hierarchical parent compatibility and term/object taxonomy bindings. Core manage_terms/assign_terms/edit_terms/delete_terms permissions apply to the correct taxonomy and object. Core refusal to delete a default term is preserved. Assignment supports explicit replace/append without granting term creation as a side effect.

Superseded in part by M2-taxonomy (ADR-0017, 2026-10-09): nine taxonomy tools including bulk-create-terms and bulk-assign-post-terms; assignment uses Direct core functions with replace/append/remove, int-only IDs, default-term refill and trash guard. See docs/en/specs/M2-taxonomy.md as the taxonomy authority.

Collections accept search,parent,hide_empty,page/per_page with bounded values; outputs are strict objects containing whitelisted term fields/items. Taxonomy-specific post assignment remains an authorized post update and verifies that the taxonomy is registered for that post type.

## S04 — Blocks pack

Toolset `blocks`, all six abilities tier read: list-block-types, list-block-patterns, get-block-pattern, parse-blocks, validate-blocks and render-blocks. Use registered core controllers/APIs, stable pagination where applicable and no filesystem/remote-source shortcuts. Do not confuse the `/wp/v2/blocks` reusable-block controller with block-type metadata, registered patterns or arbitrary markup parsing.

Parsing/validation/rendering bounds input bytes, nesting depth and block/node counts. Validate names and attributes against the actual registered block schemas; unknown types fail safely. Render previews with `do_blocks`, then sanitize for the actor with core KSES rules. Treat dynamic block callbacks as trusted installed code, yet enforce execution/output budgets and never expose a privileged render context. Output remains untrusted content data. No editor export/archive/helper operation is added.

## Acceptance matrix and sequence

1. Add failing helper contracts and remote integration fixtures for Editor, Author, Contributor, unavailable route/ID,403/404 and forbidden raw context. Implement the helper and prove private output projection.
2. Implement thin content slices in table order, each with reused pack contract, valid action, missing permission, missing ID and invalid-schema cases. Test custom REST-enabled types and type mismatches, Author vs another owner's post, Contributor publish/future refusal, terms/media checks and registered/unregistered meta.
3. Test duplicate and bulk partial outcomes,>50 items, revision-parent swaps, raw-data access, invalid status/date/order fields, KSES preservation and destructive-key tier denial.
4. Implement taxonomy with hierarchical/non-hierarchical/default-term/assignment role matrices. Implement blocks with paragraph/heading, unknown name/schema, deep nesting, dynamic rendering budgets and script sanitization.
5. Run the approved remote fixtures on WordPress6.9 and7.1.2, source quality/coverage gates, production ZIP and Plugin Check. Exercise a real MCP client creating/updating/deleting permitted content and denying cross-owner mutations. Measure tools/list with all three packs enabled and retain M1 security regressions.
6. Update catalog/controller coverage, operations/changelog and milestone report. Mark M2 accepted only when all required evidence exists; hosted CI, production and publishing remain separate gates.

## Verified official sources

Verified2026-10-05 with current official references and the downloaded6.9 core source: [internal REST dispatch](https://developer.wordpress.org/reference/functions/rest_do_request/), [Posts REST schema and routes](https://developer.wordpress.org/rest-api/reference/posts/), [revision restoration](https://developer.wordpress.org/reference/functions/wp_restore_post_revision/), [reusable-block REST controller](https://developer.wordpress.org/rest-api/reference/blocks/). Runtime controller/schema discovery on both approved remote fixtures is required before coding controller-specific assumptions.

## Approved content/media amendment (2026-10-08)

ADR-0013 supersedes the original post-type and DTO limits for this feature. E-01 through E-08 were approved by the owner. Content implementation may proceed before M1 independent acceptance; release remains gated. The extended post DTO includes the eight E-01 fields when core exposes their supported schema. expected_modified is a UTC best-effort comparison, not an atomic lock. See ADR-0013 through ADR-0015 and the implementation plan.
