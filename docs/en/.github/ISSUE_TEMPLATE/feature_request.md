# 💡 Feature Request (New Feature Request)

> **This feature request template provides the detailed, essential information an AI Coding Assistant and the development team need to understand and implement the requested feature professionally and thoroughly.**

---

## 1. General Information

- **Feature name (Required):**  
  _(A short, clear name describing the new feature to add)_

- **Related module / Toolset (Required):**
  - [ ] System Pack
  - [ ] Content Pack
  - [ ] Taxonomy Pack
  - [ ] Blocks Pack
  - [ ] Media Pack
  - [ ] Comments Pack
  - [ ] Appearance Pack
  - [ ] Settings Pack
  - [ ] API Key Management / Authentication
  - [ ] Audit Logging / GDPR
  - [ ] Other: ___________________

- **Priority (Required):**
  - [ ] High (Required for the next release)
  - [ ] Medium (Important but flexible)
  - [ ] Low (Experience improvement, future idea)

---

## 2. Description & Benefits

- **Detailed description (Required):**  
  _(Describe the feature in detail: purpose, behavior, and actions performed by the user or AI Agent)_

- **Benefits (Required):**  
  _(Explain why this feature matters and what value it brings to users, the website, or the AI Agent ecosystem)_

---

## 3. Use Cases

- **Primary users:**  
  _(AI Agents through MCP / WordPress administrators through the Admin UI / Third-party developers)_

- **Practical scenario 1:**  
  - *Step 1:* The AI Agent sends an MCP request with parameters...
  - *Step 2:* The plugin verifies permissions and calls the internal handler...
  - *Step 3:* The result is returned in the expected JSON Schema format...

- **Practical scenario 2:**  
  _(Additional scenarios or edge cases)_

---

## 4. Technical Requirements

- **Expected Risk Tier:**
  - [ ] `read` (Read only)
  - [ ] `write` (Create or update)
  - [ ] `destructive` (Delete or cancel)
  - [ ] `admin` (Change system configuration)

- **Minimum WordPress Capability Required:**  
  _(Example: `read`, `edit_posts`, `upload_files`, `manage_options`)_

- **Expected Input Schema:**  
  ```json
  {
    "type": "object",
    "properties": {
      "param1": { "type": "string" }
    },
    "required": ["param1"]
  }
  ```

- **Expected Output Schema:**  
  ```json
  {
    "type": "object",
    "properties": {
      "success": { "type": "boolean" }
    },
    "required": ["success"]
  }
  ```

- **Related technologies, Core API functions, or libraries:**  
  _(Example: rest_do_request, wp_insert_post, wpdb, etc.)_

---

## 5. Security Risks & Safety Boundaries

- [ ] Does this feature modify the database?
- [ ] Does this feature expose sensitive user information?
- [ ] Does the returned data comply with the untrusted data rules?
- [ ] Could this feature be abused for DoS or Prompt Injection attacks?

---

## 6. References & Notes

- **References:**  
  _(Links to WordPress API documentation, the MCP Specification, or similar examples)_

- **Additional notes:**  
  _(Other information the development team should consider)_
