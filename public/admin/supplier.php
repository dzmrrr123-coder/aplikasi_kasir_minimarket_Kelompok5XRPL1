<?php

declare(strict_types=1);

/**
 * Halaman admin: kelola supplier.
 *
 * Fitur: list semua supplier + form tambah, edit (modal), hapus.
 * Hapus ditolak bila masih dipakai oleh retur barang.
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Models\Supplier;

SessionGuard::requireLogin();
SessionGuard::requireRole('admin');

$nama      = $_SESSION['nama'] ?? 'Admin';
$pageTitle = 'Kelola Supplier';
$aktif     = 'supplier';

// ---- Routing aksi POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SessionGuard::validateCsrfToken($_POST['csrf_token'] ?? null)
        || (function () { header('Location: supplier.php'); exit; })();

    $aksi = (string) ($_POST['aksi'] ?? '');

    try {
        switch ($aksi) {
            case 'tambah':
                $namaBaru = trim((string) ($_POST['nama'] ?? ''));
                if ($namaBaru === '') {
                    throw new RuntimeException('Nama supplier tidak boleh kosong.');
                }
                $supplier = new Supplier([
                    'nama'   => $namaBaru,
                    'kontak' => trim((string) ($_POST['kontak'] ?? '')),
                    'alamat' => trim((string) ($_POST['alamat'] ?? '')),
                ]);
                $supplier->simpan();
                SessionGuard::setFlash('success', 'Supplier "' . htmlspecialchars($namaBaru) . '" berhasil ditambahkan.');
                break;

            case 'edit':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('ID supplier tidak valid.');
                }
                $supplier = Supplier::cari($id);
                if ($supplier === null) {
                    throw new RuntimeException('Supplier tidak ditemukan.');
                }
                $namaBaru = trim((string) ($_POST['nama'] ?? ''));
                if ($namaBaru === '') {
                    throw new RuntimeException('Nama supplier tidak boleh kosong.');
                }
                $supplier->setNama($namaBaru);
                $supplier->setKontak(trim((string) ($_POST['kontak'] ?? '')));
                $supplier->setAlamat(trim((string) ($_POST['alamat'] ?? '')));
                $supplier->perbarui();
                SessionGuard::setFlash('success', 'Supplier berhasil diperbarui.');
                break;

            case 'hapus':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('ID supplier tidak valid.');
                }
                $supplier = Supplier::cari($id);
                if ($supplier === null) {
                    throw new RuntimeException('Supplier tidak ditemukan.');
                }
                $supplier->hapus();
                SessionGuard::setFlash('success', 'Supplier berhasil dihapus.');
                break;
        }
    } catch (Throwable $e) {
        SessionGuard::setFlash('error', $e->getMessage());
    }

    header('Location: supplier.php');
    exit;
}

$daftarSupplier = Supplier::semua();
$totalSupplier  = count($daftarSupplier);
$adaKontak      = count(array_filter($daftarSupplier, fn($s) => !empty($s->getKontak())));
$adaAlamat      = count(array_filter($daftarSupplier, fn($s) => !empty($s->getAlamat())));

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="bi bi-truck me-2 text-info"></i>Kelola Supplier</h1>
        <p class="page-subtitle">Daftar mitra pemasok barang toko</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-tambah" id="btn-tambah-supplier">
        <i class="bi bi-plus-lg me-1"></i>Tambah Supplier
    </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-sky">
                <i class="bi bi-truck"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalSupplier ?></div>
                <div class="stat-mini-label">Total Supplier</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-teal">
                <i class="bi bi-telephone-check"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $adaKontak ?></div>
                <div class="stat-mini-label">Supplier Ada Kontak</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-indigo">
                <i class="bi bi-geo-alt"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $adaAlamat ?></div>
                <div class="stat-mini-label">Supplier Ada Alamat</div>
            </div>
        </div>
    </div>
</div>

<!-- Search Bar -->
<div class="d-flex align-items-center gap-2 mb-3">
    <div class="position-relative flex-grow-1" style="max-width:360px">
        <i class="bi bi-search position-absolute" style="left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.85rem"></i>
        <input type="text" id="searchSupplier" class="form-control" style="padding-left:2.2rem"
               placeholder="Cari nama, kontak, alamat...">
    </div>
    <span class="text-muted small ms-auto" id="countInfo"><?= $totalSupplier ?> supplier</span>
</div>

<!-- Tabel Supplier -->
<div class="admin-table-wrap animate-fade-in">
    <?php if (empty($daftarSupplier)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-truck" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-3">Belum ada supplier terdaftar.</p>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-tambah">
                <i class="bi bi-plus-lg me-1"></i>Tambah Supplier Pertama
            </button>
        </div>
    <?php else: ?>
        <table class="admin-table" id="tabel-supplier">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Nama Supplier</th>
                    <th>Kontak (Telepon / Email)</th>
                    <th>Alamat</th>
                    <th style="width:160px" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daftarSupplier as $i => $sp): ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="sidebar-icon icon-sky" style="width:2rem;height:2rem;font-size:0.85rem;flex-shrink:0">
                                <i class="bi bi-building"></i>
                            </span>
                            <span class="fw-semibold"><?= htmlspecialchars($sp->getNama()) ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($sp->getKontak() !== ''): ?>
                            <span class="status-badge" style="background:var(--surface-2);color:var(--text);font-size:.78rem">
                                <i class="bi bi-telephone text-primary"></i> <?= htmlspecialchars($sp->getKontak()) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($sp->getAlamat() !== ''): ?>
                            <span class="text-muted small"><i class="bi bi-geo-alt me-1 text-danger"></i><?= htmlspecialchars($sp->getAlamat()) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary me-1"
                                data-bs-toggle="modal" data-bs-target="#modal-edit"
                                data-id="<?= (int) $sp->getId() ?>"
                                data-nama="<?= htmlspecialchars($sp->getNama()) ?>"
                                data-kontak="<?= htmlspecialchars($sp->getKontak()) ?>"
                                data-alamat="<?= htmlspecialchars($sp->getAlamat()) ?>"
                                title="Edit supplier">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Hapus supplier ini? Pastikan tidak ada data yang terkait.')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="aksi" value="hapus">
                            <input type="hidden" name="id" value="<?= (int) $sp->getId() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus supplier">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Modal Tambah Supplier -->
<div class="modal fade" id="modal-tambah" tabindex="-1" aria-labelledby="modal-tambah-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tambah-label">
                        <span class="sidebar-icon icon-sky me-2" style="width:1.75rem;height:1.75rem;font-size:0.85rem;display:inline-flex">
                            <i class="bi bi-truck"></i>
                        </span>
                        Tambah Supplier Baru
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Supplier / PT <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" required autofocus placeholder="contoh: PT Sumber Makmur">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kontak (Telepon / WhatsApp / Email)</label>
                        <input type="text" name="kontak" class="form-control" placeholder="contoh: 08123456789">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Alamat Lengkap</label>
                        <textarea name="alamat" class="form-control" rows="2" placeholder="contoh: Jl. Industri No. 45, Jakarta"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Simpan Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Supplier -->
<div class="modal fade" id="modal-edit" tabindex="-1" aria-labelledby="modal-edit-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-edit-label">
                        <span class="sidebar-icon icon-indigo me-2" style="width:1.75rem;height:1.75rem;font-size:0.85rem;display:inline-flex">
                            <i class="bi bi-pencil"></i>
                        </span>
                        Edit Data Supplier
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Supplier / PT <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" id="edit-nama" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kontak</label>
                        <input type="text" name="kontak" class="form-control" id="edit-kontak">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Alamat Lengkap</label>
                        <textarea name="alamat" class="form-control" id="edit-alamat" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Modal edit: populate fields
document.getElementById('modal-edit').addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    document.getElementById('edit-id').value     = b.dataset.id;
    document.getElementById('edit-nama').value   = b.dataset.nama;
    document.getElementById('edit-kontak').value = b.dataset.kontak;
    document.getElementById('edit-alamat').value = b.dataset.alamat;
});

// Live search supplier
document.getElementById('searchSupplier').addEventListener('input', function () {
    var q     = this.value.toLowerCase().trim();
    var rows  = document.querySelectorAll('#tabel-supplier tbody tr');
    var count = 0;
    rows.forEach(function (row) {
        var text = row.textContent.toLowerCase();
        var show = text.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) count++;
    });
    var el = document.getElementById('countInfo');
    if (el) el.textContent = count + ' supplier';
});
</script>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
