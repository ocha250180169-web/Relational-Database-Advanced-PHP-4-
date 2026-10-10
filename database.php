<?php
declare(strict_types=1);

// Koneksi database (PDO) + start session + load helper.
// Ubah user/password sesuai MySQL kamu, atau set env DB_USER / DB_PASS.
$pdo = null;
try {
    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . (getenv('DB_NAME') ?: 'inventory_db') . ';charset=utf8mb4',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Koneksi database gagal. Cek config/database.php dan pastikan database/schema.sql sudah diimport.');
}

require_once __DIR__ . '/../helpers/functions.php';
