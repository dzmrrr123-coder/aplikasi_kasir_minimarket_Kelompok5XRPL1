<?php

declare(strict_types=1);

/**
 * Halaman admin: riwayat shift kasir (buka/tutup kas + rekonsiliasi).
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

$aktif = 'shift';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shift Kasir - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Shift Kasir</h1>
        <span class="text-muted small">Riwayat buka/tutup kas & rekonsiliasi per shift</span>
    </div>

    <div class="card pos-card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span>Riwayat Shift</span>
            <span class="text-muted small">DataTables</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabel-shift">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Kasir</th>
                            <th>Dibuka</th>
                            <th>Modal</th>
                            <th>Ditutup</th>
                            <th class="text-end">Total Sistem</th>
                            <th class="text-end">Kas Fisik</th>
                            <th class="text-end">Selisih</th>
                            <th>Status</th>
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

        function rupiah(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        function tgl(s) {
            if (!s) return '<span class="text-muted">—</span>';
            var t = new Date(s.replace(' ', 'T'));
            if (isNaN(t)) return s;
            return t.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) +
                ' ' + t.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        }

        if (window.jQuery && window.DataTable) {
            jQuery('#tabel-shift').DataTable({
                serverSide: true,
                ajax: { url: 'api.php?aksi=shift.tabel', data: function (d) { d.draw = d.draw || 0; } },
                pageLength: 10,
                lengthChange: false,
                order: [],
                columns: [
                    { data: 'id' },
                    { data: 'kasir_nama' },
                    { data: 'dibuka_pada', render: tgl },
                    { data: 'modal_awal', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                    { data: 'ditutup_pada', render: tgl },
                    { data: 'total_sistem', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                    { data: 'total_kas_fisik', className: 'text-end font-num', render: function (d) { return d === null ? '<span class="text-muted">—</span>' : rupiah(d); } },
                    {
                        data: 'selisih',
                        className: 'text-end font-num',
                        render: function (d) {
                            if (d === null) return '<span class="text-muted">—</span>';
                            var cls = d < 0 ? 'text-danger' : (d > 0 ? 'text-warning' : 'text-success');
                            return '<span class="' + cls + '">' + rupiah(d) + '</span>';
                        }
                    },
                    {
                        data: 'status',
                        render: function (d) {
                            return d === 'buka'
                                ? '<span class="badge text-bg-success"><i class="bi bi-play-circle me-1"></i>Buka</span>'
                                : '<span class="badge text-bg-secondary"><i class="bi bi-stop-circle me-1"></i>Tutup</span>';
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
