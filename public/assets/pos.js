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
        var kioskKanan = document.querySelector('.kiosk-kanan');

        if (kanan) {
            // Panel kanan kiosk: userbar + ringkasan + numpad.
            fragKanan.innerHTML = tmp.querySelector('.kiosk-userbar').parentElement
                ? tmp.querySelector('.kiosk-userbar').parentElement.innerHTML
                : '';
            initNumpad();
            syncNumpadMetode();
            // Panel kanan sempat disembunyikan (d-none) saat wajib buka kas;
            // tampilkan kembali sekarang karena fragment punya panel kanan.
            if (kioskKanan) kioskKanan.classList.remove('d-none');
        } else {
            // Fragment tidak berisi panel kanan (shift belum/sudah tutup) —
            // kosongkan isi lama & sembunyikan kolom kanan supaya tidak ada
            // sisa panel menggantung.
            fragKanan.innerHTML = '';
            if (kioskKanan) kioskKanan.classList.add('d-none');
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
        // Bila overlay buka kas masih tampil, biarkan fokus di input modal awal.
        var bukaKas = document.getElementById('card-buka-kas');
        if (bukaKas && !bukaKas.classList.contains('d-none')) {
            return;
        }

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
        if (tombol) {
            tombol.disabled = true;
            tombol.classList.add('is-loading');
        }

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
                if (tombol) tombol.classList.remove('is-loading');
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
                    // Hanya sembunyikan overlay bila kas berhasil dibuka.
                    // Kalau gagal (mis. modal negatif / masih ada shift),
                    // overlay wajib tetap tampil supaya kasir bisa coba lagi.
                    if (res.tipe === 'success') {
                        var cardBukaKas = document.getElementById('card-buka-kas');
                        if (cardBukaKas) cardBukaKas.classList.add('d-none');
                    } else {
                        var modalAwal = document.getElementById('modal-awal');
                        if (modalAwal) modalAwal.focus();
                    }
                }

                if (aksi === 'tutup_kas') {
                    // Tutup modal + tampilkan hasil rekonsiliasi yang jelas,
                    // supaya kasir tidak bingung apakah kas berhasil ditutup.
                    var modalTutup = document.getElementById('modal-tutup-kas');
                    if (modalTutup && window.bootstrap) {
                        var bs = bootstrap.Modal.getInstance(modalTutup);
                        if (bs) bs.hide();
                    }
                    if (res.tipe === 'success') {
                        tampilkanFlash(res.pesan + ' Kas kembali terkunci.', 'success');
                        // Kembalikan tampilan ke kondisi "belum buka kas":
                        // tampilkan card Buka Kas, sembunyikan kolom kanan.
                        var cardBukaKas = document.getElementById('card-buka-kas');
                        if (cardBukaKas) cardBukaKas.classList.remove('d-none');
                        var kioskKanan = document.querySelector('.kiosk-kanan');
                        if (kioskKanan) kioskKanan.classList.add('d-none');
                    }
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

                // Grid produk di-refresh dari server (mis. setelah bayar)
                // supaya stok yang tampil selalu sesuai DB tanpa reload.
                if (res.produk) {
                    var fragProduk = document.getElementById('fragmen-produk');
                    if (fragProduk) fragProduk.outerHTML = res.produk;
                }

                // Setelah fragment diganti, elemen tombol lama kemungkinan besar
                // ikut ke-replace oleh render server. Kalau ternyata nyangkut
                // (masih disabled), aktifkan kembali supaya tidak macet.
                if (tombol && tombol.disabled) {
                    tombol.disabled = false;
                }
            })
            .catch(function (err) {
                if (tombol) tombol.classList.remove('is-loading');
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

    // Cetak struk: bila printer thermal terhubung, kirim langsung ke ESC/POS;
    // kalau tidak, fallback ke dialog print browser (lihat @media print).
    var cetakStruk = document.getElementById('cetak-struk');
    if (cetakStruk) {
        cetakStruk.addEventListener('click', function () {
            var area = document.getElementById('area-struk');
            if (!area) return;
            var pre = area.querySelector('pre');
            var teks = pre ? pre.textContent : '';

            if (teks !== '' && window.POSHardware) {
                var st = POSHardware.getStatus();
                if (st.printer) {
                    POSHardware.cetakStruk(teks).catch(function (e) {
                        tampilkanFlash('Gagal cetak ke printer: ' + e.message, 'danger');
                    });
                    return;
                }
            }

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
        // Satu sumber kebenaran: klik tombol Void mengisi id & nama sebelum
        // modal dibuka. Jangan reset di show.bs.modal karena bisa menghapus
        // nilai yang sudah benar.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-bs-target="#modal-void"]');
            if (!btn) return;
            var idInput = document.getElementById('void-produk-id');
            var nama = document.getElementById('void-produk-nama');
            if (idInput) idInput.value = btn.getAttribute('data-produk-id') || '';
            if (nama) nama.innerHTML = 'Hapus item: <strong>' + (btn.getAttribute('data-produk-nama') || '') + '</strong>';
        });

        modalVoid.addEventListener('show.bs.modal', function () {
            var pin = document.getElementById('void-pin');
            if (pin) { pin.value = ''; setTimeout(function () { pin.focus(); }, 300); }
        });

        // Kembalikan fokus ke kolom barcode setelah modal void ditutup.
        modalVoid.addEventListener('hidden.bs.modal', function () {
            var barcode = document.getElementById('input-barcode');
            if (barcode) barcode.focus();
        });
    }

    // Modal tutup kas: muat ringkasan & riwayat transaksi shift saat dibuka,
    // supaya kasir bisa mencocokkan uang di laci dengan transaksi tercatat.
    function muatRingkasanTutupKas() {
        var wadah = document.getElementById('tutup-kas-ringkasan');
        var dibuka = document.getElementById('tutup-kas-dibuka');
        var hint = document.getElementById('tutup-kas-hint');
        if (!wadah) return;

        wadah.innerHTML = '<div class="text-muted small py-2 d-flex align-items-center gap-2"><span class="spinner"></span>Memuat riwayat shift...</div>';

        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        fetch('api.php?aksi=shift.ringkasan&csrf=' + encodeURIComponent(token))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ada) {
                    wadah.innerHTML = '<div class="alert alert-warning py-2 small">Tidak ada shift aktif. Buka kas dulu.</div>';
                    return;
                }

                if (dibuka) dibuka.textContent = d.dibuka_pada ? new Date(d.dibuka_pada.replace(' ', 'T')).toLocaleString('id-ID') : '-';
                if (hint) hint.textContent = 'Cocokkan dengan uang seharusnya di laci: ' + rupiah(d.uang_seharusnya) + '.';

                var baris = (d.riwayat || []).map(function (r) {
                    var tgl = r.tanggal ? new Date(r.tanggal.replace(' ', 'T')).toLocaleString('id-ID') : '';
                    var cls = r.metode === 'Tunai' ? 'text-bg-success' : 'text-bg-secondary';
                    return '<tr><td class="small font-num">' + tgl + '</td>' +
                        '<td class="text-end font-num">' + rupiah(r.total) + '</td>' +
                        '<td class="text-center"><span class="badge ' + cls + '">' + (r.metode || '') + '</span></td></tr>';
                }).join('');

                var isi = '<div class="row g-2 mb-3">' +
                    '<div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Modal awal</div>' +
                    '<div class="fw-bold font-num">' + rupiah(d.modal_awal) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Penjualan tunai</div>' +
                    '<div class="fw-bold font-num">' + rupiah(d.total_tunai || 0) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Non-tunai (QRIS/EDC)</div>' +
                    '<div class="fw-bold font-num">' + rupiah(d.total_nontunai || 0) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Uang di laci</div>' +
                    '<div class="fw-bold font-num">' + rupiah(d.uang_seharusnya) + '</div></div></div></div>';

                isi += '<div class="mb-3"><div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<span class="small fw-semibold">Riwayat transaksi shift ini</span>' +
                    '<span class="badge text-bg-light">' + (d.riwayat || []).length + ' transaksi</span></div>';

                if ((d.riwayat || []).length === 0) {
                    isi += '<div class="text-muted small border rounded p-3 text-center">Belum ada transaksi di shift ini.</div>';
                } else {
                    isi += '<div class="table-responsive border rounded" style="max-height: 220px; overflow-y: auto;">' +
                        '<table class="table table-sm align-middle mb-0"><thead class="table-light">' +
                        '<tr><th>Waktu</th><th class="text-end">Total</th><th class="text-center">Metode</th></tr></thead>' +
                        '<tbody>' + baris + '</tbody>' +
                        '<tfoot class="table-light"><tr><td class="fw-semibold">Total</td>' +
                        '<td class="text-end fw-semibold font-num">' + rupiah(d.total_penjualan) + '</td><td></td></tr></tfoot>' +
                        '</table></div>';
                }
                isi += '</div>';

                wadah.innerHTML = isi;
            })
            .catch(function () {
                wadah.innerHTML = '<div class="alert alert-danger py-2 small">Gagal memuat riwayat shift.</div>';
            });
    }

    var modalTutupKas = document.getElementById('modal-tutup-kas');
    if (modalTutupKas) {
        modalTutupKas.addEventListener('show.bs.modal', muatRingkasanTutupKas);
        // Kembalikan fokus ke tombol "Tutup Kas" setelah modal ditutup.
        modalTutupKas.addEventListener('hidden.bs.modal', function () {
            var trigger = document.querySelector('[data-bs-target="#modal-tutup-kas"]');
            if (trigger) trigger.focus();
        });
    }
})();
