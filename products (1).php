<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require_login();

// Hapus produk (hanya admin)
if (is_post()) {
    require_admin();
    verify_csrf();
    $id = post_int('id');
    $s = $pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE product_id = :id');
    $s->execute(['id' => $id]);
    if ($id === null) {
        flash('er', 'ID produk tidak valid.');
    } elseif ((int) $s->fetchColumn() > 0) {
        // Business rule: produk yang punya histori tidak boleh dihapus
        flash('er', 'Produk tidak bisa dihapus karena sudah memiliki histori barang masuk/keluar.');
    } else {
        $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        flash('ok', 'Produk dihapus.');
    }
    redirect('products.php');
}

// Sorting: kolom hanya dari ALLOWLIST (bukan input mentah)
$sortable = ['name' => 'p.name', 'sku' => 'p.sku', 'price' => 'p.price', 'stock' => 'p.stock'];
$sort = array_key_exists($_GET['sort'] ?? '', $sortable) ? $_GET['sort'] : 'name';
$dir = strtoupper((string) ($_GET['dir'] ?? '')) === 'DESC' ? 'DESC' : 'ASC';
$q = trim((string) ($_GET['q'] ?? ''));
$catId = get_int('category_id');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.name LIKE :q1 OR p.sku LIKE :q2)';
    $params['q1'] = $params['q2'] = '%' . $q . '%';
}
if ($catId !== null) {
    $where[] = 'p.category_id = :cat';
    $params['cat'] = $catId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$c = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSql");
$c->execute($params);
$pg = paginate((int) $c->fetchColumn(), 10);

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category,
            (SELECT COUNT(*) FROM stock_movements sm WHERE sm.product_id = p.id) AS history
     FROM products p JOIN categories c ON c.id = p.category_id
     $whereSql ORDER BY {$sortable[$sort]} $dir LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':lim', $pg['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':off', $pg['offset'], PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();
$cats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

render_header('Produk');
?>
<div class="top"><h1>Produk</h1>
    <?php if (is_admin()): ?><a class="btn" href="product_form.php">+ Tambah Produk</a><?php endif; ?></div>

<form method="get" class="f">
    <input type="text" name="q" placeholder="Cari nama / SKU" value="<?= e($q) ?>">
    <select name="category_id"><option value="">Semua kategori</option>
        <?php foreach ($cats as $c): ?><option value="<?= e($c['id']) ?>" <?= $catId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="sort">
        <?php foreach (['name' => 'Nama', 'sku' => 'SKU', 'price' => 'Harga', 'stock' => 'Stok'] as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>>Urut: <?= e($l) ?></option><?php endforeach; ?>
    </select>
    <select name="dir"><option value="ASC" <?= $dir === 'ASC' ? 'selected' : '' ?>>Naik (ASC)</option><option value="DESC" <?= $dir === 'DESC' ? 'selected' : '' ?>>Turun (DESC)</option></select>
    <button class="btn">Terapkan</button> <a class="btn gray" href="products.php">Reset</a>
</form>

<table>
    <tr><th>SKU</th><th>Nama</th><th>Kategori</th><th class="n">Harga</th><th class="n">Stok</th><th class="n">Min</th><?php if (is_admin()): ?><th>Aksi</th><?php endif; ?></tr>
    <?php foreach ($products as $p): ?>
        <tr><td><?= e($p['sku']) ?></td><td><?= e($p['name']) ?></td><td><?= e($p['category']) ?></td>
            <td class="n"><?= e(rupiah($p['price'])) ?></td>
            <td class="n <?= (int) $p['stock'] <= (int) $p['minimum_stock'] ? 'low' : '' ?>"><?= e($p['stock']) ?></td>
            <td class="n"><?= e($p['minimum_stock']) ?></td>
            <?php if (is_admin()): ?><td>
                <a class="btn sm gray" href="product_form.php?id=<?= e($p['id']) ?>">Edit</a>
                <?php if ((int) $p['history'] === 0): ?>
                <form method="post" class="i" onsubmit="return confirm('Hapus produk ini?')"><?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn sm red">Hapus</button></form>
                <?php else: ?><span class="hint">🔒 punya histori</span><?php endif; ?>
            </td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$products): ?><tr><td colspan="7">Tidak ada produk yang cocok.</td></tr><?php endif; ?>
</table>
<p class="hint"><?= e($pg['total']) ?> produk &middot; halaman <?= e($pg['page']) ?>/<?= e($pg['pages']) ?></p>
<?= render_pagination($pg) ?>
<?php render_footer();
