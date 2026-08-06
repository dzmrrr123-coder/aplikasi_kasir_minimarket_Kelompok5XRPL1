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

    try {
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
    } catch (\Throwable $e) {
        redirectSelf(pesanErrorRamah($e));
    }
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

/**
 * Simpan file gambar produk ke public/uploads/.
 * Hanya izinkan gambar (jpg/jpeg/png/webp/gif), maks 2 MB.
 * Mengembalikan string error (kosong = sukses; nama file di $GLOBALS).
 */
function simpanGambarProduk(array $file): string
{
    $izin = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $tipe = (string) ($file['type'] ?? '');

    if (!isset($izin[$tipe])) {
        return 'Gambar harus berformat JPG, PNG, WEBP, atau GIF.';
    }

    if ((int) $file['size'] > 2 * 1024 * 1024) {
        return 'Ukuran gambar maksimal 2 MB.';
    }

    // Verifikasi isi file server-side: getimagesize() membaca header asli
    // file, bukan header yang diklaim browser (bisa dipalsukan attacker).
    $info = @getimagesize((string) ($file['tmp_name'] ?? ''));

    if ($info === false) {
        return 'File yang diunggah bukan gambar yang valid.';
    }

    $tipeAsli = (string) ($info['mime'] ?? '');
    $ekstensi = $izin[$tipeAsli] ?? '';

    if ($ekstensi === '') {
        return 'Gambar harus berformat JPG, PNG, WEBP, atau GIF.';
    }

    $namaFile = 'produk-' . bin2hex(random_bytes(8)) . '.' . $ekstensi;
    $folder = __DIR__ . '/uploads';

    if (!is_dir($folder)) {
        mkdir($folder, 0775, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $namaFile)) {
        return 'Gagal menyimpan gambar.';
    }

    $GLOBALS['gambar_terupload'] = $namaFile;

    return '';
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
    $barcode = trim((string) ($data['barcode'] ?? ''));
    $hargaBeli = (float) ($data['harga_beli'] ?? 0);
    $stokMinimum = (int) ($data['stok_minimum'] ?? 0);
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $satuan = (string) ($data['satuan'] ?? 'pcs');
    $hargaPerGram = (float) ($data['harga_per_gram'] ?? 0);
    $gambar = trim((string) ($data['gambar_lama'] ?? ''));

    // Normalisasi satuan & harga per gram.
    $satuan = $satuan === 'gram' ? 'gram' : 'pcs';
    $hargaPerGram = $satuan === 'gram' ? $hargaPerGram : 0.0;

    // Upload gambar produk (opsional). Disimpan ke public/uploads/.
    if (!empty($_FILES['gambar']['name']) && is_uploaded_file($_FILES['gambar']['tmp_name'] ?? '')) {
        $file = $_FILES['gambar'];
        $error = simpanGambarProduk($file);

        if ($error !== '') {
            redirectSelfDenganEdit($error, $editId, 0);
        } else {
            $gambar = $GLOBALS['gambar_terupload'];
            unset($GLOBALS['gambar_terupload']);
        }
    }

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

    try {
        if ($editId > 0) {
            $produk = Produk::cari($editId);

            if ($produk === null) {
                redirectSelf('Produk tidak ditemukan.');
            }

            $produk->setNama($nama);
            $produk->setHarga($harga);
            $produk->setStok($stok);
            $produk->setKategori($kategori);
            $produk->setBarcode($barcode);
            $produk->setHargaBeli($hargaBeli);
            $produk->setStokMinimum($stokMinimum);
            $produk->setSupplierId($supplierId);
            $produk->setSatuan($satuan);
            $produk->setHargaPerGram($hargaPerGram);
            $produk->setGambar($gambar);
            $produk->perbarui();

            redirectSelf('Produk diperbarui.');
        }

        $produk = new Produk([
            'nama'           => $nama,
            'harga'          => $harga,
            'stok'           => $stok,
            'kategori_id'    => $kategoriId,
            'barcode'        => $barcode,
            'harga_beli'     => $hargaBeli,
            'stok_minimum'   => $stokMinimum,
            'supplier_id'    => $supplierId,
            'satuan'         => $satuan,
            'harga_per_gram' => $hargaPerGram,
            'gambar'         => $gambar,
        ]);
        $produk->simpan();

        redirectSelf('Produk ditambahkan.');
    } catch (\Throwable $e) {
        redirectSelfDenganEdit(pesanErrorRamah($e), $editId, 0);
    }
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
    require_csrf();
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
$supplierSemua = Supplier::semua();

// Produk/kategori yang sedang diedit (kalau ada).
$editKategoriId = (int) ($_SESSION['edit_kategori_id'] ?? 0);
$editProdukId = (int) ($_SESSION['edit_produk_id'] ?? 0);
$editKategori = $editKategoriId > 0 ? Kategori::cari($editKategoriId) : null;
$editProduk = $editProdukId > 0 ? Produk::cari($editProdukId) : null;
$aktif = 'admin';

// ID produk yang sedang diedit (dipakai JS untuk ambil harga beli otomatis).
$produkEditId = $editProduk?->getId() ?? 0;

function formatRupiah(float $jumlah): string
{
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}

// Produk dengan stok menipis (alert; tabel produk diisi via DataTables).
$stokMenipis = Produk::cariStokMenipis();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="produk-id-edit" content="<?= (int) $produkEditId ?>">
    <title>Admin - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .table-produk td { vertical-align: middle; }
    </style>
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Halaman Admin</h1>
        <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
    </div>

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
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></button>
                                        </form>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Hapus kategori ini?');">
                                            <input type="hidden" name="aksi" value="hapus_kategori">
                                            <input type="hidden" name="kategori_id" value="<?= $k->getId() ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash"></i></button>
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
                        <form method="post" class="row g-3" enctype="multipart/form-data">
                            <input type="hidden" name="aksi" value="simpan_produk">
                            <input type="hidden" name="gambar_lama" value="<?= htmlspecialchars($editProduk->getGambar()) ?>">
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
                            <div class="col-md-3">
                                <label for="satuan-produk" class="form-label">Satuan</label>
                                <select id="satuan-produk" name="satuan" class="form-select">
                                    <option value="pcs" <?= $editProduk->getSatuan() === 'pcs' ? 'selected' : '' ?>>pcs (satuan)</option>
                                    <option value="gram" <?= $editProduk->getSatuan() === 'gram' ? 'selected' : '' ?>>gram (curah)</option>
                                </select>
                                <div class="form-text">gram = dijual per berat.</div>
                            </div>
                            <div class="col-md-3" id="blok-harga-per-gram">
                                <label for="harga-per-gram" class="form-label">Harga per gram</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="harga-per-gram"
                                    name="harga_per_gram"
                                    class="form-control"
                                    value="<?= $editProduk->getHargaPerGram() ?>"
                                    placeholder="cth: 25"
                                >
                                <div class="form-text">Wajib untuk produk gram.</div>
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
                            <div class="col-md-6">
                                <label for="barcode-produk" class="form-label">Barcode</label>
                                <input
                                    type="text"
                                    id="barcode-produk"
                                    name="barcode"
                                    class="form-control"
                                    value="<?= htmlspecialchars($editProduk->getBarcode()) ?>"
                                    placeholder="cth: 8991002101234"
                                >
                                <div class="form-text">Kosongkan bila tidak memakai scan.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="harga-beli-produk" class="form-label">Harga beli</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="harga-beli-produk"
                                    name="harga_beli"
                                    class="form-control"
                                    value="<?= $editProduk->getHargaBeli() ?>"
                                >
                            </div>
                            <div class="col-md-4">
                                <label for="stok-min-produk" class="form-label">Stok minimum</label>
                                <input
                                    type="number"
                                    min="0"
                                    id="stok-min-produk"
                                    name="stok_minimum"
                                    class="form-control"
                                    value="<?= $editProduk->getStokMinimum() ?>"
                                >
                                <div class="form-text">Notifikasi stok menipis.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="supplier-produk" class="form-label">Supplier</label>
                                <select id="supplier-produk" name="supplier_id" class="form-select">
                                    <option value="">Tidak ada</option>
                                    <?php foreach ($supplierSemua as $s): ?>
                                        <option
                                            value="<?= $s->getId() ?>"
                                            <?= (int) $s->getId() === $editProduk->getSupplierId() ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($s->getNama()) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="gambar-produk" class="form-label">Gambar produk</label>
                                <input
                                    type="file"
                                    id="gambar-produk"
                                    name="gambar"
                                    class="form-control"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                >
                                <div class="form-text">JPG/PNG/WEBP/GIF, maks 2 MB.</div>
                            </div>
                            <div class="col-md-8 d-flex align-items-center">
                                <?php if ($editProduk->getGambar() !== ''): ?>
                                    <img
                                        src="uploads/<?= htmlspecialchars($editProduk->getGambar()) ?>"
                                        alt="Gambar produk"
                                        class="rounded border"
                                        style="max-height: 80px; max-width: 120px; object-fit: contain;"
                                        onerror="this.style.display='none';"
                                    >
                                <?php else: ?>
                                    <span class="text-muted small"><i class="bi bi-image me-1"></i>Belum ada gambar</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                                <a href="?batal_edit_produk=1" class="btn btn-outline-secondary">Batal</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="post" class="row g-3" enctype="multipart/form-data">
                            <input type="hidden" name="aksi" value="simpan_produk">
                            <input type="hidden" name="gambar_lama" value="">
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
                            <div class="col-md-3">
                                <label for="satuan-produk" class="form-label">Satuan</label>
                                <select id="satuan-produk" name="satuan" class="form-select">
                                    <option value="pcs" selected>pcs (satuan)</option>
                                    <option value="gram">gram (curah)</option>
                                </select>
                                <div class="form-text">gram = dijual per berat.</div>
                            </div>
                            <div class="col-md-3" id="blok-harga-per-gram">
                                <label for="harga-per-gram" class="form-label">Harga per gram</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="harga-per-gram"
                                    name="harga_per_gram"
                                    class="form-control"
                                    placeholder="cth: 25"
                                >
                                <div class="form-text">Wajib untuk produk gram.</div>
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
                            <div class="col-md-6">
                                <label for="barcode-produk" class="form-label">Barcode</label>
                                <input
                                    type="text"
                                    id="barcode-produk"
                                    name="barcode"
                                    class="form-control"
                                    placeholder="cth: 8991002101234"
                                >
                                <div class="form-text">Kosongkan bila tidak memakai scan.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="harga-beli-produk" class="form-label">Harga beli</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="harga-beli-produk"
                                    name="harga_beli"
                                    class="form-control"
                                    placeholder="0"
                                >
                            </div>
                            <div class="col-md-4">
                                <label for="stok-min-produk" class="form-label">Stok minimum</label>
                                <input
                                    type="number"
                                    min="0"
                                    id="stok-min-produk"
                                    name="stok_minimum"
                                    class="form-control"
                                    placeholder="0"
                                >
                                <div class="form-text">Notifikasi stok menipis.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="supplier-produk" class="form-label">Supplier</label>
                                <select id="supplier-produk" name="supplier_id" class="form-select">
                                    <option value="">Tidak ada</option>
                                    <?php foreach ($supplierSemua as $s): ?>
                                        <option value="<?= $s->getId() ?>"><?= htmlspecialchars($s->getNama()) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="gambar-produk" class="form-label">Gambar produk</label>
                                <input
                                    type="file"
                                    id="gambar-produk"
                                    name="gambar"
                                    class="form-control"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                >
                                <div class="form-text">JPG/PNG/WEBP/GIF, maks 2 MB.</div>
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
                    <span class="text-muted small">DataTables</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 table-produk" id="tabel-produk">
                            <thead class="table-light">
                                <tr>
                                    <th>Nama</th>
                                    <th>Kategori</th>
                                    <th>Barcode</th>
                                    <th>Supplier</th>
                                    <th class="text-end">Harga</th>
                                    <th class="text-center">Stok</th>
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
<script>
    // Tabel produk via DataTables server-side (api.php → InventarisController → Produk::getDataTabel).
    (function () {
        if (!window.jQuery || !window.DataTable) return;

        function rupiah(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        jQuery('#tabel-produk').DataTable({
            serverSide: true,
            ajax: { url: 'api.php?aksi=produk.tabel', data: function (d) { d.draw = d.draw || 0; } },
            pageLength: 10,
            lengthChange: false,
            order: [],
            columns: [
                { data: 'nama' },
                { data: 'kategori' },
                { data: 'barcode', render: function (d) { return d ? '<span class="font-num small">' + d + '</span>' : '<span class="text-muted">—</span>'; } },
                { data: 'supplier_nama', render: function (d) { return d ? d : '<span class="text-muted">—</span>'; } },
                { data: 'harga', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                {
                    data: 'stok',
                    className: 'text-center',
                    render: function (d, t, row) {
                        var stok = Number(d);
                        var min = Number(row.stok_minimum) || 10; // fallback ambang umum
                        if (stok <= min) {
                            return '<span class="' + (stok <= 0 ? 'stok-habis' : 'stok-menipis') + '">' + stok + '</span>' +
                                (stok <= 0 ? ' (habis)' : ' (menipis)');
                        }
                        return stok;
                    }
                },
                {
                    data: 'id',
                    className: 'text-center',
                    orderable: false,
                    render: function (d) {
                        return '<span class="d-inline-flex gap-1">' +
                            '<form method="post" class="d-inline">' +
                            '<input type="hidden" name="aksi" value="edit_produk">' +
                            '<input type="hidden" name="produk_id" value="' + d + '">' +
                            '<button type="submit" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil me-1"></i>Edit</button>' +
                            '</form>' +
                            '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus produk ini?\');">' +
                            '<input type="hidden" name="aksi" value="hapus_produk">' +
                            '<input type="hidden" name="produk_id" value="' + d + '">' +
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

    // Toggle field "Harga per gram" sesuai satuan yang dipilih.
    (function () {
        var pilih = document.querySelectorAll('select[name="satuan"]');
        pilih.forEach(function (sel) {
            function sinkron() {
                var blok = document.getElementById('blok-harga-per-gram');
                if (!blok) return;
                var gram = sel.value === 'gram';
                blok.style.display = gram ? '' : 'none';
                var input = document.getElementById('harga-per-gram');
                if (input) input.required = gram;
            }
            sel.addEventListener('change', sinkron);
            sinkron();
        });
    })();

    // Harga beli otomatis: saat supplier dipilih, ambil harga beli terakhir
    // produk dari supplier itu (riwayat pembelian) lalu isi field-nya.
    (function () {
        var supplier = document.querySelector('select[name="supplier_id"]');
        var hargaBeli = document.getElementById('harga-beli-produk');
        if (!supplier || !hargaBeli) return;

        // Produk yang sedang diedit (edit mode) — untuk tahu produk_id.
        function ambilProdukId() {
            // Form edit: produk_id tidak ada di form; pakai id dari tombol
            // edit yang disimpan di session server. Untuk tambah: 0 (kosong).
            var meta = document.querySelector('meta[name="produk-id-edit"]');
            return meta ? parseInt(meta.getAttribute('content'), 10) : 0;
        }

        function sinkronHargaBeli() {
            var supplierId = parseInt(supplier.value, 10) || 0;
            var produkId = ambilProdukId();

            // Kalau bukan mode edit, tidak ada produk yang dipilih —
            // harga beli diisi dari supplier saja tidak bisa (butuh produk).
            if (produkId <= 0) return;

            fetch('api.php?aksi=produk.harga_beli&produk_id=' + produkId + '&supplier_id=' + supplierId)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && typeof d.harga_beli === 'number') {
                        hargaBeli.value = d.harga_beli;
                    }
                })
                .catch(function () { /* biarkan */ });
        }

        supplier.addEventListener('change', sinkronHargaBeli);
    })();
</script>
</body>
</html>
