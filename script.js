// Frontend Controller for MySQL Indexing Demo
// Toàn bộ việc định dạng, hiển thị, quản lý thuật toán và giao diện xử lý tại Client-side

const $ = (s) => document.querySelector(s);
const esc = (s) =>
  String(s).replace(
    /[&<>]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])
  );

const fmtTime = (ms) =>
  ms >= 1 ? ms.toFixed(1) + ' ms' : (ms * 1000).toFixed(0) + ' µs';

const fmtBytes = (bytes) => {
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
  if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';
  return bytes + ' B';
};

// Danh mục thuật toán chỉ mục (Frontend quản lý hiển thị, lý thuyết & trạng thái)
const ALGORITHMS = {
  btree: {
    name: 'B-tree Index',
    badge: 'Đã kích hoạt',
    ready: true,
    summary: '<strong>B-Tree Index</strong> tổ chức dữ liệu thành cây tự cân bằng, các khoá được sắp xếp theo thứ tự. Tốc độ tìm kiếm giảm từ <code>O(n)</code> (quét tuần tự) xuống <code>O(log n)</code>.',
    pros: [
      'Tìm chính xác (<code>=</code>) và tìm khoảng (<code>BETWEEN</code>, <code>&gt;</code>, <code>&lt;</code>).',
      'Tối ưu sắp xếp (<code>ORDER BY</code>) và phân trang (<code>LIMIT</code>).',
      'Hỗ trợ tìm tiền tố chuỗi (<code>LIKE \'abc%\'</code>).'
    ],
    cons: [
      'Làm chậm thao tác ghi (<code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code> do phải tái cân bằng cây).',
      'Tốn dung lượng ổ đĩa và RAM (Buffer Pool) để duy trì cây chỉ mục.',
      'Không áp dụng được cho tìm chuỗi chứa từ bất kỳ (<code>LIKE \'%abc\'</code>).'
    ]
  },
  hash: {
    name: 'Hash Index',
    badge: 'Chưa kích hoạt',
    ready: false,
    summary: '<strong>Hash Index</strong> sử dụng bảng băm (Hash Table) để ánh xạ khoá tìm kiếm trực tiếp vào địa chỉ bản ghi. Đạt tốc độ truy vấn <code>O(1)</code> lý tưởng.',
    pros: [
      'Cực nhanh đối với phép so sánh bằng chính xác (<code>=</code> hoặc <code>&lt;=&gt;</code>).',
      'Thường dùng trong MEMORY storage engine hoặc Adaptive Hash Index của InnoDB.'
    ],
    cons: [
      'Hoàn toàn không hỗ trợ tìm kiếm theo khoảng (<code>BETWEEN</code>, <code>&lt;</code>, <code>&gt;</code>).',
      'Không hỗ trợ sắp xếp <code>ORDER BY</code>.',
      'Dễ bị xung đột băm (Hash collision) khi khối lượng dữ liệu lớn.'
    ]
  },
  composite: {
    name: 'Composite Index (Nhiều cột)',
    badge: 'Chưa kích hoạt',
    ready: false,
    summary: '<strong>Composite Index</strong> là chỉ mục được đánh trên tổ hợp nhiều cột (ví dụ: <code>(genre, year, price)</code>), phục vụ cho các câu truy vấn lọc theo nhiều điều kiện đồng thời.',
    pros: [
      'Tối ưu vượt trội cho truy vấn lọc nhiều cột cùng lúc.',
      'Có thể đạt trạng thái "Covering Index" (lấy dữ liệu trực tiếp trên index mà không cần đọc bảng chính).'
    ],
    cons: [
      'Phải tuân thủ nguyên tắc tiền tố bên trái (Left-most prefix rule).',
      'Kích thước chỉ mục lớn hơn nhiều so với chỉ mục đơn cột.'
    ]
  },
  fulltext: {
    name: 'Full-Text Index',
    badge: 'Chưa kích hoạt',
    ready: false,
    summary: '<strong>Full-Text Index</strong> phân tách văn bản thành các từ tố (tokens) và lập danh mục chỉ mục đảo (Inverted Index) để tìm kiếm từ ngữ trong đoạn văn bản dài.',
    pros: [
      'Hỗ trợ tìm kiếm từ khoá tự nhiên với cú pháp <code>MATCH(...) AGAINST(...)</code>.',
      'Tìm kiếm văn bản nhanh hơn rất nhiều so với dùng <code>LIKE \'%từ_khoá%\'</code>.'
    ],
    cons: [
      'Không áp dụng cho so sánh số học hay khoảng giá trị.',
      'Tốn tài nguyên tạo và duy trì chỉ mục đảo; bỏ qua các từ dừng (stopwords).'
    ]
  },
  bitmap: {
    name: 'Bitmap Index',
    badge: 'Đã kích hoạt (mô phỏng)',
    ready: true,
    summary: '<strong>Bitmap Index</strong> lưu, với <em>mỗi giá trị phân biệt</em> của cột, một chuỗi bit dài bằng số dòng của bảng: bit thứ <code>i</code> = 1 nếu dòng thứ <code>i</code> mang giá trị đó. Nhiều điều kiện được kết hợp bằng phép <code>AND</code>/<code>OR</code>/<code>NOT</code> trên bit rồi đếm bit 1 (popcount). MySQL InnoDB không có bitmap index gốc, nên demo này <strong>tự cài đặt</strong> cấu trúc đó (lưu trong bảng <code>bitmap_index</code>) và so sánh với Full Scan và B-tree.',
    pros: [
      'Rất tối ưu cho cột có độ phân biệt thấp (Low Cardinality): thể loại, trạng thái, giới tính...',
      'Kết hợp nhiều cột bằng <code>AND</code>/<code>OR</code>/<code>NOT</code> trên bit cực nhanh; <code>COUNT(*)</code> chỉ cần đếm bit mà không đọc bảng.',
      'Nhiều index đơn cột phối hợp được với nhau, không cần tạo index tổ hợp cho từng cách kết hợp cột.',
      'Chuỗi bit thưa/lặp nén rất tốt (WAH, BBC, Roaring).'
    ],
    cons: [
      'Cột có độ phân biệt cao (price, isbn) sẽ sinh quá nhiều bitmap, kích thước tăng theo <code>số giá trị × số dòng</code>.',
      'Cập nhật tốn kém: sửa 1 dòng phải sửa bitmap của giá trị cũ và mới, khoá cả đoạn bitmap nên nhiều giao dịch ghi đồng thời bị chặn lẫn nhau.',
      'Phù hợp Data Warehouse/OLAP (Oracle, Doris, StarRocks), không hợp hệ thống OLTP ghi nhiều.'
    ]
  }
};

