// Admin: submit produk via fetch lalu reload DataTable tanpa navigasi penuh.
// Edit produk tetap reload penuh karena form/meta edit perlu dirender ulang.
(function () {
    'use strict';

    if (!window.location.pathname.endsWith('/admin.php')) return;

    var flash = document.querySelector('#flash-admin') || document.querySelector('.container .alert');

    function tampilPesan(pesan, tipe) {
        if (!flash) {
            flash = document.createElement('div');
            flash.className = 'alert alert-' + tipe + ' alert-dismissible fade show';
            flash.innerHTML = '<span></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>';
            var container = document.querySelector('.container');
            if (container) container.insertBefore(flash, container.firstChild);
        }
        flash.className = 'alert alert-' + tipe + ' alert-dismissible fade show';
        flash.innerHTML = '<span>' + pesan + '</span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>';
        flash.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function reloadTabelProduk() {
        if (!window.jQuery || !window.DataTable) return;
        var tabel = jQuery('#tabel-produk');
        if (tabel.length && jQuery.fn.DataTable.isDataTable(tabel)) {
            tabel.DataTable().ajax.reload(null, false);
        }
    }

    function reloadDaftarKategori() {
        // Kategori di-render statis di card, jadi ambil ulang halaman tanpa
        // navigasi penuh: fetch admin.php dan ganti isi card kategori.
        fetch('admin.php', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var kategoriBaru = tmp.querySelector('.card.pos-card.mb-4');
                var kategoriLama = document.querySelector('.card.pos-card.mb-4');
                if (kategoriBaru && kategoriLama) {
                    kategoriLama.outerHTML = kategoriBaru.outerHTML;
                }
            })
            .catch(function () { /* biarkan */ });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelector) return;

        var aksiInput = form.querySelector('input[name="aksi"]');
        if (!aksiInput) return;

        var aksi = aksiInput.value;
        var isEditProduk = false;

        if (aksi === 'simpan_produk') {
            var meta = document.querySelector('meta[name="produk-id-edit"]');
            var editId = meta ? parseInt(meta.getAttribute('content'), 10) : 0;
            isEditProduk = editId > 0;
        }

        // Edit produk dibiarkan reload penuh supaya meta/form edit benar.
        if (aksi === 'simpan_produk' && isEditProduk) return;
        if (aksi !== 'simpan_produk' && aksi !== 'hapus_produk' &&
            aksi !== 'simpan_kategori' && aksi !== 'hapus_kategori') return;

        e.preventDefault();

        var data = new FormData(form);
        data.set('csrf', document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '');

        fetch('admin.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: data
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (res) {
                if (aksi === 'simpan_produk' || aksi === 'hapus_produk') {
                    reloadTabelProduk();
                } else {
                    reloadDaftarKategori();
                }
                tampilPesan(res.pesan || 'Tersimpan.', 'success');
                if (aksi === 'simpan_produk' || aksi === 'simpan_kategori') form.reset();
            })
            .catch(function (err) {
                tampilPesan('Gagal memproses: ' + err.message, 'danger');
            });
    });
})();
