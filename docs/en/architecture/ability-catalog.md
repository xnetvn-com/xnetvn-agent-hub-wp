# Ability catalog and core REST coverage

Six ability packs are implemented: system (5), content (19), taxonomy (9), media (7), comments (10) and users (12). Entries marked `Planned` require their milestone contracts and object-permission tests; implementation and runtime acceptance are tracked separately. The system execute-ability entry rechecks the target's full policy; its read entry tier grants no write/destructive/admin authority on the target.

| Ability | Tier | Toolset | Proposed visibility capability | Backing | Milestone | Status |
|---|---|---|---|---|---|---|
| xnetvn/get-session-info | read | system | read | Direct | M1 | Implemented |
| xnetvn/get-site-info | read | system | read | Direct | M1 | Implemented |
| xnetvn/discover-abilities | read | system | read | Direct | M1 | Implemented |
| xnetvn/get-ability-info | read | system | read | Direct | M1 | Implemented |
| xnetvn/execute-ability | read entry; target risk enforced | system | read | Direct | M1 | Implemented |
| xnetvn/list-post-types | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/list-post-statuses | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/list-posts | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/get-post | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/create-post | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/update-post | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/trash-post | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/restore-post | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/delete-post | destructive | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/duplicate-post | write | content | edit_posts | Direct | M2 | Implemented; acceptance pending |
| xnetvn/bulk-update-posts | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/list-revisions | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/get-revision | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/restore-revision | write | content | edit_posts | Direct | M2 | Implemented; acceptance pending |
| xnetvn/set-featured-media | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/stick-post | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/get-post-meta | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/update-post-meta | write | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/search-content | read | content | edit_posts | REST | M2 | Implemented; acceptance pending |
| xnetvn/list-taxonomies | read | taxonomy | read | REST | M2 | Implemented; acceptance pending |
| xnetvn/list-terms | read | taxonomy | read | REST | M2 | Implemented; acceptance pending |
| xnetvn/get-term | read | taxonomy | read | REST | M2 | Implemented; acceptance pending |
| xnetvn/create-term | write | taxonomy | read | REST | M2 | Implemented; acceptance pending |
| xnetvn/update-term | write | taxonomy | read | REST | M2 | Implemented; acceptance pending |
| xnetvn/assign-post-terms | write | taxonomy | read | Direct | M2 | Implemented; acceptance pending |
| xnetvn/bulk-create-terms | write | taxonomy | read | REST | M2 | Implemented; acceptance pending |
| xnetvn/bulk-assign-post-terms | write | taxonomy | read | Direct | M2 | Implemented; acceptance pending |
| xnetvn/delete-term | destructive | taxonomy | read | REST | M2 | Implemented; acceptance pending |
| xnetvn/list-block-types | read | blocks | read | REST | M2 | Planned |
| xnetvn/list-block-patterns | read | blocks | read | REST | M2 | Planned |
| xnetvn/get-block-pattern | read | blocks | read | REST | M2 | Planned |
| xnetvn/parse-blocks | read | blocks | read | Direct | M2 | Planned |
| xnetvn/validate-blocks | read | blocks | read | Direct | M2 | Planned |
| xnetvn/render-blocks | read | blocks | read | Direct | M2 | Planned |
| xnetvn/list-media | read | media | upload_files | REST | M3 | Implemented; acceptance pending |
| xnetvn/get-media | read | media | upload_files | REST | M3 | Implemented; acceptance pending |
| xnetvn/update-media | write | media | upload_files | REST | M3 | Implemented; acceptance pending |
| xnetvn/upload-media-from-url | write | media | upload_files | Direct + REST | M3 | Implemented; acceptance pending |
| xnetvn/upload-media-from-data | write | media | upload_files | REST | M3 | Implemented; acceptance pending |
| xnetvn/get-media-upload-limits | read | media | upload_files | Direct | M3 | Implemented; acceptance pending |
| xnetvn/delete-media | destructive | media | upload_files | REST | M3 | Implemented; acceptance pending |
| xnetvn/list-comments | read | comments | moderate_comments | REST | M3 | Implemented; acceptance pending |
| xnetvn/get-comment | read | comments | moderate_comments | REST | M3 | Implemented; acceptance pending |
| xnetvn/get-comment-counts | read | comments | moderate_comments | Direct | M3 | Implemented; acceptance pending |
| xnetvn/create-comment | write | comments | moderate_comments | REST | M3 | Implemented; acceptance pending |
| xnetvn/reply-comment | write | comments | moderate_comments | REST | M3 | Implemented; acceptance pending |
| xnetvn/update-comment | write | comments | moderate_comments | Direct | M3 | Implemented; acceptance pending |
| xnetvn/moderate-comment | write | comments | moderate_comments | Direct | M3 | Implemented; acceptance pending |
| xnetvn/bulk-moderate-comments | write | comments | moderate_comments | Direct | M3 | Implemented; acceptance pending |
| xnetvn/delete-comment | destructive | comments | moderate_comments | Direct | M3 | Implemented; acceptance pending |
| xnetvn/purge-comments | destructive | comments | moderate_comments | Direct | M3 | Implemented; acceptance pending |
| xnetvn/list-themes | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/get-active-theme | read | appearance | edit_theme_options | Direct | M4 | Planned |
| xnetvn/list-menus | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/get-menu | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/create-menu | write | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/update-menu | write | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/delete-menu | destructive | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/list-menu-locations | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/assign-menu-location | write | appearance | edit_theme_options | Direct | M4 | Planned |
| xnetvn/list-sidebars | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/list-widgets | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/update-widget | write | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/list-templates | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/get-template | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/update-template | write | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/get-global-styles | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/update-global-styles | write | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/list-patterns | read | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/create-pattern | write | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/delete-pattern | destructive | appearance | edit_theme_options | REST | M4 | Planned |
| xnetvn/get-custom-css | read | appearance | edit_theme_options | Direct | M4 | Planned |
| xnetvn/update-custom-css | write | appearance | edit_theme_options | Direct | M4 | Planned |
| xnetvn/get-settings | read | settings | manage_options | REST | M4 | Planned |
| xnetvn/update-settings | write | settings | manage_options | REST | M4 | Planned |
| xnetvn/flush-rewrite-rules | write | settings | manage_options | Direct | M4 | Planned |
| xnetvn/list-users | read | users | list_users | REST | M5 | Implemented; acceptance pending |
| xnetvn/get-user | read | users | list_users | REST | M5 | Implemented; acceptance pending |
| xnetvn/list-roles | read | users | list_users | Direct | M5 | Implemented; acceptance pending |
| xnetvn/get-user-capabilities | read | users | list_users | Direct | M5 | Implemented; acceptance pending |
| xnetvn/get-my-profile | read | users | read | REST | M5 | Implemented; acceptance pending |
| xnetvn/update-my-profile | write | users | read | REST | M5 | Implemented; acceptance pending |
| xnetvn/create-user | admin | users | list_users | REST | M5 | Implemented; acceptance pending |
| xnetvn/update-user | admin | users | list_users | REST | M5 | Implemented; acceptance pending |
| xnetvn/set-user-role | admin | users | list_users | Direct | M5 | Implemented; acceptance pending |
| xnetvn/set-user-status | admin | users | list_users | Direct | M5 | Implemented; acceptance pending |
| xnetvn/terminate-user-sessions | admin | users | list_users | Direct | M5 | Implemented; acceptance pending |
| xnetvn/delete-user | admin | users | list_users | REST | M5 | Planned (out of scope for _04, D4) |
| xnetvn/send-password-reset | admin | users | list_users | Direct | M5 | Implemented; acceptance pending |
| xnetvn/list-plugins | read | plugins | activate_plugins | REST | M5 | Planned |
| xnetvn/get-plugin | read | plugins | activate_plugins | REST | M5 | Planned |
| xnetvn/search-plugin-directory | read | plugins | activate_plugins | Direct | M5 | Planned |
| xnetvn/activate-plugin | admin | plugins | activate_plugins | REST | M5 | Planned |
| xnetvn/deactivate-plugin | admin | plugins | activate_plugins | REST | M5 | Planned |
| xnetvn/install-plugin | admin | plugins | activate_plugins | Direct | M5 | Planned |
| xnetvn/update-plugin | admin | plugins | activate_plugins | Direct | M5 | Planned |
| xnetvn/delete-plugin | admin | plugins | activate_plugins | Direct | M5 | Planned |
| xnetvn/get-site-health | read | maintenance | manage_options | REST | M5 | Planned |
| xnetvn/list-cron-events | read | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/check-updates | read | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/get-debug-log-tail | admin | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/export-content | admin | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/flush-cache | write | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/run-cron-event | write | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/update-core | admin | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/update-theme | admin | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/update-translations | admin | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/list-privacy-requests | admin | maintenance | manage_options | Direct | M5 | Planned |
| xnetvn/export-personal-data | admin | maintenance | manage_options | Direct | M5 | Planned |

