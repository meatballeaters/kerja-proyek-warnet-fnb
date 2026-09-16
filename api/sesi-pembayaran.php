<?php
// 1. Panggil koneksi database (c.php) terlebih dahulu
require_once __DIR__ . '/c.php';
// Deteksi otomatis PC Client berdasarkan IP Address lokal
$active_pc = getActiveClientPC($pdo);

// -------------------------------------------------------------------
// HANDLER AJAX POST: UNGGAH BUKTI PEMBAYARAN PELANGGAN
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_payment_proof') {
    header('Content-Type: application/json');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // ... Logika INSERT ke tabel orders/order_items di PostgreSQL ...
        $order_code = $_POST['order_code'] ?? '';
        $proof_img = $_POST['proof_img'] ?? '';

        if (empty($order_code) || empty($proof_img)) {
            echo json_encode(['success' => false, 'message' => 'Foto bukti pembayaran tidak boleh kosong!']);
            exit;
        }

        // Update database: simpan URL/base64 bukti dan ubah payment_status ke 'waiting_verification'
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET payment_proof = ?, payment_status = 'waiting_verification' 
            WHERE order_code = ? AND order_status != 'cancelled'
        ");
        $stmt->execute([$proof_img, $order_code]);

        echo json_encode([
            'success' => true, 
            'message' => 'Bukti pembayaran berhasil dikirim! Kasir akan segera memverifikasi pesanan anda.'
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(1000);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
        
        echo json_encode(['success' => false, 'message' => 'Gagal mengunggah bukti: ' . $e->getMessage()]);
        exit;
    }
}

// Fetch data pesanan khusus PC pelanggan ini dari MySQL (10 transaksi terakhir)
try {
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE pc_name = ? 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $stmt->execute([$active_pc['pc_name']]);
    $my_orders = $stmt->fetchAll();

    // Fetch rincian order_items untuk setiap pesanan
    $orders_with_items = [];
    foreach ($my_orders as $ord) {
        $stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt_items->execute([$ord['id']]);
        $ord['items'] = $stmt_items->fetchAll();
        $orders_with_items[] = $ord;
    }
} catch (Exception $e) {
    $orders_with_items = [];
}