let ROWS = 0;
let BACKEND_SCENARIOS = [];
let BITMAP_SCENARIOS = [];
let CONFIG = {
  apiEndpoint: 'api.php',
  chunkSize: 50000,
  dataSizes: [
    { value: 100000, label: '100.000 dòng' },
    { value: 500000, label: '500.000 dòng', selected: true },
    { value: 1000000, label: '1.000.000 dòng' }
  ]
};

// Cập nhật trạng thái kết nối Database trên Header
function updateDbBadge(status, text) {
  const badge = $('#dbBadge');
  const txt = $('#dbStatusText');
  badge.className = 'db-badge ' + status;
  txt.textContent = text;
}

// Cập nhật trạng thái từng bảng điều khiển
function setStatus(elementId, text, type = '') {
  const el = $(elementId);
  el.textContent = text;
  el.className = 'status-msg ' + type;
}

// Khóa / Mở nút thao tác khi đang bận
function setBusy(isBusy) {
  $('#genBtn').disabled = isBusy;
  const currentAlgo = ALGORITHMS[$('#algoSelect').value];
  $('#runBtn').disabled = isBusy || ROWS === 0 || !currentAlgo?.ready;
  $('#size').disabled = isBusy;
  $('#algoSelect').disabled = isBusy;
  $('#scn').disabled = isBusy || !currentAlgo?.ready;
}

