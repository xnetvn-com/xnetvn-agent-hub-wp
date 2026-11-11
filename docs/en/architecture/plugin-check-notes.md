# Plugin Check acceptance

Plugin Check 2.1.0 was installed from WordPress.org on 2026-10-04. Run checks against the installed production ZIP rather than development scratch containing downloaded upstream distributions. Source quality is covered independently by WPCS, PHPCompatibility, PHPStan and tests.

Initial package findings: languages/.gitkeep is a forbidden hidden file; the community Code of Conduct is unnecessary release metadata; Tested up to 6.9 is below the current stable series. The release exclusion list now removes .gitkeep and CODE_OF_CONDUCT.md. Real integration tests passed on WordPress 6.9 and 7.1.2 before setting Tested up to 7.1 (the readme header uses the major series, without a patch component).

Naming warnings remain visible and are not suppressed: the recorded runs used the earlier product title and counted the `wordpress` term. The frozen name xNetVN Agent Hub WP removes that term, but `wp` in the name, readme and slug is still an expected warning that the Task 6 rerun must reconfirm; it has not been observed under the new name. The checker's source explicitly documents wp as allowed but warned. Guideline 17 prohibits another project's trademark as a sole or initial slug term; the fixed slug starts with xnetvn. This evidence does not establish trademark permission or WordPress.org approval. Keep the owner-approved name and slug; directory review remains an external release gate. Revisit if the directory reviewers require a change.

The bootstrap does not call load_plugin_textdomain. Interface strings are translated only at their display hook and use the fixed text domain; installed-package checking is the acceptance evidence for U8. No manual text-domain loader is introduced for a WordPress.org-targeted plugin.

The2026-10-05 packaged checkpoint was activated with production-only vendor files in a separate WordPress7.1.2 installation. Plugin Check2.1.0 reported0 errors and21 visible warnings:12 input/unslash findings,6 uninstall database findings and3 naming findings. Exact bearer/socket/origin inputs are validated without general text rewriting; metadata is bounded/redacted before persistence. Uninstall uses prepared owned-table/options queries and WordPress deletion APIs rather than caching a removal snapshot. These findings remain visible for reviewer assessment; the static style/security checks and independent source review are separate evidence. This report is not WordPress.org approval or full M1 acceptance.

## Recorded 2026-11-04 warnings

The 2026-11-04 packaged run used Plugin Check 2.1.0 on the release ZIP built by `composer build` and reported 0 errors and 23 warnings. That run predates the display-name rename, so its naming warnings describe the earlier title. Every class is recorded below; all 23 remain visible in the report.

| Finding | Count | Disposition |
|---|---|---|
| `WordPress.Security.ValidatedSanitizedInput` unslash/sanitize | 12 | Exact bearer, socket and Origin inputs are validated without general text rewriting; `HTTP_USER_AGENT` and audit IP/UA values are bounded before use. Change only where a behavior test proves the change is safe; otherwise keep the warning visible with this evidence. |
| `WordPress.DB.*` direct queries in `uninstall.php` | 6 | Confined to uninstall; plugin-owned tables, options and user meta are removed with prepared queries and WordPress deletion APIs. Keep visible. |
| `WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude` | 2 | False positives: `src/Abilities/Packs/Taxonomy/TaxonomySchemas.php:161` and `src/Abilities/Packs/Users/UserSchemas.php:264` are schema property definitions, not `get_posts()` calls. Keep visible with this line evidence. |
| `trademarked_term` | 3 | The recorded three warnings come from the earlier title, which contained the `wordpress` term. After the rename, the expected warning is `wp` in the name, readme and slug; the Task 6 rerun must reconfirm it, because that warning has not been observed under the frozen name yet. `wp` is allowed but warned; record it and do not suppress it. The slug remains `xnetvn-agent-hub-wp`, and WordPress.org review may still raise the term. |

The package runner installs Plugin Check from WordPress.org when the isolated package site does not carry it, then requires version 2.1.0 and refuses any other version until that version is recorded and reviewed. Reproduce the check with:

```bash
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" sync
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec toolbox -- bash -lc 'cd /work && composer build && bash infra/remote/check-package.sh /work/dist/xnetvn-agent-hub-wp-1.0.0.zip'
```

This record is not WordPress.org approval.

Official sources, verified 2026-10-04:
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- https://wordpress.org/plugins/plugin-check/
- https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
