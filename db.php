<?php
// Quản lý cấu hình và kết nối cơ sở dữ liệu MySQL (XAMPP).
const DB_HOST = '127.0.0.1', DB_USER = 'root', DB_PASS = '', DB_NAME = 'mysql--hash';

mysqli_report(MYSQLI_REPORT_OFF);

$db = @new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($db->connect_errno) {
  http_response_code(500);
  echo json_encode([
    'error' => 'Không kết nối được MySQL (' . $db->connect_error . '). Hãy bật MySQL trong XAMPP Control Panel và kiểm tra DB_USER/DB_PASS trong db.php.'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$db->set_charset('utf8mb4');
$db->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4');
$db->select_db(DB_NAME);

/**
 * Tạo mới hoặc reset lại bảng books với cấu hình InnoDB
 */
function resetBooksTable($db) {
  $db->query('DROP TABLE IF EXISTS books');
  return $db->query('CREATE TABLE books (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    isbn CHAR(10) NOT NULL,
    title VARCHAR(120) NOT NULL,
    author VARCHAR(60) NOT NULL,
    genre VARCHAR(30) NOT NULL,
    year SMALLINT NOT NULL,
    price INT NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Đếm số dòng hiện có trong bảng books
 */
function dbRows($db) {
  if ($db->query("SHOW TABLES LIKE 'books'")->num_rows === 0) return 0;
  $r = $db->query('SELECT MAX(id) FROM books')->fetch_row();
  return (int)($r[0] ?? 0);
}

/**
 * Hàm hỗ trợ quản lý bảng MEMORY cho Hash Index
 */
function initMemoryTable($db) {
  $db->query('DROP TABLE IF EXISTS books_mem');
  $db->query('CREATE TABLE books_mem LIKE books');
  $db->query('ALTER TABLE books_mem ENGINE=MEMORY');
  $db->query('INSERT INTO books_mem SELECT * FROM books');
}

function dropMemoryTable($db) {
  $db->query('DROP TABLE IF EXISTS books_mem');
}