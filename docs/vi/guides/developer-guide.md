# Hướng Dẫn Dành Cho Lập Trình Viên (Developer Guide)

> **Cẩm nang kỹ thuật hướng dẫn lập trình viên mở rộng chức năng, xây dựng các Ability Packs mới và đóng góp vào xNetVN Agent Hub WP.**

---

## 1. Cấu Trúc Khởi Động & Dependency Injection

Plugin hoạt động dựa trên mô hình Kernel nạp các Service Providers theo thứ tự nghiêm ngặt tại `src/Plugin.php`:

```php
// Thứ tự nạp ServiceProvider tiêu chuẩn
MigrationProvider -> Capabilities -> RouteGate -> AuditLogger -> Retention 
-> PrivacyExporter -> PrivacyEraser -> Notifications -> Authenticator 
-> Registry -> ServerGateway -> ManagementGate -> KeysController 
-> AuditController -> SettingsController -> Pages
```

Khi muốn tích hợp hoặc lắng nghe sự kiện khởi động của plugin, lập trình viên sử dụng hook duy nhất:
```php
add_action( 'xnetvn_agent_hub_wp_loaded', function( \XNetVN\AgentHubWP\Plugin $kernel ) {
    // Thực hiện cấu hình mở rộng
} );
```

---

## 2. Cách Xây Dựng Một Gói Công Cụ Mới (Building an Ability Pack)

Để tạo một bộ công cụ mới (ví dụ: `ContentPack`), lập trình viên cần triển khai Interface `\XNetVN\AgentHubWP\Contracts\AbilityPack`:

### Bước 1: Khai báo lớp AbilityPack
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
        // Kiểm tra xem tính năng có sẵn sàng kích hoạt không
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
        // Xử lý nghiệp vụ an toàn...
        return array( 'status' => 'success' );
    }
}
```

### Bước 2: Đăng ký Gói Công Cụ vào Registry
Tại `Plugin.php` hoặc thông qua hook chuyên dụng:
```php
$registry->add_pack( new CustomPack( new DefinitionFactory() ) );
```

---

## 3. Quy Tắc Điều Phối REST Nội Bộ (Internal REST Dispatch)

- **Cấm gọi HTTP lặp vòng (No Loopback HTTP Requests):** Khi cần thực hiện các thao tác cốt lõi của WordPress (như tạo bài viết, sửa term), **tuyệt đối không dùng `wp_remote_get/post` để gọi lại URL của chính site**.
- **Luôn sử dụng `rest_do_request()`:**
  ```php
  $request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
  $request->set_body_params( $params );
  $response = rest_do_request( $request );
  
  if ( $response->is_error() ) {
      return $response->as_error();
  }
  return $response->get_data();
  ```
- Cơ chế này đảm bảo mọi kiểm tra quyền đối tượng (Object Permissions) và bộ lọc KSES của WordPress vẫn được thực thi đầy đủ mà không tốn chi phí mở kết nối mạng mới.

---

## 4. Xử Lý Lỗi & Ngoại Lệ An Toàn

- Mọi ngoại lệ bên trong Ability Handler phải được bắt và ánh xạ thành lỗi an toàn qua `ErrorMapper`.
- Tuyệt đối không để lộ:
  - Chi tiết stack trace của PHP.
  - Câu lệnh SQL thô hoặc mã lỗi của MySQL.
  - Đường dẫn tệp nội bộ của máy chủ (`/var/www/...`).
- Trả về mã lỗi thân thiện và thông điệp ngắn gọn mô tả nguyên nhân từ chối hoặc thất bại.