// Wrapper gọi Backend API
async function api(action, data = {}) {
  const endpoint = CONFIG.apiEndpoint || 'api.php';
  const res = await fetch(endpoint, {
    method: 'POST',
    body: new URLSearchParams({ action, ...data })
  });

  let json;
  try {
    json = await res.json();
  } catch (err) {
    throw new Error('API không trả về định dạng JSON hợp lệ.');
  }

  if (json.error) {
    throw new Error(json.error);
  }
  return json;
}

// Hiển thị lý thuyết và cập nhật trạng thái khi người dùng đổi thuật toán
function updateSelectedAlgorithm() {
  const key = $('#algoSelect').value;
  const algo = ALGORITHMS[key] || ALGORITHMS.btree;

  // Cập nhật thẻ Knowledge
  $('#algoTitle').textContent = `Hiểu nhanh về ${algo.name}`;
  $('#algoBadge').textContent = algo.badge;
  $('#algoBadge').style.background = algo.ready ? 'var(--color-accent)' : 'var(--text-dim)';
  $('#algoBadge').style.color = algo.ready ? '#0f172a' : '#ffffff';

  $('#algoBody').innerHTML = `
    <p>${algo.summary}</p>
    <div class="knowledge-grid">
      <div class="feature-box pros">
        <div class="feature-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Điểm mạnh
        </div>
        <ul>${algo.pros.map((p) => `<li>${p}</li>`).join('')}</ul>
      </div>
      <div class="feature-box cons">
        <div class="feature-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
          Hạn chế &amp; Chi phí
        </div>
        <ul>${algo.cons.map((c) => `<li>${c}</li>`).join('')}</ul>
      </div>
    </div>
  `;

  // Cập nhật ô chọn kịch bản và trạng thái chạy
  const scnSelect = $('#scn');
  if (algo.ready) {
    scnSelect.disabled = false;
    scnSelect.innerHTML = (key === 'bitmap' ? BITMAP_SCENARIOS : BACKEND_SCENARIOS)
      .map((s) => `<option value="${s.key}">${esc(s.name)}</option>`)
      .join('');
    $('#runBtn').disabled = ROWS === 0;
    setStatus('#runStatus', ROWS > 0 ? 'Sẵn sàng chạy kiểm thử so sánh.' : 'Cần tạo dữ liệu trước khi benchmark.');
  } else {
    scnSelect.disabled = true;
    scnSelect.innerHTML = `<option value="" disabled selected>(Chưa cài đặt kịch bản - bạn có thể tự thêm)</option>`;
    $('#runBtn').disabled = true;
    setStatus('#runStatus', `Thuật toán "${algo.name}" chưa kích hoạt (để bạn tự bổ sung kịch bản).`, 'err');
  }
}

// 1. Logic tạo dữ liệu theo lô (Batch Generation)
async function generateData() {
  const targetRows = +$('#size').value;
  const chunkSize = CONFIG.chunkSize || 50000;
  const progressWrap = $('#progressWrap');
  const progressBar = $('#progressBar');

  setBusy(true);
  progressWrap.classList.add('active');
  progressBar.style.width = '0%';
  setStatus('#genStatus', 'Đang reset bảng dữ liệu...', '');

  try {
    await api('reset');
    ROWS = 0;
    updateDbBadge('ready', '0 dòng sách');

    for (let from = 1; from <= targetRows; from += chunkSize) {
      const to = Math.min(from + chunkSize - 1, targetRows);
      const percent = Math.round((to / targetRows) * 100);

      progressBar.style.width = percent + '%';
      setStatus(
        '#genStatus',
        `Đang chèn: ${to.toLocaleString('vi-VN')} / ${targetRows.toLocaleString('vi-VN')} dòng (${percent}%)`
      );

      await api('gen', { from, to });
    }

    ROWS = targetRows;
    updateDbBadge('ready', `${ROWS.toLocaleString('vi-VN')} dòng trong DB`);
    setStatus('#genStatus', `Đã tạo thành công ${ROWS.toLocaleString('vi-VN')} dòng sách!`, 'success');
    updateSelectedAlgorithm();
  } catch (err) {
    setStatus('#genStatus', 'Lỗi: ' + err.message, 'err');
    updateDbBadge('error', 'Lỗi Database');
  } finally {
    progressWrap.classList.remove('active');
    setBusy(false);
  }
}

