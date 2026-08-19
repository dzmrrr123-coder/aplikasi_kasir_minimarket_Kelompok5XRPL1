<?php

declare(strict_types=1);

/**
 * Halaman admin: pembelian / stok masuk dari supplier.
 *
 * Form: pilih supplier (opsional), tambah satu atau beberapa produk dengan
 * qty & harga beli, lalu simpan. Penyimpanan lewat model Pembelian yang
 * menambah stok & memperbarui harga beli produk dalam satu transaksi DB.
 * Riwayat pembelian tampil via DataTables server-side.
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Pembelian;
use App\Models\Produk;
use App\Models\Supplier;

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

function formatRupiah(float $jumlah): string
{
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}

/** Simpan pembelian: supplier + daftar produk/qty/harga beli. */
function aksiSimpanPembelian(array $data): void
{
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $keterangan = trim((string) ($data['keterangan'] ?? ''));
    $produkIds = $data['produk_id'] ?? [];
    $qtys = $data['qty'] ?? [];
    $hargaBelis = $data['harga_beli'] ?? [];

    if (!is_array($produkIds) || !is_array($qtys) || !is_array($hargaBelis)) {
        redirectSelf('Data stok masuk tidak valid.');
    }

    $items = [];

    foreach ($produkIds as $i => $produkId) {
        $produkId = (int) $produkId;

        if ($produkId <= 0) {
            continue;
        }

        $qty = (float) ($qtys[$i] ?? 0);
        $hargaBeli = (float) ($hargaBelis[$i] ?? 0);

        if ($qty <= 0) {
            redirectSelf('Jumlah stok masuk harus lebih dari 0.');
        }

        $items[] = [
            'produk_id'  => $produkId,
            'qty'        => $qty,
            'harga_beli' => $hargaBeli,
        ];
    }

    if ($items === []) {
        redirectSelf('Minimal satu produk untuk stok masuk.');
    }

    $pembelian = new Pembelian([
        'supplier_id' => $supplierId,
        'keterangan'  => $keterangan,
    ]);

    try {
        $pembelian->simpan($items);
    } catch (\Throwable $e) {
        redirectSelf(pesanErrorRamah($e));
    }

    redirectSelf('Stok masuk berhasil disimpan, stok & harga beli produk diperbarui.');
}

/** Hapus pembelian (bersama item-nya, tapi tidak mengubah stok). */
function aksiHapusPembelian(int $id): void
{
    if (Pembelian::cari($id) === null) {
        redirectSelf('Pembelian tidak ditemukan.');
    }

    try {
        $stmt = \App\Database\Database::connect()->prepare('DELETE FROM pembelian WHERE id = :id');
        $stmt->execute([':id' => $id]);
        redirectSelf('Riwayat pembelian dihapus.');
    } catch (\Throwable $e) {
        redirectSelf('Gagal menghapus pembelian.');
    }
}

/** Redirect kembali ke halaman ini dengan pesan flash. */
function redirectSelf(string $pesan, string $tipe = 'info'): never
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['pesan' => $pesan]);
        exit;
    }

    $_SESSION['pesan'] = $pesan;
    header('Location: pembelian.php');
    exit;
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

        case 'simpan_pembelian':
            aksiSimpanPembelian($_POST);
            break;

        case 'hapus_pembelian':
            aksiHapusPembelian((int) ($_POST['pembelian_id'] ?? 0));
            break;
    }
}

