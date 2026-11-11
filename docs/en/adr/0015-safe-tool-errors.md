# ADR-0015: Allowlisted execution reasons

- Status: Accepted
- Date: 2026-10-08
- Authority: explicit owner approval in the implementation session.

## Decision

Preserve only reviewed error reasons at all execution entrypoints, including ServerGateway::tool_result, OutputPipeline and Registry. Discard arbitrary provider messages and data. Core errors map through a reviewed classifier; unknown errors become internal_error. Hidden/unavailable tools keep their uniform unavailable response. No HTTP status supplied by an arbitrary plugin grants a trusted reason. Partial or unknown mutations must be reconciled rather than retried blindly.

## Verification

Implementation and runtime acceptance are tracked separately in the content/media plan. Independent security review remains required before release.

## Mutation receipt ruling

Retain independently observed insert/update/delete identity through successful REST responses, projection and OutputPipeline. MutationResponse and MutationResult are internal envelopes only. Trusted PartialFailure IDs survive sanitization; arbitrary provider error data does not. Revision restore retains its known parent identity through readback. Bulk reports each failed target separately and continues.
