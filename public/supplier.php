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
    $aksi = $_POST['aksi'] ?? '';

    switch ($aksi) {
        case 'logout':
            session_unset();
            session_destroy();
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

// ---- Data untuk tampilan ----
$supplierSemua = Supplier::semua();
$editSupplierId = (int) ($_SESSION['edit_supplier_id'] ?? 0);
$editSupplier = $editSupplierId > 0 ? Supplier::cari($editSupplierId) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supplier - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f8; }
        .pos-card { border: 0; border-radius: .75rem; box-shadow: 0 .125rem .375rem rgba(16,24,40,.06); }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">

    <header class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
        <div>
            <h1 class="h3 mb-0">Kelola Supplier</h1>
            <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="admin.php" class="btn btn-outline-secondary btn-sm">Kembali ke Admin</a>
            <form method="post" class="d-inline">
                <input type="hidden" name="aksi" value="logout">
                <button type="submit" class="btn btn-outline-danger btn-sm">Logout</button>
            </form>
        </div>
    </header>

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
                                <?= $editSupplier !== null ? 'Simpan Perubahan' : 'Tambah Supplier' ?>
                            </button>
                            <?php if ($editSupplier !== null): ?>
                                <a href="supplier.php" class="btn btn-outline-secondary">Batal</a>
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
                    <span class="text-muted small"><?= count($supplierSemua) ?> supplier</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($supplierSemua === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada supplier.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nama</th>
                                        <th>Kontak</th>
                                        <th>Alamat</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($supplierSemua as $s): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($s->getNama()) ?></td>
                                            <td><?= htmlspecialchars($s->getKontak()) ?></td>
                                            <td><?= htmlspecialchars($s->getAlamat()) ?></td>
                                            <td class="text-center">
                                                <span class="d-inline-flex gap-1">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="aksi" value="edit_supplier">
                                                        <input type="hidden" name="supplier_id" value="<?= $s->getId() ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Edit">Edit</button>
                                                    </form>
                                                    <form method="post" class="d-inline"
                                                          onsubmit="return confirm('Hapus supplier ini?');">
                                                        <input type="hidden" name="aksi" value="hapus_supplier">
                                                        <input type="hidden" name="supplier_id" value="<?= $s->getId() ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">Hapus</button>
                                                    </form>
                                                </span>
                                            </td>
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
</body>
</html>
