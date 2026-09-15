<?php
/**
 * Configuration & Database Connection for CyberBite Warnet
 * Database: Supabase PostgreSQL
 */

$db_url = getenv('DATABASE_URL');

if ($db_url) {
    $dbopts = parse_url($db_url);
    $host   = $dbopts['host'];
    $port   = $dbopts['port'] ?? '6543';
    $user   = $dbopts['user'];
    $pass   = $dbopts['pass'];
    $dbname = ltrim($dbopts['path'], '/');
} else {
    // Sesuaikan dengan kredensial Supabase milikmu jika berjalan lokal
    $host   = 'aws-0-ap-northeast-1.pooler.supabase.com';
    $port   = '6543';
    $user   = 'postgres.besmsjllkcqdspgascsc';
    $pass   = '02jd_o1erv24';
    $dbname = 'postgres';
}

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Gagal Terhubung ke Database Warnet: " . $e->getMessage());
}

function getActiveClientPC($pdo) {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $stmt = $pdo->prepare("SELECT * FROM pc_clients WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$client_ip]);
    $client = $stmt->fetch();
    
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
