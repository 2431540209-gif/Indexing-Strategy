<?php
// Backend API cho demo chỉ mục (Indexing Strategy)
// Tách biệt hoàn toàn: Backend chỉ xử lý truy vấn và trả dữ liệu JSON thô, không chứa HTML.
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

function out($d, $code = 200)
{
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE);
  exit;
}

function isbnOf($i)
{
  return str_pad((string)(($i * 48271) % 2147483647), 10, '0', STR_PAD_LEFT);
}

// Cấu hình các kịch bản truy vấn để benchmark
$SCENARIOS = [
  'isbn' => [
    'name'  => 'Tìm theo ISBN (so sánh bằng =)',
    'index' => 'idx_books_isbn',
    'col'   => 'isbn',
    'query' => fn($n) => "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE isbn='" . isbnOf(random_int(1, $n)) . "'"
  ],
  'price' => [
    'name'  => 'Tìm theo khoảng giá (BETWEEN)',
    'index' => 'idx_books_price',
    'col'   => 'price',
    'query' => function ($n) {
      $a = random_int(10000, 490000);
      return "SELECT SQL_NO_CACHE id,title,price FROM books WHERE price BETWEEN $a AND " . ($a + 500);
    }
  ],
  'title' => [
    'name'  => '20 sách đầu theo tên (ORDER BY + LIMIT)',
    'index' => 'idx_books_title',
    'col'   => 'title',
    'query' => fn($n) => "SELECT SQL_NO_CACHE id,title,author FROM books ORDER BY title LIMIT 20"
  ],
];

function plan($db, $sql)
{
  $r = $db->query('EXPLAIN ' . $sql);
  if (!$r) return 'EXPLAIN lỗi: ' . $db->error;
  $lines = [];
  while ($row = $r->fetch_assoc()) {
    $lines[] = "type={$row['type']}  key=" . ($row['key'] ?? 'NULL') . "  rows≈{$row['rows']}" . (!empty($row['Extra']) ? "  Extra={$row['Extra']}" : '');
  }
  return implode("\n", $lines);
}

// Chạy lặp tới khi đủ ~300ms để đo chính xác cả truy vấn rất nhanh
function bench($db, $mk, $min)
{
  $r = $db->query($mk());
  if ($r) {
    $r->fetch_all();
    $r->free(); // Chạy nóng bỏ qua kết quả đầu
  }
  $n = 0;
  $rows = 0;
  $t0 = hrtime(true);
  do {
    $r = $db->query($mk());
    if ($r) {
      $rows += $r->num_rows;
      $r->fetch_all();
      $r->free();
    }
    $n++;
    $el = (hrtime(true) - $t0) / 1e6;
  } while (($el < 300 || $n < $min) && $n < 20000);
  return ['ms' => $el / $n, 'n' => $n, 'rows' => (int)round($rows / $n)];
}

$action = $_POST['action'] ?? 'meta';

// 1. Lấy thông tin số dòng và danh sách kịch bản truy vấn (chỉ dữ liệu thô)
if ($action === 'meta') {
  $scenarios = [];
  foreach ($SCENARIOS as $key => $s) {
    $scenarios[] = [
      'key'  => $key,
      'name' => $s['name'],
      'col'  => $s['col']
    ];
  }
  out([
    'rows'      => dbRows($db),
    'scenarios' => $scenarios
  ]);
}

// 2. Xóa và tạo lại bảng dữ liệu
if ($action === 'reset') {
  if (!resetBooksTable($db)) out(['error' => $db->error], 500);
  out(['ok' => true]);
}

