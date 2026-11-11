# Checkpoint độ bao phủ

Đo ngày 2026-10-06 UTC / giờ địa phương với PHP 8.2.34, Xdebug 3.5.3, PHPUnit 9.6 và bộ hợp nhất php-code-coverage 9.2 đã cài. [Bằng chứng số liệu](../../.github/coverage/M1-lines.json) chỉ chứa đường dẫn tương đối, dấu vân tay nguồn và số dòng có thể thực thi. Artifact thô và nhật ký kiểm thử nằm trong thư mục riêng mới tại lab từ xa thuộc phạm vi quản lý; không được công bố.

| Phạm vi | Đã bao phủ / có thể thực thi | Độ bao phủ |
|---|---:|---:|
| Toàn bộ 95 tệp PHP production, gồm bootstrap/uninstall |2325 /2795|83.1843%|
| Domain, kết hợp |63 /67|94.0299%|
| Auth, kết hợp |387 /429|90.2098%|
| Policy, kết hợp |48 /53|90.5660%|
| Domain, chỉ kiểm thử đơn vị |63 /67|94.0299%|
| Helper Auth thuần, chỉ kiểm thử đơn vị |57 /60|95.0000%|
| Toàn bộ namespace Auth, chỉ kiểm thử đơn vị |191 /429|44.5221%|

Auth thuần gồm FailureReason, GeneratedKey, HeaderExtractor, IssuedKey, KeyGenerator, KeyHasher, ParsedToken, Pepper, StoredKey và TokenParser. Các lớp này không gọi API WordPress; service nền tảng/lưu trữ vẫn thuộc phạm vi Auth kết hợp. Phân biệt này theo yêu cầu PHP thuần của M1-S01 gốc và không loại service đó khỏi độ bao phủ phát hành. Không được báo độ bao phủ đơn vị toàn bộ Auth là 95%.

Số liệu kết hợp hợp nhất 177 kiểm thử đơn vị và bốn bộ tích hợp WordPress trên 6.9/7.1.2, single-site và multisite thực tế. Mỗi lượt single-site cố ý bỏ qua bốn trường hợp chỉ dành cho network; cả hai lượt multisite đều thực thi chúng. Bộ hợp nhất yêu cầu đúng bộ lọc nguồn đầy đủ từ mọi artifact và bao gồm tệp không có câu lệnh được thực thi. Thiếu bằng chứng tuyệt đối không được tính là độ bao phủ bằng không hoặc thành công. Thay đổi mẫu số do phân tích dòng thực thi đã tải/chưa tải được giữ nguyên, không điều chỉnh để đạt. Cụ thể, Auth 89.9767% thấp hơn 90%; một kiểm thử bổ sung về bộ xác minh hỏng đưa kết quả đo vượt ngưỡng mà không làm tròn.

Trường hợp mới xác minh thay thế cookie cache sớm ở giai đoạn muộn, bảo toàn từ chối trước đó, cô lập tuyến, lưu trữ không khả dụng 503 và giới hạn tần suất 429, header challenge/cache/retry, khả năng hiển thị công cụ thực tế, tính mờ/hỏng của pepper, metadata xác minh đã lưu sai định dạng, thông báo vòng đời trì hoãn, thử lại khi giao thông báo thất bại và chặn thông báo của khóa đã xóa. Hàm/lưu trữ WordPress chỉ được thay thế trong tiến trình kiểm thử đơn vị cô lập; tích hợp dùng lõi/cơ sở dữ liệu thực tế và chặn mọi email trước gửi. Thành phần thay thế đơn vị không phải bằng chứng HTTP thực tế.

## Tái lập

Chỉ dùng remote-test-lab. Image toolbox cài archive Xdebug chính thức đã ghim sau kiểm tra SHA-256 và mặc định không bật tính năng Xdebug nào. Image website không thay đổi. Trong toolbox thuộc phạm vi quản lý, chạy `bash /work/infra/remote/run-coverage.sh`. Nó từ chối cache drop-in site trình duyệt đang hoạt động, tạo thư mục 0700 mới, ghi artifact PHPUnit 0600 và chạy tuần tự kiểm thử đơn vị cùng bốn chế độ tích hợp với `XDEBUG_MODE=coverage` và giới hạn PHP 768 MiB. Báo cáo dùng bộ hợp nhất gốc đã cài với debugging tắt. Không tái sử dụng artifact cũ.

Script báo cáo chỉ chấp nhận năm tên tệp cố định của runner từ thư mục riêng thuộc phạm vi quản lý. Không chấp nhận báo cáo PHP do người dùng chọn hoặc dữ liệu tuần tự hóa bên ngoài. Không sao chép artifact thô vào Git. JSON công khai là bản chiếu số liệu đã rà soát; băm nguồn ràng buộc nó với tệp production đã kiểm thử.

## Giới hạn và các cửa tiếp theo

Độ bao phủ dòng không chứng minh độ bao phủ nhánh/đường thực thi, tính đúng đắn bảo mật, tương thích client thực tế hoặc năng lực production. Auth vẫn gần ngưỡng phát hành nên tính năng mới phải giữ nguyên mẫu số và các cửa này. HTTP 35/35, Redis thực tế và benchmark được ghi riêng; độ bao phủ không thay thế chúng. M1 đầy đủ, các tính năng M2-M7 về sau, hai ứng dụng client thực tế, nội dung N02 chưa giải quyết và mục tiêu hiệu năng HTTPS đầy đủ vẫn chưa hoàn tất.

Tham chiếu chính thức đã xác minh ngày 2026-10-06: [bản tải Xdebug ổn định](https://xdebug.org/download), [kiểm tra chế độ Xdebug đang hoạt động](https://xdebug.org/docs/all_functions#xdebug_info), [phân tích độ bao phủ PHPUnit 9.6](https://docs.phpunit.de/en/9.6/code-coverage-analysis.html). API hợp nhất/lọc/báo cáo được xác minh với nguồn 9.2 đã cài trước khi dùng.
