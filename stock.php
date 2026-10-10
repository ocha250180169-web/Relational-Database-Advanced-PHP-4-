<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require_login();   // admin & staff boleh mencatat transaksi

$errors = [];
$old = ['type' => ($_GET['type'] ?? '') === 'OUT' ? 'OUT' : 'IN', 'product_id' => '', 'quantity' => '', 'note' => ''];

if (is_post()) {
    verify_csrf();
    $type = post_str('type');
    $productId = post_int('product_id');
    $qty = post_int('quantity');
    $note = post_str('note');
    $old = ['type' => $type, 'product_id' => $_POST['product_id'] ?? '', 'quantity' => $_POST['quantity'] ?? '', 'note' => $note];

    // Validasi syntactic
    if (!in_array($type, ['IN', 'OUT'], true)) {
        $errors[] = 'Tipe transaksi tidak valid.';
    }
    if ($productId === null) {
        $errors[] = 'Produk wajib dipilih.';
    }
    if ($qty === null || $qty <= 0) {
        $errors[] = 'Jumlah harus bilangan bulat lebih dari nol.';
    }
    if (mb_strlen($note) > 255) {
        $errors[] = 'Catatan maksimal 255 karakter.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // Kunci baris produk agar dua transaksi bersamaan tidak saling menimpa stok
            $s = $pdo->prepare('SELECT id, name, stock FROM products WHERE id = :id FOR UPDATE');
            $s->execute(['id' => $productId]);
            $p = $s->fetch();

            // Validasi semantic
            if (!$p) {
                $errors[] = 'Produk tidak ditemukan.';
            } elseif ($type === 'OUT' && $qty > (int) $p['stock']) {
                $errors[] = "Stok tidak cukup. Stok '{$p['name']}' saat ini {$p['stock']}, diminta $qty.";
            }

            if ($errors) {
                $pdo->rollBack();
            } else {
                $delta = $type === 'IN' ? $qty : -$qty;
                $pdo->prepare('UPDATE products SET stock = stock + :d WHERE id = :id')->execute(['d' => $delta, 'id' => $productId]);
                $pdo->prepare('INSERT INTO stock_movements (product_id, user_id, type, quantity, note) VALUES (:p, :u, :t, :q, :n)')
                    ->execute(['p' => $productId, 'u' => current_user()['id'], 't' => $type, 'q' => $qty, 'n' => $note ?: null]);
                $pdo->commit();
                flash('ok', $type === 'IN' ? 'Barang masuk dicatat, stok bertambah.' : 'Barang keluar dicatat, stok berkurang.');
                redirect('stock.php?type=' . $type);
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Transaksi gagal dan dibatalkan. Tidak ada data stok yang berubah.';
        }
    }
}

$products = $pdo->query('SELECT id, sku, name, stock FROM products ORDER BY name')->fetchAll();

// Histori + pagination
$pg = paginate((int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(), 10);
$h = $pdo->prepare(
    'SELECT sm.type, sm.quantity, sm.note, sm.created_at, p.sku, p.name AS product, u.username
     FROM stock_movements sm JOIN products p ON p.id = sm.product_id JOIN users u ON u.id = sm.user_id
     ORDER BY sm.created_at DESC, sm.id DESC LIMIT :lim OFFSET :off'
);
$h->bindValue(':lim', $pg['per_page'], PDO::PARAM_INT);
$h->bindValue(':off', $pg['offset'], PDO::PARAM_INT);
$h->execute();
$history = $h->fetchAll();

render_header('Barang Masuk/Keluar');
?>
<h1>Barang Masuk / Keluar</h1>
<?php render_errors($errors); ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <label>Jenis transaksi</label>
    <select name="type">
        <option value="IN" <?= $old['type'] === 'IN' ? 'selected' : '' ?>>Barang Masuk</option>
        <option value="OUT" <?= $old['type'] === 'OUT' ? 'selected' : '' ?>>Barang Keluar</option>
    </select>
    <label>Produk</label>
    <select name="product_id" required><option value="">-- pilih produk --</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= e($p['id']) ?>" <?= (string) $old['product_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['sku']) ?> - <?= e($p['name']) ?> (stok: <?= e($p['stock']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <label>Jumlah</label><input type="number" name="quantity" min="1" value="<?= e($old['quantity']) ?>" required>
    <label>Catatan (opsional)</label><input type="text" name="note" maxlength="255" value="<?= e($old['note']) ?>">
    <p><button class="btn">Simpan</button></p>
</form>

<h2>Histori</h2>
<table>
    <tr><th>Waktu</th><th>Tipe</th><th>SKU</th><th>Produk</th><th class="n">Jumlah</th><th>Oleh</th><th>Catatan</th></tr>
    <?php foreach ($history as $r): ?>
        <tr><td><?= e($r['created_at']) ?></td><td><?= $r['type'] === 'IN' ? 'MASUK' : 'KELUAR' ?></td>
            <td><?= e($r['sku']) ?></td><td><?= e($r['product']) ?></td><td class="n"><?= e($r['quantity']) ?></td>
            <td><?= e($r['username']) ?></td><td><?= e($r['note']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="7">Belum ada histori.</td></tr><?php endif; ?>
</table>
<?= render_pagination($pg) ?>
<?php render_footer();
