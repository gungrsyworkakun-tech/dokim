</main>
  </div>
  <p class="pub-foot">SIM-DOKIM <?= APP_VER ?> &middot; Sistem Informasi Manajemen Dokumen Keimigrasian<br>Direktorat Jenderal Imigrasi</p>
</div>
<script>document.querySelectorAll('.flash button').forEach(b=>b.addEventListener('click',()=>b.closest('.flash').remove()));
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.php').catch(() => {}));
}
</script>
</body></html>