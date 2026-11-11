# Installed MCP Adapter compatibility evidence

Verified against the separately installed official MCP Adapter 0.7.0 on WordPress 6.9, 2026-10-04. A temporary private ability/custom server was used only for the spike and removed afterward. Its HTTP transport denied all requests; no prototype security decision is part of the product.

| Question | Current evidence / result | Acceptance state |
|---|---|---|
| U1: private abilities | A registered ability omitting public/show_in_rest is accepted by explicit custom-server tool registration; custom server count=1; execution returned the fixed ok result; default-server exposure=false | Runtime verified |
| U2: runtime version | WP_MCP_VERSION and Core McpAdapter::VERSION both returned 0.7.0 | Runtime verified |
| U3: newer core hooks | Official 6.9 and 7.0.6 class source lacks permission-result/pre-execute/invoked hooks; installed WP7.1.2 source marks and implements them since7.1.0 | Versioned source verified |
| U4: realm-only 401 discovery | Inspector2.9.0 with explicit Bearer header received the realm-only401 and attempted interactive OAuth; non-TTY execution returned auth_required. Static header configuration does not disable its401 fallback. Document stored-auth-only for unattended negative probes and verify valid-key success separately | Runtime client behavior verified; valid product-key gate pending |
| U5: routes / cached cookie | Real HTTP pretty/query-route probes passed on a root installation and an independently installed WordPress6.9 subdirectory, including actual Plain permalink configuration. Valid logged-in cookie cached at plugins_loaded recorded user1 while REST_REQUEST=false, then REST auth saw user1 without another determine_current_user call. Late reauthentication is required | Runtime verified; product authentication tests still required |
| U6: listing budget | Synthetic 20/60/120-tool payloads measured 8,931/26,771/53,531 bytes; bytes/4 gives only a rough estimate, not a tokenizer measurement. Proposed direct-list target ~60 tools; actual pack payload/performance still to measure | Synthetic measurement verified; real-pack gate pending |
| U7: real client headers | Inspector2.9 CLI header syntax verified on realm-only401; its STDIO client listed and called the private tool successfully. CLI subprocess arguments precede -- and Inspector options follow it. Other client snippets require independent current-source/runtime verification | Inspector verified; other clients pending |
| U10: auth-error response headers | Actual HTTP 401 returned from REST authentication carried WWW-Authenticate Bearer realm and no-store after rest_post_dispatch, for pretty/plain and cached-cookie cases | Runtime verified |

Core WordPress injects show_in_rest=false into effective metadata. L-01 therefore means the plugin's registration arguments never set public/show_in_rest, and the effective exposure remains false; a test requiring the property to be absent from core's normalized metadata would test the wrong boundary.

## Installed API signatures
- create_server(server_id, server_route_namespace, server_route, server_name, server_description, server_version, mcp_transports, error_handler, observability_handler=null, tools=[], resources=[], prompts=[], transport_permission_callback=null) returns adapter or WP_Error. Explicit tools accept ability names or McpTool objects.
- mcp_adapter_init(adapter) fires once. In WP-CLI it initializes at init priority20; HTTP initializes at rest_api_init priority15.
- mcp_adapter_tools_list(tools, server, schema): tools are schema Tool records, with getName(), not plain arrays.
- mcp_adapter_pre_tool_call(args, tool_name, mcp_tool, server): returning WP_Error short-circuits a call.
- mcp_adapter_tool_call_result(result, args, tool_name, mcp_tool, server): result is raw ability output; adapter assembles text JSON and structuredContent afterward. Do not return an already assembled wire envelope as the raw ability result.
- Transport permission callback errors are not an authentication challenge contract. Keep 401/header behavior in the route-scoped WordPress REST authentication layer.

These signatures are read from the installed release. The release ZIP does not contain the repository documentation directory; versioned official source supplements installed-code inspection. Only the product ServerGateway may use adapter classes.

## Pending decisions

Content/Media annotation checkpoint, 2026-10-08: real MCP tools/list through
adapter0.7.0 on the owned WordPress6.9 and7.1.2 HTTP fixtures returned31 tools.
The HTTPS matrix asserted openWorldHint for all26 new tools: true only for URL
upload, false for the remaining25. U-ANN-1 is verified on these installed
versions; no adapter-specific workaround outside ServerGateway was needed.
See tests/E2E/content-media-matrix.php and infra/remote/run-content-media.sh.
ADR-0003 is Accepted based on installed0.7.0 private-registration, versioned source, HTTP route/header probes and real Inspector STDIO legacy/modern operation. Product-key HTTP and additional clients remain separate M1 acceptance gates. Existing adapters outside the verified range must leave the plugin loadable while closing MCP boot. Temporary root/subdirectory mu-plugins were removed; the disposable subdirectory configuration was retired from the served web root.

Official sources:
- https://wordpress.org/plugins/mcp-adapter/
- https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip
- https://github.com/WordPress/WordPress/blob/7.0.6/wp-includes/abilities-api/class-wp-ability.php
- https://developer.wordpress.org/apis/abilities-api/
