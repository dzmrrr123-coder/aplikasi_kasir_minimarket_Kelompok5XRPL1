// Transaksi tanpa reload (Ultra-Fast POS Engine):
// Audio Feedback, Quick Cash Presets, Qty Steppers, Smart Autofocus,
// Live Category Filtering, Keyboard Shortcuts (F1-F9, ESC).
(function () {
    'use strict';

    var flash = document.getElementById('flash-pesan');
    var fragKiri = document.getElementById('fragmen-keranjang-kiri');
    var fragKanan = document.getElementById('fragmen-keranjang-kanan');

    /* ============================================================
       1. WEB AUDIO API SYNTHESIZER (0-Dependency Audio Feedback)
       ============================================================ */
    var POSAudio = (function () {
        var audioCtx = null;
        var isMuted = localStorage.getItem('pos_sound_muted') === 'true';

        function getContext() {
            if (!audioCtx) {
                var AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (AudioContextClass) audioCtx = new AudioContextClass();
            }
            if (audioCtx && audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            return audioCtx;
        }

        function playTone(freq, type, duration, delay) {
            if (isMuted) return;
            try {
                var ctx = getContext();
                if (!ctx) return;
                setTimeout(function () {
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.type = type || 'sine';
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.12, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + duration);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(ctx.currentTime);
                    osc.stop(ctx.currentTime + duration);
                }, (delay || 0) * 1000);
            } catch (e) {}
        }

        return {
            beep: function () {
                // Scanner pleasant high beep (880Hz, 70ms)
                playTone(880, 'sine', 0.07);
            },
            chime: function () {
                // Success payment melodic chime (C5 -> E5 -> G5)
                playTone(523.25, 'triangle', 0.12, 0);
                playTone(659.25, 'triangle', 0.12, 0.08);
                playTone(783.99, 'triangle', 0.22, 0.16);
            },
            error: function () {
                // Warning low tone (220Hz, 120ms x 2)
                playTone(220, 'sawtooth', 0.12, 0);
                playTone(180, 'sawtooth', 0.14, 0.12);
            },
            toggleMute: function () {
                isMuted = !isMuted;
                localStorage.setItem('pos_sound_muted', isMuted ? 'true' : 'false');
                updateSoundIcon();
                return isMuted;
            },
            isMuted: function () {
                return isMuted;
            }
        };
    })();

    function updateSoundIcon() {
        var icon = document.getElementById('icon-sound-pos');
        if (!icon) return;
        if (POSAudio.isMuted()) {
            icon.className = 'bi bi-volume-mute-fill text-danger';
        } else {
            icon.className = 'bi bi-volume-up-fill';
        }
    }

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
        setTimeout(function () {
            area.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 50);
    }

    /* ============================================================
       2. LIVE CALCULATION & GRAND KEMBALIAN DISPLAY
       ============================================================ */
    function initKembalian() {
        var totalEl = document.getElementById('total-json');
        var input = document.getElementById('jumlah-dibayar');
        var kembalianEl = document.getElementById('kembalian');
        var changeCard = document.getElementById('kiosk-change-card');
        var changeStatusLabel = document.getElementById('change-status-label');
        var changeBadge = document.getElementById('change-badge');
        var changeVal = document.getElementById('kiosk-change-val');
        var tombol = document.querySelector('#form-bayar button[type="submit"]');
        if (!input) return;

        var total = totalEl ? parseFloat(totalEl.textContent) || 0 : 0;
        var bayar = parseFloat(input.value) || 0;
        var selisih = bayar - total;
        var isKurang = selisih < 0;

        // Update small summary text
        if (kembalianEl) {
            if (isKurang) {
                kembalianEl.textContent = 'Kurang ' + rupiah(Math.abs(selisih));
                kembalianEl.className = 'kiosk-sub font-num text-danger';
            } else {
                kembalianEl.textContent = 'Kembalian ' + rupiah(selisih);
                kembalianEl.className = 'kiosk-sub font-num text-success';
            }
        }

        // Update grand change card
        if (changeCard) {
            changeCard.classList.toggle('is-kurang', isKurang);
            changeCard.classList.toggle('is-cukup', !isKurang);
        }
        if (changeStatusLabel) {
            changeStatusLabel.textContent = isKurang ? 'Uang Kurang' : 'Kembalian Pelanggan';
        }
        if (changeBadge) {
            changeBadge.textContent = isKurang ? 'Belum Cukup' : 'Siap Selesai';
            changeBadge.className = 'badge text-bg-' + (isKurang ? 'danger' : 'success') + ' small';
        }
        if (changeVal) {
            changeVal.textContent = isKurang ? 'Kurang ' + rupiah(Math.abs(selisih)) : rupiah(selisih);
        }

        // Button status
        if (tombol) {
            if (isKurang) {
                tombol.disabled = true;
            } else {
                if (!tombol.dataset.kosong) tombol.disabled = false;
            }
        }
    }

    // Global live input listener for payment amount
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'jumlah-dibayar') {
            initKembalian();
        }
    });

    /* ============================================================
       3. QUICK CASH PRESETS & NUMPAD (Global Delegated Events)
       ============================================================ */
    document.addEventListener('click', function (e) {
        // Quick Float Chips (Buka Kas Modal Awal)
        var floatChip = e.target.closest('.btn-float-chip');
        if (floatChip) {
            e.preventDefault();
            var inputModal = document.getElementById('modal-awal');
            if (inputModal) {
                inputModal.value = floatChip.getAttribute('data-float') || '0';
                inputModal.focus();
                POSAudio.beep();
            }
            return;
        }

        // Quick Cash Chips (Pecahan Uang Otomatis)
        var chip = e.target.closest('.btn-cash-chip');
        if (chip) {
            e.preventDefault();
            var input = document.getElementById('jumlah-dibayar');
            var totalEl = document.getElementById('total-json');
            if (!input) return;

            var nominal = chip.getAttribute('data-cash');
            var total = totalEl ? parseFloat(totalEl.textContent) || 0 : 0;

            if (nominal === 'exact') {
                input.value = String(Math.ceil(total));
            } else {
                input.value = String(nominal);
            }

            input.dispatchEvent(new Event('input', { bubbles: true }));
            initKembalian();
            POSAudio.beep();
            return;
        }

        // Numpad Calculator Buttons
        var numBtn = e.target.closest('button[data-num]');
        if (numBtn) {
            e.preventDefault();
            var inputNum = document.getElementById('jumlah-dibayar');
            if (!inputNum) return;

            var aksi = numBtn.getAttribute('data-num');
            if (aksi === 'hapus') {
                inputNum.value = inputNum.value.slice(0, -1);
            } else if (aksi === 'bersih') {
                inputNum.value = '';
            } else if (aksi === 'maks') {
                var totalEl2 = document.getElementById('total-json');
                var total2 = totalEl2 ? parseFloat(totalEl2.textContent) || 0 : 0;
                inputNum.value = String(Math.ceil(total2));
            } else {
                inputNum.value = (inputNum.value || '') + aksi;
            }

            inputNum.dispatchEvent(new Event('input', { bubbles: true }));
            initKembalian();
            POSAudio.beep();
            return;
        }
    });

    /* ============================================================
       4. CATEGORY FILTER PILLS (Instant Client Filtering)
       ============================================================ */
    function initCategoryPills() {
        document.addEventListener('click', function (e) {
            var pill = e.target.closest('.cat-pill');
            if (!pill) return;

            var targetCat = pill.getAttribute('data-cat');
            var allPills = document.querySelectorAll('.cat-pill');
            allPills.forEach(function (p) { p.classList.remove('active'); });
            pill.classList.add('active');

            var cols = document.querySelectorAll('.kiosk-produk-col');
            cols.forEach(function (col) {
                var itemCat = col.getAttribute('data-kategori') || '';
                if (targetCat === 'all' || itemCat.indexOf(targetCat) !== -1) {
                    col.style.display = '';
                } else {
                    col.style.display = 'none';
                }
            });
            POSAudio.beep();
        });
    }

    /* ============================================================
       5. CART STEPPER (+ / -) QUICK QUANTITY MODIFIER
       ============================================================ */
    function kirimAksiUbahQty(produkId, delta) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        var data = new FormData();
        data.set('aksi', 'ubah_qty');
        data.set('produk_id', String(produkId));
        data.set('delta', String(delta));
        data.set('csrf', token);

        fetch('transaksi.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: data
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                tampilkanFlash(res.pesan, res.tipe);
                if (res.tipe === 'success') {
                    POSAudio.beep();
                } else {
                    POSAudio.error();
                }
                if (res.fragment) gantiFragment(res.fragment);
                fokusBarcode();
            })
            .catch(function (err) {
                tampilkanFlash('Gagal mengubah jumlah: ' + err.message, 'danger');
                POSAudio.error();
            });
    }

    document.addEventListener('click', function (e) {
        var plusBtn = e.target.closest('.btn-step-plus');
        if (plusBtn) {
            e.preventDefault();
            var pidPlus = plusBtn.getAttribute('data-produk-id');
            if (pidPlus) kirimAksiUbahQty(pidPlus, 1);
            return;
        }

        var minusBtn = e.target.closest('.btn-step-minus');
        if (minusBtn) {
            e.preventDefault();
            var pidMinus = minusBtn.getAttribute('data-produk-id');
            if (pidMinus) kirimAksiUbahQty(pidMinus, -1);
            return;
        }
    });

    /* ============================================================
       6. FRAGMENT REPLACEMENT & SYNC
       ============================================================ */
    function gantiFragment(fragment) {
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
            fragKanan.innerHTML = tmp.querySelector('.kiosk-userbar').parentElement
                ? tmp.querySelector('.kiosk-userbar').parentElement.innerHTML
                : '';
            syncMetodePembayaran();
            updateSoundIcon();
            if (kioskKanan) kioskKanan.classList.remove('d-none');
        } else {
            fragKanan.innerHTML = '';
            if (kioskKanan) kioskKanan.classList.add('d-none');
        }

        initKembalian();
        fokusBarcode();
    }

    function metodeAktif() {
        var el = document.querySelector('input[name="metode"]:checked');
        return el ? el.value : 'tunai';
    }

    function syncMetodePembayaran() {
        var tunai = metodeAktif() === 'tunai';
        var numpad = document.getElementById('kiosk-numpad');
        var groupBayar = document.getElementById('jumlah-bayar-group');
        var infoNonTunai = document.getElementById('info-non-tunai');
        var changeCard = document.getElementById('kiosk-change-card');
        if (numpad) numpad.classList.toggle('d-none', !tunai);
        if (groupBayar) groupBayar.classList.toggle('d-none', !tunai);
        if (changeCard) changeCard.classList.toggle('d-none', !tunai);
        if (infoNonTunai) infoNonTunai.classList.toggle('d-none', tunai);
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'metode') {
            syncMetodePembayaran();
            POSAudio.beep();
        }
    });

    /* ============================================================
       7. KEYBOARD SHORTCUTS (F1-F9, ESC)
       ============================================================ */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var areaStruk = document.getElementById('area-struk');
            if (areaStruk && !areaStruk.classList.contains('d-none')) {
                var tutup = document.getElementById('tutup-struk');
                if (tutup) { tutup.click(); } else { areaStruk.classList.add('d-none'); }
                fokusBarcode();
                return;
            }
        } else if (e.key === 'F1') {
            e.preventDefault();
            var cari = document.querySelector('input[name="cari"]');
            if (cari) { cari.focus(); cari.select(); }
        } else if (e.key === 'F2') {
            e.preventDefault();
            var barcode = document.getElementById('input-barcode');
            if (barcode) { barcode.focus(); barcode.select(); }
        } else if (e.key === 'F4') {
            e.preventDefault();
            var memberInput = document.getElementById('input-telepon-member');
            if (memberInput) { memberInput.focus(); memberInput.select(); }
        } else if (e.key === 'F7') {
            e.preventDefault();
            var formTahan = document.getElementById('form-tahan-keranjang');
            if (formTahan) {
                var btnTahan = formTahan.querySelector('button[type="submit"]');
                if (btnTahan && !btnTahan.disabled) {
                    formTahan.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            }
        } else if (e.key === 'F8') {
            e.preventDefault();
            var chipPas = document.querySelector('.btn-cash-chip.chip-exact');
            if (chipPas) chipPas.click();
        } else if (e.key === 'F9') {
            e.preventDefault();
            var formBayarBtn = document.querySelector('#form-bayar button[type="submit"]');
            if (formBayarBtn && !formBayarBtn.disabled) formBayarBtn.click();
        } else if (e.key === '?' && !['input', 'textarea'].includes((e.target.tagName || '').toLowerCase())) {
            e.preventDefault();
            var modalShortcuts = document.getElementById('modal-shortcuts');
            if (modalShortcuts && window.bootstrap) {
                var bsShortcuts = bootstrap.Modal.getOrCreateInstance(modalShortcuts);
                bsShortcuts.toggle();
            }
        }
    });

    /* ============================================================
       8. SMART AUTOFOCUS GUARD
       ============================================================ */
    function fokusBarcode() {
        var bukaKas = document.getElementById('card-buka-kas');
        if (bukaKas && !bukaKas.classList.contains('d-none')) return;

        var areaStruk = document.getElementById('area-struk');
        if (areaStruk && !areaStruk.classList.contains('d-none')) return;

        var activeModal = document.querySelector('.modal.show');
        if (activeModal) return;

        var barcode = document.getElementById('input-barcode');
        if (barcode) barcode.focus();
    }

    var aksiDisableTombol = ['bayar', 'batalkan', 'buka_kas', 'tutup_kas', 'set_member', 'hapus_member', 'void_item'];

    function kunciInputPOS(aktif) {
        var tombolBayar = document.querySelector('#form-bayar button[type="submit"]');
        var barcode = document.getElementById('input-barcode');
        var tombolScan = document.querySelector('#form-scan button[type="submit"]');
        var groupBayar = document.getElementById('jumlah-bayar-group');

        var elems = [];
        if (barcode) elems.push(barcode);
        if (tombolScan) elems.push(tombolScan);
        if (groupBayar) elems.push(groupBayar);
        elems.forEach(function (el) {
            if (aktif) {
                el.setAttribute('aria-busy', 'true');
                el.style.pointerEvents = 'none';
                el.style.opacity = '0.55';
            } else {
                el.removeAttribute('aria-busy');
                el.style.pointerEvents = '';
                el.style.opacity = '';
            }
        });

        if (tombolBayar) {
            tombolBayar.disabled = aktif;
            if (aktif) {
                tombolBayar.classList.add('is-loading', 'btn-processing');
                tombolBayar.setAttribute('data-text-asli', tombolBayar.innerHTML);
                tombolBayar.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Memproses…';
            } else {
                tombolBayar.classList.remove('is-loading', 'btn-processing');
                var asli = tombolBayar.getAttribute('data-text-asli');
                if (asli) tombolBayar.innerHTML = asli;
            }
        }
    }

    function tombolSubmit(form) {
        return form ? form.querySelector('button[type="submit"]') : null;
    }

    /* ============================================================
       9. FORM SUBMISSIONS VIA AJAX
       ============================================================ */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var aksi = form.getAttribute('data-aksi');
        if (!aksi) return;

        e.preventDefault();
        var data = new FormData(form);
        data.set('aksi', aksi);
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) data.set('csrf', meta.getAttribute('content'));

        var tombol = aksiDisableTombol.indexOf(aksi) >= 0 ? tombolSubmit(form) : null;
        if (tombol) {
            tombol.disabled = true;
            tombol.classList.add('is-loading');
        }

        if (aksi === 'bayar') {
            kunciInputPOS(true);
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
                if (aksi === 'bayar') {
                    kunciInputPOS(false);
                }
                if (tombol) tombol.classList.remove('is-loading');
                tampilkanFlash(res.pesan, res.tipe);

                // Audio feedback triggers
                if (res.tipe === 'success') {
                    if (aksi === 'bayar') {
                        POSAudio.chime();
                    } else {
                        POSAudio.beep();
                    }
                } else if (res.tipe === 'danger' || res.tipe === 'warning') {
                    POSAudio.error();
                }

                if (aksi === 'scan') {
                    var barcode = document.getElementById('input-barcode');
                    if (barcode) {
                        barcode.value = '';
                        barcode.focus();
                    }
                }

                if (aksi === 'buka_kas') {
                    if (res.tipe === 'success') {
                        var cardBukaKas = document.getElementById('card-buka-kas');
                        if (cardBukaKas) cardBukaKas.classList.add('d-none');
                    } else {
                        var modalAwal = document.getElementById('modal-awal');
                        if (modalAwal) modalAwal.focus();
                    }
                }

                if (aksi === 'tutup_kas') {
                    var modalTutup = document.getElementById('modal-tutup-kas');
                    if (modalTutup && window.bootstrap) {
                        var bs = bootstrap.Modal.getInstance(modalTutup);
                        if (bs) bs.hide();
                    }
                    if (res.tipe === 'success') {
                        tampilkanFlash(res.pesan + ' Kas kembali terkunci.', 'success');
                        var cardBukaKas2 = document.getElementById('card-buka-kas');
                        if (cardBukaKas2) cardBukaKas2.classList.remove('d-none');
                        var kioskKanan = document.querySelector('.kiosk-kanan');
                        if (kioskKanan) kioskKanan.classList.add('d-none');
                    }
                }

                if (aksi === 'void_item') {
                    var mVoid = document.getElementById('modal-void');
                    if (mVoid && window.bootstrap) {
                        var bsVoid = bootstrap.Modal.getInstance(mVoid);
                        if (res.tipe === 'success') {
                            // Berhasil: tutup modal, reset input
                            if (bsVoid) bsVoid.hide();
                            var voidId = document.getElementById('void-produk-id');
                            if (voidId) voidId.value = '';
                        } else {
                            // Gagal (PIN salah / item tidak ada): re-enable tombol, reset PIN
                            var voidPin = document.getElementById('void-pin');
                            if (voidPin) { voidPin.value = ''; setTimeout(function () { voidPin.focus(); }, 100); }
                        }
                    }
                }

                if (res.tipe === 'danger' || res.tipe === 'warning') {
                    if (tombol) tombol.disabled = false;
                }

                if (res.struk) {
                    tampilkanStruk(res.struk);
                    if (window.afterBayarSukses) window.afterBayarSukses(res);
                }
                if (res.fragment) {
                    gantiFragment(res.fragment);
                } else if (res.hapus_struk) {
                    var area = document.getElementById('area-struk');
                    if (area) area.classList.add('d-none');
                }

                if (res.produk) {
                    var fragProduk = document.getElementById('fragmen-produk');
                    if (fragProduk) fragProduk.outerHTML = res.produk;
                }

                if (tombol && tombol.disabled) {
                    tombol.disabled = false;
                }
            })
            .catch(function (err) {
                if (aksi === 'bayar') {
                    kunciInputPOS(false);
                }
                if (tombol) tombol.classList.remove('is-loading');
                tampilkanFlash('Terjadi kesalahan: ' + err.message, 'danger');
                POSAudio.error();
                if (tombol) tombol.disabled = false;
            });
    });

    // Sound toggle button click
    document.addEventListener('click', function (e) {
        var soundBtn = e.target.closest('#btn-toggle-sound');
        if (soundBtn) {
            e.preventDefault();
            var muted = POSAudio.toggleMute();
            tampilkanFlash(muted ? 'Suara POS dimatikan (Muted)' : 'Suara POS diaktifkan', 'info');
        }
    });

    // Tutup struk via AJAX
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
                        fokusBarcode();
                    }
                });
        });
    }

    // Cetak struk
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

    // Transaksi Baru button
    var btnBaru = document.getElementById('transaksi-baru');
    if (btnBaru) {
        btnBaru.addEventListener('click', function () {
            if (!confirm('Batalkan transaksi ini dan mulai yang baru?')) return;
            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';
            var data = new FormData();
            data.set('aksi', 'batalkan');
            data.set('csrf', token);
            fetch('transaksi.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: data
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.pesan) tampilkanFlash(res.pesan, res.tipe || 'info');
                    var area = document.getElementById('area-struk');
                    if (area) area.classList.add('d-none');
                    if (res.fragment) gantiFragment(res.fragment);
                    fokusBarcode();
                })
                .catch(function (err) {
                    tampilkanFlash('Gagal memulai transaksi baru: ' + err.message, 'danger');
                });
        });
    }

    /* ============================================================
       10. INITIALIZATION CALLS
       ============================================================ */
    initKembalian();
    initCategoryPills();
    syncMetodePembayaran();
    updateSoundIcon();
    fokusBarcode();

    // Modal void init — gunakan event.relatedTarget (Bootstrap 5 built-in)
    // agar produk_id terbaca dari tombol pemicu SEBELUM modal muncul, tanpa race condition.
    var modalVoid = document.getElementById('modal-void');
    if (modalVoid) {
        modalVoid.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget; // tombol Void yang diklik
            var idInput = document.getElementById('void-produk-id');
            var nama    = document.getElementById('void-produk-nama');
            var pin     = document.getElementById('void-pin');

            if (btn) {
                var produkId   = btn.getAttribute('data-produk-id')   || '';
                var produkNama = btn.getAttribute('data-produk-nama')  || '';
                if (idInput) idInput.value = produkId;
                if (nama)    nama.innerHTML = 'Hapus item: <strong>' + produkNama + '</strong>';
            }

            // Reset PIN setiap modal dibuka
            if (pin) { pin.value = ''; setTimeout(function () { pin.focus(); }, 300); }
        });

        modalVoid.addEventListener('hidden.bs.modal', function () {
            // Reset semua input void saat modal ditutup
            var voidId   = document.getElementById('void-produk-id');
            var voidNama = document.getElementById('void-produk-nama');
            var voidPin  = document.getElementById('void-pin');
            if (voidId)   voidId.value    = '';
            if (voidNama) voidNama.innerHTML = '';
            if (voidPin)  voidPin.value   = '';
            fokusBarcode();
        });
    }

    // Interactive Denomination Calculator for Tutup Kas
    function updateDiffStatus() {
        var kasFisikInput = document.getElementById('kas-fisik');
        var diffBox = document.getElementById('diff-status-box');
        var diffText = document.getElementById('diff-status-text');
        var diffVal = document.getElementById('diff-status-val');
        if (!kasFisikInput || !diffBox) return;

        var modalTutup = document.getElementById('modal-tutup-kas');
        var totalSistem = modalTutup ? parseFloat(modalTutup.dataset.totalSistem) || 0 : 0;
        var fisik = parseFloat(kasFisikInput.value) || 0;

        if (kasFisikInput.value === '') {
            diffBox.classList.add('d-none');
            return;
        }

        diffBox.classList.remove('d-none');
        var selisih = fisik - totalSistem;

        diffBox.classList.remove('is-pas', 'is-lebih', 'is-kurang');
        if (Math.abs(selisih) < 0.01) {
            diffBox.classList.add('is-pas');
            if (diffText) diffText.textContent = 'Selisih Kas: Pas (Sesuai Sistem)';
            if (diffVal) diffVal.textContent = rupiah(0);
        } else if (selisih > 0) {
            diffBox.classList.add('is-lebih');
            if (diffText) diffText.textContent = 'Selisih Kas: Lebih (Surplus)';
            if (diffVal) diffVal.textContent = '+' + rupiah(selisih);
        } else {
            diffBox.classList.add('is-kurang');
            if (diffText) diffText.textContent = 'Selisih Kas: Kurang (Defisit)';
            if (diffVal) diffVal.textContent = '-' + rupiah(Math.abs(selisih));
        }
    }

    function initDenominationCalc() {
        var tbody = document.getElementById('denom-tbody');
        var kasFisikInput = document.getElementById('kas-fisik');
        var grandTotalEl = document.getElementById('denom-grand-total');
        if (!tbody || !kasFisikInput) return;

        function hitungDenominasi() {
            var rows = tbody.querySelectorAll('tr[data-val]');
            var total = 0;

            rows.forEach(function (row) {
                var val = parseFloat(row.getAttribute('data-val')) || 0;
                var input = row.querySelector('.denom-input');
                var subEl = row.querySelector('.denom-subtotal');
                var qty = parseFloat(input ? input.value : 0) || 0;
                var sub = val === 1 ? qty : (val * qty);
                total += sub;
                if (subEl) subEl.textContent = rupiah(sub);
            });

            if (grandTotalEl) grandTotalEl.textContent = rupiah(total);
            if (total > 0 || tbody.dataset.userEdited === 'true') {
                kasFisikInput.value = total;
                updateDiffStatus();
            }
        }

        tbody.addEventListener('input', function (e) {
            if (e.target && e.target.classList.contains('denom-input')) {
                tbody.dataset.userEdited = 'true';
                hitungDenominasi();
            }
        });

        kasFisikInput.addEventListener('input', updateDiffStatus);
    }

    // Modal tutup kas init
    function muatRingkasanTutupKas() {
        var wadah = document.getElementById('tutup-kas-ringkasan');
        var dibuka = document.getElementById('tutup-kas-dibuka');
        var hint = document.getElementById('tutup-kas-hint');
        var modalTutup = document.getElementById('modal-tutup-kas');
        if (!wadah) return;

        wadah.innerHTML = '<div class="text-muted small py-2 d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-teal"></span>Memuat riwayat shift...</div>';

        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        fetch('api.php?aksi=shift.ringkasan&csrf=' + encodeURIComponent(token))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ada) {
                    wadah.innerHTML = '<div class="alert alert-warning py-2 small">Tidak ada shift aktif. Buka kas dulu.</div>';
                    return;
                }

                if (modalTutup) modalTutup.dataset.totalSistem = String(d.uang_seharusnya || 0);
                if (dibuka) dibuka.textContent = d.dibuka_pada ? new Date(d.dibuka_pada.replace(' ', 'T')).toLocaleString('id-ID') : '-';
                if (hint) hint.textContent = 'Total uang seharusnya di laci: ' + rupiah(d.uang_seharusnya) + '.';

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
                    '<div class="fw-bold font-num text-success">' + rupiah(d.total_tunai || 0) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Non-tunai</div>' +
                    '<div class="fw-bold font-num text-primary">' + rupiah(d.total_nontunai || 0) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="border rounded p-2 text-center bg-warning-subtle"><div class="small text-muted">Target di laci</div>' +
                    '<div class="fw-bold font-num text-warning-emphasis">' + rupiah(d.uang_seharusnya) + '</div></div></div></div>';

                isi += '<div class="mb-3"><div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<span class="small fw-semibold">Riwayat transaksi shift ini</span>' +
                    '<span class="badge text-bg-light">' + (d.riwayat || []).length + ' transaksi</span></div>';

                if ((d.riwayat || []).length === 0) {
                    isi += '<div class="text-muted small border rounded p-3 text-center">Belum ada transaksi di shift ini.</div>';
                } else {
                    isi += '<div class="table-responsive border rounded" style="max-height: 180px; overflow-y: auto;">' +
                        '<table class="table table-sm align-middle mb-0"><thead class="table-light">' +
                        '<tr><th>Waktu</th><th class="text-end">Total</th><th class="text-center">Metode</th></tr></thead>' +
                        '<tbody>' + baris + '</tbody>' +
                        '<tfoot class="table-light"><tr><td class="fw-semibold">Total</td>' +
                        '<td class="text-end fw-semibold font-num">' + rupiah(d.total_penjualan) + '</td><td></td></tr></tfoot>' +
                        '</table></div>';
                }
                isi += '</div>';

                wadah.innerHTML = isi;
                updateDiffStatus();
            })
            .catch(function () {
                wadah.innerHTML = '<div class="alert alert-danger py-2 small">Gagal memuat riwayat shift.</div>';
            });
    }

    var modalTutupKas = document.getElementById('modal-tutup-kas');
    if (modalTutupKas) {
        modalTutupKas.addEventListener('show.bs.modal', function () {
            muatRingkasanTutupKas();
            var kasFisikInput = document.getElementById('kas-fisik');
            if (kasFisikInput) {
                kasFisikInput.value = '';
                setTimeout(function () { kasFisikInput.focus(); }, 300);
            }
        });
        modalTutupKas.addEventListener('hidden.bs.modal', function () {
            var trigger = document.querySelector('[data-bs-target="#modal-tutup-kas"]');
            if (trigger) trigger.focus();
        });
    }

    initDenominationCalc();

    // Toggle Layar Penuh
    function toggleFullScreen() {
        if (!document.fullscreenElement) {
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(function () {});
            }
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen().catch(function () {});
            }
        }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('#btn-sidebar-fullscreen') || e.target.closest('#btn-header-fullscreen')) {
            e.preventDefault();
            toggleFullScreen();
            POSAudio.beep();
        }
    });
})();
