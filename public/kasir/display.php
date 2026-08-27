<?php

declare(strict_types=1);

/**
 * Customer Facing Display (CFD) / Layar Pelanggan.
 *
 * Halaman ini dapat dibuka di monitor sekunder atau tablet yang menghadap ke pelanggan.
 * Menggunakan HTML5 BroadcastChannel API & localStorage fallback untuk sinkronisasi realtime
 * nol-latensi dengan jendela kasir POS utama.
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Models\Pengaturan;

$namaToko = Pengaturan::get('nama_toko', 'Minimarket Plaza');
$alamatToko = Pengaturan::get('alamat_toko', 'Jl. Jenderal Sudirman No. 45');
$teleponToko = Pengaturan::get('telepon_toko', '0812-3456-7890');
$baseUrl = SessionGuard::baseUrl();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layar Pelanggan — <?= htmlspecialchars($namaToko) ?></title>
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --cfd-bg: #090d16;
            --cfd-surface: #131a29;
            --cfd-card: #1c263b;
            --cfd-border: rgba(255, 255, 255, 0.08);
            --cfd-teal: #0d9488;
            --cfd-teal-glow: rgba(13, 148, 136, 0.35);
            --cfd-accent: #38bdf8;
            --cfd-text: #f8fafc;
            --cfd-muted: #94a3b8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--cfd-bg);
            color: var(--cfd-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            margin: 0;
            user-select: none;
        }

        .font-num {
            font-family: 'JetBrains Mono', monospace;
            font-feature-settings: "tnum";
        }

        /* Top Header */
        .cfd-header {
            background: linear-gradient(135deg, #0f172a 0%, #134e4a 100%);
            border-bottom: 1px solid var(--cfd-border);
            padding: 16px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
        }

        .cfd-logo-badge {
            width: 44px;
            height: 44px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #fff;
        }

        .cfd-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(13, 148, 136, 0.2);
            color: #2dd4bf;
            border: 1px solid rgba(45, 212, 191, 0.3);
            border-radius: 9999px;
            padding: 6px 14px;
            font-size: 0.82rem;
            font-weight: 600;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #2dd4bf;
            border-radius: 50%;
            animation: pulse-animation 2s infinite;
        }

        @keyframes pulse-animation {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(45, 212, 191, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(45, 212, 191, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(45, 212, 191, 0); }
        }

        /* Main Screen Container */
        .cfd-body {
            flex: 1;
            padding: 24px 28px;
            display: flex;
            flex-direction: column;
        }

        /* Layout Grid */
        .cfd-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 24px;
            flex: 1;
        }

        @media (max-width: 992px) {
            .cfd-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cart Box */
        .cfd-card {
            background: var(--cfd-surface);
            border: 1px solid var(--cfd-border);
            border-radius: 20px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .cfd-table-wrapper {
            flex: 1;
            overflow-y: auto;
            max-height: calc(100vh - 280px);
            margin-top: 12px;
        }

        .cfd-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .cfd-table th {
            color: var(--cfd-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 8px 16px;
            border: none;
        }

        .cfd-table tr.item-row {
            background: var(--cfd-card);
            transition: transform 0.2s ease, background-color 0.2s ease;
        }

        .cfd-table tr.item-row td {
            padding: 14px 16px;
            border: none;
        }

        .cfd-table tr.item-row td:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .cfd-table tr.item-row td:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .item-badge-qty {
            background: rgba(13, 148, 136, 0.25);
            color: #2dd4bf;
            font-weight: 700;
            border-radius: 8px;
            padding: 4px 10px;
            display: inline-block;
        }

        /* Right Summary Panel */
        .cfd-summary-panel {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .cfd-total-banner {
            background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%);
            border-radius: 20px;
            padding: 28px;
            color: #fff;
            text-align: right;
            box-shadow: 0 12px 35px var(--cfd-teal-glow);
            position: relative;
            overflow: hidden;
        }

        .cfd-total-banner::after {
            content: '';
            position: absolute;
            top: -30%;
            left: -20%;
            width: 140%;
            height: 140%;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 60%);
            pointer-events: none;
        }

        .cfd-total-label {
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            opacity: 0.9;
            margin-bottom: 4px;
        }

        .cfd-total-amount {
            font-size: 2.8rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -1px;
        }

        .cfd-breakdown {
            background: var(--cfd-card);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .cfd-breakdown-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.95rem;
            color: var(--cfd-muted);
        }

        .cfd-breakdown-row.highlight {
            color: var(--cfd-text);
            font-weight: 600;
        }

        /* QRIS Box */
        .cfd-qris-card {
            background: #fff;
            color: #0f172a;
            border-radius: 20px;
            padding: 22px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            animation: fadeInScale 0.3s ease;
        }

        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        /* Idle State Screen */
        .cfd-idle-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex: 1;
            padding: 40px;
        }

        .cfd-hero-badge {
            font-size: 64px;
            color: #2dd4bf;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 20px var(--cfd-teal-glow));
        }

        .payment-logos {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }

        .payment-pill {
            background: var(--cfd-card);
            border: 1px solid var(--cfd-border);
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--cfd-muted);
        }

        /* Member Pill */
        .cfd-member-badge {
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: #38bdf8;
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Footer Marquee */
        .cfd-footer {
            background: #0b111e;
            border-top: 1px solid var(--cfd-border);
            padding: 12px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--cfd-muted);
        }
    </style>
