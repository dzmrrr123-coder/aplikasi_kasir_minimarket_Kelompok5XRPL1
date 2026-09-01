<?php

declare(strict_types=1);

/**
 * Front-controller AJAX (admin only).
 *
 * Satu-satunya endpoint JSON untuk Chart.js & DataTables:
 *   api.php?aksi=dashboard.grafik
 *   api.php?aksi=dashboard.ringkasan
 *   api.php?aksi=dashboard.terlaris
 *   api.php?aksi=dashboard.transaksi
 *   api.php?aksi=dashboard.stok_kategori
 *   api.php?aksi=laporan.grafik
 *   api.php?aksi=laporan.tabel
 *   api.php?aksi=retur.tabel
 *   api.php?aksi=retur.grafik
 *
 * Tidak ada query SQL / akses DB di sini — semua data dari Controller
 * yang memanggil objek DataReporter. Output JSON murni.
 */

require __DIR__ . '/../src/autoload.php';

use App\Controllers\DashboardController;
use App\Controllers\InventarisController;
use App\Controllers\LabaController;
use App\Controllers\LaporanController;
use App\Controllers\ReturController;
use App\Controllers\ShiftController;
use App\Models\User;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Guard: wajib login. Aksi hardware.config boleh diakses kasir (POS),
// sisanya khusus admin.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$aksi = (string) ($_GET['aksi'] ?? ($_POST['aksi'] ?? ''));

// Aksi yang boleh diakses kasir (data milik kasir sendiri / konfigurasi
// hardware POS yang tidak sensitif). Sisanya khusus admin.
$aksiKasir = ['hardware.config', 'shift.ringkasan', 'device.list', 'device.set', 'device.remove', 'transaksi.detail', 'produk.cari_cepat', 'sync.transaksi', 'sync.status', 'gateway.status', 'gateway.create'];

