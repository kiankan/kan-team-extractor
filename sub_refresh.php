<?php
// sub_refresh.php - اندپوینت آژاکس برای دکمه‌ی «بروزرسانی زنده» در sub_view.php
// نسخه‌ی محکم‌شده: هر خروجی اضافه/خطای PHP رو می‌قاپه تا پاسخ همیشه JSON معتبر بمونه.
declare(strict_types=1);

// هر چیزی که قبل از موقع (Warning/Notice/BOM و ...) چاپ بشه رو می‌گیریم تا JSON کثیف نشه
ob_start();

// خطاها رو نمایش نده (کاربر نباید HTML خطا ببینه)، ولی لاگ کن که خودت بتونی ببینی
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): void {
    while (ob_get_level() > 0) { ob_end_clean(); } // هر خروجی ناخواسته رو پاک کن
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// اگر یک خطای مرگ‌بار (Fatal Error) رخ بده، به‌جای صفحه‌ی سفید/HTML، JSON برگردون
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'خطای داخلی سرور: ' . $err['message'] . ' (line ' . $err['line'] . ')',
        ], JSON_UNESCAPED_UNICODE);
    }
});

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/inc_extractor.php';
} catch (\Throwable $e) {
    fail('خطا در بارگذاری فایل‌های موردنیاز: ' . $e->getMessage(), 500);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$token = preg_replace('/[^a-f0-9]/', '', (string)($body['token'] ?? ''));
if ($token === '') fail('توکن نامعتبر است.');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fail('خطای اتصال به دیتابیس: ' . $e->getMessage(), 500);
}

$stmt = $pdo->prepare("SELECT * FROM extractions WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch();
if (!$row) fail('لینک یافت نشد.', 404);

// جلوگیری از اسپم درخواست به سرور ساب (حداقل فاصله ۸ ثانیه بین دو بروزرسانی)
$lastUpdate = strtotime((string)($row['created_at'] ?? '')) ?: 0;
if (time() - $lastUpdate < 8) {
    fail('لطفاً چند ثانیه صبر کنید و دوباره تلاش کنید.', 429);
}

$subUrl = (string)($row['sub_url'] ?? '');
if ($subUrl === '') fail('لینک ساب ثبت نشده است.');

try {
    $extractor  = new AdvancedSubExtractor();
    $subData    = $extractor->extractSubscription($subUrl);
    $headerInfo = getSubHeaderInfo($subUrl);
} catch (\Throwable $e) {
    fail('خطا در استخراج اطلاعات: ' . $e->getMessage(), 500);
}

if (!is_array($subData) || (int)($subData['total_configs'] ?? 0) === 0) {
    $hint = 'خطا در بروزرسانی؛ سرور ساب پاسخ معتبری نداد.';
    if (($subData['error'] ?? '') === 'html_page') {
        $hint = 'این لینک یک صفحه‌ی وب است نه لینک مستقیم ساب‌اسکریپشن، بنابراین کانفیگی از آن استخراج نمی‌شود.';
    }
    fail($hint, 422);
}

$totalBytes  = (float)($headerInfo['total'] ?? 0);
$usedBytes   = (float)($headerInfo['upload'] ?? 0) + (float)($headerInfo['download'] ?? 0);
$expireTs    = (int)($headerInfo['expire'] ?? 0);
$configsJson = json_encode($subData['configs_list'] ?? [], JSON_UNESCAPED_UNICODE);

try {
    $upd = $pdo->prepare("UPDATE extractions SET total_configs=?, total_bytes=?, used_bytes=?, expire_ts=?, configs_json=?, created_at=NOW() WHERE token=?");
    $upd->execute([$subData['total_configs'], $totalBytes, $usedBytes, $expireTs, $configsJson, $token]);
} catch (\Throwable $e) {
    fail('خطا در ذخیره‌سازی اطلاعات جدید: ' . $e->getMessage(), 500);
}

while (ob_get_level() > 0) { ob_end_clean(); } // پاک‌کردن هر خروجی اضافه‌ی احتمالی قبل از echo نهایی

echo json_encode([
    'ok'            => true,
    'total_configs' => (int)$subData['total_configs'],
    'protocols'     => $subData['protocols'] ?? [],
    'configs'       => $subData['configs_list'] ?? [],
    'total_bytes'   => $totalBytes,
    'used_bytes'    => $usedBytes,
    'expire_ts'     => $expireTs,
    'updated_at'    => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);