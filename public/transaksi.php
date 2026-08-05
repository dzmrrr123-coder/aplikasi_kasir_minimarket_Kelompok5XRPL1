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
use App\Models\LaporanPenjualan;
use App\Models\PembayaranNonTunai;
use App\Models\PembayaranTunai;
use App\Models\Produk;
use App\Models\Struk;
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
$pesanTipe = $_SESSION['pesan_tipe'] ?? 'info';
unset($_SESSION['pesan_tipe']);

// Permintaan AJAX: semua halaman dikirim via fetch tanpa reload.
// Server membedakan lewat header X-Requested-With supaya responsnya
// berupa JSON (fragment HTML) alih-alih redirect HTTP.
$adalahAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
// Untuk mode non-AJAX (fallback), flash message tetap dikirim via session + redirect.
$modeNonAjax = !$adalahAjax;

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

/**
 * Gabungan fragment keranjang (kiri) + panel kanan kiosk (userbar,
 * ringkasan, numpad). Dipakai respons AJAX supaya halaman bisa
 * diperbarui tanpa reload.
 */
function renderFragmentKeranjang(): string
{
    return renderFragmentKeranjangKiri() . renderFragmentKananKiosk();
}

/**
 * Render card keranjang (kolom kiri) dari session.
 */
function renderFragmentKeranjangKiri(): string
{
    $keranjang = $_SESSION['keranjang'] ?? [];

    ob_start();
    ?>
    <!-- Keranjang -->
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
                                        <form method="post" data-aksi="hapus_item" class="d-inline">
                                            <input type="hidden" name="aksi" value="hapus_item">
                                            <input type="hidden" name="produk_id" value="<?= $item['produk_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus dari keranjang"><i class="bi bi-x-circle me-1"></i>Hapus</button>
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
    <?php
    return (string) ob_get_clean();
}

/**
 * Render card ringkasan (kolom kanan) dari session.
 */
