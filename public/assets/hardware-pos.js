// Hardware Integration (Web Serial): timbangan + printer thermal ESC/POS.
// Dimuat SETELAH hardware.js (POSHardware) dan pos.js (window.afterBayarSukses).
//
// Device *pairing* (label printer/timbangan) kini disimpan per-kasir di server
// (api.php?aksi=device.*). UI pairing ada di modal #modalPerangkat (buka lewat
// ikon Perangkat di userbar POS) — bukan card di kolom kanan, agar UI kasir
// minimalis & penuh space ruang kosong.
//
// Alur:
//   1. fetch device.list -> tahu device mana yang terdaftar kasir + isi label.
//   2. Badge: "Belum" (belum pilih) / "Siap" (terdaftar, belum konek) /
//      "Terhubung" (Web Serial terbuka). Badge userbar ikon printer juga update.
//   3. Klik tombol Hubungkan (ikon plug) -> POSHardware.hubungkan* (requestPort,
//      butuh interaksi user sekali per sesi browser).
//   4. Klik Lepas -> api device.remove. Ketik label -> debounce simpan ke API.

(function () {
    'use strict';

    var registered = { timbangan: false, printer: false };

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function setBadge(elem, connected, hasDevice) {
        if (!elem) return;
        if (connected) {
            elem.className = 'badge ms-1 text-bg-success';
            elem.textContent = 'Terhubung';
        } else if (hasDevice) {
            elem.className = 'badge ms-1 text-bg-info';
            elem.textContent = 'Siap';
        } else {
            elem.className = 'badge ms-1 text-bg-secondary';
            elem.textContent = 'Belum';
        }
    }

    function showFlash(msg, tipe) {
        var fl = document.getElementById('flash-pesan');
        if (fl) {
            fl.className = 'alert alert-' + (tipe || 'danger') + ' alert-dismissible fade show';
            fl.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            fl.classList.remove('d-none');
        }
    }

    function refreshBadges() {
        var st = window.POSHardware ? POSHardware.getStatus() : { timbangan: false, printer: false };
        setBadge(document.getElementById('status-timbangan'), st.timbangan, registered.timbangan);
        setBadge(document.getElementById('status-printer'), st.printer, registered.printer);

        // Badge/dot status printer kecil di userbar (kotak warna).
        var up = document.getElementById('badge-printer-pos');
        if (up) {
            if (st.printer) {
                up.className = 'badge bg-success';          // hijau: terhubung
            } else if (registered.printer) {
                up.className = 'badge bg-info text-bg-info'; // abu-biru: terdaftar
            } else {
                up.className = 'badge bg-secondary';         // abu: belum pasang
            }
            up.textContent = ''; // dot kosong (ukuran lewat CSS inline)
        }
    }

    // Ambil device pairing kasir dari server, isi label, refresh badge.
    function loadRegistered() {
        fetch('api.php?aksi=device.list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                registered.timbangan = !!d.timbangan;
                registered.printer   = !!d.printer;

                for (var i = 0; i < ['printer', 'timbangan'].length; i++) {
                    var t = ['printer', 'timbangan'][i];
                    var inp = document.getElementById('label-' + t);
                    var lepas = document.getElementById('btn-lepas-' + t);
                    if (inp && d[t]) { inp.value = d[t].label || ''; }
                    if (lepas) { lepas.classList.toggle('d-none', !d[t]); }
                }
                refreshBadges();
            })
            .catch(function () { refreshBadges(); });
    }

    // Simpan label device (debounce) via API.
    function saveLabel(tipe) {
        var inp = document.getElementById('label-' + tipe);
        var label = inp ? inp.value.trim() : '';
        if (!label) return;
        fetch('api.php?aksi=device.set', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf=' + encodeURIComponent(getCsrf()) +
                  '&tipe=' + tipe +
                  '&label=' + encodeURIComponent(label)
        }).then(function (r) { return r.json(); })
         .then(function (res) {
            if (res && res.status === 'ok') {
                refreshBadges();
            } else if (res && res.error) {
                showFlash('Gagal simpan device: ' + res.error, 'danger');
            }
        });
    }

    // Lepas device (API) + reset label.
    function removeDevice(tipe) {
        fetch('api.php?aksi=device.remove', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf=' + encodeURIComponent(getCsrf()) +
                  '&tipe=' + tipe
        }).then(function (r) { return r.json(); })
         .then(function () {
            var inp = document.getElementById('label-' + tipe);
            if (inp) inp.value = '';
            var lepas = document.getElementById('btn-lepas-' + tipe);
            if (lepas) lepas.classList.add('d-none');
            registered[tipe] = false;
            refreshBadges();
        });
    }

    // Hubungkan via Web Serial (requestPort butuh interaksi user).
    function hubungkan(tipe) {
        if (!window.POSHardware) return;
        var p = tipe === 'printer'
            ? POSHardware.hubungkanPrinter()
            : POSHardware.hubungkanTimbangan();
        p.then(function () { refreshBadges(); })
         .catch(function (e) { showFlash('Gagal hubungkan ' + tipe + ': ' + e.message, 'danger'); });
    }

    function initHardware() {
        if (!window.POSHardware || !POSHardware.didukung()) return;

        var debounce;
        ['printer', 'timbangan'].forEach(function (tipe) {
            var inp = document.getElementById('label-' + tipe);
            if (inp) {
                inp.addEventListener('input', function () {
                    if (debounce) clearTimeout(debounce);
                    debounce = setTimeout(function () { saveLabel(tipe); }, 800);
                });
            }
            var lepas = document.getElementById('btn-lepas-' + tipe);
            if (lepas) lepas.addEventListener('click', function () { removeDevice(tipe); });

            var btn = document.getElementById('btn-' + tipe);
            if (btn) btn.addEventListener('click', function () { hubungkan(tipe); });
        });

        // --- Sidebar (offcanvas) → modal: tutup sidebar dulu, lalu buka modal ---
        function openModal(id) {
            var el = document.getElementById(id);
            if (el && window.bootstrap) { return new bootstrap.Modal(el).show(); }
        }
        function closeSidebarThen(id) {
            var oc = window.bootstrap && bootstrap.Offcanvas.getInstance(document.getElementById('sidebarKasir'));
            if (oc) { oc.hide(); setTimeout(function () { openModal(id); }, 250); }
            else { openModal(id); }
        }
        document.querySelectorAll('[data-bs-target="#modalPerangkat"]').forEach(function (el) {
            el.addEventListener('click', function (e) { e.preventDefault(); closeSidebarThen('modalPerangkat'); });
        });
        document.querySelectorAll('[data-bs-target="#modal-tutup-kas"]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                // Tutup Kas hanya bila memang ada shift aktif (link sudah disembunyikan bila belum).
                var oc = window.bootstrap && bootstrap.Offcanvas.getInstance(document.getElementById('sidebarKasir'));
                if (oc) { oc.hide(); setTimeout(function () { openModal('modal-tutup-kas'); }, 250); }
                else { openModal('modal-tutup-kas'); }
            });
        });

        POSHardware.muatConfig().then(function () {
            loadRegistered();
            // Update badge tiap kali koneksi device berubah.
            POSHardware.setOnStatus(function () { refreshBadges(); });
            refreshBadges();

            // --- Timbangan: baca berat -> isi input gram produk curah ---
            POSHardware.setOnBerat(function (gram) {
                var aktif = document.activeElement;
                var input = null;
                if (aktif && aktif.hasAttribute('data-produk-gram')) {
                    input = aktif;
                } else {
                    input = document.querySelector('input[data-produk-gram]');
                }
                if (input) {
                    input.value = gram.toFixed(3);
                }
            });

            // --- Tombol timbang per produk (baca berat terakhir -> submit) ---
            document.addEventListener('click', function (e) {
                var tombol = e.target.closest('[data-timbang]');
                if (!tombol) return;
                var berat = POSHardware.getBeratGram();
                if (berat <= 0) {
                    showFlash('Belum ada pembacaan berat dari timbangan.', 'warning');
                    return;
                }
                var produkId = tombol.getAttribute('data-timbang');
                var input = document.querySelector('input[data-produk-gram="' + produkId + '"]');
                if (input) {
                    input.value = berat.toFixed(3);
                    var form = input.closest('form[data-aksi="tambah_item"]');
                    if (form) form.requestSubmit();
                }
            });
        }).catch(function () { /* config gagal -> pakai default */ });
    }

    // Cetak struk otomatis setelah bayar sukses bila printer sudah terhubung.
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

    // Cetak struk otomatis lewat window.tampilkanStrukAfterBayar juga.
    var origStruk = window.tampilkanStrukAfterBayar || null;
    window.tampilkanStrukAfterBayar = function (struk) {
        if (origStruk) origStruk(struk);
        var st = window.POSHardware ? POSHardware.getStatus() : { printer: false };
        if (st.printer) {
            POSHardware.cetakStruk(struk).catch(function () { /* biarkan */ });
        }
    };

    if (window.POSHardware) {
        try { initHardware(); } catch (e) { /* jangan ganggu alur kasir */ }
    }
})();
