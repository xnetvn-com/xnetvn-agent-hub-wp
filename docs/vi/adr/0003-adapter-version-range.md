# ADR-0003: Phụ thuộc adapter và khoảng phiên bản tương thích

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Adapter 0.7.0 đưa vào schema theo đúng từng phiên bản giao thức và các thay đổi không tương thích ở phiên bản minor.

## Quyết định

Yêu cầu mcp-adapter dưới dạng plugin và giới hạn MCP ở >=0.7.0 <0.8.0 sau khi hoàn tất khảo sát tương thích. M0 chưa phụ thuộc adapter; phụ thuộc sản phẩm và facade được đưa vào tại M1-S08.

## Hệ quả

Plugin phải tiếp tục tải được và hiển thị chẩn đoán khi adapter không tương thích. Bản 0.7.0 đã cài chấp nhận một ability riêng tư trên máy chủ tùy chỉnh; Inspector 2.9 liệt kê và gọi ability này qua STDIO, đồng thời liệt kê trong chế độ giao thức hiện đại. Chữ ký hook và phiên bản runtime được ghi trong adapter-notes.md. Phản hồi 401 chỉ có realm kích hoạt cơ chế chuyển sang OAuth của Inspector; cần ghi rõ hành vi này cho client chỉ dùng khóa. HTTP với khóa sản phẩm, các client khác và production vẫn là những cửa nghiệm thu riêng chưa được xác minh.

## Phương án đã cân nhắc

Loại bỏ adapter đóng gói kèm và khoảng phiên bản không giới hạn.

## Xác minh

Các phép khảo sát mã đã cài/runtime tại M1-S00 xác minh hợp đồng phụ thuộc. N-32 phải bao phủ các phiên bản không được hỗ trợ trong kiểm thử sản phẩm. Chạy lại hợp đồng facade khi cập nhật phụ thuộc; chấp nhận khoảng phiên bản không có nghĩa tích hợp sản phẩm đã tồn tại hoặc mọi bản phát hành trong khoảng đó đều đã được kiểm thử.

## Nguồn

- https://wordpress.org/plugins/mcp-adapter/ (đã xác minh 2026-10-04).
