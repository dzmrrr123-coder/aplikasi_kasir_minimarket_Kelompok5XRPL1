<?php

declare(strict_types=1);

/**
 * Halaman transaksi penjualan (kasir).
 *
 * Wireframe: form pencarian produk, tabel keranjang (produk, qty, harga,
 * subtotal, tombol hapus), dan sidebar ringkasan (subtotal, kode diskon,
 * total, metode pembayaran tunai/non-tunai, jumlah dibayar, kembalian,
 * tombol proses pembayaran dan batalkan).
 *
 * Keranjang disimpan di $_SESSION sebagai array item:
 *   [
 *     'produk_id' => int,
 *     'nama'      => string,
 *     'harga'     => float,
 *     'qty'       => int,
 *     'stok'      => int,   // stok saat item dimasukkan (untuk cek qty)
 *     'subtotal'  => float,
 *   ]
 *
 * Alur mengikuti spesifikasi: buat Transaksi -> tambahItem (cek stok, tolak
 * bila kurang) -> hitungTotal -> terapkanDiskon (opsional) -> proses
 * pembayaran -> bila berhasil, update stok & struk.
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;
use App\Models\Diskon;
use App\Models\Kasir;
use App\Models\PembayaranNonTunai;
use App\Models\PembayaranTunai;
use App\Models\Produk;
use App\Models\Transaksi;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login dulu (role kasir atau admin).
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$namaUser = (string) ($_SESSION['nama'] ?? 'Kasir');

$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);

if (!isset($_SESSION['keranjang'])) {
    $_SESSION['keranjang'] = [];
}

/**
 * @return array<int, array{produk_id:int,nama:string,harga:float,qty:int,stok:int,subtotal:float}>
 */
function keranjang(): array
{
    return $_SESSION['keranjang'] ?? [];
}

function subtotalKeranjang(array $keranjang): float
{
    $total = 0.0;

    foreach ($keranjang as $item) {
        $total += $item['subtotal'];
    }

    return $total;
}

function formatRupiah(float $jumlah): string
{
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}

function redirectSelf(string $pesan): never
{
    $_SESSION['pesan'] = $pesan;
    header('Location: transaksi.php');
    exit;
}

/** Tambah produk ke keranjang via method Transaksi::tambahItem(). */
function aksiTambahItem(int $produkId, int $qty, int $kasirId): void
{
    $produk = Produk::cari($produkId);

    if ($produk === null) {
        redirectSelf('Produk tidak ditemukan.');
    }

    // Keranjang di-build sebagai objek Transaksi supaya validasi stok
    // (dan clone produk) persis mengikuti method yang sudah ada.
    $transaksi = new Transaksi(['kasir_id' => $kasirId]);
    $transaksi->tambahItem($produk, $qty);

    $item = $transaksi->getItems()[0];

    $keranjang = keranjang();
    $kunci = (string) $produkId;
    $sudahAda = false;

    foreach ($keranjang as &$baris) {
        if ($baris['produk_id'] === $produkId) {
            // Item sudah ada: gabungkan qty, stok di-refresh dari DB.
            $qtyBaru = $baris['qty'] + $qty;

            if ($produk->getStok() < $qtyBaru) {
                redirectSelf(
                    sprintf('Stok "%s" tidak cukup (tersedia: %d).', $produk->getNama(), $produk->getStok())
                );
            }

            $baris['qty']      = $qtyBaru;
            $baris['stok']     = $produk->getStok();
            $baris['subtotal'] = $produk->getHarga() * $qtyBaru;
            $sudahAda = true;
            break;
        }
    }
    unset($baris);

    if (!$sudahAda) {
        $keranjang[$kunci] = [
            'produk_id' => $produkId,
            'nama'      => $produk->getNama(),
            'harga'     => $produk->getHarga(),
            'qty'       => $qty,
            'stok'      => $produk->getStok(),
            'subtotal'  => $produk->getHarga() * $qty,
        ];
    }

    $_SESSION['keranjang'] = $keranjang;
    redirectSelf(sprintf('"%s" ditambahkan ke keranjang.', $produk->getNama()));
}

/** Hapus satu baris keranjang. */
function aksiHapusItem(int $produkId): void
{
    $keranjang = keranjang();
    unset($keranjang[(string) $produkId]);
    $_SESSION['keranjang'] = $keranjang;

    redirectSelf('Item dihapus dari keranjang.');
}