function renderFragmentKeranjangKanan(): string
{
    $keranjang = $_SESSION['keranjang'] ?? [];
    $subtotal = 0.0;

    foreach ($keranjang as $item) {
        $subtotal += $item['subtotal'];
    }

    $diskonId = $_SESSION['diskon_id'] ?? null;
    $diskon = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
    $potongan = 0.0;

    if ($diskon !== null) {
        $potongan = $subtotal - $diskon->terapkan($subtotal);
    }

    $total = max(0.0, $subtotal - $potongan);
    $jumlahBayar = (float) ($_POST['jumlah_dibayar'] ?? 0);
    $kembalian = $jumlahBayar - $total;

    ob_start();
    ?>
    <!-- Ringkasan -->
    <div class="card pos-card ringkasan">
        <div class="card-header bg-white"><strong>Ringkasan</strong></div>
        <div class="card-body">
            <dl class="row mb-2">
                <dt class="col-6 text-muted fw-normal">Subtotal</dt>
                <dd class="col-6 text-end mb-0"><?= formatRupiah($subtotal) ?></dd>
            </dl>

            <form method="post" data-aksi="diskon" class="row g-2 mb-3">
                <input type="hidden" name="aksi" value="diskon">
                <div class="col-8">
                    <input
                        type="text"
                        name="kode_diskon"
                        class="form-control form-control-sm"
                        placeholder="Kode diskon"
                        value="<?= htmlspecialchars($diskon !== null ? $diskon->getKode() : '') ?>"
                        aria-label="Kode diskon"
                    >
                </div>
                <div class="col-4">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-tag me-1"></i>Terapkan</button>
                </div>
                <?php if ($diskon !== null): ?>
                    <div class="col-12 small text-success">
                        Diskon <?= $diskon->getKode() !== '' ? $diskon->getKode() . ' ' : '' ?>
                        (<?= $diskon->getJenis() === 'persen' ? $diskon->getNilai() . '%' : formatRupiah($diskon->getNilai()) ?>)
                        terpasang (-<?= formatRupiah($potongan) ?>)
                    </div>
                <?php endif; ?>
                <?php $diskonSemuaR = Diskon::semua(); ?>
                <?php if ($diskonSemuaR !== []): ?>
                    <div class="col-12 small text-muted">
                        Kode valid:
                        <?php foreach ($diskonSemuaR as $d): ?>
                            <span class="badge text-bg-light border"><?= htmlspecialchars($d->getKode()) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </form>

            <hr>

            <div class="d-flex justify-content-between align-items-baseline mb-3">
                <span class="text-muted">Total</span>
                <span class="summary-total"><?= formatRupiah($total) ?></span>
            </div>

            <form method="post" id="form-bayar" data-aksi="bayar">
                <input type="hidden" name="aksi" value="bayar">
                <span id="total-json" class="d-none"><?= $total ?></span>

                <div class="mb-3">
                    <label class="form-label">Metode pembayaran</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="metode" value="tunai" id="metode-tunai" checked>
                        <label class="form-check-label" for="metode-tunai">Tunai</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="metode" value="non_tunai" id="metode-nontunai">
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
                        value="<?= $total > 0 ? (int) ceil($total) : 0 ?>"
                        inputmode="decimal"
                    >
                </div>

                <div class="d-flex justify-content-between align-items-baseline mb-4">
                    <span class="text-muted">Kembalian</span>
                    <span class="fw-semibold" id="kembalian"><?= formatRupiah(max(0.0, $kembalian)) ?></span>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg" <?= $keranjang === [] ? 'disabled data-kosong' : '' ?>>
                        <i class="bi bi-check2-circle me-1"></i>Proses Pembayaran
                    </button>
                    <button type="submit" class="btn btn-outline-danger" form="form-batalkan" data-aksi="batalkan" <?= $keranjang === [] ? 'disabled' : '' ?>>
                        <i class="bi bi-x-lg me-1"></i>Batalkan
                    </button>
                </div>
            </form>
            <form method="post" id="form-batalkan" class="d-none">
                <input type="hidden" name="aksi" value="batalkan">
            </form>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Panel kanan Kiosk Mode (30%): userbar (nama kasir + logout),
 * ringkasan (subtotal/total/kembalian), dan numpad pembayaran.
 */
function renderFragmentKananKiosk(): string
{
    global $namaUser;

    $keranjang = $_SESSION['keranjang'] ?? [];
    $subtotal = 0.0;

    foreach ($keranjang as $item) {
        $subtotal += $item['subtotal'];
    }

    $diskonId = $_SESSION['diskon_id'] ?? null;
    $diskon = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
    $potongan = 0.0;

    if ($diskon !== null) {
        $potongan = $subtotal - $diskon->terapkan($subtotal);
    }

    $total = max(0.0, $subtotal - $potongan);
    $jumlahBayar = (float) ($_POST['jumlah_dibayar'] ?? 0);
    $kembalian = $jumlahBayar - $total;

    ob_start();
    ?>
    <!-- Userbar: nama kasir + logout (wajib ada di panel kanan) -->
    <div class="kiosk-userbar">
        <div class="kiosk-user">
            <i class="bi bi-person-circle"></i>
            <span><?= htmlspecialchars($namaUser) ?></span>
        </div>
        <form method="post" class="d-inline">
            <input type="hidden" name="aksi" value="logout">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </button>
        </form>
    </div>

    <!-- Ringkasan -->
    <div class="kiosk-ringkasan">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="kiosk-total-label">Total</span>
            <span class="kiosk-total-nilai font-num" id="kiosk-total"><?= formatRupiah($total) ?></span>
        </div>
        <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Subtotal</span>
            <span class="kiosk-sub font-num"><?= formatRupiah($subtotal) ?></span>
        </div>
        <?php if ($diskon !== null): ?>
            <div class="d-flex justify-content-between small mb-1">
                <span class="text-muted">Diskon <?= htmlspecialchars($diskon->getKode()) ?></span>
                <span class="kiosk-sub font-num text-success">-<?= formatRupiah($potongan) ?></span>
            </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between small mb-3">
            <span class="text-muted">Kembalian</span>
            <span class="kiosk-sub font-num <?= $kembalian < 0 ? 'text-danger' : '' ?>" id="kembalian">
                <?= $kembalian < 0 ? 'Kurang ' . formatRupiah(abs($kembalian)) : formatRupiah($kembalian) ?>
            </span>
        </div>
    </div>

    <!-- Form pembayaran + numpad -->
    <form method="post" id="form-bayar" data-aksi="bayar" class="mb-3">
        <input type="hidden" name="aksi" value="bayar">
        <span id="total-json" class="d-none"><?= $total ?></span>

        <div class="mb-3">
            <label class="form-label">Metode pembayaran</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="metode" value="tunai" id="metode-tunai" checked>
                    <label class="form-check-label" for="metode-tunai">Tunai</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="metode" value="non_tunai" id="metode-nontunai">
                    <label class="form-check-label" for="metode-nontunai">QRIS/EDC</label>
                </div>
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
                class="form-control form-control-lg font-num text-end"
                value="<?= $total > 0 ? (int) ceil($total) : 0 ?>"
                inputmode="decimal"
            >
        </div>

        <div class="kiosk-numpad mb-3">
            <div class="numpad-grid">
                <button type="button" data-num="1">1</button>
                <button type="button" data-num="2">2</button>
                <button type="button" data-num="3">3</button>
                <button type="button" data-num="4">4</button>
                <button type="button" data-num="5">5</button>
                <button type="button" data-num="6">6</button>
                <button type="button" data-num="7">7</button>
                <button type="button" data-num="8">8</button>
                <button type="button" data-num="9">9</button>
                <button type="button" data-num="00">00</button>
                <button type="button" data-num="0">0</button>
                <button type="button" data-num="hapus" class="numpad-hapus"><i class="bi bi-backspace"></i></button>
                <button type="button" data-num="bersih" class="numpad-aksi">C</button>
                <button type="button" data-num="maks" class="numpad-aksi">UANG PAS</button>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-success btn-lg" <?= $keranjang === [] ? 'disabled data-kosong' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i>Proses Pembayaran
            </button>
            <button type="submit" class="btn btn-outline-danger" form="form-batalkan" data-aksi="batalkan" <?= $keranjang === [] ? 'disabled' : '' ?>>
                <i class="bi bi-x-lg me-1"></i>Batalkan
            </button>
        </div>
    </form>
    <form method="post" id="form-batalkan" class="d-none">
        <input type="hidden" name="aksi" value="batalkan">
    </form>
    <?php
    return (string) ob_get_clean();
}
function kirimRespons(string $pesan, string $tipe, string $fragment, array $data = []): never
{
    global $adalahAjax, $modeNonAjax;

    if ($modeNonAjax) {
        $_SESSION['pesan'] = $pesan;
        $_SESSION['pesan_tipe'] = $tipe;
        header('Location: transaksi.php');
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'pesan'    => $pesan,
        'tipe'     => $tipe,
        'fragment' => $fragment,
        'data'     => $data,
    ]);
    exit;
}

