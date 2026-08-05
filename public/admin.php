<?php

declare(strict_types=1);

/**
 * Halaman admin: CRUD kategori & produk.
 *
 * - Kategori: tambah, edit, hapus. Hapus dibatasi bila kategori masih dipakai
 *   produk (FK RESTRICT di skema).
 * - Produk: tambah, edit (nama, harga, stok, kategori), hapus. Validasi
 *   mengikuti Produk::validasi() (kategori valid, nama tidak kosong, harga
 *   dan stok tidak negatif). Stok menipis ditandai lewat cekStokMenipis().
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Kategori;
use App\Models\Produk;

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

// Batal edit (via tautan).
if (isset($_GET['batal_edit_kategori'])) {
    unset($_SESSION['edit_kategori_id']);
    header('Location: admin.php');
    exit;
}

if (isset($_GET['batal_edit_produk'])) {
    unset($_SESSION['edit_produk_id']);
    header('Location: admin.php');
    exit;
}

function redirectSelf(string $pesan): never
{
    $_SESSION['pesan'] = $pesan;
    header('Location: admin.php');
    exit;
}

function redirectSelfDenganEdit(string $pesan, int $editProdukId, int $editKategoriId): never
{
    $_SESSION['pesan'] = $pesan;
    $_SESSION['edit_produk_id'] = $editProdukId;
    $_SESSION['edit_kategori_id'] = $editKategoriId;
    header('Location: admin.php');
    exit;
}

/** Simpan atau perbarui kategori. */
function aksiSimpanKategori(string $namaKategori): void
{
    $namaKategori = trim($namaKategori);

    if ($namaKategori === '') {
        redirectSelf('Nama kategori tidak boleh kosong.');
    }

    $editId = (int) ($_SESSION['edit_kategori_id'] ?? 0);
    unset($_SESSION['edit_kategori_id']);

    if ($editId > 0) {
        $kategori = Kategori::cari($editId);

        if ($kategori === null) {
            redirectSelf('Kategori tidak ditemukan.');
        }

        $kategori->setNama($namaKategori);
        $kategori->perbarui();

        redirectSelf('Kategori diperbarui.');
    }

    $kategori = new Kategori(['nama' => $namaKategori]);
    $kategori->simpan();

    redirectSelf('Kategori ditambahkan.');
}

/** Hapus kategori; ditolak bila masih dipakai produk (FK RESTRICT). */
function aksiHapusKategori(int $id): void
{
    $kategori = Kategori::cari($id);

    if ($kategori === null) {
        redirectSelf('Kategori tidak ditemukan.');
    }

    try {
        $kategori->hapus();
        redirectSelf('Kategori dihapus.');
    } catch (\Throwable $e) {
        redirectSelf('Kategori tidak bisa dihapus, masih dipakai produk.');
    }
}

/** Simpan atau perbarui produk. */
function aksiSimpanProduk(array $data): void
{
    $editId = (int) ($_SESSION['edit_produk_id'] ?? 0);
    unset($_SESSION['edit_produk_id']);

    $nama = trim((string) ($data['nama'] ?? ''));
    $harga = (float) ($data['harga'] ?? 0);
    $stok = (int) ($data['stok'] ?? 0);
    $kategoriId = (int) ($data['kategori_id'] ?? 0);

    // Validasi cepat: kategori harus dipilih & valid.
    $kategori = Kategori::cari($kategoriId);

    if ($kategori === null) {
        redirectSelfDenganEdit('Kategori tidak valid, pilih kategori yang tersedia.', $editId, 0);
    }

    if ($nama === '') {
        redirectSelfDenganEdit('Nama produk tidak boleh kosong.', $editId, 0);
    }

    if ($harga < 0) {
        redirectSelfDenganEdit('Harga produk tidak boleh negatif.', $editId, 0);
    }

    if ($stok < 0) {
        redirectSelfDenganEdit('Stok produk tidak boleh negatif.', $editId, 0);
    }

    if ($editId > 0) {
        $produk = Produk::cari($editId);

        if ($produk === null) {
            redirectSelf('Produk tidak ditemukan.');
        }

        $produk->setNama($nama);
        $produk->setHarga($harga);
        $produk->setStok($stok);
        $produk->setKategori($kategori);
        $produk->perbarui();

        redirectSelf('Produk diperbarui.');
    }

    $produk = new Produk([
        'nama'        => $nama,
        'harga'       => $harga,
        'stok'        => $stok,
        'kategori_id' => $kategoriId,
    ]);
    $produk->simpan();

    redirectSelf('Produk ditambahkan.');
}