/** Terapkan diskon lewat method Transaksi::terapkanDiskon(). */
function aksiTerapkanDiskon(string $kode): void
{
    $diskon = Diskon::cariBerdasarkanKode($kode);

    if ($diskon === null) {
        redirectSelf('Kode diskon tidak valid.');
    }

    $_SESSION['diskon_id']  = (int) $diskon->getId();
    $_SESSION['diskon_jenis'] = $diskon->getJenis();
    $_SESSION['diskon_nilai'] = $diskon->getNilai();

    redirectSelf('Kode diskon diterapkan.');
}

/**
 * Proses pembayaran lewat Transaksi::prosesPembayaran() + Kasir::prosesTransaksi().
 */
function aksiBayar(string $metode, float $jumlahDibayar, int $kasirId, string $namaUser): void
{
    $keranjang = keranjang();

    if ($keranjang === []) {
        redirectSelf('Keranjang masih kosong.');
    }

    $transaksi = new Transaksi(['kasir_id' => $kasirId]);

    foreach ($keranjang as $item) {
        $produk = Produk::cari($item['produk_id']);

        if ($produk === null) {
            redirectSelf('Produk tidak ditemukan, keranjang tidak valid.');
        }

        $transaksi->tambahItem($produk, $item['qty']);
    }

    $transaksi->hitungTotal();

    // Diskon hanya dipakai bila masih valid dan kodenya tersimpan.
    $diskonId = $_SESSION['diskon_id'] ?? null;

    if ($diskonId !== null) {
        $diskon = Diskon::cari((int) $diskonId);

        if ($diskon !== null) {
            $transaksi->terapkanDiskon($diskon);
            $transaksi->hitungTotal();
        }
    }

    $pembayaran = $metode === 'non_tunai'
        ? new PembayaranNonTunai(['jumlah' => $jumlahDibayar])
        : new PembayaranTunai(['jumlah' => $jumlahDibayar]);

    // Jalur gagal: pembayaran ditolak -> transaksi tidak tersimpan.
    if (!$pembayaran->proses()) {
        redirectSelf('Jumlah pembayaran tidak valid.');
    }

    // Jalur sukses: proses pembayaran -> simpan + update stok + struk.
    $kasir = new Kasir(['id' => $kasirId, 'nama' => $namaUser]);
    $kasir->prosesTransaksi($transaksi);
    $selesai = $transaksi->prosesPembayaran($pembayaran);

    if (!$selesai) {
        redirectSelf('Pembayaran gagal diproses.');
    }

    $struk = $kasir->cetakStruk($transaksi)->cetak();

    // Keranjang & diskon dibersihkan setelah transaksi selesai.
    $_SESSION['keranjang'] = [];
    unset(
        $_SESSION['diskon_id'],
        $_SESSION['diskon_jenis'],
        $_SESSION['diskon_nilai'],
        $_SESSION['struk']
    );
    $_SESSION['struk'] = $struk;

    redirectSelf('Pembayaran berhasil. Struk siap dicetak.');
}

/** Batalkan seluruh keranjang + diskon. */
function aksiBatalkan(): void
{
    $_SESSION['keranjang'] = [];
    unset(
        $_SESSION['diskon_id'],
        $_SESSION['diskon_jenis'],
        $_SESSION['diskon_nilai']
    );

    redirectSelf('Keranjang dibatalkan.');
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

        case 'tambah_item':
            aksiTambahItem(
                (int) ($_POST['produk_id'] ?? 0),
                (int) ($_POST['qty'] ?? 1),
                $userId
            );
            break;

        case 'hapus_item':
            aksiHapusItem((int) ($_POST['produk_id'] ?? 0));
            break;

        case 'diskon':
            aksiTerapkanDiskon(trim((string) ($_POST['kode_diskon'] ?? '')));
            break;

        case 'bayar':
            aksiBayar(
                (string) ($_POST['metode'] ?? 'tunai'),
                (float) ($_POST['jumlah_dibayar'] ?? 0),
                $userId,
                $namaUser
            );
            break;

        case 'batalkan':
            aksiBatalkan();
            break;
    }
}

// ---- Data untuk tampilan ----
$keranjang  = keranjang();
$subtotal   = subtotalKeranjang($keranjang);
$diskonId   = $_SESSION['diskon_id'] ?? null;
$diskon     = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
$potongan   = 0.0;

if ($diskon !== null) {
    $potongan = $subtotal - $diskon->terapkan($subtotal);
}

$total      = max(0.0, $subtotal - $potongan);
$metode     = $_POST['metode'] ?? 'tunai';
$jumlahBayar = (float) ($_POST['jumlah_dibayar'] ?? 0);
$kembalian  = max(0.0, $jumlahBayar - $total);
$struk      = $_SESSION['struk'] ?? '';
$produkDitemukan = null;

