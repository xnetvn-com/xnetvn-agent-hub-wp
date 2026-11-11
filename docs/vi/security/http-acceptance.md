# Checkpoint ma trận từ chối HTTP

Đã xác minh ngày 2026-10-05 trên lab WordPress 6.9 có phạm vi với MCP Adapter chính thức 0.7.0. `composer test:http` thực hiện yêu cầu HTTPS thực tế và HTTP thường trong mạng thuộc phạm vi quản lý. 35 ID kế hoạch gốc hiện đạt với đối chứng dương; điều này không khép lại nghiệm thu đầy đủ M1, client, cache, đồng thời, độ bao phủ hoặc hiệu năng.

Yêu cầu hiện đại phản ánh method/name và phiên bản giao thức trong header HTTP, kèm metadata body khớp. Thất bại thiếu header ban đầu là lỗi fixture, không được chấp nhận là bằng chứng chính sách. Trường hợp độc lập chỉ đặt lại bucket tần suất của lab thuộc phạm vi quản lý; kịch bản giới hạn tần suất sau đó tiêu thụ toàn bộ ngân sách riêng.

| ID | Phạm vi bằng chứng |
|---|---|
| N-01–N-06 | Thông tin xác thực thiếu/sai định dạng/không biết/sai/bị thu hồi/hết hạn; challenge; body không biết/sai bằng nhau. Đầu vào sai định dạng không tra cứu bảng thông tin xác thực. Lưu trữ WordPress/kiểm toán/giới hạn tần suất vẫn hoạt động; đây không phải tuyên bố không có SQL trên toàn ứng dụng. |
| N-07–N-09 | Chủ sở hữu fixture đã xóa, mất/khôi phục vai trò, hạn chế IP ngoài phạm vi khóa. |
| N-10–N-14 | HTTP thường nội bộ thực tế, Origin không hợp lệ/null/thiếu, danh sách HTTPS đối chứng dương, xác thực thất bại 429/Retry-After. |
| N-15–N-19 | Yêu cầu quản lý/lõi/adapter mặc định đúng không nhận danh tính khóa; cookie/nonce hợp lệ không thay khóa MCP; danh tính khóa ưu tiên hơn cookie hợp lệ khác. |
| N-20–N-25 | Thiếu context bị từ chối; fixture read được phép hiển thị còn cấp cao hơn vắng mặt; envelope và status công cụ ẩn/không biết bằng nhau; từ chối trần rủi ro cao/admin; ngân sách khóa 429. |
| N-26–N-30 | Body 16 MiB+1 và độ sâu 40 bị từ chối; gọi giá trị mồi thành công không ghi giá trị vào kiểm toán; bulk 21 vượt schema fixture 20; toàn bộ danh mục registry bị cấm tuyệt đối vắng mặt. Hành vi bulk riêng pack nghiệp vụ thuộc cột mốc sau. |
| N-31–N-35 | Phiên bản giao thức không hỗ trợ; adapter không hỗ trợ chỉ trong yêu cầu vẫn giữ tải/thông báo cơ sở và loại tuyến MCP; bảo vệ Origin/HTTP thường với khóa hợp lệ; chủ thể cookie cache sớm đã quan sát vẫn cho chủ thể khóa hoặc 401 khi thiếu khóa. |

Hai lỗi được tái hiện trước sửa: biến Authorization rỗng của Apache che cơ chế dự phòng đã bật, và HTTP 400 của công cụ không biết trong giao thức hiện đại khác HTTP 200 của công cụ ẩn. Hiện chỉ chuỗi rỗng mới chuyển xuống dự phòng; header chính không rỗng nhưng sai định dạng vẫn có thẩm quyền. Facade adapter duy nhất đối chiếu envelope tool-not-found đã dịch của error factory đã cài trước chuẩn hóa; lỗi INVALID_PARAMS/xác thực dữ liệu/thiếu tham số không liên quan giữ status/body gốc.

Ma trận cũng đạt 35/35 khi Redis cache bền vững đã ghim hoạt động. Đặt lại yêu cầu hỗ trợ flush nhóm, sentinel thuộc phạm vi quản lý có thể xóa và xác minh cưỡng bức vắng mặt; không bao giờ chuyển sang flush toàn cache. Quyền sở hữu mã đo lường/trạng thái kiểm thử được kiểm tra trước thiết lập và dọn dẹp. Chẩn đoán adapter không hỗ trợ tải helper màn hình lõi trước khi gọi listener thông báo quản trị.

[Checkpoint hiệu năng](../architecture/performance.md) ghi thời gian khóa không biết/bí mật sai, benchmark sáu mươi công cụ, đồng thời bộ đếm chung và bằng chứng sự cố có phạm vi. Độ trễ yêu cầu đầy đủ vẫn trên mục tiêu. Hai ứng dụng client thực tế và nghiệm thu trình đọc màn hình thủ công vẫn chờ. Độ bao phủ đo riêng ở [checkpoint độ bao phủ](../development-coverage.md). Nội dung cơ sở dữ liệu N-02 theo nghĩa đen cũng cần đối soát với cơ chế dự phòng kiểm toán/giới hạn tần suất; đo lường hiện tại đếm truy vấn bảng thông tin xác thực. Chỉ báo cáo 35 ID không khép lại các yêu cầu này.

Nguồn giao thức chính thức, đã xác minh ngày 2026-10-05: [phiên bản hiện đại](https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning), [header HTTP hiện đại](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [HTTP cũ](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).
