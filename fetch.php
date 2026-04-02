<?php

declare(strict_types=1);
require __DIR__ . '/config.php';

function fetch_remote_content(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (SubscriptionProxyViewer/1.2)',
        CURLOPT_SSL_VERIFYPEER => !ALLOW_INSECURE_SSL,
        CURLOPT_SSL_VERIFYHOST => ALLOW_INSECURE_SSL ? 0 : 2,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/json,text/plain;q=0.9,*/*;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return ['ok' => false, 'error' => 'خطا در ارتباط با منبع اصلی: ' . $error];
    }

    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => 'منبع اصلی با کد ' . $httpCode . ' پاسخ داد.'];
    }

    if (strlen($body) > MAX_REMOTE_BYTES) {
        $body = substr($body, 0, MAX_REMOTE_BYTES);
    }

    return [
        'ok' => true,
        'body' => $body,
        'content_type' => $contentType,
        'http_code' => $httpCode,
    ];
}

function clean_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function clean_value(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '') {
        return null;
    }

    $badPatterns = [
        '/\[\[.*?\]\]/u',
        '/\{\{.*?\}\}/u',
        '/app\./iu',
        '/IntlUtil\./iu',
        '/format\(/iu',
        '/font-family/iu',
        '/transition:/iu',
        '/box-shadow:/iu',
        '/unicode-range/iu',
    ];

    foreach ($badPatterns as $pattern) {
        if (preg_match($pattern, $value)) {
            return null;
        }
    }

    return $value;
}

function parse_json_payload(array|string $decoded): array
{
    $flat = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $data = [
        'status' => null,
        'download' => null,
        'upload' => null,
        'usage' => null,
        'total' => null,
        'remaining' => null,
        'expiry' => null,
        'last_connection' => null,
        'subscription_link' => null,
        'config_name' => null,
    ];

    $map = [
        'status' => ['status', 'state', 'active'],
        'download' => ['download', 'dl', 'down'],
        'upload' => ['upload', 'ul', 'up'],
        'usage' => ['usage', 'used', 'consumption'],
        'total' => ['total', 'quota', 'capacity'],
        'remaining' => ['remaining', 'left', 'balance', 'remained'],
        'expiry' => ['expiry', 'expiration', 'expire_at', 'expires_at', 'expire'],
        'last_connection' => ['last_connection', 'last_online', 'last_seen', 'last_connect', 'lastonline'],
    ];

    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator((array) $decoded));
    $kv = [];
    foreach ($iterator as $key => $value) {
        $kv[(string) $key] = is_scalar($value) ? (string) $value : null;
    }

    foreach ($map as $target => $candidates) {
        foreach ($kv as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (mb_strtolower($key) === mb_strtolower($candidate)) {
                    $data[$target] = (string) $value;
                    continue 3;
                }
            }
        }
    }

    if (!$data['usage'] && $data['download'] && $data['upload']) {
        $data['usage'] = trim($data['download'] . ' / ' . $data['upload']);
    }

    $data['raw_excerpt'] = mb_substr((string) $flat, 0, 1000);
    return $data;
}

function extract_template_attributes(string $body): ?array
{
    if (!preg_match('/<template[^>]+id=["\']subscription-data["\'][^>]*>/iu', $body, $match)) {
        return null;
    }

    $tag = $match[0];
    if (!preg_match_all('/\s(data-[a-z0-9_-]+)=("|\')(.*?)\2/iu', $tag, $matches, PREG_SET_ORDER)) {
        return null;
    }

    $attrs = [];
    foreach ($matches as $m) {
        $key = strtolower(substr($m[1], 5));
        $attrs[$key] = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $attrs ?: null;
}

function format_maybe_timestamp(string|int|float|null $value, bool $isMilliseconds = false): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^\d{10,16}$/', $raw)) {
        return clean_value($raw);
    }

    $timestamp = (int) $raw;
    if ($isMilliseconds || strlen($raw) >= 13) {
        $timestamp = (int) floor($timestamp / 1000);
    }

    if ($timestamp <= 0) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
}

function extract_subscription_links(string $body): array
{
    if (!preg_match('/<textarea[^>]+id=["\']subscription-links["\'][^>]*>(.*?)<\/textarea>/isu', $body, $m)) {
        return [];
    }

    $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = preg_split('/\R+/u', trim($raw)) ?: [];
    $links = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $links[] = $line;
        }
    }
    return $links;
}

function extract_display_label_from_link(string $link): ?string
{
    $fragment = parse_url($link, PHP_URL_FRAGMENT);
    if (!is_string($fragment) || $fragment === '') {
        return null;
    }
    return rawurldecode($fragment);
}

function apply_config_suffix(string $name): string
{
    $suffix = defined('CONFIG_NAME_SUFFIX') ? (string) CONFIG_NAME_SUFFIX : ' - North_VPN';
    $suffix = trim($suffix);

    $name = trim($name);
    if ($name === '') {
        return $suffix;
    }

    if ($suffix === '') {
        return $name;
    }

    $normalizedSuffix = ltrim($suffix);
    if (str_ends_with($name, $normalizedSuffix)) {
        return $name;
    }

    return $name . ' ' . $normalizedSuffix;
}

function normalize_config_name(?string $label): ?string
{
    if ($label === null) {
        return null;
    }

    $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $label = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $label) ?? $label;
    $label = trim($label);
    if ($label === '') {
        return null;
    }

    if (preg_match('/\b(VIP\d{1,6})\b/i', $label, $m)) {
        return apply_config_suffix(strtoupper($m[1]));
    }

    $parts = preg_split('/[-_|\s,]+/u', $label) ?: [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '' && preg_match('/^[A-Za-z]{2,12}\d{1,8}$/u', $part)) {
            return apply_config_suffix(strtoupper($part));
        }
    }

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '' && preg_match('/^[\p{L}A-Za-z0-9]{2,24}$/u', $part)) {
            return apply_config_suffix($part);
        }
    }

    return null;
}

function rewrite_link_label(string $link, string $configName): string
{
    $base = preg_replace('/#.*$/u', '', $link) ?? $link;
    return $base . '#' . rawurlencode($configName);
}

function extract_config_data(string $body): array
{
    $links = extract_subscription_links($body);
    if (!$links) {
        return ['subscription_link' => null, 'config_name' => null];
    }

    $first = $links[0];
    $label = extract_display_label_from_link($first);
    $configName = normalize_config_name($label);

    if ($configName === null) {
        return [
            'subscription_link' => null,
            'config_name' => null,
            'config_error' => 'اسم کانفیگ از لینک منبع پیدا نشد. لطفاً بعداً برای این مورد ورودی دستی در پنل اضافه کن.',
            'raw_config_link' => $first,
        ];
    }

    return [
        'subscription_link' => rewrite_link_label($first, $configName),
        'config_name' => $configName,
        'raw_config_link' => $first,
    ];
}

function parse_special_subscription_template(string $body): ?array
{
    $attrs = extract_template_attributes($body);
    if (!$attrs) {
        return null;
    }

    $configData = extract_config_data($body);

    $data = [
        'status' => null,
        'download' => clean_value($attrs['download'] ?? null),
        'upload' => clean_value($attrs['upload'] ?? null),
        'usage' => clean_value($attrs['used'] ?? null),
        'total' => clean_value($attrs['total'] ?? null),
        'remaining' => clean_value($attrs['remained'] ?? null),
        'expiry' => format_maybe_timestamp($attrs['expire'] ?? null, false),
        'last_connection' => format_maybe_timestamp($attrs['lastonline'] ?? null, true),
        'subscription_link' => $configData['subscription_link'] ?? null,
        'config_name' => $configData['config_name'] ?? null,
    ];

    if (!empty($configData['config_error'])) {
        $data['config_error'] = $configData['config_error'];
    }

    if (preg_match('/Status<\/th>\s*<td[^>]*class=["\']ant-descriptions-item-content["\'][^>]*>\s*(.*?)\s*<\/td>/isu', $body, $m)) {
        $statusHtml = $m[1];
        if (preg_match('/<span[^>]*>(.*?)<\/span>/isu', $statusHtml, $s)) {
            $data['status'] = clean_value($s[1]);
        } else {
            $data['status'] = clean_value($statusHtml);
        }
    }

    if (!$data['status'] && isset($attrs['status'])) {
        $data['status'] = clean_value($attrs['status']);
    }

    if (!$data['usage'] && $data['download'] && $data['upload']) {
        $data['usage'] = trim($data['download'] . ' / ' . $data['upload']);
    }

    $filled = array_filter($data, static fn($v) => $v !== null && $v !== '');
    if (!$filled) {
        return null;
    }

    return $data;
}

function parse_table_descriptions(string $body): ?array
{
    $map = [
        'Status' => 'status',
        'Downloaded' => 'download',
        'Uploaded' => 'upload',
        'Usage' => 'usage',
        'Total quota' => 'total',
        'Remained' => 'remaining',
        'Last Online' => 'last_connection',
        'Expiry' => 'expiry',
    ];

    $data = [
        'status' => null,
        'download' => null,
        'upload' => null,
        'usage' => null,
        'total' => null,
        'remaining' => null,
        'expiry' => null,
        'last_connection' => null,
        'subscription_link' => null,
        'config_name' => null,
    ];

    if (!preg_match_all('/<tr[^>]*class=["\']ant-descriptions-row["\'][^>]*>\s*<th[^>]*>(.*?)<\/th>\s*<td[^>]*>(.*?)<\/td>\s*<\/tr>/isu', $body, $rows, PREG_SET_ORDER)) {
        return null;
    }

    foreach ($rows as $row) {
        $label = clean_text($row[1]);
        $value = clean_value($row[2]);

        foreach ($map as $needle => $target) {
            if (mb_strtolower($label) === mb_strtolower($needle)) {
                $data[$target] = $value;
                break;
            }
        }
    }

    $configData = extract_config_data($body);
    $data['subscription_link'] = $configData['subscription_link'] ?? null;
    $data['config_name'] = $configData['config_name'] ?? null;
    if (!empty($configData['config_error'])) {
        $data['config_error'] = $configData['config_error'];
    }

    if (!$data['usage'] && $data['download'] && $data['upload']) {
        $data['usage'] = trim($data['download'] . ' / ' . $data['upload']);
    }

    $filled = array_filter($data, static fn($v) => $v !== null && $v !== '');
    return $filled ? $data : null;
}

function find_by_labels(string $text, array $labels): ?string
{
    $labelsPattern = implode('|', array_map(static fn(string $v): string => preg_quote($v, '/'), $labels));

    $patterns = [
        '/(?:' . $labelsPattern . ')\s*[:：|-]?\s*([^\n\r<]{1,120})/iu',
        '/(?:' . $labelsPattern . ')\s*<\/[^>]+>\s*<[^>]*>\s*([^<]{1,120})/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return clean_value($m[1]);
        }
    }

    return null;
}

function parse_subscription_data(string $body, string $contentType = ''): array
{
    $trimmed = trim($body);
    if ($trimmed === '') {
        return ['ok' => false, 'error' => 'محتوای دریافتی خالی است.'];
    }

    if (str_contains(strtolower($contentType), 'application/json') || in_array($trimmed[0] ?? '', ['{', '['], true)) {
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $data = parse_json_payload($decoded);
            return ['ok' => true, 'data' => $data];
        }
    }

    $special = parse_special_subscription_template($body);
    if ($special) {
        return ['ok' => true, 'data' => $special];
    }

    $tableData = parse_table_descriptions($body);
    if ($tableData) {
        return ['ok' => true, 'data' => $tableData];
    }

    $text = clean_text($body);
    $data = [
        'status' => find_by_labels($text, ['status', 'state', 'وضعیت']),
        'download' => find_by_labels($text, ['download', 'dl', 'دانلود']),
        'upload' => find_by_labels($text, ['upload', 'ul', 'آپلود']),
        'usage' => find_by_labels($text, ['usage', 'used', 'consumption', 'مصرف']),
        'total' => find_by_labels($text, ['total', 'quota', 'capacity', 'حجم کل']),
        'remaining' => find_by_labels($text, ['remaining', 'left', 'balance', 'remained', 'باقی‌مانده']),
        'expiry' => find_by_labels($text, ['expiry', 'expiration', 'expire', 'expires', 'تاریخ انقضا']),
        'last_connection' => find_by_labels($text, ['last connection', 'last connect', 'last seen', 'last online', 'آخرین اتصال']),
        'subscription_link' => null,
        'config_name' => null,
    ];

    $configData = extract_config_data($body);
    $data['subscription_link'] = $configData['subscription_link'] ?? null;
    $data['config_name'] = $configData['config_name'] ?? null;
    if (!empty($configData['config_error'])) {
        $data['config_error'] = $configData['config_error'];
    }

    if (!$data['usage'] && ($data['download'] || $data['upload'])) {
        $data['usage'] = trim(($data['download'] ? 'DL: ' . $data['download'] : '') . ($data['upload'] ? ' | UL: ' . $data['upload'] : ''));
    }

    $filled = array_filter($data, static fn($v) => $v !== null && $v !== '');
    if (!$filled) {
        return [
            'ok' => false,
            'error' => 'استخراج خودکار داده‌ها از منبع فعلی انجام نشد. ساختار این منبع متفاوت است و باید parser اختصاصی‌تری برای آن نوشته شود.',
            'raw_excerpt' => mb_substr($text, 0, 1200),
        ];
    }

    return ['ok' => true, 'data' => $data, 'raw_excerpt' => mb_substr($text, 0, 1200)];
}

if (PHP_SAPI !== 'cli' && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'fetch.php') {
    header('Content-Type: application/json; charset=utf-8');

    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'توکن ارسال نشده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $item = find_item_by_token($token);
    if (!$item) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'لینک پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $remote = fetch_remote_content($item['source_url']);
    if (!$remote['ok']) {
        http_response_code(502);
        echo json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $parsed = parse_subscription_data($remote['body'], $remote['content_type']);
    echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}