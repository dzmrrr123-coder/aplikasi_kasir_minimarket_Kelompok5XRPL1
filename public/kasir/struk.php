<?php

declare(strict_types=1);

/**
 * Halaman cetak struk transaksi (siap cetak / thermal receipt view).
 *
 * Mengambil data transaksi dari DB berdasarkan ?id=..., memvalidasi hak akses
 * (kasir hanya bisa melihat transaksi miliknya, admin bisa melihat semua),
 * dan menampilkan struk dengan format bersih untuk dicetak (Ctrl+P / window.print()).
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Database\Database;
use App\Models\ItemTransaksi;
use App\Models\Pengaturan;
use App\Models\Transaksi;

SessionGuard::requireLogin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . SessionGuard::baseUrl() . '/kasir/riwayat.php');
    exit;
}

$transaksi = Transaksi::cari($id);
if ($transaksi === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="id"><head><title>Transaksi Tidak Ditemukan</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Transaksi #' . htmlspecialchars((string)$id) . ' tidak ditemukan.</h2><a href="' . SessionGuard::baseUrl() . '/kasir/riwayat.php">Kembali ke Riwayat</a></body></html>';
    exit;
}

// Cek hak akses: kasir hanya boleh melihat miliknya sendiri
$roleSesi   = (string) ($_SESSION['role'] ?? '');
$userIdSesi = (int) ($_SESSION['user_id'] ?? 0);
if ($roleSesi === 'kasir' && $transaksi->getKasirId() !== $userIdSesi) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="id"><head><title>Akses Ditolak</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Akses ditolak: Anda hanya dapat melihat struk transaksi milik Anda sendiri.</h2><a href="' . SessionGuard::baseUrl() . '/kasir/riwayat.php">Kembali ke Riwayat</a></body></html>';
    exit;
}

// Ambil item transaksi
$items = ItemTransaksi::untukTransaksi($id);

// Ambil info pembayaran & diskon dari database
$pdo = Database::connect();
$stmtTx = $pdo->prepare(
    'SELECT t.*, p.metode, p.jumlah_bayar, p.kembalian, p.nomor_kartu, d.kode AS diskon_kode, d.nilai AS diskon_nilai, d.tipe AS diskon_tipe
       FROM transaksi t
  LEFT JOIN pembayaran p ON p.id = t.pembayaran_id
  LEFT JOIN diskon d ON d.id = t.diskon_id
      WHERE t.id = :id LIMIT 1'
);
$stmtTx->execute([':id' => $id]);
$txData = $stmtTx->fetch() ?: [];

$metode        = (string) ($txData['metode'] ?? 'Tunai');
$jumlahBayar   = (float) ($txData['jumlah_bayar'] ?? $transaksi->getTotal());
$kembalian     = (float) ($txData['kembalian'] ?? 0.0);
$nomorKartu    = (string) ($txData['nomor_kartu'] ?? '');
$diskonKode    = (string) ($txData['diskon_kode'] ?? '');
$diskonNilai   = (float) ($txData['diskon_nilai'] ?? 0.0);
$diskonTipe    = (string) ($txData['diskon_tipe'] ?? '');

$pengaturan    = Pengaturan::semua();
$namaToko      = $pengaturan['nama_toko'] ?? 'KASIR MINIMARKET';
$alamatToko    = $pengaturan['alamat'] ?? '';
$teleponToko   = $pengaturan['telepon'] ?? '';
$footerStruk   = $pengaturan['footer_struk'] ?? 'Terima kasih atas kunjungan Anda!';
$baseUrl       = SessionGuard::baseUrl();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Transaksi #<?= htmlspecialchars((string)$id) ?> — <?= htmlspecialchars($namaToko) ?></title>
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Courier New', Courier, monospace, 'Consolas', sans-serif;
            background-color: #f4f6f9;
            color: #111;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px;
            min-height: 100vh;
        }
        .action-bar {
            width: 100%;
            max-width: 380px;
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .btn {
            flex: 1;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: none;
            transition: opacity 0.15s;
        }
        .btn-print {
            background-color: #0d9488;
            color: #fff;
        }
        .btn-back {
            background-color: #e2e8f0;
            color: #334155;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .receipt-card {
            width: 100%;
            max-width: 380px;
            background: #fff;
            padding: 24px 20px;
            border-radius: 4px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
            font-size: 13px;
            line-height: 1.45;
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 12px;
        }
        .store-name {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .store-sub {
            font-size: 11px;
            color: #555;
        }
        .divider {
            border-top: 1px dashed #777;
            margin: 10px 0;
        }
        .receipt-meta {
            font-size: 12px;
            margin-bottom: 10px;
        }
        .receipt-meta .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        .item-list {
            margin-bottom: 10px;
        }
        .item-row {
            margin-bottom: 6px;
        }
        .item-name {
            font-weight: bold;
        }
        .item-sub {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #333;
        }
        .totals-section {
            margin-top: 6px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 12px;
        }
        .total-row.grand-total {
            font-size: 14px;
            font-weight: bold;
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px dashed #777;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 14px;
            font-size: 11px;
            color: #555;
        }

        /* Mode Cetak Thermal / Print CSS */
        @media print {
            body {
                background: #fff;
                padding: 0;
                margin: 0;
            }
            .action-bar {
                display: none !important;
            }
            .receipt-card {
                max-width: 100%;
                width: 100%;
                box-shadow: none;
                padding: 0;
                border-radius: 0;
            }
            @page {
                margin: 4mm;
            }
        }
    </style>
