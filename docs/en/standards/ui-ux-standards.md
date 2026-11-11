# User Interface & Experience Standards (UI/UX Standards)

> **Design standards for the WordPress administration interface, targeting aesthetics, consistency and WCAG 2.2 Level AA. Target and observed status are recorded separately in §9; see also [Testing Guide](../TESTING.md).**

---

## 1. WordPress Admin Interface Design Principles

1. **Seamless Integration with the WordPress Core Design System:**
   - Use standard WordPress CSS classes (`wrap`, `button`, `button-primary`, `notice`, `form-table`, etc.).
   - Respect the administration color scheme selected in the user's personal WordPress Profile.
2. **Transparency & Absolute Safety in Secret Handling:**
   - Secret API keys are displayed in a Modal Dialog with a clear warning.
   - The dialog has a quick copy button using the secure Clipboard API.
   - When the user closes the dialog (clicking Done or pressing `Escape`), the secret key is permanently destroyed from the browser's DOM memory.
3. **Clear Status Feedback:**
   - Every key creation, editing, deletion or rotation operation receives an immediate status notification (Toast or Admin Notice).
   - Use a Loading state / Spinner while submitting API requests to prevent repeated clicks (Debounce / Disable button).

---

## 2. WCAG 2.2 Level AA Accessibility Standards

Target: the xNetVN Agent Hub WP administration interface targets the international **WCAG 2.2 Level AA** standard. Observed conformance is recorded separately in §9. Automated axe results alone do not establish WCAG conformance; see [Testing Guide](../TESTING.md) and [architecture/overview.md](../architecture/overview.md).

### 2.1 Color Contrast

- Normal text has a minimum contrast ratio of **4.5:1** against its background.
- Large text (18pt or larger) and interface icons have a minimum ratio of **3.0:1**.
- Do not use color as the sole indicator of information (e.g. Active/Revoked status must include both text labels and color).

### 2.2 Keyboard Navigation

- 100% of interactive components (buttons, links, input fields, modals) can be accessed and operated entirely by keyboard (`Tab`, `Shift+Tab`, `Enter`, `Space`, `Esc`).
- Modal dialogs must implement a Focus Trap while open and Focus Restoration to the original trigger button after closing.
- Provide a Visible Focus Ring: `outline: 2px solid #2271b1`.

### 2.3 Semantic Labels for Screen Readers & ARIA

- Icons or icon-only buttons must have an `aria-label` clearly describing the action (e.g. `aria-label="Copy API key to clipboard"`).
- Error and status messages use `role="alert"` or `aria-live="polite"` so screen readers notify visually impaired users without interrupting their experience.
- Key lists and audit log tables have column headers (`<th>` with `scope="col"`) and table captions (`<caption>`).

---

## 3. Automated Interface & Accessibility Testing

- The project integrates the automated **`@axe-core/playwright`** checking tool into its E2E suite (`tests/E2E/admin.spec.js`).
- Target for every Pull Request changing the interface: Axe Core testing with **0 WCAG 2.2 AA violations**. An axe pass is a signal only and does not by itself establish WCAG conformance; manual checks, screen-reader checks and the acceptance record in §9 remain required.
- UI suites run in the Remote Test Lab; see [Testing Guide](../TESTING.md) for setup, `UI_BASE_URL` from the lab handoff, and the `UI-FX/COM/OV/TL/KY/AC/ST/HP/NT` mapping.

---

## 4. Management Pages (Six Views)

Implemented in `src/Admin/Pages.php` (`titles()`, `menu()`, `render()`, `navigation()`). All six views require the management capability; denied actors receive a denial message and no management content. Navigation marks the current view with `aria-current="page"` and keeps one top-level heading per view.

| View | Admin route | Flow and key controls |
|---|---|---|
| Overview | `admin.php?page=xnetvn-agent-hub-wp` | Getting-started list (3 steps with links to profile, Keys, Help); readonly `MCP endpoint` input with `Copy endpoint`; `Connection` and `Site` definition lists; `Check Authorization header` diagnostic (presence only, one request); connection help link when not ready. |
| Keys | `admin.php?page=xnetvn-agent-hub-wp-keys` | Scoped table (`Name`, `Key ID`, `Owner`, `Risk tier`, `Toolsets`, `Created`, `Last used`, `Expires`, `Status`, `Actions`); manager filters `state` (`all`/`active`/`expired`/`revoked`) and `owner`; create group (§7); secret and confirm dialogs; pagination. |
| Activity | `admin.php?page=xnetvn-agent-hub-wp-activity` | Read-only audit filters `owner`, `key_id` (`[a-z2-7]{12}`), `ability`, `outcome` (`all`/`success`/`denied`/`error`/`invalid_input`/`rate_limited`/`auth_failed`), `after`/`before` (`datetime-local`, converted to UTC); table (`Time`, `User`, `Ability`, `Outcome`, `Transport`); pagination. Filters only narrow the listing. |
| Tools | `admin.php?page=xnetvn-agent-hub-wp-tools` | Catalog-only lookup: `search` (`Name, label or description`), `toolset`, `tier` (`all`/`read`/`write`/`destructive`/`admin`); live count and `No matching tools` empty state. No run action and no MCP call from this screen. |
| Settings | `admin.php?page=xnetvn-agent-hub-wp-settings` | Native Settings API form (`options.php`) with groups `Access`, `Network`, `Limits`, `Audit and data`, `Notifications`, `Media`; every stored field keeps its name, type and bounds; group help plus inline impact warnings; one `Save settings` submit, never AJAX. |
| Help | `admin.php?page=xnetvn-agent-hub-wp-help` | Four guided steps (create key, copy once, configure endpoint with the `Authorization: Bearer` header and a token placeholder `<YOUR_TOKEN>`, check connection); copyable endpoint; `MCP_ENDPOINT=https://example.invalid/wp-json/xnetvn-agent-hub-wp/mcp` sample with `REDACTED` for unknown values; `401`/`403`/`429`/`503` troubleshooting details; emergency revocation link to Keys. |

