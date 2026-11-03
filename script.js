// Client logic for MySQL Indexing Demo
const $ = (s) => document.querySelector(s);
const esc = (s) =>
  String(s).replace(
    /[&<>]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])
  );
const fmt = (ms) =>
  ms >= 1 ? ms.toFixed(1) + ' ms' : (ms * 1000).toFixed(0) + ' µs';
const fmtB = (b) =>
  b >= 1048576
    ? (b / 1048576).toFixed(1) + ' MB'
    : (b / 1024).toFixed(0) + ' KB';

let ALGOS = {};
let ROWS = 0;
let CONFIG = {
  apiEndpoint: 'api.php',
  chunkSize: 50000,
  dataSizes: [
    { value: 100000, label: '100.000' },
    { value: 500000, label: '500.000', selected: true },
    { value: 1000000, label: '1.000.000' }
  ]
};

function status(text, isError = false) {
  const el = $('#status');
  el.textContent = text;
  el.className = isError ? 'err' : '';
}

function busy(isBusy) {
  $('#gen').disabled = isBusy;
  $('#run').disabled = isBusy || !ROWS;
}

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
    throw new Error(
      `${endpoint} không trả về JSON. Kiểm tra Apache/PHP đã chạy và bạn mở trang qua http://localhost/...`
    );
  }

  if (json.error) {
    throw new Error(json.error);
  }
  return json;
}

function loadAlgo() {
  const algo = ALGOS[$('#algo').value];
  if (!algo) return;
  $('#info').innerHTML = algo.info || '';
  $('#scn').innerHTML = (algo.scenarios || [])
    .map((s) => `<option value="${s.key}">${esc(s.name)}</option>`)
    .join('');
}

async function gen() {
  const n = +$('#size').value;
  const chunkSize = CONFIG.chunkSize || 50000;
  busy(true);

  try {
    await api('reset');
    ROWS = 0;
    for (let s = 1; s <= n; s += chunkSize) {
      const to = Math.min(s + chunkSize - 1, n);
      status(
        `Đang tạo dữ liệu… ${to.toLocaleString('vi-VN')} / ${n.toLocaleString('vi-VN')}`
      );
      await api('gen', { from: s, to });
    }
    ROWS = n;
    status(
      `Đã tạo ${n.toLocaleString('vi-VN')} cuốn sách (chưa có chỉ mục nào ngoài khoá chính).`
    );
  } catch (e) {
    status(e.message, true);
  }
  busy(false);
}

async function run() {
  busy(true);
  status('Đang đo: không chỉ mục → tạo chỉ mục → đo lại (có thể mất vài chục giây)…');

  try {
    const r = await api('run', {
      algo: $('#algo').value,
      scenario: $('#scn').value
    });

    const max = Math.max(r.A.ms, r.B.ms);
    const w = (x) => Math.max(0.5, (x / max) * 100).toFixed(2) + '%';
    const speedup = r.A.ms / r.B.ms;

    const el = document.createElement('div');
    el.className = 'card';
    el.innerHTML = `
      <b>${esc(r.name)}</b> <span class="meta">· ${r.n.toLocaleString('vi-VN')} dòng · ~${r.A.rows} dòng kết quả</span>
      <pre>${esc(r.sql)}</pre>
      <div class="line"><span>Không chỉ mục</span><div class="bar" style="width:${w(r.A.ms)};background:var(--bad)">${fmt(r.A.ms)}</div></div>
      <div class="line"><span>Có B-tree</span><div class="bar" style="width:${w(r.B.ms)};background:var(--good)">${fmt(r.B.ms)}</div></div>
      <div class="big">Nhanh hơn ${speedup.toFixed(speedup >= 10 ? 0 : 1)}×</div>
      <div class="meta">Tạo chỉ mục: ${fmt(r.build_ms)} · Dung lượng chỉ mục: ${fmtB(r.index_bytes)} · TB của ${r.A.n} và ${r.B.n} lần chạy (đã gồm round-trip PHP↔MySQL, như nhau cho cả hai).</div>
      <div class="cols" style="margin-top:10px">
        <div><span class="meta">EXPLAIN – không chỉ mục</span><pre>${esc(r.planA)}</pre></div>
        <div><span class="meta">EXPLAIN – có chỉ mục</span><pre>${esc(r.planB)}</pre></div>
      </div>`;

    $('#results').prepend(el);
    status('Xong. Chỉ mục đã được xoá để bạn chạy lại hoặc thử truy vấn khác.');
  } catch (e) {
    status(e.message, true);
  }
  busy(false);
}

function initEventHandlers() {
  $('#algo').onchange = loadAlgo;
  $('#gen').onclick = gen;
  $('#run').onclick = run;
}

async function init() {
  initEventHandlers();

  // Load external config.json if available
  try {
    const configRes = await fetch('config.json');
    if (configRes.ok) {
      const externalConfig = await configRes.json();
      CONFIG = { ...CONFIG, ...externalConfig };
      if (Array.isArray(CONFIG.dataSizes) && CONFIG.dataSizes.length > 0) {
        $('#size').innerHTML = CONFIG.dataSizes
          .map(
            (d) =>
              `<option value="${d.value}"${d.selected ? ' selected' : ''}>${esc(d.label)}</option>`
          )
          .join('');
      }
    }
  } catch (_) {
    // Keep fallback CONFIG if config.json cannot be fetched
  }

  // Fetch metadata from backend
  try {
    const m = await api('meta');
    ALGOS = m.algos;
    ROWS = m.rows;
    $('#algo').innerHTML = Object.entries(ALGOS)
      .map(
        ([k, a]) =>
          `<option value="${k}"${a.ready ? '' : ' disabled'}>${esc(a.label)}${a.ready ? '' : ' (sắp có)'}</option>`
      )
      .join('');
    loadAlgo();
    busy(false);
    status(
      ROWS
        ? `Đã có sẵn ${ROWS.toLocaleString('vi-VN')} dòng. Có thể chạy luôn hoặc tạo lại dữ liệu.`
        : 'Sẵn sàng. Bấm “Tạo dữ liệu” trước.'
    );
  } catch (e) {
    status(e.message, true);
  }
}

// Start application
init();