// Panggil file pendukung menggunakan path absolut __DIR__
include_once __DIR__ . '/../extends/header.php';
include_once __DIR__ . '/../extends/style.php';
include_once __DIR__ . '/../extends/keranjang.php';
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CyberBite - Status Pesanan & Sesi Pembayaran</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            400: '#38bdf8',
                            500: '#06b6d4',
                            600: '#0891b2',
                            700: '#0e7490'
                        }
                    }
                }
            }
        }
    </script>
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen pb-20 antialiased selection:bg-brand-500 selection:text-white">
    <!-- KONTEN UTAMA: HALAMAN PELELACAK & RIWAYAT PESANAN -->
    <main class="max-w-7xl mx-auto px-6 pt-6 space-y-6">

        <!-- Title & Auto Refresh Indicator -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="index.php" class="bg-slate-900 hover:bg-slate-800 border border-slate-800 p-2.5 rounded-xl text-slate-400 hover:text-white transition-all active:scale-95">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </a>
                <div>
                    <h1 class="text-xl font-extrabold text-white tracking-wide">Pemeriksaan Status Pesanan</h1>
                    <p class="text-xs text-slate-400">Pantau proses pengantaran dan rincian transaksi makanan/minuman untuk <b class="text-brand-400"><?= htmlspecialchars($active_pc['pc_name']) ?></b></p>
                </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <button onclick="location.reload()" class="bg-slate-900 hover:bg-slate-800 border border-slate-800 text-slate-200 text-xs font-bold px-3.5 py-2 rounded-xl flex items-center gap-2 transition active:scale-95">
                    <i class="fa-solid fa-rotate-right text-brand-400"></i>
                    <span>Refresh Status</span>
                </button>
            </div>
        </div>

        <?php if (empty($orders_with_items)): ?>
            <!-- EMPTY STATE PESANAN -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-12 text-center space-y-4 max-w-2xl mx-auto">
                <div class="w-16 h-16 bg-slate-800/80 rounded-2xl flex items-center justify-center mx-auto text-slate-600 border border-slate-700">
                    <i class="fa-solid fa-receipt text-2xl text-brand-400"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white">Belum Ada Pesanan Aktif</h3>
                    <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">Komputer <b class="text-white"><?= htmlspecialchars($active_pc['pc_name']) ?></b> belum memiliki riwayat transaksi pemesanan makanan atau minuman.</p>
                </div>
                <div class="flex items-center justify-center gap-3 pt-2">
                    <a href="menu-makanan.php" class="bg-gradient-to-r from-brand-500 to-indigo-600 hover:from-brand-600 hover:to-indigo-700 text-white font-extrabold text-xs px-5 py-2.5 rounded-xl shadow-lg transition flex items-center gap-2">
                        <i class="fa-solid fa-utensils"></i>
                        <span>Pesan Makanan Sekarang</span>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- LIST OF ORDERS -->
            <div class="space-y-6">
                <?php foreach ($orders_with_items as $index => $order): ?>
                    <?php
                        $status = $order['order_status'];
                        $payment_method = strtolower($order['payment_method']);
                        $payment_status = $order['payment_status'] ?? 'unpaid';
                        $va_bank = $order['va_bank'] ?? 'BCA';
                        $has_proof = !empty($order['payment_proof']);
                    ?>
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-5 hover:border-slate-700 transition">
                        
                        <!-- Header Kartu Pesanan -->
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-800 pb-4">
                            <div class="flex items-center gap-3">
                                <div class="bg-brand-500/10 text-brand-400 font-extrabold text-xs px-3 py-2 rounded-xl border border-brand-500/20 font-mono">
                                    <?= htmlspecialchars($order['order_code']) ?>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-extrabold text-white">Komputer <?= htmlspecialchars($order['pc_name']) ?></span>
                                        <span class="text-slate-600 text-xs">•</span>
                                        <span class="text-xs text-slate-400 font-mono"><?= date('H:i', strtotime($order['created_at'])) ?> WIB</span>
                                    </div>
                                    <span class="text-[10px] text-slate-500 block">Tanggal: <?= date('d M Y', strtotime($order['created_at'])) ?></span>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <!-- FIX BADGE LOGIC: JIKA DIBATALKAN, JANGAN TAMPILKAN "MENUNGGU VERIFIKASI" -->
                                <?php if ($payment_status === 'paid'): ?>
                                    <span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-check text-xs"></i> Lunas
                                    </span>
                                <?php elseif ($status !== 'cancelled' && in_array($payment_method, ['qris', 'va'])): ?>
                                    <?php if ($payment_status === 'waiting_verification'): ?>
                                        <span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5">
                                            <i class="fa-solid fa-image text-xs"></i> Bukti Dikirim (Proses Kasir)
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span> Menunggu Verifikasi Pembayaran
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- Badge Status Utama Pesanan -->
                                <?php if ($status === 'pending'): ?>
                                    <span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span> Menunggu Kasir
                                    </span>
                                <?php elseif ($status === 'preparing'): ?>
                                    <span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span> Diproses
                                    </span>
                                <?php elseif ($status === 'completed'): ?>
                                    <span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-check text-xs"></i> Pesanan Selesai
                                    </span>
                                <?php else: ?>
                                    <span class="bg-rose-500/10 text-rose-400 border border-rose-500/20 text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-xmark text-xs"></i> Dibatalkan
                                    </span>
                                <?php endif; ?>

                                <!-- Tombol Buka Sesi Pembayaran / Unggah Bukti (Hanya JIKA Belum Dibayar & Tidak Dibatalkan) -->
                                <?php if (in_array($payment_method, ['qris', 'va']) && $payment_status !== 'paid' && $status !== 'cancelled'): ?>
                                    <button onclick="openPaymentSessionModal('<?= $order['order_code'] ?>', '<?= $payment_method ?>', '<?= $va_bank ?>', <?= $order['total_amount'] ?>, '<?= addslashes($order['payment_proof'] ?? '') ?>')" class="bg-gradient-to-r from-brand-500 to-indigo-600 hover:from-brand-600 hover:to-indigo-700 text-white font-extrabold text-xs px-3.5 py-1.5 rounded-xl shadow-md transition active:scale-95 flex items-center gap-1.5">
                                        <i class="fa-solid fa-qrcode text-xs"></i>
                                        <span><?= $has_proof ? 'Lihat/Ubah Bukti' : 'Kirim Bukti Bayar' ?></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- REAL-TIME TRACKER PROGRESS BAR -->
                        <div class="bg-slate-950/80 p-4 rounded-xl border border-slate-800/80 space-y-2">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Lacak Alur Pesanan Real-Time:</p>
                            
                            <div class="grid grid-cols-4 gap-2 text-center relative pt-1">
                                <!-- Step 1: Diterima -->
                                <div class="space-y-1">
                                    <div class="w-7 h-7 mx-auto rounded-full <?= in_array($status, ['pending', 'preparing', 'completed']) ? 'bg-brand-500 text-slate-950 font-bold' : 'bg-slate-800 text-slate-500' ?> flex items-center justify-center text-xs">
                                        <i class="fa-solid fa-receipt"></i>
                                    </div>
                                    <span class="text-[10px] font-bold block <?= in_array($status, ['pending', 'preparing', 'completed']) ? 'text-brand-400' : 'text-slate-600' ?>">Diterima</span>
                                </div>

                                <!-- Step 2: Dimasak -->
                                <div class="space-y-1">
                                    <div class="w-7 h-7 mx-auto rounded-full <?= in_array($status, ['preparing', 'completed']) ? 'bg-cyan-500 text-slate-950 font-bold' : 'bg-slate-800 text-slate-500' ?> flex items-center justify-center text-xs">
                                        <i class="fa-solid fa-fire-burner"></i>
                                    </div>
                                    <span class="text-[10px] font-bold block <?= in_array($status, ['preparing', 'completed']) ? 'text-cyan-400' : 'text-slate-600' ?>">Diproses</span>
                                </div>

                                <!-- Step 3: Diantar -->
                                <div class="space-y-1">
                                    <div class="w-7 h-7 mx-auto rounded-full <?= $status === 'completed' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-800 text-slate-500' ?> flex items-center justify-center text-xs">
                                        <i class="fa-solid fa-person-walking-luggage"></i>
                                    </div>
                                    <span class="text-[10px] font-bold block <?= $status === 'completed' ? 'text-emerald-400' : 'text-slate-600' ?>">Diantar</span>
                                </div>

                                <!-- Step 4: Selesai -->
                                <div class="space-y-1">
                                    <div class="w-7 h-7 mx-auto rounded-full <?= $status === 'completed' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-800 text-slate-500' ?> flex items-center justify-center text-xs">
                                        <i class="fa-solid fa-check"></i>
                                    </div>
                                    <span class="text-[10px] font-bold block <?= $status === 'completed' ? 'text-emerald-400' : 'text-slate-600' ?>">Selesai</span>
                                </div>
                            </div>
                        </div>

                        <!-- Daftar Item Pesanan & Total -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-1">
                            <!-- Left 2 Cols: Item list -->
                            <div class="md:col-span-2 space-y-2">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Rincian Menu Yang Dipesan:</span>
                                <div class="space-y-2">
                                    <?php foreach ($order['items'] as $it): ?>
                                        <div class="bg-slate-950/50 p-2.5 rounded-xl border border-slate-800/80 flex items-center justify-between text-xs">
                                            <div class="space-y-0.5">
                                                <p class="font-bold text-slate-200"><?= htmlspecialchars($it['qty']) ?>x <?= htmlspecialchars($it['product_name']) ?> <span class="text-[10px] text-slate-400 font-normal">(<?= htmlspecialchars($it['variant']) ?>)</span></p>
                                                <?php if (!empty($it['notes'])): ?>
                                                    <p class="text-[10px] text-brand-300 italic">"<?= htmlspecialchars($it['notes']) ?>"</p>
                                                <?php endif; ?>
                                            </div>
                                            <span class="font-extrabold text-emerald-400">Rp <?= number_format((float)$it['subtotal'], 0, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Right 1 Col: Payment summary info -->
                            <div class="md:col-span-1 bg-slate-950/60 p-3.5 rounded-xl border border-slate-800 space-y-2 text-xs flex flex-col justify-between">
                                <div class="space-y-1">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Metode Pembayaran</span>
                                    <div class="flex items-center gap-2">
                                        <span class="bg-slate-800 text-brand-400 border border-slate-700 text-[10px] font-extrabold px-2 py-0.5 rounded font-mono uppercase"><?= htmlspecialchars($order['payment_method']) ?></span>
                                        <?php if ($order['payment_method'] === 'va'): ?>
                                            <span class="text-xs font-bold text-white"><?= htmlspecialchars($order['va_bank']) ?> VA</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="border-t border-slate-800 pt-2 flex justify-between items-center">
                                    <span class="text-slate-400 font-bold">Total Tagihan:</span>
                                    <span class="text-sm font-extrabold text-emerald-400">Rp <?= number_format((float)$order['total_amount'], 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <!-- MODAL POP-UP SESI PEMBAYARAN & UNGGAH BUKTI TRANSFER (QRIS & VA) -->
    <div id="payment-session-modal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-6 shadow-2xl space-y-5 transform scale-95 transition-transform duration-300" id="payment-modal-card">
            
            <!-- Modal Header -->
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="bg-brand-500/10 text-brand-400 p-2 rounded-xl border border-brand-500/20">
                        <i id="session-modal-icon" class="fa-solid fa-qrcode text-base"></i>
                    </div>
                    <div>
                        <h3 id="session-modal-title" class="text-sm font-extrabold text-white">Sesi Pembayaran Instant</h3>
                        <p id="session-modal-subtitle" class="text-[10px] text-slate-400 font-mono">Nota: WRN-000000</p>
                    </div>
                </div>
                <button onclick="closePaymentSessionModal()" class="text-slate-400 hover:text-white bg-slate-800 p-1.5 rounded-xl border border-slate-700"><i class="fa-solid fa-xmark text-sm"></i></button>
            </div>

            <!-- Modal Content Dynamic Body -->
            <div id="session-modal-body" class="space-y-4">
                <!-- Injected via JS -->
            </div>

            <!-- Upload Form Section Bukti Pembayaran -->
            <div class="bg-slate-950/80 p-3.5 rounded-xl border border-slate-800 space-y-2">
                <label class="text-[10px] font-bold text-slate-300 uppercase tracking-wider block">Unggah Foto Bukti Transfer / QRIS:</label>
                <div class="space-y-2">
                    <input type="file" id="proof-file-input" accept="image/*" onchange="previewProofImage(event)" class="text-[11px] text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-600 file:text-white hover:file:bg-brand-500 cursor-pointer w-full">
                    <div id="proof-preview-container" class="hidden text-center">
                        <img id="proof-preview-img" src="" class="max-h-36 mx-auto rounded-lg border border-slate-700 object-cover mt-2">
                    </div>
                </div>
                <button id="btn-submit-proof" onclick="submitPaymentProof()" class="w-full bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-extrabold text-xs py-2.5 rounded-xl shadow transition mt-1">
                    <i class="fa-solid fa-paper-plane mr-1"></i> Upload Bukti Pembayaran
                </button>
            </div>

            <!-- Total Amount Header -->
            <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 flex justify-between items-center text-xs">
                <span class="text-slate-400 font-bold">Total Nominal Pembayaran:</span>
                <span id="session-modal-total" class="text-base font-extrabold text-emerald-400">Rp 0</span>
            </div>

            <!-- Footer Action -->
            <button onclick="closePaymentSessionModal()" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs py-3 rounded-xl border border-slate-700 transition">
                Tutup & Kembali ke Status
            </button>
        </div>
    </div>

    <!-- TOAST NOTIFIKASI -->
    <div id="toast-notif" class="fixed top-16 left-1/2 -translate-x-1/2 z-50 bg-emerald-500 text-slate-950 px-4 py-2 rounded-xl font-bold text-xs shadow-xl transition-all transform -translate-y-10 opacity-0 pointer-events-none flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i>
        <span id="toast-msg">Notifikasi</span>
    </div>

    <!-- JAVASCRIPT LOGIK MODAL UNGGAH BUKTI & SESI PEMBAYARAN -->
    <script>
        let cartItems = JSON.parse(localStorage.getItem('cyberbite_cart') || '[]');
        window.addEventListener('DOMContentLoaded', function() {
            updateCartBadges();
        });
        let currentOrderCodeModal = '';
        let uploadedProofBase64 = '';

// ==========================================
        // LOGIK MODAL KERANJANG BELANJA
        // ==========================================
        function openCartModal() {
            renderCartModalList();
            const modal = document.getElementById('cart-modal');
            const card = document.getElementById('cart-modal-card');
            if (modal && card) {
                modal.classList.remove('opacity-0', 'pointer-events-none');
                card.classList.remove('scale-95');
            }
        }

        function closeCartModal() {
            const modal = document.getElementById('cart-modal');
            const card = document.getElementById('cart-modal-card');
            if (modal && card) {
                card.classList.add('scale-95');
                modal.classList.add('opacity-0', 'pointer-events-none');
            }
        }

        function renderCartModalList() {
            const container = document.getElementById('cart-modal-items-container');
            const emptyState = document.getElementById('cart-modal-empty');
            const checkoutBtn = document.getElementById('btn-modal-checkout');
            const itemBadge = document.getElementById('cart-modal-item-badge');

            if (!container) return;

            container.innerHTML = '';
            if (itemBadge) itemBadge.innerText = `${cartItems.length} Item`;

            if (cartItems.length === 0) {
                container.classList.add('hidden');
                if (emptyState) emptyState.classList.remove('hidden');
                if (checkoutBtn) {
                    checkoutBtn.disabled = true;
                    checkoutBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                const subtotalEl = document.getElementById('cart-modal-subtotal');
                const totalEl = document.getElementById('cart-modal-total');
                if (subtotalEl) subtotalEl.innerText = 'Rp 0';
                if (totalEl) totalEl.innerText = 'Rp 0';
                return;
            }

            container.classList.remove('hidden');
            if (emptyState) emptyState.classList.add('hidden');
            if (checkoutBtn) {
                checkoutBtn.disabled = false;
                checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }

            let subtotal = 0;

            cartItems.forEach((item, index) => {
                const itemTotal = item.price * item.qty;
                subtotal += itemTotal;

                const typeBadge = item.type === 'minuman'
                    ? `<span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase">🥤 Minuman</span>`
                    : `<span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase">🍜 Makanan</span>`;

                const notesHtml = item.notes && item.notes.trim() !== ''
                    ? `<p class="text-[10px] text-brand-300 italic bg-slate-950/80 px-2 py-0.5 rounded border border-slate-800 mt-1">"${item.notes}"</p>`
                    : '';

                const card = document.createElement('div');
                card.className = "bg-slate-950/60 border border-slate-800 rounded-xl p-3 flex items-center justify-between gap-3";
                card.innerHTML = `
                    <div class="flex items-center gap-3">
                        <img src="${item.image}" alt="${item.name}" class="w-14 h-14 rounded-lg object-cover bg-slate-800 border border-slate-800 shrink-0">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-1.5">
                                ${typeBadge}
                                <span class="text-[10px] text-slate-400 font-semibold">${item.variant}</span>
                            </div>
                            <h4 class="text-xs font-bold text-white line-clamp-1">${item.name}</h4>
                            <p class="text-xs font-extrabold text-emerald-400">${formatRupiah(item.price)}</p>
                            ${notesHtml}
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center bg-slate-900 border border-slate-800 rounded-lg p-0.5">
                            <button onclick="changeModalCartQty(${index}, -1)" class="w-6 h-6 rounded bg-slate-800 text-white text-xs font-bold flex items-center justify-center hover:bg-slate-700">-</button>
                            <span class="w-6 text-center text-xs font-extrabold">${item.qty}</span>
                            <button onclick="changeModalCartQty(${index}, 1)" class="w-6 h-6 rounded bg-slate-800 text-white text-xs font-bold flex items-center justify-center hover:bg-slate-700">+</button>
                        </div>
                        <button onclick="removeModalCartItem(${index})" title="Hapus" class="w-7 h-7 rounded-lg bg-rose-500/10 text-rose-400 border border-rose-500/20 flex items-center justify-center hover:bg-rose-500/20">
                            <i class="fa-solid fa-trash-can text-xs"></i>
                        </button>
                    </div>
                `;
                container.appendChild(card);
            });

            const subtotalEl = document.getElementById('cart-modal-subtotal');
            const totalEl = document.getElementById('cart-modal-total');
            if (subtotalEl) subtotalEl.innerText = formatRupiah(subtotal);
            if (totalEl) totalEl.innerText = formatRupiah(subtotal);
        }

        function changeModalCartQty(index, delta) {
            if (cartItems[index]) {
                cartItems[index].qty += delta;
                if (cartItems[index].qty <= 0) {
                    cartItems.splice(index, 1);
                }
                localStorage.setItem('cyberbite_cart', JSON.stringify(cartItems));
                renderCartModalList();
                updateCartBadges();
            }
        }

        function removeModalCartItem(index) {
            if (cartItems[index]) {
                cartItems.splice(index, 1);
                localStorage.setItem('cyberbite_cart', JSON.stringify(cartItems));
                renderCartModalList();
                updateCartBadges();
                showToast("Item dihapus dari keranjang");
            }
        }

        function clearCartFromModal() {
            if (cartItems.length === 0) return;
            cartItems = [];
            localStorage.setItem('cyberbite_cart', JSON.stringify(cartItems));
            renderCartModalList();
            updateCartBadges();
            showToast("Keranjang dikosongkan");
        }

        function proceedToPaymentFromModal() {
            if (cartItems.length === 0) return;
            window.location.href = 'pembayaran.php';
        }

        function updateCartBadges() {
            const totalCount = cartItems.length;
            const badge = document.getElementById('cart-header-badge');
            if (badge) badge.innerText = totalCount;
        }

        // ==========================================
        // LOGIK MODAL SESI PEMBAYARAN & UNGGAH BUKTI
        // ==========================================
        function openPaymentSessionModal(orderCode, method, vaBank, totalAmount, existingProof = '') {
            currentOrderCodeModal = orderCode;
            uploadedProofBase64 = existingProof;

            const modal = document.getElementById('payment-session-modal');
            const card = document.getElementById('payment-modal-card');
            const icon = document.getElementById('session-modal-icon');
            const title = document.getElementById('session-modal-title');
            const subtitle = document.getElementById('session-modal-subtitle');
            const body = document.getElementById('session-modal-body');
            const total = document.getElementById('session-modal-total');

            subtitle.innerText = `Kode Transaksi: ${orderCode}`;
            total.innerText = formatRupiah(totalAmount);

            // Preview jika sudah ada bukti terunggah sebelumnya
            const prevContainer = document.getElementById('proof-preview-container');
            const prevImg = document.getElementById('proof-preview-img');
            if (existingProof) {
                prevImg.src = existingProof;
                prevContainer.classList.remove('hidden');
            } else {
                prevContainer.classList.add('hidden');
                prevImg.src = '';
            }

            if (method === 'qris') {
                icon.className = "fa-solid fa-qrcode text-base text-amber-400";
                title.innerText = "Sesi Pembayaran QRIS Instant";

                body.innerHTML = `
                    <div class="text-center space-y-3">
                        <p class="text-xs text-slate-300">Scan QR Code di bawah menggunakan E-Wallet (GoPay, OVO, DANA, ShopeePay) atau M-Banking Anda:</p>
                        <div class="bg-white p-4 rounded-2xl inline-block border-4 border-slate-800 shadow-inner">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=WARNET-${orderCode}-${totalAmount}" alt="QRIS Barcode" class="w-44 h-44 mx-auto">
                        </div>
                        <div class="bg-amber-500/10 border border-amber-500/20 text-amber-400 text-[10px] p-2.5 rounded-xl font-medium">
                            <i class="fa-solid fa-clock mr-1"></i> Sesi QRIS berlaku selama 15 menit. Setelah bayar, unggah foto struk/screenshot di bawah.
                        </div>
                    </div>
                `;
            } else if (method === 'va') {
                icon.className = "fa-solid fa-building-columns text-base text-purple-400";
                title.innerText = `Virtual Account (${vaBank})`;

                const vaNum = "8801" + orderCode.replace('WRN-', '') + "99";

                body.innerHTML = `
                    <div class="space-y-3">
                        <p class="text-xs text-slate-300">Transfer ke nomor Virtual Account ${vaBank} di bawah ini melalui M-Banking atau ATM:</p>
                        
                        <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 space-y-1">
                            <span class="text-[10px] text-slate-400 font-bold uppercase block">Nomor Virtual Account ${vaBank}:</span>
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-base font-extrabold text-brand-400 tracking-wider">${vaNum}</span>
                                <button onclick="copyToClipboard('${vaNum}')" class="bg-brand-600/20 hover:bg-brand-600 text-brand-300 hover:text-white border border-brand-500/30 text-[10px] font-bold px-2.5 py-1 rounded-lg transition active:scale-95">
                                    <i class="fa-solid fa-copy mr-1"></i> Salin
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }

            modal.classList.remove('opacity-0', 'pointer-events-none');
            card.classList.remove('scale-95');
        }

        function closePaymentSessionModal() {
            const modal = document.getElementById('payment-session-modal');
            const card = document.getElementById('payment-modal-card');
            card.classList.add('scale-95');
            modal.classList.add('opacity-0', 'pointer-events-none');
        }

        function previewProofImage(event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                uploadedProofBase64 = e.target.result;
                const prevContainer = document.getElementById('proof-preview-container');
                const prevImg = document.getElementById('proof-preview-img');
                prevImg.src = uploadedProofBase64;
                prevContainer.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }

        async function submitPaymentProof() {
            if (!uploadedProofBase64) {
                alert("Silakan pilih foto bukti pembayaran terlebih dahulu!");
                return;
            }

            const btn = document.getElementById('btn-submit-proof');
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner animate-spin"></i> Mengirim Bukti...`;

            const formData = new FormData();
            formData.append('action', 'upload_payment_proof');
            formData.append('order_code', currentOrderCodeModal);
            formData.append('proof_img', uploadedProofBase64);

            try {
                const response = await fetch('sesi-pembayaran.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.success) {
                    showToast(res.message);
                    setTimeout(() => location.reload(), 1200);
                } else {
                    alert('Gagal mengirim bukti: ' + res.message);
                    btn.disabled = false;
                    btn.innerHTML = `<i class="fa-solid fa-paper-plane mr-1"></i> Upload Bukti Pembayaran`;
                }
            } catch (err) {
                alert('Koneksi terganggu saat mengunggah!');
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-paper-plane mr-1"></i> Upload Bukti Pembayaran`;
            }
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text);
            showToast("Nomor VA berhasil disalin!");
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
