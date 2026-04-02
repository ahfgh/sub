<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const APP_NAME = 'Subscription Proxy Viewer';
const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = 'ChangeThisStrongPassword123!';
const DATA_FILE = __DIR__ . '/data.json';
const REQUEST_TIMEOUT = 15;
const ALLOW_INSECURE_SSL = false;
const TOKEN_LENGTH_BYTES = 16; // 32 hex chars
const MAX_REMOTE_BYTES = 1024 * 1024 * 2; // 2 MB
const CONFIG_NAME_SUFFIX = ' - North_VPN';
const LOGO_URL = '/north%20logo.png';

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($basePath === '' || $basePath === '/' ? '' : $basePath);
}

function app_sub_url(string $token): string
{
    return rtrim(base_url(), '/') . '/sub/' . rawurlencode($token);
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function require_login(): void
{
    if (empty($_SESSION['logged_in'])) {
        header('Location: index.php');
        exit;
    }
}

function is_valid_url(string $url): bool
{
    return (bool) filter_var($url, FILTER_VALIDATE_URL);
}

function generate_token(): string
{
    return bin2hex(random_bytes(TOKEN_LENGTH_BYTES));
}

function ensure_data_file(): void
{
    if (!file_exists(DATA_FILE)) {
        file_put_contents(DATA_FILE, json_encode(['items' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

function load_data(): array
{
    ensure_data_file();
    $json = file_get_contents(DATA_FILE);
    $data = json_decode($json ?: '', true);

    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        $data = ['items' => []];
    }

    return $data;
}

function save_data(array $data): bool
{
    ensure_data_file();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents(DATA_FILE, $json, LOCK_EX) !== false;
}

function find_item_by_token(string $token): ?array
{
    $data = load_data();
    foreach ($data['items'] as $item) {
        if (($item['token'] ?? '') === $token) {
            return $item;
        }
    }
    return null;
}

function upsert_item(string $name, string $sourceUrl): array
{
    $data = load_data();
    $token = generate_token();

    $item = [
        'id' => bin2hex(random_bytes(8)),
        'name' => $name,
        'source_url' => $sourceUrl,
        'token' => $token,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];

    $data['items'][] = $item;
    save_data($data);

    return $item;
}

function delete_item(string $id): bool
{
    $data = load_data();
    $before = count($data['items']);
    $data['items'] = array_values(array_filter($data['items'], static fn(array $item): bool => ($item['id'] ?? '') !== $id));

    if ($before === count($data['items'])) {
        return false;
    }

    return save_data($data);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function normalize_number(float|int|string|null $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $number = (float) $value;
        if ($number >= 1024 * 1024 * 1024) {
            return round($number / (1024 * 1024 * 1024), 2) . ' GB';
        }
        if ($number >= 1024 * 1024) {
            return round($number / (1024 * 1024), 2) . ' MB';
        }
        if ($number >= 1024) {
            return round($number / 1024, 2) . ' KB';
        }
        return round($number, 2) . ' B';
    }

    return trim((string)$value);
}

function format_datetime(?string $value): string
{
    if (empty($value)) {
        return 'نامشخص';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('Y-m-d H:i:s', $timestamp);
}
