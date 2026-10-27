// Widget chat AI (pojok kanan bawah).
// - Percakapan disimpan di server (per pengguna) → tetap ada saat pindah halaman/tab/perangkat.
// - Jawaban AI dirender rapi (tebal, poin-poin, judul) dan tabel hasil analisis punya tombol unduh Excel/CSV.
// - Tab yang terbuka bersamaan ikut sinkron lewat localStorage.
// - Grafik digambar sebagai SVG (tanpa pustaka luar) dan bisa diunduh PNG; berkas .xlsx/.csv bisa dilampirkan untuk diperiksa AI.
(() => {
  const root = document.getElementById('chat-ai');
  if (!root) return;

  const toggle = document.getElementById('chat-ai-toggle');
  const panel = document.getElementById('chat-ai-panel');
  const body = document.getElementById('chat-ai-body');
  const form = document.getElementById('chat-ai-form');
  const input = document.getElementById('chat-ai-input');
  const clearBtn = document.getElementById('chat-ai-clear');
  const sendBtn = form.querySelector('.chat-ai-send');
  const head = panel.querySelector('.chat-ai-head');

  let csrf = '';
  try { csrf = (JSON.parse(document.getElementById('sim-data').textContent) || {}).csrf || ''; } catch (e) {}

  /* ---------- penyimpanan ringan di browser (boleh gagal) ---------- */
  const K = { id: 'simdokim.chat.id', buka: 'simdokim.chat.buka', lebar: 'simdokim.chat.lebar', draf: 'simdokim.chat.draf', tick: 'simdokim.chat.tick' };
  const simpanLokal = (k, v) => { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, String(v)); } catch (e) {} };
  const bacaLokal = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };

  let konvId = parseInt(bacaLokal(K.id) || '0', 10) || 0;
  let sedangKirim = false;
  let tampilRiwayat = false;
  let riwayatLokal = [];      // dipakai hanya kalau server tidak bisa menyimpan percakapan
  let simpanDiServer = true;

  /* ---------- gaya tambahan (disuntik dari sini supaya cukup satu berkas) ---------- */
  const css = `
.chat-ai-panel.lebar{width:min(820px,calc(100vw - 36px));height:min(700px,calc(100vh - 110px))}
.chat-ai-head .chat-ai-tools{display:flex;gap:6px;flex:0 0 auto}
.chat-ai-tools button{border:0;background:var(--sunk);color:var(--ink-2);font:500 12px var(--sans);padding:5px 9px;border-radius:8px;cursor:pointer;transition:var(--transition)}
.chat-ai-tools button:hover{background:var(--line-2)}
.chat-ai-tools button[aria-pressed="true"]{background:var(--navy-light);color:var(--navy)}
.chat-ai-msg.md{white-space:normal;max-width:94%}
.chat-ai-msg.md>*:first-child{margin-top:0}.chat-ai-msg.md>*:last-child{margin-bottom:0}
.chat-ai-msg.md p{margin:.45em 0}
.chat-ai-msg.md ul,.chat-ai-msg.md ol{margin:.4em 0;padding-left:1.25em}
.chat-ai-msg.md li{margin:.15em 0}
.chat-ai-msg.md h4{margin:.8em 0 .3em;font-size:13.5px;color:var(--navy)}
.chat-ai-msg.md code{font:12px var(--mono);background:var(--line-2);padding:1px 4px;border-radius:4px}
.chat-ai-msg.md strong{font-weight:600}
.chat-ai-tabel{align-self:stretch;border:1px solid var(--line-2);border-radius:12px;background:var(--surface);overflow:hidden;flex-shrink:0}
.chat-ai-tabel header{display:flex;align-items:center;gap:8px;padding:8px 10px;border-bottom:1px solid var(--line);background:var(--sunk)}
.chat-ai-tabel header b{flex:1;min-width:0;font-size:12.5px;color:var(--ink);line-height:1.35}
.chat-ai-tabel header a,.chat-ai-tabel header button{flex-shrink:0;border:1px solid var(--line-3);background:var(--surface);color:var(--navy);font:600 11.5px var(--sans);padding:4px 9px;border-radius:7px;cursor:pointer;text-decoration:none}
.chat-ai-tabel header a:hover,.chat-ai-tabel header button:hover{background:var(--navy-light)}
.chat-ai-scroll{overflow:auto;max-height:260px}
.chat-ai-tabel table{border-collapse:collapse;width:100%;font-size:12px}
.chat-ai-tabel th{position:sticky;top:0;background:var(--navy);color:#fff;font-weight:600;text-align:left;padding:6px 9px;white-space:nowrap}
.chat-ai-tabel td{padding:5px 9px;border-top:1px solid var(--line-2);white-space:nowrap}
.chat-ai-tabel td.n,.chat-ai-tabel th.n{text-align:right;font-variant-numeric:tabular-nums}
.chat-ai-tabel tr:nth-child(even) td{background:var(--sunk)}
.chat-ai-tabel tr.total td{font-weight:700;background:var(--navy-light);border-top:2px solid var(--navy-soft)}
.chat-ai-tabel footer{display:flex;justify-content:space-between;gap:8px;padding:6px 10px;border-top:1px solid var(--line);font-size:11.5px;color:var(--ink-3)}
.chat-ai-tabel footer button{border:0;background:none;color:var(--navy-2);font:600 11.5px var(--sans);cursor:pointer;padding:0}
.chat-ai-saran{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px}
.chat-ai-saran button{border:1px solid var(--line-3);background:var(--surface);color:var(--ink-2);font:12.3px var(--sans);padding:6px 10px;border-radius:999px;cursor:pointer;text-align:left;transition:var(--transition)}
.chat-ai-saran button:hover{background:var(--navy-light);border-color:var(--navy-soft);color:var(--navy)}
.chat-ai-msg.typing.lama{gap:8px}.chat-ai-msg.typing small{font-size:11.5px;color:var(--ink-3)}
.chat-ai-riwayat{flex:1;overflow-y:auto;padding:8px}
.chat-ai-riwayat[hidden],.chat-ai-body[hidden],.chat-ai-form[hidden]{display:none}
.chat-ai-riwayat .kosong{padding:24px 12px;text-align:center;color:var(--ink-3);font-size:13px}
.chat-ai-item{display:flex;align-items:center;gap:4px;border-radius:10px}
.chat-ai-item:hover{background:var(--sunk)}
.chat-ai-item.aktif{background:var(--navy-light)}
.chat-ai-item .buka{flex:1;min-width:0;text-align:left;border:0;background:none;padding:9px 10px;cursor:pointer;font:13px var(--sans);color:var(--ink)}
.chat-ai-item .buka span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chat-ai-item .buka small{display:block;font-size:11px;color:var(--ink-3);margin-top:2px}
.chat-ai-item .hapus{flex-shrink:0;border:0;background:none;color:var(--ink-3);width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:16px;line-height:1}
.chat-ai-item .hapus:hover{background:var(--bad-light);color:var(--bad)}

.chat-ai-grafik{align-self:stretch;border:1px solid var(--line-2);border-radius:12px;background:var(--surface);overflow:hidden;flex-shrink:0}
.chat-ai-grafik header{display:flex;align-items:center;gap:8px;padding:8px 10px;border-bottom:1px solid var(--line);background:var(--sunk)}
.chat-ai-grafik header b{flex:1;min-width:0;font-size:12.5px;color:var(--ink);line-height:1.35}
.chat-ai-grafik header button{flex-shrink:0;border:1px solid var(--line-3);background:var(--surface);color:var(--navy);font:600 11.5px var(--sans);padding:4px 9px;border-radius:7px;cursor:pointer}
.chat-ai-grafik header button:hover{background:var(--navy-light)}
.chat-ai-grafik .gambar{padding:8px 6px 4px}
.chat-ai-grafik svg{display:block;width:100%;height:auto;overflow:visible}
.chat-ai-grafik small{display:block;padding:0 10px 8px;color:var(--ink-3);font-size:11px}
.chat-ai-klip{flex-shrink:0;width:36px;height:36px;border-radius:10px;border:1px solid var(--line-2);background:var(--surface);color:var(--ink-2);display:grid;place-items:center;cursor:pointer;transition:var(--transition)}
.chat-ai-klip:hover{background:var(--navy-light);color:var(--navy)}
.chat-ai-klip:disabled{opacity:.45;cursor:not-allowed}
.chat-ai-chip{display:flex;align-items:center;gap:8px;margin:0 10px;padding:6px 8px 6px 10px;border:1px solid var(--navy-soft);background:var(--navy-light);color:var(--navy);border-radius:10px;font-size:12px;flex-shrink:0}
.chat-ai-chip[hidden]{display:none}
.chat-ai-chip span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chat-ai-chip button{border:0;background:none;color:var(--navy);font-size:16px;line-height:1;cursor:pointer;padding:2px 6px;border-radius:6px}
.chat-ai-chip button:hover{background:var(--navy-soft)}
.chat-ai-panel.seret{outline:2px dashed var(--navy-2);outline-offset:-6px}
@media (max-width:520px){.chat-ai-tools button:nth-child(2){display:none}.chat-ai-head small{display:none}.chat-ai-panel.lebar{width:calc(100vw - 20px)}.chat-ai-scroll{max-height:220px}}
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  /* ---------- tombol tambahan di kepala panel ---------- */
  const kecilSub = head.querySelector('small');
  if (kecilSub) kecilSub.textContent = 'Analisis, grafik, dan periksa berkas data';
  const tools = document.createElement('div'); tools.className = 'chat-ai-tools';
  const btnRiwayat = document.createElement('button'); btnRiwayat.type = 'button'; btnRiwayat.textContent = 'Riwayat'; btnRiwayat.setAttribute('aria-pressed', 'false'); btnRiwayat.title = 'Lihat percakapan sebelumnya';
  const btnLebar = document.createElement('button'); btnLebar.type = 'button'; btnLebar.textContent = 'Lebar'; btnLebar.title = 'Perlebar jendela chat agar tabel muat';
  clearBtn.replaceWith(tools);
  tools.append(btnRiwayat, btnLebar, clearBtn);

  const daftarEl = document.createElement('div'); daftarEl.className = 'chat-ai-riwayat'; daftarEl.hidden = true;
  body.after(daftarEl);

  /* ---------- util ---------- */
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const gulir = () => { body.scrollTop = body.scrollHeight; };
  const fmt = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });

  /** Markdown ringan & aman: semua teks di-escape dulu, baru pola yang dikenal diubah jadi tag. */
  const md = (teks) => {
    const inline = s => esc(s)
      .replace(/`([^`\n]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>')
      .replace(/(^|[^_\w])_([^_\n]+)_(?![_\w])/g, '$1<em>$2</em>');
    const out = []; let list = null;
    const tutup = () => { if (list) { out.push('</' + list + '>'); list = null; } };
    for (const baris of String(teks).replace(/\r/g, '').split('\n')) {
      let m;
      if ((m = baris.match(/^\s*[-*•]\s+(.*)$/))) { if (list !== 'ul') { tutup(); out.push('<ul>'); list = 'ul'; } out.push('<li>' + inline(m[1]) + '</li>'); }
      else if ((m = baris.match(/^\s*\d+[.)]\s+(.*)$/))) { if (list !== 'ol') { tutup(); out.push('<ol>'); list = 'ol'; } out.push('<li>' + inline(m[1]) + '</li>'); }
      else if ((m = baris.match(/^\s{0,3}#{1,4}\s+(.*)$/))) { tutup(); out.push('<h4>' + inline(m[1]) + '</h4>'); }
      else if (baris.trim() === '') { tutup(); }
      else if (/^\s*\|.*\|\s*$/.test(baris)) { tutup(); if (!/^\s*\|[\s:|-]+\|\s*$/.test(baris)) out.push('<p>' + inline(baris.trim().replace(/^\||\|$/g, '').split('|').map(x => x.trim()).join('  ·  ')) + '</p>'); }
      else { tutup(); out.push('<p>' + inline(baris) + '</p>'); }
    }
    tutup();
    return out.join('');
  };

  const tambahPesan = (teks, kelas, sebagaiMd = false) => {
    const div = document.createElement('div');
    div.className = 'chat-ai-msg ' + kelas + (sebagaiMd ? ' md' : '');
    if (sebagaiMd) div.innerHTML = md(teks); else div.textContent = teks;
    body.appendChild(div); gulir();
    return div;
  };

  /* ---------- tabel ---------- */
  const unduhCsvLokal = (t) => {
    const q = v => { v = v == null ? '' : String(v); if (/^[=+\-@\t\r]/.test(v) && isNaN(Number(v))) v = "'" + v; return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    const isi = '﻿' + [t.kolom, ...t.baris].map(r => r.map(q).join(';')).join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([isi], { type: 'text/csv;charset=utf-8' }));
    a.download = 'SIM-DOKIM_' + (t.judul || 'data').replace(/[^A-Za-z0-9]+/g, '_').slice(0, 40) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
  };

  const buatTabel = (t) => {
    const wrap = document.createElement('div'); wrap.className = 'chat-ai-tabel';
    const kolomAngka = t.kolom.map((_, i) => t.baris.length > 0 && t.baris.every(r => r[i] === null || r[i] === '' || typeof r[i] === 'number'));
    const BATAS = 50;
    const h = document.createElement('header');
    const jd = document.createElement('b'); jd.textContent = t.judul || 'Tabel'; h.appendChild(jd);
    if (t.pesan_id) {
      for (const [f, label] of [['xlsx', 'Excel'], ['csv', 'CSV']]) {
        const a = document.createElement('a');
        a.href = `chat_ekspor.php?pesan=${t.pesan_id}&tabel=${t.idx}&format=${f}`;
        a.textContent = label; a.title = 'Unduh sebagai ' + label; a.setAttribute('download', '');
        h.appendChild(a);
      }
    } else {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = 'CSV'; b.addEventListener('click', () => unduhCsvLokal(t)); h.appendChild(b);
    }
    const sc = document.createElement('div'); sc.className = 'chat-ai-scroll';
    const tb = document.createElement('table');
    tb.innerHTML = '<thead><tr>' + t.kolom.map((k, i) => `<th class="${kolomAngka[i] ? 'n' : ''}">${esc(k)}</th>`).join('') + '</tr></thead><tbody></tbody>';
    const tbody = tb.tBodies[0];
    const isiBaris = (dari, sampai) => {
      for (const r of t.baris.slice(dari, sampai)) {
        const tr = document.createElement('tr');
        if (String(r[0]).toUpperCase() === 'TOTAL') tr.className = 'total';
        tr.innerHTML = t.kolom.map((_, i) => {
          const v = r[i];
          const teks = v === null || v === undefined ? '' : (typeof v === 'number' ? fmt.format(v) : esc(v));
          return `<td class="${kolomAngka[i] ? 'n' : ''}">${teks}</td>`;
        }).join('');
        tbody.appendChild(tr);
      }
    };
    isiBaris(0, BATAS);
    sc.appendChild(tb);
    const f = document.createElement('footer');
    const info = document.createElement('span');
    const perbarui = () => { info.textContent = `${fmt.format(Math.min(tbody.rows.length, t.baris.length))} dari ${fmt.format(t.baris.length)} baris`; };
    perbarui(); f.appendChild(info);
    if (t.baris.length > BATAS) {
      const more = document.createElement('button'); more.type = 'button'; more.textContent = 'Tampilkan semua';
      more.addEventListener('click', () => { isiBaris(BATAS, t.baris.length); more.remove(); perbarui(); });
      f.appendChild(more);
    }
    wrap.append(h, sc, f);
    return wrap;
  };

  const tambahBalasan = (teks, tabel) => {
    for (const t of tabel || []) {
      try { if (t.grafik) body.appendChild(buatGrafik(t)); else if (t.kolom) body.appendChild(buatTabel(t)); }
      catch (e) { console.error('Gagal menampilkan tabel/grafik', e); tambahPesan('(Satu ' + (t.grafik ? 'grafik' : 'tabel') + ' gagal ditampilkan.)', 'err'); }
    }
    tambahPesan(teks, 'bot', true);
  };


  /* ---------- grafik (SVG) ---------- */
  const WARNA = ['#2a5490', '#c4922e', '#1a8a4a', '#d03027', '#7a5cc0', '#2a9db5', '#8a8f98', '#b5651d'];
  const kompak = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
  const FONT = 'font-family="IBM Plex Sans,system-ui,Segoe UI,Roboto,sans-serif"';
  const potong = (t, n) => { t = String(t); return t.length > n ? t.slice(0, n - 1) + '…' : t; };
  const skala = (lo, hi) => {
    if (hi === lo) { hi = lo + 1; }
    const kasar = (hi - lo) / 5, p = Math.pow(10, Math.floor(Math.log10(kasar))), f = kasar / p;
    const langkah = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
    const bawah = Math.floor(lo / langkah) * langkah, atas = Math.ceil(hi / langkah) * langkah;
    const tik = []; for (let v = bawah; v <= atas + langkah / 2; v += langkah) tik.push(Math.round(v / langkah) * langkah);
    return { bawah, atas, tik };
  };

  const svgGrafik = (g, denganJudul) => {
    const W = 640, n = g.label.length, banyakSeri = g.seri.length;
    const judulH = denganJudul ? 34 : 0, legH = banyakSeri > 1 && g.jenis !== 'pie' ? 24 : 0;
    let H = 300, isi = '';
    const legenda = (y) => g.seri.map((s, i) => `<rect x="${52 + i * 150}" y="${y}" width="10" height="10" rx="2" fill="${WARNA[i % WARNA.length]}"/><text x="${67 + i * 150}" y="${y + 9}" font-size="11" fill="#3d4452" ${FONT}>${esc(potong(s.nama, 18))}</text>`).join('');
    const gabungSeri = banyakSeri > 1;

    if (g.jenis === 'pie') {
      let data = g.label.map((l, i) => ({ l, v: Math.max(0, Number(g.seri[0].nilai[i]) || 0) })).filter(d => d.v > 0);
      if (data.length > 8) { const sisa = data.slice(7).reduce((a, d) => a + d.v, 0); data = data.slice(0, 7).concat([{ l: 'Lainnya', v: sisa }]); }
      const total = data.reduce((a, d) => a + d.v, 0) || 1;
      H = Math.max(260, data.length * 26 + 40);
      const cx = 140, cy = H / 2, R = 108, r = 60;
      let ang = -Math.PI / 2, irisan = '';
      data.forEach((d, i) => {
        const porsi = d.v / total, a2 = ang + porsi * Math.PI * 2, warna = WARNA[i % WARNA.length];
        const tip = `${d.l}: ${fmt.format(d.v)} (${(porsi * 100).toFixed(1)}%)`;
        if (porsi > 0.9999) irisan += `<path d="M${cx} ${cy - R}A${R} ${R} 0 1 1 ${cx - 0.01} ${cy - R}L${cx - 0.01} ${cy - r}A${r} ${r} 0 1 0 ${cx} ${cy - r}Z" fill="${warna}"><title>${esc(tip)}</title></path>`;
        else {
          const x1 = cx + R * Math.cos(ang), y1 = cy + R * Math.sin(ang), x2 = cx + R * Math.cos(a2), y2 = cy + R * Math.sin(a2);
          const x3 = cx + r * Math.cos(a2), y3 = cy + r * Math.sin(a2), x4 = cx + r * Math.cos(ang), y4 = cy + r * Math.sin(ang), besar = porsi > 0.5 ? 1 : 0;
          irisan += `<path d="M${x1} ${y1}A${R} ${R} 0 ${besar} 1 ${x2} ${y2}L${x3} ${y3}A${r} ${r} 0 ${besar} 0 ${x4} ${y4}Z" fill="${warna}" stroke="#fff" stroke-width="1.5"><title>${esc(tip)}</title></path>`;
        }
        isi += `<g><rect x="290" y="${24 + i * 26 - 10 + judulH}" width="12" height="12" rx="3" fill="${warna}"/><text x="310" y="${24 + i * 26 + judulH}" font-size="12" fill="#1a1d23" ${FONT}>${esc(potong(d.l, 26))}</text><text x="630" y="${24 + i * 26 + judulH}" font-size="12" text-anchor="end" fill="#3d4452" ${FONT}>${esc(kompak.format(d.v))} · ${porsi < 0.001 ? '&lt;0,1' : (porsi * 100).toFixed(1).replace('.', ',')}%</text></g>`;
        ang = a2;
      });
      isi = `<g transform="translate(0 ${judulH})">${irisan}<text x="${cx}" y="${cy - 2}" text-anchor="middle" font-size="16" font-weight="600" fill="#1a1d23" ${FONT}>${esc(kompak.format(total))}</text><text x="${cx}" y="${cy + 15}" text-anchor="middle" font-size="11" fill="#6b7280" ${FONT}>total</text></g>` + isi;
    } else if (g.jenis === 'batang_mendatar') {
      const baris = 20 * banyakSeri + 10, l = 140, r = 56;
      H = n * baris + 26;
      const semua = g.seri.flatMap(s => s.nilai.map(Number)), sk = skala(Math.min(0, ...semua), Math.max(0, ...semua));
      const x = v => l + (v - sk.bawah) / (sk.atas - sk.bawah) * (W - l - r);
      let grid = '';
      sk.tik.forEach(t => { grid += `<line x1="${x(t)}" x2="${x(t)}" y1="6" y2="${H - 20}" stroke="#e2e5ea"/><text x="${x(t)}" y="${H - 6}" font-size="10.5" text-anchor="middle" fill="#6b7280" ${FONT}>${esc(kompak.format(t))}</text>`; });
      g.label.forEach((lb, i) => {
        const y0 = 8 + i * baris;
        isi += `<text x="${l - 8}" y="${y0 + baris / 2 - 2}" font-size="11.5" text-anchor="end" fill="#1a1d23" ${FONT}><title>${esc(lb)}</title>${esc(potong(lb, 22))}</text>`;
        g.seri.forEach((s, j) => {
          const v = Number(s.nilai[i]) || 0, bw = 16, by = y0 + j * 20 + 1;
          isi += `<rect x="${Math.min(x(0), x(v))}" y="${by}" width="${Math.abs(x(v) - x(0))}" height="${bw}" rx="3" fill="${WARNA[j % WARNA.length]}"><title>${esc(lb + ' — ' + s.nama + ': ' + fmt.format(v))}</title></rect>`;
          if (n <= 24) isi += `<text x="${Math.max(x(v), x(0)) + 5}" y="${by + 12}" font-size="10.5" fill="#3d4452" ${FONT}>${esc(kompak.format(v))}</text>`;
        });
      });
      isi = `<g transform="translate(0 ${judulH + legH})">${grid}${isi}</g>`;
      H += legH;
    } else {   // batang tegak & garis
      const l = 54, r = 14, t = 14, panjang = Math.max(0, ...g.label.map(x => Math.min(x.length, 14)));
      const miring = n > 7 || panjang > 9, b = miring ? 18 + panjang * 4.6 : 30;
      const ph = H - t - b, pw = W - l - r;
      const semua = g.seri.flatMap(s => s.nilai.map(Number)), sk = skala(Math.min(0, ...semua), Math.max(0, ...semua));
      const y = v => t + ph - (v - sk.bawah) / (sk.atas - sk.bawah) * ph, band = pw / n;
      let grid = '';
      sk.tik.forEach(tk => { grid += `<line x1="${l}" x2="${W - r}" y1="${y(tk)}" y2="${y(tk)}" stroke="${tk === 0 ? '#9aa1ad' : '#e2e5ea'}"/><text x="${l - 6}" y="${y(tk) + 4}" font-size="10.5" text-anchor="end" fill="#6b7280" ${FONT}>${esc(kompak.format(tk))}</text>`; });
      const langkahLabel = Math.ceil(n / 24);
      g.label.forEach((lb, i) => {
        if (i % langkahLabel) return;
        const cx = l + band * (i + .5), ty = t + ph + 14;
        isi += miring
          ? `<text transform="translate(${cx + 3} ${ty}) rotate(-40)" font-size="10.5" text-anchor="end" fill="#3d4452" ${FONT}><title>${esc(lb)}</title>${esc(potong(lb, 14))}</text>`
          : `<text x="${cx}" y="${ty}" font-size="11" text-anchor="middle" fill="#3d4452" ${FONT}><title>${esc(lb)}</title>${esc(potong(lb, 14))}</text>`;
      });
      if (g.jenis === 'garis') {
        g.seri.forEach((s, j) => {
          const w = WARNA[j % WARNA.length];
          const titik = s.nilai.map((v, i) => [l + band * (i + .5), y(Number(v) || 0)]);
          isi += `<polyline points="${titik.map(p => p.join(',')).join(' ')}" fill="none" stroke="${w}" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>`;
          if (n <= 40) titik.forEach((p, i) => { isi += `<circle cx="${p[0]}" cy="${p[1]}" r="3.4" fill="#fff" stroke="${w}" stroke-width="2"><title>${esc(g.label[i] + ' — ' + s.nama + ': ' + fmt.format(Number(s.nilai[i]) || 0))}</title></circle>`; });
        });
      } else {
        const gw = band * .78, bw = gw / banyakSeri;
        g.seri.forEach((s, j) => s.nilai.forEach((v, i) => {
          v = Number(v) || 0;
          const bx = l + band * i + (band - gw) / 2 + j * bw, y0 = y(0), y1 = y(v);
          isi += `<rect x="${bx}" y="${Math.min(y0, y1)}" width="${Math.max(1, bw - 1)}" height="${Math.abs(y0 - y1)}" rx="2" fill="${WARNA[j % WARNA.length]}"><title>${esc(g.label[i] + ' — ' + s.nama + ': ' + fmt.format(v))}</title></rect>`;
          if (n <= 8 && banyakSeri === 1) isi += `<text x="${bx + bw / 2}" y="${(v >= 0 ? y1 - 4 : y1 + 12)}" font-size="10.5" text-anchor="middle" fill="#3d4452" ${FONT}>${esc(kompak.format(v))}</text>`;
        }));
      }
      isi = `<g transform="translate(0 ${judulH + legH})">${grid}${isi}</g>`;
      H += legH;
    }
    const judul = denganJudul ? `<text x="${W / 2}" y="22" text-anchor="middle" font-size="15" font-weight="600" fill="#1a1d23" ${FONT}>${esc(g.judul)}</text>` : '';
    const leg = legH ? `<g transform="translate(0 ${judulH + 4})">${legenda(0)}</g>` : '';
    return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H + judulH}" width="${W}" height="${H + judulH}" role="img" aria-label="${esc(g.judul)}"><rect width="100%" height="100%" fill="#fff"/>${judul}${leg}${isi}</svg>`, W, H: H + judulH };
  };

  const unduhPng = (g) => {
    const { svg, W, H } = svgGrafik(g, true);
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas'); c.width = W * 2; c.height = H * 2;
      const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(b => {
        const a = document.createElement('a'); a.href = URL.createObjectURL(b);
        a.download = 'SIM-DOKIM_' + (g.judul || 'grafik').replace(/[^A-Za-z0-9]+/g, '_').slice(0, 40) + '.png';
        document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
      }, 'image/png');
    };
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  };

  const buatGrafik = (g) => {
    const wrap = document.createElement('div'); wrap.className = 'chat-ai-grafik';
    const h = document.createElement('header');
    const jd = document.createElement('b'); jd.textContent = g.judul || 'Grafik';
    const btn = document.createElement('button'); btn.type = 'button'; btn.textContent = 'PNG'; btn.title = 'Unduh grafik sebagai gambar'; btn.addEventListener('click', () => unduhPng(g));
    h.append(jd, btn);
    const gb = document.createElement('div'); gb.className = 'gambar'; gb.innerHTML = svgGrafik(g, false).svg;
    wrap.append(h, gb);
    if (g.sumber) { const k = document.createElement('small'); k.textContent = 'Sumber: ' + g.sumber; wrap.appendChild(k); }
    return wrap;
  };

  /* ---------- tampilan awal & saran ---------- */
  const SARAN = [
    'Stok apa saja yang kritis dan perkiraan habisnya?',
    'Rekap pengeluaran per bulan tahun ini',
    'Buat grafik pengeluaran per bulan',
    'Tujuan pengeluaran terbanyak',
    'Bandingkan pemakaian tiap jenis dokumen',
  ];
  const tampilAwal = () => {
    body.innerHTML = '';
    tambahPesan('Halo! Aku bisa menganalisis stok, penerimaan, dan pengeluaran — lengkap dengan tabel (unduh Excel) dan grafik. Kamu juga bisa melampirkan berkas Excel/CSV lewat tombol klip untuk kuperiksa. Coba salah satu ini atau tulis sendiri:', 'bot');
    const s = document.createElement('div'); s.className = 'chat-ai-saran';
    for (const t of SARAN) {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = t;
      b.addEventListener('click', () => { kirim(t); });
      s.appendChild(b);
    }
    body.appendChild(s);
  };

  /* ---------- lampiran berkas ---------- */
  let berkasLampir = null;   // {id, nama, ...}
  const klip = document.createElement('button');
  klip.type = 'button'; klip.className = 'chat-ai-klip'; klip.title = 'Lampirkan berkas Excel/CSV untuk diperiksa AI'; klip.setAttribute('aria-label', 'Lampirkan berkas');
  klip.innerHTML = '<svg viewBox="0 0 20 20" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.5 9.2 9.4 15.3a3.6 3.6 0 0 1-5.1-5.1l6.4-6.4a2.4 2.4 0 0 1 3.4 3.4l-6.4 6.4a1.2 1.2 0 0 1-1.7-1.7l5.7-5.7"/></svg>';
  const pilih = document.createElement('input'); pilih.type = 'file'; pilih.accept = '.xlsx,.csv,.txt'; pilih.hidden = true;
  const chip = document.createElement('div'); chip.className = 'chat-ai-chip'; chip.hidden = true;
  const chipTeks = document.createElement('span');
  const chipX = document.createElement('button'); chipX.type = 'button'; chipX.textContent = '×'; chipX.title = 'Lepas lampiran'; chipX.setAttribute('aria-label', 'Lepas lampiran');
  chip.append(chipTeks, chipX);
  form.insertBefore(klip, input); form.appendChild(pilih); form.before(chip);
  input.required = false;

  const lepasBerkas = () => { berkasLampir = null; chip.hidden = true; pilih.value = ''; };
  chipX.addEventListener('click', lepasBerkas);
  klip.addEventListener('click', () => pilih.click());

  const unggah = async (file) => {
    if (!file || sedangKirim) return;
    if (!/\.(xlsx|csv|txt)$/i.test(file.name)) { tambahPesan('Jenis berkas tidak didukung. Pakai .xlsx atau .csv.', 'err'); return; }
    if (file.size > 3 * 1024 * 1024) { tambahPesan('Berkas terlalu besar (maks. 3 MB).', 'err'); return; }
    klip.disabled = true; chip.hidden = false; chipTeks.textContent = 'Membaca ' + file.name + '…';
    try {
      const fd = new FormData(); fd.append('csrf', csrf); fd.append('berkas', file);
      const res = await fetch('chat_unggah.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await res.json().catch(() => null);
      if (!res.ok || !j || j.error) { lepasBerkas(); tambahPesan((j && j.error) || 'Berkas gagal diunggah.', 'err'); return; }
      berkasLampir = j;
      chipTeks.textContent = '📎 ' + j.nama + ' — ' + fmt.format(j.jumlah_baris) + ' baris × ' + j.jumlah_kolom + ' kolom' + (j.terpotong ? ' (dipotong)' : '');
      input.placeholder = 'Tanya soal berkas ini, atau kirim kosong untuk diperiksa…'; input.focus();
    } catch (e) { lepasBerkas(); tambahPesan('Tidak bisa mengunggah berkas. Periksa koneksi internet.', 'err'); }
    finally { klip.disabled = false; pilih.value = ''; }
  };
  pilih.addEventListener('change', () => unggah(pilih.files[0]));
  panel.addEventListener('dragover', e => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); panel.classList.add('seret'); } });
  panel.addEventListener('dragleave', () => panel.classList.remove('seret'));
  panel.addEventListener('drop', e => { panel.classList.remove('seret'); if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); unggah(e.dataTransfer.files[0]); } });

  /* ---------- panggilan ke server ---------- */
  const api = async (data) => {
    const res = await fetch('chat_ai.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...data, csrf }),
    });
    const j = await res.json().catch(() => null);
    return { ok: res.ok && j && !j.error, status: res.status, data: j };
  };

  const tampilkanPercakapan = (pesan) => {
    body.innerHTML = '';
    if (!pesan.length) { tampilAwal(); return; }
    for (const p of pesan) {
      if (p.peran === 'user') tambahPesan(p.isi, 'user');
      else tambahBalasan(p.isi, p.tabel);
    }
    gulir();
  };

  const muat = async (id) => {
    const r = await api({ aksi: 'muat', id });
    if (!r.ok) {
      if (r.status === 404 || r.status === 401) { konvId = 0; simpanLokal(K.id, null); }
      tampilAwal(); return false;
    }
    konvId = r.data.id; simpanLokal(K.id, konvId);
    tampilkanPercakapan(r.data.pesan);
    return true;
  };

  const tampilkanMengetik = () => {
    const div = document.createElement('div');
    div.className = 'chat-ai-msg typing lama';
    div.innerHTML = '<span></span><span></span><span></span><small>Menganalisis data…</small>';
    body.appendChild(div); gulir();
    const t0 = Date.now();
    const kecil = div.querySelector('small');
    div.timer = setInterval(() => {
      const d = Math.round((Date.now() - t0) / 1000);
      kecil.textContent = d < 6 ? 'Menganalisis data…' : d < 20 ? `Mengumpulkan & menghitung data… ${d} dtk` : `Masih bekerja, analisis besar butuh waktu… ${d} dtk`;
    }, 1000);
    return div;
  };

  const kirim = async (teks) => {
    let pesan = (teks ?? input.value).trim();
    if (!pesan && berkasLampir) pesan = 'Tolong periksa berkas ini: ringkas isinya dan cek kualitas datanya.';
    if (!pesan || sedangKirim) return;
    const lampir = berkasLampir;
    if (tampilRiwayat) aturRiwayat(false);
    if (body.querySelector('.chat-ai-saran')) body.innerHTML = '';

    tambahPesan((lampir ? '📎 ' + lampir.nama + '\n' : '') + pesan, 'user');
    if (teks == null) { input.value = ''; input.style.height = 'auto'; simpanLokal(K.draf, null); }
    sedangKirim = true; sendBtn.disabled = true;
    const indikator = tampilkanMengetik();

    try {
      const r = await api({ aksi: 'kirim', pesan, id: konvId, berkas: lampir ? lampir.id : undefined, riwayat: simpanDiServer ? undefined : riwayatLokal.slice(-12) });
      clearInterval(indikator.timer); indikator.remove();
      if (!r.ok) {
        tambahPesan((r.data && r.data.error) || 'Terjadi kesalahan, coba lagi.', 'err');
      } else {
        const d = r.data;
        simpanDiServer = !!d.tersimpan;
        if (d.tersimpan) { konvId = d.id; simpanLokal(K.id, konvId); }
        else { riwayatLokal.push({ role: 'user', content: pesan }, { role: 'assistant', content: d.balasan }); }
        tambahBalasan(d.balasan, d.tabel);
        if (lampir) { lepasBerkas(); input.placeholder = 'Tulis pertanyaan…'; }
        simpanLokal(K.tick, Date.now());   // kabari tab lain agar ikut menampilkan jawaban ini
      }
    } catch (err) {
      clearInterval(indikator.timer); indikator.remove();
      tambahPesan('Tidak bisa menghubungi server. Periksa koneksi internet.', 'err');
    } finally {
      sedangKirim = false; sendBtn.disabled = false; if (!panel.hidden) input.focus();
    }
  };

  /* ---------- riwayat ---------- */
  const tglPendek = (s) => {
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return '';
    return d.toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  };

  const isiRiwayat = async () => {
    daftarEl.innerHTML = '<div class="kosong">Memuat…</div>';
    const r = await api({ aksi: 'riwayat' });
    if (!r.ok) { daftarEl.innerHTML = `<div class="kosong">${esc((r.data && r.data.error) || 'Riwayat belum bisa dimuat.')}</div>`; return; }
    if (!r.data.daftar.length) { daftarEl.innerHTML = '<div class="kosong">Belum ada percakapan tersimpan.</div>'; return; }
    daftarEl.innerHTML = '';
    for (const p of r.data.daftar) {
      const item = document.createElement('div'); item.className = 'chat-ai-item' + (Number(p.id) === konvId ? ' aktif' : '');
      const buka = document.createElement('button'); buka.type = 'button'; buka.className = 'buka';
      buka.innerHTML = `<span>${esc(p.judul || '(tanpa judul)')}</span><small>${esc(tglPendek(p.diubah))}</small>`;
      buka.addEventListener('click', async () => { aturRiwayat(false); await muat(Number(p.id)); });
      const hapus = document.createElement('button'); hapus.type = 'button'; hapus.className = 'hapus'; hapus.textContent = '×'; hapus.title = 'Hapus percakapan ini'; hapus.setAttribute('aria-label', 'Hapus percakapan');
      hapus.addEventListener('click', async () => {
        if (!confirm('Hapus percakapan ini?')) return;
        await api({ aksi: 'hapus', id: Number(p.id) });
        if (Number(p.id) === konvId) { konvId = 0; simpanLokal(K.id, null); tampilAwal(); }
        isiRiwayat();
      });
      item.append(buka, hapus); daftarEl.appendChild(item);
    }
  };

  function aturRiwayat(tampil) {
    tampilRiwayat = tampil;
    btnRiwayat.setAttribute('aria-pressed', String(tampil));
    btnRiwayat.textContent = tampil ? 'Kembali' : 'Riwayat';
    body.hidden = tampil; daftarEl.hidden = !tampil; form.hidden = tampil;
    if (tampil) isiRiwayat(); else gulir();
  }
  btnRiwayat.addEventListener('click', () => aturRiwayat(!tampilRiwayat));

  /* ---------- panel: buka/tutup, lebar, percakapan baru ---------- */
  const bukaTutup = (paksaBuka, ingat = true) => {
    const buka = paksaBuka ?? panel.hidden;
    panel.hidden = !buka;
    toggle.setAttribute('aria-expanded', String(buka));
    if (ingat) simpanLokal(K.buka, buka ? '1' : '0');
    if (buka) { gulir(); setTimeout(() => input.focus(), 50); }
  };
  toggle.addEventListener('click', () => bukaTutup());

  const aturLebar = (lebar) => { panel.classList.toggle('lebar', lebar); btnLebar.textContent = lebar ? 'Sempit' : 'Lebar'; simpanLokal(K.lebar, lebar ? '1' : '0'); };
  btnLebar.addEventListener('click', () => aturLebar(!panel.classList.contains('lebar')));

  clearBtn.textContent = 'Baru';
  clearBtn.addEventListener('click', () => {
    if (sedangKirim) return;
    konvId = 0; riwayatLokal = []; simpanLokal(K.id, null); lepasBerkas(); input.placeholder = 'Tulis pertanyaan…';
    if (tampilRiwayat) aturRiwayat(false);
    tampilAwal();
  });

  /* ---------- input ---------- */
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = input.scrollHeight + 'px';
    simpanLokal(K.draf, input.value || null);
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
  form.addEventListener('submit', e => { e.preventDefault(); kirim(); });

  /* ---------- sinkron antar-tab ---------- */
  window.addEventListener('storage', (e) => {
    if (sedangKirim) return;
    if (e.key === K.id) {
      const baru = parseInt(e.newValue || '0', 10) || 0;
      if (baru !== konvId) { konvId = baru; baru ? muat(baru) : tampilAwal(); }
    } else if (e.key === K.tick) {
      if (konvId && !tampilRiwayat) muat(konvId);
    } else if (e.key === K.buka) {
      bukaTutup(e.newValue === '1', false);
    } else if (e.key === K.lebar) {
      aturLebar(e.newValue === '1');
    }
  });
  // balik ke tab ini setelah lama di tab lain → segarkan supaya jawaban dari tab lain ikut tampil
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && !sedangKirim && !panel.hidden && !tampilRiwayat && konvId) muat(konvId);
  });

  /* ---------- mulai ---------- */
  if (bacaLokal(K.lebar) === '1') aturLebar(true);
  const draf = bacaLokal(K.draf);
  if (draf) { input.value = draf; input.dispatchEvent(new Event('input')); }
  tampilAwal();
  if (konvId) muat(konvId);
  if (bacaLokal(K.buka) === '1') bukaTutup(true, false);
})();