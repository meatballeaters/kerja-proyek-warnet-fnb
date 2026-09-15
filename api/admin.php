<?php
require_once file_exists('config.php') ? 'config.php' : 'c.php';

// -------------------------------------------------------------------
// 1. AJAX REAL-TIME POLLING ENDPOINT FOR DASHBOARD LIVE DATA
// -------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_realtime_data') {
    header('Content-Type: application/json');
    try {
        // Fetch All Valid Stats across ALL history (Non-cancelled orders)
        $stmt_today = $pdo->query("SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_amount), 0) AS total_revenue FROM orders WHERE order_status != 'cancelled'");
        $stats_today = $stmt_today->fetch();

        // Fetch Pending Stats
        $stmt_pending = $pdo->query("SELECT COUNT(*) AS pending_count FROM orders WHERE order_status = 'pending'");
        $pending_stats = $stmt_pending->fetch();

        // Fetch Raw Orders from ALL Customers across history (Latest first)
        $stmt_all_orders = $pdo->query("SELECT * FROM orders ORDER BY id DESC");
        $raw_orders = $stmt_all_orders->fetchAll();

        $orders_list = [];
        foreach ($raw_orders as $o) {
            $stmt_items = $pdo->prepare("SELECT product_name as name, qty, variant, notes, price FROM order_items WHERE order_id = ?");
            $stmt_items->execute([$o['id']]);
            $items = $stmt_items->fetchAll();

            $orders_list[] = [
                'id' => (int)$o['id'],
                'orderCode' => $o['order_code'],
                'pcName' => $o['pc_name'],
                'voucher' => $o['voucher_code'] ?? 'VOUCHER-AUTO',
                'time' => date('H:i', strtotime($o['created_at'])),
                'date' => date('d/m/Y', strtotime($o['created_at'])),
                'rawDate' => date('Y-m-d', strtotime($o['created_at'])),
                'items' => $items,
                'totalAmount' => (float)$o['total_amount'],
                'paymentMethod' => $o['payment_method'],
                'paymentStatus' => $o['payment_status'] ?? 'unpaid',
                'paymentProof' => $o['payment_proof'] ?? null,
                'status' => $o['order_status']
            ];
        }

        // Fetch All Products for Admin Catalog
        $stmt_all_prods = $pdo->query("
            SELECT p.id, p.name, c.name as category, p.price, p.is_available, p.image_url as image, p.type, p.description 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            ORDER BY p.id DESC
        ");
        $products_list = $stmt_all_prods->fetchAll();

        // Fetch Customers Activity
        $stmt_all_cust = $pdo->query("
            SELECT pc.pc_name, pc.ip_address, pc.active_voucher, pc.is_online,
                   COUNT(o.id) as total_orders,
                   COALESCE(SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END), 0) as total_spent
            FROM pc_clients pc
            LEFT JOIN orders o ON pc.pc_name = o.pc_name
            GROUP BY pc.id
            ORDER BY pc.pc_name ASC
        ");
        $customers_list = $stmt_all_cust->fetchAll();

        echo json_encode([
            'success' => true,
            'totalOrders' => (int)($stats_today['total_orders'] ?? 0),
            'totalRevenue' => (float)($stats_today['total_revenue'] ?? 0),
            'pendingCount' => (int)($pending_stats['pending_count'] ?? 0),
            'orders' => $orders_list,
            'products' => $products_list,
            'customers' => $customers_list
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------
// 2. FORMAL EXPORT CSV HANDLER
// -------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Laporan_Penjualan_Resmi_CyberBite_'.date('Y-m-d').'.csv');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['========================================================================================']);
    fputcsv($output, ['LAPORAN PENJUALAN RESMI F&B - CYBERBITE WARNET & ESPORTS ARENA']);
    fputcsv($output, ['Tanggal Ekspor:', date('d F Y H:i:s')]);
    fputcsv($output, ['========================================================================================']);
    fputcsv($output, []);
    
    fputcsv($output, ['No', 'ID Transaksi', 'Kode Nota', 'Komputer PC', 'Voucher', 'Rincian Menu Dipesan', 'Total Tagihan (Rp)', 'Metode Bayar', 'Status Bayar', 'Status Pesanan', 'Tanggal & Waktu']);

    $stmt = $pdo->query("SELECT * FROM orders ORDER BY id DESC");
    $no = 1;
    $grandTotal = 0;

    while ($o = $stmt->fetch()) {
        $stmt_items = $pdo->prepare("SELECT product_name, qty, variant FROM order_items WHERE order_id = ?");
        $stmt_items->execute([$o['id']]);
        $items = $stmt_items->fetchAll();
        
        $itemStr = [];
        foreach ($items as $it) {
            $itemStr[] = $it['qty'] . 'x ' . $it['product_name'] . ' (' . $it['variant'] . ')';
        }
        $itemFormatted = implode('; ', $itemStr);

        if ($o['order_status'] !== 'cancelled') {
            $grandTotal += (float)$o['total_amount'];
        }

        fputcsv($output, [
            $no++,
            $o['id'],
            $o['order_code'],
            $o['pc_name'],
            $o['voucher_code'] ?? 'N/A',
            $itemFormatted,
            number_format((float)$o['total_amount'], 2, '.', ''),
            strtoupper($o['payment_method']),
            strtoupper($o['payment_status'] ?? 'unpaid'),
            strtoupper($o['order_status']),
            $o['created_at']
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['', '', '', '', '', 'TOTAL OMSET PENJUALAN (VALID):', number_format($grandTotal, 2, '.', ''), '', '', '', '']);
    fclose($output);
    exit;
}

// -------------------------------------------------------------------
// 3. POST ACTIONS HANDLER (UPDATE STATUS, TOGGLE STOK, ADD PRODUCT)
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        $action = $_POST['action'];

        if ($action === 'update_status') {
            $order_id = (int)$_POST['order_id'];
            $new_status = $_POST['status'];
            $confirm_pay = isset($_POST['confirm_payment']) ? (int)$_POST['confirm_payment'] : 0;

            if ($confirm_pay === 1) {
                $stmt = $pdo->prepare("UPDATE orders SET order_status = ?, payment_status = 'paid' WHERE id = ?");
                $stmt->execute([$new_status, $order_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
                $stmt->execute([$new_status, $order_id]);
            }

            if ($new_status === 'cancelled' && !empty($_POST['cancel_reason'])) {
                $stmt_c = $pdo->prepare("INSERT INTO order_cancellations (order_id, reason) VALUES (?, ?)");
                $stmt_c->execute([$order_id, $_POST['cancel_reason']]);
            }

            echo json_encode(['success' => true, 'message' => "Status pesanan ID #{$order_id} berhasil diperbarui!"]);
            exit;
        }

        if ($action === 'confirm_payment') {
            $order_id = (int)$_POST['order_id'];
            $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?");
            $stmt->execute([$order_id]);

            echo json_encode(['success' => true, 'message' => "Pembayaran pesanan ID #{$order_id} berhasil dikonfirmasi LUNAS!"]);
            exit;
        }

        if ($action === 'toggle_stock') {
            $product_id = (int)$_POST['product_id'];
            $is_available = (int)$_POST['is_available'];
            $stmt = $pdo->prepare("UPDATE products SET is_available = ? WHERE id = ?");
            $stmt->execute([$is_available, $product_id]);

            echo json_encode(['success' => true, 'message' => 'Status stok produk berhasil diperbarui!']);
            exit;
        }

        if ($action === 'add_product') {
            $name = $_POST['name'] ?? '';
            $type = $_POST['type'] ?? 'makanan';
            $price = (float)($_POST['price'] ?? 0);
            $desc = $_POST['description'] ?? '';
            $img = $_POST['image_url'] ?? '';

            $cat_stmt = $pdo->prepare("SELECT id FROM categories WHERE type = ? LIMIT 1");
            $cat_stmt->execute([$type]);
            $cat = $cat_stmt->fetch();
            $category_id = $cat['id'] ?? 1;

            $stmt = $pdo->prepare("INSERT INTO products (category_id, name, type, price, description, image_url, is_available) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$category_id, $name, $type, $price, $desc, $img]);

            echo json_encode(['success' => true, 'message' => "Produk \"{$name}\" berhasil ditambahkan ke katalog!"]);
            exit;
        }

        if ($action === 'edit_product') {
            $product_id = (int)$_POST['product_id'];
            $name = $_POST['name'] ?? '';
            $type = $_POST['type'] ?? 'makanan';
            $price = (float)($_POST['price'] ?? 0);
            $desc = $_POST['description'] ?? '';
            $img = $_POST['image_url'] ?? '';

            $cat_stmt = $pdo->prepare("SELECT id FROM categories WHERE type = ? LIMIT 1");
            $cat_stmt->execute([$type]);
            $cat = $cat_stmt->fetch();
            $category_id = $cat['id'] ?? 1;

            $stmt = $pdo->prepare("UPDATE products SET category_id = ?, name = ?, type = ?, price = ?, description = ?, image_url = ? WHERE id = ?");
            $stmt->execute([$category_id, $name, $type, $price, $desc, $img, $product_id]);

            echo json_encode(['success' => true, 'message' => "Produk \"{$name}\" berhasil diperbarui!"]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------
// 4. FETCH REAL DATA FROM MYSQL FOR INITIAL PAGE LOAD
// -------------------------------------------------------------------
try {
    // Menghitung statistik transaksi valid
    $stmt_today = $pdo->query("SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_amount), 0) AS total_revenue FROM orders WHERE order_status != 'cancelled'");
    $stats_today = $stmt_today->fetch();

    $stmt_pending = $pdo->query("SELECT COUNT(*) AS pending_count FROM orders WHERE order_status = 'pending'");
    $pending_stats = $stmt_pending->fetch();

    $stmt_shift = $pdo->query("SELECT * FROM cashier_shifts WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $active_shift = $stmt_shift->fetch();

    $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $sys_settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt_cats = $pdo->query("SELECT * FROM categories ORDER BY id ASC");
    $all_categories = $stmt_cats->fetchAll();

    // Fetch Orders
    $stmt_all_orders = $pdo->query("SELECT * FROM orders ORDER BY id DESC");
    $raw_orders = $stmt_all_orders->fetchAll();

    $db_orders_data = [];
    foreach ($raw_orders as $o) {
        $stmt_items = $pdo->prepare("SELECT product_name as name, qty, variant, notes, price FROM order_items WHERE order_id = ?");
        $stmt_items->execute([$o['id']]);
        $items = $stmt_items->fetchAll();

        $db_orders_data[] = [
            'id' => (int)$o['id'],
            'orderCode' => $o['order_code'],
            'pcName' => $o['pc_name'],
            'voucher' => $o['voucher_code'] ?? 'VOUCHER-AUTO',
            'time' => date('H:i', strtotime($o['created_at'])),
            'date' => date('d/m/Y', strtotime($o['created_at'])),
            'rawDate' => date('Y-m-d', strtotime($o['created_at'])),
            'items' => $items,
            'totalAmount' => (float)$o['total_amount'],
            'paymentMethod' => $o['payment_method'],
            'paymentStatus' => $o['payment_status'] ?? 'unpaid',
            'paymentProof' => $o['payment_proof'] ?? null,
            'status' => $o['order_status']
        ];
    }

    // Fetch Products
    $stmt_all_prods = $pdo->query("
        SELECT p.id, p.name, c.name as category, p.price, p.is_available, p.image_url as image, p.type, p.description 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.id DESC
    ");
    $db_products_data = $stmt_all_prods->fetchAll();

    // Fetch Customers
    $stmt_all_cust = $pdo->query("
        SELECT pc.pc_name, pc.ip_address, pc.active_voucher, pc.is_online,
               COUNT(o.id) as total_orders,
               COALESCE(SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END), 0) as total_spent
        FROM pc_clients pc
        LEFT JOIN orders o ON pc.pc_name = o.pc_name
        GROUP BY pc.id
        ORDER BY pc.pc_name ASC
    ");
    $db_customers_data = $stmt_all_cust->fetchAll();

} catch (Exception $e) {
    $stats_today = ['total_orders' => 0, 'total_revenue' => 0];
    $pending_stats = ['pending_count' => 0];
    $top_pc = ['pc_name' => 'PC-01', 'order_cnt' => 0];
    $active_shift = ['operator_name' => 'Kasir (Admin)', 'shift_name' => 'Shift Aktif'];
    $sys_settings = ['billing_server_status' => 'connected', 'server_local_ip' => '127.0.0.1'];
    $db_orders_data = [];
    $db_products_data = [];
    $db_customers_data = [];
    $all_categories = [];
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CyberBite - Panel Admin & Kasir Warnet</title>
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
                            700: '#0e7490',
                            950: '#082f49'
                        }
                    }
                }
            }
        }
    </script>
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
            body { font-family: 'Plus Jakarta Sans', sans-serif; }
            .hide-scrollbar::-webkit-scrollbar { display: none; }
            .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

            @media print {
                /* 1. Atur Ukuran Kertas A4 & Hilangkan Margin Default Browser */
                @page {
                    size: A4 portrait;
                    margin: 0;
                }

                /* 2. Reset Total Body & Sembunyikan Semua Elemen Halaman */
                html, body {
                    width: 100% !important;
                    height: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    overflow: hidden !important;
                    background: #fff !important;
                }

                body * {
                    visibility: hidden !important;
                }

                /* 3. SETTING STRUK THERMAL POS (CETAK INDIVIDUAL) */
                body.print-receipt #printable-receipt, 
                body.print-receipt #printable-receipt * {
                    visibility: visible !important;
                }
                body.print-receipt #printable-receipt {
                    position: absolute !important;
                    left: 0 !important;
                    top: 0 !important;
                    width: 80mm !important;
                    padding: 4mm !important;
                    margin: 0 !important;
                    box-shadow: none !important;
                    page-break-after: avoid !important;
                    page-break-inside: avoid !important;
                }

                /* 4. SETTING LAPORAN FORMAL (CETAK KOMPILASI) */
                body.print-report #formal-report-paper, 
                body.print-report #formal-report-paper * {
                    visibility: visible !important;
                    color: #000 !important;
                }
                body.print-report #formal-report-paper {
                    position: absolute !important;
                    left: 0 !important;
                    top: 0 !important;
                    width: 100% !important;
                    box-sizing: border-box !important;
                    background: #fff !important;
                    padding: 12mm 15mm !important;
                    margin: 0 !important;
                    box-shadow: none !important;
                    border: none !important;
                    page-break-after: avoid !important;
                    page-break-inside: avoid !important;
                }

                body.print-report #formal-report-modal {
                    position: absolute !important;
                    left: 0 !important;
                    top: 0 !important;
                    width: 100% !important;
                    height: 100% !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    background: transparent !important;
                    overflow: hidden !important;
                }

                .no-print {
                    display: none !important;
                }
            }
        </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex antialiased selection:bg-brand-500 selection:text-white">

    <!-- SIDEBAR NAVIGASI KASIR / ADMIN -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col justify-between shrink-0 fixed h-full z-40">
        <div>
            <!-- Header Sidebar Brand -->
            <div class="p-5 border-b border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="bg-gradient-to-tr from-brand-600 to-indigo-600 p-2.5 rounded-xl text-white shadow-lg shadow-brand-500/20">
                        <i class="fa-solid fa-desktop text-base"></i>
                    </div>
                    <div>
                        <h1 class="font-extrabold text-sm text-white tracking-wide">CYBERBITE</h1>
                    </div>
                </div>
            </div>

            <!-- Identitas Shift Operator Active -->
            <div class="p-3 mx-3 my-4 bg-slate-950/80 border border-slate-800 rounded-xl flex items-center gap-3">
                <div class="relative">
                    <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100" class="w-9 h-9 rounded-lg object-cover border border-slate-700">
                    <span class="absolute -top-1 -right-1 flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                    </span>
                </div>
                <div class="truncate">
                    <span class="text-xs font-bold text-white block truncate"><?= htmlspecialchars($active_shift['operator_name'] ?? 'Kasir Shift 1 (Andi)') ?></span>
                    <span class="text-[9px] text-emerald-400 font-medium"><?= htmlspecialchars($active_shift['shift_name'] ?? 'Shift Pagi (08.00 - 16.00)') ?></span>
                </div>
            </div>

            <!-- Menu Navigasi Utama -->
            <nav class="px-3 space-y-1">
                <button onclick="switchTab('dashboard')" id="nav-dashboard" class="sidebar-link active w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all bg-brand-600 text-white shadow-md shadow-brand-600/30">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-chart-pie text-sm"></i>
                        <span>Dashboard</span>
                    </div>
                </button>

                <button onclick="switchTab('pesanan')" id="nav-pesanan" class="sidebar-link w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-bell-concierge text-sm"></i>
                        <span>Pesanan Masuk</span>
                    </div>
                    <span id="sidebar-pending-badge" class="bg-rose-500 text-white text-[10px] font-extrabold px-2 py-0.5 rounded-full font-mono animate-pulse"><?= (int)($pending_stats['pending_count'] ?? 0) ?></span>
                </button>

                <button onclick="switchTab('menu')" id="nav-menu" class="sidebar-link w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-utensils text-sm"></i>
                        <span>Kelola Menu</span>
                    </div>
                </button>

                <button onclick="switchTab('pelanggan')" id="nav-pelanggan" class="sidebar-link w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-users text-sm"></i>
                        <span>Data Pelanggan</span>
                    </div>
                </button>

                <button onclick="switchTab('laporan')" id="nav-laporan" class="sidebar-link w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-file-invoice-dollar text-sm"></i>
                        <span>Laporan Penjualan</span>
                    </div>
                </button>
            </nav>
        </div>

        <!-- Footer Sidebar Status System -->
        <div class="p-4 border-t border-slate-800 space-y-3">
            <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 text-[10px] space-y-1">
                <div class="flex justify-between items-center text-slate-400">
                    <span>Server Billing:</span>
                    <span class="text-emerald-400 font-bold"><?= ($sys_settings['billing_server_status'] ?? 'connected') === 'connected' ? 'Terhubung' : 'Terputus' ?></span>
                </div>
                <div class="flex justify-between items-center text-slate-400">
                    <span>IP Server Lokal:</span>
                    <span class="font-mono text-slate-300"><?= htmlspecialchars($sys_settings['server_local_ip'] ?? '127.0.0.1') ?></span>
                </div>
            </div>
            <a href="index.php" target="_blank" class="w-full bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-bold py-2 rounded-xl flex items-center justify-center gap-2 transition-all">
                <i class="fa-solid fa-external-link text-[10px]"></i>
                <span>Buka Tampilan PC Client</span>
            </a>
        </div>
    </aside>

    <!-- AREA KONTEN UTAMA (PANEL ADMIN) -->
    <div class="pl-64 flex-1 flex flex-col min-w-0">
        
        <!-- TOPBAR HEADER ADMIN -->
        <header class="sticky top-0 z-30 bg-slate-900/95 backdrop-blur-md border-b border-slate-800 px-8 py-3.5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <h2 id="topbar-title" class="text-lg font-extrabold text-white tracking-wide">Dashboard Operasional Warnet</h2>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="toggleAudioAlarm()" id="btn-audio-toggle" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-2 transition-all">
                    <i id="audio-icon" class="fa-solid fa-volume-high text-emerald-400"></i>
                    <span id="audio-label">Alarm</span>
                </button>

                <button onclick="pollRealtimeData(true)" title="Refresh Data Sekarang" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-2 transition-all">
                    <i class="fa-solid fa-rotate-right text-brand-400"></i>
                    <span>Refresh Data</span>
                </button>
            </div>
        </header>
        <main class="p-8 space-y-8 flex-1">
            <!-- TAB 1: DASHBOARD OVERVIEW -->
            <section id="tab-content-dashboard" class="tab-content space-y-6">                
                <!-- Stat Cards Row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl shadow-xl flex items-center justify-between gap-4">
                        <div class="space-y-1">
                            <span class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider block">Total Pesanan</span>
                            <span id="stat-total-orders" class="text-2xl font-black text-white"><?= number_format((int)($stats_today['total_orders'] ?? 0)) ?> Order</span>
                        </div>
                        <div class="bg-brand-500/10 border border-brand-500/20 text-brand-400 p-3.5 rounded-2xl"><i class="fa-solid fa-bag-shopping text-2xl"></i></div>
                    </div>

                    <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl shadow-xl flex items-center justify-between gap-4">
                        <div class="space-y-1">
                            <span class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider block">Total Omset Pendapatan</span>
                            <span id="stat-total-revenue" class="text-2xl font-black text-emerald-400">Rp <?= number_format((float)($stats_today['total_revenue'] ?? 0), 0, ',', '.') ?></span>
                        </div>
                        <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 p-3.5 rounded-2xl"><i class="fa-solid fa-wallet text-2xl"></i></div>
                    </div>

                    <div class="bg-slate-900 border border-rose-500/30 p-5 rounded-2xl shadow-xl flex items-center justify-between gap-4 relative overflow-hidden">
                        <div class="space-y-1 z-10">
                            <span class="text-[11px] font-extrabold text-rose-400 uppercase tracking-wider block">Butuh Tindakan</span>
                            <span id="stat-pending-orders" class="text-2xl font-black text-rose-400"><?= number_format((int)($pending_stats['pending_count'] ?? 0)) ?> Pending</span>
                        </div>
                        <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-3.5 rounded-2xl z-10"><i class="fa-solid fa-clock-rotate-left text-2xl animate-pulse"></i></div>
                    </div>
                </div>

                <!-- Dashboard Content Grid (Left: Order Status Breakdown, Right: Recent Orders) -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    <div class="lg:col-span-2 bg-slate-900 border border-slate-800 p-5 rounded-2xl shadow-xl space-y-4 min-w-0">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                            <div>
                                <h3 class="text-sm font-extrabold text-white">Ringkasan Operasional</h3>
                            </div>
                            <button onclick="switchTab('pesanan')" class="bg-brand-600/20 hover:bg-brand-600 text-brand-300 hover:text-white border border-brand-500/30 text-xs px-3 py-1.5 rounded-xl font-bold transition">Kelola Pesanan</button>
                        </div>

                        <!-- Mini Status Overview Cards -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-1">
                            <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 space-y-1">
                                <span class="text-[10px] font-bold text-amber-400 uppercase">🔥 Baru</span>
                                <span id="dash-cnt-pending" class="text-xl font-black text-white block">0</span>
                            </div>

                            <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 space-y-1">
                                <span class="text-[10px] font-bold text-cyan-400 uppercase">🍳 Diproses</span>
                                <span id="dash-cnt-preparing" class="text-xl font-black text-white block">0</span>
                            </div>

                            <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 space-y-1">
                                <span class="text-[10px] font-bold text-emerald-400 uppercase">✅ Pesanan Selesai</span>
                                <span id="dash-cnt-completed" class="text-xl font-black text-white block">0</span>
                            </div>

                            <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 space-y-1">
                                <span class="text-[10px] font-bold text-rose-400 uppercase">❌ Dibatalkan</span>
                                <span id="dash-cnt-cancelled" class="text-xl font-black text-white block">0</span>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-1 bg-slate-900 border border-slate-800 p-5 rounded-2xl shadow-xl space-y-4 flex flex-col justify-between min-w-0">
                        <div>
                            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                                <h3 class="text-sm font-extrabold text-white">Pesanan Terbaru</h3>
                            </div>
                            <div id="dashboard-recent-orders-list" class="space-y-3 pt-3"></div>
                        </div>
                        <div class="pt-3 border-t border-slate-800">
                            <button onclick="switchTab('pesanan')" class="w-full bg-brand-600/20 hover:bg-brand-600 text-brand-300 hover:text-white border border-brand-500/30 text-xs font-bold py-2.5 rounded-xl transition-all flex items-center justify-center gap-2">
                                <span>Buka Layar Manajemen Kasir</span>
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </section>

            <!-- TAB 2: PESANAN MASUK (LIVE ORDER MANAGER) -->
            <section id="tab-content-pesanan" class="tab-content hidden space-y-6">
                
                <div class="flex flex-col md:flex-row items-center justify-between gap-4 bg-slate-900 p-4 rounded-2xl border border-slate-800">
                    <div class="flex items-center gap-2 overflow-x-auto hide-scrollbar w-full md:w-auto">
                        <button onclick="filterOrderStatus('all')" class="order-status-tab active px-4 py-2 rounded-xl text-xs font-bold bg-brand-600 text-white shadow-md shadow-brand-600/30" data-status="all">Semua Pesanan</button>
                        <button onclick="filterOrderStatus('pending')" class="order-status-tab px-4 py-2 rounded-xl text-xs font-bold bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="pending">🔥  Baru</button>
                        <button onclick="filterOrderStatus('preparing')" class="order-status-tab px-4 py-2 rounded-xl text-xs font-bold bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="preparing">🍳 Diproses</button>
                        <button onclick="filterOrderStatus('completed')" class="order-status-tab px-4 py-2 rounded-xl text-xs font-bold bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="completed">✅ Selesai</button>
                        <button onclick="filterOrderStatus('cancelled')" class="order-status-tab px-4 py-2 rounded-xl text-xs font-bold bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="cancelled">❌ Dibatalkan</button>
                    </div>

                    <div class="relative w-full md:w-80">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="search-order-input" onkeyup="renderOrdersTable()" placeholder="Cari Kode Nota, PC, Voucher..." class="w-full bg-slate-950 text-xs pl-10 pr-4 py-2.5 rounded-xl border border-slate-800 focus:outline-none focus:border-brand-500">
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-950 text-slate-400 font-extrabold uppercase border-b border-slate-800 tracking-wider">
                                    <th class="p-4">Kode & Waktu</th>
                                    <th class="p-4">Komputer PC</th>
                                    <th class="p-4">Voucher PC</th>
                                    <th class="p-4">Item & Catatan</th>
                                    <th class="p-4">Total & Bayar</th>
                                    <th class="p-4">Status</th>
                                    <th class="p-4 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="orders-table-body" class="divide-y divide-slate-800/80 font-medium text-slate-200">
                                <!-- Injected via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>

            <!-- TAB 3: KELOLA MENU -->
            <section id="tab-content-menu" class="tab-content hidden space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-extrabold text-white">Katalog Makanan & Minuman</h3>
                    </div>
                    <button onclick="openProductModal()" class="bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-slate-950 font-extrabold text-xs px-4 py-2.5 rounded-xl shadow-lg active:scale-95 transition-all flex items-center gap-2">
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>Tambah Produk Baru</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-950 text-slate-400 font-extrabold uppercase border-b border-slate-800 tracking-wider">
                                    <th class="p-4">Foto</th>
                                    <th class="p-4">Nama</th>
                                    <th class="p-4">Kategori & Tipe</th>
                                    <th class="p-4">Harga</th>
                                    <th class="p-4">Status Stok</th>
                                    <th class="p-4 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="products-table-body" class="divide-y divide-slate-800/80 font-medium text-slate-200"></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- TAB 4: DATA PELANGGAN -->
            <section id="tab-content-pelanggan" class="tab-content hidden space-y-6">
                <div>
                    <h3 class="text-base font-extrabold text-white">Aktivitas Pelanggan & Billing</h3>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-950 text-slate-400 font-extrabold uppercase border-b border-slate-800 tracking-wider">
                                    <th class="p-4">Nomor PC</th>
                                    <th class="p-4">IP Address Lokal</th>
                                    <th class="p-4">Voucher Aktif</th>
                                    <th class="p-4">Total Frekuensi Order</th>
                                    <th class="p-4">Lifetime Value (Belanja F&B)</th>
                                    <th class="p-4">Status Komputer</th>
                                </tr>
                            </thead>
                            <tbody id="customers-table-body" class="divide-y divide-slate-800/80 font-medium text-slate-200"></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- TAB 5: LAPORAN PENJUALAN DASHBOARD LIST -->
            <section id="tab-content-laporan" class="tab-content hidden space-y-6">
                
                <!-- Action Controls Bar & Header -->
                <div class="flex flex-col md:flex-row items-center justify-between gap-4 bg-slate-900 p-4 rounded-2xl border border-slate-800">
                    <div>
                        <h3 class="text-base font-extrabold text-white">Daftar Rekapitulasi Laporan Penjualan</h3>
                    </div>

                    <div class="flex items-center gap-2">
                        <a href="admin.php?action=export_csv" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 px-3.5 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all">
                            <i class="fa-solid fa-file-excel text-emerald-400"></i>
                            <span>Ekspor Excel (CSV)</span>
                        </a>
                        <button onclick="openFormalReportPaperModal()" class="bg-brand-600 hover:bg-brand-500 text-white px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all shadow-md">
                            <i class="fa-solid fa-print"></i>
                            <span>Lihat Detil & Cetak Laporan Formal</span>
                        </button>
                    </div>
                </div>

                <!-- Stats Cards Summary for Reports -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider block">Total Omset Hari Ini</span>
                            <span id="report-dashboard-total-revenue" class="text-xl font-black text-emerald-400">Rp <?= number_format((float)($stats_today['total_revenue'] ?? 0), 0, ',', '.') ?></span>
                        </div>
                        <div class="bg-emerald-500/10 text-emerald-400 p-3 rounded-xl border border-emerald-500/20"><i class="fa-solid fa-money-bill-wave text-xl"></i></div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider block">Volume Transaksi Selesai</span>
                            <span id="report-dashboard-total-orders" class="text-xl font-black text-white"><?= number_format((int)($stats_today['total_orders'] ?? 0)) ?> Transaksi</span>
                        </div>
                        <div class="bg-brand-500/10 text-brand-400 p-3 rounded-xl border border-brand-500/20"><i class="fa-solid fa-receipt text-xl"></i></div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider block">Rata-Rata Transaksi</span>
                            <?php $aov = ($stats_today['total_orders'] > 0) ? ($stats_today['total_revenue'] / $stats_today['total_orders']) : 0; ?>
                            <span id="report-dashboard-aov" class="text-xl font-black text-amber-400">Rp <?= number_format($aov, 0, ',', '.') ?></span>
                        </div>
                        <div class="bg-amber-500/10 text-amber-400 p-3 rounded-xl border border-amber-500/20"><i class="fa-solid fa-chart-line text-xl"></i></div>
                    </div>
                </div>

                <!-- Table Daftar Laporan Masuk Admin -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-950 text-slate-400 font-extrabold uppercase border-b border-slate-800 tracking-wider">
                                    <th class="p-4">No</th>
                                    <th class="p-4">Kode Transaksi</th>
                                    <th class="p-4">Waktu</th>
                                    <th class="p-4">PC Client</th>
                                    <th class="p-4">Rincian Menu</th>
                                    <th class="p-4">Metode Bayar</th>
                                    <th class="p-4 text-right">Total Tagihan</th>
                                    <th class="p-4 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody id="reports-dashboard-table-body" class="divide-y divide-slate-800/80 font-medium text-slate-200">
                                <!-- Injected via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>

        </main>
    </div>

    <!-- MODAL POPUP FORMAL REPORT PRINT PAPER -->
    <div id="formal-report-modal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300 overflow-y-auto">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-4xl rounded-2xl p-6 shadow-2xl space-y-4 my-8">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3 no-print">
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-file-invoice-dollar text-brand-400"></i>
                    <span>Pratinjau Cetak Laporan Resmi</span>
                </h3>
                <div class="flex items-center gap-2">
                    <button onclick="printReportPDF()" class="bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold px-3.5 py-1.5 rounded-xl flex items-center gap-1.5 shadow">
                        <i class="fa-solid fa-print text-xs"></i> Cetak / Simpan PDF
                    </button>
                    <button onclick="closeFormalReportPaperModal()" class="text-slate-400 hover:text-white bg-slate-800 p-1.5 rounded-xl border border-slate-700"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>

            <!-- FORMAL PRINTABLE REPORT PAPER CARD -->
            <div id="formal-report-paper" class="bg-white text-slate-900 rounded-2xl p-8 shadow-2xl border border-slate-200 space-y-6 font-sans">
                <!-- Kop Surat Resmi -->
                <div class="border-b-2 border-slate-900 pb-4 flex justify-between items-start gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 bg-slate-900 text-white rounded-xl flex items-center justify-center text-2xl font-black shrink-0">
                            <i class="fa-solid fa-desktop"></i>
                        </div>
                        <div>
                            <h1 class="text-xl font-black text-slate-900 uppercase tracking-wide">CYBERBITE WARNET & ESPORTS ARENA</h1>
                            <p class="text-xs text-slate-600 font-medium">Jl. Cyber Gaming No. 88, Surabaya, Jawa Timur | Telp: (031) 555-8899</p>
                        </div>
                    </div>
                    <div class="text-right space-y-0.5">
                        <span class="bg-slate-100 border border-slate-300 text-slate-800 font-bold font-mono text-[10px] px-2.5 py-1 rounded-md uppercase block">DOKUMEN RESMI</span>
                        <span class="text-[11px] text-slate-600 font-mono block">No. Ref: REP-<?= date('Ymd-His') ?></span>
                        <span class="text-[11px] text-slate-600 block">Dicetak: <?= date('d/m/Y H:i') ?> WIB</span>
                    </div>
                </div>

                <!-- Judul Laporan -->
                <div class="text-center space-y-1">
                    <h2 class="text-base font-extrabold uppercase text-slate-900 tracking-wider">LAPORAN REKAPITULASI PENJUALAN MAKANAN & MINUMAN</h2>
                    <p class="text-xs text-slate-600 font-medium">Periode Operasional: <span class="font-bold text-slate-900"><?= date('d F Y') ?></span> | Operator Kasir: <span class="font-bold text-slate-900"><?= htmlspecialchars($active_shift['operator_name'] ?? 'Kasir Shift 1') ?></span></p>
                </div>

                <!-- Ringkasan Statistik Laporan -->
                <div class="grid grid-cols-3 gap-4 border border-slate-200 rounded-xl p-4 bg-slate-50 text-xs">
                    <div>
                        <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block">Total Omset Hari Ini</span>
                        <span id="report-formal-revenue" class="text-lg font-black text-slate-900">Rp <?= number_format((float)($stats_today['total_revenue'] ?? 0), 0, ',', '.') ?></span>
                    </div>
                    <div>
                        <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block">Total Volume Pesanan</span>
                        <span id="report-formal-orders" class="text-lg font-black text-slate-900"><?= number_format((int)($stats_today['total_orders'] ?? 0)) ?> Transaksi</span>
                    </div>
                    <div>
                        <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block">Rata-Rata Transaksi</span>
                        <span id="report-formal-aov" class="text-lg font-black text-slate-900">Rp <?= number_format($aov, 0, ',', '.') ?></span>
                    </div>
                </div>

                <!-- Tabel Rincian Seluruh Transaksi -->
                <div class="space-y-2">
                    <h3 class="text-xs font-bold uppercase text-slate-900 tracking-wider">Rincian Transaksi Masuk Hari Ini:</h3>
                    <table class="w-full text-left border-collapse text-[11px]">
                        <thead>
                            <tr class="bg-slate-200 text-slate-900 font-bold uppercase border-b-2 border-slate-400">
                                <th class="p-2 border border-slate-300">No</th>
                                <th class="p-2 border border-slate-300">Kode Nota</th>
                                <th class="p-2 border border-slate-300">Jam</th>
                                <th class="p-2 border border-slate-300">PC Client</th>
                                <th class="p-2 border border-slate-300">Rincian Menu</th>
                                <th class="p-2 border border-slate-300">Metode</th>
                                <th class="p-2 border border-slate-300 text-right">Total (Rp)</th>
                                <th class="p-2 border border-slate-300 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody id="report-table-body" class="divide-y divide-slate-300 font-medium text-slate-800">
                            <!-- Injected via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Kolom Lembar Pengesahan Tanda Tangan Formal -->
                <div class="pt-8 grid grid-cols-2 gap-8 text-center text-xs">
                    <div>
                        <p class="text-slate-600 font-medium">Dibuat Oleh Operator Kasir,</p>
                        <div class="h-16"></div>
                        <p class="font-bold underline text-slate-900"><?= htmlspecialchars($active_shift['operator_name'] ?? 'Kasir Operasional') ?></p>
                        <p class="text-[10px] text-slate-500">Kasir Shift Warnet</p>
                    </div>
                    <div>
                        <p class="text-slate-600 font-medium">Disetujui Oleh Supervisor/Owner,</p>
                        <div class="h-16"></div>
                        <p class="font-bold underline text-slate-900">( Manager / Owner CyberBite )</p>
                        <p class="text-[10px] text-slate-500">Penanggung Jawab Operasional</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL INSPEKSI BUKTI PEMBAYARAN KASIR -->
    <div id="proof-view-modal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-lg rounded-2xl p-6 shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-file-invoice-dollar text-brand-400"></i>
                    <span>Inspeksi Bukti Pembayaran Pelanggan</span>
                </h3>
                <button onclick="closeProofModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="space-y-3">
                <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 text-xs flex justify-between items-center">
                    <div>
                        <span id="proof-modal-code" class="font-extrabold text-brand-400 font-mono text-sm block"><?= $order['order_code'] ?></span>
                        <span id="proof-modal-pc" class="text-slate-400"><?= htmlspecialchars($active_pc['pc_name'] ?? 'PC tidak ditemukan') ?></span>
                    </div>
                    <span id="proof-modal-total" class="text-emerald-400 font-black text-base"><?= 'Rp ' . number_format($order['total_amount'], 0, ',', '.') ?></span>
                </div>

                <div class="bg-slate-950 p-2 rounded-xl border border-slate-800 text-center min-h-[220px] flex items-center justify-center">
                    <img id="proof-modal-image" src="" alt="Bukti Transfer" class="max-h-80 mx-auto rounded-lg object-contain">
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                <button onclick="closeProofModal()" class="bg-slate-800 text-slate-300 font-bold text-xs px-4 py-2.5 rounded-xl">Tutup</button>
                <button id="btn-proof-modal-confirm" onclick="confirmPaymentFromProofModal()" class="bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-extrabold text-xs px-5 py-2.5 rounded-xl shadow-lg flex items-center gap-2">
                    <i class="fa-solid fa-check"></i>
                    <span>Verifikasi Lunas & Terima</span>
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL STRUK CETAK THERMAL PRINTER -->
    <div id="print-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-sm rounded-2xl p-6 shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-print text-brand-400"></i>
                    <span>Cetak Struk Thermal POS</span>
                </h3>
                <button onclick="closePrintModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div id="printable-receipt" class="bg-white text-slate-900 p-4 rounded-lg font-mono text-[11px] leading-tight space-y-2 shadow-inner">
                <div class="text-center border-b border-dashed border-slate-400 pb-2">
                    <p class="font-bold text-sm">CYBERBITE WARNET F&B</p>
                    <p class="text-[9px] text-slate-600">Jl. Cyber Gaming No. 88 Warnet</p>
                    <p class="text-[9px] text-slate-600">--------------------------------</p>
                    <p id="receipt-order-code" class="font-bold">Nota: <?= $order['order_code'] ?></p>
                    <p id="receipt-date" class="text-[9px]">Tgl: <?= date('Y-m-d H:i') ?></p>
                </div>

                <div class="border-b border-dashed border-slate-400 pb-2 space-y-1">
                    <p id="receipt-pc" class="font-bold text-xs">Tujuan: <?= htmlspecialchars($active_pc['pc_name'] ?? 'PC tidak ditemukan') ?></p>
                    <div id="receipt-items-list" class="space-y-1 pt-1"></div>
                </div>

                <div class="space-y-1 pt-1">
                    <div class="flex justify-between font-bold">
                        <span>TOTAL BAYAR:</span>
                        <span id="receipt-total"><?= 'Rp ' . number_format($order['total_amount'], 0, ',', '.') ?></span>
                    </div>
                    <div class="flex justify-between text-[10px]">
                        <span>Metode:</span>
                        <span id="receipt-method"><?= $payment_method ?></span>
                    </div>
                </div>

                <div class="text-center pt-2 border-t border-dashed border-slate-400 text-[9px]">
                    <p>RESI DAPUR / KASIR</p>
                    <p>Terima kasih & Selamat Bermain!</p>
                </div>
            </div>

            <button onclick="executeReceiptPrint()" class="w-full bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-extrabold text-xs py-3 rounded-xl shadow-lg transition flex items-center justify-center gap-2">
                <i class="fa-solid fa-print"></i>
                <span>Cetak Struk Thermal</span>
            </button>
        </div>
    </div>

    <!-- MODAL TAMBAH PRODUK BARU -->
    <div id="product-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-6 shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-plus text-emerald-400"></i>
                    <span>Tambah Makanan/Minuman Baru</span>
                </h3>
                <button onclick="closeProductModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form id="add-product-form" onsubmit="saveNewProduct(event)" class="space-y-3 text-xs">
                <div>
                    <label class="block text-slate-300 font-bold mb-1">Nama Produk:</label>
                    <input type="text" id="prod-name" required placeholder="Contoh: Indomie Goreng Telur Kornet" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-slate-300 font-bold mb-1">Tipe Katalog:</label>
                        <select id="prod-type" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 font-bold">
                            <option value="makanan">Makanan</option>
                            <option value="minuman">Minuman</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-300 font-bold mb-1">Harga Jual (Rp):</label>
                        <input type="number" id="prod-price" required placeholder="18000" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 font-bold">
                    </div>
                </div>

                <div>
                    <label class="block text-slate-300 font-bold mb-1">Deskripsi Singkat:</label>
                    <textarea id="prod-desc" rows="2" placeholder="Sajikan porsi hangat lengkap dengan topping..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-slate-300 font-bold mb-1">URL Gambar produk:</label>
                    <input type="url" id="prod-img" placeholder="https://..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 font-mono text-[10px]">
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" onclick="closeProductModal()" class="bg-slate-800 text-slate-300 font-bold px-4 py-2.5 rounded-xl">Batal</button>
                    <button type="submit" id="btn-save-prod" class="bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-extrabold px-5 py-2.5 rounded-xl shadow-lg">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDIT PRODUK -->
    <div id="edit-product-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-6 shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-800 pb-3">
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-brand-400"></i>
                    <span>Edit Makanan/Minuman</span>
                </h3>
                <button onclick="closeEditProductModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form id="edit-product-form" onsubmit="saveEditProduct(event)" class="space-y-3 text-xs">
                <input type="hidden" id="edit-prod-id">

                <div>
                    <label class="block text-slate-300 font-bold mb-1">Nama Produk:</label>
                    <input type="text" id="edit-prod-name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-slate-300 font-bold mb-1">Tipe Katalog:</label>
                        <select id="edit-prod-type" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 font-bold">
                            <option value="makanan">Makanan</option>
                            <option value="minuman">Minuman</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-300 font-bold mb-1">Harga Jual (Rp):</label>
                        <input type="number" id="edit-prod-price" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 font-bold">
                    </div>
                </div>

                <div>
                    <label class="block text-slate-300 font-bold mb-1">Deskripsi Singkat:</label>
                    <textarea id="edit-prod-desc" rows="2" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-slate-300 font-bold mb-1">URL Gambar Produk:</label>
                    <input type="url" id="edit-prod-img" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white focus:outline-none focus:border-brand-500 font-mono text-[10px]">
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" onclick="closeEditProductModal()" class="bg-slate-800 text-slate-300 font-bold px-4 py-2.5 rounded-xl">Batal</button>
                    <button type="submit" id="btn-save-edit-prod" class="bg-brand-600 hover:bg-brand-500 text-white font-extrabold px-5 py-2.5 rounded-xl shadow-lg">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL BATALKAN PESANAN -->
    <div id="cancel-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-6 shadow-2xl space-y-4">
            <h3 class="text-sm font-extrabold text-white flex items-center gap-2 text-rose-400">
                <i class="fa-solid fa-circle-xmark"></i>
                <span>Batalkan Pesanan <span id="cancel-order-code"><?= $order['order_code'] ?></span></span>
            </h3>
            <p class="text-xs text-slate-400">Pilih atau masukkan alasan pembatalan pesanan pelanggan:</p>            
            <div class="space-y-2 text-xs">
                <label class="flex items-center gap-2 bg-slate-950 p-2.5 rounded-xl border border-slate-800 cursor-pointer">
                    <input type="radio" name="cancel_reason" value="Stok Bahan Makanan Habis" checked class="accent-rose-500">
                    <span>Stok Bahan Makanan / Minuman Habis</span>
                </label>
                <label class="flex items-center gap-2 bg-slate-950 p-2.5 rounded-xl border border-slate-800 cursor-pointer">
                    <input type="radio" name="cancel_reason" value="Pelanggan Membatalkan dari PC" class="accent-rose-500">
                    <span>Pelanggan Membatalkan dari Komputer</span>
                </label>
                <label class="flex items-center gap-2 bg-slate-950 p-2.5 rounded-xl border border-slate-800 cursor-pointer">
                    <input type="radio" name="cancel_reason" value="Pembayaran Gagal" class="accent-rose-500">
                    <span>Pembayaran Gagal / Kadaluarsa</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button onclick="closeCancelModal()" class="bg-slate-800 text-slate-300 text-xs font-bold px-4 py-2.5 rounded-xl">Batal</button>
                <button onclick="confirmCancelOrder()" class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl">Konfirmasi Pembatalan</button>
            </div>
        </div>
    </div>

    <!-- TOAST NOTIFIKASI KASIR -->
    <div id="admin-toast" class="fixed top-5 right-5 z-50 bg-brand-500 text-slate-950 px-4 py-3 rounded-2xl font-bold text-xs shadow-2xl transition-all transform -translate-y-16 opacity-0 pointer-events-none flex items-center gap-3">
        <i class="fa-solid fa-bell text-base"></i>
        <span id="admin-toast-msg">Notifikasi Kasir</span>
    </div>

    <!-- JAVASCRIPT LOGIC REAL-TIME DATABASE ADMIN & AUTO-POLLING -->
    <script>
        let ordersData = <?= json_encode($db_orders_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let productsData = <?= json_encode($db_products_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let customersData = <?= json_encode($db_customers_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        let activeTab = 'dashboard';
        let isAudioEnabled = true;
        let selectedCancelOrderId = null;
        let selectedProofOrderId = null;
        let lastMaxOrderId = ordersData.length > 0 ? Math.max(...ordersData.map(o => o.id)) : 0;

        window.onload = function() {
            recalculateAndRenderStats();
            renderDashboardRecent();
            renderOrdersTable();
            renderProductsTable();
            renderCustomersTable();
            renderReportsDashboardTable();
            renderFormalReportPaperTable();

            // REAL-TIME AUTO POLLING: CEK DATA BARU SETIAP 0.5 DETIK
            setInterval(pollRealtimeData, 500);
        };

        function recalculateAndRenderStats() {
            // Akumulasi seluruh pesanan valid (non-cancelled) sepanjang riwayat database
            const validOrders = ordersData.filter(o => o.status !== 'cancelled');
            const totalOrders = validOrders.length;
            const totalRevenue = validOrders.reduce((sum, o) => sum + parseFloat(o.totalAmount || 0), 0);
            
            const pendingCount = ordersData.filter(o => o.status === 'pending').length;
            const preparingCount = ordersData.filter(o => o.status === 'preparing').length;
            const completedCount = ordersData.filter(o => o.status === 'completed').length;
            const cancelledCount = ordersData.filter(o => o.status === 'cancelled').length;

            const aov = totalOrders > 0 ? (totalRevenue / totalOrders) : 0;

            // Update Header & Dashboard Overview Stats (Tampilkan Seluruh Pendapatan Terdahulu + Baru)
            if (document.getElementById('stat-total-orders')) document.getElementById('stat-total-orders').innerText = `${totalOrders} Order`;
            if (document.getElementById('stat-total-revenue')) document.getElementById('stat-total-revenue').innerText = formatRupiah(totalRevenue);
            if (document.getElementById('stat-pending-orders')) document.getElementById('stat-pending-orders').innerText = `${pendingCount} Pending`;
            if (document.getElementById('sidebar-pending-badge')) document.getElementById('sidebar-pending-badge').innerText = pendingCount;

            // Update Mini Breakdown Cards
            if (document.getElementById('dash-cnt-pending')) document.getElementById('dash-cnt-pending').innerText = pendingCount;
            if (document.getElementById('dash-cnt-preparing')) document.getElementById('dash-cnt-preparing').innerText = preparingCount;
            if (document.getElementById('dash-cnt-completed')) document.getElementById('dash-cnt-completed').innerText = completedCount;
            if (document.getElementById('dash-cnt-cancelled')) document.getElementById('dash-cnt-cancelled').innerText = cancelledCount;

            // Update Laporan Penjualan (Tab 5) Cards
            if (document.getElementById('report-dashboard-total-revenue')) document.getElementById('report-dashboard-total-revenue').innerText = formatRupiah(totalRevenue);
            if (document.getElementById('report-dashboard-total-orders')) document.getElementById('report-dashboard-total-orders').innerText = `${totalOrders} Transaksi`;
            if (document.getElementById('report-dashboard-aov')) document.getElementById('report-dashboard-aov').innerText = formatRupiah(aov);

            // Update Formal Report Paper Modal Cards
            if (document.getElementById('report-formal-revenue')) document.getElementById('report-formal-revenue').innerText = formatRupiah(totalRevenue);
            if (document.getElementById('report-formal-orders')) document.getElementById('report-formal-orders').innerText = `${totalOrders} Transaksi`;
            if (document.getElementById('report-formal-aov')) document.getElementById('report-formal-aov').innerText = formatRupiah(aov);
        }

        // =======================================================
        // REAL-TIME AUTO POLLING & AUDIO NOTIFICATION CHIME
        // =======================================================
        async function pollRealtimeData(isManual = false) {
        try {
            const response = await fetch('admin.php?action=get_realtime_data');
            const data = await response.json();

            if (data.success) {
                const newOrders = data.orders;
                const newMaxId = newOrders.length > 0 ? Math.max(...newOrders.map(o => o.id)) : 0;

                // Ambil tanggal hari ini dalam format YYYY-MM-DD
                const now = new Date();
                const todayYMD = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

                // Cek jika ada pesanan baru DAN pesanan tersebut memang masuk HARI INI
                if (lastMaxOrderId > 0 && newMaxId > lastMaxOrderId) {
                    const newestOrder = newOrders.find(o => o.id === newMaxId);

                    if (newestOrder && newestOrder.rawDate === todayYMD) {
                        if (isAudioEnabled) {
                            playOrderNotificationSound();
                        }
                        showAdminToast(`🔥 PESANAN BARU MASUK! [${newestOrder.orderCode}] dari ${newestOrder.pcName}`);
                    }
                }
                
                // Lock ID terakhir agar sync awal/refresh tidak mentrigger notifikasi palsu
                lastMaxOrderId = newMaxId;

                ordersData = newOrders;
                if (data.products) productsData = data.products;
                if (data.customers) customersData = data.customers;

                recalculateAndRenderStats();
                renderDashboardRecent();
                renderOrdersTable(currentActiveFilterStatus());
                renderProductsTable();
                renderCustomersTable();
                renderReportsDashboardTable();
                renderFormalReportPaperTable();

                if (isManual) {
                    showAdminToast("Data berhasil diperbarui!");
                }
            }
        } catch (err) {
            console.log("Polling error:", err);
        }
    }

        function currentActiveFilterStatus() {
            const activeBtn = document.querySelector('.order-status-tab.active');
            return activeBtn ? activeBtn.dataset.status : 'all';
        }

        // SYNTHESIZED WEB AUDIO BELL SOUND CHIME
        function playOrderNotificationSound() {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return;
                const ctx = new AudioContext();
                
                const osc1 = ctx.createOscillator();
                const gain1 = ctx.createGain();
                osc1.type = 'sine';
                osc1.frequency.setValueAtTime(587.33, ctx.currentTime);
                gain1.gain.setValueAtTime(0.3, ctx.currentTime);
                gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
                osc1.connect(gain1);
                gain1.connect(ctx.destination);
                osc1.start();
                osc1.stop(ctx.currentTime + 0.6);

                setTimeout(() => {
                    const osc2 = ctx.createOscillator();
                    const gain2 = ctx.createGain();
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(880, ctx.currentTime);
                    gain2.gain.setValueAtTime(0.4, ctx.currentTime);
                    gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.8);
                    osc2.connect(gain2);
                    gain2.connect(ctx.destination);
                    osc2.start();
                    osc2.stop(ctx.currentTime + 0.8);
                }, 150);
            } catch(e) {}
        }

        function switchTab(tabName) {
            activeTab = tabName;
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.getElementById(`tab-content-${tabName}`).classList.remove('hidden');

            document.querySelectorAll('.sidebar-link').forEach(btn => {
                btn.className = "sidebar-link w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800";
            });

            const activeBtn = document.getElementById(`nav-${tabName}`);
            if (activeBtn) {
                activeBtn.className = "sidebar-link active w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all bg-brand-600 text-white shadow-md shadow-brand-600/30";
            }
        }

        function renderDashboardRecent() {
            const container = document.getElementById('dashboard-recent-orders-list');
            if (!container) return;
            container.innerHTML = '';

            // Filter HANYA pesanan yang masuk HARI INI
            const now = new Date();
            const todayYMD = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            const todayOrders = ordersData.filter(o => o.rawDate === todayYMD);

            if (todayOrders.length === 0) {
                container.innerHTML = `<p class="text-xs text-slate-500 text-center py-4 font-medium">Belum ada transaksi masuk hari ini</p>`;
                return;
            }

            todayOrders.slice(0, 4).forEach(order => {
                const card = document.createElement('div');
                card.className = "bg-slate-950/60 p-3 rounded-xl border border-slate-800/80 flex items-center justify-between gap-3 text-xs min-w-0";
                const itemNames = Array.isArray(order.items) ? order.items.map(i => i.name).join(', ') : 'Rincian Pesanan';
                card.innerHTML = `
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="bg-brand-500/10 text-brand-400 font-extrabold text-[11px] p-2 rounded-lg border border-brand-500/20 font-mono shrink-0">
                            ${order.pcName}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-white truncate">${order.orderCode} • <span class="text-slate-400 font-normal">${order.time}</span></p>
                            <p class="text-[10px] text-slate-400 truncate">${itemNames}</p>
                        </div>
                    </div>
                    <span class="font-extrabold text-emerald-400 shrink-0">${formatRupiah(order.totalAmount)}</span>
                `;
                container.appendChild(card);
            });
        }

        function renderOrdersTable(filterStatus = 'all') {
            const tbody = document.getElementById('orders-table-body');
            if (!tbody) return;
            tbody.innerHTML = '';

            const search = document.getElementById('search-order-input') ? document.getElementById('search-order-input').value.toLowerCase().trim() : '';

            const filtered = ordersData.filter(o => {
                const matchStatus = filterStatus === 'all' || o.status === filterStatus;
                const matchSearch = o.orderCode.toLowerCase().includes(search) || o.pcName.toLowerCase().includes(search) || o.voucher.toLowerCase().includes(search);
                return matchStatus && matchSearch;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="p-8 text-center text-slate-500 text-xs">Tidak ada data pesanan yang cocok.</td></tr>`;
                return;
            }

            filtered.forEach(o => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-800/50 transition-colors";

                let statusBadge = `<span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Pending</span>`;
                if (o.status === 'preparing') statusBadge = `<span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Diproses</span>`;
                if (o.status === 'completed') statusBadge = `<span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Selesai</span>`;
                if (o.status === 'cancelled') statusBadge = `<span class="bg-rose-500/10 text-rose-400 border border-rose-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Batal</span>`;

                let payBadge = `<span class="text-[9px] bg-slate-800 text-slate-300 border border-slate-700 px-1.5 py-0.5 rounded uppercase font-bold">${o.paymentMethod}</span>`;

                let payStatusBadge = '';
                if (o.status === 'cancelled') {
                    payStatusBadge = `<span class="text-[9px] bg-rose-500/10 text-rose-400 border border-rose-500/20 px-1.5 py-0.5 rounded uppercase font-bold block mt-1"><i class="fa-solid fa-xmark mr-1"></i>Dibatalkan</span>`;
                } else if (['qris', 'va'].includes(o.paymentMethod)) {
                    if (o.paymentStatus === 'paid') {
                        payStatusBadge = `<span class="text-[9px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-1.5 py-0.5 rounded uppercase font-bold block mt-1"><i class="fa-solid fa-check mr-1"></i>Lunas</span>`;
                    } else if (o.paymentStatus === 'waiting_verification') {
                        payStatusBadge = `<span class="text-[9px] bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 px-1.5 py-0.5 rounded uppercase font-bold block mt-1"><i class="fa-solid fa-file-image mr-1"></i>Ada Bukti Bayar</span>`;
                    } else {
                        payStatusBadge = `<span class="text-[9px] bg-amber-500/10 text-amber-400 border border-amber-500/20 px-1.5 py-0.5 rounded uppercase font-bold block mt-1"><i class="fa-solid fa-clock mr-1"></i>Belum Bayar</span>`;
                    }
                } else if (o.paymentMethod === 'billing') {
                    payStatusBadge = `<span class="text-[9px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-1.5 py-0.5 rounded uppercase font-bold block mt-1"><i class="fa-solid fa-check mr-1"></i>Lunas (Saldo)</span>`;
                } else {
                    payStatusBadge = `<span class="text-[9px] bg-slate-800 text-slate-400 border border-slate-700 px-1.5 py-0.5 rounded uppercase font-bold block mt-1">Bayar Di Tempat</span>`;
                }

                let itemsHtml = Array.isArray(o.items) && o.items.length > 0 
                    ? o.items.map(i => `<div><span class="font-bold text-white">${i.qty}x ${i.name}</span>${i.notes ? `<span class="block text-[9px] text-brand-300 italic">"${i.notes}"</span>` : ''}</div>`).join('')
                    : `<span class="text-slate-400">Rincian terisi</span>`;

                let actionButtons = '';
                if (o.status === 'pending') {
                    if (o.paymentProof) {
                        actionButtons += `<button onclick="openProofModal(${o.id})" class="bg-cyan-600 hover:bg-cyan-500 text-white text-[10px] font-bold px-2.5 py-1.5 rounded-lg shadow transition mr-1 flex items-center gap-1"><i class="fa-solid fa-image"></i> Lihat Bukti</button>`;
                    }
                    
                    if (o.paymentStatus !== 'paid') {
                        actionButtons += `<button onclick="confirmPaymentAndAccept(${o.id})" class="bg-amber-600 hover:bg-amber-500 text-white text-[10px] font-bold px-2.5 py-1.5 rounded-lg shadow transition flex items-center gap-1"><i class="fa-solid fa-wallet text-[9px]"></i> Verifikasi & Terima</button>`;
                    } else {
                        actionButtons += `<button onclick="updateOrderStatus(${o.id}, 'preparing')" class="bg-cyan-600 hover:bg-cyan-500 text-white text-[10px] font-bold px-2.5 py-1.5 rounded-lg shadow transition">Terima & Proses</button>`;
                    }
                } else if (o.status === 'preparing') {
                    if (o.paymentStatus !== 'paid') {
                        actionButtons += `<button onclick="confirmPaymentOnly(${o.id})" class="bg-amber-600 hover:bg-amber-500 text-white text-[10px] font-bold px-2 py-1.5 rounded-lg shadow transition mr-1">Verifikasi Bayar</button>`;
                    }
                    actionButtons += `<button onclick="updateOrderStatus(${o.id}, 'completed')" class="bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-bold px-2.5 py-1.5 rounded-lg shadow transition">Tandai Selesai</button>`;
                }

                tr.innerHTML = `
                    <td class="p-4">
                        <span class="font-bold text-white font-mono block">${o.orderCode}</span>
                        <span class="text-[10px] text-slate-400">${o.time}</span>
                    </td>
                    <td class="p-4 font-extrabold text-brand-400 font-mono">${o.pcName}</td>
                    <td class="p-4 font-mono text-slate-300 text-[11px]">${o.voucher}</td>
                    <td class="p-4 space-y-1">${itemsHtml}</td>
                    <td class="p-4">
                        <span class="font-extrabold text-emerald-400 block">${formatRupiah(o.totalAmount)}</span>
                        <div class="flex items-center gap-1 flex-wrap">${payBadge} ${payStatusBadge}</div>
                    </td>
                    <td class="p-4">${statusBadge}</td>
                    <td class="p-4">
                        <div class="flex items-center justify-center gap-1.5">
                            ${actionButtons}
                            <button onclick="openPrintModal('${o.orderCode}')" title="Cetak Struk POS" class="bg-slate-800 hover:bg-slate-700 text-brand-400 border border-slate-700 p-1.5 rounded-lg"><i class="fa-solid fa-print"></i></button>
                            ${o.status !== 'cancelled' && o.status !== 'completed' ? `<button onclick="openCancelModal(${o.id})" title="Batalkan" class="bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 p-1.5 rounded-lg"><i class="fa-solid fa-xmark"></i></button>` : ''}
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // RENDER REPORT DASHBOARD TABLE (TAB 5 MAIN VIEW)
        function renderReportsDashboardTable() {
            const tbody = document.getElementById('reports-dashboard-table-body');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (ordersData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" class="p-8 text-center text-slate-500 text-xs">Belum ada transaksi laporan tercatat.</td></tr>`;
                return;
            }

            ordersData.forEach((o, index) => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-800/50 transition-colors";

                let itemsStr = Array.isArray(o.items) && o.items.length > 0
                    ? o.items.map(i => `${i.qty}x ${i.name} (${i.variant})`).join(', ')
                    : '-';

                let statusBadge = `<span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Pending</span>`;
                if (o.status === 'preparing') statusBadge = `<span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Diproses</span>`;
                if (o.status === 'completed') statusBadge = `<span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Selesai</span>`;
                if (o.status === 'cancelled') statusBadge = `<span class="bg-rose-500/10 text-rose-400 border border-rose-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Batal</span>`;

                tr.innerHTML = `
                    <td class="p-4 font-mono text-slate-400">${index + 1}</td>
                    <td class="p-4 font-bold text-white font-mono">${o.orderCode}</td>
                    <td class="p-4 font-mono text-slate-400">${o.time}</td>
                    <td class="p-4 font-extrabold text-brand-400 font-mono">${o.pcName}</td>
                    <td class="p-4 text-slate-300">${itemsStr}</td>
                    <td class="p-4 font-mono uppercase text-slate-300 text-[11px]">${o.paymentMethod}</td>
                    <td class="p-4 font-extrabold text-emerald-400 text-right">${formatRupiah(o.totalAmount)}</td>
                    <td class="p-4 text-center">${statusBadge}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        // RENDER FORMAL PRINT PAPER TABLE (MODAL VIEW)
        function renderFormalReportPaperTable() {
            const tbody = document.getElementById('report-table-body');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (ordersData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" class="p-4 text-center text-slate-500 text-xs">Belum ada transaksi tercatat untuk periode ini.</td></tr>`;
                return;
            }

            ordersData.forEach((o, index) => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-100 transition-colors";

                let itemsStr = Array.isArray(o.items) && o.items.length > 0
                    ? o.items.map(i => `${i.qty}x ${i.name} (${i.variant})`).join(', ')
                    : '-';

                let statusBadge = `<span class="text-[9px] bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded font-bold">PENDING</span>`;
                if (o.status === 'preparing') statusBadge = `<span class="text-[9px] bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded font-bold">DIPROSES</span>`;
                if (o.status === 'completed') statusBadge = `<span class="text-[9px] bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded font-bold">SELESAI</span>`;
                if (o.status === 'cancelled') statusBadge = `<span class="text-[9px] bg-rose-100 text-rose-800 px-1.5 py-0.5 rounded font-bold">BATAL</span>`;

                tr.innerHTML = `
                    <td class="p-2 border border-slate-300 font-mono">${index + 1}</td>
                    <td class="p-2 border border-slate-300 font-bold font-mono">${o.orderCode}</td>
                    <td class="p-2 border border-slate-300 font-mono">${o.time}</td>
                    <td class="p-2 border border-slate-300 font-bold">${o.pcName}</td>
                    <td class="p-2 border border-slate-300">${itemsStr}</td>
                    <td class="p-2 border border-slate-300 font-mono uppercase">${o.paymentMethod}</td>
                    <td class="p-2 border border-slate-300 font-bold text-right">${formatRupiah(o.totalAmount)}</td>
                    <td class="p-2 border border-slate-300 text-center">${statusBadge}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function filterOrderStatus(status) {
            document.querySelectorAll('.order-status-tab').forEach(btn => {
                if (btn.dataset.status === status) {
                    btn.className = "order-status-tab active px-4 py-2 rounded-xl text-xs font-bold bg-brand-600 text-white shadow-md shadow-brand-600/30";
                } else {
                    btn.className = "order-status-tab px-4 py-2 rounded-xl text-xs font-bold bg-slate-950 text-slate-400 border border-slate-800 hover:text-white";
                }
            });
            renderOrdersTable(status);
        }

        // BUKA MODAL LAPORAN FORMAL PRINT
        function openFormalReportPaperModal() {
            renderFormalReportPaperTable();
            document.getElementById('formal-report-modal').classList.remove('opacity-0', 'pointer-events-none');
        }

        function closeFormalReportPaperModal() {
            document.getElementById('formal-report-modal').classList.add('opacity-0', 'pointer-events-none');
        }

        // BUKA MODAL VERIFIKASI BUKTI TRANSFER
        function openProofModal(orderId) {
            selectedProofOrderId = orderId;
            const order = ordersData.find(o => o.id === orderId);
            if (!order || !order.paymentProof) {
                showAdminToast("Pelanggan belum mengunggah foto bukti pembayaran.");
                return;
            }

            document.getElementById('proof-modal-code').innerText = order.orderCode;
            document.getElementById('proof-modal-pc').innerText = `Komputer ${order.pcName} (${order.voucher})`;
            document.getElementById('proof-modal-total').innerText = formatRupiah(order.totalAmount);
            document.getElementById('proof-modal-image').src = order.paymentProof;

            const modal = document.getElementById('proof-view-modal');
            modal.classList.remove('opacity-0', 'pointer-events-none');
        }

        function closeProofModal() {
            document.getElementById('proof-view-modal').classList.add('opacity-0', 'pointer-events-none');
        }

        function confirmPaymentFromProofModal() {
            if (selectedProofOrderId) {
                confirmPaymentAndAccept(selectedProofOrderId);
                closeProofModal();
            }
        }

        async function updateOrderStatus(id, newStatus, cancelReason = '') {
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('order_id', id);
            formData.append('status', newStatus);
            if (cancelReason) formData.append('cancel_reason', cancelReason);

            try {
                const response = await fetch('admin.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.success) {
                    pollRealtimeData();
                    showAdminToast(res.message);
                } else {
                    showAdminToast('Gagal update status: ' + res.message);
                }
            } catch (e) {
                showAdminToast('Terjadi kesalahan jaringan server!');
            }
        }

        async function confirmPaymentAndAccept(id) {
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('order_id', id);
            formData.append('status', 'preparing');
            formData.append('confirm_payment', '1');

            try {
                const response = await fetch('admin.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.success) {
                    pollRealtimeData();
                    showAdminToast("Pembayaran dikonfirmasi LUNAS & pesanan diteruskan ke Dapur!");
                } else {
                    showAdminToast('Gagal konfirmasi: ' + res.message);
                }
            } catch (e) {
                showAdminToast('Terjadi kesalahan jaringan server!');
            }
        }

        async function confirmPaymentOnly(id) {
            const formData = new FormData();
            formData.append('action', 'confirm_payment');
            formData.append('order_id', id);

            try {
                const response = await fetch('admin.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.success) {
                    pollRealtimeData();
                    showAdminToast(res.message);
                } else {
                    showAdminToast('Gagal verifikasi pembayaran: ' + res.message);
                }
            } catch (e) {
                showAdminToast('Terjadi kesalahan koneksi!');
            }
        }

        async function toggleProductStock(productId, isAvailable) {
            const formData = new FormData();
            formData.append('action', 'toggle_stock');
            formData.append('product_id', productId);
            formData.append('is_available', isAvailable);

            try {
                const response = await fetch('admin.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.success) {
                    showAdminToast(res.message);
                    pollRealtimeData();
                } else {
                    showAdminToast('Gagal mengubah stok: ' + res.message);
                }
            } catch (err) {
                showAdminToast('Koneksi server terganggu!');
            }
        }

        function openProductModal() {
            document.getElementById('product-modal').classList.remove('opacity-0', 'pointer-events-none');
        }

        function closeProductModal() {
            document.getElementById('product-modal').classList.add('opacity-0', 'pointer-events-none');
        }

        async function saveNewProduct(e) {
            e.preventDefault();
            const name = document.getElementById('prod-name').value;
            const type = document.getElementById('prod-type').value;
            const price = document.getElementById('prod-price').value;
            const desc = document.getElementById('prod-desc').value;
            const img = document.getElementById('prod-img').value;

            const btn = document.getElementById('btn-save-prod');
            btn.disabled = true;
            btn.innerText = "Menyimpan...";

            const formData = new FormData();
            formData.append('action', 'add_product');
            formData.append('name', name);
            formData.append('type', type);
            formData.append('price', price);
            formData.append('description', desc);
            formData.append('image_url', img);

            try {
                const response = await fetch('admin.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.success) {
                    closeProductModal();
                    showAdminToast(res.message);
                    pollRealtimeData();
                    btn.disabled = false;
                    btn.innerText = "Simpan ke Database";
                    document.getElementById('add-product-form').reset();
                } else {
                    showAdminToast('Gagal menambah produk: ' + res.message);
                    btn.disabled = false;
                    btn.innerText = "Simpan ke Database";
                }
            } catch (err) {
                showAdminToast('Koneksi server terganggu!');
                btn.disabled = false;
                btn.innerText = "Simpan ke Database";
            }
        }

        function renderProductsTable() {
            const tbody = document.getElementById('products-table-body');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (!productsData || productsData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-500 text-xs">Belum ada produk terdaftar di database.</td></tr>`;
                return;
            }

            productsData.forEach(p => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-800/50";
                const isAvail = parseInt(p.is_available) === 1;
                tr.innerHTML = `
                    <td class="p-4"><img src="${p.image || 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=100'}" class="w-10 h-10 rounded-lg object-cover bg-slate-800 border border-slate-700"></td>
                    <td class="p-4 font-bold text-white">${p.name}</td>
                    <td class="p-4 font-bold text-slate-400 capitalize">${p.category || p.type} (${p.type})</td>
                    <td class="p-4 font-extrabold text-emerald-400">${formatRupiah(parseFloat(p.price))}</td>
                    <td class="p-4">
                        <button onclick="toggleProductStock(${p.id}, ${isAvail ? 0 : 1})" class="px-3 py-1 rounded-xl text-[10px] font-bold border transition ${isAvail ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/30 hover:bg-rose-500/20'}">
                            ${isAvail ? '✅ Tersedia (Ready)' : '❌ Habis (Sold Out)'}
                        </button>
                    </td>
                    <td class="p-4 text-center">
                        <button onclick="openEditProductModal(${p.id})" class="bg-brand-600/20 hover:bg-brand-600 text-brand-300 hover:text-white border border-brand-500/30 text-[10px] font-bold px-3 py-1.5 rounded-xl transition flex items-center gap-1 mx-auto active:scale-95">
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function openEditProductModal(productId) {
            const prod = productsData.find(p => parseInt(p.id) === parseInt(productId));
            if (!prod) return;

            document.getElementById('edit-prod-id').value = prod.id;
            document.getElementById('edit-prod-name').value = prod.name;
            document.getElementById('edit-prod-type').value = prod.type || 'makanan';
            document.getElementById('edit-prod-price').value = prod.price;
            document.getElementById('edit-prod-desc').value = prod.description || '';
            document.getElementById('edit-prod-img').value = prod.image || '';

            document.getElementById('edit-product-modal').classList.remove('opacity-0', 'pointer-events-none');
        }

        function closeEditProductModal() {
            document.getElementById('edit-product-modal').classList.add('opacity-0', 'pointer-events-none');
        }

        async function saveEditProduct(e) {
            e.preventDefault();
            const id = document.getElementById('edit-prod-id').value;
            const name = document.getElementById('edit-prod-name').value;
            const type = document.getElementById('edit-prod-type').value;
            const price = document.getElementById('edit-prod-price').value;
            const desc = document.getElementById('edit-prod-desc').value;
            const img = document.getElementById('edit-prod-img').value;

            const btn = document.getElementById('btn-save-edit-prod');
            btn.disabled = true;
            btn.innerText = "Menyimpan...";

            const formData = new FormData();
            formData.append('action', 'edit_product');
            formData.append('product_id', id);
            formData.append('name', name);
            formData.append('type', type);
            formData.append('price', price);
            formData.append('description', desc);
            formData.append('image_url', img);

            try {
                const response = await fetch('admin.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.success) {
                    closeEditProductModal();
                    showAdminToast(res.message);
                    pollRealtimeData();
                } else {
                    showAdminToast('Gagal mengubah produk: ' + res.message);
                }
            } catch (err) {
                showAdminToast('Koneksi server terganggu!');
            } finally {
                btn.disabled = false;
                btn.innerText = "Simpan Perubahan";
            }
        }

        function renderCustomersTable() {
            const tbody = document.getElementById('customers-table-body');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (!customersData || customersData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-500 text-xs">Belum ada pelanggan terhubung.</td></tr>`;
                return;
            }

            customersData.forEach(c => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-800/50";
                
                // Cek status is_online dari database (1 = Online, 0 = Offline)
                const isOnline = parseInt(c.is_online) === 1;
                const statusBadge = isOnline 
                    ? `<span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2.5 py-1 rounded-xl text-[10px] font-bold inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Online</span>`
                    : `<span class="bg-slate-800/80 text-slate-400 border border-slate-700/80 px-2.5 py-1 rounded-xl text-[10px] font-bold inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span> Offline</span>`;

                tr.innerHTML = `
                    <td class="p-4 font-bold text-brand-400 font-mono">${c.pc_name}</td>
                    <td class="p-4 font-mono text-slate-400">${c.ip_address}</td>
                    <td class="p-4 font-mono text-white">${c.active_voucher || '-'}</td>
                    <td class="p-4 font-bold">${c.total_orders} Order</td>
                    <td class="p-4 font-extrabold text-emerald-400">${formatRupiah(parseFloat(c.total_spent))}</td>
                    <td class="p-4">${statusBadge}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function toggleAudioAlarm() {
            isAudioEnabled = !isAudioEnabled;
            const icon = document.getElementById('audio-icon');
            const label = document.getElementById('audio-label');

            if (isAudioEnabled) {
                icon.className = "fa-solid fa-volume-high text-emerald-400";
                label.innerText = "Alarm Aktif";
            } else {
                icon.className = "fa-solid fa-volume-xmark text-rose-400";
                label.innerText = "Alarm Nonaktif";
            }
        }

        function openPrintModal(orderCode) {
            const order = ordersData.find(o => o.orderCode === orderCode) || ordersData[0];
            if (!order) return;

            document.getElementById('receipt-order-code').innerText = `Nota: ${order.orderCode}`;
            document.getElementById('receipt-pc').innerText = `Tujuan: ${order.pcName} (${order.voucher})`;
            document.getElementById('receipt-total').innerText = formatRupiah(order.totalAmount);
            document.getElementById('receipt-method').innerText = (order.paymentMethod || 'cash').toUpperCase();

            const itemsContainer = document.getElementById('receipt-items-list');
            itemsContainer.innerHTML = Array.isArray(order.items) && order.items.length > 0 
                ? order.items.map(i => `<div class="flex justify-between"><span>${i.qty}x ${i.name}</span><span>${formatRupiah(i.price * i.qty)}</span></div>`).join('')
                : `<p class="text-[9px]">Standard Order</p>`;

            const modal = document.getElementById('print-modal');
            modal.classList.remove('opacity-0', 'pointer-events-none');
        }

        function closePrintModal() {
            document.getElementById('print-modal').classList.add('opacity-0', 'pointer-events-none');
        }

        function executeReceiptPrint() {
            document.body.classList.add('print-receipt');
            window.print();
            document.body.classList.remove('print-receipt');
        }

        function printReportPDF() {
            document.body.classList.add('print-report');
            window.print();
            document.body.classList.remove('print-report');
        }

        function openCancelModal(id) {
            selectedCancelOrderId = id;
            const order = ordersData.find(o => o.id === id);
            if (order) {
                document.getElementById('cancel-order-code').innerText = `#${order.orderCode}`;
                document.getElementById('cancel-modal').classList.remove('opacity-0', 'pointer-events-none');
            }
        }

        function closeCancelModal() {
            document.getElementById('cancel-modal').classList.add('opacity-0', 'pointer-events-none');
        }

        function confirmCancelOrder() {
            if (selectedCancelOrderId) {
                const reason = document.querySelector('input[name="cancel_reason"]:checked').value;
                updateOrderStatus(selectedCancelOrderId, 'cancelled', reason);
                closeCancelModal();
            }
        }

        function showAdminToast(msg) {
            const toast = document.getElementById('admin-toast');
            document.getElementById('admin-toast-msg').innerText = msg;
            toast.classList.remove('-translate-y-16', 'opacity-0', 'pointer-events-none');
            setTimeout(() => {
                toast.classList.add('-translate-y-16', 'opacity-0', 'pointer-events-none');
            }, 3000);
        }

        function formatRupiah(num) {
            return 'Rp ' + (num || 0).toLocaleString('id-ID');
        }
    </script>
</body>
</html>