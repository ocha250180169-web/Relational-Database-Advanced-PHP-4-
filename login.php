<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

if (current_user()) {
    redirect('dashboard.php');
}

$errors = [];
if (is_post()) {
    verify_csrf();
    $username = post_str('username');
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $errors[] = 'Username dan password wajib diisi.';
    } elseif (attempt_login($pdo, $username, $password)) {
        redirect('dashboard.php');
    } else {
        $errors[] = 'Username atau password salah.';
    }
}

render_header('Login');
?>
<div class="card" style="max-width:380px;margin:60px auto">
    <h1>Login</h1>
    <?php render_errors($errors); ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Username</label><input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
        <label>Password</label><input type="password" name="password" required>
        <p><button class="btn">Masuk</button></p>
    </form>
    <p class="hint">Demo: admin / admin123 &middot; staff / staff123</p>
</div>
<?php render_footer();
