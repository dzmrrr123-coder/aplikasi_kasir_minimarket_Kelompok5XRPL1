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
       SIDEBAR — buka/tutup off-canvas di layar kecil (< 992px).
       Di layar besar sidebar selalu terlihat (CSS), JS ini hanya
       mengatur mode mobile: tombol hamburger, backdrop, tombol X, Esc.
       ============================================================ */
    var sidebar = document.getElementById('pos-sidebar');
    var sidebarToggle = document.getElementById('pos-sidebar-toggle');
    var sidebarClose = document.getElementById('pos-sidebar-close');
    var sidebarBackdrop = document.getElementById('pos-sidebar-backdrop');

    if (sidebar && sidebarToggle) {
        function bukaSidebar() {
            sidebar.classList.add('open');
            if (sidebarBackdrop) {
                sidebarBackdrop.hidden = false;
                sidebarBackdrop.classList.add('show');
            }
            sidebarToggle.setAttribute('aria-expanded', 'true');
        }

        function tutupSidebar() {
            sidebar.classList.remove('open');
            if (sidebarBackdrop) {
                sidebarBackdrop.classList.remove('show');
                sidebarBackdrop.hidden = true;
            }
            sidebarToggle.setAttribute('aria-expanded', 'false');
        }

        sidebarToggle.addEventListener('click', bukaSidebar);
        if (sidebarClose) {
            sidebarClose.addEventListener('click', tutupSidebar);
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', tutupSidebar);
        }
        // Tutup otomatis setelah memilih menu (mode mobile).
        sidebar.querySelectorAll('.pos-sidebar-link').forEach(function (link) {
            link.addEventListener('click', tutupSidebar);
        });
        // Tutup dengan tombol Escape.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                tutupSidebar();
            }
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
    }
})();