/** Redirect (non-AJAX) atau kirim JSON (AJAX) dengan pesan flash. */
function redirectSelf(string $pesan, string $tipe = 'info'): never
{
    global $adalahAjax, $modeNonAjax;

    if ($modeNonAjax) {
        $_SESSION['pesan'] = $pesan;
        $_SESSION['pesan_tipe'] = $tipe;
        header('Location: transaksi.php');
        exit;
    }

    // AJAX: kirim fragment keranjang + ringkasan terkini (sudah berubah
    // di session oleh aksi yang memanggil redirectSelf ini).
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'pesan'    => $pesan,
        'tipe'     => $tipe,
        'fragment' => renderFragmentKeranjang(),
    ]);
    exit;
}

/** Tambah produk ke keranjang via method Transaksi::tambahItem(). */
function aksiTambahItem(int $produkId, int $qty, int $kasirId): void
{
    $produk = Produk::cari($produkId);

    if ($produk === null) {
        redirectSelf('Produk tidak ditemukan.', 'danger');
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
                    sprintf('Stok "%s" tidak cukup (tersedia: %d).', $produk->getNama(), $produk->getStok()),
                    'danger'
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
    redirectSelf(sprintf('"%s" ditambahkan ke keranjang.', $produk->getNama()), 'success');
}

/** Hapus satu baris keranjang. */
function aksiHapusItem(int $produkId): void
{
    $keranjang = keranjang();
    unset($keranjang[(string) $produkId]);
    $_SESSION['keranjang'] = $keranjang;

    redirectSelf('Item dihapus dari keranjang.', 'success');
}

/** Terapkan diskon lewat method Transaksi::terapkanDiskon(). */
function aksiTerapkanDiskon(string $kode): void
{
    $diskon = Diskon::cariBerdasarkanKode($kode);

    if ($diskon === null) {
        redirectSelf('Kode diskon tidak valid.', 'danger');
    }

    $_SESSION['diskon_id']  = (int) $diskon->getId();
    $_SESSION['diskon_jenis'] = $diskon->getJenis();
    $_SESSION['diskon_nilai'] = $diskon->getNilai();

    redirectSelf('Kode diskon diterapkan.', 'success');
}

/**
 * Proses pembayaran lewat Transaksi::prosesPembayaran() + Kasir::prosesTransaksi().
 */
function aksiBayar(string $metode, float $jumlahDibayar, int $kasirId, string $namaUser): void
{
    $keranjang = keranjang();

    if ($keranjang === []) {
        redirectSelf('Keranjang masih kosong.', 'danger');
    }

    $transaksi = new Transaksi(['kasir_id' => $kasirId]);

    foreach ($keranjang as $item) {
        $produk = Produk::cari($item['produk_id']);

        if ($produk === null) {
            redirectSelf('Produk tidak ditemukan, keranjang tidak valid.', 'danger');
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

    // Strategy Pattern: injeksi strategi pembayaran via setter (DI).
    $transaksi->setMetodePembayaran($pembayaran);

    // Observer Pattern: daftarkan observer pasca-penyelesaian.
    // Struk menyiapkan JSON struk, LaporanPenjualan mencatat rekap ke DB.
    $strukObserver = new Struk($transaksi);
    $transaksi->attach($strukObserver);
    $transaksi->attach(new LaporanPenjualan());

    // Jalur sukses: proses pembayaran -> simpan + update stok + struk.
    $kasir = new Kasir(['id' => $kasirId, 'nama' => $namaUser]);
    $kasir->prosesTransaksi($transaksi);
    $selesai = $transaksi->prosesPembayaran();

    if (!$selesai) {
        redirectSelf('Pembayaran gagal diproses.', 'danger');
    }

    // Struk observer sudah menyiapkan JSON via notify(); teks struk
    // tetap dihasilkan dari objek Struk yang sama.
    $struk = $strukObserver->cetak();

    // Keranjang & diskon dibersihkan setelah transaksi selesai.
    $_SESSION['keranjang'] = [];
    unset(
        $_SESSION['diskon_id'],
        $_SESSION['diskon_jenis'],
        $_SESSION['diskon_nilai'],
        $_SESSION['struk']
    );
    $_SESSION['struk'] = $struk;

    // AJAX: kirim struk + fragment keranjang kosong supaya UI langsung
    // menampilkan struk tanpa reload. Non-AJAX: redirect seperti biasa.
    global $adalahAjax, $modeNonAjax;

    if ($modeNonAjax) {
        redirectSelf('Pembayaran berhasil. Struk siap dicetak.', 'success');
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'pesan'    => 'Pembayaran berhasil. Struk siap dicetak.',
        'tipe'     => 'success',
        'struk'    => $struk,
        'fragment' => renderFragmentKeranjang(),
    ]);
    exit;
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

    redirectSelf('Keranjang dibatalkan.', 'info');
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

        case 'hapus_struk':
            unset($_SESSION['struk']);

            if ($adalahAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['pesan' => '', 'tipe' => 'info', 'hapus_struk' => true]);
                exit;
            }

            header('Location: transaksi.php');
            exit;
    }
}

