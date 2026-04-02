<?php

declare(strict_types=1);
require __DIR__ . '/config.php';

if (!empty($_SESSION['logged_in'])) {
    header('Location: panel.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'درخواست نامعتبر است. دوباره تلاش کنید.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (hash_equals(ADMIN_USERNAME, $username) && hash_equals(ADMIN_PASSWORD, $password)) {
            $_SESSION['logged_in'] = true;
            session_regenerate_id(true);
            header('Location: panel.php');
            exit;
        }

        $error = 'نام کاربری یا رمز عبور اشتباه است.';
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ورود به پنل</title>
    <style>
        body{font-family:tahoma,Arial,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        .card{width:min(420px,92vw);background:#111827;border:1px solid #334155;border-radius:18px;padding:28px;box-shadow:0 10px 35px rgba(0,0,0,.35)}
        h1{margin:0 0 8px;font-size:24px}.muted{color:#94a3b8;margin:0 0 20px}
        label{display:block;margin:12px 0 6px}.input{width:100%;padding:12px 14px;border-radius:12px;border:1px solid #475569;background:#0b1220;color:#fff;box-sizing:border-box}
        .btn{width:100%;padding:12px 14px;border:none;border-radius:12px;background:#2563eb;color:#fff;font-size:15px;cursor:pointer;margin-top:16px}
        .err{background:#7f1d1d;color:#fecaca;padding:10px 12px;border-radius:12px;margin-bottom:12px}
    </style>
</head>
<body>
<div class="card">
    <h1>ورود به پنل</h1>
    <p class="muted">برای مدیریت لینک‌های پراکسی وارد شوید.</p>

    <?php if ($error): ?>
        <div class="err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="username">نام کاربری</label>
        <input class="input" id="username" name="username" required>

        <label for="password">رمز عبور</label>
        <input class="input" id="password" name="password" type="password" required>

        <button class="btn" type="submit">ورود</button>
    </form>
</div>
</body>
</html>
