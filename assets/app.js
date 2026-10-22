/* SIM-DOKIM 3.0 — fitur bantu di sisi browser */
(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const fmt = n => Number(n).toLocaleString('id-ID');
  let SIM = { dok: [], idle: 1800 };
  try { SIM = Object.assign(SIM, JSON.parse($('#sim-data')?.textContent || '{}')); } catch (e) { /* abaikan */ }

  /* ---------- Menu samping (ponsel) ---------- */
  $$('[data-toggle-side]').forEach(b => b.addEventListener('click', () => document.body.classList.toggle('side-open')));

  /* ---------- Kembali ke atas (footer) ---------- */
  $$('[data-scroll-top]').forEach(b => b.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' })));

  /* ---------- Pesan: bisa ditutup, pesan sukses hilang sendiri ---------- */
  $$('.flash').forEach(f => {
    $('button', f)?.addEventListener('click', () => f.remove());
    if (f.classList.contains('ok')) setTimeout(() => { f.classList.add('hide'); setTimeout(() => f.remove(), 400); }, 7000);
  });

  /* ---------- Konfirmasi sebelum aksi penting ---------- */
  document.addEventListener('submit', e => {
    const msg = e.target.dataset.confirm;
    if (msg && !confirm(msg)) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);

  /* ---------- Hitung mundur sesi + tombol perpanjang ---------- */
  const sesi = $('#sesi');
  if (sesi) {
    const out = $('b', sesi), ext = $('button', sesi);
    let end = Date.now() + SIM.idle * 1000;
    const tick = () => {
      const s = Math.max(0, Math.round((end - Date.now()) / 1000));
      out.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
      sesi.classList.toggle('warn', s <= 120);
      ext.hidden = s > 300;
      if (s <= 0) location.href = 'login.php?exp=1';
    };
    ext.addEventListener('click', async () => {
      const r = await fetch('ping.php', { credentials: 'same-origin', cache: 'no-store' }).catch(() => null);
      if (r && r.status === 204) { end = Date.now() + SIM.idle * 1000; tick(); }
      else location.href = 'login.php?exp=1';
    });
    tick(); setInterval(tick, 1000);
  }

  /* ---------- Pencarian cepat (Ctrl+K atau /) ---------- */
  const pal = $('#palette');
  if (pal && pal.showModal) {
    const q = $('#pal-q'), list = $('#pal-list');
    const all = [
      { t: 'Catat penerimaan baru', s: 'Aksi', h: 'penerimaan.php#form' },
      { t: 'Catat pengeluaran baru', s: 'Aksi', h: 'pengeluaran.php#form' },
      { t: 'Cetak lembar opname', s: 'Aksi', h: 'opname.php' },
      ...$$('.side nav a').map(a => ({ t: a.dataset.label, s: 'Menu', h: a.getAttribute('href') })),
      { t: 'Akun saya & ganti password', s: 'Menu', h: 'akun.php' },
      ...SIM.dok.map(d => ({ t: d.nama, s: d.kode + ' · stok ' + fmt(d.stok), h: 'stok_detail.php?id=' + d.id, k: d.kode })),
    ];
    let items = [], sel = 0;
    const mark = () => $$('li[data-i]', list).forEach(li => {
      const on = +li.dataset.i === sel;
      li.classList.toggle('on', on);
      li.setAttribute('aria-selected', on);
      if (on) li.scrollIntoView({ block: 'nearest' });
    });
    const render = () => {
      const k = q.value.trim().toLowerCase();
      items = k ? all.filter(i => (i.t + ' ' + (i.k || '') + ' ' + i.s).toLowerCase().includes(k)) : all;
      items = items.slice(0, 40);
      sel = 0;
      list.replaceChildren();
      if (!items.length) {
        const li = document.createElement('li'); li.className = 'none';
        li.textContent = 'Tidak ada dokumen atau menu yang cocok dengan "' + q.value + '"';
        list.append(li); return;
      }
      items.forEach((it, n) => {
        const li = document.createElement('li'), a = document.createElement('span'), b = document.createElement('small');
        li.dataset.i = n; li.setAttribute('role', 'option');
        a.textContent = it.t; b.textContent = it.s;
        li.append(a, b);
        li.addEventListener('click', () => { location.href = it.h; });
        li.addEventListener('mousemove', () => { if (sel !== n) { sel = n; mark(); } });
        list.append(li);
      });
      mark();
    };
    const open = () => { q.value = ''; render(); pal.showModal(); q.focus(); };
    $$('[data-palette]').forEach(b => b.addEventListener('click', open));
    q.addEventListener('input', render);
    q.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, items.length - 1); mark(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, 0); mark(); }
      else if (e.key === 'Enter' && items[sel]) { e.preventDefault(); location.href = items[sel].h; }
    });
    pal.addEventListener('click', e => { if (e.target === pal) pal.close(); });
    document.addEventListener('keydown', e => {
      const t = document.activeElement, typing = t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable);
      if (((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') || (e.key === '/' && !typing)) {
        e.preventDefault();
        pal.open ? pal.close() : open();
      }
    });
  }

  /* ---------- Tabel: urutkan kolom, saring teks & status ---------- */
  const toNum = s => { const t = String(s).replace(/[^\d,\-]/g, '').replace(',', '.'); return t === '' || t === '-' ? NaN : parseFloat(t); };
  $$('th[data-sort]').forEach(th => {
    th.tabIndex = 0;
    const run = () => {
      const tr = th.parentNode, tb = th.closest('table').tBodies[0], idx = [...tr.children].indexOf(th);
      const dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
      $$('th[aria-sort]', tr).forEach(x => x.removeAttribute('aria-sort'));
      th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
      const val = r => { const td = r.children[idx]; return td ? (td.dataset.v ?? td.textContent.trim()) : ''; };
      const rows = [...tb.rows].filter(r => !r.classList.contains('empty-row'));
      rows.sort((a, b) => {
        const x = val(a), y = val(b);
        if (th.dataset.sort === 'num') {
          const nx = toNum(x), ny = toNum(y);
          return ((isNaN(nx) ? -1e18 : nx) - (isNaN(ny) ? -1e18 : ny)) * dir;
        }
        return x.localeCompare(y, 'id', { numeric: true, sensitivity: 'base' }) * dir;
      });
      tb.append(...rows);
    };
    th.addEventListener('click', run);
    th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); run(); } });
  });

  const applyFilter = table => {
    const k = (table._q || '').toLowerCase(), st = table._st || '';
    let n = 0;
    [...table.tBodies[0].rows].forEach(r => {
      if (r.classList.contains('empty-row')) return;
      const ok = (!k || r.textContent.toLowerCase().includes(k)) && (!st || r.dataset.status === st);
      r.hidden = !ok; if (ok) n++;
    });
    $$('[data-count="#' + table.id + '"]').forEach(c => { c.textContent = fmt(n); });
  };
  $$('[data-filter]').forEach(inp => {
    const t = $(inp.dataset.filter); if (!t) return;
    inp.addEventListener('input', () => { t._q = inp.value; applyFilter(t); });
  });
  $$('[data-chips]').forEach(g => {
    const t = $(g.dataset.chips); if (!t) return;
    g.addEventListener('click', e => {
      const b = e.target.closest('.chip'); if (!b) return;
      $$('.chip', g).forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); });
      t._st = b.dataset.v; applyFilter(t);
    });
  });

  /* Centang semua baris yang terlihat */
  $$('[data-checkall]').forEach(c => c.addEventListener('change', () => {
    $$(c.dataset.checkall + ' tbody input[type=checkbox]').forEach(x => { if (!x.closest('tr').hidden) x.checked = c.checked; });
  }));

  /* Toggle kelas (mis. hanya tampilkan hari bermutasi) */
  $$('[data-toggle-class]').forEach(cb => {
    const [target, cls] = cb.dataset.toggleClass.split('|');
    const f = () => $(target)?.classList.toggle(cls, cb.checked);
    cb.addEventListener('change', f); f();
  });

  /* Tombol aktif hanya kalau teks konfirmasi diketik persis sama (zona berbahaya) */
  $$('[data-match-btn]').forEach(btn => {
    const inp = $(btn.dataset.matchBtn);
    if (!inp) return;
    const check = () => { btn.disabled = inp.value !== btn.dataset.match; };
    inp.addEventListener('input', check);
    check();
  });

  /* Tampilkan/sembunyikan password */
  $$('[data-showpw]').forEach(cb => cb.addEventListener('change', () => {
    $$('input.pw', cb.form).forEach(i => { i.type = cb.checked ? 'text' : 'password'; });
  }));

  /* ---------- Formulir transaksi: info stok, seri otomatis, cek stok ---------- */
  const tf = $('form[data-trx]');
  if (tf) {
    const mode = tf.dataset.trx;                              // in | out
    const sel = tf.elements.dokumen_id, jml = tf.elements.jumlah;
    const sa = tf.elements.seri_awal, sz = tf.elements.seri_akhir, btn = $('button[type=submit]', tf);
    const o = k => $('[data-o="' + k + '"]', tf);
    const byId = Object.fromEntries(SIM.dok.map(d => [String(d.id), d]));
    // Naikkan bagian angka di akhir nomor seri, pertahankan awalan & nol di depan: A00001 + 9999 = A10000
    const step = (s, k) => {
      const m = /^(.*?)(\d+)$/.exec(String(s || '').trim()); if (!m) return '';
      const n = parseInt(m[2], 10) + k; if (n < 0) return '';
      return m[1] + String(n).padStart(m[2].length, '0');
    };
    [sa, sz].forEach(i => { if (i.value) i.dataset.manual = '1'; i.addEventListener('input', () => { i.dataset.manual = i.value ? '1' : ''; }); });

    const update = fromDok => {
      const d = byId[sel.value], j = parseInt(jml.value, 10) || 0;
      const last = d ? (mode === 'in' ? d.last_in : d.last_out) : null;
      if (fromDok && d && !sa.dataset.manual) sa.value = last ? step(last, 1) : '';
      o('last').textContent = !d ? '' : last ? 'Melanjutkan dari seri terakhir ' + last + '.' : 'Belum ada riwayat seri untuk dokumen ini.';
      if (!sz.dataset.manual) sz.value = j > 0 ? step(sa.value, j - 1) : '';

      const stok = d ? +d.stok : 0, after = mode === 'in' ? stok + j : stok - j;
      o('nama').textContent = d ? d.nama : '—';
      o('stok').textContent = d ? fmt(stok) + ' ' + d.satuan.toLowerCase() : '—';
      o('after').textContent = d && j ? fmt(after) : '—';
      o('after').classList.toggle('neg', !!d && after < 0);
      o('seri').textContent = sa.value && sz.value ? sa.value + ' s.d. ' + sz.value : '—';
      const bad = mode === 'out' && d && j > stok;
      o('warn').hidden = !bad;
      if (bad) o('warn').textContent = 'Jumlah melebihi stok yang tersedia (' + fmt(stok) + '). Kurangi jumlah atau catat penerimaan dulu.';
      btn.disabled = !!bad;
    };
    sel.addEventListener('change', () => update(true));
    [jml, sa].forEach(i => i.addEventListener('input', () => update(false)));
    sz.addEventListener('input', () => update(false));
    tf.addEventListener('submit', () => setTimeout(() => { btn.disabled = true; btn.textContent = 'Menyimpan…'; }));
    update(!!sel.value);
  }

  /* ---------- Lembar kerja opname ---------- */
  const op = $('#opname');
  if (op) {
    const KEY = 'simdokim-opname-' + (op.dataset.tgl || '');
    let draft = {};
    try { draft = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { draft = {}; }
    const save = () => { try { localStorage.setItem(KEY, JSON.stringify(draft)); } catch (e) { /* penyimpanan browser tidak tersedia */ } };
    const ins = $$('input.op-in', op), kets = $$('input.op-ket', op);
    const calc = () => {
      let cek = 0, beda = 0, nilai = 0;
      ins.forEach(i => {
        const cell = $('[data-sel]', i.closest('tr'));
        if (i.value === '') { cell.textContent = '—'; cell.className = 'num muted'; return; }
        cek++;
        const s = (parseInt(i.value, 10) || 0) - parseInt(i.dataset.sys, 10);
        if (s) beda++;
        nilai += s * (+i.dataset.harga || 0);
        cell.textContent = (s > 0 ? '+' : '') + fmt(s);
        cell.className = 'num ' + (s > 0 ? 'sel-plus' : s < 0 ? 'sel-min' : 'muted');
      });
      $('#op-cek').textContent = fmt(cek);
      $('#op-beda').textContent = fmt(beda);
      $('#op-nilai').textContent = (nilai < 0 ? '−' : '') + 'Rp ' + fmt(Math.abs(nilai));
    };
    [...ins, ...kets].forEach((i, n, arr) => {
      if (draft[i.name] !== undefined) i.value = draft[i.name];
      i.addEventListener('input', () => { draft[i.name] = i.value; save(); calc(); });
    });
    ins.forEach((i, n) => i.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); (ins[n + (e.shiftKey ? -1 : 1)] || i).focus(); }
    }));
    $('#op-isi')?.addEventListener('click', () => {
      ins.forEach(i => { if (i.value === '') { i.value = i.dataset.sys; draft[i.name] = i.value; } });
      save(); calc();
    });
    $('#op-reset')?.addEventListener('click', () => {
      if (!confirm('Kosongkan semua isian stok fisik dan keterangan?')) return;
      [...ins, ...kets].forEach(i => { i.value = ''; });
      draft = {}; save(); calc();
    });
    calc();
  }
})();