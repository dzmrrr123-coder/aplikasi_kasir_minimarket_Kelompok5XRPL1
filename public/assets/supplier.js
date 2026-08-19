// Supplier: tambah & hapus tanpa reload penuh. Edit tetap reload.
(function () {
    'use strict';

    if (!window.location.pathname.endsWith('/supplier.php')) return;

    function tampilPesan(pesan, tipe) {
        var flash = document.querySelector('.container .alert');
        if (!flash) {
            flash = document.createElement('div');
            flash.className = 'alert alert-' + tipe + ' alert-dismissible fade show';
            var container = document.querySelector('.container');
            if (container) container.insertBefore(flash, container.firstChild);
        }
        flash.className = 'alert alert-' + tipe + ' alert-dismissible fade show';
        flash.innerHTML = '<span>' + pesan + '</span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>';
    }

    function reloadTabel() {
        if (!window.jQuery || !window.DataTable) return;
        var tabel = jQuery('#tabel-supplier');
        if (tabel.length && jQuery.fn.DataTable.isDataTable(tabel)) {
            tabel.DataTable().ajax.reload(null, false);
        }
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelector) return;
        var aksiInput = form.querySelector('input[name="aksi"]');
        if (!aksiInput) return;

        var aksi = aksiInput.value;
        if (aksi !== 'simpan_supplier' && aksi !== 'hapus_supplier') return;

        // Edit supplier = form simpan_supplier saat editSupplier ada; biarkan reload.
        var editHeading = form.closest('.card') && form.closest('.card').querySelector('.card-header');
        var isEdit = editHeading && editHeading.textContent.indexOf('Edit Supplier') !== -1;
        if (aksi === 'simpan_supplier' && isEdit) return;

        e.preventDefault();
        var data = new FormData(form);
        data.set('csrf', document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '');

        fetch('supplier.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: data
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                reloadTabel();
                tampilPesan(res.pesan || 'Tersimpan.', 'success');
                if (aksi === 'simpan_supplier') form.reset();
            })
            .catch(function (err) {
                tampilPesan('Gagal memproses: ' + err.message, 'danger');
            });
    });
})();