// 2. Logic chạy kiểm thử so sánh (Benchmark)
async function runBenchmark() {
  const scenarioKey = $('#scn').value;
  if (!scenarioKey) return;

  const isBitmap = $('#algoSelect').value === 'bitmap';
  setBusy(true);
  setStatus('#runStatus', isBitmap
    ? 'Đang thực thi: Full Scan → B-tree → Dựng bitmap → Đo lại (có thể mất vài giây)...'
    : 'Đang thực thi: Quét tuần tự → Đánh B-tree index → Đo lại...', '');

  try {
    if (isBitmap) renderBitmapResult(await api('run_bitmap', { scenario: scenarioKey }));
    else renderBenchmarkResult(await api('run', { scenario: scenarioKey }));
    setStatus('#runStatus', 'Kiểm thử hoàn tất! Index mẫu đã tự động được dọn dẹp.', 'success');
  } catch (err) {
    setStatus('#runStatus', 'Lỗi: ' + err.message, 'err');
  } finally {
    setBusy(false);
  }
}

// 3. Render kết quả đo lường trực quan lên DOM
let runCount = 0;
function renderBenchmarkResult(r) {
  const emptyState = $('#emptyState');
  if (emptyState) emptyState.remove();

  runCount++;
  $('#resultsCount').textContent = `${runCount} lần chạy kiểm thử`;

  const timeNoIndex = r.A.ms;
  const timeWithIndex = r.B.ms;
  const maxTime = Math.max(timeNoIndex, timeWithIndex);

  const widthNoIndex = Math.max(1, (timeNoIndex / maxTime) * 100).toFixed(1) + '%';
  const widthWithIndex = Math.max(1, (timeWithIndex / maxTime) * 100).toFixed(1) + '%';

  // Sửa lỗi Temporal Dead Zone (TDZ): tính ratio trước khi gán speedup
  const ratio = timeWithIndex > 0 ? timeNoIndex / timeWithIndex : 1;
  const speedup = ratio.toFixed(ratio >= 10 ? 0 : 1);

  const card = document.createElement('article');
  card.className = 'result-card';
  card.innerHTML = `
    <div class="result-header">
      <div class="result-title-group">
        <h3>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
          </svg>
          ${esc(r.name)}
        </h3>
        <div class="result-meta-badge">
          Dữ liệu: <strong>${r.n.toLocaleString('vi-VN')} dòng</strong> · Kết quả trả về: <strong>~${r.A.rows} dòng</strong>
        </div>
      </div>
      <div class="speedup-badge">
        <span class="speedup-val">${speedup}×</span>
        <span class="speedup-lbl">Nhanh hơn</span>
      </div>
    </div>

    <div class="sql-box">${esc(r.sql)}</div>

    <div class="benchmark-visualizer">
      <div class="bench-row">
        <span class="bench-label">Không Index (Full Scan)</span>
        <div class="bar-track">
          <div class="bar-fill danger" style="width: ${widthNoIndex}"></div>
        </div>
        <span class="bench-time danger">${fmtTime(timeNoIndex)}</span>
      </div>
      <div class="bench-row">
        <span class="bench-label">Có B-tree Index</span>
        <div class="bar-track">
          <div class="bar-fill success" style="width: ${widthWithIndex}"></div>
        </div>
        <span class="bench-time success">${fmtTime(timeWithIndex)}</span>
      </div>
    </div>

    <div class="result-stats-row">
      <div class="stat-item">
        <span>Thời gian build index:</span>
        <strong>${fmtTime(r.build_ms)}</strong>
      </div>
      <div class="stat-item">
        <span>Dung lượng chỉ mục:</span>
        <strong>${fmtBytes(r.index_bytes)}</strong>
      </div>
      <div class="stat-item">
        <span>Độ chính xác:</span>
        <strong>Lặp ${r.A.n} &amp; ${r.B.n} lần (đã bù trừ round-trip)</strong>
      </div>
    </div>

    <div class="explain-grid">
      <div class="explain-col">
        <h4>Kế hoạch thực thi (EXPLAIN khi chưa đánh index)</h4>
        <pre>${esc(r.planA)}</pre>
      </div>
      <div class="explain-col">
        <h4>Kế hoạch thực thi (EXPLAIN với B-Tree Index)</h4>
        <pre>${esc(r.planB)}</pre>
      </div>
    </div>
  `;

  $('#resultsList').prepend(card);
}

