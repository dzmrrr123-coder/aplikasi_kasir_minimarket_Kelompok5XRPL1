// Pembelian: simpan & hapus tanpa reload penuh.
(function () {
    'use strict';

    if (!window.location.pathname.endsWith('/pembelian.php')) return;

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
        var tabel = jQuery('#tabel-pembelian');
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
        if (aksi !== 'simpan_pembelian' && aksi !== 'hapus_pembelian') return;

        e.preventDefault();
        var data = new FormData(form);
        data.set('csrf', document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '');

        fetch('pembelian.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: data
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                reloadTabel();
                tampilPesan(res.pesan || 'Tersimpan.', 'success');
                if (aksi === 'simpan_pembelian') form.reset();
            })
            .catch(function (err) {
                tampilPesan('Gagal memproses: ' + err.message, 'danger');
            });
    });
})();
