# ADR-0016: Comments policy and direct moderation

- Status: Accepted
- Date: 2026-10-09
- Authority: explicit owner approval in the implementation session (all EC-01 through EC-11 approved for this build).

## Decision

EC-01 through EC-11 are accepted. Comment state changes, edits, deletions and purges call core admin functions directly (wp_set_comment_status, wp_spam_comment, wp_unspam_comment, wp_trash_comment, wp_untrash_comment, wp_delete_comment, wp_update_comment) with per-comment edit_comment checks, because the comments REST update path overwrites the original author IP, trash becomes permanent deletion when EMPTY_TRASH_DAYS is zero, repeat transitions return HTTP 500, and REST edit checks use the looser moderate_comments shortcut. Reads and creations stay on internal REST dispatch to retain validation, flood/duplicate handling and object permissions. Deletion requires spam or trash status first. Trash transitions are refused when the trash is disabled. New comments default to hold. Email is returned only on explicit request with edit_comment; IP, user agent and avatars are never returned. Only comment, pingback and trackback types are managed; note and foreign types report not_found. KSES is forced on agent-written content regardless of unfiltered_html. ReasonMapper gains duplicate_comment and comments_closed; RestBackedAbility observes WP_Comment receipts and comment delete hooks.

## Verification

Implementation is present; complete runtime acceptance and the independent security gate remain open. The CM-01 through CM-77 matrix, both WordPress 6.9/7.1 fixtures and multisite, all N-01 through N-35 regressions, real MCP application behavior and packaged Plugin Check are required before release.
