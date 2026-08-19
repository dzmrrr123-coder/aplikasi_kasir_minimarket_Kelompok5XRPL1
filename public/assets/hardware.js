/* ============================================================
   KASIR MINIMARKET — Hardware Integration (Web Serial)
   ============================================================
   - Timbangan digital: baca berat via Web Serial API (Chrome 89+),
     otomatis isi qty (gram) produk curah.
   - Printer thermal ESC/POS: kirim raw bytes via Web Serial,
     tanpa dialog print browser.

   API dipakai hanya jika 'serial' in navigator (Chrome/Edge desktop,
   HTTPS atau localhost). Kalau tidak tersedia, fallback ke input manual.
   ============================================================ */
(function () {
    'use strict';

    var state = {
        config: {
            timbangan: { baudRate: 9600, dataBits: 8, stopBits: 1, parity: 'none' },
            printer: { baudRate: 9600, dataBits: 8, stopBits: 1, parity: 'none', charset: 'utf8' }
        },
        timbangan: null,   // SerialPort
        printer: null,     // SerialPort
        beratTerakhirGram: 0,
        stabilSejak: 0,
        onBerat: null,     // callback(gram)
        onStatus: null     // callback({timbangan:bool, printer:bool})
    };

    function dukunganSerial() {
        return 'serial' in navigator;
    }

    function ambilConfig() {
        if (!dukunganSerial()) return Promise.resolve();
        return fetch('api.php?aksi=hardware.config')
            .then(function (r) { return r.json(); })
            .then(function (c) {
                if (c && c.timbangan) state.config.timbangan = Object.assign(state.config.timbangan, c.timbangan);
                if (c && c.printer) state.config.printer = Object.assign(state.config.printer, c.printer);
            })
            .catch(function () { /* pakai default */ });
    }

    function beriStatus() {
        if (state.onStatus) {
            state.onStatus({
                timbangan: !!state.timbangan,
                printer: !!state.printer
            });
        }
    }

    /* ============================================================
       TIMBANGAN DIGITAL
       ============================================================ */

    function hubungkanTimbangan() {
        if (!dukunganSerial()) {
            throw new Error('Web Serial tidak didukung browser ini.');
        }
        return navigator.serial.requestPort()
            .then(function (port) {
                var cfg = state.config.timbangan;
                return port.open({
                    baudRate: cfg.baudRate,
                    dataBits: cfg.dataBits,
                    stopBits: cfg.stopBits,
                    parity: cfg.parity
                }).then(function () {
                    state.timbangan = port;
                    bacaTimbangan(port);
                    beriStatus();
                });
            });
    }

    function putuskanTimbangan() {
        var port = state.timbangan;
        state.timbangan = null;
        beriStatus();
        if (port) {
            try { port.close(); } catch (e) { /* abaikan */ }
        }
    }

    function bacaTimbangan(port) {
        var decoder = new TextDecoderStream();
        var readableClosed = port.readable.pipeTo(decoder.writable);
        var reader = decoder.readable.getReader();
        var buffer = '';

        function proses() {
            return reader.read().then(function (hasil) {
                if (hasil.done) return;
                buffer += hasil.value;
                // Pecah per baris.
                var garis = buffer.split(/\r?\n/);
                buffer = garis.pop();
                garis.forEach(parseBarisBerat);
                return proses();
            }).catch(function () {
                // Non-fatal read error; coba lanjut.
                return proses();
            });
        }

        proses();
    }

    /**
     * Parse satu baris teks dari timbangan menjadi berat (gram).
     * Mendukung format umum: "ST,GS,+   1.235 kg", "S 1.235 kg", "1.235kg",
     * "STABLE 1235 g". Stabil hanya bila ada flag stabilitas (ST/STABLE/= atau
     * tanpa flag di awal); nilai tidak stabil diabaikan.
     */
    function parseBarisBerat(line) {
        var s = line.trim();
        if (s === '') return;

        // Abaikan baris yang jelas-jelas bukan pembacaan stabil:
        // UNST/US = belum stabil, OL = overload, ERR/E = error timbangan.
        if (/^(UNST|US\b|OL|ERR|E\b|----)/i.test(s)) return;

        // Deteksi stabil: ST/STABLE/GS/= di awal menandakan terbaca stabil.
        var stabil = /^(ST|STABLE|GS|=====|[\s=])/i.test(s);
        // Ambil angka desimal + satuan (kg/g).
        var m = s.match(/(-?\d+(?:[.,]\d+)?)\s*(kg|g|gr|gram)?/i);

        if (!m) return;

        var nilai = parseFloat(m[1].replace(',', '.'));
        var satuan = (m[2] || '').toLowerCase();

        // Konversi ke gram.
        var gram;
        if (satuan === 'kg') {
            gram = nilai * 1000;
        } else if (satuan === 'g' || satuan === 'gr' || satuan === 'gram') {
            gram = nilai;
        } else {
            // Tanpa satuan: asumsikan gram (timbangan umum).
            gram = nilai;
        }

        if (gram < 0) return;
        state.beratTerakhirGram = gram;

        // Stabil: panggil callback hanya untuk nilai stabil (ST/STABLE/=).
        if (stabil && state.onBerat) {
            state.onBerat(gram);
        }
    }

    /* ============================================================
       PRINTER THERMAL ESC/POS
       ============================================================ */

    function hubungkanPrinter() {
        if (!dukunganSerial()) {
            throw new Error('Web Serial tidak didukung browser ini.');
        }
        return navigator.serial.requestPort()
            .then(function (port) {
                var cfg = state.config.printer;
                return port.open({
                    baudRate: cfg.baudRate,
                    dataBits: cfg.dataBits,
                    stopBits: cfg.stopBits,
                    parity: cfg.parity
                }).then(function () {
                    state.printer = port;
                    beriStatus();
                });
            });
    }

    function putuskanPrinter() {
        var port = state.printer;
        state.printer = null;
        beriStatus();
        if (port) {
            try { port.close(); } catch (e) { /* abaikan */ }
        }
    }

    // Konversi teks ke byte ESC/POS (encoding sederhana; utk utf8 gunakan
    // TextEncoder; utk cp437 disederhanakan ke byte latin1).
    function teksKeBytes(teks) {
        if (state.config.printer.charset === 'utf8' && window.TextEncoder) {
            return new TextEncoder().encode(teks);
        }
        var bytes = [];
        for (var i = 0; i < teks.length; i++) {
            var c = teks.charCodeAt(i);
            bytes.push(c <= 255 ? c : 63); // '?'
        }
        return new Uint8Array(bytes);
    }

    /**
     * Susun perintah ESC/POS utk struk teks lalu kirim ke printer.
     * @param {string} strukText teks struk (dari server, plain text).
     */
    function cetakStruk(strukText) {
        var port = state.printer;
        if (!port) {
            return Promise.reject(new Error('Printer belum terhubung.'));
        }

        var cmds = [];
        cmds.push(0x1b, 0x40); // ESC @ — inisialisasi

        var baris = String(strukText || '').split(/\r?\n/);
        baris.forEach(function (barisTeks) {
            var b = teksKeBytes(barisTeks);
            for (var i = 0; i < b.length; i++) {
                cmds.push(b[i]);
            }
            cmds.push(0x0a); // LF
        });

        cmds.push(0x1b, 0x64, 0x04); // ESC d 4 — feed 4 baris
        cmds.push(0x1d, 0x56, 0x42, 0x00); // GS V B 0 — full cut
        // cmds.push(0x1b, 0x69); // ESC i — auto cutter (alternatif)

        var payload = new Uint8Array(cmds);
        var writer = port.writable.getWriter();
        return writer.write(payload)
            .then(function () { writer.releaseLock(); })
            .catch(function (e) {
                try { writer.releaseLock(); } catch (e2) { /* abaikan */ }
                throw e;
            });
    }

    /* ============================================================
       API publik
       ============================================================ */
    window.POSHardware = {
        didukung: dukunganSerial,
        muatConfig: ambilConfig,
        // Timbangan
        hubungkanTimbangan: hubungkanTimbangan,
        putuskanTimbangan: putuskanTimbangan,
        getBeratGram: function () { return state.beratTerakhirGram; },
        setOnBerat: function (fn) { state.onBerat = fn; },
        // Printer
        hubungkanPrinter: hubungkanPrinter,
        putuskanPrinter: putuskanPrinter,
        cetakStruk: cetakStruk,
        // Status
        getStatus: function () { return { timbangan: !!state.timbangan, printer: !!state.printer }; },
        setOnStatus: function (fn) { state.onStatus = fn; }
    };
})();
