<?php
require_once __DIR__ . '/c.php';

include_once __DIR__ . '/../extends/header.php';
include_once __DIR__ . '/../extends/style.php';
include_once __DIR__ . '/../extends/keranjang.php';

// -------------------------------------------------------------------
// 1. DETEKSI OTOMATIS IDENTITAS PC CLIENT DARI DATABASE LOKAL
// -------------------------------------------------------------------
$active_pc = getActiveClientPC($pdo);
// -------------------------------------------------------------------
// 2. AJAX POST HANDLER: PROSES SIMPAN PESANAN KE DATABASE MYSQL
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_order') {
    header('Content-Type: application/json');
    try {
        $cart_json = $_POST['cart_data'] ?? '[]';
        $cart_items = json_decode($cart_json, true);
        $payment_method = $_POST['payment_method'] ?? 'cash';

        if (empty($cart_items)) {
            echo json_encode(['success' => false, 'message' => 'Keranjang pesanan kosong! Silakan pilih makanan/minuman terlebih dahulu.']);
            exit;
        }

        // Hitung total nominal transaksi
        $total_amount = 0;
        foreach ($cart_items as $item) {
            $total_amount += ((float)$item['price'] * (int)$item['qty']);
        }

        // Generate Kode Nota / ID Transaksi unik (Contoh: WRN-482910)
        $order_code = 'WRN-' . rand(100000, 999999);

        // Tentukan status awal pembayaran berdasarkan metode
        $payment_status = ($payment_method === 'billing') ? 'paid' : 'unpaid';

        // Mulai Database Transaction PDO
        $pdo->beginTransaction();

        // 1. Insert Header Transaksi ke Tabel `orders`
        $stmt_order = $pdo->prepare("
            INSERT INTO orders (order_code, pc_name, voucher_code, total_amount, payment_method, payment_status, order_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt_order->execute([
            $order_code,
            $active_pc['pc_name'],
            $active_pc['active_voucher'],
            $total_amount,
            $payment_method,
            $payment_status
        ]);

        $order_id = $pdo->lastInsertId();

        // 2. Insert Rincian Item ke Tabel `order_items`
        $stmt_item = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, price, qty, variant, notes, subtotal)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($cart_items as $item) {
            $subtotal = (float)$item['price'] * (int)$item['qty'];
            $product_id = isset($item['id']) ? (int)$item['id'] : 1;
            
            $stmt_item->execute([
                $order_id,
                $product_id,
                $item['name'],
                $item['price'],
                $item['qty'],
                $item['variant'] ?? 'Normal',
                $item['notes'] ?? null,
                $subtotal
            ]);
        }

        // Commit transaksi database
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Pesanan berhasil dikirim ke kasir & dapur warnet!',
            'order_code' => $order_code,
            'order_id' => $order_id,
            'payment_method' => $payment_method,
            'total' => $total_amount,
            'pc_name' => $active_pc['pc_name']
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan ke database MySQL: ' . $e->getMessage()]);
        exit;
    }
}
?>

    <!-- KONTEN UTAMA: HALAMAN METODE PEMBAYARAN -->
    <main class="max-w-7xl mx-auto px-6 pt-6 space-y-6">

        <!-- Title & Back Navigation -->
        <div class="flex items-center gap-3">
            <button onclick="history.back()" class="bg-slate-900 hover:bg-slate-800 border border-slate-800 p-2.5 rounded-xl text-slate-400 hover:text-white transition-all active:scale-95">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </button>
            <div>
                <h1 class="text-xl font-extrabold text-white tracking-wide">Pilih Metode Pembayaran</h1>
                <p class="text-xs text-slate-400">Tentukan cara pembayaran yang ingin Anda gunakan untuk transaksi ini</p>
            </div>
        </div>

        <!-- Grid Utama (2 Kolom Desktop Widescreen) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- KOLOM KIRI (2/3 Width): Pilihan Metode Pembayaran -->
            <div class="lg:col-span-2 space-y-4">
                
                <!-- Banner Identitas Pengantaran PC -->
                <div class="bg-gradient-to-r from-slate-900 via-slate-900 to-brand-950/40 border border-brand-500/30 p-4 rounded-2xl flex items-center justify-between gap-4 shadow-lg">
                    <div class="flex items-center gap-3.5">
                        <div class="bg-brand-500/20 text-brand-400 p-3 rounded-xl border border-brand-500/30">
                            <i class="fa-solid fa-desktop text-xl"></i>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-brand-400 uppercase tracking-widest block">Tujuan Pengantaran Pesanan</span>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-base font-extrabold text-white">Komputer <?= htmlspecialchars($active_pc['pc_name']) ?></span>
                                <span class="text-slate-600">•</span>
                                <span class="text-xs text-slate-300 font-mono"><?= htmlspecialchars($active_pc['active_voucher']) ?></span>
                            </div>
                        </div>
                    </div>
                    <span class="hidden sm:inline-flex items-center gap-1.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 font-bold text-xs px-3 py-1 rounded-lg">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        IP Client MySQL Terverifikasi
                    </span>
                </div>

                <!-- Formulir Pilihan Metode Pembayaran (Cash & QRIS) -->
                <div class="space-y-3">
                    <label class="text-xs font-bold text-slate-300 uppercase tracking-wider block pl-1">Pilihan Metode Pembayaran:</label>

                    <!-- Opsi 1: Bayar di Kasir (Cash / Tunai) -->
                    <div id="card-pay-cash" onclick="selectPaymentMethod('cash')" class="payment-card active bg-slate-900 border-2 border-brand-500 rounded-2xl p-4 cursor-pointer transition-all hover:border-brand-400 shadow-md flex items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="bg-emerald-500/10 text-emerald-400 p-3.5 rounded-xl border border-emerald-500/20 shrink-0">
                                <i class="fa-solid fa-money-bill-wave text-xl"></i>
                            </div>
                            <div class="space-y-0.5">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-extrabold text-white">Bayar di Kasir (Cash / Tunai)</h3>
                                    <span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[9px] font-bold px-2 py-0.5 rounded">Rekomendasi</span>
                                </div>
                                <p class="text-xs text-slate-400">Bayar uang tunai saat operator kasir mengantar makanan langsung ke meja <b><?= htmlspecialchars($active_pc['pc_name']) ?></b>.</p>
                            </div>
                        </div>
                        <input type="radio" name="payment_method" value="cash" checked class="w-4 h-4 accent-brand-500 shrink-0 cursor-pointer">
                    </div>

                    <!-- Opsi 2: QRIS (Scan Barcode Instant) -->
                    <div id="card-pay-qris" onclick="selectPaymentMethod('qris')" class="payment-card bg-slate-900 border border-slate-800 rounded-2xl p-4 cursor-pointer transition-all hover:border-brand-500/60 shadow-md flex items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="bg-amber-500/10 text-amber-400 p-3.5 rounded-xl border border-amber-500/20 shrink-0">
                                <i class="fa-solid fa-qrcode text-xl"></i>
                            </div>
                            <div class="space-y-0.5">
                                <h3 class="text-sm font-extrabold text-white">QRIS (Scan Barcode Instant)</h3>
                                <p class="text-xs text-slate-400">Bisa menggunakan Gopay, OVO, DANA, ShopeePay, LinkAja, BCA, atau M-Banking apa saja.</p>
                            </div>
                        </div>
                        <input type="radio" name="payment_method" value="qris" class="w-4 h-4 accent-brand-500 shrink-0 cursor-pointer">
                    </div>
                </div>
            </div>

            <!-- KOLOM KANAN (1/3 Width): Ringkasan Belanja & Tombol Bayar -->
            <div class="lg:col-span-1 space-y-4">            
                <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl shadow-xl space-y-5 sticky top-24">
                    <h2 class="text-sm font-extrabold text-white tracking-wide border-b border-slate-800 pb-3 flex items-center justify-between">
                        <span>Ringkasan Tagihan</span>
                        <span id="payment-summary-items-count" class="bg-brand-500/10 border border-brand-500/20 text-brand-400 text-xs px-2.5 py-0.5 rounded-lg font-mono">0 Item</span>
                    </h2>

                    <!-- Daftar Item Singkat dari Previous Page -->
                    <div id="payment-summary-items-list" class="space-y-2 max-h-48 overflow-y-auto pr-1 hide-scrollbar">
                        <!-- Dynamic via JS -->
                    </div>

                    <!-- Rincian Total Bayar -->
                    <div class="border-t border-slate-800 pt-3 space-y-2 text-xs">
                        <div class="flex justify-between items-center text-slate-400">
                            <span>Subtotal Pesanan</span>
                            <span id="payment-subtotal" class="font-bold text-slate-200">Rp 0</span>
                        </div>
                        <div class="flex justify-between items-center text-slate-400">
                            <span>Metode Dipilih</span>
                            <span id="selected-method-label" class="font-extrabold text-brand-400">Bayar di Kasir (Cash)</span>
                        </div>
                        <div class="border-t border-slate-800 pt-3 flex justify-between items-center">
                            <div>
                                <span class="text-sm font-extrabold text-white block">Total Tagihan</span>
                                <span class="text-[10px] text-slate-500 block">Sudah Termasuk PPN</span>
                            </div>
                            <span id="payment-total" class="text-lg font-extrabold text-emerald-400">Rp 0</span>
                        </div>
                    </div>

                    <!-- Tombol Eksekusi Pembayaran -->
                    <button id="btn-confirm-pay" onclick="confirmPaymentProcess()" class="w-full bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-slate-950 font-extrabold text-xs py-3.5 rounded-xl shadow-lg active:scale-95 transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-lock text-xs"></i>
                        <span>Bayar sekarang!</span>
                    </button>

                    <!-- Jaminan Keamanan -->
                    <div class="bg-slate-950/60 p-3 rounded-xl border border-slate-800/80 flex items-start gap-2 text-[10px] text-slate-400">
                        <i class="fa-solid fa-shield-halved text-emerald-400 text-xs mt-0.5 shrink-0"></i>
                        <p>Setelah mengonfirmasi, pesanan otomatis tersimpan di Database MySQL & diteruskan ke dapur warnet.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- TOAST NOTIFIKASI -->
    <div id="toast-notif" class="fixed top-16 left-1/2 -translate-x-1/2 z-50 bg-emerald-500 text-slate-950 px-4 py-2 rounded-xl font-bold text-xs shadow-xl transition-all transform -translate-y-10 opacity-0 pointer-events-none flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i>
        <span id="toast-msg">Metode pembayaran dipilih!</span>
    </div>

    <!-- JAVASCRIPT LOGIK METODE PEMBAYARAN PHP & DATABASE -->
    <script>
        let cartItems = [];
        let selectedMethod = 'cash';

        window.onload = function() {
            loadCartData();
        };

        function loadCartData() {
            cartItems = JSON.parse(localStorage.getItem('cyberbite_cart') || '[]');
            if (cartItems.length === 0) {
                // Jika keranjang kosong, redirect kembali ke halaman makanan
                window.location.href = 'menu-makanan.php';
                return;
            }
            renderSummarySidebar();
        }

        function renderSummarySidebar() {
            const container = document.getElementById('payment-summary-items-list');
            const totalItems = cartItems.reduce((sum, item) => sum + item.qty, 0);
            const subtotal = cartItems.reduce((sum, item) => sum + (item.price * item.qty), 0);

            document.getElementById('payment-summary-items-count').innerText = `${totalItems} Item`;
            document.getElementById('payment-subtotal').innerText = formatRupiah(subtotal);
            document.getElementById('payment-total').innerText = formatRupiah(subtotal);

            container.innerHTML = '';
            cartItems.forEach(item => {
                const row = document.createElement('div');
                row.className = "flex items-center justify-between text-xs bg-slate-950/60 p-2 rounded-xl border border-slate-800/80";
                row.innerHTML = `
                    <div class="flex items-center gap-2.5 overflow-hidden pr-2">
                        <img src="${item.image}" alt="${item.name}" class="w-8 h-8 rounded-lg object-cover bg-slate-800 shrink-0">
                        <div class="truncate">
                            <p class="font-bold text-slate-200 text-[11px] truncate">${item.name}</p>
                            <p class="text-[9px] text-slate-400">${item.qty}x • ${item.variant}</p>
                        </div>
                    </div>
                    <span class="font-extrabold text-emerald-400 text-xs shrink-0">${formatRupiah(item.price * item.qty)}</span>
                `;
                container.appendChild(row);
            });
        }

        function selectPaymentMethod(method) {
            selectedMethod = method;

            document.querySelectorAll('.payment-card').forEach(card => {
                card.className = "payment-card bg-slate-900 border border-slate-800 rounded-2xl p-4 cursor-pointer transition-all hover:border-brand-500/60 shadow-md flex items-center justify-between gap-4";
            });

            if (method === 'cash') {
                const card = document.getElementById('card-pay-cash');
                card.className = "payment-card active bg-slate-900 border-2 border-brand-500 rounded-2xl p-4 cursor-pointer transition-all hover:border-brand-400 shadow-md flex items-center justify-between gap-4";
                document.querySelector('input[value="cash"]').checked = true;
                document.getElementById('selected-method-label').innerText = "Bayar di Kasir (Cash)";
            } else if (method === 'qris') {
                const card = document.getElementById('card-pay-qris');
                card.className = "payment-card active bg-slate-900 border-2 border-brand-500 rounded-2xl p-4 cursor-pointer transition-all hover:border-brand-400 shadow-md flex items-center justify-between gap-4";
                document.querySelector('input[value="qris"]').checked = true;
                document.getElementById('selected-method-label').innerText = "QRIS (Scan Barcode)";
            }
        }

        async function confirmPaymentProcess() {
            if (cartItems.length === 0) return;

            const btnConfirm = document.getElementById('btn-confirm-pay');
            btnConfirm.disabled = true;
            btnConfirm.innerHTML = `<i class="fa-solid fa-spinner animate-spin text-xs"></i> <span>Menyimpan ke Database MySQL...</span>`;

            const formData = new FormData();
            formData.append('action', 'process_order');
            formData.append('cart_data', JSON.stringify(cartItems));
            formData.append('payment_method', selectedMethod);

            try {
                const response = await fetch('pembayaran.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();

                if (res.success) {
                    showToast(res.message);
                    
                    // Simpan response transaksi aktif ke LocalStorage untuk pelacakan di sesi-pembayaran.php
                    localStorage.setItem('cyberbite_active_payment', JSON.stringify({
                        orderCode: res.order_code,
                        dbOrderId: res.order_id,
                        method: res.payment_method,
                        totalAmount: res.total,
                        pcName: res.pc_name,
                        orderTime: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
                    }));

                    // Kosongkan keranjang di browser setelah sukses tersimpan di MySQL
                    localStorage.removeItem('cyberbite_cart');

                    setTimeout(() => {
                        window.location.href = 'sesi-pembayaran.php';
                    }, 1200);
                } else {
                    alert("Gagal memproses transaksi: " + res.message);
                    btnConfirm.disabled = false;
                    btnConfirm.innerHTML = `<i class="fa-solid fa-lock text-xs"></i> <span>Bayar sekarang!</span>`;
                }
            } catch (err) {
                console.error(err);
                alert("Terjadi kesalahan koneksi server lokal!");
                btnConfirm.disabled = false;
                btnConfirm.innerHTML = `<i class="fa-solid fa-lock text-xs"></i> <span>Bayar sekarang!</span>`;
            }
        }

        function triggerLogoutModal() {
            showToast("Sesi komputer <?= htmlspecialchars($active_pc['pc_name']) ?> masih aktif.");
        }

        function showToast(msg) {
            const toast = document.getElementById('toast-notif');
            document.getElementById('toast-msg').innerText = msg;
            toast.classList.remove('-translate-y-10', 'opacity-0', 'pointer-events-none');
            setTimeout(() => {
                toast.classList.add('-translate-y-10', 'opacity-0', 'pointer-events-none');
            }, 2500);
        }

        function formatRupiah(num) {
            return 'Rp ' + num.toLocaleString('id-ID');
        }
    </script>
</body>
</html>
