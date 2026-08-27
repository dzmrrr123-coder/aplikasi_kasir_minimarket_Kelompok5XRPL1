<?php

declare(strict_types=1);

/**
 * Halaman admin: CRUD supplier.
 *
 * Data supplier: nama, kontak, alamat. Memakai model Supplier
 * (simpan, perbarui, hapus, semua, cari).
 */

require __DIR__ . '/../src/autoload.php';

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

function redirectSelf(string $pesan): never
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['pesan' => $pesan]);
        exit;
    }

    $_SESSION['pesan'] = $pesan;
    header('Location: supplier.php');
    exit;
}

/** Simpan atau perbarui supplier. */
function aksiSimpanSupplier(array $data): void
{
    $editId = (int) ($_SESSION['edit_supplier_id'] ?? 0);
    unset($_SESSION['edit_supplier_id']);

    $nama = trim((string) ($data['nama'] ?? ''));
    $kontak = trim((string) ($data['kontak'] ?? ''));
    $alamat = trim((string) ($data['alamat'] ?? ''));

    if ($nama === '') {
        $_SESSION['edit_supplier_id'] = $editId;
        redirectSelf('Nama supplier tidak boleh kosong.');
    }

    if ($editId > 0) {
        $supplier = Supplier::cari($editId);

        if ($supplier === null) {
            redirectSelf('Supplier tidak ditemukan.');
        }

        $supplier->setNama($nama);
        $supplier->setKontak($kontak);
        $supplier->setAlamat($alamat);
        $supplier->perbarui();

        redirectSelf('Supplier diperbarui.');
    }

    $supplier = new Supplier([
        'nama'   => $nama,
        'kontak' => $kontak,
        'alamat' => $alamat,
    ]);
    $supplier->simpan();

    redirectSelf('Supplier ditambahkan.');
}

/** Hapus supplier; ditolak bila masih dipakai retur (FK RESTRICT). */
function aksiHapusSupplier(int $id): void
{
    $supplier = Supplier::cari($id);

    if ($supplier === null) {
        redirectSelf('Supplier tidak ditemukan.');
    }

    try {
        $supplier->hapus();
        redirectSelf('Supplier dihapus.');
    } catch (\Throwable $e) {
        redirectSelf('Supplier tidak bisa dihapus, masih dipakai data retur.');
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

        case 'simpan_supplier':
            aksiSimpanSupplier($_POST);
            break;

        case 'edit_supplier':
            $_SESSION['edit_supplier_id'] = (int) ($_POST['supplier_id'] ?? 0);
            redirectSelf('');
            break;

        case 'hapus_supplier':
            aksiHapusSupplier((int) ($_POST['supplier_id'] ?? 0));
            break;
    }
}

// ---- Data untuk tampilan (view murni) ----
// Tabel supplier diisi via DataTables server-side (api.php?aksi=supplier.tabel
// → InventarisController → Supplier::getDataTabel), bukan di-render di view.
$editSupplierId = (int) ($_SESSION['edit_supplier_id'] ?? 0);
$editSupplier = $editSupplierId > 0 ? Supplier::cari($editSupplierId) : null;
$aktif = 'supplier';
$breadcrumb = ['Dashboard' => 'dashboard.php', 'Supplier' => ''];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supplier - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Kelola Supplier</h1>
        <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Form tambah/edit -->
        <div class="col-lg-4">
            <div class="card pos-card">
                <div class="card-header bg-white">
                    <?= $editSupplier !== null ? 'Edit Supplier' : 'Tambah Supplier' ?>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="aksi" value="simpan_supplier">
                        <div class="col-12">
                            <label for="nama-supplier" class="form-label">Nama supplier</label>
                            <input
                                type="text"
                                id="nama-supplier"
                                name="nama"
                                class="form-control"
                                value="<?= $editSupplier !== null ? htmlspecialchars($editSupplier->getNama()) : '' ?>"
                                placeholder="cth: PT Sumber Jaya"
                                required
                            >
                        </div>
                        <div class="col-12">
                            <label for="kontak-supplier" class="form-label">Kontak</label>
                            <input
                                type="text"
                                id="kontak-supplier"
                                name="kontak"
                                class="form-control"
                                value="<?= $editSupplier !== null ? htmlspecialchars($editSupplier->getKontak()) : '' ?>"
                                placeholder="cth: 0812-3456-7890"
                            >
                        </div>
                        <div class="col-12">
                            <label for="alamat-supplier" class="form-label">Alamat</label>
                            <textarea
                                id="alamat-supplier"
                                name="alamat"
                                class="form-control"
                                rows="3"
                                placeholder="cth: Jl. Merdeka No. 10, Jakarta"
                            ><?= $editSupplier !== null ? htmlspecialchars($editSupplier->getAlamat()) : '' ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi <?= $editSupplier !== null ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-1"></i>
                                <?= $editSupplier !== null ? 'Simpan Perubahan' : 'Tambah Supplier' ?>
                            </button>
                            <?php if ($editSupplier !== null): ?>
                                <a href="supplier.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Batal</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Daftar supplier -->
        <div class="col-lg-8">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Daftar Supplier</span>
                    <span class="text-muted small">DataTables</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabel-supplier">
                            <thead class="table-light">
                                <tr>
                                    <th>Nama</th>
                                    <th>Kontak</th>
                                    <th>Alamat</th>
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
<script src="assets/supplier.js"></script>
<script>
    // Tabel supplier via DataTables server-side (api.php → InventarisController → Supplier::getDataTabel).
    (function () {
        if (!window.jQuery || !window.DataTable) return;

        jQuery('#tabel-supplier').DataTable({
            serverSide: true,
            ajax: { url: 'api.php?aksi=supplier.tabel', data: function (d) { d.draw = d.draw || 0; } },
            pageLength: 10,
            lengthChange: false,
            order: [],
            columns: [
                { data: 'nama' },
                { data: 'kontak' },
                { data: 'alamat' },
                {
                    data: 'id',
                    className: 'text-center',
                    orderable: false,
                    render: function (d) {
                        return '<span class="d-inline-flex gap-1">' +
                            '<form method="post" class="d-inline">' +
                            '<input type="hidden" name="aksi" value="edit_supplier">' +
                            '<input type="hidden" name="supplier_id" value="' + d + '">' +
                            '<button type="submit" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil me-1"></i>Edit</button>' +
                            '</form>' +
                            '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus supplier ini?\');">' +
                            '<input type="hidden" name="aksi" value="hapus_supplier">' +
                            '<input type="hidden" name="supplier_id" value="' + d + '">' +
                            '<button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash me-1"></i>Hapus</button>' +
                            '</form></span>';
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
