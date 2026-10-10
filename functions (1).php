<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* ---------- Escape / output encoding (cegah XSS) ---------- */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function rupiah(float|int|string $n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

/* ---------- Input & validasi ---------- */
function post_str(string $k): string
{
    return trim((string) ($_POST[$k] ?? ''));
}

function post_int(string $k): ?int
{
    $v = filter_input(INPUT_POST, $k, FILTER_VALIDATE_INT);
    return ($v === false || $v === null) ? null : $v;
}

function get_int(string $k): ?int
{
    $v = filter_input(INPUT_GET, $k, FILTER_VALIDATE_INT);
    return ($v === false || $v === null) ? null : $v;
}

/** Harga: angka >= 0 dengan maksimal 2 desimal, atau null jika tidak valid. */
function post_price(string $k): ?float
{
    $raw = trim((string) ($_POST[$k] ?? ''));
    return preg_match('/^\d+(\.\d{1,2})?$/', $raw) ? (float) $raw : null;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/* ---------- CSRF ---------- */
function csrf_field(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

/* ---------- Authentication & Authorization ---------- */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

/** Otorisasi di sisi server: staff yang membuka halaman admin mendapat 403. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('403 Forbidden: halaman ini hanya untuk admin.');
    }
}

function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.password_hash, u.role, p.full_name
         FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
         WHERE u.username = :username'
    );
    $stmt->execute(['username' => $username]);
    $u = $stmt->fetch();

    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $u['id'], 'username' => $u['username'],
            'role' => $u['role'], 'full_name' => $u['full_name'] ?? $u['username'],
        ];
        return true;
    }
    return false;
}

/* ---------- Pagination ---------- */
function paginate(int $total, int $perPage = 10): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, get_int('page') ?? 1), $pages);
    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total, 'pages' => $pages];
}

/** Query string yang mempertahankan search/filter/sort saat pindah halaman. */
function qs(array $override = []): string
{
    return '?' . http_build_query(array_filter(array_merge($_GET, $override), fn($v) => $v !== '' && $v !== null));
}

function render_pagination(array $pg): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    $h = '<nav class="pager">';
    for ($i = 1; $i <= $pg['pages']; $i++) {
        $h .= '<a' . ($i === $pg['page'] ? ' class="on"' : '') . ' href="' . e(qs(['page' => $i])) . '">' . $i . '</a>';
    }
    return $h . '</nav>';
}

/* ---------- Layout ---------- */
function render_header(string $title): void
{
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - Inventory</title>
<style>
*{box-sizing:border-box}body{margin:0;font:15px system-ui,sans-serif;background:#f4f6f9;color:#1f2937}
a{color:#2563eb;text-decoration:none}.bar{display:flex;flex-wrap:wrap;gap:16px;align-items:center;background:#111827;color:#fff;padding:12px 24px}
.bar a{color:#d1d5db}.bar nav{flex:1;display:flex;gap:14px;flex-wrap:wrap}.wrap{max-width:1050px;margin:24px auto;padding:0 16px}
h1{margin-top:0}h2{margin-top:28px}table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px #0001}
th,td{padding:9px 12px;text-align:left;border-bottom:1px solid #e5e7eb}th{background:#f9fafb}.n{text-align:right}
.card{background:#fff;border-radius:8px;padding:18px;box-shadow:0 1px 3px #0001;margin-bottom:18px}
.widgets{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px}
.widgets div{background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px #0001}.widgets small{color:#6b7280}.widgets b{display:block;font-size:24px;margin-top:4px}
label{display:block;margin:12px 0 4px;font-weight:600}input,select{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px}
.f{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}.f input,.f select{width:auto}
.btn{display:inline-block;padding:8px 14px;border:0;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;font-size:14px}
.red{background:#dc2626}.gray{background:#e5e7eb;color:#111}.sm{padding:3px 9px;font-size:13px}form.i{display:inline}
.ok{background:#dcfce7;color:#166534;padding:11px 15px;border-radius:8px;margin-bottom:14px}
.er{background:#fee2e2;color:#991b1b;padding:11px 15px;border-radius:8px;margin-bottom:14px}ul.er{padding-left:32px}
.low{color:#dc2626;font-weight:700}.pager{display:flex;gap:6px;margin-top:14px}.pager a{padding:5px 10px;background:#fff;border:1px solid #d1d5db;border-radius:6px}
.pager a.on{background:#2563eb;color:#fff}.hint{color:#6b7280;font-size:13px}.top{display:flex;justify-content:space-between;align-items:center}
</style></head><body>
<?php if ($u): ?>
<header class="bar"><b>📦 Inventory</b>
<nav><a href="dashboard.php">Dashboard</a><a href="products.php">Produk</a><a href="stock.php">Barang Masuk/Keluar</a>
<?php if (is_admin()): ?><a href="categories.php">Kategori</a><?php endif; ?></nav>
<span><?= e($u['full_name']) ?> (<?= e($u['role']) ?>) · <a href="logout.php">Logout</a></span></header>
<?php endif; ?>
<main class="wrap">
<?php foreach ($_SESSION['flash'] ?? [] as $f): ?><div class="<?= $f['type'] === 'ok' ? 'ok' : 'er' ?>"><?= e($f['msg']) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

function render_footer(): void
{
    echo '</main></body></html>';
}

function render_errors(array $errors): void
{
    if ($errors) {
        echo '<ul class="er">';
        foreach ($errors as $err) {
            echo '<li>' . e($err) . '</li>';
        }
        echo '</ul>';
    }
}
