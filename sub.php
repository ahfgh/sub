<?php

declare(strict_types=1);
require __DIR__ . '/config.php';

$token = '';

if (isset($_GET['token'])) {
    $token = trim((string)$_GET['token']);
} elseif (isset($_SERVER['REQUEST_URI'])) {
    $path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#/sub/([a-f0-9]{16,128})$#i', (string)$path, $m)) {
        $token = $m[1];
    }
}

$item = $token !== '' ? find_item_by_token($token) : null;

if (!$item) {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $item ? e($item['name']) : 'لینک نامعتبر' ?></title>
    <style>
        body{font-family:tahoma,Arial,sans-serif;background:linear-gradient(180deg,#020617,#0f172a);color:#e2e8f0;margin:0;min-height:100vh}
        .wrap{max-width:1000px;margin:0 auto;padding:24px}
        .head{margin-bottom:18px}.muted{color:#94a3b8}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
        .card{background:rgba(15,23,42,.85);backdrop-filter: blur(8px);border:1px solid #334155;border-radius:18px;padding:18px;box-shadow:0 10px 25px rgba(0,0,0,.25)}
        .label{color:#94a3b8;font-size:13px;margin-bottom:8px}.value{font-size:18px;font-weight:700;word-break:break-word}
        .status{display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:999px;background:#1e293b;margin-top:10px}
        .dot{width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block}.dot.off{background:#ef4444}
        .err{background:#7f1d1d;color:#fecaca;padding:14px;border-radius:14px}
        .loading{opacity:.8}
        .config-box{margin-top:18px}
        .config-title{font-size:16px;font-weight:700;margin-bottom:12px}
        .config-name{display:inline-block;background:#312e81;color:#e0e7ff;border:1px solid #4f46e5;padding:6px 10px;border-radius:999px;font-size:13px;margin-bottom:12px}
        .config-link{direction:ltr;text-align:left;font-family:Consolas,Monaco,monospace;background:#0b1220;border:1px solid #334155;border-radius:14px;padding:14px;word-break:break-all;line-height:1.9;color:#cbd5e1}
        .copy-btn{margin-top:12px;border:0;border-radius:12px;background:#2563eb;color:#fff;padding:10px 14px;font-size:14px;cursor:pointer}
        .copy-btn:hover{background:#1d4ed8}
        .copy-msg{margin-right:10px;color:#93c5fd;font-size:13px}
    </style>
</head>
<body>
<div class="wrap">
    <?php if (!$item): ?>
        <div class="err">لینک نامعتبر یا حذف شده است.</div>
    <?php else: ?>
        <div class="head">
            <h1 style="margin:0 0 8px"><?= e($item['name']) ?></h1>
            <div class="muted">اطلاعات این صفحه به‌صورت لحظه‌ای از منبع اصلی خوانده می‌شود، بدون نمایش لینک اصلی.</div>
            <div id="statusBar" class="status loading"><span class="dot"></span><span id="statusText">در حال دریافت اطلاعات...</span></div>
        </div>

        <div class="grid">
            <div class="card"><div class="label">وضعیت</div><div class="value" id="v-status">---</div></div>
            <div class="card"><div class="label">دانلود</div><div class="value" id="v-download">---</div></div>
            <div class="card"><div class="label">آپلود</div><div class="value" id="v-upload">---</div></div>
            <div class="card"><div class="label">مصرف</div><div class="value" id="v-usage">---</div></div>
            <div class="card"><div class="label">حجم کل</div><div class="value" id="v-total">---</div></div>
            <div class="card"><div class="label">باقی‌مانده</div><div class="value" id="v-remaining">---</div></div>
            <div class="card"><div class="label">تاریخ انقضا</div><div class="value" id="v-expiry">---</div></div>
            <div class="card"><div class="label">آخرین اتصال</div><div class="value" id="v-last_connection">---</div></div>
        </div>

        <div class="card config-box" id="configBox" style="display:none;">
            <div class="config-title">کانفیگ</div>
            <div class="config-name" id="configName">---</div>
            <div class="config-link" id="configLink">---</div>
            <button type="button" class="copy-btn" id="copyBtn">کپی کانفیگ</button>
            <span class="copy-msg" id="copyMsg"></span>
        </div>

        <div id="errorBox" class="err" style="display:none;margin-top:18px"></div>
    <?php endif; ?>
</div>
<?php if ($item): ?>
<script>
const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const fields = ['status','download','upload','usage','total','remaining','expiry','last_connection'];
const setText = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value && String(value).trim() !== '' ? value : 'نامشخص';
};

const fetchUrl = <?= json_encode(rtrim(base_url(), '/') . '/fetch.php?token=' . rawurlencode($token), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
fetch(fetchUrl, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json())
    .then(data => {
        const statusBar = document.getElementById('statusBar');
        const statusText = document.getElementById('statusText');
        const dot = statusBar.querySelector('.dot');
        statusBar.classList.remove('loading');

        if (!data.ok) {
            dot.classList.add('off');
            statusText.textContent = 'خطا در دریافت اطلاعات';
            const box = document.getElementById('errorBox');
            box.style.display = 'block';
            box.textContent = data.error || 'خطای نامشخص';
            return;
        }

        const info = data.data || {};
        fields.forEach(key => setText('v-' + key, info[key] || null));

        const normalized = String(info.status || '').toLowerCase();
        const onlineWords = ['active','online','ok','enabled','فعال'];
        const isOnline = onlineWords.some(w => normalized.includes(w));
        if (!isOnline && normalized !== '') dot.classList.add('off');
        statusText.textContent = 'اطلاعات با موفقیت به‌روزرسانی شد';

        if (info.subscription_link) {
            document.getElementById('configBox').style.display = 'block';
            setText('configName', info.config_name || null);
            document.getElementById('configLink').textContent = info.subscription_link;
        }

        if (info.config_error) {
            const box = document.getElementById('errorBox');
            box.style.display = 'block';
            box.textContent = info.config_error;
        }
    })
    .catch(() => {
        const statusBar = document.getElementById('statusBar');
        const statusText = document.getElementById('statusText');
        const dot = statusBar.querySelector('.dot');
        dot.classList.add('off');
        statusBar.classList.remove('loading');
        statusText.textContent = 'خطا در ارتباط با سرور';
    });

async function copyTextWithFallback(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
    }

    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', 'readonly');
    ta.style.position = 'fixed';
    ta.style.top = '-9999px';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, ta.value.length);

    let ok = false;
    try {
        ok = document.execCommand('copy');
    } finally {
        document.body.removeChild(ta);
    }

    if (!ok) {
        throw new Error('copy-failed');
    }

    return true;
}

const copyBtn = document.getElementById('copyBtn');
if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
        const text = document.getElementById('configLink').textContent || '';
        const msg = document.getElementById('copyMsg');
        if (!text || text === '---') return;
        try {
            await copyTextWithFallback(text);
            msg.textContent = 'کپی شد';
        } catch (e) {
            msg.textContent = 'کپی نشد';
        }
        setTimeout(() => { msg.textContent = ''; }, 2000);
    });
}
</script>
<?php endif; ?>
</body>
</html>