// 3. Sinh dữ liệu mẫu theo block
if ($action === 'gen') {
  $from = max(1, (int)($_POST['from'] ?? 1));
  $to = (int)($_POST['to'] ?? 0);
  if ($to < $from) out(['error' => 'Dải dữ liệu không hợp lệ'], 400);

  $W1 = ['Dế', 'Tôi', 'Cô', 'Người', 'Con', 'Mùa', 'Đường', 'Biển', 'Núi', 'Thành phố', 'Ký ức', 'Hành trình', 'Bóng', 'Ngôi nhà', 'Giấc mơ'];
  $W2 = ['Mèn', 'Thấy', 'Gái', 'Lái đò', 'Chim', 'Hoa', 'Xa', 'Xanh', 'Cao', 'Ngủ quên', 'Mùa thu', 'Cuối', 'Đêm', 'Nhỏ', 'Bất tận'];
  $AU = ['Nguyễn Nhật Ánh', 'Tô Hoài', 'Nam Cao', 'Ngô Tất Tố', 'Xuân Diệu', 'Nguyễn Du', 'Vũ Trọng Phụng', 'Bảo Ninh', 'Dương Thu Hương', 'Nguyễn Huy Thiệp'];
  $GE = ['Tiểu thuyết', 'Truyện ngắn', 'Thiếu nhi', 'Trinh thám', 'Lịch sử', 'Khoa học', 'Kinh tế'];
  
  $db->begin_transaction();
  for ($s = $from; $s <= $to; $s += 1000) {
    $vals = [];
    for ($i = $s; $i <= min($s + 999, $to); $i++) {
      $title = $db->real_escape_string($W1[array_rand($W1)] . ' ' . $W2[array_rand($W2)] . ' ' . random_int(0, 99999));
      $vals[] = "($i,'" . isbnOf($i) . "','$title','" . $db->real_escape_string($AU[array_rand($AU)]) . "','" . $db->real_escape_string($GE[array_rand($GE)]) . "'," . random_int(1950, 2025) . ',' . random_int(10000, 500000) . ')';
    }
    if (!$db->query('INSERT INTO books (id,isbn,title,author,genre,year,price) VALUES ' . implode(',', $vals))) {
      $db->rollback();
      out(['error' => $db->error], 500);
    }
  }
  $db->commit();
  out(['ok' => true]);
}

// 4. Chạy benchmark so sánh có index và không có index
if ($action === 'run') {
  $scenarioKey = $_POST['scenario'] ?? '';
  $sc = $SCENARIOS[$scenarioKey] ?? null;
  if (!$sc) out(['error' => 'Kịch bản truy vấn không hợp lệ'], 400);

  $n = dbRows($db);
  if ($n < 1) out(['error' => 'Chưa có dữ liệu. Bấm "Tạo dữ liệu" trước.'], 400);

  $mk = fn() => $sc['query']($n);
  $idx = $sc['index'];

  // Đo lúc KHÔNG có index
  $db->query("DROP INDEX $idx ON books");
  $db->query('ANALYZE TABLE books');
  $planA = plan($db, $mk());
  $A = bench($db, $mk, 2);

  // Tạo index và đo lúc CÓ index
  $t = hrtime(true);
  if (!$db->query("CREATE INDEX $idx USING BTREE ON books({$sc['col']})")) out(['error' => $db->error], 500);
  $build = (hrtime(true) - $t) / 1e6;
  $db->query('ANALYZE TABLE books');
  $planB = plan($db, $mk());
  $B = bench($db, $mk, 50);

  // Lấy dung lượng index
  @$db->query('SET SESSION information_schema_stats_expiry=0');
  $q = $db->query("SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='books'")->fetch_row();
  $size = (int)($q[0] ?? 0);

  // Dọn dẹp index sau khi đo xong
  $db->query("DROP INDEX $idx ON books");

  out([
    'scenario'    => $scenarioKey,
    'name'        => $sc['name'],
    'sql'         => preg_replace('/ SQL_NO_CACHE/', '', $mk()),
    'n'           => $n,
    'A'           => $A,
    'B'           => $B,
    'build_ms'    => $build,
    'index_bytes' => $size,
    'planA'       => $planA,
    'planB'       => $planB
  ]);
}

out(['error' => 'Action không hợp lệ'], 400);
