# Secure Coding Guide

> **Practical guidance for secure coding and preventing common vulnerabilities in the WordPress ecosystem and MCP servers.**

---

## 1. SQL Injection Prevention

### ❌ NEVER:
Concatenate strings directly into `$wpdb->query` or `$wpdb->get_results` statements:
```php
// DANGEROUS: Severe SQL Injection vulnerability
$sql = "SELECT * FROM {$wpdb->prefix}xnetvn_agent_hub_wp_keys WHERE key_id = '" . $key_id . "'";
$wpdb->query( $sql );
```

### ✅ ALWAYS:
Use Prepared Statements with `$wpdb->prepare` and the correct type specifiers (`%s`, `%d`, `%f`):
```php
// SAFE: Fully parameterized
$sql = $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}xnetvn_agent_hub_wp_keys WHERE key_id = %s LIMIT 1",
    $key_id
);
$result = $wpdb->get_row( $sql, ARRAY_A );
```

---

## 2. Cross-Site Scripting Prevention (XSS)

### 2.1 Input Sanitization (Sanitization)
When receiving data from requests or users:
```php
$name = sanitize_text_field( $raw_name );
$toolset = sanitize_key( $raw_toolset );
$cidr = filter_var( $raw_ip, FILTER_VALIDATE_IP );
```

### 2.2 Output Escaping (Late Escaping)
Escape at the precise point where data is displayed in HTML or HTML attributes:
```php
// Display normal text content
echo esc_html( $key['name'] );

// Display inside an HTML attribute
echo '<input type="text" value="' . esc_attr( $key['key_id'] ) . '" />';

// Display a URL link
echo '<a href="' . esc_url( $redirect_url ) . '">';
```

---

## 3. Cross-Site Request Forgery Prevention (CSRF)

- **At management REST endpoints:** Always require `check_ajax_referer()` or validate the `X-WP-Nonce` header through the `permission_callback` permission filter of `register_rest_route`:
```php
'permission_callback' => function( \WP_REST_Request $request ): bool {
    return is_user_logged_in() && current_user_can( 'edit_posts' );
}
```
- **At the MCP route:** Do not use cookies or nonces; authenticate only with Bearer Tokens.

---

## 4. Safe Cryptography & Token Handling

- **Secure random generation:** Always use `random_bytes()`; never use `rand()`, `mt_rand()`, `uniqid()` for security keys.
- **Timing-safe comparison:** Always use `hash_equals($known_hash, $user_hash)` to compare hashes and avoid timing leakage.
- **Do not log secrets:** Before logging any array or object, strip or remove `secret`, `password`, `token`, `pepper` fields.
