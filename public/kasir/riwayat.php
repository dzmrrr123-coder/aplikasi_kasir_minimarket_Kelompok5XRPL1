<?php

declare(strict_types=1);

/**
 * Halaman riwayat transaksi kasir.
 *
 * Menampilkan daftar transaksi yang dilakukan oleh kasir yang sedang login
 * (atau semua transaksi jika dibuka oleh admin).
 * Fitur:
 * - Filter rentang tanggal
 * - Ringkasan jumlah transaksi & omzet
 * - Tabel transaksi dengan rincian status pembayaran
 * - Tombol Cetak Struk (mengarahkan ke struk.php?id=...)
 * - Modal detail item transaksi
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Database\Database;
use App\Models\ItemTransaksi;

SessionGuard::requireLogin();

$userId   = (int) ($_SESSION['user_id'] ?? 0);
$role     = (string) ($_SESSION['role'] ?? 'kasir');
$nama     = (string) ($_SESSION['nama'] ?? 'Kasir');
$baseUrl  = SessionGuard::baseUrl();
$pageTitle = 'Riwayat Transaksi';
$aktif     = 'riwayat';

// Filter tanggal
$mulaiDefault = date('Y-m-01');
$akhirDefault = date('Y-m-d');

$mulai = trim((string) ($_GET['mulai'] ?? $mulaiDefault));
$akhir = trim((string) ($_GET['akhir'] ?? $akhirDefault));

$pdo = Database::connect();

// Query riwayat transaksi
$where = ['t.tanggal >= :mulai', 't.tanggal < :akhir'];
$params = [
    ':mulai' => $mulai . ' 00:00:00',
    ':akhir' => date('Y-m-d 00:00:00', strtotime($akhir . ' +1 day')),
];

if ($role !== 'admin') {
    $where[] = 't.kasir_id = :kasir_id';
    $params[':kasir_id'] = $userId;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT t.id, t.tanggal, t.total, t.pajak, t.kasir_id, u.nama AS kasir_nama,
            p.metode, p.jumlah_bayar, p.kembalian, d.kode AS diskon_kode
       FROM transaksi t
       JOIN users u ON u.id = t.kasir_id
  LEFT JOIN pembayaran p ON p.id = t.pembayaran_id
  LEFT JOIN diskon d ON d.id = t.diskon_id
      WHERE $whereSql
   ORDER BY t.tanggal DESC"
);
$stmt->execute($params);
$daftarTransaksi = $stmt->fetchAll();

$totalOmzet = 0.0;
foreach ($daftarTransaksi as $tx) {
    $totalOmzet += (float) $tx['total'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Transaksi — Kasir Minimarket</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/theme.css">
    <style>
        .table-hover tbody tr:hover {
            background-color: rgba(13, 148, 136, 0.04);
        }
        .pos-navbar {
            background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%);
        }
    </style>
</head>
<body class="bg-light">

    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg pos-navbar navbar-dark shadow-sm mb-4">
        <div class="container-fluid px-3 px-xl-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $baseUrl ?>/transaksi.php">
                <i class="bi bi-shop"></i>
                <span class="fw-bold">Kasir Minimarket</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= $baseUrl ?>/transaksi.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-cash-register me-1"></i>Buka Kasir (POS)
                </a>
                <span class="navbar-text text-white small ms-2 d-none d-sm-inline">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nama) ?> (<?= htmlspecialchars($role) ?>)
                </span>
                <form method="post" action="<?= $baseUrl ?>/logout.php" class="d-inline ms-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </button>
                </form>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-3 px-xl-4 pb-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1"><i class="bi bi-clock-history me-2 text-teal"></i>Riwayat Transaksi</h1>
                <p class="text-muted small mb-0">
                    <?= $role === 'admin' ? 'Menampilkan semua transaksi kasir' : 'Daftar transaksi kasir Anda' ?>
                </p>
            </div>
            <a href="<?= $baseUrl ?>/transaksi.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Transaksi Baru
            </a>
        </div>

        <!-- Filter Form & Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-sm-5">
                                <label class="form-label small text-muted mb-1">Tanggal Mulai</label>
                                <input type="date" name="mulai" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($mulai) ?>">
                            </div>
                            <div class="col-sm-5">
                                <label class="form-label small text-muted mb-1">Tanggal Akhir</label>
                                <input type="date" name="akhir" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($akhir) ?>">
                            </div>
                            <div class="col-sm-2">
                                <button type="submit" class="btn btn-teal btn-sm w-100" style="background:#0d9488;color:#fff">
                                    <i class="bi bi-filter me-1"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="card border-0 shadow-sm h-100 text-center">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-teal" style="color:#0d9488"><?= count($daftarTransaksi) ?></div>
                        <div class="text-muted small">Total Transaksi</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100 text-center">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-success">Rp <?= number_format($totalOmzet, 0, ',', '.') ?></div>
                        <div class="text-muted small">Total Penjualan</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabel Riwayat Transaksi -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold">Daftar Transaksi</span>
                <span class="badge bg-secondary"><?= count($daftarTransaksi) ?> data ditemukan</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 100px;">No. Tx</th>
                                <th>Waktu</th>
                                <?php if ($role === 'admin'): ?>
                                <th>Kasir</th>
                                <?php endif; ?>
                                <th>Metode</th>
                                <th class="text-end">Total</th>
                                <th class="text-end" style="width: 180px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftarTransaksi)): ?>
                            <tr>
                                <td colspan="<?= $role === 'admin' ? 6 : 5 ?>" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                    Tidak ada transaksi pada periode ini.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($daftarTransaksi as $tx): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-dark border font-num">#<?= (int)$tx['id'] ?></span>
                                </td>
                                <td class="small">
                                    <?= htmlspecialchars(date('d/m/Y H:i', strtotime($tx['tanggal']))) ?>
                                </td>
                                <?php if ($role === 'admin'): ?>
                                <td><?= htmlspecialchars($tx['kasir_nama']) ?></td>
                                <?php endif; ?>
                                <td>
                                    <span class="badge <?= strtolower((string)$tx['metode']) === 'non-tunai' ? 'text-bg-primary' : 'text-bg-success' ?>">
                                        <?= htmlspecialchars((string)($tx['metode'] ?: 'Tunai')) ?>
                                    </span>
                                    <?php if (!empty($tx['diskon_kode'])): ?>
                                    <span class="badge text-bg-warning ms-1" title="Diskon <?= htmlspecialchars($tx['diskon_kode']) ?>">
                                        <i class="bi bi-tag-fill"></i>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold font-num">
                                    Rp <?= number_format((float)$tx['total'], 0, ',', '.') ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= $baseUrl ?>/kasir/struk.php?id=<?= (int)$tx['id'] ?>"
                                       class="btn btn-sm btn-outline-teal"
                                       style="color:#0d9488;border-color:#0d9488"
                                       target="_blank"
                                       title="Cetak Struk">
                                        <i class="bi bi-printer"></i> Struk
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
