---
name: Refactoring request
about: Request a code refactoring with a clear scope, motivation and acceptance criteria.
title: "[Refactor] "
labels: refactor
assignees: ""
---

> **This template contains the detailed fields needed for the AI coding assistant and developers to understand and carry out the requested refactoring.**

---

## 1. General information

- **Project:** xNetVN Agent Hub WP (`xnetvn-agent-hub-wp`)
- **Module / feature to refactor:** _(e.g. user management, payment processing, product API, MCP gateway, ...)_
- **Repository:** https://github.com/xnetvn-com/xnetvn-agent-hub-wp
- **Requester:** _(name, email/Slack, ...)_

---

## 2. Requested scope

- **Parts / functions / classes / features to refactor:**
  - [ ] _(specific function/class/file/module name)_
  - [ ] _(short description of the feature or behavior)_

- **Reason / motivation for the refactoring:**
  - [ ] Poor performance, needs improvement (Performance)
  - [ ] Defect-prone (Bug-prone / defect density)
  - [ ] Hard to test automatically (Testability)
  - [ ] Hard to maintain or extend (Maintainability / scalability)
  - [ ] Code standardization, quality improvement (Code quality)
  - [ ] Coding convention / standard violations (Coding convention)
  - [ ] Safety and security (Security / compliance)
  - [ ] Reduce defects, improve stability (Reliability)
  - [ ] Preparation for a new integration or feature
  - [ ] Technical debt (Technical debt)
  - [ ] Other: _(describe clearly)_

- **Recorded evidence / defect origin:**
  - [ ] _(link to the test case, bug report, log, screenshot or detailed reproduction steps)_

---

## 3. Detailed description

- **Current state:**
  _Briefly describe the problem, for example:_
  > e.g. The handler is too slow with large data sets; hard to read and modify; error-prone when integrating a new module ...

- **Actual impact:**
  > e.g. Slows down the release schedule, increases production risk, blocks new features, users complain about speed or stability ...

- **Expected outcome after refactoring:**
  _State the goal clearly, for example:_
  > e.g. Processing time under 1s for one million records; clear and documented code; easy to extend; improved security ...

---

## 4. Expected results and reporting

- **Expected deliverables:**
  - [ ] Clean, readable, standardized refactored code (with comments / documentation)
  - [ ] Full test coverage; all existing test cases pass and missing tests are added (unit / integration tests)
  - [ ] Code complies with the coding standard
  - [ ] Recorded defects are fixed and latent defects are removed or reduced
  - [ ] Performance optimized / security standards met
  - [ ] A before/after comparison report (performance, test coverage, defects/warnings, security)
  - [ ] Documentation describing the change, usage guidance, retest and rollout instructions
  - [ ] The pull request includes a review checklist and a detailed description of the work

---

## 5. Deadline and priority

- **Desired deadline:** _(dd/mm/yyyy)_
- **Priority:** _(High / Medium / Low)_
- **Impact if not performed:**
  _e.g. Affects the project schedule, increases defect risk, blocks future extension ..._

---

## 6. Additional information (if any)

- References, coding standards, internal guidelines:
  - _(link to the coding standard documentation)_
  - _(code review, CI/CD and testing requirements)_

---

## 7. Attachments (if any)

- Screenshots of the defect
- Log files, sample test data
- A video describing the workflow or defect

---

# Developer team guidance

- Clarify the business goal with the requester whenever the scope is ambiguous.
- Propose the optimal refactoring solution based on industry best practices.
- Review thoroughly with a pre-merge checklist.
- Ensure every change is strictly tested before merging.
- Suggest improvements beyond the requested scope when other serious issues are discovered.
- Ensure every change remains rollback-safe.
- Keep the requester updated with progress, results and defect-fix confirmation.

## Contact and feedback process

- **Requester:** _(name / email / Slack)_
- **Process:**
  - [ ] Send back the refactoring plan (timeline, resources, estimate)
  - [ ] Agree on scope, goals and schedule
  - [ ] Provide periodic progress updates (daily / weekly)

---

**For any questions, contact the requester directly for timely support.**