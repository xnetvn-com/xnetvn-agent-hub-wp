# M3 — Comments contract

Design authority: EC-01 through EC-11 approved on 2026-10-09 (ADR-0016).
Implementation is present; complete runtime acceptance and the independent
security gate remain open. Media is specified separately in M3-media.

## Private execution boundary

All ten abilities use toolset `comments` and visibility capability
`moderate_comments`. Visibility never replaces core object permissions
(`edit_comment` per comment, `edit_post` on the parent post). HTTP requires
the verified key owner's AuthContext; key/site tiers and allow/deny lists
still intersect current permissions. Packs never change the actor. The
comments route is the fixed core `/wp/v2/comments` collection.

| Ability | Tier | Backing | Additional constraints |
|---|---|---|---|
| list-comments | read | REST GET collection | Bounded filters/page; type allowlist |
| get-comment | read | REST GET item | Exact ID; type allowlist; raw/email need edit_comment |
| get-comment-counts | read | Direct | wp_count_comments; post gate edit_post |
| create-comment | write | REST POST item | Reviewed target post; default hold |
| reply-comment | write | REST POST item | Parent-bound post/parent; approve_parent flow |
| update-comment | write | Direct wp_update_comment | edit_comment; allowlisted fields; forced KSES |
| moderate-comment | write | Direct core status functions | edit_comment; transition table; trash gate |
| bulk-moderate-comments | write,costly | Direct loop | <=50 unique IDs; per-item results; deferred counting |
| delete-comment | destructive | Direct wp_delete_comment force | Only from spam/trash |
| purge-comments | destructive,costly | Direct WP_Comment_Query + delete | Only spam/trash; cutoff; limit; timebox |

Each definition has explicit readonly/destructive/idempotent/openWorldHint
annotations. All tool descriptions are fixed English and treat comment text,
author names and URLs as untrusted data.

## Input contracts

Every root input is an object with additionalProperties=false. IDs are
positive integers. page>=1; per_page=1..100, default 20. content_format is
raw/rendered, default rendered. fields only narrows the reviewed DTO; id and
status always remain. Raw access and author_email must never fall back when
refused.

list-comments accepts post (up to 20 IDs), status
approved/hold/spam/trash/all (default approved; all means approved plus hold),
type comment/pingback/trackback (default comment), parent (up to 20 IDs,
0 means top-level), author IDs (up to 20), author_email filter (does not leak
email), search<=200, after/before ISO8601 with explicit timezone converted to
site time, orderby date_gmt/id/post/parent, order, page/per_page,
content_max_chars 1..5000 default 500, and fields. Unsupported or malformed
filters are rejected.

create-comment requires post and content (1..65525 bytes, non-blank after
trim); status approved/hold defaults to hold. No author, IP, date, type,
parent or meta input exists; the comment always carries the key owner's
identity. reply-comment requires parent_id and content with the same status
rule plus approve_parent (default false); the post and parent come from the
parent comment. update-comment requires id and at least one of content,
author_name (1..245 chars/bytes), author_url (empty or http/https, <=200
bytes); post, parent, status, identity and meta inputs do not exist.
moderate-comment requires id and one of
approve/hold/spam/unspam/trash/untrash. bulk-moderate-comments requires 1..50
unique IDs and an action. delete-comment requires id. purge-comments requires
status spam/trash, optional limit 1..100 default 50 and optional before
cutoff timestamp.

## State machine and output

Normalized states are approved, hold, spam and trash. Same-state transitions
succeed with changed=false. approve from spam/trash, trash from spam, and
trash with the trash disabled are refused. unspam/untrash restore the
pre-spam/trash state recorded by core (hold when the record is missing).
Every transition is read before write, applied through the matching core
function, then re-read and verified; mismatches report internal_error with a
mutation receipt.

The strict comment DTO permits id, post, parent, type, status, author,
author_name, author_url, author_email (only on explicit request with
edit_comment), content, content_truncated, date, date_gmt (UTC with Z) and
link. IP, user agent, avatars, _links, _embedded and meta are never returned.
Collections use items,total,total_pages,page from core's pagination headers.
Counts report approved, hold, spam, trash, post_trashed, all (approved plus
hold) and total. Moderation returns id,status,previous_status,changed. Bulk
returns updated,unchanged,failed (id plus safe code). Deletion returns
id,deleted=true. Purge returns status,deleted,skipped,failed,remaining,cutoff
(UTC with Z). Replies add parent_approved.

Every result passes OutputPipeline validation, redaction, byte bounding and
final validation. The internal MutationResponse/MutationResult envelope
retains the core-observed ID through projection and output validation; it is
never serialized on the wire. Failure after a confirmed write yields
partial_failure with a trusted created_id or affected_id. Arbitrary provider
messages/data are discarded. Read back and reconcile before retrying a
partial or uncertain mutation.

## Safety notes

Creation and update force wp_filter_kses on pre_comment_content regardless of
unfiltered_html; the filter is added only when absent and removed only when
added, always in finally. Creation scopes a pre_comment_approved override that
only downgrades auto-approval to hold and never overrides spam verdicts.
Deletion is two-step and permanent: child comments are reparented, comment
meta is removed, and there is no undo. Purge selects only spam/trash older
than a single computed cutoff so newer arrivals survive; callers stop when
deleted reaches zero because skipped items keep remaining nonzero. Bulk and
purge consume the expensive-work budget (at most two concurrent slots per
site/key). Approving may notify post authors through core hooks; creations
through REST do not emit comment_post notifications.

## Settings and acceptance

This pack adds no site settings. Comment closing for a post reuses the
content pack's update-post (comment_status/ping_status). Acceptance requires
CM-01..77 from the approved request, both WordPress 6.9/7.1.2 fixtures and
multisite, all N-01..35 regressions, real MCP application behavior
(moderation queue, draft replies, spam purge, cross-owner refusal), packaged
Plugin Check and independent security review. Unverified gates remain explicit
in the execution report.
