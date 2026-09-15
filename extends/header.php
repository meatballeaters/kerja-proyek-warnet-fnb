<?php
require_once file_exists('config.php') ? 'config.php' : 'c.php';

// Deteksi otomatis PC Client berdasarkan IP
$active_pc = getActiveClientPC($pdo);

// Deteksi file halaman aktif untuk penanda navigasi (Active Nav Item)
$current_page = basename($_SERVER['PHP_SELF']);

// Penentu kondisi halaman aktif berdasarkan nama file
$is_status_page  = in_array($current_page, ['sesi-pembayaran.php', 'status.php']);
$is_makanan_page = in_array($current_page, ['index.php', 'makanan.php', 'index.php']);
$is_minuman_page = in_array($current_page, ['index-2.php', 'minuman.php']);

try {
    // 1. Fetch Kategori & Produk Makanan
    $stmt_cat_food = $pdo->query("SELECT * FROM categories WHERE type = 'makanan' ORDER BY sort_order ASC");
    $food_categories = $stmt_cat_food->fetchAll();

    $stmt_food = $pdo->query("
        SELECT p.id, p.category_id, c.slug AS category_slug, p.name, p.price, p.image_url AS image, p.description 
        FROM products p 
        JOIN categories c ON p.category_id = c.id 
        WHERE p.type = 'makanan' AND p.is_available = 1 
        ORDER BY p.id ASC
    ");
    $food_products = $stmt_food->fetchAll();

    // 2. Fetch Kategori & Produk Minuman
    $stmt_cat_drink = $pdo->query("SELECT * FROM categories WHERE type = 'minuman' ORDER BY sort_order ASC");
    $drink_categories = $stmt_cat_drink->fetchAll();

    $stmt_drink = $pdo->query("
        SELECT p.id, p.category_id, c.slug AS category_slug, p.name, p.price, p.image_url AS image, p.description 
        FROM products p 
        JOIN categories c ON p.category_id = c.id 
        WHERE p.type = 'minuman' AND p.is_available = 1 
        ORDER BY p.id ASC
    ");
    $drink_products = $stmt_drink->fetchAll();

    // 3. Fetch Riwayat Pesanan Terakhir untuk PC Client Aktif
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
    $food_categories = [];
    $food_products = [];
    $drink_categories = [];
    $drink_products = [];
    $orders_with_items = [];
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CyberBite - Panel Pelanggan Warnet</title>
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
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen pb-28 antialiased selection:bg-brand-500 selection:text-white">
    <header class="sticky top-0 z-50 bg-slate-900/95 backdrop-blur-md border-b border-slate-800 px-6 py-3 shadow-xl">
        <div class="max-w-7xl mx-auto flex items-center justify-between gap-4">
            
            <!-- KIRI: Brand & Identitas PC Pelanggan dari Database -->
            <div class="flex items-center gap-4">
                <a href="index.php" class="flex items-center gap-2 group">
                    <div class="bg-gradient-to-tr from-brand-600 to-indigo-600 p-2.5 rounded-xl shadow-md shadow-brand-500/20 text-white shrink-0 group-hover:scale-105 transition-transform">
                        <i class="fa-solid fa-gamepad text-base"></i>
                    </div>
                    <span class="text-base font-extrabold text-white tracking-wide hidden sm:inline-block">CYBERBITE <span class="text-brand-400">F&B</span></span>
                </a>
                
                <div class="h-6 w-px bg-slate-800 hidden sm:block"></div>
                
                <!-- Card Status PC Client -->
                <div class="flex items-center gap-3 bg-slate-950/60 border border-slate-800 px-3 py-1.5 rounded-xl">
                    <div class="relative text-brand-400">
                        <i class="fa-solid fa-desktop text-sm"></i>
                        <span class="absolute -top-1 -right-1 flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-extrabold text-white tracking-wide"><?= htmlspecialchars($active_pc['pc_name']) ?></span>
                            <span class="text-slate-600 text-xs">•</span>
                            <span class="text-xs text-brand-400 font-semibold font-mono"><?= htmlspecialchars($active_pc['active_voucher']) ?></span>
                        </div>
                        <span class="text-[9px] text-emerald-400 font-medium block">Sesi Komputer Aktif</span>
                    </div>
                </div>
            </div>

            <!-- KANAN: Group Tombol Navigasi Header -->
            <div class="flex items-center gap-2">
                <!-- 1. IKON STATUS PESANAN (Aktif jika di sesi-pembayaran.php) -->
                <a href="sesi-pembayaran.php" title="Cek Status Pesanan" class="flex items-center gap-2 <?= $is_status_page ? 'bg-brand-600 text-white border-brand-500 shadow-md shadow-brand-600/30' : 'bg-slate-800 hover:bg-slate-700 border-slate-700 text-slate-200' ?> border px-3.5 py-2 rounded-xl text-xs font-bold transition-all shadow-sm active:scale-95">
                    <i class="fa-solid fa-clock-rotate-left text-xs <?= $is_status_page ? 'text-white' : 'text-brand-400' ?>"></i>
                    <span class="hidden md:inline-block">Status Pesanan</span>
                </a>

                <!-- 2. TOMBOL KERANJANG BELANJA MODAL -->
                <button onclick="openCartModal()" title="Keranjang Belanja" class="relative bg-slate-800 hover:bg-slate-700 active:scale-95 border border-slate-700 px-3 py-2 rounded-xl flex items-center gap-2 text-slate-200 transition-all shadow-sm">
                    <i class="fa-solid fa-basket-shopping text-sm text-brand-400"></i>
                    <span class="text-xs font-bold hidden md:inline-block">Keranjang</span>
                    <span id="cart-header-badge" class="bg-brand-500 text-slate-950 font-extrabold text-[10px] px-1.5 py-0.5 rounded-full border border-slate-900 shadow"></span>
                </button>

                <!-- 3. NAVIGASI DINAMIS MAKANAN & MINUMAN -->
                <?php if ($is_makanan_page): ?>
                    <!-- Jika sedang di Halaman Makanan, Tampilkan HANYA Tombol Menu Minuman -->
                    <a href="index-2.php" class="flex items-center gap-2 bg-slate-800 hover:bg-slate-700 active:scale-95 border border-slate-700 text-slate-200 border px-3.5 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
                        <span>Menu Minuman</span>
                        <i class="fa-solid fa-wine-glass text-cyan-400 text-xs"></i>
                    </a>
                <?php elseif ($is_minuman_page): ?>
                    <!-- Jika sedang di Halaman Minuman, Tampilkan HANYA Tombol Menu Makanan -->
                    <a href="index.php" class="flex items-center gap-2 bg-slate-800 hover:bg-slate-700 active:scale-95 border border-slate-700 text-slate-200 border px-3.5 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
                        <span>Menu Makanan</span>
                        <i class="fa-solid fa-utensils text-amber-400 text-xs"></i>
                    </a>
                <?php else: ?>
                    <!-- Jika sedang di Halaman Lain (misal Status Pesanan / Pembayaran), Tampilkan Keduanya -->
                    <a href="index.php" class="flex items-center gap-2 bg-slate-800 hover:bg-slate-700 active:scale-95 border border-slate-700 text-slate-200 border px-3.5 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
                        <i class="fa-solid fa-utensils text-amber-400 text-xs"></i>
                        <span class="hidden md:inline-block">Menu Makanan</span>
                    </a>
                    <a href="index-2.php" class="flex items-center gap-2 bg-slate-800 hover:bg-slate-700 active:scale-95 border border-slate-700 text-slate-200 border px-3.5 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
                        <i class="fa-solid fa-wine-glass text-cyan-400 text-xs"></i>
                        <span class="hidden md:inline-block">Menu Minuman</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>