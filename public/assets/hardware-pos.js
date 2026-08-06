// Hardware Integration (Web Serial): timbangan + printer thermal ESC/POS.
// Dimuat SETELAH hardware.js (POSHardware) dan pos.js (window.afterBayarSukses).
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
