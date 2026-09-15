<?php
/**
 * Configuration & Database Connection for CyberBite Warnet
 * Database: warnet
 */

$db_host = 'localhost';
$db_user = 'root';
$db_pass = ''; // Sesuaikan dengan password MySQL lokal Anda
$db_name = 'warnet';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
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