/** Hapus produk. */
function aksiHapusProduk(int $id): void
{
    $produk = Produk::cari($id);

    if ($produk === null) {
        redirectSelf('Produk tidak ditemukan.');
    }

    try {
        $produk->hapus();
        redirectSelf('Produk dihapus.');
    } catch (\Throwable $e) {
        redirectSelf('Produk tidak bisa dihapus, masih dipakai transaksi.');
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

        case 'simpan_kategori':
            aksiSimpanKategori((string) ($_POST['nama_kategori'] ?? ''));
            break;

        case 'hapus_kategori':
            aksiHapusKategori((int) ($_POST['kategori_id'] ?? 0));
            break;

        case 'edit_kategori':
            $_SESSION['edit_kategori_id'] = (int) ($_POST['kategori_id'] ?? 0);
            redirectSelf('');
            break;

        case 'simpan_produk':
            aksiSimpanProduk($_POST);
            break;

        case 'hapus_produk':
            aksiHapusProduk((int) ($_POST['produk_id'] ?? 0));
            break;

        case 'edit_produk':
            $_SESSION['edit_produk_id'] = (int) ($_POST['produk_id'] ?? 0);
            redirectSelf('');
            break;
    }
}

// ---- Data untuk tampilan ----
$kategoriSemua = Kategori::semua();
$produkSemua = Produk::semua();

// Produk/kategori yang sedang diedit (kalau ada).
$editKategoriId = (int) ($_SESSION['edit_kategori_id'] ?? 0);
$editProdukId = (int) ($_SESSION['edit_produk_id'] ?? 0);
$editKategori = $editKategoriId > 0 ? Kategori::cari($editKategoriId) : null;
$editProduk = $editProdukId > 0 ? Produk::cari($editProdukId) : null;

function formatRupiah(float $jumlah): string
{
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}

