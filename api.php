<?php
// Backend API cho demo chỉ mục (Indexing Strategy)
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

// ---- Danh sách thuật toán & kịch bản truy vấn ----
$ALGOS = [
  'btree' => [
    'label' => 'B-tree index',
    'ready' => true,
    'info' => '<b>B-tree index</b>: cây cân bằng, các khoá được giữ theo thứ tự nên tìm kiếm tốn O(log n) thay vì quét toàn bảng O(n).'
      . '<ul><li>Mạnh: so sánh bằng (<code>=</code>), khoảng (<code>BETWEEN</code>, <code>&lt;</code>, <code>&gt;</code>), <code>ORDER BY</code>, tiền tố <code>LIKE \'abc%\'</code>.</li>'
      . '<li>Yếu: ghi chậm hơn và tốn dung lượng (mỗi INSERT/UPDATE phải cập nhật cây); không giúp <code>LIKE \'%abc\'</code>.</li></ul>',
    'scenarios' => [
      'isbn' => [
        'name' => 'Tìm theo ISBN (so sánh bằng)',
        'index' => 'idx_books_isbn',
        'col' => 'isbn',
        'query' => fn($n) => "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE isbn='" . isbnOf(random_int(1, $n)) . "'"
      ],
      'price' => [
        'name' => 'Tìm theo khoảng giá (BETWEEN)',
        'index' => 'idx_books_price',
        'col' => 'price',
        'query' => function ($n) {
          $a = random_int(10000, 490000);
          return "SELECT SQL_NO_CACHE id,title,price FROM books WHERE price BETWEEN $a AND " . ($a + 500);
        }
      ],
      'title' => [
        'name' => '20 sách đầu theo tên (ORDER BY + LIMIT)',
        'index' => 'idx_books_title',
        'col' => 'title',
        'query' => fn($n) => "SELECT SQL_NO_CACHE id,title,author FROM books ORDER BY title LIMIT 20"
      ],
    ],
  ],
  'hash'      => ['label' => 'Hash index',      'ready' => false],
  'composite' => ['label' => 'Composite index', 'ready' => false],
  'bitmap'    => ['label' => 'Bitmap index',    'ready' => false],
  'fulltext'  => ['label' => 'Full-text index', 'ready' => false],
];

function plan($db, $sql)
{
  $r = $db->query('EXPLAIN ' . $sql);
  if (!$r) return 'EXPLAIN lỗi: ' . $db->error;
  $lines = [];
  while ($row = $r->fetch_assoc())
    $lines[] = "type={$row['type']}  key=" . ($row['key'] ?? 'NULL') . "  rows≈{$row['rows']}" . (!empty($row['Extra']) ? "  Extra={$row['Extra']}" : '');
  return implode("\n", $lines);
}

// Chạy lặp tới khi đủ ~300ms để đo chính xác cả truy vấn rất nhanh
function bench($db, $mk, $min)
{
  $r = $db->query($mk());
  $r->fetch_all();
  $r->free(); // Chạy nóng bỏ qua kết quả đầu
  $n = 0;
  $rows = 0;
  $t0 = hrtime(true);
  do {
    $r = $db->query($mk());
    $rows += $r->num_rows;
    $r->fetch_all();
    $r->free();
    $n++;
    $el = (hrtime(true) - $t0) / 1e6;
  } while (($el < 300 || $n < $min) && $n < 20000);
  return ['ms' => $el / $n, 'n' => $n, 'rows' => (int)round($rows / $n)];
}

$action = $_POST['action'] ?? 'meta';

// 1. Lấy thông tin cấu hình và số dòng hiện tại
if ($action === 'meta') {
  $meta = [];
  foreach ($ALGOS as $k => $a) {
    $meta[$k] = ['label' => $a['label'], 'ready' => $a['ready'], 'info' => $a['info'] ?? ''];
    if ($a['ready']) foreach ($a['scenarios'] as $sk => $s) $meta[$k]['scenarios'][] = ['key' => $sk, 'name' => $s['name']];
  }
  out(['algos' => $meta, 'rows' => dbRows($db)]);
}

// 2. Xóa và tạo lại bảng dữ liệu
if ($action === 'reset') {
  if (!resetBooksTable($db)) out(['error' => $db->error], 500);
  out(['ok' => true]);
}

// 3. Sinh dữ liệu mẫu theo block
if ($action === 'gen') {
  $from = max(1, (int)$_POST['from']);
  $to = (int)$_POST['to'];
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
    if (!$db->query('INSERT INTO books (id,isbn,title,author,genre,year,price) VALUES ' . implode(',', $vals))) out(['error' => $db->error], 500);
  }
  $db->commit();
  out(['ok' => true]);
}

// 4. Chạy benchmark so sánh có index và không có index
if ($action === 'run') {
  $algo = $ALGOS[$_POST['algo'] ?? ''] ?? null;
  if (!$algo || !$algo['ready']) out(['error' => 'Thuật toán chưa hỗ trợ'], 400);
  $sc = $algo['scenarios'][$_POST['scenario'] ?? ''] ?? null;
  if (!$sc) out(['error' => 'Truy vấn không hợp lệ'], 400);
  $n = dbRows($db);
  if ($n < 1) out(['error' => 'Chưa có dữ liệu. Bấm "Tạo dữ liệu" trước.'], 400);
  $mk = fn() => $sc['query']($n);
  $idx = $sc['index'];

  $db->query("DROP INDEX $idx ON books");
  $db->query('ANALYZE TABLE books');
  $planA = plan($db, $mk());
  $A = bench($db, $mk, 2);

  $t = hrtime(true);
  if (!$db->query("CREATE INDEX $idx USING BTREE ON books({$sc['col']})")) out(['error' => $db->error], 500);
  $build = (hrtime(true) - $t) / 1e6;
  $db->query('ANALYZE TABLE books');
  $planB = plan($db, $mk());
  $B = bench($db, $mk, 50);

  @$db->query('SET SESSION information_schema_stats_expiry=0');
  $q = $db->query("SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='books'")->fetch_row();
  $size = (int)$q[0];

  $db->query("DROP INDEX $idx ON books"); // Reset lại trạng thái
  out([
    'name' => $sc['name'],
    'sql' => preg_replace('/ SQL_NO_CACHE/', '', $mk()),
    'n' => $n,
    'A' => $A,
    'B' => $B,
    'build_ms' => $build,
    'index_bytes' => $size,
    'planA' => $planA,
    'planB' => $planB
  ]);
}

out(['error' => 'action không hợp lệ'], 400);
