<?php

declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();

$message = null;
$error = null;

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'درخواست نامعتبر است.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $sourceUrl = trim((string)($_POST['source_url'] ?? ''));

            if ($name === '') {
                $error = 'اسم را وارد کنید.';
            } elseif (!is_valid_url($sourceUrl)) {
                $error = 'لینک ساب اصلی معتبر نیست.';
            } else {
                $item = upsert_item($name, $sourceUrl);
                $message = 'لینک جدید ساخته شد: ' . app_sub_url($item['token']);
            }
        }

        if ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $message = delete_item($id) ? 'آیتم حذف شد.' : 'آیتم پیدا نشد.';
        }
    }
}

$data = load_data();
$items = array_reverse($data['items']);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>پنل مدیریت</title>
    <style>
        body{font-family:tahoma,Arial,sans-serif;background:#020617;color:#e2e8f0;margin:0}
        .wrap{max-width:1100px;margin:0 auto;padding:24px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
        .card{background:#0f172a;border:1px solid #334155;border-radius:18px;padding:20px;margin-top:20px}
        .grid{display:grid;grid-template-columns:1fr 1fr auto;gap:12px}
        .input{width:100%;padding:12px 14px;border-radius:12px;border:1px solid #475569;background:#020617;color:#fff;box-sizing:border-box}
        .btn{padding:12px 16px;border:none;border-radius:12px;background:#2563eb;color:#fff;cursor:pointer}
        .btn-danger{background:#b91c1c}.btn-ghost{background:#1e293b;text-decoration:none;display:inline-block}
        .msg,.err{padding:12px 14px;border-radius:12px;margin-top:14px}.msg{background:#052e16;color:#bbf7d0}.err{background:#7f1d1d;color:#fecaca}
        table{width:100%;border-collapse:collapse;margin-top:14px}th,td{padding:12px;border-bottom:1px solid #334155;text-align:right;vertical-align:top}
        a{color:#93c5fd} code{word-break:break-all}
        @media (max-width:800px){.grid{grid-template-columns:1fr}.hide-sm{display:none}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1 style="margin:0 0 8px">پنل مدیریت</h1>
            <div style="color:#94a3b8">ثبت لینک اصلی و ساخت لینک امن برای مشتری</div>
        </div>
        <a class="btn btn-ghost" href="?logout=1">خروج</a>
    </div>

    <div class="card">
        <h2 style="margin-top:0">ساخت لینک جدید</h2>
        <?php if ($message): ?><div class="msg"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="grid">
                <input class="input" name="name" placeholder="اسم اشتراک / مشتری" required>
                <input class="input" name="source_url" placeholder="لینک ساب اصلی" required>
                <button class="btn" type="submit">ساخت لینک</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">لیست لینک‌ها</h2>
        <table>
            <thead>
            <tr>
                <th>اسم</th>
                <th class="hide-sm">لینک مشتری</th>
                <th class="hide-sm">تاریخ</th>
                <th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$items): ?>
                <tr><td colspan="4">هنوز موردی ثبت نشده است.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['name'] ?? '') ?></td>
                    <td class="hide-sm"><code><?= e(app_sub_url($item['token'])) ?></code></td>
                    <td class="hide-sm"><?= e(format_datetime($item['created_at'] ?? null)) ?></td>
                    <td>
                        <a href="<?= e(app_sub_url($item['token'])) ?>" target="_blank">مشاهده</a>
                        <form method="post" style="display:inline-block" onsubmit="return confirm('حذف شود؟');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                            <button type="submit" class="btn btn-danger">حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