// ---- Data untuk tampilan ----
$keranjang  = keranjang();
$subtotal   = subtotalKeranjang($keranjang);
$diskonSemua = Diskon::semua();
$diskonId   = $_SESSION['diskon_id'] ?? null;
$diskon     = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
$potongan   = 0.0;

if ($diskon !== null) {
    $potongan = $subtotal - $diskon->terapkan($subtotal);
}

$total      = max(0.0, $subtotal - $potongan);
$metode     = $_POST['metode'] ?? 'tunai';
$jumlahBayar = (float) ($_POST['jumlah_dibayar'] ?? 0);
// Kembalian boleh negatif: kasir harus lihat "kurang Rp X" biar tahu
// jumlah yang dibayar belum menutupi total.
$kembalian  = $jumlahBayar - $total;
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .qty-input { max-width: 4.5rem; }
        .summary-total { font-size: 1.75rem; font-weight: 700; }
        .hasil-cari { min-height: 4.5rem; }
        @media (max-width: 991.98px) {
            .ringkasan { margin-top: 1.5rem; }
        }
    </style>
</head>
<body class="kiosk-body dark-mode">
<div class="kiosk-wrapper">
    <!-- KOLOM KIRI (70%): pencarian + keranjang + produk -->
    <div class="kiosk-kiri">

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h1 class="h3 mb-0">Transaksi Penjualan</h1>
            <span class="text-muted small">Kasir: <?= htmlspecialchars($namaUser) ?></span>
        </div>
    </div>

    <div id="flash-pesan" class="alert alert-<?= htmlspecialchars($pesanTipe) ?> alert-dismissible fade show <?= $pesan === '' ? 'd-none' : '' ?>" role="alert">
        <?= htmlspecialchars($pesan) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>

    <?php if ($struk !== ''): ?>
        <div class="card pos-card mb-4" id="area-struk">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span>Struk terakhir</span>
                <button type="button" class="btn-close btn-close-white" id="tutup-struk" aria-label="Tutup"></button>
            </div>
            <div class="card-body">
                <pre class="mb-0"><?= htmlspecialchars($struk) ?></pre>
            </div>
        </div>
    <?php else: ?>
        <div class="card pos-card mb-4 d-none" id="area-struk">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span>Struk terakhir</span>
                <button type="button" class="btn-close btn-close-white" id="tutup-struk" aria-label="Tutup"></button>
            </div>
            <div class="card-body">
                <pre class="mb-0"></pre>
            </div>
        </div>
    <?php endif; ?>

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
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Cari</button>
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
                            <form method="post" class="d-flex gap-2" data-aksi="tambah_item">
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
                                    <i class="bi bi-cart-plus me-1"></i>Tambah
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

    <div id="fragmen-keranjang-kiri"><?= renderFragmentKeranjangKiri() ?></div>

    <div class="card pos-card">
        <div class="card-header bg-white">Produk Lain</div>
        <div class="card-body">
            <?php if ($produkSemua === []): ?>
                <div class="text-muted small">Belum ada produk tersimpan.</div>
            <?php else: ?>
                <div class="row g-2">
                    <?php foreach ($produkSemua as $p): ?>
                        <div class="col-sm-6 col-md-4 col-xl-3">
                            <div class="kiosk-produk d-flex flex-column gap-1 h-100">
                                <div class="kiosk-produk-nama text-truncate" title="<?= htmlspecialchars($p->getNama()) ?>">
                                    <?= htmlspecialchars($p->getNama()) ?>
                                </div>
                                <div class="kiosk-produk-harga font-num"><?= formatRupiah($p->getHarga()) ?></div>
                                <div class="small <?= $p->getStok() > 0 ? 'text-success' : 'text-danger' ?>">
                                    stok <?= $p->getStok() ?>
                                </div>
                                <form method="post" class="d-flex gap-1 mt-auto" data-aksi="tambah_item">
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
                                        <i class="bi bi-cart-plus me-1"></i>Tambah
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    </div><!-- /.kiosk-kiri -->

    <!-- KOLOM KANAN (30%): userbar + ringkasan + numpad -->
    <div class="kiosk-kanan">
        <div id="fragmen-keranjang-kanan"><?= renderFragmentKananKiosk() ?></div>
    </div><!-- /.kiosk-kanan -->
