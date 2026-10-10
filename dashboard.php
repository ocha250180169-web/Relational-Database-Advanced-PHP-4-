<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require_login();

$stats = $pdo->query(
    'SELECT COUNT(id) AS total_products,
            COALESCE(SUM(stock * price), 0) AS inventory_value,
            COALESCE(SUM(stock <= minimum_stock), 0) AS low_count
     FROM products'
)->fetch();

$moves = $pdo->query(
    "SELECT COALESCE(SUM(CASE WHEN type = 'IN'  THEN quantity END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN type = 'OUT' THEN quantity END), 0) AS total_out
     FROM stock_movements"
)->fetch();

$low = $pdo->query(
    'SELECT p.sku, p.name, p.stock, p.minimum_stock, c.name AS category
     FROM products p JOIN categories c ON c.id = p.category_id
     WHERE p.stock <= p.minimum_stock ORDER BY p.stock'
)->fetchAll();

// JOIN + GROUP BY + COUNT / SUM / AVG
$byCategory = $pdo->query(
    'SELECT c.name, COUNT(p.id) AS total_products, COALESCE(SUM(p.stock), 0) AS total_stock,
            COALESCE(AVG(p.price), 0) AS avg_price
     FROM categories c LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id, c.name ORDER BY c.name'
)->fetchAll();

// Laporan inventory per produk
$report = $pdo->query(
    "SELECT p.sku, p.name, p.stock,
            COALESCE(SUM(CASE WHEN sm.type = 'IN'  THEN sm.quantity END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN sm.type = 'OUT' THEN sm.quantity END), 0) AS total_out
     FROM products p LEFT JOIN stock_movements sm ON sm.product_id = p.id
     GROUP BY p.id, p.sku, p.name, p.stock ORDER BY p.name"
)->fetchAll();

render_header('Dashboard');
?>
<h1>Dashboard</h1>
<div class="widgets">
    <div><small>Total Produk</small><b><?= e($stats['total_products']) ?></b></div>
    <div><small>Nilai Inventory</small><b><?= e(rupiah($stats['inventory_value'])) ?></b></div>
    <div><small>Produk Stok Rendah</small><b class="low"><?= e($stats['low_count']) ?></b></div>
    <div><small>Barang Masuk</small><b><?= e($moves['total_in']) ?></b></div>
    <div><small>Barang Keluar</small><b><?= e($moves['total_out']) ?></b></div>
</div>

<h2>Produk Stok Rendah</h2>
<table>
    <tr><th>SKU</th><th>Produk</th><th>Kategori</th><th class="n">Stok</th><th class="n">Minimum</th></tr>
    <?php foreach ($low as $r): ?>
        <tr><td><?= e($r['sku']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['category']) ?></td>
            <td class="n low"><?= e($r['stock']) ?></td><td class="n"><?= e($r['minimum_stock']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$low): ?><tr><td colspan="5">Semua stok aman.</td></tr><?php endif; ?>
</table>

<h2>Ringkasan per Kategori</h2>
<table>
    <tr><th>Kategori</th><th class="n">Produk</th><th class="n">Total Stok</th><th class="n">Rata-rata Harga</th></tr>
    <?php foreach ($byCategory as $r): ?>
        <tr><td><?= e($r['name']) ?></td><td class="n"><?= e($r['total_products']) ?></td>
            <td class="n"><?= e($r['total_stock']) ?></td><td class="n"><?= e(rupiah($r['avg_price'])) ?></td></tr>
    <?php endforeach; ?>
</table>

<h2>Laporan Inventory</h2>
<table>
    <tr><th>SKU</th><th>Produk</th><th class="n">Total Masuk</th><th class="n">Total Keluar</th><th class="n">Stok</th></tr>
    <?php foreach ($report as $r): ?>
        <tr><td><?= e($r['sku']) ?></td><td><?= e($r['name']) ?></td>
            <td class="n"><?= e($r['total_in']) ?></td><td class="n"><?= e($r['total_out']) ?></td>
            <td class="n"><b><?= e($r['stock']) ?></b></td></tr>
    <?php endforeach; ?>
</table>
<?php render_footer();
