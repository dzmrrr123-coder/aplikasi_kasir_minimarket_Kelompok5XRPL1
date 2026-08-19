// Sisa halaman admin: setelah aksi CRUD sukses, reload halaman secara otomatis.
// Ini menggantikan refresh manual oleh user, bukan navigasi penuh yang lambat.
(function () {
    'use strict';

    // Halaman dan aksi yang dianggap CRUD (simpan/hapus/edit/void).
    var aksiCrud = [
        'proses_retur',
        'simpan_member', 'hapus_member',
        'simpan_diskon', 'hapus_diskon', 'edit_diskon',
        'simpan_kasir', 'hapus_kasir', 'toggle_aktif_kasir', 'reset_password'
    ];

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelector) return;

        var aksiInput = form.querySelector('input[name="aksi"]');
        if (!aksiInput) return;

        var aksi = aksiInput.value;
        if (aksiCrud.indexOf(aksi) === -1) return;

        // Konfirmasi `onsubmit` sudah dieksekusi browser sebelum event submit,
        // jadi tidak akan menerobos konfirmasi hapus yang memakai `return confirm(...)`.
        e.preventDefault();

        var data = new FormData(form);
        data.set('csrf', document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '');

        var target = window.location.pathname.split('/').pop();

        fetch(target, {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: data
        })
            .then(function (r) { return r.text(); })
            .then(function () {
                // Setelah aksi selesai, reload halaman otomatis. Pesan hasil
                // tetap tampil lewat session flash setelah reload.
                window.location.reload();
            })
            .catch(function () {
                window.location.reload();
            });
    });
})();