// Produk dengan stok menipis (pakai cekStokMenipis()).
$stokMenipis = array_values(array_filter(
    $produkSemua,
    static fn (Produk $p): bool => $p->cekStokMenipis()
));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f8; }
        .pos-card { border: 0; border-radius: .75rem; box-shadow: 0 .125rem .375rem rgba(16,24,40,.06); }
        .stok-menipis { color: #b45309; font-weight: 600; }
        .stok-habis { color: #b91c1c; font-weight: 600; }
        .table-produk td { vertical-align: middle; }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">

    <header class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
        <div>
            <h1 class="h3 mb-0">Halaman Admin</h1>
            <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="transaksi.php" class="btn btn-outline-primary btn-sm">Buka Kasir</a>
            <a href="laporan.php" class="btn btn-outline-primary btn-sm">Laporan Penjualan</a>
            <a href="supplier.php" class="btn btn-outline-primary btn-sm">Supplier</a>
            <a href="retur.php" class="btn btn-outline-primary btn-sm">Retur Barang</a>
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

    <?php if ($stokMenipis !== []): ?>
        <div class="alert alert-warning py-2" role="alert">
            <strong>Perhatian:</strong> <?= count($stokMenipis) ?> produk dengan stok menipis
            (stok &le; 10).
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Kategori -->
        <div class="col-lg-4">
            <div class="card pos-card mb-4">
                <div class="card-header bg-white"><strong>Kategori</strong></div>
                <div class="card-body">
                    <?php if ($editKategori !== null): ?>
                        <form method="post" class="mb-3">
                            <input type="hidden" name="aksi" value="simpan_kategori">
                            <label for="nama-kategori" class="form-label">Edit kategori</label>
                            <div class="input-group">
                                <input
                                    type="text"
                                    id="nama-kategori"
                                    name="nama_kategori"
                                    class="form-control"
                                    value="<?= htmlspecialchars($editKategori->getNama()) ?>"
                                    required
                                >
                                <button type="submit" class="btn btn-primary">Simpan</button>
                            </div>
                            <a href="?batal_edit_kategori=1" class="small">Batal edit</a>
                        </form>
                    <?php else: ?>
                        <form method="post" class="mb-3">
                            <input type="hidden" name="aksi" value="simpan_kategori">
                            <label for="nama-kategori" class="form-label">Tambah kategori</label>
                            <div class="input-group">
                                <input
                                    type="text"
                                    id="nama-kategori"
                                    name="nama_kategori"
                                    class="form-control"
                                    placeholder="cth: Minuman"
                                    required
                                >
                                <button type="submit" class="btn btn-success">Tambah</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($kategoriSemua === []): ?>
                        <div class="text-muted small">Belum ada kategori.</div>
                    <?php else: ?>
                        <ul class="list-group">
                            <?php foreach ($kategoriSemua as $k): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($k->getNama()) ?></span>
                                    <span class="d-flex gap-1">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="aksi" value="edit_kategori">
                                            <input type="hidden" name="kategori_id" value="<?= $k->getId() ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Edit">Edit</button>
                                        </form>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Hapus kategori ini?');">
                                            <input type="hidden" name="aksi" value="hapus_kategori">
                                            <input type="hidden" name="kategori_id" value="<?= $k->getId() ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">Hapus</button>
                                        </form>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Produk -->
        <div class="col-lg-8">
            <div class="card pos-card mb-4">
                <div class="card-header bg-white">
                    <?= $editProduk !== null ? 'Edit Produk' : 'Tambah Produk' ?>
                </div>
                <div class="card-body">
                    <?php if ($editProduk !== null): ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="aksi" value="simpan_produk">
                            <div class="col-md-6">
                                <label for="nama-produk" class="form-label">Nama produk</label>
                                <input
                                    type="text"
                                    id="nama-produk"
                                    name="nama"
                                    class="form-control"
                                    value="<?= htmlspecialchars($editProduk->getNama()) ?>"
                                    required
                                >
                            </div>
                            <div class="col-md-3">
                                <label for="harga-produk" class="form-label">Harga</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="harga-produk"
                                    name="harga"
                                    class="form-control"
                                    value="<?= $editProduk->getHarga() ?>"
                                    required
                                >
                            </div>
                            <div class="col-md-3">
                                <label for="stok-produk" class="form-label">Stok</label>
                                <input
                                    type="number"
                                    min="0"
                                    id="stok-produk"
                                    name="stok"
                                    class="form-control"
                                    value="<?= $editProduk->getStok() ?>"
                                    required
                                >
                            </div>
                            <div class="col-md-6">
                                <label for="kategori-produk" class="form-label">Kategori</label>
                                <select id="kategori-produk" name="kategori_id" class="form-select" required>
                                    <option value="">Pilih kategori...</option>
                                    <?php foreach ($kategoriSemua as $k): ?>
                                        <option
                                            value="<?= $k->getId() ?>"
                                            <?= $k->getId() === $editProduk->getKategori()->getId() ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($k->getNama()) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                                <a href="?batal_edit_produk=1" class="btn btn-outline-secondary">Batal</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="aksi" value="simpan_produk">
                            <div class="col-md-6">
                                <label for="nama-produk" class="form-label">Nama produk</label>
                                <input
                                    type="text"
                                    id="nama-produk"
                                    name="nama"
                                    class="form-control"
                                    placeholder="cth: Teh Botol"
                                    required
                                >
                            </div>
                            <div class="col-md-3">
                                <label for="harga-produk" class="form-label">Harga</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="harga-produk"
                                    name="harga"
                                    class="form-control"
                                    placeholder="0"
                                    required
                                >
                            </div>
                            <div class="col-md-3">
                                <label for="stok-produk" class="form-label">Stok</label>
                                <input
                                    type="number"
                                    min="0"
                                    id="stok-produk"
                                    name="stok"
                                    class="form-control"
                                    placeholder="0"
                                    required
                                >
                            </div>
                            <div class="col-md-6">
                                <label for="kategori-produk" class="form-label">Kategori</label>
                                <select id="kategori-produk" name="kategori_id" class="form-select" required>
                                    <option value="">Pilih kategori...</option>
                                    <?php foreach ($kategoriSemua as $k): ?>
                                        <option value="<?= $k->getId() ?>"><?= htmlspecialchars($k->getNama()) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success">Tambah Produk</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Daftar Produk</span>
                    <span class="text-muted small"><?= count($produkSemua) ?> produk</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($produkSemua === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada produk.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 table-produk">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nama</th>
                                        <th>Kategori</th>
                                        <th class="text-end">Harga</th>
                                        <th class="text-center">Stok</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($produkSemua as $p): ?>
                                        <?php $stok = $p->getStok(); ?>
                                        <tr>
                                            <td><?= htmlspecialchars($p->getNama()) ?></td>
                                            <td><?= htmlspecialchars($p->getKategori()->getNama()) ?></td>
                                            <td class="text-end"><?= formatRupiah($p->getHarga()) ?></td>
                                            <td class="text-center">
                                                <?php if ($p->cekStokMenipis()): ?>
                                                    <span class="<?= $stok <= 0 ? 'stok-habis' : 'stok-menipis' ?>">
                                                        <?= $stok ?>
                                                    </span>
                                                    <?= $stok <= 0 ? '(habis)' : '(menipis)' ?>
                                                <?php else: ?>
                                                    <?= $stok ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="d-inline-flex gap-1">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="aksi" value="edit_produk">
                                                        <input type="hidden" name="produk_id" value="<?= $p->getId() ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Edit">Edit</button>
                                                    </form>
                                                    <form method="post" class="d-inline"
                                                          onsubmit="return confirm('Hapus produk ini?');">
                                                        <input type="hidden" name="aksi" value="hapus_produk">
                                                        <input type="hidden" name="produk_id" value="<?= $p->getId() ?>">
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