Network abilities and optional M7 integrations require separate complete specifications; this catalog does not invent those tool contracts.

## WordPress 6.9 controller inventory

Inventory was read from the official WordPress 6.9 distribution on 2026-10-04. The base controller is abstract and excluded. All concrete endpoint files are accounted for; existence does not imply a given site exposes its routes.

| Controller | Pack / decision |
|---|---|
| abilities-v1-categories | Excluded: private MCP server is the only agent surface |
| abilities-v1-list | Excluded: private MCP server is the only agent surface |
| abilities-v1-run | Excluded: private MCP server is the only agent surface |
| application-passwords | Permanently excluded: secret/credential administration |
| attachments | media (M3) |
| autosaves | Deferred to M4 specification: editor revision/autosave helpers |
| block-directory | Excluded: editor helpers, remote-fetch or export/ZIP surfaces |
| block-pattern-categories | blocks (M2) |
| block-patterns | blocks (M2) |
| block-renderer | blocks (M2) |
| block-types | blocks (M2) |
| blocks | appearance (M4) |
| comments | comments (M3) |
| edit-site-export | Excluded: editor helpers, remote-fetch or export/ZIP surfaces |
| font-collections | Deferred to M4 specification: font lifecycle requires explicit validation/permission scope |
| font-faces | Deferred to M4 specification: font lifecycle requires explicit validation/permission scope |
| font-families | Deferred to M4 specification: font lifecycle requires explicit validation/permission scope |
| global-styles | appearance (M4) |
| global-styles-revisions | Deferred to M4 specification: editor revision/autosave helpers |
| menu-items | appearance (M4) |
| menu-locations | appearance (M4) |
| menus | appearance (M4) |
| navigation-fallback | Excluded: editor helpers, remote-fetch or export/ZIP surfaces |
| pattern-directory | Excluded: editor helpers, remote-fetch or export/ZIP surfaces |
| plugins | plugins (M5) |
| post-statuses | content (M2) |
| post-types | content (M2) |
| posts | content (M2) |
| revisions | content (M2) |
| search | search-content (M2) |
| settings | settings (M4) |
| sidebars | appearance (M4) |
| site-health | maintenance (M5) |
| taxonomies | taxonomy (M2) |
| template-autosaves | Deferred to M4 specification: editor revision/autosave helpers |
| template-revisions | Deferred to M4 specification: editor revision/autosave helpers |
| templates | appearance (M4) |
| terms | taxonomy (M2) |
| themes | appearance (M4) |
| url-details | Excluded: editor helpers, remote-fetch or export/ZIP surfaces |
| users | users (M5) |
| widget-types | appearance (M4) |
| widgets | appearance (M4) |

`icons`, `icon-collections` and `view-config` are not present in this WordPress 6.9 endpoint directory; reassess when increasing the minimum WordPress version. `wp_navigation`, WXR import and site icon/logo have no dedicated controller here; their inclusion or exclusion requires an M4/M5 specification decision. Application Passwords are excluded even if their controller lives outside this directory.

Source: [official WordPress 6.9 distribution](https://wordpress.org/wordpress-6.9.zip), [REST API handbook](https://developer.wordpress.org/rest-api/).
