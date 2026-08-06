// Kamera scan barcode: pakai BarcodeDetector native (Chrome/Edge),
// fallback ZXing untuk browser lain. Begitu barcode terbaca, otomatis
// isi field & submit (sama seperti scan manual).
// Dimuat SETELAH ZXing (unpkg) di halaman transaksi.
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
