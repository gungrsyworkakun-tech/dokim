<?php require __DIR__ . '/../config.php';   // dipanggil tombol "Perpanjang" sesi
if (empty($_SESSION['u'])) { http_response_code(401); exit; }   // dari folder api/, jangan redirect ke login.php relatif
login_required();
http_response_code(204);
