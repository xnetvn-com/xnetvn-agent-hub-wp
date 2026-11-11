# Developer Guide

> **A technical handbook for developers extending functionality, building new Ability Packs, and contributing to xNetVN Agent Hub WP.**

---

## 1. Bootstrap Structure and Dependency Injection

The plugin uses a Kernel model that loads Service Providers in a strict order in `src/Plugin.php`:

```php
// Standard ServiceProvider loading order
MigrationProvider -> Capabilities -> RouteGate -> AuditLogger -> Retention
-> PrivacyExporter -> PrivacyEraser -> Notifications -> Authenticator
-> Registry -> ServerGateway -> ManagementGate -> KeysController
-> AuditController -> SettingsController -> Pages
```

To integrate with or listen for the plugin's bootstrap event, developers use this single hook:

```php
add_action( 'xnetvn_agent_hub_wp_loaded', function( \XNetVN\AgentHubWP\Plugin $kernel ) {
    // Configure extensions
} );
```

---

## 2. Building an Ability Pack

To create a new toolset (for example, `ContentPack`), developers implement the `\XNetVN\AgentHubWP\Contracts\AbilityPack` interface:

### Step 1: Declare the AbilityPack Class

```php
declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Custom;

use XNetVN\AgentHubWP\Contracts\AbilityPack;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Contracts\AbilityFactory;
use XNetVN\AgentHubWP\Domain\RiskTier;

final class CustomPack implements AbilityPack {

    public function __construct( private AbilityFactory $factory ) {}

    public function is_available(): bool {
        // Check whether the feature is ready to be enabled
        return true;
    }

    public function definitions(): iterable {
        yield $this->factory->create( array(
            'name'          => 'xnetvn/custom-action',
            'label'         => __( 'Custom Action', 'xnetvn-agent-hub-wp' ),
            'description'   => 'Fixed English description explaining what the tool does.',
            'inputSchema'   => array(
                'type'                 => 'object',
                'properties'           => array(
                    'target_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                ),
                'required'             => array( 'target_id' ),
                'additionalProperties' => false,
            ),
            'outputSchema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'status' => array( 'type' => 'string' ),
                ),
                'required'   => array( 'status' ),
            ),
            'tier'          => RiskTier::Write,
            'toolset'       => 'content',
            'visibilityCap' => 'edit_posts',
            'annotations'   => array(
                'readonly'    => false,
                'destructive' => false,
                'idempotent'  => true,
            ),
            'handler'       => fn( array $args ): array => $this->handle_action( $args ),
            'example'       => array( 'status' => 'success' ),
        ) );
    }

    private function handle_action( array $args ): array {
        // Handle business logic safely...
        return array( 'status' => 'success' );
    }
}
```

### Step 2: Register the Tool Pack in the Registry

In `Plugin.php` or through a dedicated hook:

```php
$registry->add_pack( new CustomPack( new DefinitionFactory() ) );
```

---

## 3. Internal REST Dispatch Rules

- **No Loopback HTTP Requests:** For core WordPress operations (such as creating posts or editing terms), **never use `wp_remote_get/post` to call the site's own URL**.
- **Always use `rest_do_request()`:**
  ```php
  $request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
  $request->set_body_params( $params );
  $response = rest_do_request( $request );

  if ( $response->is_error() ) {
      return $response->as_error();
  }
  return $response->get_data();
  ```
- This mechanism ensures that all WordPress Object Permissions checks and KSES filters remain fully enforced without the cost of opening a new network connection.

---

## 4. Safe Error and Exception Handling

- Every exception inside an Ability Handler must be caught and mapped to a safe error through `ErrorMapper`.
- Never expose:
  - PHP stack trace details.
  - Raw SQL statements or MySQL error codes.
  - Internal server file paths (`/var/www/...`).
- Return a friendly error code and a concise message describing the reason for denial or failure.
