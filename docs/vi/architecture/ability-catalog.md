# Danh mục ability và phạm vi REST lõi

Sáu pack ability đã được triển khai: system (5), content (19), taxonomy (9), media (7), comments (10) và users (12). Các mục ghi `Planned` cần hợp đồng cột mốc và kiểm thử quyền đối tượng; triển khai và nghiệm thu runtime được theo dõi riêng. Điểm vào execute-ability hệ thống kiểm tra lại toàn bộ chính sách của đích; cấp read của điểm vào không cấp quyền write/destructive/admin trên đích.

| Ability | Cấp độ | Toolset | Capability hiển thị đề xuất | Nền tảng | Cột mốc | Trạng thái |
|---|---|---|---|---|---|---|
| xnetvn/get-session-info | read | system | read | Trực tiếp | M1 | Đã triển khai |
| xnetvn/get-site-info | read | system | read | Trực tiếp | M1 | Đã triển khai |
| xnetvn/discover-abilities | read | system | read | Trực tiếp | M1 | Đã triển khai |
| xnetvn/get-ability-info | read | system | read | Trực tiếp | M1 | Đã triển khai |
| xnetvn/execute-ability | điểm vào read; thực thi rủi ro của đích | system | read | Trực tiếp | M1 | Đã triển khai |
| xnetvn/list-post-types | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-post-statuses | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-posts | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-post | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/create-post | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/update-post | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/trash-post | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/restore-post | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/delete-post | destructive | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/duplicate-post | write | content | edit_posts | Trực tiếp | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/bulk-update-posts | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-revisions | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-revision | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/restore-revision | write | content | edit_posts | Trực tiếp | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/set-featured-media | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/stick-post | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-post-meta | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/update-post-meta | write | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/search-content | read | content | edit_posts | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-taxonomies | read | taxonomy | read | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-terms | read | taxonomy | read | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-term | read | taxonomy | read | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/create-term | write | taxonomy | read | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/update-term | write | taxonomy | read | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/assign-post-terms | write | taxonomy | read | Trực tiếp | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/bulk-create-terms | write | taxonomy | read | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/bulk-assign-post-terms | write | taxonomy | read | Trực tiếp | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/delete-term | destructive | taxonomy | read | REST | M2 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-block-types | read | blocks | read | REST | M2 | Đã lên kế hoạch |
| xnetvn/list-block-patterns | read | blocks | read | REST | M2 | Đã lên kế hoạch |
| xnetvn/get-block-pattern | read | blocks | read | REST | M2 | Đã lên kế hoạch |
| xnetvn/parse-blocks | read | blocks | read | Trực tiếp | M2 | Đã lên kế hoạch |
| xnetvn/validate-blocks | read | blocks | read | Trực tiếp | M2 | Đã lên kế hoạch |
| xnetvn/render-blocks | read | blocks | read | Trực tiếp | M2 | Đã lên kế hoạch |
| xnetvn/list-media | read | media | upload_files | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-media | read | media | upload_files | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/update-media | write | media | upload_files | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/upload-media-from-url | write | media | upload_files | Direct + REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/upload-media-from-data | write | media | upload_files | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-media-upload-limits | read | media | upload_files | Trực tiếp | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/delete-media | destructive | media | upload_files | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-comments | read | comments | moderate_comments | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-comment | read | comments | moderate_comments | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/get-comment-counts | read | comments | moderate_comments | Trực tiếp | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/create-comment | write | comments | moderate_comments | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/reply-comment | write | comments | moderate_comments | REST | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/update-comment | write | comments | moderate_comments | Trực tiếp | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/moderate-comment | write | comments | moderate_comments | Trực tiếp | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/bulk-moderate-comments | write | comments | moderate_comments | Trực tiếp | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/delete-comment | destructive | comments | moderate_comments | Trực tiếp | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/purge-comments | destructive | comments | moderate_comments | Trực tiếp | M3 | Đã hiện thực; chờ nghiệm thu |
| xnetvn/list-themes | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/get-active-theme | read | appearance | edit_theme_options | Trực tiếp | M4 | Đã lên kế hoạch |
| xnetvn/list-menus | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/get-menu | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/create-menu | write | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/update-menu | write | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/delete-menu | destructive | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/list-menu-locations | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/assign-menu-location | write | appearance | edit_theme_options | Trực tiếp | M4 | Đã lên kế hoạch |
| xnetvn/list-sidebars | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/list-widgets | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/update-widget | write | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/list-templates | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/get-template | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/update-template | write | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/get-global-styles | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/update-global-styles | write | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/list-patterns | read | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/create-pattern | write | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/delete-pattern | destructive | appearance | edit_theme_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/get-custom-css | read | appearance | edit_theme_options | Trực tiếp | M4 | Đã lên kế hoạch |
| xnetvn/update-custom-css | write | appearance | edit_theme_options | Trực tiếp | M4 | Đã lên kế hoạch |
| xnetvn/get-settings | read | settings | manage_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/update-settings | write | settings | manage_options | REST | M4 | Đã lên kế hoạch |
| xnetvn/flush-rewrite-rules | write | settings | manage_options | Trực tiếp | M4 | Đã lên kế hoạch |
| xnetvn/list-users | read | users | list_users | REST | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/get-user | read | users | list_users | REST | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/list-roles | read | users | list_users | Trực tiếp | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/get-user-capabilities | read | users | list_users | Trực tiếp | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/get-my-profile | read | users | read | REST | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/update-my-profile | write | users | read | REST | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/create-user | admin | users | list_users | REST | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/update-user | admin | users | list_users | REST | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/set-user-role | admin | users | list_users | Trực tiếp | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/set-user-status | admin | users | list_users | Trực tiếp | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/terminate-user-sessions | admin | users | list_users | Trực tiếp | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/delete-user | admin | users | list_users | REST | M5 | Đã lên kế hoạch (ngoài phạm vi _04, D4) |
| xnetvn/send-password-reset | admin | users | list_users | Trực tiếp | M5 | Đã làm; chờ nghiệm thu |
| xnetvn/list-plugins | read | plugins | activate_plugins | REST | M5 | Đã lên kế hoạch |
| xnetvn/get-plugin | read | plugins | activate_plugins | REST | M5 | Đã lên kế hoạch |
| xnetvn/search-plugin-directory | read | plugins | activate_plugins | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/activate-plugin | admin | plugins | activate_plugins | REST | M5 | Đã lên kế hoạch |
| xnetvn/deactivate-plugin | admin | plugins | activate_plugins | REST | M5 | Đã lên kế hoạch |
| xnetvn/install-plugin | admin | plugins | activate_plugins | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/update-plugin | admin | plugins | activate_plugins | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/delete-plugin | admin | plugins | activate_plugins | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/get-site-health | read | maintenance | manage_options | REST | M5 | Đã lên kế hoạch |
| xnetvn/list-cron-events | read | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/check-updates | read | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/get-debug-log-tail | admin | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/export-content | admin | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/flush-cache | write | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/run-cron-event | write | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/update-core | admin | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/update-theme | admin | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/update-translations | admin | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/list-privacy-requests | admin | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |
| xnetvn/export-personal-data | admin | maintenance | manage_options | Trực tiếp | M5 | Đã lên kế hoạch |

