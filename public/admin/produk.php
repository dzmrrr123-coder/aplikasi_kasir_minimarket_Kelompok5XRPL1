<?php

declare(strict_types=1);

/**
 * Halaman admin: kelola produk.
 *
 * Fitur:
 * - List semua produk aktif + badge stok menipis
 * - Tambah produk (form modal) + Edit produk (form modal)
 * - Nonaktifkan / hapus produk (soft delete via is_active)
 * - Filter pencarian nama/barcode
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Models\Kategori;
use App\Models\Produk;
use App\Models\Supplier;

SessionGuard::requireLogin();
SessionGuard::requireRole('admin');

$nama      = $_SESSION['nama'] ?? 'Admin';
$pageTitle = 'Kelola Produk';
$aktif     = 'produk';

// ---- Routing aksi POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SessionGuard::validateCsrfToken($_POST['csrf_token'] ?? null)
        || (function () { header('Location: produk.php'); exit; })();

    $aksi = (string) ($_POST['aksi'] ?? '');

    try {
        switch ($aksi) {
            case 'tambah':
                $data = [
                    'nama'          => trim((string) ($_POST['nama'] ?? '')),
                    'harga'         => (float) ($_POST['harga'] ?? 0),
                    'stok'          => (int) ($_POST['stok'] ?? 0),
                    'kategori_id'   => (int) ($_POST['kategori_id'] ?? 0),
                    'satuan'        => (string) ($_POST['satuan'] ?? 'pcs'),
                    'harga_per_gram'=> (float) ($_POST['harga_per_gram'] ?? 0),
                    'barcode'       => trim((string) ($_POST['barcode'] ?? '')),
                    'harga_beli'    => (float) ($_POST['harga_beli'] ?? 0),
                    'stok_minimum'  => (int) ($_POST['stok_minimum'] ?? 0),
                ];
                $produk = new Produk($data);
                $produk->simpan();
                SessionGuard::setFlash('success', 'Produk "' . htmlspecialchars($data['nama']) . '" berhasil ditambahkan.');
                break;

            case 'edit':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('ID produk tidak valid.');
                }
                $produk = Produk::cari($id);
                if ($produk === null) {
                    throw new RuntimeException('Produk tidak ditemukan.');
                }
                $produk->setNama(trim((string) ($_POST['nama'] ?? '')));
                $produk->setHarga((float) ($_POST['harga'] ?? 0));
                $produk->setStok((int) ($_POST['stok'] ?? 0));
                $produk->setSatuan((string) ($_POST['satuan'] ?? 'pcs'));
                $produk->setHargaPerGram((float) ($_POST['harga_per_gram'] ?? 0));
                $produk->setBarcode(trim((string) ($_POST['barcode'] ?? '')));
                $produk->setHargaBeli((float) ($_POST['harga_beli'] ?? 0));
                $produk->setStokMinimum((int) ($_POST['stok_minimum'] ?? 0));
                $katBaru = Kategori::cari((int) ($_POST['kategori_id'] ?? 0));
                if ($katBaru !== null) {
                    $produk->setKategori($katBaru);
                }
                $produk->perbarui();
                SessionGuard::setFlash('success', 'Produk berhasil diperbarui.');
                break;

            case 'hapus':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('ID produk tidak valid.');
                }
                $produk = Produk::cari($id);
                if ($produk === null) {
                    throw new RuntimeException('Produk tidak ditemukan.');
                }
                $produk->hapus();
                SessionGuard::setFlash('success', 'Produk berhasil dihapus.');
                break;
        }
    } catch (Throwable $e) {
        SessionGuard::setFlash('error', $e->getMessage());
    }

    header('Location: produk.php');
    exit;
}

$daftarProduk   = Produk::semua();
$daftarKategori = Kategori::semua();
$daftarSupplier = Supplier::semua();

$totalProduk    = count($daftarProduk);
$stokMenipisCount = count(array_filter($daftarProduk, fn($p) => $p->cekStokMenipis() && $p->getStok() > 0));
$stokHabisCount   = count(array_filter($daftarProduk, fn($p) => $p->getStok() === 0));

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="bi bi-box-seam me-2 text-primary"></i>Kelola Produk</h1>
        <p class="page-subtitle">Daftar produk, penetapan harga, dan pemantauan stok</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-tambah" id="btn-tambah-produk">
        <i class="bi bi-plus-lg me-1"></i>Tambah Produk
    </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
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
                <i class="bi bi-tag"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= count($daftarKategori) ?></div>
                <div class="stat-mini-label">Kategori</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-amber">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $stokMenipisCount ?></div>
                <div class="stat-mini-label">Stok Menipis</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-rose">
                <i class="bi bi-x-circle"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $stokHabisCount ?></div>
                <div class="stat-mini-label">Stok Habis</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Search -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <div class="position-relative" style="min-width:240px;flex:1;max-width:360px">
        <i class="bi bi-search position-absolute" style="left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.85rem"></i>
        <input type="text" id="cari-produk" class="form-control" style="padding-left:2.2rem"
               placeholder="Cari nama atau barcode…">
    </div>
    <div style="min-width:180px">
        <select id="filter-kategori" class="form-select">
            <option value="">Semua Kategori</option>
            <?php foreach ($daftarKategori as $kat): ?>
            <option value="<?= htmlspecialchars($kat->getNama()) ?>"><?= htmlspecialchars($kat->getNama()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <span class="text-muted small ms-auto" id="countInfo"><?= $totalProduk ?> produk</span>
</div>

<div class="admin-table-wrap animate-fade-in">
    <div class="table-responsive">
        <table class="admin-table" id="tabel-produk">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Nama Produk</th>
                    <th>Kategori</th>
                    <th class="text-end">Harga Jual</th>
                    <th class="text-center">Stok</th>
                    <th>Satuan</th>
                    <th style="width:160px" class="text-end">Aksi</th>
                </tr>
            </thead>
                <tbody>
                    <?php if (empty($daftarProduk)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">
                        <i class="bi bi-box-seam" style="font-size:2.5rem;opacity:.3"></i>
                        <p class="mt-2">Belum ada produk terdaftar.</p>
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($daftarProduk as $i => $p): ?>
                    <?php
                        $stok    = $p->getStok();
                        $stokMin = $p->getStokMinimum() ?: 10;
                        $menipis = $p->cekStokMenipis();
                        $persen  = $stok === 0 ? 0 : min(100, round($stok / max($stokMin * 3, 1) * 100));
                        $stokClass = $stok === 0 ? 'stok-habis' : ($menipis ? 'stok-sedikit' : 'stok-ok');
                    ?>
                    <tr>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($p->getNama()) ?></div>
                            <?php if ($p->getBarcode() !== ''): ?>
                            <div><code class="text-muted" style="font-size:0.7rem"><?= htmlspecialchars($p->getBarcode()) ?></code></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge" style="background:var(--brand-primary-lighter);color:var(--brand-primary-darker);font-size:.68rem">
                                <i class="bi bi-tag"></i> <?= htmlspecialchars($p->getKategori()->getNama()) ?>
                            </span>
                        </td>
                        <td class="text-end" style="font-family:var(--font-num);font-weight:700">
                            <?php if ($p->getSatuan() === 'gram'): ?>
                            <div style="font-size:.75rem;color:var(--text-muted)">Rp <?= number_format($p->getHargaPerGram(), 0, ',', '.') ?>/gr</div>
                            <?php else: ?>
                            Rp <?= number_format($p->getHarga(), 0, ',', '.') ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center" style="min-width:90px">
                            <div class="stok-bar-wrap <?= $stokClass ?>">
                                <div class="stok-bar">
                                    <div class="stok-bar-fill" style="width:<?= $persen ?>%"></div>
                                </div>
                                <span class="stok-count"><?= $stok ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?= $p->getSatuan() === 'gram' ? 'status-badge-warning' : '' ?>" style="<?= $p->getSatuan() !== 'gram' ? 'background:var(--surface-2);color:var(--text-muted)' : '' ?>">
                                <?= $p->getSatuan() ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary btn-edit-produk"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modal-edit"
                                    data-id="<?= (int) $p->getId() ?>"
                                    data-nama="<?= htmlspecialchars($p->getNama()) ?>"
                                    data-harga="<?= $p->getHarga() ?>"
                                    data-stok="<?= $p->getStok() ?>"
                                    data-kategori="<?= (int) $p->getKategori()->getId() ?>"
                                    data-satuan="<?= $p->getSatuan() ?>"
                                    data-harga-per-gram="<?= $p->getHargaPerGram() ?>"
                                    data-barcode="<?= htmlspecialchars($p->getBarcode()) ?>"
                                    data-harga-beli="<?= $p->getHargaBeli() ?>"
                                    data-stok-minimum="<?= $p->getStokMinimum() ?>"
                                    title="Edit produk">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus produk ini?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="aksi" value="hapus">
                                <input type="hidden" name="id" value="<?= (int) $p->getId() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus produk"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah Produk -->
<div class="modal fade" id="modal-tambah" tabindex="-1" aria-labelledby="modal-tambah-label" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tambah-label"><i class="bi bi-box-seam me-1"></i>Tambah Produk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                            <input type="text" name="nama" class="form-control" required autofocus>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" class="form-control" placeholder="opsional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kategori <span class="text-danger">*</span></label>
                            <select name="kategori_id" class="form-select" required>
                                <option value="">-- Pilih Kategori --</option>
                                <?php foreach ($daftarKategori as $kat): ?>
                                <option value="<?= (int) $kat->getId() ?>"><?= htmlspecialchars($kat->getNama()) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Satuan</label>
                            <select name="satuan" class="form-select" id="satuan-tambah">
                                <option value="pcs">pcs</option>
                                <option value="gram">gram (curah)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Harga Jual (Rp) <span class="text-danger">*</span></label>
                            <input type="number" name="harga" class="form-control" min="0" step="1" value="0" required>
                        </div>
                        <div class="col-md-6 row-harga-gram d-none">
                            <label class="form-label">Harga per Gram (Rp)</label>
                            <input type="number" name="harga_per_gram" class="form-control" min="0" step="0.01" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Harga Beli (Rp)</label>
                            <input type="number" name="harga_beli" class="form-control" min="0" step="1" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Stok Awal</label>
                            <input type="number" name="stok" class="form-control" min="0" step="1" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Stok Minimum</label>
                            <input type="number" name="stok_minimum" class="form-control" min="0" step="1" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Produk -->
<div class="modal fade" id="modal-edit" tabindex="-1" aria-labelledby="modal-edit-label" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-edit-label"><i class="bi bi-pencil me-1"></i>Edit Produk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                            <input type="text" name="nama" class="form-control" id="edit-nama" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" class="form-control" id="edit-barcode">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kategori <span class="text-danger">*</span></label>
                            <select name="kategori_id" class="form-select" id="edit-kategori" required>
                                <?php foreach ($daftarKategori as $kat): ?>
                                <option value="<?= (int) $kat->getId() ?>"><?= htmlspecialchars($kat->getNama()) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Satuan</label>
                            <select name="satuan" class="form-select" id="edit-satuan">
                                <option value="pcs">pcs</option>
                                <option value="gram">gram (curah)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Harga Jual (Rp) <span class="text-danger">*</span></label>
                            <input type="number" name="harga" class="form-control" id="edit-harga" min="0" step="1" required>
                        </div>
                        <div class="col-md-6" id="row-edit-harga-gram">
                            <label class="form-label">Harga per Gram (Rp)</label>
                            <input type="number" name="harga_per_gram" class="form-control" id="edit-harga-per-gram" min="0" step="0.01">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Harga Beli (Rp)</label>
                            <input type="number" name="harga_beli" class="form-control" id="edit-harga-beli" min="0" step="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Stok</label>
                            <input type="number" name="stok" class="form-control" id="edit-stok" min="0" step="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Stok Minimum</label>
                            <input type="number" name="stok_minimum" class="form-control" id="edit-stok-minimum" min="0" step="1">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Perbarui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle baris harga/gram saat satuan berubah.
function toggleHargaGram(satuanSel, rowClass) {
    var gram = satuanSel.value === 'gram';
    var row = document.querySelector(rowClass);
    if (row) row.classList.toggle('d-none', !gram);
}
document.getElementById('satuan-tambah')?.addEventListener('change', function () {
    toggleHargaGram(this, '.row-harga-gram');
});

// Isi modal edit.
document.getElementById('modal-edit').addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    document.getElementById('edit-id').value              = b.dataset.id;
    document.getElementById('edit-nama').value            = b.dataset.nama;
    document.getElementById('edit-harga').value           = b.dataset.harga;
    document.getElementById('edit-stok').value            = b.dataset.stok;
    document.getElementById('edit-kategori').value        = b.dataset.kategori;
    document.getElementById('edit-satuan').value          = b.dataset.satuan;
    document.getElementById('edit-harga-per-gram').value  = b.dataset.hargaPerGram;
    document.getElementById('edit-barcode').value         = b.dataset.barcode;
    document.getElementById('edit-harga-beli').value      = b.dataset.hargaBeli;
    document.getElementById('edit-stok-minimum').value    = b.dataset.stokMinimum;
    toggleHargaGram(document.getElementById('edit-satuan'), '#row-edit-harga-gram');
});
document.getElementById('edit-satuan')?.addEventListener('change', function () {
    toggleHargaGram(this, '#row-edit-harga-gram');
});

// Pencarian & filter kategori real-time.
function filterProdukTable() {
    var q   = (document.getElementById('cari-produk')?.value || '').toLowerCase().trim();
    var kat = (document.getElementById('filter-kategori')?.value || '').toLowerCase().trim();
    var count = 0;
    document.querySelectorAll('#tabel-produk tbody tr').forEach(function (tr) {
        var text = tr.textContent.toLowerCase();
        var katCol = (tr.querySelector('td:nth-child(3)')?.textContent || '').toLowerCase();
        var matchQ   = !q || text.includes(q);
        var matchKat = !kat || katCol.includes(kat);
        var show = matchQ && matchKat;
        tr.style.display = show ? '' : 'none';
        if (show) count++;
    });
    var el = document.getElementById('countInfo');
    if (el) el.textContent = count + ' produk';
}

document.getElementById('cari-produk')?.addEventListener('input', filterProdukTable);
document.getElementById('filter-kategori')?.addEventListener('change', filterProdukTable);
</script>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
