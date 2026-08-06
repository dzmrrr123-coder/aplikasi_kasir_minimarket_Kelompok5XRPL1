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
            syncNumpadMetode();
        }

        // Panel kanan sempat disembunyikan (d-none) saat wajib buka kas.
        // Begitu ada interaksi yang menghasilkan fragment (buka kas,
        // tambah item, dll.), tampilkan kembali.
        var kioskKanan = document.querySelector('.kiosk-kanan');
        if (kioskKanan) kioskKanan.classList.remove('d-none');

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

    // Numpad (kalkulator) hanya muncul saat kasir memilih metode
    // pembayaran Tunai — panel kanan tetap lega saat awal / non-tunai.
    var numpadTampil = false; // default: sembunyi biar panel lega
    function syncNumpadMetode() {
        var numpad = document.getElementById('kiosk-numpad');
        if (!numpad) return;
        numpad.classList.toggle('d-none', !numpadTampil);
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'metode') {
            // Muncul saat Tunai dipilih; sembunyi saat Non-tunai.
            numpadTampil = e.target.value === 'tunai';
            syncNumpadMetode();
        }
    });

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

    // Aksi yang tombolnya di-disable selama request AJAX berjalan (cegah
    // double-tap di layar sentuh). Aksi 'scan' & tambah_item TIDAK di-disable
    // karena kasir butuh kecepatan scan beruntun.
    var aksiDisableTombol = ['bayar', 'batalkan', 'buka_kas', 'tutup_kas', 'set_member', 'hapus_member'];

    function tombolSubmit(form) {
        return form ? form.querySelector('button[type="submit"]') : null;
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

        // Disable tombol submit SEBELUM fetch supaya tidak bisa dobel-tap.
        var tombol = aksiDisableTombol.indexOf(aksi) >= 0 ? tombolSubmit(form) : null;
        if (tombol) tombol.disabled = true;

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

                if (aksi === 'buka_kas') {
                    // Kas sudah terbuka — sembunyikan card "Buka Kas".
                    var cardBukaKas = document.getElementById('card-buka-kas');
                    if (cardBukaKas) cardBukaKas.classList.add('d-none');
                }

                // Respons error (validasi/guard server) — aktifkan lagi tombol
                // supaya kasir bisa perbaiki input & coba lagi.
                if (res.tipe === 'danger' || res.tipe === 'warning') {
                    if (tombol) tombol.disabled = false;
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

                // Setelah fragment diganti, elemen tombol lama kemungkinan besar
                // ikut ke-replace oleh render server. Kalau ternyata nyangkut
                // (masih disabled), aktifkan kembali supaya tidak macet.
                if (tombol && tombol.disabled) {
                    tombol.disabled = false;
                }
            })
            .catch(function (err) {
                tampilkanFlash('Terjadi kesalahan: ' + err.message, 'danger');
                // Request gagal — aktifkan kembali tombol.
                if (tombol) tombol.disabled = false;
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

    // Cetak struk: hanya area struk yang tercetak (lihat @media print).
    var cetakStruk = document.getElementById('cetak-struk');
    if (cetakStruk) {
        cetakStruk.addEventListener('click', function () {
            var area = document.getElementById('area-struk');
            if (!area) return;
            window.print();
        });
    }

    initKembalian();
    initNumpad();
    syncNumpadMetode();
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
