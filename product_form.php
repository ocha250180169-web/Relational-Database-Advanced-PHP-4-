<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require_admin();   // staff -> 403

$id = is_post() ? post_int('id') : get_int('id');
$product = ['sku' => '', 'name' => '', 'category_id' => '', 'price' => '', 'minimum_stock' => 5, 'stock' => 0];
$selected = [];   // supplier_id yang dipilih (many-to-many)

if ($id !== null) {
    $s = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $s->execute(['id' => $id]);
    $product = $s->fetch() ?: null;
    if (!$product) {
        http_response_code(404);
        exit('Produk tidak ditemukan.');
    }
    $s = $pdo->prepare('SELECT supplier_id FROM product_suppliers WHERE product_id = :id');
    $s->execute(['id' => $id]);
    $selected = array_map('intval', array_column($s->fetchAll(), 'supplier_id'));
}

$errors = [];
if (is_post()) {
    verify_csrf();
    $sku = post_str('sku');
    $name = post_str('name');
    $catId = post_int('category_id');
    $price = post_price('price');
    $min = post_int('minimum_stock');
    $selected = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['supplier_ids'] ?? [])), fn($v) => $v > 0
    )));
    $product = array_merge($product, [
        'sku' => $sku, 'name' => $name, 'category_id' => $catId ?? '',
        'price' => $_POST['price'] ?? '', 'minimum_stock' => $_POST['minimum_stock'] ?? '',
    ]);

    // Validasi syntactic
    if ($sku === '' || strlen($sku) > 50) {
        $errors[] = 'SKU wajib diisi (maksimal 50 karakter).';
    }
    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Nama produk wajib diisi (maksimal 150 karakter).';
    }
    if ($price === null) {
        $errors[] = 'Harga harus angka 0 atau lebih (contoh: 15000 atau 15000.50). Harga tidak boleh negatif.';
    }
    if ($min === null || $min < 0) {
        $errors[] = 'Stok minimum harus bilangan bulat 0 atau lebih.';
    }
    // Validasi semantic
    if ($catId === null) {
        $errors[] = 'Kategori wajib dipilih.';
    } else {
        $s = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
        $s->execute(['id' => $catId]);
        if ((int) $s->fetchColumn() === 0) {
            $errors[] = 'Kategori tidak ditemukan.';
        }
    }
    if ($sku !== '') {
        $s = $pdo->prepare('SELECT id FROM products WHERE sku = :sku AND id <> :id');
        $s->execute(['sku' => $sku, 'id' => $id ?? 0]);
        if ($s->fetch()) {
            $errors[] = "SKU '$sku' sudah dipakai produk lain (SKU harus unik).";
        }
    }
    if ($selected) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id IN (' . implode(',', array_fill(0, count($selected), '?')) . ')');
        $s->execute($selected);
        if ((int) $s->fetchColumn() !== count($selected)) {
            $errors[] = 'Ada supplier yang tidak valid.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if ($id === null) {
                // stok awal 0; stok bertambah hanya lewat Barang Masuk agar ada histori
                $pdo->prepare('INSERT INTO products (category_id, sku, name, price, stock, minimum_stock) VALUES (:c, :s, :n, :p, 0, :m)')
                    ->execute(['c' => $catId, 's' => $sku, 'n' => $name, 'p' => $price, 'm' => $min]);
                $pid = (int) $pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE products SET category_id = :c, sku = :s, name = :n, price = :p, minimum_stock = :m WHERE id = :id')
                    ->execute(['c' => $catId, 's' => $sku, 'n' => $name, 'p' => $price, 'm' => $min, 'id' => $id]);
                $pid = $id;
            }
            // Many-to-many via junction table product_suppliers
            $pdo->prepare('DELETE FROM product_suppliers WHERE product_id = :id')->execute(['id' => $pid]);
            $ins = $pdo->prepare('INSERT INTO product_suppliers (product_id, supplier_id) VALUES (:p, :s)');
            foreach ($selected as $sid) {
                $ins->execute(['p' => $pid, 's' => $sid]);
            }
            $pdo->commit();
            flash('ok', $id === null ? 'Produk ditambahkan.' : 'Produk diperbarui.');
            redirect('products.php');
        } catch (PDOException $ex) {
            $pdo->rollBack();
            $errors[] = 'Gagal menyimpan produk (SKU mungkin duplikat).';
        }
    }
}

$cats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();

render_header($id === null ? 'Tambah Produk' : 'Edit Produk');
?>
<h1><?= $id === null ? 'Tambah Produk' : 'Edit Produk' ?></h1>
<?php render_errors($errors); ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
    <label>SKU (unik)</label><input type="text" name="sku" maxlength="50" value="<?= e($product['sku']) ?>" required>
    <label>Nama Produk</label><input type="text" name="name" maxlength="150" value="<?= e($product['name']) ?>" required>
    <label>Kategori</label>
    <select name="category_id" required><option value="">-- pilih --</option>
        <?php foreach ($cats as $c): ?><option value="<?= e($c['id']) ?>" <?= (string) $product['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <label>Harga (Rp)</label><input type="text" name="price" inputmode="decimal" value="<?= e($product['price']) ?>" required>
    <label>Stok Minimum</label><input type="number" name="minimum_stock" min="0" value="<?= e($product['minimum_stock']) ?>" required>
    <p class="hint"><?= $id === null ? 'Stok awal 0.' : 'Stok saat ini: ' . e($product['stock']) . '.' ?> Stok hanya berubah lewat menu Barang Masuk / Keluar.</p>
    <label>Supplier (bisa lebih dari satu)</label>
    <?php foreach ($suppliers as $s): ?>
        <div><label style="font-weight:400;margin:4px 0"><input type="checkbox" style="width:auto" name="supplier_ids[]" value="<?= e($s['id']) ?>" <?= in_array((int) $s['id'], $selected, true) ? 'checked' : '' ?>> <?= e($s['name']) ?></label></div>
    <?php endforeach; ?>
    <p><button class="btn">Simpan</button> <a class="btn gray" href="products.php">Batal</a></p>
</form>
<?php render_footer();
