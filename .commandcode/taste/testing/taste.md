# Taste
- Wants automated tests to cover success paths as well as failure paths — explicitly asks for e.g. a test that a valid non-cash payment succeeds, not only the rejected/negative cases (also: retur tests must cover jalur sukses AND jalur gagal — stok kurang, supplier invalid). Confidence: 0.9
- Wants e2e tests added for each new feature/flow implemented (e.g., explicitly requested "tambahkan test e2e untuk alur ini" for admin CRUD produk/kategori), covering create/edit/delete plus guardrails (e.g., FK-restricted deletes rejected). Confidence: 0.8
- Expects the e2e test suite to be re-run after every code change and wants failing assertions updated to match the new behavior rather than left red — explicitly asked "Jalankan test e2e lagi setelah ini". Confidence: 0.8
