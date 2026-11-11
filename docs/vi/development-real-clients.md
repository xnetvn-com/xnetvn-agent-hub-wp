# Cửa kiểm tra client MCP đã phát hành

Trạng thái: đã chuẩn bị triển khai; nghiệm thu runtime chưa được xác minh. Cửa này bao phủ ứng dụng CLI Inspector 2.9.0 và FastMCP Slim 4.0.11 đã phát hành. Nó không xác lập hành vi GUI desktop, nghiệm thu khả năng tiếp cận thủ công, mức sẵn sàng production hay hoàn tất toàn bộ M1.

Bộ cài toolbox chỉ dùng từ xa tạo thư mục công cụ riêng độc quyền, từ chối thư mục tổ tiên là symlink, xác minh digest wheel FastMCP và ghim phiên bản Inspector. Cài đặt và khảo sát phiên bản có giới hạn thời gian. Công cụ có sẵn cần đối soát. Inspector không có cờ phiên bản được hỗ trợ: đọc metadata gói đã cài thay vì khởi chạy ứng dụng web.

Trước khi tạo thông tin xác thực, cung cấp chứng chỉ công khai của lab qua kênh quản trị tin cậy vào thư mục tin cậy riêng cố định. Tuyệt đối không xuất private key. Tin chứng chỉ của peer chưa xác minh là không đủ. Xác minh chuỗi chứng chỉ và hostname vẫn bật. Môi trường client loại bỏ cơ chế chèn runtime kế thừa, proxy, cờ tắt TLS và ghi nhật ký khóa TLS.

Runner từ chối fixture có sẵn. Khóa, người dùng và cấu hình tạm thời nằm trong tệp máy chủ riêng. argv CLI chứa đường dẫn cấu hình, tuyệt đối không chứa token. Đầu ra client thô và dấu vết ngoại lệ không được xuất.

Mỗi ứng dụng phải liệt kê công cụ được phép, xác định chủ sở hữu khóa, thực thi thao tác đọc tổng hợp và ẩn công cụ cấp cao hơn. Sau khi thu hồi khóa đã ràng buộc, chỉ một lần gọi CLI thất bại là không đủ. Đo lường máy chủ chỉ dành cho kiểm thử phải ghi HTTP 401, lý do khóa bị thu hồi nội bộ thực tế và context yêu cầu ẩn danh cho đúng tuyến MCP, control tạm thời đã xác thực và trường hợp client trong danh sách cho phép. Thiếu bằng chứng, JSON sai định dạng và lỗi truyền tải không liên quan khiến cửa kiểm tra thất bại.

Mã đo lường bị loại khỏi ZIP phát hành và chỉ ghi bằng chứng status/boolean có giới hạn. Dọn dẹp khôi phục cài đặt, xóa người dùng/khóa/bản ghi kiểm toán và mã đo lường đã ràng buộc, sau đó xóa cấu hình client riêng. Dọn dẹp thất bại hoặc còn đường dẫn không được trả thành công.

Kiểm thử hồi quy local: `python3 -I -B tests/Tooling/test_client_safety.py` và `python3 -I -B tests/Tooling/test_client_cleanup.py`. Tiêm lỗi dọn dẹp xác minh rằng xóa thất bại không thể báo thành công và dọn dẹp thành công giữ trạng thái thất bại của client. Hành vi dọn dẹp cũ đã được tái hiện bằng đột biến nguồn: exit 0 trong khi tệp riêng vẫn còn. Chạy hành vi HTTP/tích hợp WordPress từ xa trước nghiệm thu. Giữ bằng chứng thất bại và phục hồi riêng với kết quả đạt.

Hợp đồng TLS chính thức: [context client SSL mặc định của Python](https://docs.python.org/3/library/ssl.html#ssl.create_default_context) và [chứng chỉ CA bổ sung của Node](https://nodejs.org/api/cli.html#node_extra_ca_certsfile), đã kiểm tra ngày 2026-10-06.
