-- ============================================
-- KTX Smart - Database Schema
-- Quản Lý Ký Túc Xá Thông Minh
-- ============================================

CREATE DATABASE IF NOT EXISTS ktx_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ktx_management;

-- ============================================
-- USERS & AUTH
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL UNIQUE,
    email           VARCHAR(100) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    full_name       VARCHAR(100) NOT NULL,
    phone           VARCHAR(20),
    role            ENUM('admin','staff','student') NOT NULL DEFAULT 'student',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    reset_token         VARCHAR(100) NULL,
    reset_token_expires DATETIME    NULL,
    last_login      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_profiles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    student_code    VARCHAR(20) UNIQUE,
    university      VARCHAR(150),
    room_id         INT NULL,
    move_in_date    DATE NULL,
    move_out_date   DATE NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- BUILDINGS & ROOMS
-- ============================================
CREATE TABLE IF NOT EXISTS buildings (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(100) NOT NULL,
    address VARCHAR(200),
    floors  INT DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    building_id     INT NOT NULL,
    room_number     VARCHAR(20) NOT NULL,
    type            ENUM('standard','premium','vip') NOT NULL DEFAULT 'standard',
    capacity        INT NOT NULL DEFAULT 4,
    price_per_month DECIMAL(12,2) NOT NULL DEFAULT 0,
    status          ENUM('available','full','maintenance') NOT NULL DEFAULT 'available',
    description     TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (building_id) REFERENCES buildings(id),
    UNIQUE KEY uq_room (building_id, room_number)
) ENGINE=InnoDB;