---

## 5. Profile and User-Edit

Rendered by `src/Admin/Pages.php` (`profile()`) on `profile.php` and `user-edit.php` without nesting a form inside the WordPress profile form. The section appears only when the profile owner can use the plugin and the viewer is the owner or a manager; other viewers see nothing. Managers listing all owners use the Keys view with `data-all="1"`; profiles lock the owner.

- Heading `Agent Hub API Keys` with the same Keys table, states and pagination as §4.
- Own profile adds a notification preference checkbox (`Email me about key creation, administrator revocation, expiry and unusual use.`) saved only under its explicit profile nonce.
- Key creation fields appear only for the current owner with use permission.

---

## 6. Request States (Busy, Error, Empty, Unknown)

Every read section (`overview`, `activity`, `tools`, Keys listing) renders the same three regions from `src/Admin/Pages.php` (`read_state()`) and drives them from `assets/admin.js` without ever writing secrets into the DOM except the one-time input in §7.

- Busy: container sets `aria-busy="true"` and shows `Loading…` (`data-loading`); the flag is cleared in `finally` so a slow read announces loading without wiping current rows.
- Error: `data-error` with message text plus one `Try again` button (`data-retry`) that sends exactly one read GET; mutations are never retried automatically. Session expiry (`401`/`403`) guides reload and login without printing raw errors.
- Empty: `data-empty` (`No keys found.`, `No activity found.`, `No tools available.`, `No status data available.`).
- Unknown result: a lost mutation response announces `The key change finished with an uncertain result. The list will reload; check it before creating a replacement key.` Missing or unreadable facts read `Unavailable`; revoked or unknown values read as `REDACTED`. A failed refresh keeps the displayed secret; only an explicit close clears it.

---

## 7. Secrets and Passwords (One-Time Display)

- Creation returns the full token once in a native `dialog` (`data-secret-dialog`): title `Save your new key now`, help `This key is shown once. Closing this window clears it. Copy it now and store it securely.`, readonly secret input (`data-secret`), `Copy key` and `I have saved the key` buttons.
- The readonly input is the only secret holder; copy helpers refuse any input marked `data-secret` outside that dialog and announce through the live region (`role="status"`, `aria-live="polite"`).
- Closing (confirm button, `Escape`, or dialog close) clears the value from the DOM; assertions use booleans or lengths so traces never capture credential values.
- High-risk tiers require the current password: the create form shows `Current password` only for `destructive`/`admin`, and the confirm dialog (`data-action-dialog`) shows its password group for the same tiers. Tier help reads `Choose the lowest tier needed. Your current WordPress permissions still apply. Destructive and Admin keys require your current password.`

---

## 8. Keyboard, Reflow and RTL

- Keyboard: all controls from §4–§7 are reachable and operable by keyboard; troubleshooting `details` elements open by keyboard; invalid Settings fields keep focus, an enabled Save button and an assertive announcement (`Settings were not sent. Check the highlighted field.`).
- Focus: visible ring `outline: 2px solid #2271b1` (`:focus-visible`); secret and action dialogs trap focus while open and restore focus to the invoking button on close; tables keep keyboard focus across reflow checks.
- Reflow: UI checks viewports `390px`, `768px`, `1440px` and reflow at `320px` with no page-level horizontal overflow (`document.documentElement.scrollWidth - clientWidth <= 1`); long names, Unicode and endpoint values wrap (`overflow-wrap: anywhere`); tables scroll inside `.xnetvn-table-wrap` (`tabindex="0"`, `role="region"`).
- RTL: the `right-to-left` layout check keeps content inside the viewport; no separate RTL stylesheet is required because layout uses logical flow and scoped `.xnetvn-` selectors.
- Motion: `prefers-reduced-motion: reduce` disables non-essential motion.

---

## 9. Target Versus Observed (Acceptance Record)

Do not read a green automated check as a release, hosted-CI, real-client, host or production claim. P13 comprehensive acceptance (three browsers, two single-sites plus multisite) has no evidence in this revision and stays pending.

| Claim | Target | Observed in this revision |
|---|---|---|
| WCAG 2.2 AA conformance | Conform to WCAG 2.2 Level AA per §2 | Not established. Axe is a signal only; manual and screen-reader verification pending. |
| Axe automated checks | 0 axe violations on changed UI | Per-run result only; record the run revision, site, browser and role. No standing pass is claimed here. |
| Six-view flows, fields and errors (§4–§7) | Match `src/Admin/Pages.php` and `assets/admin.js` | Matches revision `45d9e36` as documented above; runtime proof belongs to the run record in [Testing Guide](../TESTING.md). |
| Keyboard, reflow (`390`/`768`/`1440`, `320`), RTL (§8) | No overflow, focus kept, RTL inside viewport | Per-run result only; see test record. |
| P13 acceptance (three browsers, two sites, multisite) | Full matrix green | Pending: no evidence in this revision. |
| Manual, screen-reader, real-client, hosted CI, host, production | Separately evidenced | Unverified in this revision; never inferred from `git diff --check` or a focused run. |

Record each run with time, revision, site (`wp69`/`wp71`), browser, role and command; see [Testing Guide](../TESTING.md).
