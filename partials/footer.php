</main>
<footer class="foot">
  <div class="foot-id">
    <span class="foot-seal" aria-hidden="true">SD</span>
    <span>SIM-DOKIM <?= APP_VER ?><em>Sistem Informasi Manajemen Dokumen Keimigrasian &middot; Direktorat Jenderal Imigrasi</em></span>
  </div>
  <div class="foot-aux">
    <span class="foot-hint">Tekan <kbd>Ctrl</kbd> <kbd>K</kbd> untuk mencari dari halaman mana pun</span>
    <button type="button" class="foot-top" data-scroll-top title="Kembali ke atas">
      <svg viewBox="0 0 20 20" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 15V5M5.5 9.5 10 5l4.5 4.5"/></svg>
      Ke atas
    </button>
  </div>
</footer>
</div>

<dialog class="palette" id="palette" aria-label="Pencarian cepat">
  <input class="pal-q" id="pal-q" placeholder="Ketik nama dokumen, kode, atau menu…" autocomplete="off" spellcheck="false">
  <ul id="pal-list" role="listbox"></ul>
  <div class="pal-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> pilih</span><span><kbd>Enter</kbd> buka</span><span><kbd>Esc</kbd> tutup</span></div>
</dialog>

<script type="application/json" id="sim-data"><?= json_encode($simData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php if (app_setting('openrouter_api_key', OPENROUTER_API_KEY) !== ''): ?>
<div id="chat-ai" class="chat-ai">
  <button type="button" class="chat-ai-fab" id="chat-ai-toggle" aria-expanded="false" aria-controls="chat-ai-panel" title="Tanya AI soal stok &amp; transaksi">
    <svg class="ic-chat" viewBox="0 0 20 20" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9.5c0-3.6 3.1-6.5 7-6.5s7 2.9 7 6.5-3.1 6.5-7 6.5c-.9 0-1.8-.16-2.6-.46L4 17l1.1-3.3C3.8 12.6 3 11.1 3 9.5Z"/></svg>
    <svg class="ic-close" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5 5 15"/></svg>
  </button>
  <section class="chat-ai-panel" id="chat-ai-panel" hidden aria-label="Chat AI">
    <header class="chat-ai-head">
      <span class="chat-ai-seal" aria-hidden="true">AI</span>
      <div><b>Tanya seputar data</b><small>Jawab berdasar data stok &amp; transaksi terkini</small></div>
      <button type="button" class="chat-ai-clear" id="chat-ai-clear" title="Mulai percakapan baru">Baru</button>
    </header>
    <div class="chat-ai-body" id="chat-ai-body">
      <div class="chat-ai-msg bot">Halo! Tanya aja soal stok dokumen, penerimaan, atau pengeluaran — misalnya "stok apa yang kritis?" atau "berapa total penerimaan bulan ini?".</div>
    </div>
    <form class="chat-ai-form" id="chat-ai-form">
      <textarea id="chat-ai-input" placeholder="Tulis pertanyaan…" rows="1" maxlength="600" required></textarea>
      <button type="submit" class="chat-ai-send" aria-label="Kirim">
        <svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h14M11 4l6 6-6 6"/></svg>
      </button>
    </form>
  </section>
</div>
<script src="assets/chat_ai.js?v=<?= APP_VER ?>" defer></script>
<?php endif; ?>
</body></html>