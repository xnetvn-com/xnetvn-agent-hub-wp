---
name: Detailed bug report
about: Report a reproducible defect with full environment and evidence details. Incomplete reports may be rejected.
title: "[Bug] "
labels: bug
assignees: ""
---

<!-- Security reports: send them privately to security@xnetvn.net as described in SECURITY.md.
     Never include API keys, passwords, bearer tokens or authorization headers in this report. -->

> **Fill in every section below so the engineering team can identify and resolve the defect quickly. Reports missing required information will be rejected or returned for completion.**

---

## 1. Overview

- **Bug title (required):**
  _(Clear, concise and reflecting the actual nature of the defect)_

- **Severity (required):**
  - [ ] Blocker (Service down, major data loss)
  - [ ] Critical (Core functionality affected)
  - [ ] Major (Secondary functionality affected)
  - [ ] Minor (Small defect with a workaround)
  - [ ] Trivial (UI or spelling issue)

- **Priority (required):**
  - [ ] P1 (Fix immediately)
  - [ ] P2 (Important)
  - [ ] P3 (Normal)
  - [ ] P4 (Low)

- **Related module / feature:**
  _(e.g. MCP gateway, API key management, audit log, REST management, admin UI, ...)_

---

## 2. Environment

- **Operating system (required):**
  _(Windows, Linux, macOS, Android, iOS, ... + version)_

- **Browser / application version (required):**
  _(Chrome/Firefox/Safari/Edge, plugin build, commit SHA, ...)_

- **Environment:**
  - [ ] Development
  - [ ] Staging
  - [ ] Production
  - [ ] Other: ___________________

- **Affected account / user:**
  _(username, role, capabilities, ... if relevant)_

---

## 3. Bug description

- **Short description (required):**
  _(Observed symptom and incorrect behavior)_

- **Expected result (required):**
  _(How should the system behave?)_

- **Actual result (required):**
  _(How is the system currently behaving?)_

---

## 4. Steps to reproduce

1. Step 1:
2. Step 2:
3. ...

- **Occurrence frequency:**
  - [ ] 100% (Always occurs)
  - [ ] Intermittent (state the approximate ratio)

---

## 5. Evidence

- **Screenshot (required):**
  _(Attach an image or paste a link)_

- **Reproduction video (if available):**
  _(Attach or link)_

- **Affected URL:**
  _(The URL where the defect occurs)_

- **Log / error trace:**
  _(Paste the error log or stack trace with all sensitive information removed)_

---

## 6. Additional technical information

- **Affected commit / release:**
  _(SHA / git tag / branch, release note, related pull request)_

- **Special configuration / settings:**
  _(Feature flags, site settings, ... if relevant)_

- **Related third-party service / API:**
  _(Service name, status, response code, ...)_

---

## 7. Reporter

- **Name / username:**
- **Email / Slack:**
- **Team / department:**

---

## 8. Checklist

- [ ] I have completed every required section of this report.
- [ ] I have checked that this bug is not a duplicate.
- [ ] I have attached logs / screenshots / video evidence.
- [ ] I have retested on another environment (if available).
- [ ] I have notified the relevant stakeholders (if the bug is severe).

---

> **Note:** The more detailed the report, the faster the defect can be resolved completely. Reports missing fields marked `(required)` will be skipped until the missing information is provided.