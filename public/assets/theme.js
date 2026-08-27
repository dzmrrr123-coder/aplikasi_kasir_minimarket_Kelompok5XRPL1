/* ============================================================
   KASIR MINIMARKET — Theme toggle (Light/Dark Mode)
   - Menyimpan preferensi ke localStorage ('kasir-theme')
   - Kiosk Mode (POS) memaksa Dark Mode secara default
   ============================================================ */
(function () {
    'use strict';

    var KEY = 'kasir-theme';
    var kiosk = document.body.classList.contains('kiosk-body');
    // Kiosk (POS) dibuat light (putih); halaman lain default light juga.
    var defaultTheme = 'light';

    function terapkan(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            document.body.classList.add('dark-mode');
        } else {
            document.documentElement.setAttribute('data-theme', 'light');
            document.body.classList.remove('dark-mode');
        }
    }

    // Inisialisasi: kiosk (POS) SELALU light — abaikan preferensi tersimpan
    // supaya layar kasir konsisten putih. Halaman lain hormati preferensi.
    var tersimpan = null;
    try {
        tersimpan = localStorage.getItem(KEY);
    } catch (e) {
        tersimpan = null;
    }
    terapkan(kiosk ? 'light' : (tersimpan === 'dark' || tersimpan === 'light' ? tersimpan : defaultTheme));

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

    /* ============================================================
       CSRF — tambahkan token ke semua form POST secara otomatis.
       Token dibaca dari <meta name="csrf-token">. Mencakup form
       statis maupun form yang di-render DataTables (edit/hapus).
       ============================================================ */
    var metaCsrf = document.querySelector('meta[name="csrf-token"]');
    var tokenCsrf = metaCsrf ? metaCsrf.getAttribute('content') : '';

    if (tokenCsrf) {
        function sisipToken(form) {
            if (form.method.toLowerCase() === 'post' && !form.querySelector('input[name="csrf"]')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf';
                input.value = tokenCsrf;
                form.appendChild(input);
            }
        }

        // Form yang sudah ada di DOM.
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(sisipToken);

    // Form baru (mis. hasil fragment AJAX / DataTables) — pakai observer.
    if (window.MutationObserver) {
        var observer = new MutationObserver(function (mutasi) {
            mutasi.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1 && node.tagName === 'FORM') {
                        sisipToken(node);
                    } else if (node.nodeType === 1) {
                        node.querySelectorAll &&
                            node.querySelectorAll('form[method="post"], form[method="POST"]').forEach(sisipToken);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();

/* ============================================================
   GLOBAL LOADING STATE
   - Semua tombol export (.export-btn): ganti teks jadi
     "Memproses…" + spinner saat klik (sebelum form submit /
     fetch AJAX selesai).
   - Semua tabel DataTables (.datatable): tampilkan overlay
     "Memuat…" saat ajax / draw sedang berjalan.
   ============================================================ */
(function () {
    'use strict';

    // --- Export button loading ---
    function initExportButtons() {
        document.querySelectorAll('.export-btn').forEach(function (btn) {
            if (btn.__exportBound) return;
            btn.__exportBound = true;
            btn.addEventListener('click', function () {
                var asli = btn.innerHTML;
                btn.disabled = true;
                btn.setAttribute('data-text-asli', asli);
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Memproses…';
                // Beberapa detik kemudian, kembalikan (jika fetch tak set disabled lagi).
                setTimeout(function () {
                    if (btn.disabled) {
                        btn.disabled = false;
                        var txt = btn.getAttribute('data-text-asli') || asli;
                        btn.innerHTML = txt;
                    }
                }, 4000);
            });
        });
    }

    // --- DataTables loading overlay ---
    function observasiDataTable() {
        var table = document.querySelector('.datatable');
        if (!table || table.__dtBound) return;
        table.__dtBound = true;
        // Tunggu DataTable inisialisasi (asinkron via ajax).
        if (window.jQuery && window.jQuery().DataTable) {
            table.addEventListener('processing.dt', function (e, settings, data) {
                var el = table.closest('.dataTables_wrapper');
                if (el) {
                    el.setAttribute('aria-busy', data);
                }
            });
        }
    }

    // Export buttons
    initExportButtons();

    // Untuk DataTables yang di-render asinkron, pakai observer.
    if (window.MutationObserver) {
        var obs = new MutationObserver(function () {
            initExportButtons();
            observasiDataTable();
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }
})();
