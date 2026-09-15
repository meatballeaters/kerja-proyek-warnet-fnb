<?php
/**
 * Configuration & Database Connection for CyberBite Warnet
 * Database: Supabase PostgreSQL
 */

// Mengambil koneksi dari Environment Variable (jika di-set di Vercel)
$db_url = getenv('DATABASE_URL');

if ($db_url) {
    // Parsing URL PostgreSQL Supabase
    $dbopts = parse_url($db_url);
    $host = $dbopts['host'];
    $port = $dbopts['port'] ?? '6543';
    $user = $dbopts['user'];
    $pass = $dbopts['pass'];
    $dbname = ltrim($dbopts['path'], '/');
} else {
    // Fallback koneksi manual (Ganti credential ini sesuai data Supabase milikmu)
    $host   = 'aws-0-ap-northeast-1.pooler.supabase.com'; // Host Supabase
    $port   = '6543';                                    // Port Transaction Pooler
    $user   = 'postgres.besmsjllkcqdspgascsc';          // DB Username
    $pass   = '02jd_o1erv24';          // Password DB Supabase
    $dbname = 'postgres';                                // Nama Database
}

try {
    // Inisialisasi koneksi PDO PostgreSQL (pgsql)
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Gagal Terhubung ke Database Warnet: " . $e->getMessage());
}

/**
 * Mendeteksi PC Client secara otomatis berdasarkan REMOTE_ADDR (IP Address)
 */
function getActiveClientPC($pdo) {
    // Ambil IP lokal client yang mengakses website
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Query kecocokan IP ke tabel pc_clients
    $stmt = $pdo->prepare("SELECT * FROM pc_clients WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$client_ip]);
    $client = $stmt->fetch();
    
    // Jika mengakses dari localhost atau IP belum terdaftar, gunakan fallback default (PC-05)
    if (!$client) {
        return [
            'pc_name' => 'PC-05',
            'ip_address' => $client_ip,
            'active_voucher' => 'VOUCHER-8821',
            'is_online' => 1
        ];
    }
    
    return $client;
}
?>