if ($_SESSION['role'] !== 'admin' && !in_array($aksi, $aksiKasir, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// Baca params dari request (GET utk data, POST utk filter berat).
// Gunakan $_GET untuk menentukan aksi router agar form POST tanpa aksi tetap valid.
$params = array_merge($_GET, $_POST);
$aksi = (string) ($_GET['aksi'] ?? $params['aksi'] ?? '');
unset($params['aksi']);

// Normalisasi parameter DataTables server-side:
// - search[value] dikirim sebagai array -> ambil string-nya
// - start/length/draw dijamin int
if (isset($params['search']) && is_array($params['search'])) {
    $params['search'] = (string) ($params['search']['value'] ?? '');
}
if (isset($params['start'])) {
    $params['start'] = (int) $params['start'];
}
if (isset($params['length'])) {
    $params['length'] = (int) $params['length'];
}
if (isset($params['draw'])) {
    $params['draw'] = (int) $params['draw'];
}

$dashboard = new DashboardController();
$laporan = new LaporanController();
$retur = new ReturController();
$inventaris = new InventarisController();
$laba = new LabaController();
$shift = new ShiftController();

$hasil = null;

switch ($aksi) {
    case 'device.list':
        // Daftar device yang terpasang kasir yang login (GET, tidak butuh CSRF).
        $hasil = ['printer' => null, 'timbangan' => null, 'semua' => []];
        $user = User::cariBerdasarkanId((int) $_SESSION['user_id']);
        if ($user !== null) {
            foreach ($user->getDevices() as $d) {
                if (!(bool) $d['is_aktif']) {
                    continue; // skip yang sudah dilepas
                }
                $hasil['semua'][] = $d;
                $hasil[$d['tipe']] = $d;
            }
        }
        break;

    case 'device.set':
        // Simpan / ganti device pairing (POST, butuh CSRF).
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $tipe = (string) ($params['tipe'] ?? '');
        $label = trim((string) ($params['label'] ?? ''));
        $user = User::cariBerdasarkanId((int) $_SESSION['user_id']);
        if ($user === null) {
            http_response_code(401);
            echo json_encode(['error' => 'user tidak ditemukan']);
            exit;
        }
        $user->setDevice($tipe, $label);
        $hasil = ['status' => 'ok', 'device' => $user->getDeviceByTipe($tipe)];
        break;

    case 'device.remove':
        // Lepas device pairing (POST, butuh CSRF).
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $tipe = (string) ($params['tipe'] ?? '');
        $user = User::cariBerdasarkanId((int) $_SESSION['user_id']);
        if ($user !== null) {
            $user->removeDevice($tipe);
        }
        $hasil = ['status' => 'ok'];
        break;

    case 'hardware.config':
        // Konfigurasi Web Serial (timbangan & printer) untuk frontend.
        $hardware = require __DIR__ . '/../config/hardware.php';
        $hasil = is_array($hardware) ? $hardware : ['timbangan' => [], 'printer' => []];
        break;

    case 'produk.tabel':
        $hasil = $inventaris->dataTabelProduk($params);
        break;

    // ---- Inline Kategori CRUD (admin workflow) ----
    case 'kategori.simpan':
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $namaBaru = trim((string) ($params['nama_kategori'] ?? $params['nama'] ?? ''));
        $editIdKat = (int) ($params['edit_id'] ?? 0);
        if ($namaBaru === '') {
            $hasil = ['sukses' => false, 'pesan' => 'Nama kategori tidak boleh kosong.'];
            break;
        }
        try {
            if ($editIdKat > 0) {
                $kat = \App\Models\Kategori::cari($editIdKat);
                if ($kat === null) { $hasil = ['sukses' => false, 'pesan' => 'Kategori tidak ditemukan.']; break; }
                $kat->setNama($namaBaru);
                $kat->perbarui();
                $hasil = ['sukses' => true, 'pesan' => 'Kategori diperbarui.', 'id' => $editIdKat, 'nama' => $namaBaru];
            } else {
                $kat = new \App\Models\Kategori(['nama' => $namaBaru]);
                $newId = $kat->simpan();
                $hasil = ['sukses' => true, 'pesan' => 'Kategori ditambahkan.', 'id' => (int) $newId, 'nama' => $namaBaru];
            }
        } catch (\Throwable $e) {
            $hasil = ['sukses' => false, 'pesan' => pesanErrorRamah($e)];
        }
        break;

    case 'kategori.hapus':
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $hapusId = (int) ($params['kategori_id'] ?? $params['id'] ?? 0);
        if ($hapusId <= 0) { $hasil = ['sukses' => false, 'pesan' => 'ID tidak valid.']; break; }
        try {
            $katH = \App\Models\Kategori::cari($hapusId);
            if ($katH === null) { $hasil = ['sukses' => false, 'pesan' => 'Kategori tidak ditemukan.']; break; }
            $katH->hapus();
            $hasil = ['sukses' => true, 'pesan' => 'Kategori dihapus.'];
        } catch (\Throwable $e) {
            $hasil = ['sukses' => false, 'pesan' => 'Kategori tidak bisa dihapus, masih dipakai produk.'];
        }
        break;

    case 'kategori.list':
        $semuaKat = \App\Models\Kategori::semua();
        $katList = [];
        foreach ($semuaKat as $sk) {
            $katList[] = ['id' => (int) $sk->getId(), 'nama' => $sk->getNama()];
        }
        $hasil = ['sukses' => true, 'kategori' => $katList];
        break;

    // ---- Produk Detail (for modal) ----
    case 'produk.detail':
        $detailId = (int) ($params['id'] ?? 0);
        if ($detailId <= 0) { $hasil = ['sukses' => false, 'pesan' => 'ID tidak valid.']; break; }
        $dp = \App\Models\Produk::cari($detailId);
        if ($dp === null) { $hasil = ['sukses' => false, 'pesan' => 'Produk tidak ditemukan.']; break; }
        $hasil = [
            'sukses'         => true,
            'id'             => (int) $dp->getId(),
            'nama'           => $dp->getNama(),
            'harga'          => $dp->getHarga(),
            'harga_beli'     => $dp->getHargaBeli(),
            'stok'           => $dp->getStok(),
            'stok_minimum'   => $dp->getStokMinimum(),
            'satuan'         => $dp->getSatuan(),
            'harga_per_gram' => $dp->getHargaPerGram(),
            'barcode'        => $dp->getBarcode(),
            'kategori'       => $dp->getKategori()->getNama(),
            'kategori_id'    => (int) $dp->getKategori()->getId(),
            'supplier'       => $dp->getSupplierNama(),
            'supplier_id'    => $dp->getSupplierId(),
            'gambar'         => $dp->getGambar(),
            'is_active'      => $dp->isActive(),
            'harga_grosir'   => $dp->getHargaGrosir(),
            'batas_grosir'   => $dp->getBatasGrosir(),
        ];
        break;

    // ---- Simpan / Edit Produk via AJAX ----
    case 'produk.simpan':
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $editId = (int) ($params['id'] ?? ($params['edit_id'] ?? 0));
        $nama = trim((string) ($params['nama'] ?? ''));
        $harga = (float) ($params['harga'] ?? 0);
        $stok = (int) ($params['stok'] ?? 0);
        $kategoriId = (int) ($params['kategori_id'] ?? 0);
        $barcode = trim((string) ($params['barcode'] ?? ''));
        $hargaBeli = (float) ($params['harga_beli'] ?? 0);
        $stokMinimum = (int) ($params['stok_minimum'] ?? 0);
        $supplierId = (int) ($params['supplier_id'] ?? 0);
        $satuan = (string) ($params['satuan'] ?? 'pcs');
        $hargaPerGram = (float) ($params['harga_per_gram'] ?? 0);
        $gambar = trim((string) ($params['gambar_lama'] ?? ''));
        $hapusGambar = !empty($params['hapus_gambar']);

        if ($hapusGambar) {
            $gambar = '';
        }

        $satuan = $satuan === 'gram' ? 'gram' : 'pcs';
        $hargaPerGram = $satuan === 'gram' ? $hargaPerGram : 0.0;

        // Upload gambar bila ada file baru diunggah
        if (!empty($_FILES['gambar']['name']) && is_uploaded_file($_FILES['gambar']['tmp_name'] ?? '')) {
            $file = $_FILES['gambar'];
            $izin = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $info = @getimagesize((string) ($file['tmp_name'] ?? ''));
            if ($info === false) {
                $hasil = ['sukses' => false, 'pesan' => 'File yang diunggah bukan gambar yang valid.'];
                break;
            }
            $tipeAsli = (string) ($info['mime'] ?? '');
            $ekstensi = $izin[$tipeAsli] ?? '';
            if ($ekstensi === '' || (int) $file['size'] > 2 * 1024 * 1024) {
                $hasil = ['sukses' => false, 'pesan' => 'Gambar harus berformat JPG/PNG/WEBP/GIF dan maks 2 MB.'];
                break;
            }
            $namaFile = 'produk-' . bin2hex(random_bytes(8)) . '.' . $ekstensi;
            $folder = __DIR__ . '/uploads';
            if (!is_dir($folder)) {
                mkdir($folder, 0775, true);
            }
            if (move_uploaded_file($file['tmp_name'], $folder . '/' . $namaFile)) {
                $gambar = $namaFile;
            } else {
                $hasil = ['sukses' => false, 'pesan' => 'Gagal menyimpan gambar di server.'];
                break;
            }
        }

        $kategori = \App\Models\Kategori::cari($kategoriId);
        if ($kategori === null) {
            $hasil = ['sukses' => false, 'pesan' => 'Kategori tidak valid, silakan pilih kategori yang tersedia.'];
            break;
        }
        if ($nama === '') {
            $hasil = ['sukses' => false, 'pesan' => 'Nama produk tidak boleh kosong.'];
            break;
        }
        if ($harga < 0) {
            $hasil = ['sukses' => false, 'pesan' => 'Harga produk tidak boleh bernilai negatif.'];
            break;
        }
        if ($stok < 0) {
            $hasil = ['sukses' => false, 'pesan' => 'Stok produk tidak boleh bernilai negatif.'];
            break;
        }

        try {
            if ($editId > 0) {
                $produk = \App\Models\Produk::cari($editId);
                if ($produk === null) {
                    $hasil = ['sukses' => false, 'pesan' => 'Produk tidak ditemukan.'];
                    break;
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

                $hasil = [
                    'sukses' => true,
                    'pesan'  => 'Produk "' . $nama . '" berhasil diperbarui.',
                    'id'     => $editId,
                    'produk' => [
                        'id'             => $editId,
                        'nama'           => $nama,
                        'harga'          => $harga,
                        'stok'           => $stok,
                        'kategori'       => $kategori->getNama(),
                        'kategori_id'    => $kategoriId,
                        'barcode'        => $barcode,
                        'supplier_id'    => $supplierId,
                        'supplier_nama'  => $produk->getSupplierNama(),
                        'satuan'         => $satuan,
                        'harga_beli'     => $hargaBeli,
                        'stok_minimum'   => $stokMinimum,
                        'harga_per_gram' => $hargaPerGram,
                        'gambar'         => $gambar,
                    ],
                ];
            } else {
                $produk = new \App\Models\Produk([
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
                $newId = $produk->simpan();
                $hasil = [
                    'sukses' => true,
                    'pesan'  => 'Produk "' . $nama . '" berhasil ditambahkan.',
                    'id'     => $newId,
                    'produk' => [
                        'id'             => $newId,
                        'nama'           => $nama,
                        'harga'          => $harga,
                        'stok'           => $stok,
                        'kategori'       => $kategori->getNama(),
                        'kategori_id'    => $kategoriId,
                        'barcode'        => $barcode,
                        'supplier_id'    => $supplierId,
                        'supplier_nama'  => $produk->getSupplierNama(),
                        'satuan'         => $satuan,
                        'harga_beli'     => $hargaBeli,
                        'stok_minimum'   => $stokMinimum,
                        'harga_per_gram' => $hargaPerGram,
                        'gambar'         => $gambar,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            $hasil = ['sukses' => false, 'pesan' => pesanErrorRamah($e)];
        }
        break;

    // ---- Inline Stok Edit ----
    case 'produk.update_stok':
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $upId = (int) ($params['id'] ?? 0);
        $upStok = (int) ($params['stok'] ?? 0);
        if ($upId <= 0 || $upStok < 0) { $hasil = ['sukses' => false, 'pesan' => 'Data tidak valid.']; break; }
        try {
            $upProduk = \App\Models\Produk::cari($upId);
            if ($upProduk === null) { $hasil = ['sukses' => false, 'pesan' => 'Produk tidak ditemukan.']; break; }
            $upProduk->setStok($upStok);
            $upProduk->perbarui();
            $hasil = ['sukses' => true, 'pesan' => 'Stok diperbarui.', 'stok' => $upStok];
        } catch (\Throwable $e) {
            $hasil = ['sukses' => false, 'pesan' => pesanErrorRamah($e)];
        }
        break;

    // ---- Single Delete ----
    case 'produk.hapus':
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $hapusProdukId = (int) ($params['id'] ?? 0);
        if ($hapusProdukId <= 0) { $hasil = ['sukses' => false, 'pesan' => 'ID tidak valid.']; break; }
        try {
            $produkH = \App\Models\Produk::cari($hapusProdukId);
            if ($produkH === null) { $hasil = ['sukses' => false, 'pesan' => 'Produk tidak ditemukan.']; break; }
            $produkH->hapus();
            $hasil = ['sukses' => true, 'pesan' => 'Produk dihapus.'];
        } catch (\Throwable $e) {
            $hasil = ['sukses' => false, 'pesan' => 'Produk tidak bisa dihapus, masih dipakai transaksi.'];
        }
        break;

    // ---- Bulk Actions ----
    case 'produk.bulk_hapus':
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $rawIds = $params['ids'] ?? [];
        if (is_string($rawIds)) {
            $decoded = json_decode($rawIds, true);
            $rawIds = is_array($decoded) ? $decoded : [];
        }
        $bulkIds = array_map('intval', $rawIds);
        $bulkIds = array_filter($bulkIds, fn($v) => $v > 0);
        if ($bulkIds === []) { $hasil = ['sukses' => false, 'pesan' => 'Tidak ada produk dipilih.']; break; }
        $deleted = 0;
        $errors = [];
        foreach ($bulkIds as $bid) {
            try {
                $bp = \App\Models\Produk::cari($bid);
                if ($bp !== null) { $bp->hapus(); $deleted++; }
            } catch (\Throwable $e) {
                $errors[] = $bid;
            }
        }
        $pesan = $deleted . ' produk dihapus.';
        if ($errors !== []) $pesan .= ' ' . count($errors) . ' gagal (masih dipakai transaksi).';
        $hasil = ['sukses' => true, 'pesan' => $pesan, 'dihapus' => $deleted, 'gagal' => count($errors)];
        break;

    case 'produk.bulk_kategori':
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }
        $rawIds2 = $params['ids'] ?? [];
        if (is_string($rawIds2)) {
            $decoded2 = json_decode($rawIds2, true);
            $rawIds2 = is_array($decoded2) ? $decoded2 : [];
        }
        $bulkIds2 = array_map('intval', $rawIds2);
        $bulkIds2 = array_filter($bulkIds2, fn($v) => $v > 0);
        $newKatId = (int) ($params['kategori_id'] ?? 0);
        if ($bulkIds2 === [] || $newKatId <= 0) { $hasil = ['sukses' => false, 'pesan' => 'Data tidak valid.']; break; }
        $newKat = \App\Models\Kategori::cari($newKatId);
        if ($newKat === null) { $hasil = ['sukses' => false, 'pesan' => 'Kategori tidak valid.']; break; }
        $updated = 0;
        foreach ($bulkIds2 as $bid2) {
            try {
                $bp2 = \App\Models\Produk::cari($bid2);
                if ($bp2 !== null) { $bp2->setKategori($newKat); $bp2->perbarui(); $updated++; }
            } catch (\Throwable $e) { /* skip */ }
        }
        $hasil = ['sukses' => true, 'pesan' => $updated . ' produk dipindahkan ke kategori baru.', 'dipindah' => $updated];
        break;

    case 'supplier.tabel':
        $hasil = $inventaris->dataTabelSupplier($params);
        break;

    case 'pembelian.tabel':
        $hasil = $inventaris->dataTabelPembelian($params);
        break;

    case 'member.tabel':
        $hasil = $inventaris->dataTabelMember($params);
        break;

    case 'member.cari':
        $hasil = $inventaris->cariMember($params);
        break;

    case 'produk.harga_beli':
        $hasil = $inventaris->hargaBeliTerakhir($params);
        break;

    case 'shift.tabel':
        $hasil = $shift->dataTabelShift($params);
        break;

    case 'shift.ringkasan':
        // Ringkasan shift aktif untuk modal tutup kas (riwayat transaksi).
        $hasil = $shift->ringkasanShift($params);
        break;

    case 'audit.tabel':
        $hasil = $shift->dataTabelAudit($params);
        break;

    case 'dashboard.grafik':
        $hasil = $dashboard->grafikPenjualan($params);
        break;

    case 'dashboard.ringkasan':
        $hasil = $dashboard->ringkasan($params);
        break;

    case 'dashboard.terlaris':
        $hasil = $dashboard->dataTabelTerlaris($params);
        break;

    case 'dashboard.transaksi':
        $hasil = $dashboard->dataTabelTransaksi($params);
        break;

    case 'dashboard.stok_kategori':
        $hasil = $dashboard->grafikStokKategori($params);
        break;

    case 'laporan.grafik':
        $hasil = $laporan->grafikPeriode($params);
        break;

    case 'laporan.tabel':
        $hasil = $laporan->dataTabelTransaksi($params);
        break;

    case 'laba.ringkasan':
        $hasil = $laba->ringkasan($params);
        break;

    case 'laba.grafik':
        $hasil = $laba->grafik($params);
        break;

    case 'laba.tabel':
        $hasil = $laba->dataTabel($params);
        break;

    case 'dashboard.jam_ramai':
        $hasil = \App\Models\Dashboard::jamRamai(
            (string) ($params['tanggal_mulai'] ?? date('Y-m-01')),
            (string) ($params['tanggal_akhir'] ?? date('Y-m-d'))
        );
        break;

    case 'dashboard.penjualan_per_kasir':
        $hasil = \App\Models\Dashboard::penjualanPerKasir(
            (string) ($params['tanggal_mulai'] ?? date('Y-m-01')),
            (string) ($params['tanggal_akhir'] ?? date('Y-m-d'))
        );
        break;

    case 'retur.tabel':
        $hasil = $retur->dataTabelRiwayat($params);
        break;

    case 'retur.supplier_asal':
        // Supplier asal produk (dari pembelian/stok masuk terakhir).
        $hasil = $retur->supplierAsalProduk($params);
        break;

    case 'transaksi.detail':
        $txId = (int) ($params['id'] ?? 0);
        $tx = \App\Models\Transaksi::cari($txId);
        if ($tx === null) {
            $hasil = ['sukses' => false, 'pesan' => 'Transaksi tidak ditemukan.'];
            break;
        }
        // Cek hak akses: kasir hanya boleh melihat transaksi miliknya sendiri
        if ($_SESSION['role'] === 'kasir' && $tx->getKasirId() !== (int) $_SESSION['user_id']) {
            $hasil = ['sukses' => false, 'pesan' => 'Akses ditolak.'];
            break;
        }
        $items = \App\Models\ItemTransaksi::untukTransaksi($txId);
        $itemRows = [];
        foreach ($items as $it) {
            $p = $it->getProduk();
            $itemRows[] = [
                'nama'     => $p->getNama(),
                'satuan'   => $p->getSatuan(),
                'qty'      => $it->getQty(),
                'harga'    => $it->getHarga(),
                'subtotal' => $it->getSubtotal(),
            ];
        }
        $hasil = [
            'sukses'     => true,
            'id'         => $txId,
            'tanggal'    => $tx->getTanggal()->format('d/m/Y H:i'),
            'kasir_nama' => $tx->getKasirNama(),
            'total'      => $tx->getTotal(),
            'pajak'      => $tx->getPajak(),
            'items'      => $itemRows,
        ];
        break;

    case 'sync.transaksi':
        // Sinkronisasi transaksi dari mode offline ke server.
        // Menerima data transaksi dari IndexedDB client dan menyimpannya
        // layaknya transaksi normal (update stok, simpan item, dll.).
        if (!csrf_valid()) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid csrf token']);
            exit;
        }

        $localId = (string) ($params['local_id'] ?? '');
        $kasirId = (int) ($params['kasir_id'] ?? ($_SESSION['user_id'] ?? 0));
        $total = (float) ($params['total'] ?? 0);
        $metode = (string) ($params['metode'] ?? 'tunai');
        $jumlahBayar = (float) ($params['jumlah_bayar'] ?? 0);
        $kembalian = (float) ($params['kembalian'] ?? 0);
        $pajak = (float) ($params['pajak'] ?? 0);
        $diskonId = !empty($params['diskon_id']) ? (int) $params['diskon_id'] : null;
        $memberId = !empty($params['member_id']) ? (int) $params['member_id'] : null;
        $shiftId = !empty($params['shift_id']) ? (int) $params['shift_id'] : null;
        $itemsJson = (string) ($params['items'] ?? '[]');
        $timestamp = (string) ($params['timestamp'] ?? '');

        if ($total <= 0 || $kasirId <= 0) {
            $hasil = ['sukses' => false, 'pesan' => 'Data transaksi tidak valid.'];
            break;
        }

        try {
            $pdo = \App\Database\Database::connect();
            $pdo->beginTransaction();

            // Cek duplikat berdasarkan local_id + timestamp
            $cekStmt = $pdo->prepare(
                "SELECT id FROM transaksi WHERE kasir_id = :kid AND tanggal = :ts AND total = :tot LIMIT 1"
            );
            $cekStmt->execute([':kid' => $kasirId, ':ts' => $timestamp, ':tot' => $total]);
            if ($cekStmt->fetch()) {
                $pdo->rollBack();
                $hasil = ['sukses' => false, 'pesan' => 'Transaksi sudah pernah disinkronkan (duplikat).'];
                break;
            }

            // Simpan transaksi
            $stmt = $pdo->prepare(
                'INSERT INTO transaksi (tanggal, total, pajak, kasir_id, diskon_id, pembayaran_id, member_id)
                 VALUES (:tanggal, :total, :pajak, :kasir_id, NULL, NULL, :member_id)'
            );
            $stmt->execute([
                ':tanggal'   => $timestamp ?: date('Y-m-d H:i:s'),
                ':total'     => $total,
                ':pajak'     => $pajak,
                ':kasir_id'  => $kasirId,
                ':member_id' => $memberId,
            ]);
            $transaksiId = (int) $pdo->lastInsertId();

            // Simpan item transaksi + update stok
            $items = json_decode($itemsJson, true) ?: [];
            foreach ($items as $item) {
                $produkId = (int) ($item['produk_id'] ?? 0);
                $qty = (float) ($item['qty'] ?? 0);
                $subtotal = (float) ($item['subtotal'] ?? 0);
                $hargaBeli = (float) ($item['harga_beli_satuan'] ?? 0);

                if ($produkId <= 0 || $qty <= 0) continue;

                // Insert item
                $itemStmt = $pdo->prepare(
                    'INSERT INTO item_transaksi (transaksi_id, produk_id, qty, subtotal, harga_beli_satuan)
                     VALUES (:tx_id, :produk_id, :qty, :subtotal, :harga_beli)'
                );
                $itemStmt->execute([
                    ':tx_id'      => $transaksiId,
                    ':produk_id'  => $produkId,
                    ':qty'        => $qty,
                    ':subtotal'   => $subtotal,
                    ':harga_beli' => $hargaBeli,
                ]);

                // Kurangi stok
                $stokStmt = $pdo->prepare(
                    'UPDATE produk SET stok = stok - :qty WHERE id = :id AND stok >= :qty2'
                );
                $stokStmt->execute([':qty' => $qty, ':id' => $produkId, ':qty2' => $qty]);

                if ($stokStmt->rowCount() === 0) {
                    $pdo->rollBack();
                    $hasil = ['sukses' => false, 'pesan' => 'Stok produk #' . $produkId . ' tidak cukup saat sinkronisasi.'];
                    break 2;
                }
            }

            // Simpan pembayaran
            $bayarStmt = $pdo->prepare(
                'INSERT INTO pembayaran (jenis, jumlah) VALUES (:jenis, :jumlah)'
            );
            $bayarStmt->execute([':jenis' => $metode, ':jumlah' => $jumlahBayar]);
            $pembayaranId = (int) $pdo->lastInsertId();

            // Update transaksi dengan pembayaran_id
            $updateStmt = $pdo->prepare('UPDATE transaksi SET pembayaran_id = :pid WHERE id = :id');
            $updateStmt->execute([':pid' => $pembayaranId, ':id' => $transaksiId]);

            // Rekap penjualan
            $rekapStmt = $pdo->prepare(
                'INSERT INTO rekap_penjualan (transaksi_id, tanggal, total, kasir_id, metode)
                 VALUES (:tx_id, :tanggal, :total, :kasir_id, :metode)'
            );
            $rekapStmt->execute([
                ':tx_id'     => $transaksiId,
                ':tanggal'   => $timestamp ?: date('Y-m-d H:i:s'),
                ':total'     => $total,
                ':kasir_id'  => $kasirId,
                ':metode'    => $metode,
            ]);

            // Poin member
            if ($memberId > 0 && $total > 0) {
                $poin = (int) floor($total / 1000);
                if ($poin > 0) {
                    $poinStmt = $pdo->prepare(
                        'UPDATE member SET poin = poin + :poin WHERE id = :id'
                    );
                    $poinStmt->execute([':poin' => $poin, ':id' => $memberId]);

                    $pdo->prepare('UPDATE transaksi SET poin_diberikan = :poin WHERE id = :id')
                        ->execute([':poin' => $poin, ':id' => $transaksiId]);
                }
            }

            $pdo->commit();

            // Log audit
            try {
                \App\Models\AuditLog::catat(
                    $kasirId,
                    'sync_offline',
                    'transaksi',
                    $transaksiId,
                    json_encode(['local_id' => $localId, 'total' => $total, 'metode' => $metode])
                );
            } catch (\Throwable $e) {
                // Audit log gagal tidak membatalkan transaksi
            }

            $hasil = ['sukses' => true, 'id' => $transaksiId, 'pesan' => 'Transaksi offline berhasil disinkronkan.'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $hasil = ['sukses' => false, 'pesan' => 'Gagal sinkronisasi: ' . $e->getMessage()];
        }
        break;

    case 'sync.status':
        // Status sinkronisasi: jumlah transaksi offline yang belum ter-sync.
        $hasil = [
            'sukses' => true,
            'online' => true,
            'server_time' => date('Y-m-d H:i:s'),
        ];
        break;

    case 'produk.cari_cepat':
        $keyword = trim((string) ($params['q'] ?? ''));
        if ($keyword === '') {
            $hasil = ['sukses' => true, 'produk' => []];
            break;
        }
        $pdo = \App\Database\Database::connect();
        $adminId = currentAdminId();
        $stmt = $pdo->prepare(
            "SELECT p.id, p.nama, p.barcode, p.harga, p.harga_per_gram, p.satuan, p.stok, p.gambar, k.nama AS kategori_nama
               FROM produk p
          LEFT JOIN kategori k ON k.id = p.kategori_id
              WHERE p.is_active = 1 AND p.admin_id = :admin_id
                AND (p.nama LIKE :q OR p.barcode LIKE :qb)
              ORDER BY p.stok > 0 DESC, p.nama ASC
              LIMIT 8"
        );
        $qLike = '%' . $keyword . '%';
        $stmt->execute([':q' => $qLike, ':qb' => $qLike, ':admin_id' => $adminId]);
        $items = $stmt->fetchAll();
        $hasil = ['sukses' => true, 'produk' => $items];
        break;

    case 'gateway.status':
        $orderId = $params['order_id'] ?? '';
        if ($orderId === '') {
            $hasil = ['status' => 'error', 'message' => 'order_id required'];
            break;
        }
        // First check local DB
        $paymentData = \App\Models\PembayaranGateway::findByGatewayOrderId($orderId);
        if ($paymentData !== null && ($paymentData['gateway_status'] ?? '') === 'paid') {
            $hasil = ['status' => 'paid'];
            break;
        }
        // If not found/paid locally, check directly with Midtrans API
        $gw = new \App\Payment\MidtransGateway();
        $gwResult = $gw->verifyTransaction($orderId);
        if ($gwResult['success'] && $gwResult['status'] === 'paid') {
            $hasil = ['status' => 'paid'];
        } else {
            $hasil = ['status' => $gwResult['status'] ?? 'pending'];
        }
        break;

    case 'gateway.create':
        $orderId = $params['order_id'] ?? '';
        $amount = (float) ($params['amount'] ?? 0);
        $method = $params['payment_method'] ?? 'qris';
        $items = json_decode($params['items'] ?? '[]', true);
        $gatewayDriver = $params['gateway'] ?? 'midtrans';

        error_log('GATEWAY.CREATE: oid=' . $orderId . ' amt=' . $amount . ' method=' . $method . ' items=' . ($params['items'] ?? 'null') . ' gw=' . $gatewayDriver);

        if ($orderId === '' || $amount <= 0) {
            $hasil = ['success' => false, 'error' => 'Invalid order data'];
            break;
        }

        $gw = new \App\Payment\MidtransGateway();

        $payload = [
            'order_id' => $orderId,
            'amount' => $amount,
            'customer_name' => $_SESSION['nama'] ?? 'Customer',
            'items' => $items,
            'payment_method' => $method,
            'callback_url' => (getenv('APP_URL') ?: 'http://localhost') . '/payment_status.php?order_id=' . $orderId,
        ];
        error_log('GATEWAY.PAYLOAD: ' . json_encode($payload));
        $result = $gw->createTransaction($payload);
        error_log('GATEWAY.RESULT: success=' . ($result['success'] ? '1' : '0') . ' error=' . ($result['error'] ?? 'none') . ' qr=' . ($result['qr_code'] ?? 'null'));

        // Store gateway transaction in local DB for tracking
        if ($result['success']) {
            try {
                \App\Models\PembayaranGateway::simpanData([
                    'gateway'        => 'midtrans',
                    'gateway_order_id' => $orderId,
                    'jumlah'         => $amount,
                    'metode'         => $method,
                    'gateway_status' => 'pending',
                    'response_json'  => json_encode($result),
                ]);
            } catch (\Throwable $e) {
                // non-fatal: log but continue
                error_log('gateway.create: failed to save payment record: ' . $e->getMessage());
            }
        }

        $hasil = $result;
        // Debug: log gateway response
        error_log('GATEWAY_CREATE: order=' . $orderId . ' success=' . ($result['success'] ? '1' : '0') . ' qr=' . ($result['qr_code'] ?? 'null') . ' error=' . ($result['error'] ?? 'none'));
        break;

    case 'backup.run':
        if ($_SESSION['role'] !== 'admin') {
            $hasil = ['success' => false, 'error' => 'Hanya admin yang bisa melakukan backup'];
            break;
        }
        $backup = new \App\Database\Backup();
        $hasil = $backup->run();
        break;

    case 'backup.list':
        if ($_SESSION['role'] !== 'admin') {
            $hasil = ['success' => false, 'error' => 'Hanya admin'];
            break;
        }
        $backup = new \App\Database\Backup();
        $hasil = ['success' => true, 'backups' => $backup->list()];
        break;

    case 'backup.delete':
        if ($_SESSION['role'] !== 'admin') {
            $hasil = ['success' => false, 'error' => 'Hanya admin'];
            break;
        }
        $filename = $params['file'] ?? '';
        $backup = new \App\Database\Backup();
        $hasil = ['success' => $backup->delete($filename)];
        break;

    case 'gudang.tabel':
        // DataTables server-side: daftar gudang.
        $gudangList = \App\Models\Gudang::semua(false);
        $gudangRows = [];
        foreach ($gudangList as $g) {
            $gudangRows[] = [
                'id'       => (int) $g->getId(),
                'nama'     => $g->getNama(),
                'alamat'   => $g->getAlamat(),
                'is_utama' => $g->isUtama(),
                'is_aktif' => $g->isAktif(),
            ];
        }
        $hasil = [
            'total'    => count($gudangRows),
            'filtered' => count($gudangRows),
            'rows'     => $gudangRows,
        ];
        break;

    case 'gudang.stok':
        // Stok produk di gudang tertentu.
        $gudangId = (int) ($params['gudang_id'] ?? 0);
        $cari = (string) ($params['search'] ?? '');
        $hasil = \App\Models\Gudang::daftarProduk($gudangId, $cari);
        break;

    case 'gudang.stok_produk':
        // Stok satu produk di satu gudang.
        $gudangId2 = (int) ($params['gudang_id'] ?? 0);
        $produkId = (int) ($params['produk_id'] ?? 0);
        $hasil = [
            'stok' => \App\Models\Gudang::stokProduk($gudangId2, $produkId),
        ];
        break;

    case 'gudang.riwayat':
        $hasil = \App\Models\Gudang::riwayatTransfer(50);
        break;

    case 'gudang.detail':
        $transferId = (int) ($params['transfer_id'] ?? 0);
        $hasil = \App\Models\Gudang::detailTransfer($transferId);
        break;

    // ---- Analitik ----
    case 'dashboard.jam_ramai':
        $hasil = \App\Models\Dashboard::jamRamai(
            (string) ($params['tanggal_mulai'] ?? date('Y-m-01')),
            (string) ($params['tanggal_akhir'] ?? date('Y-m-d'))
        );
        break;

    case 'dashboard.hari_tersibuk':
        $hasil = \App\Models\Analitik::hariTersibuk(
            (string) ($params['tanggal_mulai'] ?? date('Y-m-01')),
            (string) ($params['tanggal_akhir'] ?? date('Y-m-d'))
        );
        break;

    case 'dashboard.penjualan_per_kasir':
        $hasil = \App\Models\Dashboard::penjualanPerKasir(
            (string) ($params['tanggal_mulai'] ?? date('Y-m-01')),
            (string) ($params['tanggal_akhir'] ?? date('Y-m-d'))
        );
        break;

    case 'dashboard.metode_pembayaran':
        $hasil = \App\Models\Analitik::metodePembayaran(
            (string) ($params['tanggal_mulai'] ?? date('Y-m-01')),
            (string) ($params['tanggal_akhir'] ?? date('Y-m-d'))
        );
        break;

    case 'analitik.tren_bulanan':
        $hasil = \App\Models\Analitik::trenBulanan(
            (int) ($params['tahun'] ?? (int) date('Y'))
        );
        break;

    case 'analitik.tren_tahunan':
        $hasil = \App\Models\Analitik::trenTahunan();
        break;

    // ---- Label Printing ----
    case 'label.cetak':
        // Generate label untuk satu atau banyak produk.
        $produkIdLabel = (int) ($params['produk_id'] ?? 0);
        $jumlahLabel = max(1, (int) ($params['jumlah'] ?? 1));

        if ($produkIdLabel > 0) {
            $produkLabel = \App\Models\Produk::cari($produkIdLabel);
            if ($produkLabel !== null) {
                $labelText = \App\Models\LabelPrinter::generateLabel($produkLabel, $jumlahLabel);
                $hasil = ['sukses' => true, 'label' => $labelText, 'nama' => $produkLabel->getNama()];
            } else {
                $hasil = ['sukses' => false, 'pesan' => 'Produk tidak ditemukan.'];
            }
        } else {
            $hasil = ['sukses' => false, 'pesan' => 'produk_id wajib.'];
        }
        break;

    case 'label.batch':
        // Generate batch labels (HTML preview).
        $batchItems = json_decode((string) ($params['items'] ?? '[]'), true) ?: [];
        $labels = \App\Models\LabelPrinter::generateBatchLabels($batchItems);
        $hasil = ['sukses' => true, 'labels' => $labels, 'html' => \App\Models\LabelPrinter::generateHtmlLabels($labels)];
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'aksi tidak dikenal']);
        exit;
}

// Aksi DataTables membungkus ke format server-side DataTables.
if (in_array($aksi, ['produk.tabel', 'supplier.tabel', 'pembelian.tabel', 'member.tabel', 'dashboard.terlaris', 'dashboard.transaksi', 'laporan.tabel', 'laba.tabel', 'retur.tabel', 'shift.tabel', 'audit.tabel'], true)) {
    echo json_encode([
        'draw'            => (int) ($params['draw'] ?? 0),
        'recordsTotal'    => $hasil['total'],
        'recordsFiltered' => $hasil['filtered'],
        'data'            => $hasil['rows'],
    ]);
    exit;
}

echo json_encode($hasil);
