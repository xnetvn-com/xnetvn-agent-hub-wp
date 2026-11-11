# ADR-0017: Taxonomy policy

Status: Accepted (2026-10-09). Owner approved ET-02, ET-03, ET-04, ET-05,
ET-06 on 2026-10-09; full ET-01..ET-16 approved for implementation on the
working branch per owner direction for this task.

## Context

M2 S03 froze seven taxonomy tools with REST backing and replace/append
assignment through post REST. Comments Pack (ADR-0016) is the newest pack
pattern. Direct WordPress source review (6.9.10 and 7.1.3, 2026-10-09)
showed post-REST assignment cannot append/remove safely and silently drops
unknown IDs, while term REST is sound. Official sources: WordPress
7.1.3/6.9.10 (2026-10-06 stable), MCP 2026-07-28 tools, adapter 0.7.0.

## Decisions

- ET-01: TaxonomyResolver allowlist (REST-enabled, default controller,
  expected route, block list, managed post type, deny-only
  `xnetvn_agent_hub_wp_taxonomy_allowed`); unified `taxonomy_unavailable`.
- ET-02: visibility `read` for all nine tools; object checks remain
  authoritative.
- ET-03: follow core creation rule (flat needs assign_terms, hierarchical
  needs edit_terms).
- ET-04: assignment uses Direct core functions with explicit checks and
  int-only IDs; documents no save_post/post_modified/revision side effects.
- ET-05/ET-06: add bulk-create-terms and bulk-assign-post-terms (50 cap,
  costly, per-item results, no global transaction; bulk-assign append/remove
  only).
- ET-07: generalize RestBackedAbility receipts for WP_Term with explicit
  kind; never use get_post_type for terms.
- ET-08: add taxonomy_unavailable and duplicate_term plus code-first core
  mappings; HTTP status checked first for rest_cannot_delete.
- ET-09: parent prechecks stricter than core (existence, same taxonomy,
  non-self, non-descendant).
- ET-10: default-term guard (`default_{tax}`, `default_term_{tax}`) before
  permission for a clear invalid_state; default status is public.
- ET-11: delete-term dry_run impact without writes; permission still
  required for non-default terms.
- ET-12: refill emptied assignments with the default term where core does.
- ET-13: refuse trash/auto-draft assignment with invalid_state.
- ET-14: slug_adjusted flag; no verbatim name/description comparison
  (entity/KSES normalization).
- ET-15: delete-term costly; list-taxonomies exposes can_assign/can_edit/
  can_delete/default_term_id (nullable).
- ET-16: term meta only for registered show_in_rest keys, prechecked, max
  20 keys; bulk-create carries no meta.

## Consequences

Catalog, api-standards, M2-taxonomy, overview, roadmap, threat-model,
performance, operations and changelogs updated (EN then VI). No new site
settings. No taxonomy registration/deletion, no merge, no non-REST support.
If core later supports safe append/remove with strict IDs, revisit ET-04
with a new ADR. U-TX items resolved by lab evidence where the design
depends on them; remaining unknowns stay explicit in the final report.
