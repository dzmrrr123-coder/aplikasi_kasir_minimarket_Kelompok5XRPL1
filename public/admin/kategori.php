<?php

declare(strict_types=1);

/**
 * Halaman admin: kelola kategori produk.
 *
 * Fitur: list semua kategori + form tambah, edit inline (modal), hapus.
 * Hapus ditolak bila masih ada produk yang menggunakan kategori tersebut.
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Models\Kategori;
use App\Models\Produk;

SessionGuard::requireLogin();
SessionGuard::requireRole('admin');

$nama      = $_SESSION['nama'] ?? 'Admin';
$pageTitle = 'Kelola Kategori';
$aktif     = 'kategori';

// ---- Routing aksi POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SessionGuard::validateCsrfToken($_POST['csrf_token'] ?? null)
        || (function () { header('Location: kategori.php'); exit; })();

    $aksi = (string) ($_POST['aksi'] ?? '');

    try {
        switch ($aksi) {
            case 'tambah':
                $namaBaru = trim((string) ($_POST['nama'] ?? ''));
                if ($namaBaru === '') {
                    throw new RuntimeException('Nama kategori tidak boleh kosong.');
                }
                $kategori = new Kategori(['nama' => $namaBaru]);
                $kategori->simpan();
                SessionGuard::setFlash('success', 'Kategori "' . htmlspecialchars($namaBaru) . '" berhasil ditambahkan.');
                break;

            case 'edit':
                $id   = (int) ($_POST['id'] ?? 0);
                $nama_baru = trim((string) ($_POST['nama'] ?? ''));
                if ($id <= 0) {
                    throw new RuntimeException('ID kategori tidak valid.');
                }
                if ($nama_baru === '') {
                    throw new RuntimeException('Nama kategori tidak boleh kosong.');
                }
                $kategori = Kategori::cari($id);
                if ($kategori === null) {
                    throw new RuntimeException('Kategori tidak ditemukan.');
                }
                $kategori->setNama($nama_baru);
                $kategori->perbarui();
                SessionGuard::setFlash('success', 'Kategori berhasil diperbarui.');
                break;

            case 'hapus':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('ID kategori tidak valid.');
                }
                $kategori = Kategori::cari($id);
                if ($kategori === null) {
                    throw new RuntimeException('Kategori tidak ditemukan.');
                }
                $kategori->hapus();
                SessionGuard::setFlash('success', 'Kategori berhasil dihapus.');
                break;
        }
    } catch (Throwable $e) {
        SessionGuard::setFlash('error', $e->getMessage());
    }

    header('Location: kategori.php');
    exit;
}

$daftarKategori = Kategori::semua();

// Hitung produk per kategori
$produkPerKategori = [];
try {
    $semuaProduk = Produk::semua();
    foreach ($semuaProduk as $p) {
        $kid = $p->getKategori()->getId();
        $produkPerKategori[$kid] = ($produkPerKategori[$kid] ?? 0) + 1;
    }
} catch (Throwable $e) {
    $produkPerKategori = [];
}

$totalKategori = count($daftarKategori);
$totalProduk   = array_sum($produkPerKategori);

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="bi bi-tag me-2 text-warning"></i>Kelola Kategori</h1>
        <p class="page-subtitle">Organisasi produk berdasarkan kategori</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-tambah" id="btn-tambah-kategori">
        <i class="bi bi-plus-lg me-1"></i>Tambah Kategori
    </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-amber">
                <i class="bi bi-tag"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalKategori ?></div>
                <div class="stat-mini-label">Total Kategori</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-indigo">
                <i class="bi bi-box-seam"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalProduk ?></div>
                <div class="stat-mini-label">Total Produk</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-teal">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="stat-mini-value">
                    <?= count(array_filter($daftarKategori, fn($k) => ($produkPerKategori[$k->getId()] ?? 0) > 0)) ?>
                </div>
                <div class="stat-mini-label">Kategori Terisi</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-rose">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <div>
                <div class="stat-mini-value">
                    <?= count(array_filter($daftarKategori, fn($k) => ($produkPerKategori[$k->getId()] ?? 0) === 0)) ?>
                </div>
                <div class="stat-mini-label">Kategori Kosong</div>
            </div>
        </div>
    </div>
</div>

<!-- Search Bar -->
<div class="d-flex align-items-center gap-2 mb-3">
    <div class="position-relative flex-grow-1" style="max-width:360px">
        <i class="bi bi-search position-absolute" style="left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.85rem"></i>
        <input type="text" id="searchKategori" class="form-control" style="padding-left:2.2rem"
               placeholder="Cari nama kategori...">
    </div>
    <span class="text-muted small" id="countInfo"><?= $totalKategori ?> kategori</span>
</div>

<!-- Tabel Kategori -->
<div class="admin-table-wrap animate-fade-in">
    <?php if (empty($daftarKategori)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-tag" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-3">Belum ada kategori. Mulai dengan menambahkan satu!</p>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-tambah">
                <i class="bi bi-plus-lg me-1"></i>Tambah Kategori
            </button>
        </div>
    <?php else: ?>
        <table class="admin-table" id="tabel-kategori">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Nama Kategori</th>
                    <th style="width:130px" class="text-center">Jumlah Produk</th>
                    <th style="width:150px" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daftarKategori as $i => $kat): ?>
                <?php $jmlProduk = $produkPerKategori[$kat->getId()] ?? 0; ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="sidebar-icon icon-amber" style="width:1.75rem;height:1.75rem;font-size:0.8rem;flex-shrink:0">
                                <i class="bi bi-tag"></i>
                            </span>
                            <span class="fw-600"><?= htmlspecialchars($kat->getNama()) ?></span>
                        </div>
                    </td>
                    <td class="text-center">
                        <?php if ($jmlProduk > 0): ?>
                            <span class="status-badge status-badge-active">
                                <i class="bi bi-box-seam"></i> <?= $jmlProduk ?> produk
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-badge-inactive">
                                <i class="bi bi-dash"></i> Kosong
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary me-1"
                                data-bs-toggle="modal"
                                data-bs-target="#modal-edit"
                                data-id="<?= (int) $kat->getId() ?>"
                                data-nama="<?= htmlspecialchars($kat->getNama()) ?>"
                                title="Edit kategori">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Hapus kategori ini? Pastikan tidak ada produk yang menggunakannya.')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="aksi" value="hapus">
                            <input type="hidden" name="id" value="<?= (int) $kat->getId() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                <i class="bi bi-trash3 me-1"></i>Hapus
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="modal-tambah" tabindex="-1" aria-labelledby="modal-tambah-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tambah-label">
                        <span class="sidebar-icon icon-amber me-2" style="width:1.75rem;height:1.75rem;font-size:0.85rem;display:inline-flex">
                            <i class="bi bi-tag"></i>
                        </span>
                        Tambah Kategori Baru
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-0">
                        <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" id="input-tambah-nama"
                               placeholder="contoh: Minuman, Makanan Ringan..." required autofocus>
                        <div class="form-text">Masukkan nama kategori yang deskriptif dan singkat.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Kategori
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
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
                        Edit Kategori
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-0">
                        <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" id="edit-nama" required>
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
// Modal edit: isi data
document.getElementById('modal-edit').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('edit-id').value   = btn.dataset.id;
    document.getElementById('edit-nama').value = btn.dataset.nama;
});

// Live search kategori
document.getElementById('searchKategori').addEventListener('input', function () {
    var q = this.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#tabel-kategori tbody tr');
    var visCount = 0;
    rows.forEach(function (row) {
        var nama = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() ?? '';
        var show = nama.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visCount++;
    });
    var countEl = document.getElementById('countInfo');
    if (countEl) countEl.textContent = visCount + ' kategori';
});
</script>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