</head>
<body>

    <div class="action-bar">
        <a href="<?= $baseUrl ?>/transaksi.php" class="btn btn-back">
            <i class="bi bi-arrow-left"></i> POS
        </a>
        <a href="<?= $baseUrl ?>/kasir/riwayat.php" class="btn btn-back">
            <i class="bi bi-clock-history"></i> Riwayat
        </a>
        <button onclick="window.print()" class="btn btn-print">
            <i class="bi bi-printer"></i> Cetak Struk
        </button>
    </div>

    <div class="receipt-card" id="receiptCard">
        <div class="receipt-header">
            <div class="store-name"><?= htmlspecialchars($namaToko) ?></div>
            <?php if ($alamatToko !== ''): ?>
                <div class="store-sub"><?= htmlspecialchars($alamatToko) ?></div>
            <?php endif; ?>
            <?php if ($teleponToko !== ''): ?>
                <div class="store-sub">Telp: <?= htmlspecialchars($teleponToko) ?></div>
            <?php endif; ?>
        </div>

        <div class="divider"></div>

        <div class="receipt-meta">
            <div class="row">
                <span>No. Transaksi</span>
                <strong>#<?= htmlspecialchars((string)$id) ?></strong>
            </div>
            <div class="row">
                <span>Tanggal</span>
                <span><?= htmlspecialchars($transaksi->getTanggal()->format('d/m/Y H:i')) ?></span>
            </div>
            <div class="row">
                <span>Kasir</span>
                <span><?= htmlspecialchars($transaksi->getKasirNama()) ?></span>
            </div>
            <?php if ($transaksi->getMemberId() > 0): ?>
            <div class="row">
                <span>Member</span>
                <span><?= htmlspecialchars($transaksi->getMemberNama()) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="divider"></div>

        <div class="item-list">
            <?php 
            $subtotalKotor = 0.0;
            foreach ($items as $idx => $item): 
                $p = $item->getProduk();
                $subtotalKotor += $item->getSubtotal();
                $satuan = $p->getSatuan();
                $qtyStr = $satuan === 'gram' ? number_format($item->getQty(), 0) . 'g' : (string)(int)$item->getQty();
                $hargaUnitStr = $satuan === 'gram' ? 'Rp ' . number_format($p->getHargaPerGram(), 0, ',', '.') . '/g' : 'Rp ' . number_format($p->getHarga(), 0, ',', '.');
            ?>
                <div class="item-row">
                    <div class="item-name"><?= ($idx + 1) . '. ' . htmlspecialchars($p->getNama()) ?></div>
                    <div class="item-sub">
                        <span><?= $qtyStr ?> x <?= $hargaUnitStr ?></span>
                        <strong>Rp <?= number_format($item->getSubtotal(), 0, ',', '.') ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="divider"></div>

        <div class="totals-section">
            <div class="total-row">
                <span>Subtotal</span>
                <span>Rp <?= number_format($subtotalKotor, 0, ',', '.') ?></span>
            </div>

            <?php if ($diskonKode !== '' || $diskonNilai > 0): ?>
            <div class="total-row" style="color: #b91c1c;">
                <span>Diskon <?= $diskonKode !== '' ? '(' . htmlspecialchars($diskonKode) . ')' : '' ?></span>
                <span>-Rp <?= number_format($subtotalKotor - $transaksi->getTotal(), 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($transaksi->getPajak() > 0): ?>
            <div class="total-row">
                <span>PPN</span>
                <span>Rp <?= number_format($transaksi->getPajak(), 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <div class="total-row grand-total">
                <span>TOTAL</span>
                <span>Rp <?= number_format($transaksi->getTotal(), 0, ',', '.') ?></span>
            </div>

            <div class="divider"></div>

            <div class="total-row">
                <span>Pembayaran (<?= htmlspecialchars($metode) ?>)</span>
                <span>Rp <?= number_format($jumlahBayar, 0, ',', '.') ?></span>
            </div>

            <?php if ($kembalian > 0 || strtolower($metode) === 'tunai'): ?>
            <div class="total-row">
                <span>Kembalian</span>
                <span>Rp <?= number_format($kembalian, 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($nomorKartu !== ''): ?>
            <div class="total-row" style="font-size: 11px; color:#555;">
                <span>No. Ref / Kartu</span>
                <span><?= htmlspecialchars($nomorKartu) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="divider"></div>

        <div class="receipt-footer">
            <p><?= nl2br(htmlspecialchars($footerStruk)) ?></p>
            <p style="margin-top: 4px; font-size: 10px; color: #888;">Simpan struk ini sebagai bukti pembayaran yang sah.</p>
        </div>
    </div>

</body>
</html>
