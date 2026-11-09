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


// ======================= BITMAP INDEX (mô phỏng) =======================
// MySQL InnoDB không có bitmap index gốc nên ta tự cài đặt đúng cấu trúc của nó:
// với MỖI giá trị phân biệt của cột -> 1 chuỗi bit, độ dài = số dòng, bit thứ i = 1 nếu dòng id=i mang giá trị đó.
// Các bitmap được lưu bền vững trong bảng bitmap_index (col, val, bits LONGBLOB).
$GENRES  = ['Tiểu thuyết', 'Truyện ngắn', 'Thiếu nhi', 'Trinh thám', 'Lịch sử', 'Khoa học', 'Kinh tế'];
$AUTHORS = ['Nguyễn Nhật Ánh', 'Tô Hoài', 'Nam Cao', 'Ngô Tất Tố', 'Xuân Diệu', 'Nguyễn Du', 'Vũ Trọng Phụng', 'Bảo Ninh', 'Dương Thu Hương', 'Nguyễn Huy Thiệp'];

function bmSet(&$s, $i) { $s[$i >> 3] = chr(ord($s[$i >> 3]) | (1 << ($i & 7))); }
function bmGet($s, $i) { return (ord($s[$i >> 3]) >> ($i & 7)) & 1; }

// Đếm số bit 1 (popcount): thống kê tần suất từng byte rồi nhân với số bit 1 của byte đó
function bmCount($s)
{
  static $pc = null;
  if ($pc === null) for ($b = 0; $b < 256; $b++) $pc[$b] = substr_count(decbin($b), '1');
  $t = 0;
  foreach (count_chars($s, 1) as $b => $c) $t += $pc[$b] * $c;
  return $t;
}

function bmLoad($db, $col, $val)
{
  $r = $db->query("SELECT bits FROM bitmap_index WHERE col='" . $db->real_escape_string($col) . "' AND val='" . $db->real_escape_string($val) . "'");
  if (!$r) return '';
  $row = $r->fetch_row();
  $r->free();
  return $row ? $row[0] : '';
}

// Quét bảng 1 lần, dựng bitmap cho các cột và ghi vào bảng bitmap_index
function bmBuild($db, $n, $cols)
{
  $len = ($n >> 3) + 1;
  $bm = [];
  $r = $db->query('SELECT id,' . implode(',', $cols) . ' FROM books', MYSQLI_USE_RESULT);
  while ($row = $r->fetch_row()) {
    foreach ($cols as $k => $c) {
      $v = $row[$k + 1];
      if (!isset($bm[$c][$v])) $bm[$c][$v] = str_repeat("\0", $len);
      bmSet($bm[$c][$v], (int)$row[0]);
    }
  }
  $r->free();
  $db->query('DROP TABLE IF EXISTS bitmap_index');
  $db->query('CREATE TABLE bitmap_index (col VARCHAR(20) NOT NULL, val VARCHAR(60) NOT NULL, bits LONGBLOB NOT NULL, PRIMARY KEY (col, val)) ENGINE=InnoDB');
  $raw = 0;
  $gz = 0;
  foreach ($bm as $c => $vals) foreach ($vals as $v => $bits) {
    if (!$db->query("INSERT INTO bitmap_index VALUES ('$c','" . $db->real_escape_string($v) . "',0x" . bin2hex($bits) . ')'))
      out(['error' => 'Ghi bitmap lỗi: ' . $db->error . ' (thử tăng max_allowed_packet trong my.ini)'], 500);
    $raw += strlen($bits);
    $gz += strlen(gzcompress($bits)); // gzip dùng để minh hoạ khả năng nén (thực tế dùng WAH/BBC/Roaring)
  }
  return [$raw, $gz, $bm];
}

$pick = fn($a) => $a[array_rand($a)];
// Mỗi kịch bản trả về [câu SQL COUNT(*) tương đương, hàm đánh giá biểu thức bit từ bộ nạp $L(col,val)]
$BM_SCENARIOS = [
  'bm_single' => [
    'name' => 'Đếm theo 1 điều kiện (genre = ?)',
    'plan' => "Nạp bitmap(genre=?)\nPopcount (đếm bit 1) -> kết quả",
    'make' => function () use ($pick, $GENRES) {
      $g = $pick($GENRES);
      return ["SELECT SQL_NO_CACHE COUNT(*) FROM books WHERE genre='$g'", fn($L) => $L('genre', $g)];
    }
  ],
  'bm_and' => [
    'name' => 'Kết hợp AND 2 cột (genre = ? AND author = ?)',
    'plan' => "Nạp bitmap(genre=?) AND bitmap(author=?)\nPopcount -> kết quả",
    'make' => function () use ($pick, $GENRES, $AUTHORS) {
      $g = $pick($GENRES);
      $a = $pick($AUTHORS);
      return ["SELECT SQL_NO_CACHE COUNT(*) FROM books WHERE genre='$g' AND author='$a'", fn($L) => $L('genre', $g) & $L('author', $a)];
    }
  ],
  'bm_or' => [
    'name' => 'Kết hợp OR + AND ((genre = ? OR genre = ?) AND author = ?)',
    'plan' => "(bitmap(genre=?1) OR bitmap(genre=?2)) AND bitmap(author=?)\nPopcount -> kết quả",
    'make' => function () use ($pick, $GENRES, $AUTHORS) {
      $g1 = $GENRES[0];
      $g2 = $GENRES[3];
      $a = $pick($AUTHORS);
      return ["SELECT SQL_NO_CACHE COUNT(*) FROM books WHERE (genre='$g1' OR genre='$g2') AND author='$a'", fn($L) => ($L('genre', $g1) | $L('genre', $g2)) & $L('author', $a)];
    }
  ],
];

