# KTX Smart - Hệ Thống Quản Lý Ký Túc Xá
qlktx-production.up.railway.app
## Cấu Trúc Thư Mục

```
ktx/
├── index.php                   # Entry point (redirect)
├── config/
│   ├── database.php            # Cấu hình CSDL & hằng số
│   └── schema.sql              # Schema MySQL đầy đủ
├── includes/
│   ├── functions.php           # Tất cả hàm helper
│   ├── header.php              # Sidebar + Topbar layout
│   └── footer.php              # Scripts + đóng HTML
├── pages/
│   ├── login.php               # Đăng nhập
│   ├── register.php            # Đăng ký (sinh viên)
│   ├── logout.php              # Đăng xuất
│   ├── forgot_password.php     # Quên/đặt lại mật khẩu
│   ├── dashboard.php           # Trang chủ (theo phân quyền)
│   ├── profile.php             # Hồ sơ & đổi mật khẩu
│   ├── notifications.php       # Thông báo
│   ├── forbidden.php           # Trang lỗi phân quyền
│   │
│   ├── [ADMIN + STAFF]
│   ├── students.php            # Quản lý sinh viên, xếp phòng
│   ├── rooms.php               # Quản lý tòa nhà & phòng ở
│   ├── invoices.php            # Tạo & quản lý hóa đơn
│   ├── pay_invoice.php         # Thanh toán QR / xác nhận
│   ├── payments.php            # Lịch sử thanh toán
│   ├── utilities.php           # Ghi chỉ số điện/nước
│   │
│   ├── [ADMIN ONLY]
│   ├── users.php               # Phân quyền tài khoản
│   ├── services.php            # Quản lý dịch vụ
│   ├── backup.php              # Sao lưu CSDL
│   ├── logs.php                # Nhật ký hoạt động
│   │
│   └── [STUDENT]
│       ├── my_invoices.php     # Xem hóa đơn cá nhân
│       └── my_room.php         # Xem thông tin phòng
├── assets/
│   ├── css/style.css           # Giao diện chính
│   └── js/app.js               # JavaScript (chart, QR, sidebar)
├── backup/                     # File backup SQL
└── uploads/
    └── qr/                     # QR code tạo ra
```

## Cài Đặt

### Yêu cầu
- PHP 8.0+, MySQL 8.0+, Apache/Nginx với mod_rewrite
- Extensions: PDO, PDO_MySQL, fileinfo

### Bước 1 — Cấu hình database
Sửa `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
define('DB_NAME', 'ktx_management');
define('BASE_URL', 'http://localhost/ktx');
```

### Bước 2 — Tạo database
```sql
mysql -u root -p < config/schema.sql
```

### Bước 3 — Phân quyền thư mục
```bash
chmod 775 backup/ uploads/qr/
chown www-data:www-data backup/ uploads/qr/
```

### Bước 4 — Đặt vào web root
```bash
cp -r ktx/ /var/www/html/ktx
# hoặc
cp -r ktx/ /htdocs/ktx   # XAMPP
```

## Tài Khoản Demo
| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `password` |
| Nhân viên | `staff01` | `password` |
| Sinh viên | `sv001` | `password` |

## Tính Năng

### Phân Quyền
- **Admin**: Toàn quyền — tài khoản, phân quyền, backup, log, dịch vụ
- **Nhân viên (Staff)**: Quản lý sinh viên, phòng, hóa đơn, thanh toán, điện nước
- **Sinh viên**: Xem hóa đơn, thanh toán QR, xem phòng

### Thanh Toán QR
- Tích hợp VietQR (Vietcombank) tự động
- QR hiển thị số tiền, mã hóa đơn, thông tin chuyển khoản
- Polling tự động kiểm tra trạng thái sau khi quét
- Nhân viên xác nhận thanh toán thủ công (tiền mặt/chuyển khoản)

### Dashboard
- Biểu đồ doanh thu 6 tháng (Chart.js)
- Tỷ lệ lấp đầy phòng (Pie chart)
- Thống kê nhanh (sinh viên, phòng, hóa đơn chờ, doanh thu)

### Backup
- Backup thủ công full CSDL ra file .sql
- Xem lịch sử, tải về, xóa file backup
