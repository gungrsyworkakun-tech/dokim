/* Panduan langkah demi langkah (sorotan + kotak penjelasan berpanah).
   Pakai:  SimTour.mulai([{el:'.selector' | fn, j:'Judul', t:'Penjelasan'}, ...], 'kunci-penyimpanan')   */
(function () {
  var css = '#tour-hl{position:fixed;z-index:9990;border-radius:12px;box-shadow:0 0 0 9999px rgba(7,18,34,.6),0 0 0 2px #fff;pointer-events:none;transition:top .28s ease,left .28s ease,width .28s ease,height .28s ease}' +
    '#tour-pop{position:fixed;z-index:9991;width:330px;max-width:calc(100vw - 24px);background:#fff;color:#1a1d23;border-radius:12px;padding:16px 18px 14px;box-shadow:0 16px 40px rgba(4,14,28,.4);font:14px/1.55 "IBM Plex Sans",system-ui,sans-serif;transition:top .28s ease,left .28s ease}' +
    '#tour-pop::before{content:"";position:absolute;width:14px;height:14px;background:#fff;transform:rotate(45deg);left:var(--ax,28px);top:-7px;border-radius:3px}' +
    '#tour-pop.atas::before{top:auto;bottom:-7px}#tour-pop.kiri::before{left:auto;right:-7px;top:var(--ay,24px)}#tour-pop.kanan::before{left:-7px;top:var(--ay,24px)}#tour-pop.tanpa::before{display:none}' +
    '#tour-pop h3{font-size:15px;font-weight:600;margin:0 0 4px;padding-right:18px}#tour-pop p{font-size:13.5px;color:#3d4452;margin:0}' +
    '#tour-pop .ft{display:flex;align-items:center;gap:8px;margin-top:14px}#tour-pop .stp{margin-right:auto;font-size:12px;color:#667085;white-space:nowrap}' +
    '#tour-pop button{font:inherit;font-size:13px;font-weight:500;border-radius:8px;padding:7px 14px;cursor:pointer;border:1px solid #d5dae1;background:#fff;color:#3d4452}' +
    '#tour-pop button.pri{background:#1e3a5f;border-color:#1e3a5f;color:#fff}#tour-pop button.pri:hover{background:#2a5490}' +
    '#tour-pop button:focus-visible{outline:2px solid #2a5490;outline-offset:2px}' +
    '#tour-pop .x{position:absolute;right:8px;top:6px;border:0;background:none;font-size:20px;line-height:1;color:#667085;padding:2px 8px}' +
    '@media(prefers-reduced-motion:reduce){#tour-hl,#tour-pop{transition:none}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  var hl, pop, steps, i, fokusAwal, kunci, idTimer;
  function cari(s) { return typeof s === 'function' ? s() : document.querySelector(s); }
  function tutup() {
    if (!hl) return;
    hl.remove(); pop.remove(); hl = pop = null;
    document.removeEventListener('keydown', tombol); window.removeEventListener('resize', taruh); window.removeEventListener('scroll', taruh, true);
    try { if (kunci) localStorage.setItem(kunci, '1'); } catch (e) {}
    if (fokusAwal && fokusAwal.focus) fokusAwal.focus();
  }
  function tombol(e) { if (e.key === 'Escape') tutup(); }
  function taruh() {
    if (!pop) return;
    var el = cari(steps[i].el); if (!el) return;
    var r = el.getBoundingClientRect(), pad = 6, vw = innerWidth, vh = innerHeight;
    hl.style.top = (r.top - pad) + 'px'; hl.style.left = (r.left - pad) + 'px';
    hl.style.width = (r.width + pad * 2) + 'px'; hl.style.height = (r.height + pad * 2) + 'px';
    var pw = pop.offsetWidth, ph = pop.offsetHeight, cx = r.left + r.width / 2, left, top;
    pop.classList.remove('atas', 'kiri', 'kanan', 'tanpa');
    if (vh - r.bottom - pad - 14 >= ph + 8) {                // cukup ruang di bawah
      top = r.bottom + pad + 14; left = Math.min(Math.max(12, cx - pw / 2), vw - pw - 12);
      pop.style.setProperty('--ax', Math.min(Math.max(16, cx - left - 7), pw - 30) + 'px');
    } else if (r.top - pad - 14 >= ph + 8) {                 // cukup ruang di atas
      top = r.top - pad - 14 - ph; left = Math.min(Math.max(12, cx - pw / 2), vw - pw - 12); pop.classList.add('atas');
      pop.style.setProperty('--ax', Math.min(Math.max(16, cx - left - 7), pw - 30) + 'px');
    } else if (r.left - pad - 16 >= pw + 8) {                // di kiri
      top = Math.min(Math.max(12, r.top + 20), vh - ph - 12); left = r.left - pad - 16 - pw; pop.classList.add('kiri');
      pop.style.setProperty('--ay', Math.min(Math.max(14, 34 - 7), ph - 30) + 'px');
    } else if (vw - r.right - pad - 16 >= pw + 8) {          // di kanan
      top = Math.min(Math.max(12, r.top + 20), vh - ph - 12); left = r.right + pad + 16; pop.classList.add('kanan');
      pop.style.setProperty('--ay', '27px');
    } else {                                                 // target sangat besar: kotak di dasar layar, tanpa panah
      top = vh - ph - 16; left = Math.min(Math.max(12, cx - pw / 2), vw - pw - 12); pop.classList.add('tanpa');
    }
    pop.style.top = top + 'px'; pop.style.left = left + 'px';
  }
  function tampil(n, arah) {
    i = n; arah = arah || 1; var L = steps[i], el = cari(L.el);
    var rr = el && el.getBoundingClientRect();
    if (!el || !rr.width || !rr.height || rr.right <= 0 || rr.left >= innerWidth) {   // bagian tidak terlihat (mis. menu tersembunyi di HP): lewati
      var s = i + arah; if (s >= 0 && s < steps.length) return tampil(s, arah);
      return arah > 0 ? tutup() : tampil(i + 1, 1);
    }
    var r = rr, vh = innerHeight;
    var tujuan = r.height > vh - 260 ? r.top + scrollY - 96 : r.top + scrollY - Math.max(96, (vh - r.height - 190) / 2);   // sisakan ruang untuk kotak penjelasan di bawahnya
    scrollTo({ top: Math.max(0, tujuan), behavior: 'instant' });
    pop.innerHTML = '<button type="button" class="x" id="tour-x" aria-label="Tutup panduan">×</button><h3></h3><p></p><div class="ft"><span class="stp"></span>' +
      (i > 0 ? '<button type="button" id="tour-b">Kembali</button>' : '') + '<button type="button" class="pri" id="tour-n"></button></div>';
    pop.querySelector('h3').textContent = L.j; pop.querySelector('p').textContent = L.t;
    pop.querySelector('.stp').textContent = 'Langkah ' + (i + 1) + ' dari ' + steps.length;
    var nx = pop.querySelector('#tour-n'); nx.textContent = i < steps.length - 1 ? 'Berikutnya' : 'Selesai';
    nx.onclick = function () { i < steps.length - 1 ? tampil(i + 1) : tutup(); };
    pop.querySelector('#tour-x').onclick = tutup;
    var kb = pop.querySelector('#tour-b'); if (kb) kb.onclick = function () { tampil(i - 1, -1); };
    nx.focus({ preventScroll: true }); taruh(); setTimeout(taruh, 60);
  }
  window.SimTour = {
    mulai: function (daftar, kunciSimpan) {
      if (hl) return;
      steps = daftar; kunci = kunciSimpan || null; fokusAwal = document.activeElement;
      hl = document.createElement('div'); hl.id = 'tour-hl';
      pop = document.createElement('div'); pop.id = 'tour-pop'; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Panduan');
      document.body.appendChild(hl); document.body.appendChild(pop);
      document.addEventListener('keydown', tombol); window.addEventListener('resize', taruh); window.addEventListener('scroll', taruh, true);
      tampil(0);
    },
    sudahDilihat: function (k) { try { return !!localStorage.getItem(k); } catch (e) { return true; } }
  };
})();
