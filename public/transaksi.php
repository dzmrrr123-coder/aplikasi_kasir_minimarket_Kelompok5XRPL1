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

    ob_start();
    ?>
    <div id="fragmen-produk">
    <div class="card pos-card">
        <div class="card-header bg-white">Produk Lain</div>
        <div class="card-body">
            <?php if ($produkSemua === []): ?>
                <div class="text-muted small">Belum ada produk tersimpan.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($produkSemua as $p): ?>
                        <div class="col-sm-6 col-md-4 col-xl-3">
                            <div class="kiosk-produk d-flex flex-column gap-1 h-100">
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
                                <div class="small <?= $p->getStok() > 0 ? 'text-success' : 'text-danger' ?>">
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
                                            >
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary flex-shrink-0"
                                                data-timbang="<?= $p->getId() ?>"
                                                title="Ambil berat dari timbangan"
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
                                            >
                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success flex-shrink-0"
                                                <?= $p->getStok() < 1 ? 'disabled' : '' ?>
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
                                    <td class="text-center"><?= ($item['satuan'] ?? 'pcs') === 'gram' ? rtrim(rtrim(number_format($item['qty'], 3, ',', '.'), '0'), ',') . ' gr' : (int) $item['qty'] ?></td>
                                    <td class="text-end"><?= formatRupiah($item['harga']) ?></td>
                                    <td class="text-end"><?= formatRupiah($item['subtotal']) ?></td>
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
                                            <i class="bi bi-x-circle me-1"></i>Void
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
    ?>
    <!-- Userbar: nama kasir + logout (wajib ada di panel kanan) -->
    <div class="kiosk-userbar">
        <div class="kiosk-user">
            <i class="bi bi-person-circle"></i>
            <span><?= htmlspecialchars($namaUser) ?></span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <?php if ($shiftAktif !== null): ?>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modal-tutup-kas" title="Tutup kas & rekonsiliasi">
                    <i class="bi bi-cash-coin me-1"></i>Tutup Kas
                </button>
            <?php endif; ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="aksi" value="logout">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i>Logout
                </button>
            </form>
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
                        class="form-control form-control-sm font-num"
                        placeholder="Scan telepon member"
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

    <!-- Perangkat: timbangan & printer (Web Serial) -->
    <div class="card pos-card mb-3">
        <div class="card-header bg-white py-2"><i class="bi bi-usb-plug me-1"></i>Perangkat</div>
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span>
                    <i class="bi bi-bullseye me-1"></i>Timbangan
                    <span class="badge text-bg-secondary ms-1" id="status-timbangan">Belum</span>
                </span>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-timbangan">
                        <i class="bi bi-plug me-1"></i>Hubungkan
                    </button>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-printer me-1"></i>Printer
                    <span class="badge text-bg-secondary ms-1" id="status-printer">Belum</span>
                </span>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-printer">
                        <i class="bi bi-plug me-1"></i>Hubungkan
                    </button>
                </div>
            </div>
            <div class="small text-muted mt-2" id="hw-kompatibilitas">
                Web Serial: Chrome/Edge desktop + HTTPS/localhost.
            </div>
        </div>
    </div>

    <!-- Ringkasan -->
    <div class="kiosk-ringkasan">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="kiosk-total-label">Total</span>
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

/**
 * Void (hapus) satu item dari keranjang. Butuh PIN supervisor/admin
 * (disimpan di pengaturan 'pin_supervisor', default 0000).
 */
function aksiVoidItem(int $produkId, string $pin, int $kasirId, string $namaKasir): void
{
    $pinBenar = \App\Models\Pengaturan::get('pin_supervisor', '0000');

    if (!hash_equals($pinBenar, $pin)) {
        \App\Models\AuditLog::catat('void_gagal', 'item_transaksi', $produkId, ['alasan' => 'PIN salah']);
        redirectSelf('PIN supervisor salah. Item tidak dihapus.', 'danger');
    }

    $keranjang = keranjang();

    if (!isset($keranjang[(string) $produkId])) {
        redirectSelf('Item tidak ada di keranjang.', 'danger');
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
    <link href="assets/theme.css?v=20260818" rel="stylesheet">
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
        <div>
            <h1 class="h3 mb-0">Transaksi Penjualan</h1>
            <span class="text-muted small">Kasir: <?= htmlspecialchars($namaUser) ?></span>
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

        <div class="pos-toolbar-row">
            <span class="pos-toolbar-label"><i class="bi bi-search me-1"></i>Cari</span>
            <form method="get" class="pos-toolbar-form">
                <input
                    type="search"
                    name="cari"
                    class="form-control"
                    placeholder="Ketik nama produk lalu Enter..."
                    value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>"
                    aria-label="Cari produk"
                >
                <button type="submit" class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i>Cari</button>
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
                <p class="mb-4">Anda harus <strong>buka kas</strong> dulu sebelum mulai transaksi. Isi modal awal (uang di laci) untuk memulai shift.</p>
                <form method="post" data-aksi="buka_kas">
                    <input type="hidden" name="aksi" value="buka_kas">
                    <div class="mb-4">
                        <label for="modal-awal" class="form-label">Modal awal (Rp)</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="modal-awal"
                            name="modal_awal"
                            class="form-control form-control-lg font-num"
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


<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/pos.js?v=20260818c"></script>
<script src="assets/hardware.js?v=20260818"></script>
<script src="assets/hardware-pos.js?v=20260818"></script>

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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" data-aksi="tutup_kas">
                <input type="hidden" name="aksi" value="tutup_kas">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tutup-label"><i class="bi bi-cash-coin me-1"></i>Tutup Kas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="small text-muted">Kas dibuka sejak</div>
                        <div class="fw-semibold" id="tutup-kas-dibuka">-</div>
                    </div>

                    <!-- Ringkasan & riwayat transaksi shift (diisi dinamis
                         via api.php?aksi=shift.ringkasan saat modal dibuka,
                         supaya selalu mutakhir walau shift baru dibuka lewat AJAX) -->
                    <div id="tutup-kas-ringkasan"></div>

                    <div class="mb-3">
                        <label for="kas-fisik" class="form-label">Uang di laci (kas fisik) — Rp</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="kas-fisik"
                            name="kas_fisik"
                            class="form-control form-control-lg font-num"
                            placeholder="Isi jumlah uang fisik di laci"
                            required
                        >
                        <div class="form-text" id="tutup-kas-hint">Total penjualan shift otomatis dihitung untuk rekonsiliasi.</div>
                    </div>
                    <div class="mb-2">
                        <label for="catatan-shift" class="form-label">Catatan (opsional)</label>
                        <input
                            type="text"
                            id="catatan-shift"
                            name="catatan_shift"
                            class="form-control"
                            placeholder="cth: shift pagi"
                        >
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning" id="btn-tutup-kas"><i class="bi bi-cash-coin me-1"></i>Tutup Kas</button>
                </div>
            </form>
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

<script src="https://unpkg.com/@zxing/library@0.21.3/umd/index.min.js"></script>
<script src="assets/scanner-pos.js?v=20260818"></script>
<script src="assets/theme.js?v=20260818"></script>
</body>
</html>
