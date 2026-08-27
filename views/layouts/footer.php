<?php
// views/layouts/footer.php
// Penutup halaman: tutup <main> + </div> shell, Bootstrap JS, theme script, sidebar mobile JS.
?>
</main><!-- /.admin-content -->
</div><!-- /.admin-shell -->

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ============================================================
// Dark Mode Toggle — Admin Pages
// Menyinkronkan kelas .dark-mode di <html> dan <body>,
// menyimpan preferensi di localStorage.
// ============================================================
(function () {
    const html    = document.documentElement;
    const body    = document.body;
    const KEY     = 'adminTheme';
    const BTN_ID  = 'adminThemeToggle';

    function applyTheme(dark) {
        if (dark) {
            html.classList.add('dark-mode');
            body.classList.add('dark-mode');
            html.setAttribute('data-theme', 'dark');
        } else {
            html.classList.remove('dark-mode');
            body.classList.remove('dark-mode');
            html.setAttribute('data-theme', 'light');
        }
    }

    // Restore on load (already done in head, but sync body too)
    applyTheme(localStorage.getItem(KEY) === 'dark');

    document.addEventListener('DOMContentLoaded', function () {
        const btn = document.getElementById(BTN_ID);
        if (!btn) return;
        btn.addEventListener('click', function () {
            const isDark = html.classList.contains('dark-mode');
            applyTheme(!isDark);
            localStorage.setItem(KEY, isDark ? 'light' : 'dark');
        });
    });
})();

// ============================================================
// Sidebar Mobile Toggle
// ============================================================
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const toggleBtn  = document.getElementById('sidebarToggleBtn');
        const sidebar    = document.getElementById('adminSidebar');
        const backdrop   = document.getElementById('sidebarBackdrop');

        if (!toggleBtn || !sidebar) return;

        function openSidebar() {
            sidebar.classList.add('show');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }

        toggleBtn.addEventListener('click', openSidebar);
        backdrop.addEventListener('click', closeSidebar);

        // Close on resize to desktop
        window.addEventListener('resize', function () {
            if (window.innerWidth >= 992) closeSidebar();
        });
    });
})();
</script>

</body>
</html>