// 3b. Render kết quả Bitmap Index (so sánh 3 phương án)
function renderBitmapResult(r) {
  const emptyState = $('#emptyState');
  if (emptyState) emptyState.remove();
  runCount++;
  $('#resultsCount').textContent = `${runCount} lần chạy kiểm thử`;

  const rows = [
    ['Không Index (Full Scan)', r.A.ms, 'danger'],
    ['B-tree (2 index đơn cột)', r.B.ms, 'warn'],
    ['Bitmap Index (mô phỏng)', r.C.ms, 'success']
  ];
  const max = Math.max(...rows.map((x) => x[1]));
  const bars = rows.map(([label, ms, cls]) => `
    <div class="bm-row">
      <span class="bm-label">${label}</span>
      <div class="bm-track"><div class="bm-bar ${cls}" style="width:${Math.max(1, (ms / max) * 100).toFixed(1)}%"></div></div>
      <span class="bm-time">${fmtTime(ms)}</span>
    </div>`).join('');

  const x = (a, b) => { const v = a / b; return v.toFixed(v >= 10 ? 0 : 1) + '×'; };
  const ok = r.verify.sql === r.verify.bm;

  const d = r.demo;
  const matrix = `
    <table class="bm-matrix">
      <tr><th>Dòng (id)</th>${d.ids.map((i) => `<th>${i}</th>`).join('')}</tr>
      <tr class="bm-real"><th>genre thực tế</th>${d.genre.map((g) => `<td title="${esc(g)}">${esc(g.slice(0, 3))}.</td>`).join('')}</tr>
      ${Object.entries(d.bitmaps).map(([g, bits]) =>
        `<tr><th>${esc(g)}</th>${bits.map((b) => `<td class="${b ? 'bit1' : 'bit0'}">${b}</td>`).join('')}</tr>`).join('')}
    </table>`;

  const card = `
    <table class="bm-card-table">
      <tr><th>Cột</th><th>Số giá trị phân biệt</th><th>Kích thước bitmap (ước tính)</th><th>Đánh giá</th></tr>
      ${r.card.map((c) => `<tr><td>${esc(c.col)}</td><td>${c.distinct.toLocaleString('vi-VN')}</td><td>${fmtBytes(c.est_bytes)}</td>
        <td>${c.distinct <= 100 ? '<span class="ok">Phù hợp (low cardinality)</span>' : '<span class="bad">Không phù hợp (quá nhiều bitmap)</span>'}</td></tr>`).join('')}
    </table>`;

  const el = document.createElement('article');
  el.className = 'result-card';
  el.innerHTML = `
    <div class="result-header">
      <div class="result-title-group">
        <h3>${esc(r.name)}</h3>
        <div class="result-meta-badge">Dữ liệu: <strong>${r.n.toLocaleString('vi-VN')} dòng</strong> · Kết quả: <strong>${r.verify.bm.toLocaleString('vi-VN')} dòng</strong>
          · Kiểm chứng SQL = Bitmap: <strong>${ok ? '✓ khớp' : '✗ lệch'}</strong></div>
      </div>
      <div class="speedup-badge"><span class="speedup-val">${x(r.A.ms, r.C.ms)}</span><span class="speedup-lbl">so với Full Scan</span></div>
    </div>
    <div class="sql-box">${esc(r.sql)}</div>
    <div class="bm-bars">${bars}</div>
    <p class="bm-note">Bitmap nhanh hơn B-tree <strong>${x(r.B.ms, r.C.ms)}</strong>. Thời gian bitmap gồm cả nạp bitmap từ bảng lưu trữ.</p>

    <div class="result-stats-row">
      <div class="stat-item"><span>Build B-tree (2 index):</span><strong>${fmtTime(r.build_bt_ms)}</strong></div>
      <div class="stat-item"><span>Build Bitmap:</span><strong>${fmtTime(r.build_bm_ms)}</strong></div>
      <div class="stat-item"><span>Dung lượng B-tree:</span><strong>${fmtBytes(r.bt_bytes)}</strong></div>
      <div class="stat-item"><span>Dung lượng Bitmap (thô / nén gzip):</span><strong>${fmtBytes(r.bm_raw_bytes)} / ${fmtBytes(r.bm_gz_bytes)}</strong></div>
    </div>

    <h4 class="bm-h">Cấu trúc bitmap của cột genre (16 dòng đầu)</h4>
    <div class="bm-scroll">${matrix}</div>
    <h4 class="bm-h">Độ phân biệt (cardinality) quyết định bitmap có đáng dùng không</h4>
    <div class="bm-scroll">${card}</div>

    <div class="explain-grid">
      <div class="explain-col"><h4>EXPLAIN – Full Scan</h4><pre>${esc(r.planA)}</pre></div>
      <div class="explain-col"><h4>EXPLAIN – B-tree</h4><pre>${esc(r.planB)}</pre></div>
      <div class="explain-col"><h4>Kế hoạch – Bitmap</h4><pre>${esc(r.planC)}</pre></div>
    </div>`;
  $('#resultsList').prepend(el);
}