</head>
<body>

    <!-- CFD Header -->
    <header class="cfd-header">
        <div class="d-flex align-items-center gap-3">
            <div class="cfd-logo-badge">
                <i class="bi bi-shop"></i>
            </div>
            <div>
                <h1 class="h5 fw-bold mb-0 text-white"><?= htmlspecialchars($namaToko) ?></h1>
                <span class="small text-muted" style="color: #94a3b8;"><?= htmlspecialchars($alamatToko) ?></span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="cfd-status-pill">
                <span class="pulse-dot"></span>
                <span>Kasir Aktif</span>
            </div>
            <div class="font-num fw-bold text-white fs-6" id="live-clock">--:--:--</div>
        </div>
    </header>

    <!-- CFD Main Body -->
    <main class="cfd-body">

        <!-- STATE 1: IDLE / WELCOME (Saat belum ada transaksi aktif) -->
        <div id="cfd-state-idle" class="cfd-idle-container">
            <div class="cfd-hero-badge">
                <i class="bi bi-bag-check-fill"></i>
            </div>
            <h2 class="display-6 fw-bold mb-2">Selamat Datang di <?= htmlspecialchars($namaToko) ?></h2>
            <p class="text-muted fs-5 mb-4" style="max-width: 600px;">
                Belanja hemat, lengkap, dan nyaman. Kami siap melayani transaksi Anda dengan senang hati.
            </p>
            <div class="payment-logos">
                <span class="payment-pill"><i class="bi bi-cash me-1 text-success"></i>Tunai</span>
                <span class="payment-pill"><i class="bi bi-qr-code me-1 text-danger"></i>QRIS Nasional</span>
                <span class="payment-pill"><i class="bi bi-credit-card me-1 text-primary"></i>Debit / EDC</span>
                <span class="payment-pill"><i class="bi bi-phone me-1 text-info"></i>GoPay / OVO / ShopeePay</span>
                <span class="payment-pill"><i class="bi bi-star-fill me-1 text-warning"></i>Member Points</span>
            </div>
        </div>

        <!-- STATE 2: ACTIVE TRANSACTION (Saat kasir melakukan scan produk) -->
        <div id="cfd-state-active" class="cfd-grid d-none">
            <!-- Kolom Kiri: Tabel Barang Belanja -->
            <div class="cfd-card">
                <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-secondary border-opacity-25">
                    <div>
                        <h2 class="h6 fw-bold mb-0 text-uppercase tracking-wider text-teal"><i class="bi bi-cart3 me-2"></i>Daftar Belanja Anda</h2>
                    </div>
                    <span class="badge bg-secondary font-num" id="cfd-item-count">0 Item</span>
                </div>

                <div class="cfd-table-wrapper">
                    <table class="cfd-table">
                        <thead>
                            <tr>
                                <th>Item Produk</th>
                                <th class="text-center" style="width: 100px;">Qty</th>
                                <th class="text-end" style="width: 140px;">Harga</th>
                                <th class="text-end" style="width: 150px;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="cfd-items-tbody">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Kolom Kanan: Ringkasan Total, Member & QRIS -->
            <div class="cfd-summary-panel">
                <!-- Total Amount Banner -->
                <div class="cfd-total-banner">
                    <div class="cfd-total-label">Total Pembayaran</div>
                    <div class="cfd-total-amount font-num" id="cfd-grand-total">Rp 0</div>
                </div>

                <!-- Member Card (jika aktif) -->
                <div id="cfd-member-box" class="cfd-member-badge d-none">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-person-circle fs-4"></i>
                        <div>
                            <div class="fw-bold" id="cfd-member-nama">Pelanggan Member</div>
                            <div class="small opacity-75 font-num" id="cfd-member-telepon">0812-xxxx</div>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge text-bg-warning font-num" id="cfd-member-poin">0 Poin</span>
                    </div>
                </div>

                <!-- Breakdown Table -->
                <div class="cfd-breakdown">
                    <div class="cfd-breakdown-row">
                        <span>Subtotal Belanja</span>
                        <span class="font-num" id="cfd-subtotal">Rp 0</span>
                    </div>
                    <div class="cfd-breakdown-row text-success d-none" id="cfd-row-diskon">
                        <span>Hemat Diskon</span>
                        <span class="font-num" id="cfd-diskon">-Rp 0</span>
                    </div>
                    <div class="cfd-breakdown-row d-none" id="cfd-row-pajak">
                        <span>PPN (11%)</span>
                        <span class="font-num" id="cfd-pajak">Rp 0</span>
                    </div>
                    <div class="cfd-breakdown-row highlight pt-2 border-top border-secondary border-opacity-25" id="cfd-row-bayar-kembalian">
                        <span id="cfd-pay-status-label">Uang Diterima</span>
                        <span class="font-num fw-bold text-success" id="cfd-kembalian">Rp 0</span>
                    </div>
                </div>

                <!-- QRIS Dynamic Display (Muncul saat mode QRIS aktif) -->
                <div id="cfd-qris-box" class="cfd-qris-card d-none">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="badge text-bg-danger px-3 py-1 fw-bold">QRIS</span>
                        <span class="small fw-semibold text-muted">Scan untuk Bayar</span>
                    </div>
                    <img id="cfd-qris-img" src="" alt="QRIS QR Code" class="img-fluid rounded border mb-2" style="width: 170px; height: 170px;">
                    <div class="small text-muted font-num fw-bold" id="cfd-qris-amount">Rp 0</div>
                </div>
            </div>
        </div>
    </main>

    <!-- CFD Footer -->
    <footer class="cfd-footer">
        <div>
            <i class="bi bi-info-circle me-1"></i>Periksa kembali belanjaan & kembalian Anda sebelum meninggalkan kasir.
        </div>
        <div>
            <span class="fw-semibold">Layanan Pelanggan:</span> <?= htmlspecialchars($teleponToko) ?>
        </div>
    </footer>

    <script>
    (function () {
        'use strict';

        // Live Clock
        function updateClock() {
            var clockEl = document.getElementById('live-clock');
            if (clockEl) {
                var now = new Date();
                clockEl.textContent = now.toLocaleTimeString('id-ID');
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        function rupiah(n) {
            return 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID');
        }

        // Render data from POS Broadcast
        function renderCFD(data) {
            var stateIdle = document.getElementById('cfd-state-idle');
            var stateActive = document.getElementById('cfd-state-active');
            if (!data || !data.items || data.items.length === 0) {
                stateIdle.classList.remove('d-none');
                stateActive.classList.add('d-none');
                return;
            }

            stateIdle.classList.add('d-none');
            stateActive.classList.remove('d-none');

            // Render Items
            var tbody = document.getElementById('cfd-items-tbody');
            var countEl = document.getElementById('cfd-item-count');
            countEl.textContent = data.items.length + ' Jenis Produk';

            var rowsHtml = '';
            data.items.forEach(function (it) {
                var qtyStr = it.satuan === 'gram'
                    ? (parseFloat(it.qty) || 0).toLocaleString('id-ID') + ' gr'
                    : (parseInt(it.qty) || 1) + ' pcs';

                rowsHtml += '<tr class="item-row">' +
                    '<td><div class="fw-bold text-white fs-6">' + it.nama + '</div><div class="small text-muted font-num">' + rupiah(it.harga) + ' / ' + (it.satuan || 'pcs') + '</div></td>' +
                    '<td class="text-center"><span class="item-badge-qty font-num">' + qtyStr + '</span></td>' +
                    '<td class="text-end font-num text-muted">' + rupiah(it.harga) + '</td>' +
                    '<td class="text-end font-num fw-bold text-white fs-6">' + rupiah(it.subtotal) + '</td>' +
                    '</tr>';
            });
            tbody.innerHTML = rowsHtml;

            // Render Totals
            document.getElementById('cfd-grand-total').textContent = rupiah(data.total || 0);
            document.getElementById('cfd-subtotal').textContent = rupiah(data.subtotal || 0);

            // Diskon
            var rowDiskon = document.getElementById('cfd-row-diskon');
            if (data.potongan && data.potongan > 0) {
                rowDiskon.classList.remove('d-none');
                document.getElementById('cfd-diskon').textContent = '-' + rupiah(data.potongan);
            } else {
                rowDiskon.classList.add('d-none');
            }

            // Pajak
            var rowPajak = document.getElementById('cfd-row-pajak');
            if (data.pajak && data.pajak > 0) {
                rowPajak.classList.remove('d-none');
                document.getElementById('cfd-pajak').textContent = rupiah(data.pajak);
            } else {
                rowPajak.classList.add('d-none');
            }

            // Kembalian / Bayar
            var statusLabel = document.getElementById('cfd-pay-status-label');
            var kembalianEl = document.getElementById('cfd-kembalian');
            if (data.kembalian !== undefined) {
                if (data.kembalian >= 0) {
                    statusLabel.textContent = 'Kembalian Anda';
                    kembalianEl.textContent = rupiah(data.kembalian);
                    kembalianEl.className = 'font-num fw-bold text-success';
                } else {
                    statusLabel.textContent = 'Kekurangan Pembayaran';
                    kembalianEl.textContent = '-' + rupiah(Math.abs(data.kembalian));
                    kembalianEl.className = 'font-num fw-bold text-danger';
                }
            }

            // Member
            var memberBox = document.getElementById('cfd-member-box');
            if (data.member) {
                memberBox.classList.remove('d-none');
                document.getElementById('cfd-member-nama').textContent = data.member.nama || 'Member';
                document.getElementById('cfd-member-telepon').textContent = data.member.telepon || '';
                document.getElementById('cfd-member-poin').textContent = (data.member.poin || 0) + ' Poin';
            } else {
                memberBox.classList.add('d-none');
            }

            // QRIS Display
            var qrisBox = document.getElementById('cfd-qris-box');
            if (data.metode === 'non_tunai' && data.total > 0) {
                qrisBox.classList.remove('d-none');
                var totalStr = rupiah(data.total);
                document.getElementById('cfd-qris-amount').textContent = 'Total Tagihan: ' + totalStr;
                var qrisUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent('00020101021126580014ID.LINKAJA.WWW01189360091100212345675204541153033605802ID5916MINIMARKET PLAZA6007JAKARTA6304ABCD' + String(data.total));
                document.getElementById('cfd-qris-img').src = qrisUrl;
            } else {
                qrisBox.classList.add('d-none');
            }
        }

        // 1. BroadcastChannel Communication (Primary)
        if ('BroadcastChannel' in window) {
            var channel = new BroadcastChannel('pos_cfd_channel');
            channel.onmessage = function (event) {
                if (event && event.data) {
                    renderCFD(event.data);
                }
            };
        }

        // 2. Storage Event Listener (Fallback)
        window.addEventListener('storage', function (e) {
            if (e.key === 'pos_cfd_sync_data' && e.newValue) {
                try {
                    var parsed = JSON.parse(e.newValue);
                    renderCFD(parsed);
                } catch (err) {}
            }
        });

        // Initial check from localStorage
        var initial = localStorage.getItem('pos_cfd_sync_data');
        if (initial) {
            try {
                renderCFD(JSON.parse(initial));
            } catch (e) {}
        }
    })();
    </script>
</body>
</html>
