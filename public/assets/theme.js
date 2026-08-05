/* ============================================================
   KASIR MINIMARKET — Theme toggle (Light/Dark Mode)
   - Menyimpan preferensi ke localStorage ('kasir-theme')
   - Kiosk Mode (POS) memaksa Dark Mode secara default
   ============================================================ */
(function () {
    'use strict';

    var KEY = 'kasir-theme';
    var kiosk = document.body.classList.contains('kiosk-body');
    // Kiosk default: dark, tapi tetap hormati preferensi tersimpan.
    var defaultTheme = kiosk ? 'dark' : 'light';

    function terapkan(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            document.body.classList.add('dark-mode');
        } else {
            document.documentElement.setAttribute('data-theme', 'light');
            document.body.classList.remove('dark-mode');
        }
    }

    // Inisialisasi: preferensi tersimpan > default (kiosk = dark).
    var tersimpan = null;
    try {
        tersimpan = localStorage.getItem(KEY);
    } catch (e) {
        tersimpan = null;
    }
    terapkan(tersimpan === 'dark' || tersimpan === 'light' ? tersimpan : defaultTheme);

    // Toggle button (di navbar semua halaman).
    var btn = document.getElementById('toggle-theme');
    if (btn) {
        btn.addEventListener('click', function () {
            var sekarang = document.body.classList.contains('dark-mode') ? 'dark' : 'light';
            var baru = sekarang === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem(KEY, baru);
            } catch (e) { /* abaikan */ }
            terapkan(baru);
        });
    }
})();
