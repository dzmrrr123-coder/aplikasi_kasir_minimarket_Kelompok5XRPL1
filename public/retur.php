<?php

declare(strict_types=1);

/**
 * Halaman admin: catat retur barang ke supplier.
 *
 * Alur mengikuti ReturBarang::prosesRetur():
 *   1. cek stok produk cukup -> kalau kurang, batalkan dengan pesan spesifik;
 *   2. cek produk punya riwayat stok masuk (pembelian) -> kalau belum pernah
 *      dibeli, batalkan (barang tidak berasal dari supplier manapun);
 *   3. supplier tujuan retur = supplier asal pembelian terakhir -> kalau tidak
 *      cocok, batalkan;
 *   4. kalau valid: kurangi stok produk & catat retur dalam satu transaksi DB
 *      (rollback otomatis kalau gagal di tengah, stok tidak terlanjur berkurang).
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;
use App\Models\Produk;
use App\Models\ReturBarang;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login sebagai admin.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    $nama403 = $_SESSION['nama'] ?? 'Pengguna';
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
    $pembelianId = (int) ($data['pembelian_id'] ?? 0);
    $qty = (int) ($data['qty'] ?? 0);
    $alasan = trim((string) ($data['alasan'] ?? ''));

    $produk = Produk::cari($produkId);

    if ($produk === null) {
        redirectSelf('Produk tidak ditemukan.');
    }

    $retur = new ReturBarang([
        'produk_id'    => $produkId,
        'supplier_id'  => $supplierId,
        'pembelian_id' => $pembelianId,
        'qty'          => $qty,
        'alasan'       => $alasan,
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
        redirectSelf(pesanErrorRamah($e));
    }
}

// ---- Routing aksi (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $aksi = $_POST['aksi'] ?? '';

    switch ($aksi) {
        case 'logout':
            logoutKaryawan();
            header('Location: login.php');
            exit;

        case 'proses_retur':
            aksiProsesRetur($_POST);
            break;
    }
}

// ---- Data untuk tampilan (view murni: produk utk form) ----
$produkSemua = Produk::semua();
$aktif = 'retur';
$breadcrumb = ['Dashboard' => 'dashboard.php', 'Retur Barang' => ''];
// Riwayat retur diambil via DataTables server-side dari api.php?aksi=retur.tabel
// (Controller → ReturBarang::getDataTabel), bukan di-render di view.
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Retur Barang - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
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
                    <?php if ($produkSemua === []): ?>
                        <div class="text-muted small">
                            Belum ada produk. Siapkan dulu lewat halaman terkait.
                        </div>
                    <?php else: ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="aksi" value="proses_retur">
                            <div class="col-12">
                                <label for="produk-retur" class="form-label">Produk</label>
                                <select id="produk-retur" name="produk_id" class="form-select" required>
                                    <option value="">Pilih produk...</option>
                                    <?php foreach ($produkSemua as $p): ?>
                                        <option value="<?= $p->getId() ?>" data-stok="<?= $p->getStok() ?>">
                                            <?= htmlspecialchars($p->getNama()) ?> (stok <?= $p->getStok() ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="supplier-retur" class="form-label">Supplier asal (dari stok masuk)</label>
                                <select id="supplier-retur" name="supplier_id" class="form-select" required disabled>
                                    <option value="">Pilih produk dulu...</option>
                                </select>
                                <div class="form-text" id="info-pembelian"></div>
                            </div>
                            <input type="hidden" name="pembelian_id" id="pembelian-id" value="0">
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
                    <span class="text-muted small">DataTables</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabel-retur">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Produk</th>
                                    <th>Supplier</th>
                                    <th class="text-center">Qty</th>
                                    <th>Alasan</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/datatables/dataTables.min.js"></script>
<script src="assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script>
    // Saat produk dipilih: ambil supplier asal (dari stok masuk terakhir)
    // lalu isi dropdown supplier + info pembelian. Batasi qty maks = stok.
    (function () {
        var select = document.getElementById('produk-retur');
        var supplier = document.getElementById('supplier-retur');
        var info = document.getElementById('info-pembelian');
        var pembelianId = document.getElementById('pembelian-id');
        var qty = document.getElementById('qty-retur');
        if (!select || !supplier || !info || !pembelianId || !qty) return;

        function setMaksStok(stok) {
            var maks = Math.max(1, stok);
            qty.max = maks;
            if (parseInt(qty.value, 10) > maks) qty.value = maks;
        }

        function muatSupplierAsal() {
            var id = select.value;
            if (!id) {
                supplier.innerHTML = '<option value="">Pilih produk dulu...</option>';
                supplier.disabled = true;
                info.textContent = '';
                pembelianId.value = '0';
                qty.max = '';
                return;
            }

            var opt = select.options[select.selectedIndex];
            setMaksStok(opt && opt.dataset.stok ? parseInt(opt.dataset.stok, 10) : 1);

            fetch('api.php?aksi=retur.supplier_asal&produk_id=' + encodeURIComponent(id), {
                headers: { 'X-Requested-With': 'fetch' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ditemukan) {
                        supplier.innerHTML = '<option value="">Belum ada stok masuk</option>';
                        supplier.disabled = true;
                        info.textContent = 'Produk ini belum pernah dibeli dari supplier mana pun, belum bisa diretur.';
                        info.className = 'form-text text-danger';
                        pembelianId.value = '0';
                        return;
                    }
                    supplier.innerHTML =
                        '<option value="' + data.supplier_id + '">' + data.nama + '</option>';
                    supplier.disabled = false;
                    pembelianId.value = data.pembelian_id;
                    info.textContent = 'Stok masuk terakhir: ' + data.tanggal_beli + ' — ' + data.nama + '.';
                    info.className = 'form-text';
                })
                .catch(function () {
                    supplier.innerHTML = '<option value="">Gagal memuat supplier</option>';
                    supplier.disabled = true;
                    info.textContent = 'Terjadi kesalahan saat memuat data. Coba lagi.';
                });
        }

        select.addEventListener('change', muatSupplierAsal);
        muatSupplierAsal();
    })();

    // Riwayat retur via DataTables server-side (api.php → ReturController).
    if (window.jQuery && window.DataTable) {
        jQuery('#tabel-retur').DataTable({
            serverSide: true,
            ajax: { url: 'api.php?aksi=retur.tabel', data: function (d) { d.draw = d.draw || 0; } },
            pageLength: 10,
            lengthChange: false,
            columns: [
                { data: 'tanggal' },
                { data: 'produk_nama' },
                { data: 'supplier_nama' },
                { data: 'qty', className: 'text-center' },
                { data: 'alasan' }
            ],
            language: {
                url: 'assets/vendor/datatables/id.json'
            }
        });
    }
</script>
<script src="assets/theme.js"></script>
<script src="assets/crud-reload.js"></script>
</body>
</html>
