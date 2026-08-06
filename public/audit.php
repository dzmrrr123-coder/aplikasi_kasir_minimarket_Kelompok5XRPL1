<?php

declare(strict_types=1);

/**
 * Halaman admin: audit log (jejak perubahan data penting).
 */

require __DIR__ . '/../src/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    $nama = $_SESSION['nama'] ?? 'Pengguna';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Akses Ditolak - Kasir Minimarket</title>
        <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
        <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
        <link href="assets/theme.css" rel="stylesheet">
    </head>
    <body class="d-flex align-items-center" style="min-height: 100vh;">
    <div class="container">
        <div class="card pos-card mx-auto" style="max-width: 480px;">
            <div class="card-body text-center p-4">
                <span class="badge text-bg-danger mb-3"><i class="bi bi-shield-exclamation me-1"></i>403</span>
                <h1 class="h4 mb-3">Akses Ditolak</h1>
                <p class="mb-4">Anda tidak memiliki akses ke halaman ini.</p>
                <a href="transaksi.php" class="btn btn-primary"><i class="bi bi-cash-register me-1"></i>Kembali ke Kasir</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$nama = $_SESSION['nama'] ?? 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'logout') {
    require_csrf();
    logoutKaryawan();
    header('Location: login.php');
    exit;
}

$aktif = 'audit';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Log - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Audit Log</h1>
        <span class="text-muted small">Jejak perubahan penting: void item, buka/tutup kas, dan lainnya</span>
    </div>

    <div class="card pos-card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span>Riwayat Aktivitas</span>
            <span class="text-muted small">DataTables</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabel-audit">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Waktu</th>
                            <th>User</th>
                            <th>Aksi</th>
                            <th>Tabel</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/datatables/dataTables.min.js"></script>
<script src="assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script src="assets/theme.js"></script>
<script>
    (function () {
        'use strict';

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        if (window.jQuery && window.DataTable) {
            jQuery('#tabel-audit').DataTable({
                serverSide: true,
                ajax: { url: 'api.php?aksi=audit.tabel', data: function (d) { d.draw = d.draw || 0; } },
                pageLength: 15,
                lengthChange: false,
                order: [],
                columns: [
                    { data: 'id' },
                    { data: 'dicatat_pada' },
                    { data: 'user_nama' },
                    {
                        data: 'aksi',
                        render: function (d) {
                            var warna = { void_item: 'danger', void_gagal: 'secondary', buka_kas: 'success', tutup_kas: 'warning' };
                            var cls = warna[d] || 'primary';
                            return '<span class="badge text-bg-' + cls + '">' + esc(d) + '</span>';
                        }
                    },
                    { data: 'tabel', render: function (d) { return '<code>' + esc(d) + '</code>'; } },
                    {
                        data: 'detail',
                        render: function (d) {
                            if (!d) return '<span class="text-muted">—</span>';
                            try {
                                var obj = JSON.parse(d);
                                return esc(JSON.stringify(obj));
                            } catch (e) {
                                return esc(d);
                            }
                        }
                    }
                ],
                language: {
                    url: 'assets/vendor/datatables/id.json'
                }
            });
        }
    })();
</script>
</body>
</html>
