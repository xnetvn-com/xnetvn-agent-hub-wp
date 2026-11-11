# ADR-0013: Content types, extended DTO and edit conflicts

- Status: Accepted
- Date: 2026-10-08
- Authority: explicit owner approval in the implementation session.

## Decision

E-01, E-02 and E-08 are accepted by the owner on 2026-10-08. Only REST-enabled post types using exactly WP_REST_Posts_Controller are manageable. Explicitly exclude attachment, revision, nav_menu_item, wp_block, wp_template, wp_template_part, wp_global_styles, wp_navigation, wp_font_family, wp_font_face, customize_changeset, custom_css, oembed_cache and user_request. Resolve routes via core at call time; filters may only deny. Extend the DTO with date_gmt, modified_gmt, parent, menu_order, template, comment_status, ping_status and format when available from the supported core schema. expected_modified compares UTC modification timestamps before writes; it is best-effort conflict detection, never a transaction lock.

## Verification

Implementation and runtime acceptance are tracked separately in the content/media plan. Independent security review remains required before release.
