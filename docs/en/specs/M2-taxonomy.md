# M2 — Taxonomy contract

Design authority: ET-01 through ET-16 approved on 2026-10-09 (ADR-0017).
Implementation is present; complete runtime acceptance and the independent
security gate remain open. Content (19 tools) and Media (7 tools) are
implemented under the 2026-10-08 amendment; Comments (10 tools) under
ADR-0016. Blocks remains planned.

## Private execution boundary

All nine abilities use toolset `taxonomy` and visibility capability `read`.
Visibility never replaces core object permissions (`edit_term`,
`delete_term`, `assign_term` per term, `edit_terms`/`assign_terms` per
taxonomy at creation, `edit_post` per post at assignment). HTTP requires
the verified key owner's AuthContext; key/site tiers and allow/deny lists
still intersect current permissions. Packs never change the actor.

| Ability | Tier | Backing | Additional constraints |
|---|---|---|---|
| list-taxonomies | read | REST GET taxonomies | Managed-taxonomy filter, capability hints, default-term ID |
| list-terms | read | REST GET collection | Bounded filters/page, hierarchical parent gate, page-overflow refusal |
| get-term | read | REST GET item | Exact ID with taxonomy binding |
| create-term | write | REST POST collection | Parent precheck, meta precheck, slug_adjusted |
| update-term | write | REST POST item | Taxonomy binding, parent precheck, at-least-one-field |
| assign-post-terms | write | Direct wp_set/remove_object_terms | 13-step chain, int IDs only, default-term fill, trash guard |
| bulk-create-terms | write,costly | REST loop | <=50 items, created/existing/failed, no global transaction |
| bulk-assign-post-terms | write,costly | Direct loop | <=50 posts, append/remove only, per-post results |
| delete-term | destructive,costly | REST DELETE force=true | Default-term guard, dry_run impact, costly slot |

Each definition has explicit readonly/destructive/idempotent/openWorldHint
annotations. All tool descriptions are fixed English and treat term names,
descriptions, slugs and meta as untrusted data.

## Input contracts

Every root input is an object with additionalProperties=false. IDs are
positive integers. page>=1; per_page=1..100, default 20. Taxonomy slugs
match `^[a-z0-9_-]{1,32}$` and resolve through TaxonomyResolver at call
time. Term names are 1..200 characters after trim; slugs 1..200; descriptions
up to 5000; meta up to 20 registered show_in_rest keys. Assignment term IDs
are unique integers, at most 100; bulk post IDs at most 50 unique.

list-taxonomies accepts an optional post_type filter. list-terms accepts
search, parent (hierarchical only), include/exclude (up to 100), slug (up to
50), post, hide_empty, orderby id/name/slug/count/description/include,
order, page/per_page, description_max_chars 1..5000 default 300, and fields.
Parent on a flat taxonomy, orderby=include without include, and page beyond
max(1,total_pages) are refused. get-term requires taxonomy and id.
create-term requires taxonomy and name. update-term requires taxonomy, id
and at least one mutable field. assign-post-terms requires post_id,
taxonomy, term_ids and mode replace/append/remove (empty only for replace).
bulk-create-terms requires taxonomy and 1..50 items without meta.
bulk-assign-post-terms requires taxonomy, 1..50 post_ids, 1..100 term_ids
and mode append/remove. delete-term requires taxonomy and id with optional
dry_run.

## Taxonomy policy and hierarchy

TaxonomyResolver (ET-01) allows a taxonomy only when it exists, has
show_in_rest strictly true, is not in the explicit block list (nav_menu,
link_category, post_format, wp_theme, wp_template_part_area,
wp_pattern_category), uses exactly WP_REST_Terms_Controller, exposes the
expected `/{namespace}/{rest_base}` route, attaches to at least one managed
post type, and passes the deny-only `xnetvn_agent_hub_wp_taxonomy_allowed`
filter. All rejections share `taxonomy_unavailable`.

Hierarchical taxonomies support parent 0..N; flat taxonomies refuse parent
input. Parent assignment prechecks existence, same-taxonomy binding, non-self
and non-descendant relations (ET-09); core's silent tree-flattening is never
relied upon. Default terms (`default_{taxonomy}`, `default_term_{taxonomy}`)
cannot be deleted (ET-10) and refill emptied assignments where core does
(ET-12). Trash and auto-draft posts refuse assignment (ET-13).

## Output and safety

The strict term DTO permits id, taxonomy, name, slug, description,
description_truncated, parent (hierarchical only), count (published posts
only), link (string only), meta (registered keys only, on explicit fields
request) and slug_adjusted (writes only). term_taxonomy_id, term_group,
_links, _embedded and plugin-added fields never leave. Collections use
items,total,total_pages,page from core headers. Assignment returns
post_id,taxonomy,mode,term_ids,added,removed,default_term_applied. Bulk
assignment returns taxonomy,mode,updated,unchanged,failed. Bulk creation
returns taxonomy,created,existing,failed with input indexes. Deletion
returns id,taxonomy,deleted with children_reparented, or a dry_run impact
record without writing.

Every result passes OutputPipeline validation, redaction, byte bounding and
final validation. The generalized RestBackedAbility receipt (ET-07)
observes `rest_insert_{taxonomy}` (WP_Term) and `delete_term` with taxonomy
match; term and post IDs never confuse each other. Core error codes map per
ET-08 including taxonomy_unavailable and duplicate_term; provider data never
passes through. Failure after a confirmed write yields partial_failure with
a trusted created_id or affected_id. Unknown IDs never pass silently;
string IDs never create terms as a side effect.

## Settings and acceptance

This pack adds no site settings and one deny-only public filter
`xnetvn_agent_hub_wp_taxonomy_allowed`. Acceptance requires TX-01..122 from
the approved request on WordPress 6.9/7.1 (single-site and multisite),
role matrices for Contributor/Author/Editor/Administrator (plus Subscriber
with allowed_roles_override), all N-01..35 regressions, real MCP
application behavior, packaged Plugin Check and independent security
review. Unverified gates remain explicit in the execution report.

Verified 2026-10-09 against WordPress 6.9.10/7.1.3 sources, MCP 2026-07-28
tools (deterministic list, untrusted annotations, strict object schemas)
and adapter 0.7.0. Full requirement: `FEATURE_REQUEST_v0.1_2026-10-09_03_quan-ly-taxonomy-qua-mcp` in the local ticket store.