// Xóa lịch sử kết quả
function clearResults() {
  runCount = 0;
  $('#resultsCount').textContent = 'Chưa có lượt chạy nào';
  $('#resultsList').innerHTML = `
    <div class="empty-state" id="emptyState">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M3 3v18h18"></path>
        <path d="m19 9-5 5-4-4-3 3"></path>
      </svg>
      <p>Chưa có dữ liệu đo lường.</p>
      <span>Hãy bấm nút <strong>"Chạy kiểm thử so sánh"</strong> để đối chiếu hiệu năng giữa Full Table Scan và B-tree Index.</span>
    </div>
  `;
}

// Khởi chạy ứng dụng
async function init() {
  $('#genBtn').onclick = generateData;
  $('#runBtn').onclick = runBenchmark;
  $('#clearBtn').onclick = clearResults;
  $('#algoSelect').onchange = updateSelectedAlgorithm;

  // Render danh sách thuật toán vào dropdown
  $('#algoSelect').innerHTML = Object.entries(ALGORITHMS)
    .map(
      ([key, algo]) =>
        `<option value="${key}">${esc(algo.name)} ${algo.ready ? '(Đang hoạt động)' : '(Chưa kích hoạt)'}</option>`
    )
    .join('');

  // Đọc config.json nếu có
  try {
    const configRes = await fetch('config.json');
    if (configRes.ok) {
      const extCfg = await configRes.json();
      CONFIG = { ...CONFIG, ...extCfg };
    }
  } catch (_) {}

  // Lấy dữ liệu meta từ backend
  try {
    const meta = await api('meta');
    ROWS = meta.rows || 0;
    BACKEND_SCENARIOS = meta.scenarios || [];
    BITMAP_SCENARIOS = meta.bitmap_scenarios || [];

    // Cập nhật trạng thái DB và giao diện theo thuật toán đang chọn
    if (ROWS > 0) {
      updateDbBadge('ready', `${ROWS.toLocaleString('vi-VN')} dòng sẵn sàng`);
      setStatus('#genStatus', `Bảng hiện có ${ROWS.toLocaleString('vi-VN')} dòng sách.`);
    } else {
      updateDbBadge('ready', '0 dòng sách');
      setStatus('#genStatus', 'Chưa có dữ liệu. Hãy bấm "Tạo lại dữ liệu mẫu".');
    }

    updateSelectedAlgorithm();
    $('#genBtn').disabled = false;
  } catch (err) {
    updateDbBadge('error', 'Mất kết nối API');
    setStatus('#genStatus', 'Không kết nối được: ' + err.message, 'err');
    setStatus('#runStatus', 'Không thể chạy khi chưa kết nối API.', 'err');
  }
}

init();
