<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require_admin();   // staff -> 403

$errors = [];
$editing = null;

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = post_int('id');

    if ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $id]);
            flash('ok', 'Kategori dihapus.');
        } catch (PDOException $ex) {
            // FK ON DELETE RESTRICT: kategori yang masih punya produk tidak bisa dihapus
            flash('er', 'Kategori tidak bisa dihapus karena masih dipakai produk.');
        }
        redirect('categories.php');
    }

    $name = post_str('name');
    $desc = post_str('description');
    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Nama kategori wajib diisi (maksimal 100 karakter).';
    }
    if (mb_strlen($desc) > 255) {
        $errors[] = 'Deskripsi maksimal 255 karakter.';
    }
    if (!$errors) {
        $dup = $pdo->prepare('SELECT id FROM categories WHERE name = :n AND id <> :id');
        $dup->execute(['n' => $name, 'id' => $id ?? 0]);
        if ($dup->fetch()) {
            $errors[] = 'Nama kategori sudah ada.';
        }
    }
    if (!$errors) {
        if ($action === 'update' && $id !== null) {
            $pdo->prepare('UPDATE categories SET name = :n, description = :d WHERE id = :id')
                ->execute(['n' => $name, 'd' => $desc ?: null, 'id' => $id]);
            flash('ok', 'Kategori diperbarui.');
        } else {
            $pdo->prepare('INSERT INTO categories (name, description) VALUES (:n, :d)')
                ->execute(['n' => $name, 'd' => $desc ?: null]);
            flash('ok', 'Kategori ditambahkan.');
        }
        redirect('categories.php');
    }
    $editing = ['id' => $id, 'name' => $name, 'description' => $desc];
} elseif (get_int('edit') !== null) {
    $s = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
    $s->execute(['id' => get_int('edit')]);
    $editing = $s->fetch() ?: null;
}

$rows = $pdo->query(
    'SELECT c.*, COUNT(p.id) AS product_count FROM categories c
     LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id, c.name, c.description ORDER BY c.name'
)->fetchAll();

render_header('Kategori');
$isEdit = $editing && $editing['id'];
?>
<h1>Kategori</h1>
<?php render_errors($errors); ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= e($editing['id']) ?>"><?php endif; ?>
    <label><?= $isEdit ? 'Edit' : 'Tambah' ?> kategori</label>
    <input type="text" name="name" maxlength="100" value="<?= e($editing['name'] ?? '') ?>" required>
    <label>Deskripsi</label>
    <input type="text" name="description" maxlength="255" value="<?= e($editing['description'] ?? '') ?>">
    <p><button class="btn">Simpan</button> <?php if ($editing): ?><a class="btn gray" href="categories.php">Batal</a><?php endif; ?></p>
</form>

<table>
    <tr><th>Nama</th><th>Deskripsi</th><th class="n">Produk</th><th>Aksi</th></tr>
    <?php foreach ($rows as $r): ?>
        <tr><td><?= e($r['name']) ?></td><td><?= e($r['description']) ?></td><td class="n"><?= e($r['product_count']) ?></td>
            <td><a class="btn sm gray" href="categories.php?edit=<?= e($r['id']) ?>">Edit</a>
                <form method="post" class="i" onsubmit="return confirm('Hapus kategori ini?')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
                    <button class="btn sm red">Hapus</button></form></td></tr>
    <?php endforeach; ?>
</table>
<?php render_footer();