// Lặp tới khi đủ ~300ms; $fn trả về số dòng kết quả
function benchFn($fn, $min)
{
  $fn();
  $n = 0;
  $t0 = hrtime(true);
  do {
    $fn();
    $n++;
    $el = (hrtime(true) - $t0) / 1e6;
  } while (($el < 300 || $n < $min) && $n < 20000);
  return ['ms' => $el / $n, 'n' => $n];
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
  $bmScenarios = [];
  foreach ($BM_SCENARIOS as $key => $s) $bmScenarios[] = ['key' => $key, 'name' => $s['name']];
  out([
    'rows'             => dbRows($db),
    'scenarios'        => $scenarios,
    'bitmap_scenarios' => $bmScenarios
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

// 5. Benchmark Bitmap Index: Full Scan vs B-tree (2 index đơn cột) vs Bitmap (mô phỏng)
if ($action === 'run_bitmap') {
  $sc = $BM_SCENARIOS[$_POST['scenario'] ?? ''] ?? null;
  if (!$sc) out(['error' => 'Kịch bản bitmap không hợp lệ'], 400);
  $n = dbRows($db);
  if ($n < 1) out(['error' => 'Chưa có dữ liệu. Bấm "Tạo dữ liệu" trước.'], 400);

  $sqlCount = function () use ($db, $sc) {
    $r = $db->query($sc['make']()[0]);
    $c = (int)$r->fetch_row()[0];
    $r->free();
    return $c;
  };
  $dropBt = fn() => array_map(fn($i) => $db->query("DROP INDEX $i ON books"), ['idx_books_genre', 'idx_books_author']);

  // A. Không index
  $dropBt();
  $db->query('ANALYZE TABLE books');
  $planA = plan($db, $sc['make']()[0]);
  $A = benchFn($sqlCount, 2);

  // B. B-tree: mỗi cột 1 index riêng (MySQL có thể dùng index_merge hoặc chọn 1 index)
  $t = hrtime(true);
  $db->query('CREATE INDEX idx_books_genre USING BTREE ON books(genre)');
  $db->query('CREATE INDEX idx_books_author USING BTREE ON books(author)');
  $buildBt = (hrtime(true) - $t) / 1e6;
  $db->query('ANALYZE TABLE books');
  $planB = plan($db, $sc['make']()[0]);
  $B = benchFn($sqlCount, 20);
  @$db->query('SET SESSION information_schema_stats_expiry=0');
  $q = $db->query("SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='books'")->fetch_row();
  $btBytes = (int)($q[0] ?? 0);
  $dropBt();

  // C. Bitmap: dựng bitmap (quét 1 lần) rồi đo; mỗi truy vấn nạp bitmap từ bảng bitmap_index + AND/OR + popcount
  $t = hrtime(true);
  [$raw, $gz, $bm] = bmBuild($db, $n, ['genre', 'author']);
  $buildBm = (hrtime(true) - $t) / 1e6;
  $L = fn($c, $v) => bmLoad($db, $c, $v);
  $C = benchFn(function () use ($sc, $L) { return bmCount($sc['make']()[1]($L)); }, 50);

  // Kiểm chứng: cùng 1 điều kiện, SQL và bitmap phải cho cùng kết quả
  [$vsql, $vev] = $sc['make']();
  $rr = $db->query($vsql);
  $cSql = (int)$rr->fetch_row()[0];
  $rr->free();
  $cBm = bmCount($vev($L));

  // Minh hoạ cấu trúc bitmap của cột genre cho 16 dòng đầu
  $ids = range(1, 16);
  $gOf = [];
  $rr = $db->query('SELECT genre FROM books WHERE id<=16 ORDER BY id');
  while ($x = $rr->fetch_row()) $gOf[] = $x[0];
  $demo = ['ids' => $ids, 'genre' => $gOf, 'bitmaps' => []];
  foreach ($GENRES as $g) {
    $demo['bitmaps'][$g] = isset($bm['genre'][$g]) ? array_map(fn($i) => bmGet($bm['genre'][$g], $i), $ids) : array_fill(0, 16, 0);
  }

  // Độ phân biệt (cardinality) và kích thước bitmap ước tính nếu đánh bitmap cho cột đó
  $len = ($n >> 3) + 1;
  $cr = $db->query('SELECT COUNT(DISTINCT genre),COUNT(DISTINCT author),COUNT(DISTINCT year),COUNT(DISTINCT price) FROM books')->fetch_row();
  $card = [];
  foreach (['genre', 'author', 'year', 'price'] as $i => $c) $card[] = ['col' => $c, 'distinct' => (int)$cr[$i], 'est_bytes' => (int)$cr[$i] * $len];

  $db->query('DROP TABLE IF EXISTS bitmap_index'); // dọn dẹp

  out([
    'name' => $sc['name'], 'sql' => preg_replace('/ SQL_NO_CACHE/', '', $vsql), 'n' => $n,
    'A' => $A, 'B' => $B, 'C' => $C,
    'build_bt_ms' => $buildBt, 'build_bm_ms' => $buildBm,
    'bt_bytes' => $btBytes, 'bm_raw_bytes' => $raw, 'bm_gz_bytes' => $gz,
    'planA' => $planA, 'planB' => $planB, 'planC' => $sc['plan'],
    'verify' => ['sql' => $cSql, 'bm' => $cBm],
    'demo' => $demo, 'card' => $card
  ]);
}

out(['error' => 'Action không hợp lệ'], 400);
