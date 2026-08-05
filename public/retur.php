<?php

declare(strict_types=1);

/**
 * Halaman admin: catat retur barang ke supplier.
 *
 * Alur mengikuti ReturBarang::prosesRetur():
 *   1. cek stok produk cukup -> kalau kurang, batalkan dengan pesan spesifik;
 *   2. cek data supplier valid -> kalau invalid, batalkan;
 *   3. kalau valid: kurangi stok produk & catat retur dalam satu transaksi DB
 *      (rollback otomatis kalau gagal di tengah, stok tidak terlanjur berkurang).
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;
use App\Models\Produk;
use App\Models\ReturBarang;
use App\Models\Supplier;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login sebagai admin.
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Admin';

$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);

function redirectSelf(string $pesan): never
{
    $_SESSION['pesan'] = $pesan;
    header('Location: retur.php');
    exit;
}

/** Catat retur via ReturBarang::prosesRetur(). */
function aksiProsesRetur(array $data): void
{
    $produkId = (int) ($data['produk_id'] ?? 0);
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $qty = (int) ($data['qty'] ?? 0);
    $alasan = trim((string) ($data['alasan'] ?? ''));

    $produk = Produk::cari($produkId);

    if ($produk === null) {
        redirectSelf('Produk tidak ditemukan.');
    }

    $retur = new ReturBarang([
        'produk_id'   => $produkId,
        'supplier_id' => $supplierId,
        'qty'         => $qty,
        'alasan'      => $alasan,
    ]);

    // Update stok di objek produk dari DB supaya cek stok aktual.
    $retur->setProduk($produk);

    try {
        $retur->prosesRetur();
        redirectSelf(sprintf(
            'Retur %d unit "%s" ke "%s" berhasil dicatat.',
            $qty,
            $produk->getNama(),
            $retur->getSupplier()->getNama()
        ));
    } catch (\Throwable $e) {
        redirectSelf($e->getMessage());
    }
}

// ---- Routing aksi (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    switch ($aksi) {
        case 'logout':
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit;

        case 'proses_retur':
            aksiProsesRetur($_POST);
            break;
    }
}

// ---- Data untuk tampilan ----
$produkSemua = Produk::semua();
$supplierSemua = Supplier::semua();

// Riwayat retur (join nama produk & supplier untuk tampilan).
$riwayat = Database::connect()->query(
    'SELECT r.id, r.tanggal, r.qty, r.alasan,
            p.nama AS produk_nama,
            s.nama AS supplier_nama
     FROM retur_barang r
     JOIN produk p ON p.id = r.produk_id
     JOIN supplier s ON s.id = r.supplier_id
     ORDER BY r.tanggal DESC
     LIMIT 100'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Retur Barang - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top">
    <div class="container">
        <a class="navbar-brand" href="admin.php"><i class="bi bi-shop"></i> Kasir Minimarket</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-retur"
                aria-controls="nav-retur" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-retur">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="admin.php"><i class="bi bi-speedometer2"></i> Admin</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="transaksi.php"><i class="bi bi-cash-register"></i> Kasir</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="laporan.php"><i class="bi bi-bar-chart-line"></i> Laporan</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="supplier.php"><i class="bi bi-truck"></i> Supplier</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="retur.php"><i class="bi bi-arrow-counterclockwise"></i> Retur</a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <span class="navbar-text text-white small me-2 d-none d-lg-inline">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nama) ?>
                </span>
                <form method="post" class="d-inline">
                    <input type="hidden" name="aksi" value="logout">
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Retur Barang</h1>
        <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Form retur -->
        <div class="col-lg-5">
            <div class="card pos-card">
                <div class="card-header bg-white"><strong>Catat Retur</strong></div>
                <div class="card-body">
                    <?php if ($produkSemua === [] || $supplierSemua === []): ?>
                        <div class="text-muted small">
                            <?= $produkSemua === [] ? 'Belum ada produk. ' : '' ?>
                            <?= $supplierSemua === [] ? 'Belum ada supplier. ' : '' ?>
                            Siapkan dulu lewat halaman terkait.
                        </div>
                    <?php else: ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="aksi" value="proses_retur">
                            <div class="col-12">
                                <label for="produk-retur" class="form-label">Produk</label>
                                <select id="produk-retur" name="produk_id" class="form-select" required>
                                    <option value="">Pilih produk...</option>
                                    <?php foreach ($produkSemua as $p): ?>
                                        <option value="<?= $p->getId() ?>">
                                            <?= htmlspecialchars($p->getNama()) ?> (stok <?= $p->getStok() ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="supplier-retur" class="form-label">Supplier</label>
                                <select id="supplier-retur" name="supplier_id" class="form-select" required>
                                    <option value="">Pilih supplier...</option>
                                    <?php foreach ($supplierSemua as $s): ?>
                                        <option value="<?= $s->getId() ?>">
                                            <?= htmlspecialchars($s->getNama()) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="qty-retur" class="form-label">Qty</label>
                                <input
                                    type="number"
                                    min="1"
                                    id="qty-retur"
                                    name="qty"
                                    class="form-control"
                                    value="1"
                                    required
                                >
                            </div>
                            <div class="col-md-8">
                                <label for="alasan-retur" class="form-label">Alasan</label>
                                <input
                                    type="text"
                                    id="alasan-retur"
                                    name="alasan"
                                    class="form-control"
                                    placeholder="cth: Rusak, kadaluarsa"
                                >
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Proses Retur</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Riwayat retur -->
        <div class="col-lg-7">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Riwayat Retur</span>
                    <span class="text-muted small"><?= count($riwayat) ?> catatan</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($riwayat === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada retur tercatat.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Produk</th>
                                        <th>Supplier</th>
                                        <th class="text-center">Qty</th>
                                        <th>Alasan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($riwayat as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((new DateTimeImmutable($r['tanggal']))->format('d-m-Y H:i')) ?></td>
                                            <td><?= htmlspecialchars($r['produk_nama']) ?></td>
                                            <td><?= htmlspecialchars($r['supplier_nama']) ?></td>
                                            <td class="text-center"><?= (int) $r['qty'] ?></td>
                                            <td><?= htmlspecialchars($r['alasan']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