Ability network và tích hợp M7 tùy chọn cần đặc tả đầy đủ riêng; danh mục này không tự đặt ra hợp đồng cho các công cụ đó.

## Danh mục controller WordPress 6.9

Danh mục được đọc từ bản phân phối WordPress 6.9 chính thức ngày 2026-10-04. Controller cơ sở là lớp trừu tượng và bị loại. Đã ghi nhận mọi tệp endpoint cụ thể; sự tồn tại không có nghĩa một site nhất định cung cấp các tuyến đó.

| Controller | Pack / quyết định |
|---|---|
| abilities-v1-categories | Loại trừ: máy chủ MCP riêng tư là bề mặt duy nhất dành cho agent |
| abilities-v1-list | Loại trừ: máy chủ MCP riêng tư là bề mặt duy nhất dành cho agent |
| abilities-v1-run | Loại trừ: máy chủ MCP riêng tư là bề mặt duy nhất dành cho agent |
| application-passwords | Loại trừ vĩnh viễn: quản trị bí mật/thông tin xác thực |
| attachments | media (M3) |
| autosaves | Hoãn đến đặc tả M4: công cụ hỗ trợ revision/autosave của trình biên tập |
| block-directory | Loại trừ: công cụ hỗ trợ trình biên tập, bề mặt tải từ xa hoặc export/ZIP |
| block-pattern-categories | blocks (M2) |
| block-patterns | blocks (M2) |
| block-renderer | blocks (M2) |
| block-types | blocks (M2) |
| blocks | appearance (M4) |
| comments | comments (M3) |
| edit-site-export | Loại trừ: công cụ hỗ trợ trình biên tập, bề mặt tải từ xa hoặc export/ZIP |
| font-collections | Hoãn đến đặc tả M4: vòng đời font cần phạm vi xác thực dữ liệu/quyền rõ ràng |
| font-faces | Hoãn đến đặc tả M4: vòng đời font cần phạm vi xác thực dữ liệu/quyền rõ ràng |
| font-families | Hoãn đến đặc tả M4: vòng đời font cần phạm vi xác thực dữ liệu/quyền rõ ràng |
| global-styles | appearance (M4) |
| global-styles-revisions | Hoãn đến đặc tả M4: công cụ hỗ trợ revision/autosave của trình biên tập |
| menu-items | appearance (M4) |
| menu-locations | appearance (M4) |
| menus | appearance (M4) |
| navigation-fallback | Loại trừ: công cụ hỗ trợ trình biên tập, bề mặt tải từ xa hoặc export/ZIP |
| pattern-directory | Loại trừ: công cụ hỗ trợ trình biên tập, bề mặt tải từ xa hoặc export/ZIP |
| plugins | plugins (M5) |
| post-statuses | content (M2) |
| post-types | content (M2) |
| posts | content (M2) |
| revisions | content (M2) |
| search | search-content (M2) |
| settings | settings (M4) |
| sidebars | appearance (M4) |
| site-health | maintenance (M5) |
| taxonomies | taxonomy (M2) |
| template-autosaves | Hoãn đến đặc tả M4: công cụ hỗ trợ revision/autosave của trình biên tập |
| template-revisions | Hoãn đến đặc tả M4: công cụ hỗ trợ revision/autosave của trình biên tập |
| templates | appearance (M4) |
| terms | taxonomy (M2) |
| themes | appearance (M4) |
| url-details | Loại trừ: công cụ hỗ trợ trình biên tập, bề mặt tải từ xa hoặc export/ZIP |
| users | users (M5) |
| widget-types | appearance (M4) |
| widgets | appearance (M4) |

`icons`, `icon-collections` và `view-config` không có trong thư mục endpoint WordPress 6.9 này; đánh giá lại khi nâng phiên bản WordPress tối thiểu. `wp_navigation`, nhập WXR và icon/logo site không có controller chuyên biệt ở đây; quyết định đưa vào hoặc loại trừ cần đặc tả M4/M5. Application Passwords bị loại dù controller của chúng nằm ngoài thư mục này.

Nguồn: [bản phân phối WordPress 6.9 chính thức](https://wordpress.org/wordpress-6.9.zip), [sổ tay REST API](https://developer.wordpress.org/rest-api/).
