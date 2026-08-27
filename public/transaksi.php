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
use App\Models\Member;
use App\Models\NotifikasiAntrian;
use App\Models\NotifikasiWhatsApp;
use App\Models\PembayaranNonTunai;
use App\Models\PembayaranTunai;
use App\Models\Produk;
use App\Models\ShiftKasir;
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

// Shift kasir aktif (buka kas). Bila tidak ada, kasir wajib buka kas dulu
// sebelum bisa transaksi.
$shiftAktif = ShiftKasir::shiftAktif($userId);
$wajibBukaKas = $shiftAktif === null;

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
 * Render grid "Produk Lain" dari DB (stok aktual). Dipakai ulang setelah
 * pembayaran supaya stok di daftar cepat ikut ter-refresh tanpa reload.
 */
function renderFragmentProduk(): string
{
    $produkSemua = Produk::semua();

    $semuaKategori = [];
    try {
        $semuaKategori = \App\Models\Kategori::semua();
    } catch (\Throwable $e) {
        $semuaKategori = [];
    }

    ob_start();
    ?>
    <div id="fragmen-produk">
    <div class="card pos-card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-grid-3x3-gap me-1"></i>Katalog Cepat Produk</span>
            <span class="text-muted small"><?= count($produkSemua) ?> produk</span>
        </div>
        <div class="card-body">
            <?php if ($produkSemua === []): ?>
                <div class="text-muted small">Belum ada produk tersimpan.</div>
            <?php else: ?>
                <!-- Category Filter Pills -->
                <div class="kiosk-cat-pills">
                    <button type="button" class="cat-pill active" data-cat="all">
                        <i class="bi bi-grid me-1"></i>Semua
                    </button>
                    <?php foreach ($semuaKategori as $kat): ?>
                        <button type="button" class="cat-pill" data-cat="<?= htmlspecialchars(mb_strtolower($kat->getNama())) ?>">
                            <?= htmlspecialchars($kat->getNama()) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="row g-3 kiosk-produk-grid" id="kiosk-grid-produk">
                    <?php foreach ($produkSemua as $p): ?>
                        <?php 
                        $katNama = mb_strtolower($p->getKategori()->getNama() ?: 'umum');
                        $habis = $p->getStok() < 1; 
                        ?>
                        <div class="col-sm-6 col-md-4 col-xl-3 kiosk-produk-col" data-kategori="<?= htmlspecialchars($katNama) ?>">
                            <div class="kiosk-produk d-flex flex-column gap-1 h-100<?php if ($habis) echo ' kiosk-produk-habis'; ?>"
                                 data-stok-habis="<?= $habis ? '1' : '0' ?>">
                                <?php if ($habis): ?>
                                    <span class="badge bg-danger kiosk-badge-habis" title="Stok habis">
                                        <i class="bi bi-emoji-x me-1"></i>HABIS
                                    </span>
                                <?php endif; ?>
                                <?php if ($p->getGambar() !== ''): ?>
                                    <img
                                        src="uploads/<?= htmlspecialchars($p->getGambar()) ?>"
                                        alt="<?= htmlspecialchars($p->getNama()) ?>"
                                        class="kiosk-produk-gambar rounded"
                                        loading="lazy"
                                        onerror="this.style.display='none';"
                                    >
                                <?php endif; ?>
                                <div class="kiosk-produk-nama text-truncate" title="<?= htmlspecialchars($p->getNama()) ?>">
                                    <?= htmlspecialchars($p->getNama()) ?>
                                </div>
                                <div class="kiosk-produk-harga font-num">
                                    <?= $p->getSatuan() === 'gram'
                                        ? formatRupiah($p->getHargaPerGram()) . '/gr'
                                        : formatRupiah($p->getHarga()) ?>
                                </div>
                                <div class="small <?= $habis ? 'text-danger fw-semibold' : 'text-success' ?>">
                                    stok <?= $p->getStok() ?> <?= $p->getSatuan() === 'gram' ? 'gr' : '' ?>
                                </div>
                                <form method="post" class="d-flex flex-column gap-1 mt-auto" data-aksi="tambah_item">
                                    <input type="hidden" name="aksi" value="tambah_item">
                                    <input type="hidden" name="produk_id" value="<?= $p->getId() ?>">
                                    <?php if ($p->getSatuan() === 'gram'): ?>
                                        <div class="d-flex gap-1">
                                            <input
                                                type="number"
                                                name="qty"
                                                class="form-control form-control-sm qty-input"
                                                value="100"
                                                min="1"
                                                step="0.001"
                                                max="<?= max(1, $p->getStok()) ?>"
                                                placeholder="Berat (gr)"
                                                required
                                                data-produk-gram="<?= $p->getId() ?>"
                                                <?= $habis ? 'disabled' : '' ?>
                                            >
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary flex-shrink-0"
                                                data-timbang="<?= $p->getId() ?>"
                                                title="Ambil berat dari timbangan"
                                                <?= $habis ? 'disabled' : '' ?>
                                            >
                                                <i class="bi bi-bullseye"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex gap-1">
                                            <input
                                                type="number"
                                                name="qty"
                                                class="form-control form-control-sm qty-input"
                                                value="1"
                                                min="1"
                                                max="<?= max(1, $p->getStok()) ?>"
                                                required
                                                <?= $habis ? 'disabled' : '' ?>
                                            >
                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success flex-shrink-0"
                                                <?= $habis ? 'disabled' : '' ?>
                                                title="Tambah ke keranjang"
                                            >
                                                <i class="bi bi-cart-plus"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Render card keranjang (kolom kiri) dari session.
 */
function renderFragmentKeranjangKiri(): string
{
    $keranjang = $_SESSION['keranjang'] ?? [];
    $heldCarts = $_SESSION['keranjang_tertunda'] ?? [];
    $jumlahTertunda = count($heldCarts);

    ob_start();
    ?>
    <!-- Keranjang -->
    <div class="card pos-card mb-4" id="card-keranjang-pos">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="fw-bold"><i class="bi bi-cart3 me-1 text-teal"></i>Keranjang Belanja</span>
                <span class="badge text-bg-primary font-num"><?= count($keranjang) ?> jenis barang</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <!-- Tombol Parkir / Panggil Keranjang -->
                <?php if ($jumlahTertunda > 0): ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-warning dropdown-toggle btn-hold-cart" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Panggil keranjang yang ditahan">
                            <i class="bi bi-pause-circle-fill"></i>
                            <span>Diparkir</span>
                            <span class="badge bg-danger rounded-pill badge-hold-count ms-1"><?= $jumlahTertunda ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width: 280px; z-index: 1050;">
                            <li class="dropdown-header small fw-bold text-uppercase text-muted">Daftar Transaksi Ditahan</li>
                            <?php foreach ($heldCarts as $hIdx => $hCart): ?>
                                <li class="p-2 border-bottom">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-semibold small"><?= htmlspecialchars((string)($hCart['catatan'] ?? 'Pelanggan')) ?></span>
                                        <span class="badge bg-light text-dark font-num small"><?= htmlspecialchars((string)($hCart['waktu'] ?? '')) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center small text-muted mb-2">
                                        <span><?= count($hCart['keranjang'] ?? []) ?> item</span>
                                        <strong class="text-teal font-num"><?= formatRupiah((float)($hCart['total'] ?? 0)) ?></strong>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <form method="post" data-aksi="panggil_keranjang" class="flex-fill">
                                            <input type="hidden" name="aksi" value="panggil_keranjang">
                                            <input type="hidden" name="hold_id" value="<?= htmlspecialchars((string)($hCart['id'] ?? (string)$hIdx)) ?>">
                                            <button type="submit" class="btn btn-sm btn-primary w-100 py-0" style="font-size: 0.78rem;">
                                                <i class="bi bi-play-fill me-1"></i>Panggil
                                            </button>
                                        </form>
                                        <form method="post" data-aksi="hapus_tahanan" onsubmit="return confirm('Hapus antrean keranjang tertunda ini?')">
                                            <input type="hidden" name="aksi" value="hapus_tahanan">
                                            <input type="hidden" name="hold_id" value="<?= htmlspecialchars((string)($hCart['id'] ?? (string)$hIdx)) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0" style="font-size: 0.78rem;" title="Hapus">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Tombol Tahan Keranjang Saat Ini -->
                <form method="post" data-aksi="tahan_keranjang" class="d-inline" id="form-tahan-keranjang">
                    <input type="hidden" name="aksi" value="tahan_keranjang">
                    <button type="submit" class="btn btn-sm btn-outline-warning btn-hold-cart" <?= empty($keranjang) ? 'disabled' : '' ?> title="Tahan keranjang untuk pelanggan lain (F7)">
                        <i class="bi bi-pause-circle"></i>
                        <span>Tahan (F7)</span>
                    </button>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if ($keranjang === []): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-basket3 display-6 d-block mb-2 text-muted opacity-50"></i>
                    Keranjang masih kosong. Scan barcode atau pilih produk dari katalog.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Produk</th>
                                <th class="text-center" style="min-width: 120px;">Qty</th>
                                <th class="text-end">Harga</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keranjang as $item): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($item['nama']) ?></div>
                                        <div class="small text-muted font-num"><?= formatRupiah($item['harga']) ?> / <?= htmlspecialchars($item['satuan'] ?? 'pcs') ?></div>
                                    </td>
                                    <td class="text-center">
                                        <?php if (($item['satuan'] ?? 'pcs') === 'gram'): ?>
                                            <span class="font-num fw-semibold"><?= rtrim(rtrim(number_format($item['qty'], 3, ',', '.'), '0'), ',') ?> gr</span>
                                        <?php else: ?>
                                            <div class="cart-qty-stepper">
                                                <button
                                                    type="button"
                                                    class="btn-qty-step btn-step-minus"
                                                    data-produk-id="<?= $item['produk_id'] ?>"
                                                    title="Kurangi 1"
                                                >
                                                    <i class="bi bi-dash"></i>
                                                </button>
                                                <span class="cart-qty-val"><?= (int) $item['qty'] ?></span>
                                                <button
                                                    type="button"
                                                    class="btn-qty-step btn-step-plus"
                                                    data-produk-id="<?= $item['produk_id'] ?>"
                                                    title="Tambah 1"
                                                >
                                                    <i class="bi bi-plus"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-num"><?= formatRupiah($item['harga']) ?></td>
                                    <td class="text-end font-num fw-bold"><?= formatRupiah($item['subtotal']) ?></td>
                                    <td class="text-center">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Void (hapus) item — butuh PIN supervisor"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modal-void"
                                            data-produk-id="<?= $item['produk_id'] ?>"
                                            data-produk-nama="<?= htmlspecialchars($item['nama']) ?>"
                                        >
                                            <i class="bi bi-trash3 me-1"></i>Void
                                        </button>
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

    $diskonId = $_SESSION['diskon_id'] ?? null;
    $diskon = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
    $ringkasan = hitungRingkasanKeranjang($keranjang, $diskon);
    $total = $ringkasan['total'];
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
                <dd class="col-6 text-end mb-0"><?= formatRupiah($ringkasan['subtotal']) ?></dd>
                <?php if ($ringkasan['potongan'] > 0): ?>
                    <dt class="col-6 text-muted fw-normal">Diskon</dt>
                    <dd class="col-6 text-end mb-0 text-success">-<?= formatRupiah($ringkasan['potongan']) ?></dd>
                <?php endif; ?>
                <?php if ($ringkasan['pajak'] > 0): ?>
                    <dt class="col-6 text-muted fw-normal">Pajak (PPN)</dt>
                    <dd class="col-6 text-end mb-0"><?= formatRupiah($ringkasan['pajak']) ?></dd>
                <?php endif; ?>
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
 * Hitung ringkasan keranjang konsisten dengan Transaksi::hitungTotal():
 * subtotal -> diskon (sekali) -> pajak (PPN dari pengaturan).
 * Dipakai di semua panel ringkasan (kiosk & fallback) supaya angka yang
 * ditampilkan = angka yang ditagih.
 *
 * @return array{subtotal:float, potongan:float, pajak:float, total:float}
 */
function hitungRingkasanKeranjang(array $keranjang, ?Diskon $diskon): array
{
    $subtotal = 0.0;

    foreach ($keranjang as $item) {
        $subtotal += $item['subtotal'];
    }

    $subtotal = round($subtotal, 2);
    $totalSetelahDiskon = $diskon !== null
        ? round($diskon->terapkan($subtotal), 2)
        : $subtotal;
    $potongan = round($subtotal - $totalSetelahDiskon, 2);

    $persenPajak = (float) (App\Models\Pengaturan::get('pajak', '0') ?: 0);
    $pajak = $persenPajak > 0 ? round($totalSetelahDiskon * $persenPajak / 100, 2) : 0.0;

    $total = max(0.0, round($totalSetelahDiskon + $pajak, 2));

    return [
        'subtotal'  => $subtotal,
        'potongan'  => $potongan,
        'pajak'     => $pajak,
        'total'     => $total,
    ];
}

/**
 * Panel kanan Kiosk Mode (30%): userbar (nama kasir + logout),
 * ringkasan (subtotal/total/kembalian), dan numpad pembayaran.
 */
function renderFragmentKananKiosk(): string
{
    global $namaUser, $shiftAktif;

    // Shift tidak aktif (belum buka kas / sudah tutup kas): panel kanan
    // dikosongkan. `gantiFragment` memakai ada/tidaknya .kiosk-userbar
    // sebagai penanda — kalau kosong, kolom kanan tetap tersembunyi.
    if ($shiftAktif === null) {
        return '';
    }

    $keranjang = $_SESSION['keranjang'] ?? [];

    $memberId = (int) ($_SESSION['member_id'] ?? 0);
    $member = $memberId > 0 ? Member::cari($memberId) : null;

    $diskonId = $_SESSION['diskon_id'] ?? null;
    $diskon = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
    $ringkasan = hitungRingkasanKeranjang($keranjang, $diskon);
    $total = $ringkasan['total'];
    $jumlahBayar = (float) ($_POST['jumlah_dibayar'] ?? 0);
    $kembalian = $jumlahBayar - $total;

    ob_start();
    $namaKasirArr = explode(' ', trim($namaUser));
    $inisialKasir = mb_strtoupper(mb_substr($namaKasirArr[0], 0, 1));
    if (isset($namaKasirArr[1])) {
        $inisialKasir .= mb_strtoupper(mb_substr($namaKasirArr[1], 0, 1));
    }
    ?>
    <!-- Userbar: nama kasir + audio toggle + status printer + menu -->
    <div class="kiosk-userbar">
        <div class="kiosk-user" data-bs-toggle="offcanvas" data-bs-target="#sidebarKasir" role="button" title="Buka menu kasir">
            <span class="kiosk-user-avatar"><?= htmlspecialchars($inisialKasir) ?></span>
            <div class="kiosk-user-details">
                <span class="kiosk-user-name"><?= htmlspecialchars($namaUser) ?></span>
                <span class="kiosk-user-status">
                    <span class="status-dot-pulse"></span>
                    <span><?= ucfirst((string)($_SESSION['role'] ?? 'Kasir')) ?> Online</span>
                </span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <!-- Audio toggle -->
            <button type="button" class="btn btn-sm btn-outline-secondary kiosk-btn-icon" id="btn-toggle-sound" title="Bunyi Suara POS (Beep)">
                <i class="bi bi-volume-up-fill" id="icon-sound-pos"></i>
            </button>
            <!-- Status printer -->
            <button type="button" class="btn btn-sm btn-outline-secondary kiosk-btn-icon" data-bs-toggle="modal" data-bs-target="#modalPerangkat" title="Perangkat POS">
                <i class="bi bi-printer"></i>
                <span class="badge-status-dot" id="badge-printer-pos"></span>
            </button>
            <!-- Hamburger → buka sidebar semua aksi kasir -->
            <button type="button" class="btn btn-sm btn-primary kiosk-btn-menu"
                    data-bs-toggle="offcanvas" data-bs-target="#sidebarKasir"
                    aria-label="Buka menu" title="Buka menu kasir">
                <i class="bi bi-grid-fill"></i>
                <span>Menu</span>
            </button>
        </div>
    </div>

    <?php if ($shiftAktif !== null): ?>
        <div class="small text-success mb-2">
            <i class="bi bi-cash-stack me-1"></i>Kas buka sejak
            <?= date('d-m-Y H:i', strtotime($shiftAktif->getDibukaPada())) ?>
            · modal <?= formatRupiah($shiftAktif->getModalAwal()) ?>
        </div>
    <?php endif; ?>

    <!-- Member: scan telepon pelanggan untuk poin -->
    <div class="card pos-card mb-3">
        <div class="card-header bg-white py-2"><i class="bi bi-person-badge me-1"></i>Member</div>
        <div class="card-body py-2">
            <?php if ($member !== null): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($member->getNama()) ?></div>
                        <div class="small text-muted font-num"><?= htmlspecialchars($member->getTelepon()) ?> · poin <?= $member->getPoin() ?></div>
                    </div>
                    <form method="post" class="d-inline" data-aksi="hapus_member">
                        <input type="hidden" name="aksi" value="hapus_member">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Lepas member">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <form method="post" data-aksi="set_member" class="d-flex gap-1">
                    <input type="hidden" name="aksi" value="set_member">
                    <input
                        type="text"
                        name="telepon"
                        id="input-telepon-member"
                        class="form-control form-control-sm font-num"
                        placeholder="Scan telepon member (F4)"
                        autocomplete="off"
                        aria-label="Scan telepon member"
                    >
                    <button type="submit" class="btn btn-sm btn-outline-primary flex-shrink-0" title="Pasang member">
                        <i class="bi bi-person-plus"></i>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ringkasan Total & Potongan -->
    <div class="kiosk-ringkasan">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="kiosk-total-label">Total Belanja</span>
            <span class="kiosk-total-nilai font-num" id="kiosk-total"><?= formatRupiah($total) ?></span>
        </div>
        <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Subtotal</span>
            <span class="kiosk-sub font-num"><?= formatRupiah($ringkasan['subtotal']) ?></span>
        </div>
        <?php if ($ringkasan['potongan'] > 0): ?>
            <div class="d-flex justify-content-between small mb-1">
                <span class="text-muted">Diskon <?= $diskon !== null ? htmlspecialchars($diskon->getKode()) : '' ?></span>
                <span class="kiosk-sub font-num text-success">-<?= formatRupiah($ringkasan['potongan']) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($ringkasan['pajak'] > 0): ?>
            <div class="d-flex justify-content-between small mb-1">
                <span class="text-muted">Pajak (PPN)</span>
                <span class="kiosk-sub font-num"><?= formatRupiah($ringkasan['pajak']) ?></span>
            </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between small">
            <span class="text-muted">Status</span>
            <span class="kiosk-sub font-num <?= $kembalian < 0 ? 'text-danger' : 'text-success' ?>" id="kembalian">
                <?= $kembalian < 0 ? 'Kurang ' . formatRupiah(abs($kembalian)) : 'Kembalian ' . formatRupiah($kembalian) ?>
            </span>
        </div>
    </div>

    <!-- Grand Kembalian Display Box -->
    <div class="kiosk-change-card <?= $kembalian < 0 ? 'is-kurang' : 'is-cukup' ?>" id="kiosk-change-card">
        <div class="d-flex justify-content-between align-items-center">
            <span class="kiosk-change-label" id="change-status-label"><?= $kembalian < 0 ? 'Uang Kurang' : 'Kembalian Pelanggan' ?></span>
            <span class="badge text-bg-<?= $kembalian < 0 ? 'danger' : 'success' ?> small" id="change-badge"><?= $kembalian < 0 ? 'Belum Cukup' : 'Siap Selesai' ?></span>
        </div>
        <div class="kiosk-change-val font-num" id="kiosk-change-val">
            <?= $kembalian < 0 ? 'Kurang ' . formatRupiah(abs($kembalian)) : formatRupiah($kembalian) ?>
        </div>
    </div>

    <!-- Form pembayaran + Quick Cash Presets -->
    <form method="post" id="form-bayar" data-aksi="bayar" class="mb-3">
        <input type="hidden" name="aksi" value="bayar">
        <span id="total-json" class="d-none"><?= $total ?></span>

        <div class="mb-3">
            <label class="form-label fw-semibold">Metode Pembayaran</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="metode" value="tunai" id="metode-tunai" checked>
                    <label class="form-check-label fw-medium" for="metode-tunai"><i class="bi bi-cash me-1 text-success"></i>Tunai</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="metode" value="non_tunai" id="metode-nontunai">
                    <label class="form-check-label fw-medium" for="metode-nontunai"><i class="bi bi-qr-code-scan me-1 text-primary"></i>QRIS / EDC</label>
                </div>
            </div>
        </div>

        <div id="jumlah-bayar-group">
            <div class="mb-3">
                <label for="jumlah-dibayar" class="form-label d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Jumlah Dibayar (F2)</span>
                    <span class="text-muted small">Pilih uang cepat:</span>
                </label>

                <!-- Quick Cash Presets -->
                <div class="quick-cash-grid">
                    <button type="button" class="btn-cash-chip chip-exact" data-cash="exact" title="Bayar dengan uang pas (F8)">
                        <i class="bi bi-cash-stack me-1"></i>UANG PAS (<?= formatRupiah($total) ?>)
                    </button>
                    <button type="button" class="btn-cash-chip" data-cash="10000">Rp 10.000</button>
                    <button type="button" class="btn-cash-chip" data-cash="20000">Rp 20.000</button>
                    <button type="button" class="btn-cash-chip" data-cash="50000">Rp 50.000</button>
                    <button type="button" class="btn-cash-chip" data-cash="100000">Rp 100.000</button>
                    <button type="button" class="btn-cash-chip" data-cash="200000">Rp 200.000</button>
                    <button type="button" class="btn-cash-chip" data-cash="500000">Rp 500.000</button>
                </div>

                <input
                    type="number"
                    step="0.01"
                    min="0"
                    id="jumlah-dibayar"
                    name="jumlah_dibayar"
                    class="form-control form-control-lg font-num text-end fw-bold"
                    value="<?= $total > 0 ? (int) ceil($total) : 0 ?>"
                    inputmode="decimal"
                    autocomplete="off"
                >
            </div>

            <div class="kiosk-numpad mb-3 d-none" id="kiosk-numpad">
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
        </div>

        <!-- Info bila metode non-tunai dipilih: tidak perlu input uang tunai -->
        <div id="info-non-tunai" class="alert alert-info py-2 small mb-3 d-none">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-semibold"><i class="bi bi-qr-code-scan me-1"></i>QRIS / EDC Merchant</span>
                <button type="button" class="btn btn-sm btn-primary py-0 px-2" id="btn-buka-modal-qris" data-bs-toggle="modal" data-bs-target="#modal-qris">
                    <i class="bi bi-qr-code me-1"></i>Tampilkan QRIS
                </button>
            </div>
            Total <strong class="font-num" id="info-total-qris"><?= formatRupiah($total) ?></strong> siap dipindai via GoPay, OVO, ShopeePay, BCA, atau EDC.
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-success btn-lg" <?= $keranjang === [] ? 'disabled data-kosong' : '' ?> title="Proses Pembayaran (F9)">
                <i class="bi bi-check2-circle me-1"></i>Proses Pembayaran (F9)
            </button>
            <button type="submit" class="btn btn-outline-danger" form="form-batalkan" data-aksi="batalkan" <?= $keranjang === [] ? 'disabled' : '' ?> title="Batalkan Transaksi (ESC)">
                <i class="bi bi-x-lg me-1"></i>Batalkan Transaksi
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
function aksiTambahItem(int $produkId, float $qty, int $kasirId): void
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
            $baris['subtotal'] = round($produk->getHargaEfektif() * $qtyBaru, 2);
            $sudahAda = true;
            break;
        }
    }
    unset($baris);

    if (!$sudahAda) {
        $keranjang[$kunci] = [
            'produk_id' => $produkId,
            'nama'      => $produk->getNama(),
            'harga'     => $produk->getHargaEfektif(),
            'satuan'    => $produk->getSatuan(),
            'qty'       => $qty,
            'stok'      => $produk->getStok(),
            'subtotal'  => round($produk->getHargaEfektif() * $qty, 2),
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

/** Ubah kuantitas produk di keranjang (misal klik tombol + / -). */
function aksiUbahQty(int $produkId, float $delta, int $kasirId = 0): void
{
    $keranjang = keranjang();
    $kunci = (string) $produkId;

    if (!isset($keranjang[$kunci])) {
        redirectSelf('Item tidak ada di keranjang.', 'warning');
    }

    $produk = Produk::cari($produkId);
    if ($produk === null) {
        redirectSelf('Produk tidak ditemukan.', 'danger');
    }

    $satuan = $keranjang[$kunci]['satuan'] ?? 'pcs';
    $qtyBaru = $keranjang[$kunci]['qty'] + $delta;

    if ($qtyBaru <= 0) {
        unset($keranjang[$kunci]);
        $_SESSION['keranjang'] = $keranjang;
        redirectSelf(sprintf('"%s" dikeluarkan dari keranjang.', $produk->getNama()), 'info');
    }

    if ($produk->getStok() < $qtyBaru) {
        redirectSelf(
            sprintf('Stok "%s" tidak cukup (tersedia: %s).', $produk->getNama(), $produk->getStok()),
            'danger'
        );
    }

    $keranjang[$kunci]['qty']      = $qtyBaru;
    $keranjang[$kunci]['stok']     = $produk->getStok();
    $keranjang[$kunci]['subtotal'] = round($produk->getHargaEfektif() * $qtyBaru, 2);

    $_SESSION['keranjang'] = $keranjang;
    redirectSelf(sprintf('Jumlah "%s" diubah (%s).', $produk->getNama(), $satuan === 'gram' ? $qtyBaru . ' gr' : (int)$qtyBaru . ' pcs'), 'success');
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

    // Idempotency guard: cegah double-submit (2 tap/klik bersamaan).
    // Request kedua yang masuk saat request pertama masih berjalan akan
    // mendapat pesan ramah, bukan error "stok tidak cukup".
    if (!empty($_SESSION['bayar_lock'])) {
        redirectSelf('Transaksi sedang diproses, silakan tunggu.', 'warning');
    }

    $_SESSION['bayar_lock'] = true;

    try {
        $transaksi = new Transaksi(['kasir_id' => $kasirId]);

        // Member transaksi (bila dipasang lewat scan telepon).
        $memberId = (int) ($_SESSION['member_id'] ?? 0);

        if ($memberId > 0) {
            $transaksi->setMemberId($memberId);
        }

        foreach ($keranjang as $item) {
            $produk = Produk::cari($item['produk_id']);

            if ($produk === null) {
                throw new \RuntimeException('Produk tidak ditemukan, keranjang tidak valid.');
            }

            $transaksi->tambahItem($produk, $item['qty']);
        }

        // Diskon hanya dipakai bila masih valid dan kodenya tersimpan.
        $diskonId = $_SESSION['diskon_id'] ?? null;

        if ($diskonId !== null) {
            $diskon = Diskon::cari((int) $diskonId);

            if ($diskon !== null) {
                $transaksi->terapkanDiskon($diskon);
            }
        }

        // Hitung total SEKALI setelah item & diskon diset. hitungTotal() bersifat
        // idempotent (diskon & pajak diterapkan dari state saat ini), jadi
        // memanggilnya di prosesPembayaran() tidak akan mendobel diskon.
        $transaksi->hitungTotal();

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
        // Notifikasi WA ke n8n: observer ini HANYA meng-INSERT baris pending ke
        // notifikasi_queue (di dalam transaction yang sama, atomic). Pengiriman
        // HTTP kejakan dilakukan setelah commit di bawah lewat NotifikasiAntrian.
        $transaksi->attach(new NotifikasiWhatsApp());

        // Jalur sukses: proses pembayaran -> simpan + update stok + struk.
        $kasir = new Kasir(['id' => $kasirId, 'nama' => $namaUser]);
        $kasir->prosesTransaksi($transaksi);
        $selesai = $transaksi->prosesPembayaran();

        if (!$selesai) {
            unset($_SESSION['bayar_lock']);
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
            $_SESSION['member_id'],
            $_SESSION['struk'],
            $_SESSION['bayar_lock']
        );
        $_SESSION['struk'] = $struk;

        // Kirim notifikasi WA ke n8n (best-effort, POST-commit). Transaksi sudah
        // ter-commit & tersimpan; notifikasi gagal tidak boleh mengganggu respons
        // struk ke kasir. Error ditelan & dicatat di kolom `error` outbox saja.
        try {
            NotifikasiAntrian::proses(10, 5, 3000);
        } catch (\Throwable $eWa) {
            // diamkan — penjualan sudah selesai & tercatat.
        }
    } catch (\Throwable $e) {
        // Pastikan lock dilepas supaya kasir tidak terjebak state "sedang diproses".
        unset($_SESSION['bayar_lock']);
        redirectSelf(pesanErrorRamah($e), 'danger');
    }

    // AJAX: kirim struk + fragment keranjang kosong supaya UI langsung
    // menampilkan struk tanpa reload. Non-AJAX: redirect seperti biasa.
    global $adalahAjax, $modeNonAjax;

    if ($modeNonAjax) {
        redirectSelf('Pembayaran berhasil. Struk siap dicetak.', 'success');
    }

    // Jangan bawa nominal pembayaran ke fragment kosong — nanti "Kembalian"
    // tampak sebesar nominal bayar padahal keranjang sudah kosong/total 0.
    unset($_POST['jumlah_dibayar']);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'pesan'    => 'Pembayaran berhasil. Struk siap dicetak.',
        'tipe'     => 'success',
        'struk'    => $struk,
        'fragment' => renderFragmentKeranjang(),
        'produk'   => renderFragmentProduk(),
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
        $_SESSION['diskon_nilai'],
        $_SESSION['member_id']
    );

    redirectSelf('Keranjang dibatalkan.', 'info');
}

/** Tahan (parkir) keranjang saat ini ke memori sesi sementara. */
function aksiTahanKeranjang(int $kasirId, string $catatan = ''): void
{
    $keranjang = keranjang();
    if ($keranjang === []) {
        redirectSelf('Keranjang masih kosong, tidak ada yang bisa ditahan.', 'warning');
    }

    if (!isset($_SESSION['keranjang_tertunda'])) {
        $_SESSION['keranjang_tertunda'] = [];
    }

    $diskonId = $_SESSION['diskon_id'] ?? null;
    $diskon = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
    $ringkasan = hitungRingkasanKeranjang($keranjang, $diskon);

    $holdId = uniqid('hold_', true);
    $_SESSION['keranjang_tertunda'][$holdId] = [
        'id'        => $holdId,
        'waktu'     => date('H:i'),
        'catatan'   => $catatan !== '' ? $catatan : 'Pelanggan #' . (count($_SESSION['keranjang_tertunda']) + 1),
        'keranjang' => $keranjang,
        'diskon_id' => $_SESSION['diskon_id'] ?? null,
        'member_id' => $_SESSION['member_id'] ?? null,
        'total'     => $ringkasan['total'],
    ];

    $_SESSION['keranjang'] = [];
    unset($_SESSION['diskon_id'], $_SESSION['member_id']);

    redirectSelf('Keranjang berhasil diparkir/ditahan (F7). Anda bisa melayani pelanggan berikutnya.', 'info');
}

/** Panggil kembali keranjang yang sebelumnya ditahan. */
function aksiPanggilKeranjang(string $holdId): void
{
    if (empty($_SESSION['keranjang_tertunda'][$holdId])) {
        redirectSelf('Transaksi tertunda tidak ditemukan.', 'danger');
    }

    $held = $_SESSION['keranjang_tertunda'][$holdId];
    unset($_SESSION['keranjang_tertunda'][$holdId]);

    // Jika keranjang saat ini sedang ada isinya, tukar (tahan keranjang saat ini dulu)
    $keranjangSekarang = keranjang();
    if (!empty($keranjangSekarang)) {
        $holdIdSwap = uniqid('hold_', true);
        $_SESSION['keranjang_tertunda'][$holdIdSwap] = [
            'id'        => $holdIdSwap,
            'waktu'     => date('H:i'),
            'catatan'   => 'Pelanggan #' . (count($_SESSION['keranjang_tertunda']) + 1),
            'keranjang' => $keranjangSekarang,
            'diskon_id' => $_SESSION['diskon_id'] ?? null,
            'member_id' => $_SESSION['member_id'] ?? null,
            'total'     => hitungRingkasanKeranjang($keranjangSekarang, null)['total'],
        ];
    }

    $_SESSION['keranjang'] = $held['keranjang'] ?? [];
    if (!empty($held['diskon_id'])) {
        $_SESSION['diskon_id'] = (int) $held['diskon_id'];
    } else {
        unset($_SESSION['diskon_id']);
    }
    if (!empty($held['member_id'])) {
        $_SESSION['member_id'] = (int) $held['member_id'];
    } else {
        unset($_SESSION['member_id']);
    }

    redirectSelf('Keranjang tertunda dipanggil kembali.', 'success');
}

/** Hapus antrean keranjang tertunda. */
function aksiHapusTahanan(string $holdId): void
{
    if (isset($_SESSION['keranjang_tertunda'][$holdId])) {
        unset($_SESSION['keranjang_tertunda'][$holdId]);
    }
    redirectSelf('Antrean keranjang tertunda dihapus.', 'info');
}

/**
 * Void (hapus) satu item dari keranjang. Butuh PIN supervisor/admin
 * (disimpan di pengaturan 'pin_supervisor', default 0000).
 */
function aksiVoidItem(int $produkId, string $pin, int $kasirId, string $namaKasir): void
{
    // Validasi produk_id: 0 berarti hidden input kosong (tidak ada item dipilih)
    if ($produkId <= 0) {
        redirectSelf('Pilih item yang akan di-void terlebih dahulu.', 'warning');
    }

    $pinBenar = \App\Models\Pengaturan::get('pin_supervisor', '0000');

    if (!hash_equals($pinBenar, $pin)) {
        \App\Models\AuditLog::catat('void_gagal', 'item_transaksi', $produkId, ['alasan' => 'PIN salah']);
        redirectSelf('PIN supervisor salah. Item tidak dihapus.', 'danger');
    }

    $keranjang = keranjang();

    if (!isset($keranjang[(string) $produkId])) {
        redirectSelf('Item tidak ada di keranjang. Mungkin sudah dihapus sebelumnya.', 'warning');
    }

    $namaItem = $keranjang[(string) $produkId]['nama'] ?? 'Item';
    unset($keranjang[(string) $produkId]);
    $_SESSION['keranjang'] = $keranjang;

    \App\Models\AuditLog::catat('void_item', 'item_transaksi', $produkId, [
        'produk' => $namaItem,
        'kasir'  => $namaKasir,
    ]);

    redirectSelf('Item "' . $namaItem . '" dihapus (void) oleh supervisor.', 'success');
}

// ---- Routing aksi (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $aksi = $_POST['aksi'] ?? '';

    switch ($aksi) {
        case 'ubah_qty':
            $pId = (int) ($_POST['produk_id'] ?? 0);
            $delta = (int) ($_POST['delta'] ?? 0);
            $setQty = isset($_POST['qty']) && $_POST['qty'] !== '' ? (float) $_POST['qty'] : null;
            aksiUbahQty($pId, $delta, $setQty);
            break;

        case 'tahan_keranjang':
            aksiTahanKeranjang($userId, trim((string) ($_POST['catatan_tahan'] ?? '')));
            break;

        case 'panggil_keranjang':
            aksiPanggilKeranjang((string) ($_POST['hold_id'] ?? ''));
            break;

        case 'hapus_tahanan':
            aksiHapusTahanan((string) ($_POST['hold_id'] ?? ''));
            break;
        case 'logout':
            logoutKaryawan();
            header('Location: login.php');
            exit;

        case 'buka_kas':
            try {
                $modal = (float) ($_POST['modal_awal'] ?? 0);
                $shiftId = ShiftKasir::buka($userId, $modal);
                \App\Models\AuditLog::catat('buka_kas', 'shift_kasir', $shiftId, ['modal_awal' => $modal]);

                // Refresh $shiftAktif global supaya fragment yang dikirim
                // redirectSelf() merender panel kanan (bukan kosong).
                $shiftAktif = ShiftKasir::shiftAktif($userId);

                redirectSelf('Kas dibuka. Selamat bertugas!', 'success');
            } catch (\Throwable $e) {
                redirectSelf(pesanErrorRamah($e), 'danger');
            }
            break;

        case 'tutup_kas':
            try {
                if ($shiftAktif === null) {
                    redirectSelf('Tidak ada shift yang terbuka.', 'danger');
                }

                $kasFisik = (float) ($_POST['kas_fisik'] ?? 0);
                $catatan = trim((string) ($_POST['catatan_shift'] ?? ''));
                $shiftAktif->tutup($kasFisik, $catatan);
                $selisih = (float) $shiftAktif->getSelisih();
                \App\Models\AuditLog::catat('tutup_kas', 'shift_kasir', (int) $shiftAktif->getId(), [
                    'kas_fisik' => $kasFisik,
                    'selisih'   => $selisih,
                ]);
                // Refresh global shiftAktif jadi null supaya fragment AJAX
                // merender panel kanan kosong (kas sudah terkunci), bukan
                // memakai objek shift lama yang statusnya sudah "tutup".
                $shiftAktif = ShiftKasir::shiftAktif($userId);
                redirectSelf('Kas ditutup. Selisih: ' . formatRupiah($selisih), 'success');
            } catch (\Throwable $e) {
                redirectSelf(pesanErrorRamah($e), 'danger');
            }
            break;

        case 'void_item':
            // Void item dari keranjang butuh PIN supervisor/admin.
            aksiVoidItem((int) ($_POST['produk_id'] ?? 0), (string) ($_POST['pin'] ?? ''), $userId, $namaUser);
            break;

        case 'tambah_item':
            aksiTambahItem(
                (int) ($_POST['produk_id'] ?? 0),
                (float) ($_POST['qty'] ?? 1),
                $userId
            );
            break;

        case 'ubah_qty':
            aksiUbahQty(
                (int) ($_POST['produk_id'] ?? 0),
                (float) ($_POST['delta'] ?? 0),
                $userId
            );
            break;

        case 'scan':
            // Scan barcode: cari produk by barcode, tambah 1 pcs langsung.
            $barcode = trim((string) ($_POST['barcode'] ?? ''));

            if ($barcode === '') {
                redirectSelf('Barcode kosong.', 'danger');
            }

            $produk = Produk::cariBerdasarkanBarcode($barcode);

            if ($produk === null) {
                redirectSelf('Produk dengan barcode "' . $barcode . '" tidak ditemukan.', 'danger');
            }

            aksiTambahItem((int) $produk->getId(), 1, $userId);
            break;

        case 'hapus_item':
            aksiHapusItem((int) ($_POST['produk_id'] ?? 0));
            break;

        case 'diskon':
            aksiTerapkanDiskon(trim((string) ($_POST['kode_diskon'] ?? '')));
            break;

        case 'set_member':
            // Set member transaksi by telepon.
            $telepon = trim((string) ($_POST['telepon'] ?? ''));
            $member = Member::cariBerdasarkanTelepon($telepon);

            if ($member === null) {
                redirectSelf('Member dengan nomor "' . $telepon . '" tidak ditemukan.', 'danger');
            }

            $_SESSION['member_id'] = (int) $member->getId();
            redirectSelf('Member "' . $member->getNama() . '" terpasang.', 'success');
            break;

        case 'hapus_member':
            unset($_SESSION['member_id']);
            redirectSelf('Member dilepas dari transaksi.', 'info');
            break;

        case 'bayar':
            // Untuk non-tunai (QRIS/EDC), kasir tidak memasukkan uang tunai —
            // gunakan total sebagai jumlah yang dibayar otomatis.
            $metodeBayar = (string) ($_POST['metode'] ?? 'tunai');
            $jumlahDibayar = (float) ($_POST['jumlah_dibayar'] ?? 0);
            if ($metodeBayar === 'non_tunai' && $jumlahDibayar <= 0) {
                $diskonRoute = null;
                $diskonIdRoute = $_SESSION['diskon_id'] ?? null;
                if ($diskonIdRoute !== null) {
                    $diskonRoute = Diskon::cari((int) $diskonIdRoute);
                }
                $ringkasanBayar = hitungRingkasanKeranjang(keranjang(), $diskonRoute);
                $jumlahDibayar = $ringkasanBayar['total'];
            }
            aksiBayar(
                $metodeBayar,
                $jumlahDibayar,
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
$diskonSemua = Diskon::semua();
$diskonId   = $_SESSION['diskon_id'] ?? null;
$diskon     = $diskonId !== null ? Diskon::cari((int) $diskonId) : null;
$ringkasan  = hitungRingkasanKeranjang($keranjang, $diskon);
$subtotal   = $ringkasan['subtotal'];
$potongan   = $ringkasan['potongan'];
$pajak      = $ringkasan['pajak'];
$total      = $ringkasan['total'];
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
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css?v=<?= @filemtime(__DIR__ . '/assets/theme.css') ?: time() ?>" rel="stylesheet">
    <style>
        .qty-input { max-width: 4.5rem; }
        .summary-total { font-size: 1.75rem; font-weight: 700; }
        .hasil-cari { min-height: 4.5rem; }
        /* Toolbar pencarian kompak: barcode & pencarian nama terpisah. */
        .pos-toolbar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1rem;
        }
        .pos-toolbar-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .pos-toolbar-row + .pos-toolbar-row {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px dashed var(--border);
        }
        .pos-toolbar-label {
            flex: 0 0 auto;
            min-width: 5rem;
            margin: 0;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
        }
        .pos-toolbar-form {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1 1 auto;
            min-width: 0;
        }
        /* Mobile: susun vertikal supaya tidak ada tombol yang terpotong. */
        @media (max-width: 575.98px) {
            .pos-toolbar-row {
                flex-direction: column;
                align-items: stretch;
            }
            .pos-toolbar-label {
                min-width: 0;
            }
            .pos-toolbar-form {
                flex-direction: column;
                align-items: stretch;
                width: 100%;
            }
            .pos-toolbar-form input {
                width: 100%;
                min-width: 0;
            }
            .pos-toolbar-form button {
                width: 100%;
            }
        }
        @media (max-width: 991.98px) {
            .ringkasan { margin-top: 1.5rem; }
        }
        /* Overlay wajib buka kas: menutupi seluruh layar dengan blur,
           tidak ada tombol tutup — hanya bisa ditutup setelah kas dibuka. */
        .buka-kas-overlay {
            position: fixed;
            inset: 0;
            z-index: 1080;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .buka-kas-overlay.d-none { display: none !important; }
        .buka-kas-card {
            width: 100%;
            max-width: 480px;
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 1.5rem 3rem rgba(0, 0, 0, 0.35);
            overflow: hidden;
        }
        /* Print: hanya tampilkan struk terakhir */
        @media print {
            body { background: #fff; color: #000; }
            .kiosk-wrapper, .kiosk-kiri, .kiosk-kanan, #flash-pesan, .buka-kas-overlay { display: none !important; }
            #area-struk { display: block !important; border: 0; box-shadow: none; }
            #area-struk .card-header { display: none; }
            #area-struk pre {
                font-family: 'Courier New', monospace;
                font-size: 12px;
                white-space: pre;
            }
        }
    </style>
</head>
<body class="kiosk-body">
<div class="kiosk-wrapper">
    <!-- KOLOM KIRI (70%): pencarian + keranjang + produk -->
    <div class="kiosk-kiri">

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div class="d-flex align-items-center gap-3">
            <div>
                <h1 class="h3 mb-0">Transaksi Penjualan</h1>
                <span class="text-muted small">Kasir: <?= htmlspecialchars($namaUser) ?></span>
            </div>
            <span id="pos-network-status" class="badge text-bg-success-subtle text-success border border-success-subtle d-inline-flex align-items-center gap-1" title="Koneksi ke server aktif">
                <span class="network-pulse"></span>Online
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="kasir/display.php" target="_blank" class="btn btn-sm btn-outline-teal" id="btn-open-cfd" title="Buka Layar Pelanggan / Customer Display di Monitor Kedua">
                <i class="bi bi-display me-1"></i>Layar Pelanggan
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-pos-settings" title="Pengaturan Suara & Layar POS">
                <i class="bi bi-sliders"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-shortcuts" title="Panduan Tombol Pintas Keyboard (?)">
                <i class="bi bi-keyboard me-1"></i>Shortcuts <kbd class="ms-1" style="font-size:0.7rem">?</kbd>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-header-fullscreen" title="Layar Penuh (F11)">
                <i class="bi bi-arrows-fullscreen"></i>
            </button>
        </div>
    </div>

    <div id="flash-pesan" class="alert alert-<?= htmlspecialchars($pesanTipe) ?> alert-dismissible fade show <?= $pesan === '' ? 'd-none' : '' ?>" role="alert">
        <?= htmlspecialchars($pesan) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>

    <div class="pos-toolbar mb-4">
        <div class="pos-toolbar-row">
            <span class="pos-toolbar-label"><i class="bi bi-upc-scan me-1"></i>Barcode</span>
            <form method="post" data-aksi="scan" class="pos-toolbar-form">
                <input type="hidden" name="aksi" value="scan">
                <input
                    type="text"
                    id="input-barcode"
                    name="barcode"
                    class="form-control font-num"
                    placeholder="Scan barcode... (Enter untuk tambah)"
                    autocomplete="off"
                    aria-label="Scan barcode"
                >
                <button type="submit" class="btn btn-outline-primary text-nowrap" title="Tambah produk dari barcode">
                    <i class="bi bi-upc-scan me-1"></i>Scan
                </button>
                <button
                    type="button"
                    id="btn-kamera-barcode"
                    class="btn btn-success text-nowrap"
                    title="Scan barcode pakai kamera"
                >
                    <i class="bi bi-camera me-1"></i>Kamera
                </button>
            </form>
        </div>
        <div id="kamera-status" class="small text-muted mb-0 d-none"></div>

        <div class="pos-toolbar-row position-relative">
            <span class="pos-toolbar-label"><i class="bi bi-search me-1"></i>Cari</span>
            <form method="get" class="pos-toolbar-form position-relative flex-grow-1" id="form-cari-produk">
                <input
                    type="search"
                    id="input-cari"
                    name="cari"
                    class="form-control"
                    placeholder="Ketik nama produk / barcode... (Pencarian instan otomatis)"
                    value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>"
                    aria-label="Cari produk"
                    autocomplete="off"
                >
                <button type="submit" class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i>Cari</button>

                <!-- Floating Live Search Dropdown -->
                <div id="live-search-dropdown" class="live-search-dropdown d-none">
                    <div class="live-search-header d-flex justify-content-between align-items-center px-3 py-2 border-bottom text-muted small">
                        <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Hasil Pencarian Cepat</span>
                        <span style="font-size: 0.75rem;">Gunakan <kbd class="px-1">↑</kbd> <kbd class="px-1">↓</kbd> lalu <kbd class="px-1">Enter</kbd></span>
                    </div>
                    <div id="live-search-items" class="live-search-list"></div>
                </div>
            </form>
        </div>
    </div>

    <div class="hasil-cari mb-4">
        <?php if (isset($_GET['cari']) && trim($_GET['cari']) !== ''): ?>
            <?php if ($produkDitemukan === null): ?>
                <div class="text-danger">Produk tidak ditemukan.</div>
            <?php else: ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border rounded p-2">
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($produkDitemukan->getGambar() !== ''): ?>
                            <img
                                src="uploads/<?= htmlspecialchars($produkDitemukan->getGambar()) ?>"
                                alt="<?= htmlspecialchars($produkDitemukan->getNama()) ?>"
                                class="rounded border"
                                style="width: 48px; height: 48px; object-fit: contain;"
                                onerror="this.style.display='none';"
                            >
                        <?php endif; ?>
                        <div>
                            <strong><?= htmlspecialchars($produkDitemukan->getNama()) ?></strong>
                            <span class="text-muted ms-2">
                                <?= $produkDitemukan->getSatuan() === 'gram'
                                    ? formatRupiah($produkDitemukan->getHargaPerGram()) . '/gr'
                                    : formatRupiah($produkDitemukan->getHarga()) ?>
                            </span>
                            <span class="badge text-bg-<?= $produkDitemukan->getStok() > 0 ? 'success' : 'danger' ?> ms-2">
                                stok <?= $produkDitemukan->getStok() ?> <?= $produkDitemukan->getSatuan() === 'gram' ? 'gr' : '' ?>
                            </span>
                        </div>
                    </div>
                    <form method="post" class="d-flex gap-2" data-aksi="tambah_item">
                        <input type="hidden" name="aksi" value="tambah_item">
                        <input type="hidden" name="produk_id" value="<?= $produkDitemukan->getId() ?>">
                        <?php if ($produkDitemukan->getSatuan() === 'gram'): ?>
                            <input
                                type="number"
                                name="qty"
                                class="form-control qty-input"
                                value="100"
                                min="0.001"
                                step="0.001"
                                max="<?= max(1, $produkDitemukan->getStok()) ?>"
                                placeholder="Berat (gr)"
                                required
                                data-produk-gram="<?= $produkDitemukan->getId() ?>"
                            >
                        <?php else: ?>
                            <input
                                type="number"
                                name="qty"
                                class="form-control qty-input"
                                value="1"
                                min="1"
                                max="<?= max(1, $produkDitemukan->getStok()) ?>"
                                required
                            >
                        <?php endif; ?>
                        <button
                            type="submit"
                            class="btn btn-success text-nowrap"
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

    <!-- Overlay wajib buka kas: selalu ada di DOM dan disembunyikan via d-none
         bila shift sudah terbuka. Dengan demikian pos.js dapat menampilkannya
         kembali setelah tutup kas (aksi AJAX) tanpa reload penuh — sehingga
         card "Buka Kas" tidak lagi tak muncul / tidak muncul berulang kali
         ketika kasir keluar (logout) sebelum menutup shift. -->
    <div class="buka-kas-overlay<?= $wajibBukaKas ? '' : ' d-none' ?>" id="card-buka-kas" role="dialog" aria-modal="true" aria-labelledby="buka-kas-judul">
        <div class="card pos-card buka-kas-card">
            <div class="card-header bg-warning-subtle text-warning-emphasis">
                <i class="bi bi-cash-stack me-1"></i><span id="buka-kas-judul">Buka Kas</span>
            </div>
            <div class="card-body p-4">
                <p class="mb-3">Anda harus <strong>buka kas</strong> dulu sebelum mulai transaksi. Isi modal awal (uang di laci) untuk memulai shift.</p>
                <form method="post" data-aksi="buka_kas">
                    <input type="hidden" name="aksi" value="buka_kas">
                    <div class="mb-3">
                        <label for="modal-awal" class="form-label d-flex justify-content-between">
                            <span>Modal awal (Rp)</span>
                            <span class="text-muted small">Pilih cepat:</span>
                        </label>
                        <div class="quick-float-grid mb-2">
                            <button type="button" class="btn-float-chip" data-float="50000">Rp 50.000</button>
                            <button type="button" class="btn-float-chip" data-float="100000">Rp 100.000</button>
                            <button type="button" class="btn-float-chip" data-float="200000">Rp 200.000</button>
                            <button type="button" class="btn-float-chip" data-float="500000">Rp 500.000</button>
                        </div>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="modal-awal"
                            name="modal_awal"
                            class="form-control form-control-lg font-num text-end fw-bold"
                            placeholder="cth: 100000"
                            required
                            <?= $wajibBukaKas ? 'autofocus' : '' ?>
                        >
                    </div>
                    <button type="submit" class="btn btn-success btn-lg w-100">
                        <i class="bi bi-cash-coin me-1"></i>Buka Kas & Mulai Transaksi
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($struk !== ''): ?>
        <div class="card pos-card mb-4" id="area-struk">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span>Struk terakhir</span>
                <span class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-light" id="cetak-struk" title="Cetak struk">
                        <i class="bi bi-printer me-1"></i>Cetak
                    </button>
                    <button type="button" class="btn btn-sm btn-light" id="transaksi-baru" title="Transaksi baru">
                        <i class="bi bi-play me-1"></i>Baru
                    </button>
                    <button type="button" class="btn-close btn-close-white" id="tutup-struk" aria-label="Tutup"></button>
                </span>
            </div>
            <div class="card-body">
                <pre class="mb-0"><?= htmlspecialchars($struk) ?></pre>
            </div>
        </div>
    <?php else: ?>
        <div class="card pos-card mb-4 d-none" id="area-struk">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span>Struk terakhir</span>
                <span class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-light" id="cetak-struk" title="Cetak struk">
                        <i class="bi bi-printer me-1"></i>Cetak
                    </button>
                    <button type="button" class="btn btn-sm btn-light" id="transaksi-baru" title="Transaksi baru">
                        <i class="bi bi-play me-1"></i>Baru
                    </button>
                    <button type="button" class="btn-close btn-close-white" id="tutup-struk" aria-label="Tutup"></button>
                </span>
            </div>
            <div class="card-body">
                <pre class="mb-0"></pre>
            </div>
        </div>
    <?php endif; ?>

    <div id="fragmen-keranjang-kiri"><?= renderFragmentKeranjangKiri() ?></div>

    <?= renderFragmentProduk() ?>

    </div><!-- /.kiosk-kiri -->

    <!-- KOLOM KANAN (30%): userbar + ringkasan + numpad -->
    <div class="kiosk-kanan <?= $wajibBukaKas ? 'd-none' : '' ?>">
        <div id="fragmen-keranjang-kanan"><?= renderFragmentKananKiosk() ?></div>
    </div><!-- /.kiosk-kanan -->
</div><!-- /.kiosk-wrapper -->

<!-- Sidebar kiosk (offcanvas): semua aksi kasir → rapi & modern -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="sidebarKasir" aria-labelledby="sidebarKasirLabel">
    <div class="offcanvas-header flex-column align-items-stretch pb-3">
        <div class="d-flex justify-content-between align-items-center w-100">
            <h5 class="offcanvas-title d-flex align-items-center gap-2 fw-bold text-white fs-6 mb-0" id="sidebarKasirLabel">
                <i class="bi bi-shield-lock-fill text-warning"></i> Menu Kasir POS
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup menu"></button>
        </div>

        <!-- Card Profile User -->
        <div class="sidebar-user-card">
            <div class="sidebar-avatar">
                <?= strtoupper(substr($namaUser, 0, 1)) ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name" title="<?= htmlspecialchars($namaUser) ?>">
                    <?= htmlspecialchars($namaUser) ?>
                </div>
                <div class="sidebar-user-role">
                    <span class="status-dot-pulse"></span>
                    <?= ucfirst((string)($_SESSION['role'] ?? 'Kasir')) ?> Online
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas-body d-flex flex-column px-0 py-2">
        <?php if ($shiftAktif !== null): ?>
            <!-- Shift Info Active Card -->
            <div class="sidebar-shift-card">
                <div class="shift-title">
                    <i class="bi bi-cash-stack"></i> Shift Kasir Aktif
                </div>
                <div class="shift-detail d-flex justify-content-between align-items-center mt-1">
                    <span><i class="bi bi-clock me-1 text-muted"></i>Buka <?= date('H:i', strtotime($shiftAktif->getDibukaPada())) ?> WIB</span>
                    <span class="badge text-bg-success font-num"><?= formatRupiah($shiftAktif->getModalAwal()) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section: Navigasi Utama -->
        <div class="sidebar-section-title">Navigasi Utama</div>
        <ul class="sidebar-menu-list">
            <li>
                <a class="sidebar-menu-item-link" href="profile.php">
                    <div class="sidebar-icon-bubble icon-teal">
                        <i class="bi bi-person-gear"></i>
                    </div>
                    <div class="sidebar-link-content">
                        <span class="sidebar-link-title">Profil Saya</span>
                        <span class="sidebar-link-sub">Pengaturan akun & kata sandi</span>
                    </div>
                    <i class="bi bi-chevron-right sidebar-chevron"></i>
                </a>
            </li>
            <li>
                <a class="sidebar-menu-item-link" href="#"
                   data-bs-toggle="modal" data-bs-target="#modalPerangkat"
                   data-bs-dismiss="offcanvas">
                    <div class="sidebar-icon-bubble icon-indigo">
                        <i class="bi bi-printer-fill"></i>
                    </div>
                    <div class="sidebar-link-content">
                        <span class="sidebar-link-title">Hubungkan Perangkat</span>
                        <span class="sidebar-link-sub">Printer thermal & scanner POS</span>
                    </div>
                    <i class="bi bi-chevron-right sidebar-chevron"></i>
                </a>
            </li>
            <li>
                <a class="sidebar-menu-item-link" href="laporan.php">
                    <div class="sidebar-icon-bubble icon-sky">
                        <i class="bi bi-receipt-cutoff"></i>
                    </div>
                    <div class="sidebar-link-content">
                        <span class="sidebar-link-title">Laporan Penjualan</span>
                        <span class="sidebar-link-sub">Riwayat & rekap transaksi</span>
                    </div>
                    <i class="bi bi-chevron-right sidebar-chevron"></i>
                </a>
            </li>
            <li>
                <a class="sidebar-menu-item-link" href="#" id="btn-sidebar-fullscreen">
                    <div class="sidebar-icon-bubble icon-purple">
                        <i class="bi bi-arrows-fullscreen"></i>
                    </div>
                    <div class="sidebar-link-content">
                        <span class="sidebar-link-title">Mode Layar Penuh</span>
                        <span class="sidebar-link-sub">Fokus transaksi minimarket</span>
                    </div>
                    <span class="badge text-bg-light border small text-muted">F11</span>
                </a>
            </li>
        </ul>

        <?php if ($shiftAktif !== null): ?>
            <!-- Section: Shift & Kas -->
            <div class="sidebar-section-title">Manajemen Shift</div>
            <ul class="sidebar-menu-list">
                <li>
                    <a class="sidebar-menu-item-link warning-link" href="#"
                       data-bs-toggle="modal" data-bs-target="#modal-tutup-kas"
                       data-bs-dismiss="offcanvas">
                        <div class="sidebar-icon-bubble icon-amber">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                        <div class="sidebar-link-content">
                            <span class="sidebar-link-title text-warning-emphasis">Tutup Kas Shift</span>
                            <span class="sidebar-link-sub">Rekonsiliasi kas fisik & sistem</span>
                        </div>
                        <i class="bi bi-chevron-right sidebar-chevron"></i>
                    </a>
                </li>
            </ul>
        <?php endif; ?>

        <!-- Section: Sesi & Keamanan -->
        <div class="sidebar-section-title">Sesi & Keamanan</div>
        <ul class="sidebar-menu-list">
            <li>
                <form method="post" action="logout.php" class="w-100">
                    <button type="submit" class="sidebar-menu-item-link danger-link border-0 text-start">
                        <div class="sidebar-icon-bubble icon-rose">
                            <i class="bi bi-box-arrow-right"></i>
                        </div>
                        <div class="sidebar-link-content">
                            <span class="sidebar-link-title text-danger">Keluar (Logout)</span>
                            <span class="sidebar-link-sub">Selesaikan sesi petugas kasir</span>
                        </div>
                        <i class="bi bi-chevron-right sidebar-chevron"></i>
                    </button>
                </form>
            </li>
        </ul>

        <!-- Sidebar Footer Metadata -->
        <div class="sidebar-footer">
            <div class="sidebar-footer-info">
                <span>Kasir Minimarket v2.0</span>
                <div class="sidebar-footer-badges">
                    <span class="kbd-shortcut" title="Tombol Cari">F2</span>
                    <span class="kbd-shortcut" title="Tombol Bayar">F9</span>
                    <span class="kbd-shortcut" title="Tutup Menu">ESC</span>
                </div>
            </div>
        </div>
    </div>
</div>



<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/pos.js?v=<?= @filemtime(__DIR__ . '/assets/pos.js') ?: time() ?>"></script>
<script src="assets/hardware.js?v=<?= @filemtime(__DIR__ . '/assets/hardware.js') ?: time() ?>"></script>
<script src="assets/hardware-pos.js?v=<?= @filemtime(__DIR__ . '/assets/hardware-pos.js') ?: time() ?>"></script>

<!-- Modal void item (butuh PIN supervisor) -->
<div class="modal fade" id="modal-void" tabindex="-1" aria-labelledby="modal-void-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" data-aksi="void_item">
                <input type="hidden" name="aksi" value="void_item">
                <input type="hidden" name="produk_id" id="void-produk-id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-void-label"><i class="bi bi-x-circle me-1"></i>Void Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <p id="void-produk-nama" class="mb-3"></p>
                    <p class="small text-muted">Void (hapus item dari keranjang) butuh otorisasi supervisor. Masukkan PIN supervisor:</p>
                    <input
                        type="password"
                        name="pin"
                        id="void-pin"
                        class="form-control form-control-lg font-num text-center"
                        placeholder="PIN supervisor"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="off"
                        required
                    >
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Void Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal tutup kas (rekonsiliasi) -->
<div class="modal fade" id="modal-tutup-kas" tabindex="-1" aria-labelledby="modal-tutup-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" data-aksi="tutup_kas">
                <input type="hidden" name="aksi" value="tutup_kas">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tutup-label"><i class="bi bi-cash-coin me-2 text-warning"></i>Tutup Kas Shift & Rekonsiliasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="small text-muted">Kas dibuka sejak</div>
                        <div class="fw-semibold" id="tutup-kas-dibuka">-</div>
                    </div>

                    <!-- Ringkasan & riwayat transaksi shift (diisi dinamis
                         via api.php?aksi=shift.ringkasan saat modal dibuka) -->
                    <div id="tutup-kas-ringkasan"></div>

                    <!-- Kalkulator Pecahan Lembaran Fisik (Interactive Denomination Calculator) -->
                    <div class="card bg-light border mb-3">
                        <div class="card-header bg-transparent py-2 d-flex justify-content-between align-items-center" role="button" data-bs-toggle="collapse" data-bs-target="#collapsePecahan">
                            <span class="small fw-bold"><i class="bi bi-calculator me-1 text-teal"></i>Kalkulator Pecahan Uang Fisik (Lembaran & Koin)</span>
                            <i class="bi bi-chevron-down small text-muted"></i>
                        </div>
                        <div class="collapse show" id="collapsePecahan">
                            <div class="card-body p-2">
                                <table class="denomination-table">
                                    <thead>
                                        <tr>
                                            <th>Pecahan</th>
                                            <th class="text-center" style="width: 140px;">Jumlah</th>
                                            <th class="text-end" style="width: 160px;">Subtotal (Rp)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="denom-tbody">
                                        <tr data-val="100000"><td>Rp 100.000</td><td class="text-center"><input type="number" min="0" class="form-control form-control-sm denom-input mx-auto" value="0"></td><td class="denom-subtotal">Rp 0</td></tr>
                                        <tr data-val="50000"><td>Rp 50.000</td><td class="text-center"><input type="number" min="0" class="form-control form-control-sm denom-input mx-auto" value="0"></td><td class="denom-subtotal">Rp 0</td></tr>
                                        <tr data-val="20000"><td>Rp 20.000</td><td class="text-center"><input type="number" min="0" class="form-control form-control-sm denom-input mx-auto" value="0"></td><td class="denom-subtotal">Rp 0</td></tr>
                                        <tr data-val="10000"><td>Rp 10.000</td><td class="text-center"><input type="number" min="0" class="form-control form-control-sm denom-input mx-auto" value="0"></td><td class="denom-subtotal">Rp 0</td></tr>
                                        <tr data-val="5000"><td>Rp 5.000</td><td class="text-center"><input type="number" min="0" class="form-control form-control-sm denom-input mx-auto" value="0"></td><td class="denom-subtotal">Rp 0</td></tr>
                                        <tr data-val="2000"><td>Rp 2.000</td><td class="text-center"><input type="number" min="0" class="form-control form-control-sm denom-input mx-auto" value="0"></td><td class="denom-subtotal">Rp 0</td></tr>
                                        <tr data-val="1000"><td>Rp 1.000</td><td class="text-center"><input type="number" min="0" class="form-control form-control-sm denom-input mx-auto" value="0"></td><td class="denom-subtotal">Rp 0</td></tr>
                                        <tr data-val="1"><td>Uang Logam / Koin</td><td class="text-center"><input type="number" min="0" step="100" class="form-control form-control-sm denom-input mx-auto" placeholder="Rp" value="0" style="width:100px;"></td><td class="denom-subtotal">Rp 0</td></tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-bold border-top">
                                            <td colspan="2" class="pt-2">Total Terhitung Fisik</td>
                                            <td class="text-end pt-2 font-num text-teal" id="denom-grand-total">Rp 0</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="kas-fisik" class="form-label fw-bold">Uang di laci (kas fisik) — Rp</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="kas-fisik"
                            name="kas_fisik"
                            class="form-control form-control-lg font-num fw-bold text-end"
                            placeholder="0"
                            required
                        >
                        <div class="form-text" id="tutup-kas-hint">Total penjualan shift otomatis dihitung untuk rekonsiliasi.</div>
                    </div>

                    <!-- Live Difference Badge (Selisih) -->
                    <div id="diff-status-box" class="diff-status-box is-pas d-none">
                        <span id="diff-status-text">Selisih Kas: Pas</span>
                        <span class="font-num fw-bold" id="diff-status-val">Rp 0</span>
                    </div>

                    <div class="mb-2 mt-3">
                        <label for="catatan-shift" class="form-label">Catatan (opsional)</label>
                        <input
                            type="text"
                            id="catatan-shift"
                            name="catatan_shift"
                            class="form-control"
                            placeholder="cth: shift pagi, kas pas"
                        >
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning" id="btn-tutup-kas"><i class="bi bi-cash-coin me-1"></i>Tutup Kas & Rekonsiliasi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Panduan Keyboard Shortcuts -->
<div class="modal fade" id="modal-shortcuts" tabindex="-1" aria-labelledby="modalShortcutsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalShortcutsLabel"><i class="bi bi-keyboard me-2 text-teal"></i>Panduan Tombol Pintas POS (Keyboard Shortcuts)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-3">Gunakan tombol pintas di keyboard untuk mempercepat transaksi kasir tanpa perlu memegang mouse.</p>
                <div class="shortcut-guide-grid">
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">F1</span>
                        <div>
                            <div class="fw-bold small">Cari Produk</div>
                            <div class="text-muted small" style="font-size:0.75rem">Fokus ke kolom pencarian nama produk</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">F2</span>
                        <div>
                            <div class="fw-bold small">Scan Barcode</div>
                            <div class="text-muted small" style="font-size:0.75rem">Fokus ke kolom scan barcode scanner</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">F4</span>
                        <div>
                            <div class="fw-bold small">Scan Member</div>
                            <div class="text-muted small" style="font-size:0.75rem">Fokus ke kolom nomor telepon member</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">F7</span>
                        <div>
                            <div class="fw-bold small">Tahan Keranjang</div>
                            <div class="text-muted small" style="font-size:0.75rem">Parkir keranjang untuk melayani antrean lain</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">F8</span>
                        <div>
                            <div class="fw-bold small">Uang Pas</div>
                            <div class="text-muted small" style="font-size:0.75rem">Isi jumlah bayar sama dengan total belanja</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">F9</span>
                        <div>
                            <div class="fw-bold small">Proses Bayar</div>
                            <div class="text-muted small" style="font-size:0.75rem">Selesaikan transaksi penjualan</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">F11</span>
                        <div>
                            <div class="fw-bold small">Layar Penuh</div>
                            <div class="text-muted small" style="font-size:0.75rem">Mode layar penuh kasir (Fullscreen POS)</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">ESC</span>
                        <div>
                            <div class="fw-bold small">Tutup / Batal</div>
                            <div class="text-muted small" style="font-size:0.75rem">Tutup struk terakhir atau batal modal</div>
                        </div>
                    </div>
                    <div class="shortcut-guide-card">
                        <span class="shortcut-guide-key">?</span>
                        <div>
                            <div class="fw-bold small">Bantuan Shortcuts</div>
                            <div class="text-muted small" style="font-size:0.75rem">Buka jendela panduan ini kapan saja</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="bi bi-check-lg me-1"></i>Mengerti</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal kamera scan barcode -->
<div class="modal fade" id="modal-kamera" tabindex="-1" aria-labelledby="modal-kamera-label" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-kamera-label"><i class="bi bi-camera me-1"></i>Scan Barcode Kamera</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup" id="tutup-kamera"></button>
            </div>
            <div class="modal-body">
                <div class="position-relative bg-black rounded overflow-hidden" style="min-height: 260px;">
                    <video id="video-kamera" class="w-100" style="max-height: 60vh;" autoplay playsinline muted></video>
                    <div id="kamera-overlay" class="position-absolute top-50 start-50 translate-middle text-center text-white px-3" style="background: rgba(0,0,0,.55); border-radius: .5rem;">
                        <div><i class="bi bi-upc-scan fs-3"></i></div>
                        <div class="small mt-1">Arahkan kamera ke barcode produk</div>
                    </div>
                </div>
                <div id="kamera-error" class="alert alert-danger py-2 mt-3 d-none" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal perangkat (printer & timbangan) -->
<div class="modal fade" id="modalPerangkat" tabindex="-1" aria-labelledby="modalPerangkatLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="form-device">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPerangkatLabel"><i class="bi bi-usb-plug me-1"></i>Perangkat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Hubungkan printer &amp; timbangan. Label disimpan per-akun kasir & otomatis
                        tersedia tiap login (Chrome/Edge desktop, localhost).
                    </p>

                    <!-- Printer -->
                    <div class="border rounded p-2 mb-2">
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span><i class="bi bi-printer me-1"></i>Printer</span>
                            <span class="badge text-bg-secondary ms-auto" id="status-printer">Belum</span>
                        </div>
                        <input type="text" id="label-printer" class="form-control form-control-sm mb-1"
                               list="list-printer" placeholder="Pilih / ketik label printer">
                        <datalist id="list-printer">
                            <option>Printer Kasir</option>
                            <option>Printer Dapur</option>
                            <option>Printer Nota</option>
                        </datalist>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-printer">
                                <i class="bi bi-plug me-1"></i>Hubungkan
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btn-lepas-printer">
                                <i class="bi bi-x-lg"></i> Lepas
                            </button>
                        </div>
                    </div>

                    <!-- Timbangan -->
                    <div class="border rounded p-2">
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span><i class="bi bi-bullseye me-1"></i>Timbangan</span>
                            <span class="badge text-bg-secondary ms-auto" id="status-timbangan">Belum</span>
                        </div>
                        <input type="text" id="label-timbangan" class="form-control form-control-sm mb-1"
                               list="list-timbangan" placeholder="Pilih / ketik label timbangan">
                        <datalist id="list-timbangan">
                            <option>Timbangan Kasir</option>
                            <option>Timbangan Dapur</option>
                        </datalist>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-timbangan">
                                <i class="bi bi-plug me-1"></i>Hubungkan
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btn-lepas-timbangan">
                                <i class="bi bi-x-lg"></i> Lepas
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bottom Keyboard Shortcuts Bar -->
<div class="kiosk-shortcuts-bar d-none d-md-flex">
    <div class="kiosk-shortcuts-list">
        <span class="shortcut-chip"><kbd>F1</kbd> Cari Produk</span>
        <span class="shortcut-chip"><kbd>F2</kbd> Scan Barcode</span>
        <span class="shortcut-chip"><kbd>F4</kbd> Member</span>
        <span class="shortcut-chip"><kbd>F8</kbd> Uang Pas</span>
        <span class="shortcut-chip"><kbd>F9</kbd> Proses Bayar</span>
        <span class="shortcut-chip"><kbd>ESC</kbd> Batal / Reset</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>POS Fast Mode Active</span>
    </div>
<!-- Modal QRIS Dynamic Payment Display -->
<div class="modal fade" id="modal-qris" tabindex="-1" aria-labelledby="modalQrisLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalQrisLabel"><i class="bi bi-qr-code-scan me-2"></i>Pembayaran QRIS Nasional</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="qris-badge-header mb-2">
                    <span class="badge text-bg-danger fw-bold px-3 py-1">QRIS</span>
                    <span class="text-muted small ms-2">Standar Pembayaran Nasional</span>
                </div>
                <h4 class="fw-bold font-num text-teal mb-3" id="qris-modal-total"><?= formatRupiah($total) ?></h4>
                <div class="qris-box-wrapper mx-auto p-3 border rounded shadow-sm bg-white mb-3" style="max-width: 260px;">
                    <div id="qris-qrcode" class="d-flex justify-content-center align-items-center">
                        <img id="qris-img" src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= urlencode('00020101021126580014ID.LINKAJA.WWW01189360091100212345675204541153033605802ID5916MINIMARKET PLAZA6007JAKARTA6304ABCD') ?>" alt="QR Code QRIS" class="img-fluid rounded" style="width: 220px; height: 220px;">
                    </div>
                </div>
                <div class="small text-muted mb-2">
                    <i class="bi bi-phone me-1"></i>Arahkan kamera aplikasi (BCA, GoPay, OVO, ShopeePay, Dana, dll.) ke kode QR di atas.
                </div>
                <div class="alert alert-light border py-1 px-2 small mb-0 d-inline-block text-muted">
                    <i class="bi bi-shield-check text-success me-1"></i>Verifikasi Otomatis Terhubung
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-success" id="btn-qris-sudah-bayar" data-bs-dismiss="modal">
                    <i class="bi bi-check2-circle me-1"></i>Sudah Dibayar (Proses Struk)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Pengaturan POS & Suara -->
<div class="modal fade" id="modal-pos-settings" tabindex="-1" aria-labelledby="modalPosSettingsLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPosSettingsLabel"><i class="bi bi-sliders me-2 text-teal"></i>Pengaturan Kasir & Profil Suara</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <label class="form-label fw-bold small"><i class="bi bi-volume-up me-1 text-teal"></i>Tema Bunyi Kasir (Audio Sound FX)</label>
                    <div class="d-flex flex-column gap-2" id="sound-theme-options">
                        <label class="form-check sound-theme-chip p-2 border rounded d-flex align-items-center justify-content-between mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <input class="form-check-input ms-1" type="radio" name="sound_theme" value="classic" checked>
                                <div>
                                    <div class="fw-semibold small">Minimarket Classic</div>
                                    <div class="text-muted small" style="font-size:0.75rem">Beep barcode scanner retail</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 btn-test-sound" data-sound="classic">Test</button>
                        </label>
                        <label class="form-check sound-theme-chip p-2 border rounded d-flex align-items-center justify-content-between mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <input class="form-check-input ms-1" type="radio" name="sound_theme" value="modern">
                                <div>
                                    <div class="fw-semibold small">Modern Soft Chime</div>
                                    <div class="text-muted small" style="font-size:0.75rem">Nada halus & elegan</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 btn-test-sound" data-sound="modern">Test</button>
                        </label>
                        <label class="form-check sound-theme-chip p-2 border rounded d-flex align-items-center justify-content-between mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <input class="form-check-input ms-1" type="radio" name="sound_theme" value="arcade">
                                <div>
                                    <div class="fw-semibold small">Arcade Fun</div>
                                    <div class="text-muted small" style="font-size:0.75rem">Nada retro ceria 8-bit</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 btn-test-sound" data-sound="arcade">Test</button>
                        </label>
                        <label class="form-check sound-theme-chip p-2 border rounded d-flex align-items-center justify-content-between mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <input class="form-check-input ms-1" type="radio" name="sound_theme" value="mute">
                                <div>
                                    <div class="fw-semibold small">Mute (Hening)</div>
                                    <div class="text-muted small" style="font-size:0.75rem">Tanpa bunyi audio feedback</div>
                                </div>
                            </div>
                            <span class="badge text-bg-light border small">Muted</span>
                        </label>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-bold small"><i class="bi bi-display me-1 text-teal"></i>Layar Pelanggan (Dual Screen)</label>
                    <p class="text-muted small mb-2">Buka tampilan pelanggan di monitor kedua untuk menampilkan keranjang belanja langsung ke pembeli.</p>
                    <a href="kasir/display.php" target="_blank" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Buka Layar Pelanggan (Tab Baru)
                    </a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="bi bi-check-lg me-1"></i>Simpan Preferensi</button>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/@zxing/library@0.21.3/umd/index.min.js"></script>
<script src="assets/scanner-pos.js?v=<?= @filemtime(__DIR__ . '/assets/scanner-pos.js') ?: time() ?>"></script>
<script src="assets/theme.js?v=<?= @filemtime(__DIR__ . '/assets/theme.css') ?: time() ?>"></script>
</body>
</html>
