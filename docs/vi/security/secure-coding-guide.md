# Cẩm Nang Lập Trình An Toàn (Secure Coding Guide)

> **Hướng dẫn thực hành viết mã an toàn, phòng ngừa các lỗ hổng phổ biến trong hệ sinh thái WordPress và máy chủ MCP.**

---

## 1. Phòng Ngừa Tấn Công Tiêm Lệnh SQL (SQL Injection Prevention)

### ❌ KHÔNG BAO GIỜ:
Nối chuỗi trực tiếp vào câu lệnh `$wpdb->query` hoặc `$wpdb->get_results`:
```php
// NGUY HIỂM: Lỗ hổng SQL Injection nghiêm trọng
$sql = "SELECT * FROM {$wpdb->prefix}xnetvn_agent_hub_wp_keys WHERE key_id = '" . $key_id . "'";
$wpdb->query( $sql );
```

### ✅ LUÔN LUÔN:
Sử dụng câu lệnh Prepared Statements với `$wpdb->prepare` và đúng bộ chỉ định kiểu (`%s`, `%d`, `%f`):
```php
// AN TOÀN: Tham số hóa đầy đủ
$sql = $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}xnetvn_agent_hub_wp_keys WHERE key_id = %s LIMIT 1",
    $key_id
);
$result = $wpdb->get_row( $sql, ARRAY_A );
```

---

## 2. Phòng Ngừa Tấn Công XSS (Cross-Site Scripting Prevention)

### 2.1 Làm Sạch Dữ Liệu Đầu Vào (Sanitization)
Khi nhận dữ liệu từ request hoặc người dùng:
```php
$name = sanitize_text_field( $raw_name );
$toolset = sanitize_key( $raw_toolset );
$cidr = filter_var( $raw_ip, FILTER_VALIDATE_IP );
```

### 2.2 Thoát Ký Tự Đầu Ra (Late Escaping)
Thoát ký tự tại đúng thời điểm hiển thị dữ liệu ra HTML hoặc thuộc tính HTML:
```php
// Hiển thị nội dung văn bản thông thường
echo esc_html( $key['name'] );

// Hiển thị bên trong thuộc tính HTML
echo '<input type="text" value="' . esc_attr( $key['key_id'] ) . '" />';

// Hiển thị liên kết URL
echo '<a href="' . esc_url( $redirect_url ) . '">';
```

---

## 3. Phòng Ngừa Tấn Công CSRF (Cross-Site Request Forgery)

- **Tại các endpoint REST quản trị:** Luôn yêu cầu `check_ajax_referer()` hoặc kiểm tra tiêu đề `X-WP-Nonce` thông qua bộ lọc quyền hạn `permission_callback` của `register_rest_route`:
```php
'permission_callback' => function( \WP_REST_Request $request ): bool {
    return is_user_logged_in() && current_user_can( 'edit_posts' );
}
```
- **Tại tuyến MCP:** Không dùng cookie hay nonce; chỉ xác thực Bearer Token duy nhất.

---

## 4. Xử Lý Mật Mã Học & Token An Toàn

- **Sinh ngẫu nhiên bảo mật:** Luôn dùng `random_bytes()`, tuyệt đối không dùng `rand()`, `mt_rand()`, `uniqid()` cho khóa bảo mật.
- **So sánh an toàn thời gian:** Luôn dùng `hash_equals($known_hash, $user_hash)` để so sánh mã băm, tránh rò rỉ thời gian.
- **Không ghi log thông tin bí mật:** Trước khi ghi log bất kỳ mảng hoặc đối tượng nào, phải bóc tách hoặc xóa bỏ các trường `secret`, `password`, `token`, `pepper`.
