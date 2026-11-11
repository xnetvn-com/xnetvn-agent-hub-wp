# Frequently Asked Questions (FAQ)

> **Answers to common questions about installation, operations and features of xNetVN Agent Hub WP.**

---

### Q1: How does xNetVN Agent Hub differ from the default WordPress REST API?
**Answer:**  
The WordPress REST API is designed for ordinary web applications using Cookies or Application Passwords with permissions identical to the user's. **xNetVN Agent Hub**, meanwhile:
1. Standardizes the protocol using **Model Context Protocol (MCP)** specifically for AI Agents.
2. Provides **User-Owned API Keys** with fine-grained Scope narrowing, risk ceilings (`read`, `write`, `destructive`) and per-Toolset restrictions.
3. Completely separates authentication: The MCP route **accepts only Bearer Tokens** and rejects Cookies to eliminate CSRF risk.
4. Integrates sensitive data filtering (Output Pipeline), sliding window rate limits and audit logs without secrets.

---

### Q2: Why can I not use my login Cookie to call the MCP Server?
**Answer:**  
This is an immutable security principle. If login Cookies were allowed to call the MCP route, any malicious website visited in the user's browser could perform CSRF or DNS Rebinding attacks to send hidden commands to the AI Agent on WordPress. Therefore, the MCP route requires a Bearer Token with a separately issued secret API key.

---

### Q3: What happens to a user's API keys if their permissions are reduced or their account is deleted?
**Answer:**  
- **When permissions are reduced:** The system checks actual user permissions in real time for every request. For example, if an Editor becomes a Contributor, their API key is immediately denied when publishing posts, even if its previous scope allowed `write`.
- **When the account is deleted:** The account's API keys are automatically and permanently revoked, and all requests are denied with `401 Unauthorized`.

---

### Q4: Does the plugin expose passwords, personal information or draft posts?
**Answer:**  
- **Passwords & Secrets:** Absolutely not. The output pipeline (`OutputPipeline`) completely removes password fields, hashes, tokens and application passwords.
- **Draft / Private posts:** An AI Agent can read or view drafts only if the WordPress account owning the API key has permission to view them (`edit_others_posts` or authorship of that draft).

---

### Q5: How do I rotate API keys (Key Rotation) without service interruption?
**Answer:**  
When you perform **Rotate Key** in the administration page:
1. The system atomically generates a new API key.
2. The old key receives a default **24-hour** Grace Period before expiring completely.
3. During these 24 hours, you can update the AI Agent's configuration (for example, Claude Desktop, Cursor) with the new key without worrying about service interruption.

---

### Q6: Does the plugin support WordPress Multisite (a network of websites)?
**Answer:**  
Yes. The plugin fully supports WordPress Multisite. Each subsite in the network has completely independent key databases, audit logs, settings and Pepper. Super Administrator permissions are tightly controlled to ensure permissions do not leak between sites.