-- ============================================
-- SERVICES
-- ============================================
CREATE TABLE IF NOT EXISTS services (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    default_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit            VARCHAR(30),
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- INVOICES & PAYMENTS
-- ============================================
CREATE TABLE IF NOT EXISTS invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_code    VARCHAR(30) NOT NULL UNIQUE,
    student_id      INT NOT NULL,
    room_id         INT NOT NULL,
    billing_month   DATE NOT NULL,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date        DATE NOT NULL,
    status          ENUM('pending','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'pending',
    qr_data         TEXT,
    notes           TEXT,
    created_by      INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (room_id)    REFERENCES rooms(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id  INT NOT NULL,
    service_id  INT NULL,
    description VARCHAR(200) NOT NULL,
    quantity    DECIMAL(10,3) NOT NULL DEFAULT 1,
    unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    payment_code    VARCHAR(40) NOT NULL UNIQUE,
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  ENUM('qr_code','cash','bank_transfer','momo','zalopay') NOT NULL DEFAULT 'qr_code',
    payment_status  ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    transaction_id  VARCHAR(100),
    payer_name      VARCHAR(100),
    notes           TEXT,
    paid_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB;

-- ============================================
-- UTILITIES (ELECTRICITY / WATER)
-- ============================================
CREATE TABLE IF NOT EXISTS utility_readings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    room_id         INT NOT NULL,
    type            ENUM('electricity','water') NOT NULL,
    period          VARCHAR(7) NOT NULL,   -- YYYY-MM
    prev_reading    DECIMAL(10,3) NOT NULL DEFAULT 0,
    curr_reading    DECIMAL(10,3) NOT NULL DEFAULT 0,
    used            DECIMAL(10,3) NOT NULL DEFAULT 0,
    unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
    recorded_by     INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    UNIQUE KEY uq_reading (room_id, type, period)
) ENGINE=InnoDB;

-- ============================================
-- NOTIFICATIONS
-- ============================================
CREATE TABLE IF NOT EXISTS notifications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    message         TEXT NOT NULL,
    type            ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    related_id      INT NULL,
    related_type    VARCHAR(30) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- ACTIVITY LOGS
-- ============================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    action      VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address  VARCHAR(45),
    user_agent  TEXT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- BACKUP LOGS
-- ============================================
CREATE TABLE IF NOT EXISTS backup_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(100) NOT NULL,
    file_size   BIGINT,
    backup_type ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    status      ENUM('success','failed') NOT NULL DEFAULT 'success',
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- SEED DATA
-- ============================================

-- Default services
INSERT IGNORE INTO services (id, name, description, default_price, unit, is_active) VALUES
(1, 'Tiền phòng',    'Tiền thuê phòng hàng tháng',   800000, 'tháng', 1),
(2, 'Internet',      'Phí sử dụng internet',           50000, 'tháng', 1),
(3, 'Vệ sinh',       'Phí vệ sinh chung',              30000, 'tháng', 1),
(4, 'Điện',          'Tiền điện theo chỉ số',           3500, 'kWh',   1),
(5, 'Nước',          'Tiền nước theo chỉ số',          15000, 'm³',    1),
(6, 'Giữ xe',        'Phí gửi xe máy',                 50000, 'tháng', 1),
(7, 'Bảo hiểm',      'Phí bảo hiểm sinh viên',         20000, 'tháng', 1);

-- Buildings
INSERT IGNORE INTO buildings (id, name, address, floors) VALUES
(1, 'Tòa A', 'Khu A - Ký túc xá trường', 5),
(2, 'Tòa B', 'Khu B - Ký túc xá trường', 6),
(3, 'Tòa C', 'Khu C - Ký túc xá trường', 4);

-- Sample rooms
INSERT IGNORE INTO rooms (id, building_id, room_number, type, capacity, price_per_month, status) VALUES
(1, 1, 'A101', 'standard', 4, 800000,  'available'),
(2, 1, 'A102', 'standard', 4, 800000,  'full'),
(3, 1, 'A201', 'premium',  2, 1200000, 'available'),
(4, 2, 'B101', 'standard', 6, 700000,  'available'),
(5, 2, 'B201', 'premium',  2, 1500000, 'full'),
(6, 3, 'C101', 'vip',      1, 2000000, 'available');

-- Default admin account (password: Admin@123)
INSERT IGNORE INTO users (id, username, email, password, full_name, role, status) VALUES
(1, 'admin', 'admin@ktx.vn',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'Quản Trị Viên', 'admin', 'active');

-- Staff account (password: password)
INSERT IGNORE INTO users (id, username, email, password, full_name, role, status) VALUES
(2, 'staff01', 'staff01@ktx.vn',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'Nguyễn Văn Nhân Viên', 'staff', 'active');

-- Sample student (password: password)
INSERT IGNORE INTO users (id, username, email, password, full_name, phone, role, status) VALUES
(3, 'sv001', 'sv001@student.edu.vn',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'Trần Thị Sinh Viên', '0901234567', 'student', 'active');

INSERT IGNORE INTO student_profiles (user_id, student_code, university, room_id) VALUES
(3, 'SV2024001', 'Đại học ABC', 2);

-- Update room status based on student assignment
UPDATE rooms SET status='full' WHERE id=2;

-- ============================================
-- ROOM REQUESTS (Đăng ký / Huỷ phòng)
-- ============================================
CREATE TABLE IF NOT EXISTS room_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    student_id      INT NOT NULL,
    request_type    ENUM('register','cancel','change') NOT NULL DEFAULT 'register',
    from_room_id    INT NULL,          -- phòng hiện tại (khi cancel / change)
    to_room_id      INT NULL,          -- phòng muốn đăng ký (khi register / change)
    reason          TEXT,              -- lý do sinh viên điền
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by     INT NULL,          -- admin/staff duyệt
    review_note     TEXT,              -- ghi chú khi duyệt/từ chối
    reviewed_at     DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id)   REFERENCES users(id),
    FOREIGN KEY (from_room_id) REFERENCES rooms(id),
    FOREIGN KEY (to_room_id)   REFERENCES rooms(id),
    FOREIGN KEY (reviewed_by)  REFERENCES users(id)
) ENGINE=InnoDB;
