// Widget chat AI (pojok kanan bawah). Riwayat percakapan cuma disimpan di memori
// halaman ini (hilang kalau halaman dimuat ulang) — tidak dikirim/disimpan ke server
// selain sebagai konteks tiap kali bertanya.
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

  let csrf = '';
  try { csrf = (JSON.parse(document.getElementById('sim-data').textContent) || {}).csrf || ''; } catch (e) {}

  let riwayat = [];   // [{role:'user'|'assistant', content:'...'}]
  let sedangKirim = false;

  const bukaTutup = (paksaBuka) => {
    const buka = paksaBuka ?? panel.hidden;
    panel.hidden = !buka;
    toggle.setAttribute('aria-expanded', String(buka));
    if (buka) setTimeout(() => input.focus(), 50);
  };
  toggle.addEventListener('click', () => bukaTutup());

  const tambahPesan = (teks, kelas) => {
    const div = document.createElement('div');
    div.className = 'chat-ai-msg ' + kelas;
    div.textContent = teks;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
    return div;
  };

  const tampilkanMengetik = () => {
    const div = document.createElement('div');
    div.className = 'chat-ai-msg typing';
    div.innerHTML = '<span></span><span></span><span></span>';
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
    return div;
  };

  clearBtn.addEventListener('click', () => {
    riwayat = [];
    body.innerHTML = '';
    tambahPesan('Percakapan baru dimulai. Tanya aja soal stok, penerimaan, atau pengeluaran.', 'bot');
  });

  // textarea tumbuh otomatis sampai batas tinggi (diatur di CSS max-height)
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = input.scrollHeight + 'px';
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const pesan = input.value.trim();
    if (!pesan || sedangKirim) return;

    tambahPesan(pesan, 'user');
    riwayat.push({ role: 'user', content: pesan });
    input.value = ''; input.style.height = 'auto';
    sedangKirim = true; sendBtn.disabled = true;
    const indikator = tampilkanMengetik();

    try {
      const res = await fetch('chat_ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pesan, riwayat: riwayat.slice(0, -1), csrf }),
      });
      const data = await res.json().catch(() => null);
      indikator.remove();
      if (!res.ok || !data || data.error) {
        tambahPesan((data && data.error) || 'Terjadi kesalahan, coba lagi.', 'err');
      } else {
        tambahPesan(data.balasan, 'bot');
        riwayat.push({ role: 'assistant', content: data.balasan });
      }
    } catch (err) {
      indikator.remove();
      tambahPesan('Tidak bisa menghubungi server. Periksa koneksi internet.', 'err');
    } finally {
      sedangKirim = false; sendBtn.disabled = false; input.focus();
    }
  });
})();