// ---- Data untuk tampilan ----
$supplierSemua = Supplier::semua();
$produkSemua = Produk::semua();
$aktif = 'pembelian';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stok Masuk - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .baris-produk select { min-width: 12rem; }
    </style>
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Stok Masuk (Pembelian)</h1>
        <span class="text-muted small">Catat pembelian dari supplier, stok & harga beli produk otomatis diperbarui</span>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Form stok masuk -->
        <div class="col-lg-5">
            <div class="card pos-card">
                <div class="card-header bg-white">
                    <i class="bi bi-box-arrow-in-down me-1"></i>Form Stok Masuk
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3" id="form-pembelian">
                        <input type="hidden" name="aksi" value="simpan_pembelian">

                        <div class="col-12">
                            <label for="supplier-pembelian" class="form-label">Supplier</label>
                            <select id="supplier-pembelian" name="supplier_id" class="form-select">
                                <option value="0">Tanpa supplier</option>
                                <?php foreach ($supplierSemua as $s): ?>
                                    <option value="<?= $s->getId() ?>"><?= htmlspecialchars($s->getNama()) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Produk & qty</label>
                            <div id="daftar-baris">
                                <div class="baris-produk row g-2 mb-2">
                                    <div class="col-7">
                                        <select name="produk_id[]" class="form-select form-select-sm" required>
                                            <option value="">Pilih produk...</option>
                                            <?php foreach ($produkSemua as $p): ?>
                                                <option value="<?= $p->getId() ?>">
                                                    <?= htmlspecialchars($p->getNama()) ?> (stok <?= $p->getStok() ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <input type="number" name="qty[]" class="form-control form-control-sm" placeholder="Qty" min="0.001" step="0.001" required>
                                    </div>
                                    <div class="col-2 d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-success flex-shrink-0" data-tambah-baris title="Tambah baris produk">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" data-hapus-baris title="Hapus baris">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div class="col-12 mt-1">
                                        <input type="number" name="harga_beli[]" class="form-control form-control-sm" placeholder="Harga beli satuan" min="0" step="0.01" required>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text">Kosongkan baris yang tidak dipakai; minimal satu baris terisi.</div>
                        </div>

                        <div class="col-12">
                            <label for="keterangan-pembelian" class="form-label">Keterangan</label>
                            <input
                                type="text"
                                id="keterangan-pembelian"
                                name="keterangan"
                                class="form-control"
                                placeholder="cth: Restock mingguan"
                            >
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Simpan Stok Masuk
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Riwayat pembelian -->
        <div class="col-lg-7">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Riwayat Stok Masuk</span>
                    <span class="text-muted small">DataTables</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabel-pembelian">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Supplier</th>
                                    <th class="text-end">Total</th>
                                    <th>Keterangan</th>
                                    <th class="text-center">Aksi</th>
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
<script src="assets/theme.js"></script>
<script src="assets/pembelian.js"></script>
<script>
    // Tambah/hapus baris produk di form.
    (function () {
        var daftar = document.getElementById('daftar-baris');
        if (!daftar) return;

        var template = daftar.querySelector('.baris-produk');

        document.getElementById('form-pembelian').addEventListener('click', function (e) {
            var tombolTambah = e.target.closest('[data-tambah-baris]');
            if (tombolTambah) {
                var klon = template.cloneNode(true);
                klon.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
                daftar.appendChild(klon);
                return;
            }

            var tombolHapus = e.target.closest('[data-hapus-baris]');
            if (tombolHapus) {
                var baris = tombolHapus.closest('.baris-produk');
                if (daftar.querySelectorAll('.baris-produk').length > 1) {
                    baris.remove();
                }
            }
        });
    })();

    // Tabel riwayat pembelian via DataTables server-side.
    (function () {
        if (!window.jQuery || !window.DataTable) return;

        function rupiah(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        jQuery('#tabel-pembelian').DataTable({
            serverSide: true,
            ajax: { url: 'api.php?aksi=pembelian.tabel', data: function (d) { d.draw = d.draw || 0; } },
            pageLength: 10,
            lengthChange: false,
            order: [],
            columns: [
                {
                    data: 'tanggal',
                    render: function (d) {
                        var t = new Date(d);
                        if (isNaN(t)) return d;
                        return t.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) +
                            ' ' + t.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
                    }
                },
                { data: 'supplier_nama', render: function (d) { return d ? d : '<span class="text-muted">—</span>'; } },
                { data: 'total', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                { data: 'keterangan', render: function (d) { return d ? d : '<span class="text-muted">—</span>'; } },
                {
                    data: 'id',
                    className: 'text-center',
                    orderable: false,
                    render: function (d) {
                        return '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus riwayat ini? Stok produk TIDAK dikembalikan.\');">' +
                            '<input type="hidden" name="aksi" value="hapus_pembelian">' +
                            '<input type="hidden" name="pembelian_id" value="' + d + '">' +
                            '<button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash me-1"></i>Hapus</button>' +
                            '</form>';
                    }
                }
            ],
            language: {
                url: 'assets/vendor/datatables/id.json'
            }
        });
    })();
</script>
</body>
</html>