if (isset($_GET['cari']) && trim($_GET['cari']) !== '') {
    $kata = trim($_GET['cari']);
    $semua = Produk::semua();

    foreach ($semua as $p) {
        if (mb_stripos($p->getNama(), $kata) !== false) {
            $produkDitemukan = $p;
            break;
        }
    }
}

$produkSemua = Produk::semua();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transaksi Penjualan - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f8; }
        .pos-card { border: 0; border-radius: .75rem; box-shadow: 0 .125rem .375rem rgba(16,24,40,.06); }
        .qty-input { max-width: 4.5rem; }
        .summary-total { font-size: 1.75rem; font-weight: 700; }
        .hasil-cari { min-height: 4.5rem; }
        @media (max-width: 991.98px) {
            .ringkasan { margin-top: 1.5rem; }
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">

    <header class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
        <div>
            <h1 class="h3 mb-0">Transaksi Penjualan</h1>
            <span class="text-muted small">Kasir: <?= htmlspecialchars($namaUser) ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Kembali ke Beranda</a>
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

    <?php if ($struk !== ''): ?>
        <div class="card pos-card mb-4">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span>Struk terakhir</span>
                <button type="button" class="btn-close btn-close-white" id="tutup-struk" aria-label="Tutup"></button>
            </div>
            <div class="card-body">
                <pre class="mb-0"><?= htmlspecialchars($struk) ?></pre>
            </div>
        </div>
        <script>
            document.getElementById('tutup-struk').addEventListener('click', function () {
                fetch('transaksi.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'aksi=hapus_struk'
                }).then(function () { location.reload(); });
            });
        </script>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Kolom kiri: pencarian + keranjang -->
        <div class="col-lg-8">
            <div class="card pos-card mb-4">
                <div class="card-header bg-white">Cari Produk</div>
                <div class="card-body">
                    <form method="get" class="row g-2">
                        <div class="col">
                            <input
                                type="search"
                                name="cari"
                                class="form-control"
                                placeholder="Ketik nama produk lalu Enter..."
                                value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>"
                                aria-label="Cari produk"
                            >
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Cari</button>
                        </div>
                    </form>

                    <div class="hasil-cari mt-3">
                        <?php if (isset($_GET['cari']) && trim($_GET['cari']) !== ''): ?>
                            <?php if ($produkDitemukan === null): ?>
                                <div class="text-danger">Produk tidak ditemukan.</div>
                            <?php else: ?>
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border rounded p-2">
                                    <div>
                                        <strong><?= htmlspecialchars($produkDitemukan->getNama()) ?></strong>
                                        <span class="text-muted ms-2"><?= formatRupiah($produkDitemukan->getHarga()) ?></span>
                                        <span class="badge text-bg-<?= $produkDitemukan->getStok() > 0 ? 'success' : 'danger' ?> ms-2">
                                            stok <?= $produkDitemukan->getStok() ?>
                                        </span>
                                    </div>
                                    <form method="post" class="d-flex gap-2">
                                        <input type="hidden" name="aksi" value="tambah_item">
                                        <input type="hidden" name="produk_id" value="<?= $produkDitemukan->getId() ?>">
                                        <input
                                            type="number"
                                            name="qty"
                                            class="form-control qty-input"
                                            value="1"
                                            min="1"
                                            max="<?= max(1, $produkDitemukan->getStok()) ?>"
                                            required
                                        >
                                        <button
                                            type="submit"
                                            class="btn btn-success"
                                            <?= $produkDitemukan->getStok() < 1 ? 'disabled' : '' ?>
                                        >
                                            Tambah
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted small">Gunakan kolom di atas untuk mencari produk, atau pilih dari daftar cepat di bawah.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card pos-card mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Keranjang</span>
                    <span class="text-muted small"><?= count($keranjang) ?> item</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($keranjang === []): ?>
                        <div class="p-4 text-center text-muted">Keranjang masih kosong.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Produk</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Harga</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($keranjang as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['nama']) ?></td>
                                            <td class="text-center"><?= $item['qty'] ?></td>
                                            <td class="text-end"><?= formatRupiah($item['harga']) ?></td>
                                            <td class="text-end"><?= formatRupiah($item['subtotal']) ?></td>
                                            <td class="text-center">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="aksi" value="hapus_item">
                                                    <input type="hidden" name="produk_id" value="<?= $item['produk_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus dari keranjang">Hapus</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card pos-card">
                <div class="card-header bg-white">Produk Lain</div>
                <div class="card-body">
                    <?php if ($produkSemua === []): ?>
                        <div class="text-muted small">Belum ada produk tersimpan.</div>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($produkSemua as $p): ?>
                                <div class="col-sm-6 col-md-4">
                                    <div class="border rounded p-2 d-flex flex-column gap-1 h-100">
                                        <div class="text-truncate small fw-semibold" title="<?= htmlspecialchars($p->getNama()) ?>">
                                            <?= htmlspecialchars($p->getNama()) ?>
                                        </div>
                                        <div class="small text-muted"><?= formatRupiah($p->getHarga()) ?></div>
                                        <div class="small <?= $p->getStok() > 0 ? 'text-success' : 'text-danger' ?>">
                                            stok <?= $p->getStok() ?>
                                        </div>
                                        <form method="post" class="d-flex gap-1 mt-auto">
                                            <input type="hidden" name="aksi" value="tambah_item">
                                            <input type="hidden" name="produk_id" value="<?= $p->getId() ?>">
                                            <input
                                                type="number"
                                                name="qty"
                                                class="form-control form-control-sm qty-input"
                                                value="1"
                                                min="1"
                                                max="<?= max(1, $p->getStok()) ?>"
                                                required
                                            >
                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success flex-shrink-0"
                                                <?= $p->getStok() < 1 ? 'disabled' : '' ?>
                                            >
                                                Tambah
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Kolom kanan: ringkasan -->
        <div class="col-lg-4">
            <div class="card pos-card ringkasan">
                <div class="card-header bg-white"><strong>Ringkasan</strong></div>
                <div class="card-body">
                    <dl class="row mb-2">
                        <dt class="col-6 text-muted fw-normal">Subtotal</dt>
                        <dd class="col-6 text-end mb-0"><?= formatRupiah($subtotal) ?></dd>
                    </dl>

                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="aksi" value="diskon">
                        <div class="col-8">
                            <input
                                type="text"
                                name="kode_diskon"
                                class="form-control form-control-sm"
                                placeholder="Kode diskon"
                                value="<?= htmlspecialchars($diskon !== null ? (string) $diskon->getId() : '') ?>"
                                aria-label="Kode diskon"
                            >
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100">Terapkan</button>
                        </div>
                        <?php if ($diskon !== null): ?>
                            <div class="col-12 small text-success">
                                Diskon <?= $diskon->getJenis() === 'persen' ? $diskon->getNilai() . '%' : formatRupiah($diskon->getNilai()) ?>
                                terpasang (-<?= formatRupiah($potongan) ?>)
                            </div>
                        <?php endif; ?>
                    </form>

                    <hr>

                    <div class="d-flex justify-content-between align-items-baseline mb-3">
                        <span class="text-muted">Total</span>
                        <span class="summary-total"><?= formatRupiah($total) ?></span>
                    </div>

                    <form method="post" id="form-bayar">
                        <input type="hidden" name="aksi" value="bayar">

                        <div class="mb-3">
                            <label class="form-label">Metode pembayaran</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="metode" value="tunai" id="metode-tunai"
                                       <?= $metode === 'tunai' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="metode-tunai">Tunai</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="metode" value="non_tunai" id="metode-nontunai"
                                       <?= $metode === 'non_tunai' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="metode-nontunai">Non-tunai (QRIS / EDC)</label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="jumlah-dibayar" class="form-label">Jumlah dibayar</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                id="jumlah-dibayar"
                                name="jumlah_dibayar"
                                class="form-control"
                                value="<?= $jumlahBayar > 0 ? $jumlahBayar : (int) ceil($total) ?>"
                                inputmode="decimal"
                            >
                        </div>

                        <div class="d-flex justify-content-between align-items-baseline mb-4">
                            <span class="text-muted">Kembalian</span>
                            <span class="fw-semibold" id="kembalian"><?= formatRupiah($kembalian) ?></span>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg" <?= $keranjang === [] ? 'disabled' : '' ?>>
                                Proses Pembayaran
                            </button>
                            <button type="submit" class="btn btn-outline-danger" form="form-batalkan" <?= $keranjang === [] ? 'disabled' : '' ?>>
                                Batalkan
                            </button>
                        </div>
                    </form>
                    <form method="post" id="form-batalkan" class="d-none">
                        <input type="hidden" name="aksi" value="batalkan">
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Hitung kembalian di sisi klien saat jumlah dibayar berubah.
    (function () {
        var total = <?= json_encode($total) ?>;
        var input = document.getElementById('jumlah-dibayar');
        var kembalian = document.getElementById('kembalian');

        function hitung() {
            var bayar = parseFloat(input.value) || 0;
            kembalian.textContent = 'Rp ' + (bayar - total).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }
        input.addEventListener('input', hitung);
    })();
</script>
</body>
</html>
