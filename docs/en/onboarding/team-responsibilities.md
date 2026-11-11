# Team Responsibilities

> **Organizational structure, roles, and the RACI responsibility matrix for xNetVN Agent Hub WP.**

---

## 1. Specialist Team Structure

```
┌────────────────────────────────────────────────────────┐
│                Project Management Board                │
│             (Lead Architect & Project Leads)           │
└───────────────────────────┬────────────────────────────┘
                            │
       ┌────────────────────┼────────────────────┐
       ▼                    ▼                    ▼
[ Core & MCP Team ]   [ Security & DB Team ] [ QA & Operations Team ]
- Build the Kernel    - Cryptography, Pepper - Automated testing
- Manage Abilities    - Schema, Transactions - CI/CD, Packaging
- REST Controllers    - Policy permissions  - SRE, Monitoring
```

---

## 2. RACI Responsibility Matrix by Work Item

*(R - Responsible: Performs the work | A - Accountable: Responsible for approval | C - Consulted: Consulted party | I - Informed: Informed party)*

| Technical Work Item | Architecture Team | Security Team | Backend / Core Team | QA / DevOps Team |
|---|---|---|---|---|
| **Define safety boundaries & risk tiers** | A | R | C | I |
| **Develop the Kernel & Service Providers** | A | C | R | I |
| **Build new Ability Packs (Content, Taxonomy)** | C | C | R | R |
| **Cryptography, API Keys & Atomic Key Rotation** | A | R | R | C |
| **Automated Testing & Coverage Measurement** | I | C | R | A / R |
| **Set Up CI/CD & Package Release ZIPs** | I | C | C | A / R |
| **Emergency Incident Response (P0 / P1)** | A | R | R | R |
| **Maintain Vietnamese Documentation (Single Source of Truth)** | A | C | R | C |

---

## 3. Communication & Reporting Rules

- **Daily Sync:** Answer 3 questions (What did you do yesterday? What will you do today? Are there any blockers?) in the internal Slack/Discord channel.
- **Architecture Review:** A review meeting is required before adding any Ability with a `destructive` or `admin` tier.
