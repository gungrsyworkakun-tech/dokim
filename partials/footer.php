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
</body></html>