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
            'harga'     => $produk->getHarga(),
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

    $transaksi = new Transaksi(['kasir_id' => $kasirId]);

    // Member transaksi (bila dipasang lewat scan telepon).
    $memberId = (int) ($_SESSION['member_id'] ?? 0);

    if ($memberId > 0) {
        $transaksi->setMemberId($memberId);
    }

    foreach ($keranjang as $item) {
        $produk = Produk::cari($item['produk_id']);

        if ($produk === null) {
            redirectSelf('Produk tidak ditemukan, keranjang tidak valid.', 'danger');
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
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit;

        case 'buka_kas':
            try {
                $modal = (float) ($_POST['modal_awal'] ?? 0);
                $shiftId = ShiftKasir::buka($userId, $modal);
                \App\Models\AuditLog::catat('buka_kas', 'shift_kasir', $shiftId, ['modal_awal' => $modal]);
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
                \App\Models\AuditLog::catat('tutup_kas', 'shift_kasir', (int) $shiftAktif->getId(), [
                    'kas_fisik' => $kasFisik,
                    'selisih'   => $shiftAktif->getSelisih(),
                ]);
                redirectSelf('Kas ditutup. Selisih: ' . formatRupiah((float) $shiftAktif->getSelisih()), 'success');
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
        /* Print: hanya tampilkan struk terakhir */
        @media print {
            body { background: #fff; color: #000; }
            .kiosk-wrapper, .kiosk-kiri, .kiosk-kanan, #flash-pesan { display: none !important; }
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
    <div class="kiosk-kiri <?= $wajibBukaKas ? 'd-none' : '' ?>">

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

    <?php if ($wajibBukaKas): ?>
        <!-- Wajib buka kas sebelum transaksi -->
        <div class="card pos-card mb-4 border-warning">
            <div class="card-header bg-warning-subtle text-warning-emphasis">
                <i class="bi bi-cash-stack me-1"></i>Buka Kas
            </div>
            <div class="card-body">
                <p class="mb-3">Anda harus <strong>buka kas</strong> dulu sebelum mulai transaksi. Isi modal awal (uang di laci) untuk memulai shift.</p>
                <form method="post" class="row g-2" data-aksi="buka_kas">
                    <input type="hidden" name="aksi" value="buka_kas">
                    <div class="col-md-4">
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
                        >
                    </div>
                    <div class="col-md-8 d-flex align-items-end">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-cash-coin me-1"></i>Buka Kas & Mulai Transaksi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

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
            <!-- Scan barcode: auto-fokus & auto-submit saat Enter -->
            <form method="post" data-aksi="scan" class="row g-2 mb-3">
                <input type="hidden" name="aksi" value="scan">
                <label for="input-barcode" class="col-auto col-form-label">
                    <i class="bi bi-upc-scan"></i>
                </label>
                <div class="col">
                    <input
                        type="text"
                        id="input-barcode"
                        name="barcode"
                        class="form-control font-num"
                        placeholder="Scan barcode... (Enter untuk tambah)"
                        autocomplete="off"
                        aria-label="Scan barcode"
                    >
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-primary" title="Tambah produk dari barcode">
                        <i class="bi bi-upc-scan me-1"></i>Scan
                    </button>
                </div>
                <div class="col-auto">
                    <button
                        type="button"
                        id="btn-kamera-barcode"
                        class="btn btn-success"
                        title="Scan barcode pakai kamera"
                    >
                        <i class="bi bi-camera me-1"></i>Kamera
                    </button>
                </div>
            </form>
            <div id="kamera-status" class="small text-muted mb-2 d-none"></div>

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
                                    <span class="text-muted ms-2"><?= formatRupiah($produkDitemukan->getHarga()) ?></span>
                                    <span class="badge text-bg-<?= $produkDitemukan->getStok() > 0 ? 'success' : 'danger' ?> ms-2">
                                        stok <?= $produkDitemukan->getStok() ?>
                                    </span>
                                </div>
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

    </div><!-- /.kiosk-kiri -->

    <!-- KOLOM KANAN (30%): userbar + ringkasan + numpad -->
    <div class="kiosk-kanan <?= $wajibBukaKas ? 'd-none' : '' ?>">
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
            // Pertahankan fokus barcode setelah fragment diganti (supaya
            // operator bisa langsung scan produk berikutnya).
            fokusBarcode();
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

        // Scan barcode: fokus otomatis saat halaman dimuat, dan setelah
        // submit, kosongkan + refokus supaya bisa scan produk berikutnya.
        function fokusBarcode() {
            var barcode = document.getElementById('input-barcode');
            if (barcode && !document.getElementById('area-struk').classList.contains('d-none')) {
                // Struk sedang tampil — jangan rebut fokus.
                return;
            }
            if (barcode) barcode.focus();
        }

        document.addEventListener('submit', function (e) {
            var form = e.target;
            var aksi = form.getAttribute('data-aksi');
            if (!aksi) return; // form biasa (mis. pencarian GET) tetap normal

            e.preventDefault();
            var data = new FormData(form);
            data.set('aksi', aksi);
            // Token CSRF untuk request fetch.
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) data.set('csrf', meta.getAttribute('content'));

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

                    if (aksi === 'scan') {
                        // Kosongkan field barcode & refokus untuk scan berikutnya.
                        var barcode = document.getElementById('input-barcode');
                        if (barcode) {
                            barcode.value = '';
                            barcode.focus();
                        }
                    }

                    if (res.struk) {
                        tampilkanStruk(res.struk);
                        // Hook hardware: cetak struk otomatis ke printer thermal.
                        if (window.afterBayarSukses) window.afterBayarSukses(res);
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
                var meta = document.querySelector('meta[name="csrf-token"]');
                var token = meta ? meta.getAttribute('content') : '';
                fetch('transaksi.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'aksi=hapus_struk&csrf=' + encodeURIComponent(token)
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
        fokusBarcode();

        // Modal void: isi produk id & nama dari tombol yang diklik.
        var modalVoid = document.getElementById('modal-void');
        if (modalVoid) {
            modalVoid.addEventListener('show.bs.modal', function (e) {
                var btn = e.relatedTarget;
                if (!btn) return;
                document.getElementById('void-produk-id').value = btn.getAttribute('data-produk-id') || '';
                var nama = document.getElementById('void-produk-nama');
                if (nama) nama.innerHTML = 'Hapus item: <strong>' + (btn.getAttribute('data-produk-nama') || '') + '</strong>';
                var pin = document.getElementById('void-pin');
                if (pin) { pin.value = ''; setTimeout(function () { pin.focus(); }, 300); }
            });
        }
    })();
</script>
<script src="assets/hardware.js"></script>
<script>
    // Hardware Integration (Web Serial): timbangan + printer thermal ESC/POS.
    (function () {
        'use strict';

        function initHardware() {
            if (!window.POSHardware) return;

            var didukung = POSHardware.didukung();
            var keterangan = document.getElementById('hw-kompatibilitas');
            if (!didukung && keterangan) {
                keterangan.textContent = 'Mode kompatibilitas: Web Serial tidak didukung, gunakan input manual.';
            }
            if (!didukung) return;

            POSHardware.muatConfig().then(function () {
                // Status LED.
                POSHardware.setOnStatus(function (st) {
                    var bT = document.getElementById('status-timbangan');
                    var bP = document.getElementById('status-printer');
                    if (bT) {
                        bT.className = 'badge ms-1 ' + (st.timbangan ? 'text-bg-success' : 'text-bg-secondary');
                        bT.textContent = st.timbangan ? 'Terhubung' : 'Belum';
                    }
                    if (bP) {
                        bP.className = 'badge ms-1 ' + (st.printer ? 'text-bg-success' : 'text-bg-secondary');
                        bP.textContent = st.printer ? 'Terhubung' : 'Belum';
                    }
                });

                // Tombol hubungkan timbangan.
                var btnT = document.getElementById('btn-timbangan');
                if (btnT) {
                    btnT.addEventListener('click', function () {
                        POSHardware.hubungkanTimbangan()
                            .catch(function (e) {
                                var fl = document.getElementById('flash-pesan');
                                if (fl) {
                                    fl.className = 'alert alert-danger alert-dismissible fade show';
                                    fl.innerHTML = 'Gagal hubungkan timbangan: ' + e.message +
                                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                                }
                            });
                    });
                }

                // Callback berat stabil -> isi input berat produk gram terfokus.
                POSHardware.setOnBerat(function (gram) {
                    var aktif = document.activeElement;
                    var input = null;
                    if (aktif && aktif.hasAttribute('data-produk-gram')) {
                        input = aktif;
                    } else {
                        // Fallback: isi input gram pertama yang ada.
                        input = document.querySelector('input[data-produk-gram]');
                    }
                    if (input) {
                        input.value = gram.toFixed(3);
                    }
                });

                // Tombol timbang per produk: baca berat terakhir -> isi input + submit.
                document.addEventListener('click', function (e) {
                    var tombol = e.target.closest('[data-timbang]');
                    if (!tombol) return;
                    var produkId = tombol.getAttribute('data-timbang');
                    var berat = POSHardware.getBeratGram();
                    if (berat <= 0) {
                        var fl = document.getElementById('flash-pesan');
                        if (fl) {
                            fl.className = 'alert alert-warning alert-dismissible fade show';
                            fl.innerHTML = 'Belum ada pembacaan berat dari timbangan.' +
                                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                        }
                        return;
                    }
                    var input = document.querySelector('input[data-produk-gram="' + produkId + '"]');
                    if (input) {
                        input.value = berat.toFixed(3);
                        // Submit form tambah_item.
                        var form = input.closest('form[data-aksi="tambah_item"]');
                        if (form) form.requestSubmit();
                    }
                });

                // Tombol hubungkan printer.
                var btnP = document.getElementById('btn-printer');
                if (btnP) {
                    btnP.addEventListener('click', function () {
                        POSHardware.hubungkanPrinter()
                            .catch(function (e) {
                                var fl = document.getElementById('flash-pesan');
                                if (fl) {
                                    fl.className = 'alert alert-danger alert-dismissible fade show';
                                    fl.innerHTML = 'Gagal hubungkan printer: ' + e.message +
                                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                                }
                            });
                    });
                }

                // Cetak struk otomatis setelah bayar sukses (tanpa dialog print).
                var asli = window.tampilkanStrukAfterBayar || null;
                window.tampilkanStrukAfterBayar = function (struk) {
                    if (asli) asli(struk);
                    var st = POSHardware.getStatus();
                    if (st.printer) {
                        POSHardware.cetakStruk(struk).catch(function () { /* biarkan */ });
                    }
                };
            });
        }

        // Hook: setelah bayar sukses (AJAX) -> panggil cetak otomatis.
        // Dipasang setelah blok fetch utama didefinisikan.
        var origBayar = window.afterBayarSukses || null;
        window.afterBayarSukses = function (res) {
            if (origBayar) origBayar(res);
            if (res && res.struk && window.POSHardware) {
                var st = POSHardware.getStatus();
                if (st.printer) {
                    POSHardware.cetakStruk(res.struk).catch(function () { /* biarkan */ });
                }
            }
        };

        // Panggil initHardware dari scope utama (sudah dipanggil di atas).
        if (window.POSHardware) {
            try { initHardware(); } catch (e) { /* jangan ganggu alur kasir */ }
        }
    })();
</script>

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
    <div class="modal-dialog">
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
                        <div class="fw-semibold"><?= $shiftAktif !== null ? date('d-m-Y H:i', strtotime($shiftAktif->getDibukaPada())) : '-' ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="kas-fisik" class="form-label">Uang di laci (kas fisik) — Rp</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="kas-fisik"
                            name="kas_fisik"
                            class="form-control form-control-lg font-num"
                            required
                        >
                        <div class="form-text">Total penjualan shift ini otomatis dihitung untuk rekonsiliasi.</div>
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
                    <button type="submit" class="btn btn-warning"><i class="bi bi-cash-coin me-1"></i>Tutup Kas</button>
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
<script>
    // Kamera scan barcode: pakai BarcodeDetector native (Chrome/Edge),
    // fallback ZXing untuk browser lain. Begitu barcode terbaca, otomatis
    // isi field & submit (sama seperti scan manual).
    (function () {
        'use strict';

        var btn = document.getElementById('btn-kamera-barcode');
        if (!btn) return;

        var modal = document.getElementById('modal-kamera');
        var video = document.getElementById('video-kamera');
        var statusEl = document.getElementById('kamera-status');
        var errorEl = document.getElementById('kamera-error');
        var stream = null;
        var detector = null;
        var zxingReader = null;
        var scanning = false;
        var pernahTerbaca = false;

        // Dukungan BarcodeDetector native?
        var nativeDetector = 'BarcodeDetector' in window && typeof window.BarcodeDetector !== 'undefined';
        var zxingTersedia = typeof window.ZXing !== 'undefined' || (window.ZXing && window.ZXing.BrowserMultiFormatReader);

        function setStatus(teks, warna) {
            if (!statusEl) return;
            statusEl.textContent = teks;
            statusEl.className = 'small mb-2 ' + (warna || 'text-muted');
            statusEl.classList.remove('d-none');
        }

        function setError(teks) {
            if (!errorEl) return;
            // innerHTML: pesan ini selalu dari kode sendiri (statis), bukan
            // input user — aman dan memungkinkan penekanan <strong>.
            errorEl.innerHTML = teks;
            errorEl.classList.remove('d-none');
        }

        function bersihkanError() {
            if (errorEl) errorEl.classList.add('d-none');
        }

        function hentikanStream() {
            if (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                stream = null;
            }
            scanning = false;
            pernahTerbaca = false;
            if (video) video.srcObject = null;
        }

        function tutupModal() {
            var bs = window.bootstrap && bootstrap.Modal.getInstance(modal);
            if (bs) bs.hide();
            hentikanStream();
        }

        function prosesBarcode(nilai) {
            if (pernahTerbaca) return;
            pernahTerbaca = true;

            var input = document.getElementById('input-barcode');
            if (input) {
                input.value = nilai;
            }

            // Submit form scan (AJAX) — barang langsung masuk keranjang.
            var form = input && input.closest('form[data-aksi="scan"]');
            if (form) {
                // Tunggu sebentar supaya user lihat hasilnya, lalu submit.
                setTimeout(function () {
                    tutupModal();
                    form.requestSubmit();
                }, 400);
            } else {
                tutupModal();
            }
        }

        // Buka kamera.
        function bukaKamera() {
            bersihkanError();
            pernahTerbaca = false;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                // Cek penyebab: biasanya halaman dibuka via HTTP non-localhost
                // (browser hanya izinkan kamera di HTTPS / localhost).
                var host = window.location.hostname;
                var isi = 'Browser tidak mengizinkan akses kamera di halaman ini. ';
                if (window.location.protocol !== 'https:' && host !== 'localhost' && host !== '127.0.0.1') {
                    isi += 'Akses kamera butuh HTTPS atau localhost. Buka lewat <strong>http://localhost/kasir-minimarket</strong> ' +
                        '(bukan ' + host + '), atau akses dari perangkat lain via HTTPS.';
                } else {
                    isi += 'Gunakan Chrome/Edge versi terbaru, atau scan manual / scanner USB.';
                }
                setError(isi);
                return;
            }

            setStatus('Meminta izin kamera...', 'text-info');

            navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1280 } },
                audio: false
            }).then(function (s) {
                stream = s;
                video.srcObject = s;
                video.play().catch(function () { /* autoplay muted */ });

                setStatus('Kamera aktif. Arahkan ke barcode produk.');

                // Siapkan detector.
                if (nativeDetector) {
                    detector = new window.BarcodeDetector({
                        formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code']
                    });
                } else if (zxingTersedia) {
                    zxingReader = new window.ZXing.BrowserMultiFormatReader();
                } else {
                    setStatus('Pustaka deteksi belum siap, coba lagi.', 'text-danger');
                    return;
                }

                scanning = true;
                loopDeteksi();
            }).catch(function (err) {
                setError('Tidak bisa akses kamera: ' + (err && err.name ? err.name : 'ditolak') +
                    '. Izinkan akses kamera, atau gunakan scan manual / scanner USB.');
                setStatus('');
            });
        }

        // Deteksi berulang: native BarcodeDetector (async) atau ZXing
        // (decode frame dari canvas — tidak membuka stream sendiri).
        function loopDeteksi() {
            if (!scanning) return;

            if (nativeDetector && detector) {
                detector.detect(video).then(function (hasil) {
                    if (hasil && hasil.length > 0 && hasil[0].rawValue) {
                        prosesBarcode(hasil[0].rawValue);
                        return;
                    }
                    requestAnimationFrame(loopDeteksi);
                }).catch(function () {
                    requestAnimationFrame(loopDeteksi);
                });
            } else if (zxingReader) {
                // Ambil frame dari video -> canvas -> decode.
                var canvas = document.getElementById('kamera-canvas') || document.createElement('canvas');
                canvas.id = 'kamera-canvas';
                canvas.width = video.videoWidth || 640;
                canvas.height = video.videoHeight || 480;
                var ctx = canvas.getContext('2d', { willReadFrequently: true });
                if (ctx && video.videoWidth > 0) {
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    try {
                        var hasil = zxingReader.decodeFromImage(canvas);
                        if (hasil && hasil.getText && hasil.getText()) {
                            prosesBarcode(hasil.getText());
                            return;
                        }
                    } catch (e) { /* belum terbaca — lanjut */ }
                }
                requestAnimationFrame(loopDeteksi);
            }
        }

        btn.addEventListener('click', function () {
            var bs = window.bootstrap && bootstrap.Modal.getOrCreateInstance(modal);
            if (bs) bs.show();
            // Modal ditampilkan lewat event -> buka kamera setelah tampil.
            setTimeout(bukaKamera, 300);
        });

        // Tutup / sembunyikan -> matikan kamera.
        modal.addEventListener('hidden.bs.modal', hentikanStream);

        // Tombol tutup manual.
        var tutupBtn = document.getElementById('tutup-kamera');
        if (tutupBtn) {
            tutupBtn.addEventListener('click', function () {
                tutupModal();
            });
        }
    })();
</script>
<script src="assets/theme.js"></script>
</body>
</html>