</div><!-- /.kiosk-wrapper -->


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Transaksi tanpa reload: semua form data-aksi dikirim via fetch,
    // server mengembalikan JSON {fragment, struk?, pesan, tipe},
    // lalu UI diperbarui langsung tanpa memuat ulang halaman.
    (function () {
        'use strict';

        var flash = document.getElementById('flash-pesan');
        var fragKiri = document.getElementById('fragmen-keranjang-kiri');
        var fragKanan = document.getElementById('fragmen-keranjang-kanan');

        function rupiah(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        function tampilkanFlash(pesan, tipe) {
            if (!flash || !pesan) return;
            flash.className = 'alert alert-' + (tipe || 'info') + ' alert-dismissible fade show';
            flash.innerHTML = pesan + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>';
            flash.classList.remove('d-none');
        }

        function tampilkanStruk(teks) {
            var area = document.getElementById('area-struk');
            if (!area) return;
            var pre = area.querySelector('pre');
            if (pre) pre.textContent = teks;
            area.classList.remove('d-none');
        }

        // Re-init kembalian live setelah fragment ringkasan diganti.
        function initKembalian() {
            var totalEl = document.getElementById('total-json');
            if (!totalEl) return;
            var total = parseFloat(totalEl.textContent) || 0;
            var input = document.getElementById('jumlah-dibayar');
            var kembalian = document.getElementById('kembalian');
            var tombol = document.querySelector('#form-bayar button[type="submit"]');
            if (!input || !kembalian) return;

            function hitung() {
                var bayar = parseFloat(input.value) || 0;
                var selisih = bayar - total;
                if (selisih < 0) {
                    kembalian.textContent = 'Kurang ' + rupiah(Math.abs(selisih));
                    kembalian.classList.add('text-danger');
                    if (tombol) tombol.disabled = true;
                } else {
                    kembalian.textContent = rupiah(selisih);
                    kembalian.classList.remove('text-danger');
                    if (tombol && !tombol.dataset.kosong) tombol.disabled = false;
                }
            }
            input.addEventListener('input', hitung);
            hitung();
        }

        function gantiFragment(fragment) {
            // Fragment berisi card keranjang (kiri) + panel kanan kiosk.
            if (!fragment || !fragKiri || !fragKanan) return;
            var tmp = document.createElement('div');
            tmp.innerHTML = fragment;

            var kiri = tmp.querySelector('.card.pos-card.mb-4');
            var kanan = tmp.querySelector('.kiosk-userbar');

            if (kiri) {
                fragKiri.innerHTML = '';
                fragKiri.appendChild(kiri);
            }
            if (kanan) {
                // Panel kanan kiosk: userbar + ringkasan + numpad.
                fragKanan.innerHTML = tmp.querySelector('.kiosk-userbar').parentElement
                    ? tmp.querySelector('.kiosk-userbar').parentElement.innerHTML
                    : '';
                initNumpad();
            }

            initKembalian();
        }

        // Numpad: isi input jumlah dibayar.
        function initNumpad() {
            var input = document.getElementById('jumlah-dibayar');
            if (!input) return;
            var grid = document.querySelector('.numpad-grid');
            if (!grid) return;

            grid.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-num]');
                if (!btn) return;
                var aksi = btn.getAttribute('data-num');

                if (aksi === 'hapus') {
                    input.value = input.value.slice(0, -1);
                } else if (aksi === 'bersih') {
                    input.value = '';
                } else if (aksi === 'maks') {
                    var totalEl = document.getElementById('total-json');
                    var total = totalEl ? parseFloat(totalEl.textContent) || 0 : 0;
                    input.value = String(Math.ceil(total));
                } else {
                    input.value = (input.value || '') + aksi;
                }

                // Trigger event input utk hitung kembalian.
                input.dispatchEvent(new Event('input'));
            });
        }

        // Shortcut keyboard: F1 = fokus pencarian, F2 = fokus jumlah dibayar.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'F1') {
                e.preventDefault();
                var cari = document.querySelector('input[name="cari"]');
                if (cari) cari.focus();
            } else if (e.key === 'F2') {
                e.preventDefault();
                var bayar = document.getElementById('jumlah-dibayar');
                if (bayar) bayar.focus();
            }
        });

        document.addEventListener('submit', function (e) {
            var form = e.target;
            var aksi = form.getAttribute('data-aksi');
            if (!aksi) return; // form biasa (mis. pencarian GET) tetap normal

            e.preventDefault();
            var data = new FormData(form);
            data.set('aksi', aksi);

            fetch('transaksi.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: data
            })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (res) {
                    tampilkanFlash(res.pesan, res.tipe);
                    if (res.struk) {
                        tampilkanStruk(res.struk);
                    }
                    if (res.fragment) {
                        gantiFragment(res.fragment);
                    } else if (res.hapus_struk) {
                        var area = document.getElementById('area-struk');
                        if (area) area.classList.add('d-none');
                    }
                })
                .catch(function (err) {
                    tampilkanFlash('Terjadi kesalahan: ' + err.message, 'danger');
                });
        });

        // Tutup struk via AJAX tanpa reload.
        var tutupStruk = document.getElementById('tutup-struk');
        if (tutupStruk) {
            tutupStruk.addEventListener('click', function () {
                fetch('transaksi.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'aksi=hapus_struk'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.hapus_struk) {
                            var area = document.getElementById('area-struk');
                            if (area) area.classList.add('d-none');
                        }
                    });
            });
        }

        initKembalian();
        initNumpad();
    })();
</script>
<script src="assets/theme.js"></script>
</body>
</html>
