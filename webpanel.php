<?php
// webpanel.php - پنل وب یکپارچه ربات (نسخه 3.0)
// این فایل جایگزین buttons_panel.php و settings_panel.php است (هر دو در یک پنل ادغام شدند):
//   - چیدمان / متن / رنگ / ایموجی دکمه‌ها
//   - قفل کانال (جوین اجباری)
//   - گزارشات خودکار (تاپیک‌ها)
//   - کرون‌جاب بکاپ
//   - مدیریت مدیران و کاربران
//   - تنظیمات عمومی ربات
//   - پیام همگانی متنی
//   - بکاپ کامل (دیتابیس/سورس) + بکاپ سریع تنظیمات (JSON)
//   - امنیت پنل (تغییر رمز)
// این فایل را کنار bot.php روی هاست آپلود کنید. فایل‌های قدیمی buttons_panel.php و
// settings_panel.php را می‌توانید حذف کنید (به شرطی که آدرس‌های داخل bot.php را هم
// به webpanel.php آپدیت کرده باشید).
declare(strict_types=1);
session_start();
require_once 'config.php';

// ------------------------------------------------------------------
// اتصال به دیتابیس + اطمینان از وجود جدول‌های لازم (هماهنگ با bot.php)
// ------------------------------------------------------------------
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (`setting_key` VARCHAR(50) PRIMARY KEY, `setting_value` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (`user_id` BIGINT PRIMARY KEY, `points` INT DEFAULT 0, `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `is_blocked` TINYINT(1) DEFAULT 0, `is_admin` TINYINT(1) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (`admin_id` BIGINT PRIMARY KEY, `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_states` (`user_id` BIGINT PRIMARY KEY, `state` VARCHAR(50) NOT NULL, `data` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `extractions` (
        `token` VARCHAR(32) PRIMARY KEY,
        `user_id` BIGINT,
        `sub_url` TEXT,
        `total_configs` INT DEFAULT 0,
        `total_bytes` DOUBLE DEFAULT 0,
        `used_bytes` DOUBLE DEFAULT 0,
        `expire_ts` BIGINT DEFAULT 0,
        `configs_json` LONGTEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `backup_imports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` BIGINT,
        `inserted_count` INT DEFAULT 0,
        `skipped_count` INT DEFAULT 0,
        `failed_count` INT DEFAULT 0,
        `total_count` INT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // محدودیت تلاش ورود به پنل (ضد حدس رمز/بروت‌فورس) بر اساس IP
    $pdo->exec("CREATE TABLE IF NOT EXISTS `panel_login_throttle` (
        `ip` VARCHAR(45) PRIMARY KEY,
        `attempts` INT DEFAULT 0,
        `first_attempt_at` INT DEFAULT 0,
        `locked_until` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . htmlspecialchars($e->getMessage()));
}

// ------------------------------------------------------------------
// توابع پایه تنظیمات
// ------------------------------------------------------------------
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string)$val : $default;
}
function setSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

const DEFAULT_PANEL_PASSWORD = 'admin';
function checkPanelPassword(PDO $pdo, string $inputPassword): bool {
    $hash = getSetting($pdo, 'webpanel_password_hash', '');
    if ($hash === '') return hash_equals(DEFAULT_PANEL_PASSWORD, $inputPassword);
    return password_verify($inputPassword, $hash);
}
function currentPanelUrl(string $file = 'webpanel.php'): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return $scheme . '://' . $host . $dir . '/' . $file;
}

// ------------------------------------------------------------------
// آی‌پی واقعی کاربر (توجه: اگر هاست شما پشت پراکسی/CDN است و هدر
// X-Forwarded-For را درست تنظیم می‌کند این مقدار درست خوانده می‌شود؛ در غیر
// این صورت همان REMOTE_ADDR واقعی سرور استفاده می‌شود)
// ------------------------------------------------------------------
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ------------------------------------------------------------------
// محدودیت تلاش ورود (ضد حدس رمز): بعد از ۵ تلاش اشتباه در یک بازه‌ی ۱۵
// دقیقه‌ای، همان IP به مدت ۱۵ دقیقه قفل می‌شود.
// ------------------------------------------------------------------
const LOGIN_MAX_ATTEMPTS  = 5;
const LOGIN_ATTEMPT_WINDOW = 900; // 15 دقیقه
const LOGIN_LOCK_DURATION  = 900; // 15 دقیقه

function loginThrottleStatus(PDO $pdo, string $ip): array {
    $stmt = $pdo->prepare("SELECT * FROM panel_login_throttle WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    $now = time();
    if ($row && (int)$row['locked_until'] > $now) {
        return ['locked' => true, 'seconds_left' => (int)$row['locked_until'] - $now];
    }
    return ['locked' => false, 'seconds_left' => 0];
}

function loginThrottleRecordFailure(PDO $pdo, string $ip): void {
    $now = time();
    $stmt = $pdo->prepare("SELECT * FROM panel_login_throttle WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->prepare("INSERT INTO panel_login_throttle (ip, attempts, first_attempt_at, locked_until) VALUES (?, 1, ?, 0)")
            ->execute([$ip, $now]);
        return;
    }

    // اگر بازه‌ی قبلی تمام شده، شمارش از نو شروع می‌شود
    if ($now - (int)$row['first_attempt_at'] > LOGIN_ATTEMPT_WINDOW) {
        $pdo->prepare("UPDATE panel_login_throttle SET attempts = 1, first_attempt_at = ?, locked_until = 0 WHERE ip = ?")
            ->execute([$now, $ip]);
        return;
    }

    $attempts = (int)$row['attempts'] + 1;
    $lockedUntil = ($attempts >= LOGIN_MAX_ATTEMPTS) ? ($now + LOGIN_LOCK_DURATION) : 0;
    $pdo->prepare("UPDATE panel_login_throttle SET attempts = ?, locked_until = ? WHERE ip = ?")
        ->execute([$attempts, $lockedUntil, $ip]);
}

function loginThrottleReset(PDO $pdo, string $ip): void {
    $pdo->prepare("DELETE FROM panel_login_throttle WHERE ip = ?")->execute([$ip]);
}

// ------------------------------------------------------------------
// قفل دسترسی پنل بر اساس IP: پیش‌فرض «نامحدود» است (هر IP می‌تواند وارد
// صفحه‌ی ورود شود)؛ مدیر می‌تواند از تب «امنیت» آن را روی «فقط یک IP خاص»
// بگذارد که در آن صورت IP فعلی خودش هنگام فعال‌سازی ثبت می‌شود.
// ------------------------------------------------------------------
function panelIpAccessAllowed(PDO $pdo, string $ip): bool {
    $mode = getSetting($pdo, 'panel_ip_lock_mode', 'unlimited');
    if ($mode !== 'single') return true;
    $allowed = getSetting($pdo, 'panel_allowed_ip', '');
    if ($allowed === '') return true; // هنوز هیچ IP ثبت نشده؛ قفل را اجرا نکن
    return hash_equals($allowed, $ip);
}

// ------------------------------------------------------------------
// تاریخ شمسی (دقیقاً همان الگوریتم bot.php)
// ------------------------------------------------------------------
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + 33 * ((int)($days / 12053)); $days %= 12053;
    $jy += 4 * ((int)($days / 1461)); $days %= 1461;
    if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + (int)($days / 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + (int)(($days - 186) / 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}
function safe_timestamp($dateStr) {
    if (empty($dateStr) || str_starts_with((string)$dateStr, '0000-00-00')) return time();
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? $ts : time();
}
function jdate($format, $timestamp = null) {
    if ($timestamp === null || $timestamp === false) $timestamp = time();
    $g = date('Y-m-d-H-i-s', $timestamp);
    list($gy, $gm, $gd, $h, $i, $s) = explode('-', $g);
    list($jy, $jm, $jd) = gregorian_to_jalali((int)$gy, (int)$gm, (int)$gd);
    $jm = str_pad((string)$jm, 2, '0', STR_PAD_LEFT);
    $jd = str_pad((string)$jd, 2, '0', STR_PAD_LEFT);
    return str_replace(['Y', 'm', 'd', 'H', 'i', 's'], [$jy, $jm, $jd, $h, $i, $s], $format);
}

// ------------------------------------------------------------------
// ارتباط با تلگرام
// ------------------------------------------------------------------
function tgApi(string $method, array $params = [], int $timeout = 15): array {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_TIMEOUT        => $timeout
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if (!is_array($data)) $data = ['ok' => false, 'description' => $err ?: 'invalid_response'];
    return $data;
}

// ------------------------------------------------------------------
// بکاپ‌گیری (هماهنگ با bot.php)
// ------------------------------------------------------------------
function createDbBackupFile(PDO $pdo): string {
    $tables  = ['users', 'user_states', 'settings', 'admins', 'extractions'];
    $sqlDump = "-- DB Backup\n-- Time: " . date('Y-m-d H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table) {
        try {
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
            foreach ($rows as $row) {
                $keys = array_keys($row);
                $vals = array_map(fn($v) => is_null($v) ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                $sqlDump .= "INSERT IGNORE INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $vals) . ");\n";
            }
            $sqlDump .= "\n";
        } catch (Exception $e) { }
    }
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $fileName = sys_get_temp_dir() . '/db_backup_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.sql';
    file_put_contents($fileName, $sqlDump);
    return $fileName;
}
// نکته: تابع بکاپ سورس (createSourceBackupFile) به‌طور کامل از پروژه حذف شد.
// بکاپ‌گیری فقط از دیتابیس انجام می‌شود (createDbBackupFile).

// توکن کرون مخصوص همین نصب: از روی BOT_TOKEN شما ساخته می‌شود (نه یک مقدار
// ثابت که در همه‌ی نصب‌های این کد یکسان باشد). دقیقاً همان مقداری است که
// bot.php هنگام بررسی درخواست کرون‌جاب انتظار دارد.
function getCronToken(): string {
    return substr(hash('sha256', BOT_TOKEN . '|cron_backup_secret_v1'), 0, 24);
}

// ------------------------------------------------------------------
// مدیریت تاپیک‌های گزارش (هماهنگ با sendTopicReport در bot.php)
// ------------------------------------------------------------------
const REPORT_THREAD_KEYS = [
    'report_join_thread_id'     => 'ورود کاربران 🚪',
    'report_realtime_thread_id' => 'گزارش لحظه‌ای ⏳',
    'report_backup_thread_id'   => 'بکاپ سیستم 📦',
    'report_extract_thread_id'  => 'گزارش استخراج 📊',
];

function resetAndCreateTopics(PDO $pdo): array {
    $log = [];
    $groups = [
        ['id' => getSetting($pdo, 'report_group_id', ''),   'suffix' => ''],
        ['id' => getSetting($pdo, 'report_group_id_2', ''), 'suffix' => '_2'],
    ];
    foreach ($groups as $grp) {
        if (empty($grp['id'])) continue;
        foreach (REPORT_THREAD_KEYS as $baseKey => $topicName) {
            $tKey = $baseKey . $grp['suffix'];
            setSetting($pdo, $tKey, '');
            $res = tgApi('createForumTopic', ['chat_id' => $grp['id'], 'name' => $topicName]);
            if (!empty($res['ok'])) {
                setSetting($pdo, $tKey, (string)$res['result']['message_thread_id']);
                $log[] = "✅ گروه {$grp['id']} / {$topicName} ساخته شد.";
            } else {
                $desc = strtolower((string)($res['description'] ?? ''));
                if (strpos($desc, 'not a forum') !== false || strpos($desc, 'not enough rights') !== false) {
                    setSetting($pdo, $tKey, 'NOT_FORUM');
                }
                $log[] = "❌ گروه {$grp['id']} / {$topicName}: " . ($res['description'] ?? 'خطای نامعلوم');
            }
        }
    }
    if (empty($log)) $log[] = "⚠️ هیچ گروهی تنظیم نشده است.";
    return $log;
}

function testTopics(PDO $pdo): array {
    $log = [];
    $groups = [
        ['id' => getSetting($pdo, 'report_group_id', ''),   'suffix' => ''],
        ['id' => getSetting($pdo, 'report_group_id_2', ''), 'suffix' => '_2'],
    ];
    foreach ($groups as $grp) {
        if (empty($grp['id'])) continue;
        foreach (REPORT_THREAD_KEYS as $baseKey => $topicName) {
            $tKey     = $baseKey . $grp['suffix'];
            $threadId = getSetting($pdo, $tKey, '');
            $params   = ['chat_id' => $grp['id'], 'text' => "🧪 تست تاپیک: {$topicName}"];
            if (!empty($threadId) && $threadId !== 'NOT_FORUM') $params['message_thread_id'] = (int)$threadId;
            $res = tgApi('sendMessage', $params);
            $log[] = (!empty($res['ok']) ? "✅ " : "❌ ") . "{$grp['id']} / {$topicName}" . (!empty($res['ok']) ? '' : (': ' . ($res['description'] ?? '')));
        }
    }
    if (empty($log)) $log[] = "⚠️ هیچ گروهی تنظیم نشده است.";
    return $log;
}

// ------------------------------------------------------------------
// رجیستری مرکزی دکمه‌ها - دقیقاً هماهنگ با getBtnRegistry() در bot.php
// (نکته: کلید btn_admin_settingspanel قبلاً در پنل قدیمی buttons_panel.php
// جا افتاده بود و اینجا اضافه شد. کلید btn_admin_restore هم به همین ترتیب
// اضافه شد تا دکمه‌ی «📥 ایمپورت بک‌آپ» در پنل وب قابل مدیریت باشد.
// آپدیت جدید: کلید btn_admin_backup هم که در نسخه جدید bot.php اضافه شده
// (دکمه‌ی «💾 بک‌آپ» که به‌جای سه دکمه‌ی قدیمی دیتابیس/سورس/ایمپورت در سطح
// اول منوی ادمین می‌نشیند) اینجا اضافه شد. دکمه‌های دیتابیس/سورس/ایمپورت
// با فلگ backup_sub_only مشخص شده‌اند چون طبق فیلتر امنیتی bot.php
// (getBackupSubOnlyKeys) هرگز مستقیم در سطح اول «پنل مدیریت» رندر
// نمی‌شوند و فقط از داخل دکمه‌ی «💾 بک‌آپ» در دسترس‌اند؛ برای همین در
// ادیتور چیدمان «پنل مدیریت» این پنل هم دیگر پیشنهاد داده نمی‌شوند.)
// ------------------------------------------------------------------
function getButtonRegistry(): array {
    return [
        // منوی اصلی
        'btn_main_analyze' => ['label' => '🔍 آنالیز و استخراج ساب',   'menu' => 'main_menu'],
        'btn_main_account' => ['label' => '🗂 حساب کاربری',            'menu' => 'main_menu'],
        'btn_main_admin'   => ['label' => '👨‍💼 پنل مدیریت ربات',      'menu' => 'main_menu', 'admin_only' => true],

        // منوی پنل مدیریت
        'btn_admin_stats'         => ['label' => '📊 آمار ربات',             'menu' => 'admin_menu'],
        'btn_admin_users'         => ['label' => '📄 لیست کاربران',          'menu' => 'admin_menu'],
        'btn_admin_broadcast'     => ['label' => '📢 پیام همگانی',           'menu' => 'admin_menu'],
        'btn_admin_status'        => ['label' => '⚙️ وضعیت ربات',            'menu' => 'admin_menu', 'dynamic' => true],
        'btn_admin_lock'          => ['label' => '🔐 قفل کانال',             'menu' => 'admin_menu'],
        'btn_admin_admins'        => ['label' => '👮‍♂️ مدیران',              'menu' => 'admin_menu', 'supadmin_only' => true],
        'btn_admin_reports'       => ['label' => '📢 تنظیمات گزارشات',        'menu' => 'admin_menu'],
        'btn_admin_premium'       => ['label' => '🌟 دکمه ها',                'menu' => 'admin_menu'],
        'btn_admin_cron'          => ['label' => '⏱ کرون',                   'menu' => 'admin_menu'],
        'btn_admin_backup'        => ['label' => '💾 بک‌آپ',                  'menu' => 'admin_menu'],
        'btn_admin_webpanel'      => ['label' => '🌐 تنظیمات پنل وب',         'menu' => 'admin_menu', 'supadmin_only' => true],
        'btn_admin_settingspanel' => ['label' => '⚙️ پنل تنظیمات کامل',       'menu' => 'admin_menu', 'supadmin_only' => true],
        'btn_admin_settings'      => ['label' => '⚙️ تنظیمات',                'menu' => 'admin_menu'],
        'btn_admin_db'            => ['label' => '💾 بکاپ دیتابیس',           'menu' => 'admin_menu', 'backup_sub_only' => true],
        'btn_admin_restore'       => ['label' => '📥 ایمپورت بک‌آپ',           'menu' => 'admin_menu', 'supadmin_only' => true, 'backup_sub_only' => true],
        'btn_admin_back'          => ['label' => '🔙 بازگشت به منوی اصلی',    'menu' => 'admin_menu'],

        // منوی استخراج (ساب)
        'btn_sub_update' => ['label' => '🔄 بروزرسانی اطلاعات', 'menu' => 'sub_menu'],
        'btn_sub_text'   => ['label' => '📄 دریافت کانفیگ',     'menu' => 'sub_menu'],
        'btn_sub_ip'     => ['label' => '🌐 دریافت آی‌پی',       'menu' => 'sub_menu'],
        'btn_sub_qr1'    => ['label' => '🔲 QR کانفیگ‌ها',       'menu' => 'sub_menu'],
        'btn_sub_qr2'    => ['label' => '🔲 QR ساب',            'menu' => 'sub_menu'],
        // این دکمه همیشه به‌صورت ثابت آخرین ردیف زیر نتیجه‌ی استخراجه (چون آدرسش
        // برای هر استخراج فرق می‌کنه)، برای همین در ادیتور ردیف‌های «سطح استخراج»
        // قابل جابه‌جایی نیست؛ ولی برچسب/رنگ/ایموجیش از همین‌جا قابل تغییره.
        'btn_web_view'   => ['label' => '🖥 مشاهده در وب',     'menu' => 'sub_menu', 'fixed_row' => true],
    ];
}

// کلیدهایی که هرگز نباید مستقیم در چیدمان سطح اول «پنل مدیریت» ظاهر شوند
// (دقیقاً هماهنگ با getBackupSubOnlyKeys() در bot.php)
function getBackupSubOnlyKeys(): array {
    return ['btn_admin_db', 'btn_admin_restore'];
}

function defaultLayouts(): array {
    return [
        'main_menu'  => [['btn_main_analyze'], ['btn_main_account'], ['btn_main_admin']],
        'admin_menu' => [
            ['btn_admin_stats', 'btn_admin_users'],
            ['btn_admin_broadcast', 'btn_admin_status'],
            ['btn_admin_lock', 'btn_admin_admins'],
            ['btn_admin_premium', 'btn_admin_settings'],
            ['btn_admin_reports'],
            ['btn_admin_webpanel', 'btn_admin_settingspanel'],
            ['btn_admin_cron', 'btn_admin_backup'],
            ['btn_admin_back']
        ],
        'sub_menu' => [
            ['btn_sub_update'],
            ['btn_sub_text', 'btn_sub_ip'],
            ['btn_sub_qr1', 'btn_sub_qr2']
        ]
    ];
}

// ------------------------------------------------------------------
// لیست ایموجی‌های قابل «سفارشی‌سازی متن» - هماهنگ با getPremiumTextEmojis() در bot.php
// ------------------------------------------------------------------
function getDefaultTextEmojiChars(): array {
    $chars = [
        '🚀','✅','❌','📊','📦','🔋','⏳','🟢','🔴','👤','⚙️','🔍','🗂','👨‍💼','📢','🔐',
        '👮‍♂️','🌟','⏱','💾','🔙','🏠','🆔','📅','🔗','🌐','🔲','📄','🔄','📈','📉','🔹',
        '📝','📌','📡','💬','🎨','✨','👇','🗑','➕','⛔️','🚪','⚠️','🔸','✔️','🌍','𔲲',
        '⌚️','🏷','👨‍💻','📁','⬅️','➡️','🛠','💳','🔊','🔇',
        '🇮🇷','🇹🇷','🇦🇪','🇸🇦','🇮🇶','🇶🇦','🇧🇭','🇰🇼','🇴🇲','🇾🇪','🇸🇾','🇱🇧','🇯🇴','🇮🇱','🇵🇸',
        '🇩🇪','🇬🇧','🇳🇱','🇫🇷','🇫🇮','🇷🇺','🇵🇱','🇨🇭','🇸🇪','🇮🇹','🇪🇸','🇺🇦','🇩🇰','🇦🇹','🇧🇬','🇷🇴','🇳🇴','🇧🇪','🇮🇪','🇵🇹','🇬🇷','🇨🇿','🇭🇺','🇷🇸','🇭🇷','🇸🇰','🇸🇮','🇪🇪','🇱🇻','🇱🇹','🇮🇸','🇦🇱','🇲🇰','🇧🇦','🇲🇩','🇧🇾','🇨🇾','🇱🇺',
        '🇺🇸','🇨🇦','🇧🇷','🇲🇽','🇦🇷','🇨🇱','🇨🇴','🇵🇪','🇻🇪','🇺🇾','🇵🇦','🇨🇺','🇪🇨','🇵🇾','🇧🇴',
        '🇸🇬','🇮🇳','🇦🇺','🇯🇵','🇰🇷','🇭🇰','🇹🇼','🇻🇳','🇿🇦','🇨🇳','🇲🇾','🇮🇩','🇹🇭','🇵🇰','🇦🇫','🇳🇿',
        '😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃',
        '😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙',
        '😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔',
        '🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥',
        '😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮',
        '🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','😎','🤓',
        '🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺',
        '😦','😧','😨','😰','😥','😢','😭','😱','😖','😣'
    ];
    return array_values(array_unique($chars));
}

const VALID_MENUS  = ['main_menu', 'admin_menu', 'sub_menu'];
const VALID_COLORS = ['primary', 'success', 'danger'];

// کلیدهای تنظیماتی که در «خروجی/ورودی» پشتیبان JSON قرار می‌گیرند
// (اجتماع کلیدهای دو پنل قدیمی buttons_panel.php + settings_panel.php)
const EXPORTABLE_SETTING_KEYS = [
    'layout_main_menu', 'layout_admin_menu', 'layout_sub_menu',
    'btn_labels', 'premium_colors', 'premium_emojis', 'premium_text_emojis',
    'fj_status', 'fj_channel',
    'report_status', 'report_group_id', 'report_group_id_2',
    'cron_interval', 'public_mode', 'show_account_btn',
];

// ------------------------------------------------------------------
// اعمال قفل IP (در صورت فعال بودن) قبل از هر مسیر دیگری، حتی قبل از فرم ورود
// ------------------------------------------------------------------
$CLIENT_IP = getClientIp();
if (!isset($_GET['logout']) && !panelIpAccessAllowed($pdo, $CLIENT_IP)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "دسترسی از این IP مجاز نیست. (IP شما: {$CLIENT_IP})";
    exit;
}

// ------------------------------------------------------------------
// دانلود مستقیم بکاپ کامل (باید قبل از هر خروجی دیگری هندل شود)
// ------------------------------------------------------------------
if (isset($_GET['api']) && $_GET['api'] === 'download_db_backup') {
    if (empty($_SESSION['panel_auth'])) { http_response_code(401); exit('Unauthorized'); }
    $file = createDbBackupFile($pdo);
    $downloadName = 'db_backup_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/sql');
    if (!file_exists($file)) { http_response_code(500); exit('Backup failed'); }
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    @unlink($file);
    exit;
}

// ------------------------------------------------------------------
// سایر API ها (JSON) - اجتماع همه APIهای دو پنل قدیمی
// ------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['panel_auth'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'unauthorized']); exit; }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
    $api = $_GET['api'];

    // ================= چیدمان دکمه‌ها =================
    if ($api === 'save') {
        $menuKey       = $body['menu'] ?? '';
        $layout        = $body['layout'] ?? null;
        $registry      = getButtonRegistry();
        $backupSubOnly = getBackupSubOnlyKeys();

        if (!in_array($menuKey, VALID_MENUS, true) || !is_array($layout)) {
            echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit;
        }

        $cleanLayout = [];
        foreach ($layout as $row) {
            if (!is_array($row)) continue;
            $cleanRow = [];
            foreach ($row as $btnKey) {
                $btnKey = (string)$btnKey;
                if (!isset($registry[$btnKey]) || $registry[$btnKey]['menu'] !== $menuKey) continue;
                // فیلتر امنیتی هماهنگ با bot.php: این کلیدها هرگز مستقیم در سطح
                // اول «پنل مدیریت» ذخیره نمی‌شوند، فقط از داخل دکمه‌ی «💾 بک‌آپ» در دسترسند.
                if ($menuKey === 'admin_menu' && in_array($btnKey, $backupSubOnly, true)) continue;
                $cleanRow[] = $btnKey;
            }
            if (!empty($cleanRow)) $cleanLayout[] = $cleanRow;
        }

        setSetting($pdo, 'layout_' . $menuKey, json_encode($cleanLayout, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }

    if ($api === 'reset') {
        $menuKey = $body['menu'] ?? '';
        if (!in_array($menuKey, VALID_MENUS, true)) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }
        setSetting($pdo, 'layout_' . $menuKey, '');
        echo json_encode(['ok' => true]); exit;
    }

    // ================= متن سفارشی دکمه‌ها (btn_labels) =================
    if ($api === 'save_labels') {
        $labels   = $body['labels'] ?? null;
        $registry = getButtonRegistry();
        if (!is_array($labels)) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }

        $clean = [];
        foreach ($labels as $btnKey => $text) {
            $btnKey = (string)$btnKey;
            if (!isset($registry[$btnKey])) continue;
            $text = trim((string)$text);
            if ($text === '') continue;
            if (mb_strlen($text, 'UTF-8') > 64) $text = mb_substr($text, 0, 64, 'UTF-8');
            $clean[$btnKey] = $text;
        }

        setSetting($pdo, 'btn_labels', json_encode($clean, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }

    if ($api === 'reset_label') {
        $btnKey   = (string)($body['btn_key'] ?? '');
        $registry = getButtonRegistry();
        if (!isset($registry[$btnKey])) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }
        $labels = json_decode(getSetting($pdo, 'btn_labels', '{}'), true);
        if (!is_array($labels)) $labels = [];
        unset($labels[$btnKey]);
        setSetting($pdo, 'btn_labels', json_encode($labels, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'default' => $registry[$btnKey]['label']]); exit;
    }

    // ================= رنگ دکمه‌ها (premium_colors) =================
    if ($api === 'set_color') {
        $btnKey   = (string)($body['btn_key'] ?? '');
        $color    = (string)($body['color'] ?? '');
        $registry = getButtonRegistry();

        if (!isset($registry[$btnKey])) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }

        $colors = json_decode(getSetting($pdo, 'premium_colors', '{}'), true);
        if (!is_array($colors)) $colors = [];

        if ($color === '' || $color === 'default') {
            unset($colors[$btnKey]);
        } elseif (in_array($color, VALID_COLORS, true)) {
            $colors[$btnKey] = $color;
        } else {
            echo json_encode(['ok' => false, 'error' => 'invalid_color']); exit;
        }

        setSetting($pdo, 'premium_colors', json_encode($colors, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }

    // ================= ایموجی دکمه‌ها (premium_emojis) =================
    if ($api === 'save_button_emojis') {
        $emojis   = $body['emojis'] ?? null;
        $registry = getButtonRegistry();
        if (!is_array($emojis)) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }

        $clean = [];
        foreach ($emojis as $btnKey => $val) {
            $btnKey = (string)$btnKey;
            if (!isset($registry[$btnKey])) continue;
            $val = trim((string)$val);
            if ($val === '') continue;
            if (!preg_match('/^\d+$/', $val)) continue;
            $clean[$btnKey] = $val;
        }

        setSetting($pdo, 'premium_emojis', json_encode($clean, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }

    if ($api === 'reset_button_emoji') {
        $btnKey   = (string)($body['btn_key'] ?? '');
        $registry = getButtonRegistry();
        if (!isset($registry[$btnKey])) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }
        $emojis = json_decode(getSetting($pdo, 'premium_emojis', '{}'), true);
        if (!is_array($emojis)) $emojis = [];
        unset($emojis[$btnKey]);
        setSetting($pdo, 'premium_emojis', json_encode($emojis, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }

    // ================= ایموجی متن‌ها (premium_text_emojis) =================
    if ($api === 'save_text_emojis') {
        $emojis     = $body['emojis'] ?? null;
        $validChars = getDefaultTextEmojiChars();
        if (!is_array($emojis)) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }

        $clean = [];
        foreach ($emojis as $char => $val) {
            $char = (string)$char;
            if (!in_array($char, $validChars, true)) continue;
            $val = trim((string)$val);
            if ($val === '') continue;
            if (!preg_match('/^\d+$/', $val)) continue;
            $clean[$char] = $val;
        }

        setSetting($pdo, 'premium_text_emojis', json_encode($clean, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }

    if ($api === 'reset_text_emoji') {
        $char       = (string)($body['char'] ?? '');
        $validChars = getDefaultTextEmojiChars();
        if (!in_array($char, $validChars, true)) { echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit; }
        $emojis = json_decode(getSetting($pdo, 'premium_text_emojis', '{}'), true);
        if (!is_array($emojis)) $emojis = [];
        unset($emojis[$char]);
        setSetting($pdo, 'premium_text_emojis', json_encode($emojis, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }

    // ================= قفل کانال =================
    if ($api === 'toggle_fj') {
        $cur = getSetting($pdo, 'fj_status', 'off');
        setSetting($pdo, 'fj_status', $cur === 'on' ? 'off' : 'on');
        echo json_encode(['ok' => true, 'status' => getSetting($pdo, 'fj_status', 'off')]); exit;
    }
    if ($api === 'set_fj_channel') {
        $ch = trim((string)($body['channel'] ?? ''));
        if ($ch === '' || (!str_starts_with($ch, '@') && !str_starts_with($ch, '-100'))) {
            echo json_encode(['ok' => false, 'error' => 'invalid_channel']); exit;
        }
        setSetting($pdo, 'fj_channel', $ch);
        echo json_encode(['ok' => true]); exit;
    }
    if ($api === 'remove_fj_channel') {
        setSetting($pdo, 'fj_channel', '');
        echo json_encode(['ok' => true]); exit;
    }
    if ($api === 'test_fj_channel') {
        $ch = getSetting($pdo, 'fj_channel', '');
        if (empty($ch)) { echo json_encode(['ok' => false, 'error' => 'no_channel']); exit; }
        $res = tgApi('getChat', ['chat_id' => $ch]);
        echo json_encode(['ok' => !empty($res['ok']), 'title' => $res['result']['title'] ?? null, 'description' => $res['description'] ?? null]); exit;
    }

    // ================= گزارشات =================
    if ($api === 'toggle_report_status') {
        $cur = getSetting($pdo, 'report_status', 'off');
        setSetting($pdo, 'report_status', $cur === 'on' ? 'off' : 'on');
        echo json_encode(['ok' => true, 'status' => getSetting($pdo, 'report_status', 'off')]); exit;
    }
    if ($api === 'set_report_group') {
        $slot = (string)($body['slot'] ?? '1');
        $gid  = trim((string)($body['group_id'] ?? ''));
        if ($gid === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
        $key = $slot === '2' ? 'report_group_id_2' : 'report_group_id';
        setSetting($pdo, $key, $gid);
        foreach (array_keys(REPORT_THREAD_KEYS) as $bk) setSetting($pdo, $bk . ($slot === '2' ? '_2' : ''), '');
        if ($slot !== '2') setSetting($pdo, 'report_status', 'on');
        echo json_encode(['ok' => true]); exit;
    }
    if ($api === 'remove_report_group_2') {
        setSetting($pdo, 'report_group_id_2', '');
        foreach (array_keys(REPORT_THREAD_KEYS) as $bk) setSetting($pdo, $bk . '_2', '');
        echo json_encode(['ok' => true]); exit;
    }
    if ($api === 'reset_report_topics') { echo json_encode(['ok' => true, 'log' => resetAndCreateTopics($pdo)]); exit; }
    if ($api === 'test_report_topics')  { echo json_encode(['ok' => true, 'log' => testTopics($pdo)]); exit; }

    // ================= کرون =================
    if ($api === 'set_cron') {
        $val = (string)($body['interval'] ?? '300');
        if (!preg_match('/^\d+$/', $val)) { echo json_encode(['ok' => false]); exit; }
        setSetting($pdo, 'cron_interval', $val);
        echo json_encode(['ok' => true]); exit;
    }

    // ================= تنظیمات عمومی =================
    if ($api === 'toggle_public_mode') {
        $cur = getSetting($pdo, 'public_mode', '0');
        setSetting($pdo, 'public_mode', $cur === '1' ? '0' : '1');
        echo json_encode(['ok' => true, 'status' => getSetting($pdo, 'public_mode', '0')]); exit;
    }
    if ($api === 'toggle_show_account_btn') {
        $cur = getSetting($pdo, 'show_account_btn', 'on');
        setSetting($pdo, 'show_account_btn', $cur === 'on' ? 'off' : 'on');
        echo json_encode(['ok' => true, 'status' => getSetting($pdo, 'show_account_btn', 'on')]); exit;
    }

    // ================= مدیران =================
    if ($api === 'add_admin') {
        $id = preg_replace('/[^0-9]/', '', (string)($body['admin_id'] ?? ''));
        if (empty($id)) { echo json_encode(['ok' => false, 'error' => 'invalid_id']); exit; }
        $pdo->prepare("INSERT INTO users (user_id, is_admin) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_admin = 1")->execute([$id]);
        $pdo->prepare("INSERT IGNORE INTO admins (admin_id) VALUES (?)")->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }
    if ($api === 'remove_admin') {
        $id = preg_replace('/[^0-9]/', '', (string)($body['admin_id'] ?? ''));
        if (empty($id)) { echo json_encode(['ok' => false]); exit; }
        if (defined('ADMIN_ID') && (string)ADMIN_ID === (string)$id) { echo json_encode(['ok' => false, 'error' => 'cannot_remove_supadmin']); exit; }
        $pdo->prepare("UPDATE users SET is_admin = 0 WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM admins WHERE admin_id = ?")->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }
    if ($api === 'list_admins') {
        $rows = $pdo->query("SELECT user_id, joined_at FROM users WHERE is_admin = 1 ORDER BY joined_at DESC")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (string)$r['user_id'], 'joined' => jdate('Y/m/d H:i', safe_timestamp($r['joined_at'] ?? ''))];
        }
        echo json_encode(['ok' => true, 'admins' => $out, 'supadmin' => defined('ADMIN_ID') ? (string)ADMIN_ID : null]); exit;
    }

    // ================= کاربران =================
    if ($api === 'list_users') {
        $page  = max(0, (int)($_GET['page'] ?? 0));
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = 15; $offset = $page * $limit;

        if ($q !== '' && preg_match('/^\d+$/', $q)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$q]);
            $rows  = $stmt->fetchAll();
            $total = count($rows);
        } else {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stmt  = $pdo->prepare("SELECT * FROM users ORDER BY joined_at DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string)$r['user_id'],
                'joined' => jdate('Y/m/d H:i', safe_timestamp($r['joined_at'] ?? '')),
                'blocked' => (bool)($r['is_blocked'] ?? 0),
                'admin' => (bool)($r['is_admin'] ?? 0),
                'points' => (int)($r['points'] ?? 0),
            ];
        }
        echo json_encode(['ok' => true, 'users' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit]); exit;
    }
    if ($api === 'toggle_block') {
        $id = preg_replace('/[^0-9]/', '', (string)($body['user_id'] ?? ''));
        if (empty($id)) { echo json_encode(['ok' => false]); exit; }
        $stmt = $pdo->prepare("SELECT is_blocked FROM users WHERE user_id = ?");
        $stmt->execute([$id]);
        $cur = $stmt->fetchColumn();
        if ($cur === false) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
        $newVal = empty($cur) ? 1 : 0;
        $pdo->prepare("UPDATE users SET is_blocked = ? WHERE user_id = ?")->execute([$newVal, $id]);
        echo json_encode(['ok' => true, 'blocked' => (bool)$newVal]); exit;
    }
    if ($api === 'send_direct_message') {
        $id   = preg_replace('/[^0-9]/', '', (string)($body['user_id'] ?? ''));
        $text = trim((string)($body['text'] ?? ''));
        if (empty($id) || $text === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
        $res = tgApi('sendMessage', ['chat_id' => $id, 'text' => $text]);
        echo json_encode(['ok' => !empty($res['ok']), 'description' => $res['description'] ?? null]); exit;
    }

    // ================= پیام همگانی =================
    if ($api === 'broadcast') {
        $text = trim((string)($body['text'] ?? ''));
        if ($text === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
        $users = $pdo->query("SELECT user_id FROM users WHERE is_blocked = 0")->fetchAll();
        $sent = 0; $failed = 0;
        foreach ($users as $u) {
            $res = tgApi('sendMessage', ['chat_id' => $u['user_id'], 'text' => $text], 5);
            if (!empty($res['ok'])) $sent++; else $failed++;
            usleep(40000);
        }
        echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]); exit;
    }

    // ================= آمار (داشبورد) =================
    if ($api === 'bot_stats') {
        $t0 = microtime(true);
        $me = tgApi('getMe', [], 8);
        $ping = round((microtime(true) - $t0) * 1000);
        $totalUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $blockedUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_blocked=1")->fetchColumn();
        $totalAdmins  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin=1")->fetchColumn();
        $totalExtractions = (int)$pdo->query("SELECT COUNT(*) FROM extractions")->fetchColumn();
        echo json_encode([
            'ok' => true,
            'ping' => $ping,
            'bot_ok' => !empty($me['ok']),
            'bot_username' => $me['result']['username'] ?? null,
            'total_users' => $totalUsers,
            'blocked_users' => $blockedUsers,
            'total_admins' => $totalAdmins,
            'total_extractions' => $totalExtractions,
            'public_mode' => getSetting($pdo, 'public_mode', '0'),
            'report_status' => getSetting($pdo, 'report_status', 'off'),
            'fj_status' => getSetting($pdo, 'fj_status', 'off'),
            'cron_interval' => getSetting($pdo, 'cron_interval', '300'),
        ]); exit;
    }

    // ================= پنل استخراج (مدیریت استخراج‌های کاربران) =================
    if ($api === 'list_extractions') {
        $page  = max(0, (int)($_GET['page'] ?? 0));
        $q     = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? 'all'); // all | active | expired
        $limit = 15; $offset = $page * $limit;

        $where  = [];
        $params = [];
        if ($q !== '' && preg_match('/^\d+$/', $q)) { $where[] = 'user_id = ?'; $params[] = $q; }
        elseif ($q !== '') { $where[] = 'token LIKE ?'; $params[] = '%' . $q . '%'; }

        $now = time();
        if ($status === 'active')  $where[] = '(expire_ts = 0 OR expire_ts > ' . $now . ')';
        if ($status === 'expired') $where[] = '(expire_ts > 0 AND expire_ts <= ' . $now . ')';

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM extractions $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM extractions $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $bindIdx = 1;
        foreach ($params as $p) { $stmt->bindValue($bindIdx++, $p); }
        $stmt->bindValue($bindIdx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $expireTs = (int)($r['expire_ts'] ?? 0);
            $isActive = ($expireTs === 0 || $expireTs > $now) && (
                (float)($r['total_bytes'] ?? 0) <= 0 || ((float)($r['total_bytes'] ?? 0) - (float)($r['used_bytes'] ?? 0)) > 0
            );
            $out[] = [
                'token'      => $r['token'],
                'user_id'    => (string)$r['user_id'],
                'total_configs' => (int)($r['total_configs'] ?? 0),
                'total_bytes'   => (float)($r['total_bytes'] ?? 0),
                'used_bytes'    => (float)($r['used_bytes'] ?? 0),
                'expire_ts'     => $expireTs,
                'active'        => $isActive,
                'created_at'    => jdate('Y/m/d H:i', safe_timestamp($r['created_at'] ?? '')),
                'view_url'      => currentPanelUrl('sub_view.php') . '?id=' . $r['token'],
            ];
        }
        echo json_encode(['ok' => true, 'extractions' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit]); exit;
    }
    if ($api === 'extraction_stats') {
        $now = time();
        $total   = (int)$pdo->query("SELECT COUNT(*) FROM extractions")->fetchColumn();
        $active  = (int)$pdo->query("SELECT COUNT(*) FROM extractions WHERE expire_ts = 0 OR expire_ts > $now")->fetchColumn();
        $expired = $total - $active;
        $uniqueUsers = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM extractions")->fetchColumn();
        $totalConfigs = (int)$pdo->query("SELECT COALESCE(SUM(total_configs),0) FROM extractions")->fetchColumn();
        echo json_encode(['ok' => true, 'total' => $total, 'active' => $active, 'expired' => $expired, 'unique_users' => $uniqueUsers, 'total_configs' => $totalConfigs]); exit;
    }
    if ($api === 'delete_extraction') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($body['token'] ?? ''));
        if ($token === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
        $stmt = $pdo->prepare("DELETE FROM extractions WHERE token = ?");
        $stmt->execute([$token]);
        echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount() > 0]); exit;
    }

    // ================= رمز عبور پنل =================
    if ($api === 'change_password') {
        $current = (string)($body['current'] ?? '');
        $newPass = (string)($body['new_password'] ?? '');
        if (!checkPanelPassword($pdo, $current)) { echo json_encode(['ok' => false, 'error' => 'wrong_current']); exit; }
        if (mb_strlen($newPass, 'UTF-8') < 4) { echo json_encode(['ok' => false, 'error' => 'too_short']); exit; }
        setSetting($pdo, 'webpanel_password_hash', password_hash($newPass, PASSWORD_DEFAULT));
        echo json_encode(['ok' => true]); exit;
    }

    // ================= قفل IP پنل =================
    if ($api === 'ip_lock_status') {
        echo json_encode([
            'ok'          => true,
            'mode'        => getSetting($pdo, 'panel_ip_lock_mode', 'unlimited'),
            'allowed_ip'  => getSetting($pdo, 'panel_allowed_ip', ''),
            'current_ip'  => $CLIENT_IP,
        ]); exit;
    }

    if ($api === 'set_ip_lock') {
        $mode = (string)($body['mode'] ?? '');
        if (!in_array($mode, ['unlimited', 'single'], true)) {
            echo json_encode(['ok' => false, 'error' => 'invalid_input']); exit;
        }
        if ($mode === 'single') {
            // IP فعلیِ همین درخواست (یعنی IP خود مدیر) به‌عنوان تنها IP مجاز ثبت می‌شود
            setSetting($pdo, 'panel_allowed_ip', $CLIENT_IP);
        }
        setSetting($pdo, 'panel_ip_lock_mode', $mode);
        echo json_encode(['ok' => true, 'mode' => $mode, 'allowed_ip' => getSetting($pdo, 'panel_allowed_ip', '')]); exit;
    }

    // ================= خروجی / ورودی تنظیمات (پشتیبان JSON سریع - همه‌چیز یکجا) =================
    if ($api === 'export_settings') {
        $out = ['exported_at' => date('c'), 'data' => []];
        foreach (EXPORTABLE_SETTING_KEYS as $k) $out['data'][$k] = getSetting($pdo, $k, '');
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }
    if ($api === 'import_settings') {
        $data = $body['data'] ?? null;
        if (!is_array($data)) { echo json_encode(['ok' => false]); exit; }
        foreach (EXPORTABLE_SETTING_KEYS as $k) if (array_key_exists($k, $data)) setSetting($pdo, $k, (string)$data[$k]);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown_api']); exit;
}

// ------------------------------------------------------------------
// ورود / خروج (با محدودیت تلاش برای جلوگیری از حدس رمز)
// ------------------------------------------------------------------
if (isset($_POST['login_password'])) {
    $throttle = loginThrottleStatus($pdo, $CLIENT_IP);
    if ($throttle['locked']) {
        $minutesLeft = max(1, (int)ceil($throttle['seconds_left'] / 60));
        $loginError = "به‌دلیل تلاش‌های ناموفق زیاد، ورود از این IP موقتاً مسدود شده است. لطفاً حدود {$minutesLeft} دقیقه دیگر دوباره تلاش کنید.";
    } elseif (checkPanelPassword($pdo, (string)$_POST['login_password'])) {
        loginThrottleReset($pdo, $CLIENT_IP);
        $_SESSION['panel_auth'] = true;
    } else {
        loginThrottleRecordFailure($pdo, $CLIENT_IP);
        $loginError = 'رمز عبور اشتباه است.';
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: webpanel.php'); exit; }

$isLoggedIn = !empty($_SESSION['panel_auth']);

if (!$isLoggedIn) {
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>ورود به پنل مدیریت ربات</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="preconnect" href="https://cdn.jsdelivr.net">
        <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css">
        <style>
            :root{
                --bg:#000103;
                --p1:#0a84ff; --p2:#bf5af2; --p3:#ff375f;
                --glass-fill: rgba(255,255,255,.06);
                --glass-fill-strong: rgba(255,255,255,.11);
                --glass-border: rgba(255,255,255,.35);
                --glass-border-dim: rgba(255,255,255,.14);
            }
            *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
            body {
                font-family:'Vazirmatn', Tahoma, sans-serif; background:var(--bg); color:#f2f2f7;
                display:flex; align-items:center; justify-content:center; height:100vh; margin:0; overflow:hidden; position:relative;
            }
            .blob{ position:absolute; border-radius:50%; filter:blur(90px); opacity:.55; z-index:0; animation: float 16s ease-in-out infinite; mix-blend-mode:screen; }
            .b1{ width:460px; height:460px; background:var(--p1); top:-140px; left:-110px; }
            .b2{ width:420px; height:420px; background:var(--p2); bottom:-160px; right:-110px; animation-delay:-5s; }
            .b3{ width:300px; height:300px; background:var(--p3); top:42%; left:58%; animation-delay:-9s; }
            @keyframes float { 0%,100%{ transform:translate(0,0) scale(1); } 50%{ transform:translate(34px,-28px) scale(1.1); } }
            .box {
                position:relative; z-index:1;
                background: linear-gradient(180deg, var(--glass-fill-strong), var(--glass-fill));
                backdrop-filter: blur(34px) saturate(180%); -webkit-backdrop-filter: blur(34px) saturate(180%);
                padding:40px 34px 34px; border-radius:28px; width:320px;
                border:1px solid var(--glass-border-dim);
                box-shadow:0 24px 70px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.5), inset 0 0 0 1px rgba(255,255,255,.04);
            }
            .box::before{
                content:''; position:absolute; inset:0; border-radius:28px; padding:1px;
                background:linear-gradient(135deg, rgba(255,255,255,.65), rgba(255,255,255,0) 35%, rgba(255,255,255,0) 65%, rgba(255,255,255,.25));
                -webkit-mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
                -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none;
            }
            .lock-icon{ text-align:center; font-size:36px; margin-bottom:8px; filter:drop-shadow(0 4px 18px rgba(191,90,242,.65)); }
            h2 { margin:0 0 22px; text-align:center; font-size:16px; font-weight:800; color:#fff; letter-spacing:-.2px; }
            input {
                width:100%; padding:14px 15px; border-radius:14px; border:1px solid var(--glass-border-dim);
                background:rgba(0,0,0,.35); color:#fff; margin-top:4px; font-size:14px; font-family:inherit;
                transition:.2s border-color, .2s box-shadow, .2s background;
            }
            input:focus{ outline:none; border-color:var(--p1); box-shadow:0 0 0 4px rgba(10,132,255,.22); background:rgba(0,0,0,.5); }
            button {
                width:100%; padding:14px; margin-top:20px; border:none; border-radius:14px;
                background:linear-gradient(135deg, var(--p1), var(--p2) 60%, var(--p3)); color:#fff; font-size:14px; font-weight:800; cursor:pointer;
                box-shadow:0 10px 26px rgba(10,132,255,.4), inset 0 1px 0 rgba(255,255,255,.4); transition:.15s transform, .15s filter;
                letter-spacing:.2px;
            }
            button:hover { filter:brightness(1.12); transform:translateY(-1px); }
            button:active { transform:scale(.97); }
            .err { color:#ff6b6b; text-align:center; margin-top:14px; font-size:13px; font-weight:600; }
        </style>
    </head>
    <body>
        <div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div>
        <form class="box" method="post">
            <div class="lock-icon">🔐</div>
            <h2>ورود به پنل مدیریت ربات</h2>
            <input type="password" name="login_password" placeholder="رمز عبور پنل" required autofocus>
            <button type="submit">ورود به پنل</button>
            <?php if (!empty($loginError)): ?><div class="err">❌ <?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// ------------------------------------------------------------------
// داده اولیه رندر
// ------------------------------------------------------------------
$registry      = getButtonRegistry();
$defaults      = defaultLayouts();
$backupSubOnly = getBackupSubOnlyKeys();

$savedMain  = json_decode(getSetting($pdo, 'layout_main_menu', ''), true);
$savedAdmin = json_decode(getSetting($pdo, 'layout_admin_menu', ''), true);
$savedSub   = json_decode(getSetting($pdo, 'layout_sub_menu', ''), true);

$layoutMain  = is_array($savedMain)  && !empty($savedMain)  ? $savedMain  : $defaults['main_menu'];
$layoutAdmin = is_array($savedAdmin) && !empty($savedAdmin) ? $savedAdmin : $defaults['admin_menu'];
$layoutSub   = is_array($savedSub)   && !empty($savedSub)   ? $savedSub   : $defaults['sub_menu'];

// فیلتر امنیتی هماهنگ با bot.php (buildMenuKeyboard): حتی اگر یک چیدمان قدیمی/دستکاری‌شده
// این سه کلید را مستقیم در سطح اول «پنل مدیریت» ذخیره کرده باشد، اینجا هم حذف می‌شوند تا
// ویرایشگر پنل وب دقیقاً همان چیزی را نشان دهد که در تلگرام واقعاً رندر می‌شود.
$layoutAdmin = array_values(array_filter(array_map(function ($row) use ($backupSubOnly) {
    if (!is_array($row)) return $row;
    return array_values(array_diff($row, $backupSubOnly));
}, $layoutAdmin), function ($row) { return !empty($row); }));

function unusedButtons(array $registry, array $layout, string $menuKey, array $backupSubOnly = []): array {
    $used = [];
    foreach ($layout as $row) {
        if (!is_array($row)) continue;
        foreach ($row as $k) $used[$k] = true;
    }
    $unused = [];
    foreach ($registry as $key => $def) {
        if ($def['menu'] !== $menuKey) continue;
        // دکمه‌های زیرمجموعه‌ی بک‌آپ اصلاً در ادیتور چیدمان «پنل مدیریت» پیشنهاد داده نمی‌شوند
        // چون bot.php هرگز اجازه نمی‌دهد مستقیم در آن سطح قرار بگیرند.
        if ($menuKey === 'admin_menu' && in_array($key, $backupSubOnly, true)) continue;
        // دکمه‌هایی با fixed_row (مثل «مشاهده در وب») موقعیت‌شون همیشه ثابته و
        // در ادیتور ردیف‌ها قابل جابه‌جایی نیستن؛ فقط از تب‌های برچسب/رنگ/ایموجی قابل تغییرن.
        if (!empty($def['fixed_row'])) continue;
        if (empty($used[$key])) $unused[] = $key;
    }
    return $unused;
}

$unusedMain  = unusedButtons($registry, $layoutMain, 'main_menu', $backupSubOnly);
$unusedAdmin = unusedButtons($registry, $layoutAdmin, 'admin_menu', $backupSubOnly);
$unusedSub   = unusedButtons($registry, $layoutSub, 'sub_menu', $backupSubOnly);

$customLabels = json_decode(getSetting($pdo, 'btn_labels', '{}'), true);
if (!is_array($customLabels)) $customLabels = [];

$customColors = json_decode(getSetting($pdo, 'premium_colors', '{}'), true);
if (!is_array($customColors)) $customColors = [];

$customBtnEmojis = json_decode(getSetting($pdo, 'premium_emojis', '{}'), true);
if (!is_array($customBtnEmojis)) $customBtnEmojis = [];

$customTextEmojis = json_decode(getSetting($pdo, 'premium_text_emojis', '{}'), true);
if (!is_array($customTextEmojis)) $customTextEmojis = [];

$defaultTextEmojiChars = getDefaultTextEmojiChars();

$panelUrl          = currentPanelUrl('webpanel.php');
$hasCustomPassword = getSetting($pdo, 'webpanel_password_hash', '') !== '';

$showAccountBtn = getSetting($pdo, 'show_account_btn', 'on') === 'on';
$publicModeOn   = getSetting($pdo, 'public_mode', '0') === '1';

$fjStatus       = getSetting($pdo, 'fj_status', 'off');
$fjChannel      = getSetting($pdo, 'fj_channel', '');
$reportStatus   = getSetting($pdo, 'report_status', 'off');
$reportGroup    = getSetting($pdo, 'report_group_id', '');
$reportGroup2   = getSetting($pdo, 'report_group_id_2', '');
$cronInterval   = getSetting($pdo, 'cron_interval', '300');
$supAdminId     = defined('ADMIN_ID') ? (string)ADMIN_ID : null;

// تب اولیه‌ای که باید باز شود (برای لینک‌های عمیق از داخل ربات، مثلاً webpanel.php?tab=fj)
$ALLOWED_TABS = ['dashboard','main_menu','admin_menu','sub_menu','labels','colors','button_emojis','text_emojis','fj','reports','cron','admins','users','general','broadcast','backup','security'];
$initialTab = (string)($_GET['tab'] ?? 'dashboard');
if (!in_array($initialTab, $ALLOWED_TABS, true)) $initialTab = 'dashboard';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>پنل یکپارچه مدیریت ربات</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css">
<style>
:root {
    --bg-grad1: #06070c; --bg-grad2: #030308;
    --blue: #0a84ff; --purple: #bf5af2; --pink: #ff375f; --green: #30d158; --red: #ff453a; --orange: #ff9f0a; --yellow: #ffd60a;
    --glass-1: rgba(255,255,255,.055); --glass-2: rgba(255,255,255,.09); --glass-3: rgba(255,255,255,.14);
    --glass-border: rgba(255,255,255,.14); --glass-border-strong: rgba(255,255,255,.4);
    --glass-shadow: 0 22px 60px rgba(0,0,0,.55), inset 0 0 0 1px rgba(255,255,255,.03);
    --text: #f5f5f7; --muted: #98a0b3; --muted-2: #6b7386;
    --radius-lg: 26px; --radius-md: 18px; --radius-sm: 12px;
    --ease: cubic-bezier(.22,1,.36,1);
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
::selection{ background: var(--purple); color:#fff; }
html{ scroll-behavior:smooth; }
body {
    font-family:'Vazirmatn', Tahoma, 'Segoe UI', sans-serif;
    background:
        radial-gradient(1200px 800px at 15% -10%, rgba(10,132,255,.10), transparent 60%),
        radial-gradient(1000px 700px at 110% 10%, rgba(191,90,242,.10), transparent 55%),
        radial-gradient(900px 900px at 50% 120%, rgba(255,55,95,.07), transparent 55%),
        linear-gradient(180deg, var(--bg-grad1), var(--bg-grad2));
    color: var(--text); margin:0; padding:26px; position:relative; min-height:100vh; overflow-x:hidden;
}
.page-wrap { max-width: 1300px; margin: 0 auto; display:flex; align-items:flex-start; gap:22px; }
.page { max-width: 1080px; margin: 0; flex:1; min-width:0; }
.sidebar-tabs {
    flex:0 0 232px; position:sticky; top:26px;
    display:flex; flex-direction:column; gap:4px;
    background: linear-gradient(165deg, var(--glass-2), var(--glass-1));
    backdrop-filter: blur(28px) saturate(180%); -webkit-backdrop-filter: blur(28px) saturate(180%);
    border: 1px solid var(--glass-border); border-radius: var(--radius-lg);
    padding:16px 12px; max-height:calc(100vh - 52px); overflow-y:auto;
    box-shadow: var(--glass-shadow);
}
.sidebar-brand {
    font-size:14px; font-weight:800; text-align:center; padding:6px 0 14px;
    background: linear-gradient(90deg, #8ec7ff, #d8b4fe 45%, #ffb3c9 85%);
    -webkit-background-clip:text; background-clip:text; color:transparent;
    border-bottom:1px solid var(--glass-border); margin-bottom:8px; letter-spacing:-.2px;
}
.bg-blob{ position:fixed; border-radius:50%; filter:blur(120px); z-index:-2; pointer-events:none; animation: drift 20s ease-in-out infinite; mix-blend-mode:screen; }
.bg1{ width:560px; height:560px; background:var(--blue); opacity:.20; top:-200px; right:-140px; }
.bg2{ width:520px; height:520px; background:var(--purple); opacity:.18; bottom:-220px; left:-160px; animation-delay:-7s; }
.bg3{ width:380px; height:380px; background:var(--pink); opacity:.12; top:48%; left:48%; animation-delay:-13s; }
@keyframes drift { 0%,100%{ transform:translate(0,0) scale(1); } 50%{ transform:translate(-46px,32px) scale(1.12); } }
.grain{ position:fixed; inset:0; z-index:-1; pointer-events:none;
    background-image:radial-gradient(rgba(255,255,255,.035) 1px, transparent 1px);
    background-size:3px 3px; opacity:.35; }

.glass {
    position:relative;
    background: linear-gradient(165deg, var(--glass-2), var(--glass-1));
    backdrop-filter: blur(28px) saturate(180%); -webkit-backdrop-filter: blur(28px) saturate(180%);
    border: 1px solid var(--glass-border);
    box-shadow: var(--glass-shadow);
    border-radius: var(--radius-lg);
}
.glass::before {
    content:''; position:absolute; inset:0; border-radius:inherit; padding:1px; pointer-events:none;
    background: linear-gradient(135deg, var(--glass-border-strong), rgba(255,255,255,0) 30%, rgba(255,255,255,0) 68%, rgba(255,255,255,.18));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor; mask-composite: exclude;
}

h1 { font-size: 23px; margin-bottom: 4px; font-weight:800; letter-spacing:-.4px;
     background: linear-gradient(90deg, #8ec7ff, #d8b4fe 45%, #ffb3c9 85%);
     -webkit-background-clip:text; background-clip:text; color:transparent; display:inline-block; }
.sub { color: var(--muted); margin-bottom: 22px; font-size: 13px; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 20px; flex-wrap:wrap; gap:10px; }
.logout { color: var(--muted); text-decoration:none; font-size:13px; border:1px solid var(--glass-border); padding:9px 18px; border-radius:999px;
           transition:.2s var(--ease); background:var(--glass-1); backdrop-filter:blur(10px); font-weight:600; }
.logout:hover { color:#fff; border-color:rgba(255,69,58,.5); background:rgba(255,69,58,.1); transform:translateY(-1px); }

.tabs { display:flex; flex-direction:column; gap:2px; overflow:visible; padding:6px; position:relative;
        background:var(--glass-1); border:1px solid var(--glass-border); border-radius:16px; backdrop-filter:blur(14px); }
.tab-btn { padding:11px 14px; border-radius:11px; border:none; background: transparent;
           color: var(--muted); cursor:pointer; font-size:13px; font-weight:700;
           white-space: nowrap; transition:.2s var(--ease); font-family:inherit; text-align:right; width:100%;
           position:relative; border-right:3px solid transparent; }
.tab-btn:hover { color:#fff; background:rgba(255,255,255,.06); }
.tab-btn.active { background: linear-gradient(90deg, rgba(10,132,255,.16), rgba(191,90,242,.10) 70%, transparent);
                   color:#fff; border-right-color: var(--blue); box-shadow: inset 0 0 0 1px rgba(255,255,255,.05); }
.menu-section { display:none; animation: fadeIn .3s var(--ease); }
.menu-section.active { display:block; }
@keyframes fadeIn { from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }

.dash-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:14px; margin-bottom:16px; }
.dash-card { padding:20px; position:relative; overflow:hidden; }
.dash-card .dash-icon { font-size:22px; margin-bottom:10px; display:inline-block; filter:drop-shadow(0 4px 12px rgba(0,0,0,.4)); }
.dash-card .dash-num { font-size:26px; font-weight:800; letter-spacing:-.5px; }
.dash-card .dash-label { font-size:12.5px; color:var(--muted); margin-top:2px; font-weight:600; }
.dash-card.accent-blue .dash-num{ color:#8ec7ff; }
.dash-card.accent-purple .dash-num{ color:#d8b4fe; }
.dash-card.accent-green .dash-num{ color:#7ee8a8; }
.dash-card.accent-pink .dash-num{ color:#ffb3c9; }
.dash-card.accent-orange .dash-num{ color:#ffcf8a; }
.dash-subtitle { font-size:13px; color:#d8b4fe; font-weight:800; margin:22px 0 12px; display:flex; align-items:center; gap:8px; }
.dash-subtitle:first-child { margin-top:0; }

.backup-row { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.file-btn-wrap { position:relative; overflow:hidden; display:inline-block; }
.file-btn-wrap input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; }

.box, .layout-box { padding:20px; margin-bottom:16px; }
.layout-title, .box h3 { font-size:13.5px; color: var(--muted); margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; font-weight:700; }
.box h3 { color:#d8b4fe; font-size:14.5px; margin:0 0 14px 0; }
.stat-pill { font-size:11px; padding:6px 13px; border-radius:999px; background:rgba(10,132,255,.14); color:#8ec7ff;
             border:1px solid rgba(10,132,255,.28); white-space:nowrap; font-weight:700; backdrop-filter:blur(8px); }
.stat-pill.warn { background:rgba(255,159,10,.14); color:#ffcf8a; border-color:rgba(255,159,10,.3); }
.rows { display:flex; flex-direction:column; gap:10px; }
.row { display:flex; gap:8px; align-items:center; background: rgba(0,0,0,.22); border:1px dashed var(--glass-border);
       border-radius:var(--radius-md); padding:12px; min-height:56px; flex-wrap:wrap; transition: border-color .18s var(--ease), background .18s var(--ease), box-shadow .25s var(--ease); }
.row.dragover { border-color: var(--blue); border-style:solid; background:rgba(10,132,255,.09); box-shadow:0 0 0 4px rgba(10,132,255,.15); }
.row-remove { margin-inline-start:auto; background:none; border:none; color: var(--red); cursor:pointer;
              font-size:16px; padding:6px 10px; border-radius:999px; transition:.15s var(--ease); }
.row-remove:hover { background:rgba(255,69,58,.14); }
.chip { position:relative; padding:11px 16px; background: linear-gradient(160deg, rgba(255,255,255,.13), rgba(255,255,255,.045));
        backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
        border:1px solid var(--glass-border); border-radius:999px; cursor:grab; font-size:13px; user-select:none;
        white-space:nowrap; transition: transform .16s var(--ease), box-shadow .16s var(--ease), border-color .16s var(--ease);
        display:inline-flex; align-items:center; gap:7px; }
.chip:hover { transform: translateY(-3px); box-shadow:0 12px 26px rgba(0,0,0,.45), 0 0 0 1px rgba(191,90,242,.3), inset 0 1px 0 rgba(255,255,255,.3); border-color:rgba(191,90,242,.45); }
.chip:active { cursor:grabbing; }
.chip.dragging { opacity:.35; }
.chip .role-tag { font-size:10px; padding:2px 8px; border-radius:999px; line-height:1.6; font-weight:700; }
.chip .role-tag.rt-admin { background:rgba(96,165,250,.18); color:#8ec7ff; border:1px solid rgba(96,165,250,.32); }
.chip .role-tag.rt-supadmin { background:rgba(216,180,254,.18); color:#d8b4fe; border:1px solid rgba(216,180,254,.32); }
.chip.restricted-dim { opacity:.32; filter:grayscale(.7); }
.pool { padding:20px; margin-bottom:16px; }
.pool .rows .row { border-style:solid; background:rgba(0,0,0,.32); }
.actions { display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; }
button.act { padding:12px 20px; border-radius:999px; border:none; cursor:pointer; font-size:13.5px; font-weight:700;
             transition: transform .14s var(--ease), filter .14s var(--ease), box-shadow .14s var(--ease); font-family:inherit;
             position:relative; text-decoration:none; display:inline-block; }
button.act:hover { filter:brightness(1.12); transform:translateY(-2px); }
button.act:active { transform: scale(.96); }
.btn-primary { background: linear-gradient(135deg, var(--blue), #2563eb); color:#fff; box-shadow:0 8px 20px rgba(10,132,255,.4), inset 0 1px 0 rgba(255,255,255,.35); }
.btn-success { background: linear-gradient(135deg, var(--green), #16a34a); color:#fff; box-shadow:0 8px 20px rgba(48,209,88,.35), inset 0 1px 0 rgba(255,255,255,.35); }
.btn-danger  { background: linear-gradient(135deg, var(--red), #dc2626); color:#fff; box-shadow:0 8px 20px rgba(255,69,58,.35), inset 0 1px 0 rgba(255,255,255,.35); }
.btn-orange  { background: linear-gradient(135deg, var(--orange), #c2410c); color:#fff; box-shadow:0 8px 20px rgba(255,159,10,.35); }
.btn-ghost   { background: var(--glass-1); color: var(--text); border:1px solid var(--glass-border) !important; backdrop-filter:blur(10px); }
.add-row-btn { align-self:flex-start; }
.toast { position:fixed; bottom:26px; left:50%; transform:translateX(-50%) translateY(14px) scale(.96); background:rgba(20,22,30,.7);
         backdrop-filter:blur(22px) saturate(180%); border:1px solid var(--glass-border); padding:13px 24px; border-radius:999px; font-size:13px;
         opacity:0; transition:.35s var(--ease); pointer-events:none; z-index:999; box-shadow:0 16px 40px rgba(0,0,0,.55); font-weight:700; }
.toast.show { opacity:1; transform:translateX(-50%) translateY(0) scale(1); }
.toast.toast-success{ border-color:rgba(48,209,88,.4); color:#8ff5ac; }
.toast.toast-error{ border-color:rgba(255,69,58,.4); color:#ff9d97; }
.hint { font-size:12px; color: var(--muted); margin-top:10px; line-height:1.9; }

.role-bar { display:flex; align-items:center; gap:14px; padding:14px 18px; margin-bottom:16px; flex-wrap:wrap; }
.role-bar .role-label { font-size:13px; color: var(--muted); font-weight:700; }
.role-switch { display:flex; gap:4px; background:rgba(0,0,0,.35); border:1px solid var(--glass-border); border-radius:999px; padding:4px; }
.role-switch button { padding:9px 17px; border-radius:999px; border:none; background:transparent; color: var(--muted);
                       font-size:12.5px; cursor:pointer; transition:.25s var(--ease); font-family:inherit; font-weight:700; }
.role-switch button.active-role { background: linear-gradient(135deg, var(--purple), var(--blue)); color:#fff; box-shadow:0 6px 16px rgba(139,92,246,.45); }
.role-bar .role-desc { font-size:12px; color: var(--muted); }

.tg-preview-wrap { padding:20px; margin-bottom:16px; }
.tg-preview { background:linear-gradient(180deg,#0e1621,#0b131c); border-radius:18px; padding:18px; border:1px solid #1c2733; }
.tg-bubble { background:#182533; border-radius:16px; padding:12px 16px; color:#e9edef; font-size:12.5px; max-width:320px; margin-bottom:12px; line-height:1.8; }
.tg-kb { display:flex; flex-direction:column; gap:6px; }
.tg-kb-row { display:flex; gap:6px; }
.tg-kb-btn { flex:1; background:#213040; color:#8ab4f8; text-align:center; padding:11px 8px; border-radius:11px;
             font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; border:1px solid #2a3d52;
             transition:.2s var(--ease); }
.tg-kb-btn.style-primary { background:#1d3a63; color:#93c5fd; border-color:#2c5282; }
.tg-kb-btn.style-success { background:#153a24; color:#6ee7a0; border-color:#1f6b3a; }
.tg-kb-btn.style-danger  { background:#3a1717; color:#fca5a5; border-color:#6b2323; }
.tg-empty { color: var(--muted); font-size:12.5px; text-align:center; padding:18px; }
.hidden { display:none !important; }

.label-group { padding:20px; margin-bottom:16px; }
.label-group h3 { margin:0 0 14px 0; font-size:14px; color: #d8b4fe; font-weight:800; display:flex; align-items:center; gap:8px; }
.label-row { display:flex; align-items:center; gap:10px; padding:11px 0; border-bottom:1px solid var(--glass-border); flex-wrap:wrap; transition:.2s; }
.label-row:last-child { border-bottom:none; }
.label-row.filtered-out { display:none; }
.label-key { flex:0 0 200px; font-size:12px; color: var(--muted); display:flex; align-items:center; gap:6px; }
.label-key .copy-key { cursor:pointer; opacity:.5; font-size:11px; transition:.15s; }
.label-key .copy-key:hover { opacity:1; }
.label-input { flex:1; min-width:180px; padding:11px 14px; border-radius:12px; border:1px solid var(--glass-border);
                background: rgba(0,0,0,.28); color:var(--text); font-size:13px; font-family:inherit; transition:.2s var(--ease); }
.label-input:focus{ outline:none; border-color:var(--blue); box-shadow:0 0 0 4px rgba(10,132,255,.16); background:rgba(0,0,0,.4); }
.label-reset { background:var(--glass-1); border:1px solid var(--glass-border); color: var(--muted); border-radius:999px;
                padding:9px 14px; cursor:pointer; font-size:12px; transition:.2s var(--ease); font-family:inherit; font-weight:600; }
.label-reset:hover { color:#fff; border-color:#8ec7ff; background:rgba(10,132,255,.12); }

.search-box { position:relative; margin-bottom:16px; }
.search-box input { width:100%; padding:13px 44px 13px 16px; border-radius:999px; border:1px solid var(--glass-border);
                     background: var(--glass-1); backdrop-filter:blur(16px); color:var(--text); font-size:13.5px; font-family:inherit; transition:.2s var(--ease); }
.search-box input:focus{ outline:none; border-color:var(--purple); box-shadow:0 0 0 4px rgba(191,90,242,.16); }
.search-box .search-icon { position:absolute; inset-inline-end:16px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none; }

.color-opt-wrap { display:flex; gap:6px; flex-wrap:wrap; }
.color-opt { padding:8px 14px !important; font-size:11.5px !important; opacity:.4; transition:.2s var(--ease); }
.color-opt.active-opt { opacity:1; box-shadow:0 0 0 2px rgba(255,255,255,.55), 0 8px 20px rgba(0,0,0,.35) !important; }

.settings-box { padding:26px; max-width:460px; }
.settings-box label { display:block; font-size:12.5px; color: var(--muted); margin-bottom:6px; margin-top:14px; font-weight:700; }
.settings-box input { width:100%; padding:13px 14px; border-radius:12px; border:1px solid var(--glass-border);
                       background: rgba(0,0,0,.28); color:var(--text); font-size:14px; font-family:inherit; transition:.2s var(--ease); }
.settings-box input:focus{ outline:none; border-color:var(--blue); box-shadow:0 0 0 4px rgba(10,132,255,.16); }
.url-box { background: rgba(0,0,0,.3); border:1px solid var(--glass-border); border-radius:12px; padding:12px 14px;
           font-size:13px; direction:ltr; text-align:left; word-break:break-all; color:#8ec7ff; }
.copy-btn { margin-top:8px; }
.badge { display:inline-block; font-size:11px; padding:4px 10px; border-radius:999px; background:rgba(96,165,250,.18); color:#8ec7ff; margin-inline-start:8px; font-weight:700; }

.field-row { display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
.field-row label { flex:0 0 150px; font-size:12.5px; color:var(--muted); font-weight:700; }
input[type=text], input[type=password], input[type=number], textarea, select {
    flex:1; min-width:180px; padding:12px 14px; border-radius:12px; border:1px solid var(--glass-border);
    background:rgba(0,0,0,.28); color:var(--text); font-size:13.5px; font-family:inherit; transition:.2s var(--ease);
}
select { cursor:pointer; }
textarea { resize:vertical; min-height:80px; width:100%; }
input:focus, textarea:focus, select:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 4px rgba(10,132,255,.16); background:rgba(0,0,0,.4); }
.pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 14px; border-radius:999px; font-weight:700; }
.pill.on { background:rgba(48,209,88,.15); color:#7ee8a8; border:1px solid rgba(48,209,88,.3); }
.pill.off { background:rgba(255,69,58,.15); color:#ff9d97; border:1px solid rgba(255,69,58,.3); }
.switch { position:relative; width:52px; height:30px; flex:0 0 52px; }
.switch input { opacity:0; width:0; height:0; }
.slider { position:absolute; cursor:pointer; inset:0; background:rgba(255,255,255,.12); border:1px solid var(--glass-border); border-radius:999px; transition:.25s var(--ease); }
.slider::before { content:''; position:absolute; height:22px; width:22px; right:3px; top:3px; background:#fff; border-radius:50%; transition:.25s var(--ease); box-shadow:0 2px 6px rgba(0,0,0,.4); }
.switch input:checked + .slider { background:linear-gradient(135deg, var(--green), #16a34a); }
.switch input:checked + .slider::before { transform:translateX(-22px); }
table.data-table { width:100%; border-collapse:collapse; font-size:13px; }
table.data-table th, table.data-table td { padding:10px 8px; text-align:right; border-bottom:1px solid var(--glass-border); }
table.data-table th { color:var(--muted); font-weight:700; font-size:12px; }
table.data-table code { background:rgba(0,0,0,.3); padding:2px 8px; border-radius:8px; font-size:12px; direction:ltr; display:inline-block; }
.mini-btn { padding:6px 12px; font-size:11.5px; border-radius:999px; border:1px solid var(--glass-border); background:var(--glass-1); color:var(--text); cursor:pointer; font-family:inherit; margin-inline-start:4px; transition:.15s; }
.mini-btn:hover { filter:brightness(1.2); }
.mini-btn.mb-danger { color:#ff9d97; border-color:rgba(255,69,58,.35); }
.mini-btn.mb-success { color:#7ee8a8; border-color:rgba(48,209,88,.35); }
.mini-btn.mb-primary { color:#8ec7ff; border-color:rgba(10,132,255,.35); }
.pager { display:flex; gap:8px; justify-content:center; margin-top:14px; align-items:center; }
.pager span { font-size:12.5px; color:var(--muted); }
.log-box { background:rgba(0,0,0,.35); border:1px solid var(--glass-border); border-radius:12px; padding:12px 14px; font-size:12.5px; margin-top:12px; max-height:220px; overflow:auto; line-height:1.9; white-space:pre-wrap; direction:ltr; text-align:left; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.empty-note { text-align:center; color:var(--muted); font-size:13px; padding:20px; }

@media (max-width: 980px) {
    .page-wrap { flex-direction:column; }
    .sidebar-tabs { position:static; flex-direction:row; overflow-x:auto; overflow-y:visible; max-height:none; width:100%; flex:1 1 auto; padding:10px; }
    .sidebar-brand { display:none; }
    .tabs { flex-direction:row; }
    .tab-btn { width:auto; text-align:center; border-right:none; border-bottom:3px solid transparent; border-radius:11px 11px 0 0; }
    .tab-btn.active { border-right-color:transparent; border-bottom-color: var(--blue); }
    .page { max-width:100%; }
}
@media (max-width: 700px) { .grid2 { grid-template-columns:1fr; } .field-row label { flex-basis:100%; } }
@media (max-width: 640px) { body{ padding:16px; } .label-key{ flex-basis:100%; } }
</style>
</head>
<body>
<div class="bg-blob bg1"></div><div class="bg-blob bg2"></div><div class="bg-blob bg3"></div>
<div class="grain"></div>

<div class="page-wrap">
<div class="sidebar-tabs" id="sidebar-tabs">
    <div class="sidebar-brand">🧩 پنل مدیریت</div>
    <div class="tabs">
    <button class="tab-btn" data-tab="dashboard">🧭 داشبورد</button>
    <button class="tab-btn" data-tab="main_menu">🏠 منوی اصلی</button>
    <button class="tab-btn" data-tab="admin_menu">⚙️ پنل مدیریت</button>
    <button class="tab-btn" data-tab="sub_menu">📡 منوی استخراج</button>
    <button class="tab-btn" data-tab="labels">✏️ متن دکمه‌ها</button>
    <button class="tab-btn" data-tab="colors">🎨 رنگ دکمه‌ها</button>
    <button class="tab-btn" data-tab="button_emojis">✨ ایموجی دکمه‌ها</button>
    <button class="tab-btn" data-tab="text_emojis">📝 ایموجی متن‌ها</button>
    <button class="tab-btn" data-tab="fj">🔐 قفل کانال</button>
    <button class="tab-btn" data-tab="reports">📢 گزارشات</button>
    <button class="tab-btn" data-tab="cron">⏱ کرون</button>
    <button class="tab-btn" data-tab="admins">👮‍♂️ مدیران</button>
    <button class="tab-btn" data-tab="users">👥 کاربران</button>
    <button class="tab-btn" data-tab="extractions">📡 مدیریت استخراج‌ها</button>
    <button class="tab-btn" data-tab="general">🛠 تنظیمات عمومی</button>
    <button class="tab-btn" data-tab="broadcast">📣 پیام همگانی</button>
    <button class="tab-btn" data-tab="backup">💾 بکاپ</button>
    <button class="tab-btn" data-tab="security">🔑 امنیت پنل</button>
    </div>
</div>

<div class="page">
<div class="topbar">
    <div>
        <h1>🧩 پنل یکپارچه مدیریت ربات</h1>
        <div class="sub">چیدمان/متن/رنگ/ایموجی دکمه‌ها، قفل کانال، گزارشات، کرون، مدیران، کاربران، تنظیمات عمومی، پیام همگانی، بکاپ و امنیت پنل — همه از یک‌جا.</div>
    </div>
    <a class="logout" href="?logout=1">خروج ⏻</a>
</div>

<!-- ================= داشبورد ================= -->
<div class="menu-section" id="section-dashboard">
    <div class="dash-subtitle">🧩 آمار دکمه‌های شخصی‌سازی‌شده</div>
    <div class="dash-grid">
        <div class="glass dash-card accent-blue"><div class="dash-icon">🧩</div><div class="dash-num" id="dash-total-buttons">0</div><div class="dash-label">کل دکمه‌های ثبت‌شده</div></div>
        <div class="glass dash-card accent-purple"><div class="dash-icon">✏️</div><div class="dash-num" id="dash-custom-labels">0</div><div class="dash-label">متن سفارشی‌شده</div></div>
        <div class="glass dash-card accent-pink"><div class="dash-icon">🎨</div><div class="dash-num" id="dash-custom-colors">0</div><div class="dash-label">رنگ سفارشی‌شده</div></div>
        <div class="glass dash-card accent-green"><div class="dash-icon">✨</div><div class="dash-num" id="dash-btn-emojis">0</div><div class="dash-label">ایموجی روی دکمه</div></div>
        <div class="glass dash-card accent-orange"><div class="dash-icon">📝</div><div class="dash-num" id="dash-text-emojis">0</div><div class="dash-label">ایموجی متن فعال</div></div>
        <div class="glass dash-card accent-blue"><div class="dash-icon">🔒</div><div class="dash-num" id="dash-locked-buttons">0</div><div class="dash-label">دکمه محدود به نقش</div></div>
    </div>

    <div class="dash-subtitle">🤖 آمار زنده ربات</div>
    <div class="dash-grid">
        <div class="glass dash-card accent-blue"><div class="dash-icon">👥</div><div class="dash-num" id="d-total-users">—</div><div class="dash-label">کل کاربران</div></div>
        <div class="glass dash-card accent-pink"><div class="dash-icon">🚫</div><div class="dash-num" id="d-blocked-users">—</div><div class="dash-label">کاربران مسدود</div></div>
        <div class="glass dash-card accent-purple"><div class="dash-icon">👮‍♂️</div><div class="dash-num" id="d-total-admins">—</div><div class="dash-label">مدیران</div></div>
        <div class="glass dash-card accent-green"><div class="dash-icon">⏱</div><div class="dash-num" id="d-ping">—</div><div class="dash-label">پینگ ربات (ms)</div></div>
        <div class="glass dash-card accent-orange"><div class="dash-icon">🤖</div><div class="dash-num" id="d-bot-status" style="font-size:16px;">—</div><div class="dash-label">وضعیت اتصال به تلگرام</div></div>
        <div class="glass dash-card accent-blue"><div class="dash-icon">📡</div><div class="dash-num" id="d-total-extractions">—</div><div class="dash-label">کل استخراج‌های ثبت‌شده</div></div>
    </div>

    <div class="glass box">
        <h3>🔎 وضعیت لحظه‌ای تنظیمات کلیدی</h3>
        <div id="dash-status-pills" style="display:flex; gap:10px; flex-wrap:wrap;"></div>
        <div class="actions"><button class="act btn-ghost" onclick="loadDashboard()">🔄 بروزرسانی آمار</button></div>
    </div>

    <div class="glass layout-box">
        <div class="layout-title"><span>💾 پشتیبان‌گیری سریع از تمام تنظیمات (چیدمان، متن، رنگ، ایموجی، قفل کانال، گزارشات، کرون، عمومی)</span></div>
        <div class="backup-row">
            <button class="act btn-primary" onclick="exportSettings()">⬇️ دانلود فایل پشتیبان تنظیمات (JSON)</button>
            <div class="file-btn-wrap">
                <button class="act btn-ghost">⬆️ بازیابی از فایل پشتیبان</button>
                <input type="file" accept="application/json" onchange="importSettings(event)">
            </div>
        </div>
        <div class="hint">این فایل شامل همه‌ی تنظیمات این پنل است (مستقل از بکاپ کامل دیتابیس/سورس در تب «💾 بکاپ»). بازیابی، مقادیر فعلی را جایگزین می‌کند و صفحه پس از اتمام رفرش می‌شود.</div>
    </div>

    <div class="glass layout-box">
        <div class="layout-title"><span>⌨️ میان‌برها</span></div>
        <div class="hint">
            • در تب‌های چیدمان می‌توانید با <b>Ctrl/Cmd + S</b> چیدمان فعلی را ذخیره کنید.<br>
            • روی هر کلید دکمه (مثلاً <code>btn_admin_stats</code>) در تب‌های متن/رنگ/ایموجی کلیک کنید تا کپی شود.<br>
            • از باکس جستجو در بالای تب‌های متن/رنگ/ایموجی برای پیدا کردن سریع یک دکمه استفاده کنید.
        </div>
    </div>
</div>

<div class="glass role-bar hidden" id="role-bar-wrap">
    <div class="role-label">👁 پیش‌نمایش به عنوان:</div>
    <div class="role-switch" id="role-switch">
        <button data-role="supadmin" class="active-role" onclick="setPreviewRole('supadmin')">👑 مدیر کل</button>
        <button data-role="admin" onclick="setPreviewRole('admin')">🛡 ادمین معمولی</button>
        <button data-role="user" onclick="setPreviewRole('user')">👤 کاربر عادی</button>
    </div>
    <div class="role-desc" id="role-desc">در حالت «مدیر کل» همه دکمه‌ها نمایش داده می‌شوند. دکمه‌های 👑 فقط به مدیر کل (ADMIN_ID) و دکمه‌های 🛡 به ادمین‌ها نمایش داده می‌شوند.</div>
</div>

<div class="glass tg-preview-wrap hidden" id="tg-preview-wrap">
    <div class="layout-title">
        <span>📱 پیش‌نمایش زنده کیبورد تلگرام — <span id="preview-menu-name">منوی اصلی</span></span>
        <span class="stat-pill" id="preview-stat">۰ دکمه</span>
    </div>
    <div class="tg-preview">
        <div class="tg-bubble">این پیش‌نمایش دقیقاً همان چیدمان، متن، رنگ و همان وضعیت فعلی تنظیمات (روشن/خاموش بودن دکمه حساب کاربری، وضعیت عمومی ربات) را که کاربر در تلگرام می‌بیند نشان می‌دهد.</div>
        <div class="tg-kb" id="tg-preview-kb"></div>
    </div>
</div>

<!-- ================= چیدمان‌ها ================= -->
<div class="menu-section" id="section-main_menu">
    <div class="glass layout-box">
        <div class="layout-title">
            <span>چیدمان فعلی «منوی اصلی» (هر ردیف = یک خط از دکمه‌ها در تلگرام)</span>
            <span class="stat-pill" id="stat-main_menu">۰ دکمه</span>
        </div>
        <div class="rows" id="rows-main_menu" data-menu="main_menu"></div>
        <div class="actions">
            <button class="act btn-ghost add-row-btn" onclick="addRow('main_menu')">➕ ردیف جدید</button>
        </div>
    </div>
    <div class="glass pool">
        <div class="layout-title">دکمه‌های استفاده‌نشده (بکشید داخل یکی از ردیف‌های بالا)</div>
        <div class="rows"><div class="row" id="pool-main_menu" data-menu="main_menu" data-pool="1"></div></div>
    </div>
    <div class="actions">
        <button class="act btn-success" onclick="saveLayout('main_menu')">💾 ذخیره چیدمان</button>
        <button class="act btn-danger" onclick="resetLayout('main_menu')">↩️ بازگشت به پیش‌فرض</button>
    </div>
    <div class="hint">نکته: دکمه «حساب کاربری» فقط وقتی در پیش‌نمایش و ربات واقعی دیده می‌شود که سوییچ «نمایش دکمه حساب کاربری» در تب 🛠 تنظیمات عمومی روشن باشد — وضعیت الان: <b><?= $showAccountBtn ? '🟢 روشن' : '🔴 خاموش' ?></b></div>
</div>

<div class="menu-section" id="section-admin_menu">
    <div class="glass layout-box">
        <div class="layout-title">
            <span>چیدمان فعلی «پنل مدیریت»</span>
            <span class="stat-pill" id="stat-admin_menu">۰ دکمه</span>
        </div>
        <div class="rows" id="rows-admin_menu" data-menu="admin_menu"></div>
        <div class="actions">
            <button class="act btn-ghost add-row-btn" onclick="addRow('admin_menu')">➕ ردیف جدید</button>
        </div>
    </div>
    <div class="glass pool">
        <div class="layout-title">دکمه‌های استفاده‌نشده (بکشید داخل یکی از ردیف‌های بالا)</div>
        <div class="rows"><div class="row" id="pool-admin_menu" data-menu="admin_menu" data-pool="1"></div></div>
    </div>
    <div class="actions">
        <button class="act btn-success" onclick="saveLayout('admin_menu')">💾 ذخیره چیدمان</button>
        <button class="act btn-danger" onclick="resetLayout('admin_menu')">↩️ بازگشت به پیش‌فرض</button>
    </div>
    <div class="hint">دکمه‌های 👑 («مدیران»، «تنظیمات پنل وب»، «پنل تنظیمات کامل») فقط برای مدیر کل (ADMIN_ID) نمایش داده می‌شوند، حتی اگر در چیدمان باشند. دکمه‌ی «وضعیت ربات» یک پسوند داینامیک دارد که وضعیت عمومی فعلی را نشان می‌دهد: <b><?= $publicModeOn ? '🟢 روشن' : '🔴 خاموش' ?></b> — همین الان در پیش‌نمایش هم دیده می‌شود.<br>نکته مهم: دکمه‌ی «💾 بک‌آپ» یک زیرمنو باز می‌کند که شامل «بکاپ دیتابیس» و (برای مدیر کل) «ایمپورت بک‌آپ» است؛ به همین دلیل این گزینه‌ها دیگر در ادیتور بالا قابل چیدمان مستقیم نیستند و فقط از طریق دکمه‌ی «💾 بک‌آپ» در دسترس‌اند (متن/رنگ/ایموجی آن‌ها همچنان از تب‌های مربوطه قابل تغییر است).</div>
</div>

<div class="menu-section" id="section-sub_menu">
    <div class="glass layout-box">
        <div class="layout-title">
            <span>چیدمان فعلی «بخش استخراج و آنالیز ساب»</span>
            <span class="stat-pill" id="stat-sub_menu">۰ دکمه</span>
        </div>
        <div class="rows" id="rows-sub_menu" data-menu="sub_menu"></div>
        <div class="actions">
            <button class="act btn-ghost add-row-btn" onclick="addRow('sub_menu')">➕ ردیف جدید</button>
        </div>
    </div>
    <div class="glass pool">
        <div class="layout-title">دکمه‌های استفاده‌نشده (بکشید داخل یکی از ردیف‌های بالا)</div>
        <div class="rows"><div class="row" id="pool-sub_menu" data-menu="sub_menu" data-pool="1"></div></div>
    </div>
    <div class="actions">
        <button class="act btn-success" onclick="saveLayout('sub_menu')">💾 ذخیره چیدمان</button>
        <button class="act btn-danger" onclick="resetLayout('sub_menu')">↩️ بازگشت به پیش‌فرض</button>
    </div>
    <div class="hint">نکته: دکمه‌ی «🖥 مشاهده در وب» همیشه به‌صورت ثابت زیر همین دکمه‌ها نمایش داده می‌شود (چون آدرسش برای هر استخراج فرق می‌کند) و در این ادیتور قابل جابه‌جایی نیست؛ ولی متن و رنگ و ایموجی‌اش از تب‌های «برچسب‌ها»، «رنگ دکمه‌ها» و «ایموجی دکمه‌ها» قابل تغییر است.</div>
</div>

<!-- ================= متن دکمه‌ها ================= -->
<div class="menu-section" id="section-labels">
    <div class="search-box"><input type="text" id="search-labels" placeholder="جستجوی دکمه بر اساس نام یا کلید..." oninput="filterRows('labels-container', this.value)"><span class="search-icon">🔍</span></div>
    <div id="labels-container"></div>
    <div class="actions">
        <button class="act btn-success" onclick="saveAllLabels()">💾 ذخیره همه متن‌ها</button>
    </div>
    <div class="hint">خالی گذاشتن یک فیلد و ذخیره، آن دکمه را به متن پیش‌فرض برمی‌گرداند. برای اضافه کردن ایموجی، آن را همراه متن تایپ کنید.</div>
</div>

<!-- ================= رنگ دکمه‌ها ================= -->
<div class="menu-section" id="section-colors">
    <div class="search-box"><input type="text" id="search-colors" placeholder="جستجوی دکمه بر اساس نام یا کلید..." oninput="filterRows('colors-container', this.value)"><span class="search-icon">🔍</span></div>
    <div id="colors-container"></div>
    <div class="hint">رنگ‌بندی همان لحظه ذخیره می‌شود و مستقیماً روی استایل دکمه‌های شیشه‌ای ربات در تلگرام اعمال می‌شود (فقط ۳ رنگ رسمی آبی/سبز/قرمز پشتیبانی می‌شود).</div>
</div>

<!-- ================= ایموجی دکمه‌ها ================= -->
<div class="menu-section" id="section-button_emojis">
    <div class="search-box"><input type="text" id="search-button-emojis" placeholder="جستجوی دکمه بر اساس نام یا کلید..." oninput="filterRows('button-emojis-container', this.value)"><span class="search-icon">🔍</span></div>
    <div id="button-emojis-container"></div>
    <div class="actions">
        <button class="act btn-success" onclick="saveButtonEmojis()">💾 ذخیره همه آیدی‌ها</button>
    </div>
    <div class="hint">برای هر دکمه، آیدی عددی ایموجی متحرک (Custom Emoji ID) تلگرام را وارد کنید. برای گرفتن این آیدی، ایموجی متحرک موردنظر را در تلگرام به ربات فوروارد کرده و عدد نمایش‌داده‌شده را اینجا وارد کنید. خالی گذاشتن فیلد و ذخیره، ایموجی سفارشی آن دکمه را حذف می‌کند.</div>
</div>

<!-- ================= ایموجی متن‌ها ================= -->
<div class="menu-section" id="section-text_emojis">
    <div class="search-box"><input type="text" id="search-text-emojis" placeholder="جستجوی ایموجی..." oninput="filterRows('text-emojis-container', this.value)"><span class="search-icon">🔍</span></div>
    <div id="text-emojis-container"></div>
    <div class="actions">
        <button class="act btn-success" onclick="saveTextEmojis()">💾 ذخیره همه آیدی‌ها</button>
    </div>
    <div class="hint">هر ایموجی که در این لیست آیدی داشته باشد، در تمام متن‌های ارسالی ربات (پیام‌ها، کپشن‌ها و ...) به‌صورت خودکار با نسخه‌ی متحرک آن جایگزین می‌شود. خالی گذاشتن فیلد و ذخیره، آن ایموجی را به حالت عادی برمی‌گرداند.</div>
</div>

<!-- ================= قفل کانال ================= -->
<div class="menu-section" id="section-fj">
    <div class="glass box">
        <h3>🔐 قفل کانال (جوین اجباری) <span class="pill <?= $fjStatus === 'on' ? 'on' : 'off' ?>" id="fj-status-pill"><?= $fjStatus === 'on' ? '🟢 فعال' : '🔴 غیرفعال' ?></span></h3>
        <div class="field-row">
            <label>وضعیت</label>
            <label class="switch"><input type="checkbox" id="fj-toggle" <?= $fjStatus === 'on' ? 'checked' : '' ?> onchange="toggleFj()"><span class="slider"></span></label>
            <span class="hint" style="margin:0;">با روشن‌بودن این گزینه، کاربران باید عضو کانال زیر باشند تا از ربات استفاده کنند.</span>
        </div>
        <div class="field-row">
            <label>آیدی کانال</label>
            <input type="text" id="fj-channel" placeholder="@channel یا -100xxxxxxxxxx" value="<?= htmlspecialchars($fjChannel, ENT_QUOTES, 'UTF-8') ?>">
            <button class="act btn-success" onclick="saveFjChannel()">💾 ذخیره</button>
            <button class="act btn-danger" onclick="removeFjChannel()">🗑 حذف</button>
        </div>
        <div class="actions"><button class="act btn-ghost" onclick="testFjChannel()">🧪 تست دسترسی به کانال</button></div>
        <div class="hint">⚠️ ربات باید ادمین کانال باشد تا بتواند عضویت کاربران را چک کند. آیدی عددی باید با <code>-100</code> شروع شود یا با <code>@</code>.</div>
        <div class="log-box" id="fj-log" style="display:none;"></div>
    </div>
</div>

<!-- ================= گزارشات ================= -->
<div class="menu-section" id="section-reports">
    <div class="glass box">
        <h3>📢 تنظیمات گزارشات خودکار <span class="pill <?= $reportStatus === 'on' ? 'on' : 'off' ?>" id="report-status-pill"><?= $reportStatus === 'on' ? '🟢 روشن' : '🔴 خاموش' ?></span></h3>
        <div class="field-row">
            <label>وضعیت کلی</label>
            <label class="switch"><input type="checkbox" id="report-toggle" <?= $reportStatus === 'on' ? 'checked' : '' ?> onchange="toggleReport()"><span class="slider"></span></label>
        </div>
        <div class="grid2">
            <div>
                <div class="field-row"><label>گروه اصلی</label></div>
                <input type="text" id="report-group-1" placeholder="-100xxxxxxxxxx" value="<?= htmlspecialchars($reportGroup, ENT_QUOTES, 'UTF-8') ?>" style="width:100%; margin-bottom:8px;">
                <button class="act btn-success" onclick="saveReportGroup(1)">💾 ذخیره گروه اصلی</button>
            </div>
            <div>
                <div class="field-row"><label>گروه پشتیبان (دوم)</label></div>
                <input type="text" id="report-group-2" placeholder="-100xxxxxxxxxx (اختیاری)" value="<?= htmlspecialchars($reportGroup2, ENT_QUOTES, 'UTF-8') ?>" style="width:100%; margin-bottom:8px;">
                <button class="act btn-success" onclick="saveReportGroup(2)">💾 ذخیره گروه پشتیبان</button>
                <button class="act btn-danger" onclick="removeReportGroup2()">🗑 حذف</button>
            </div>
        </div>
        <div class="actions" style="margin-top:16px;">
            <button class="act btn-primary" onclick="resetTopics()">🔄 ساخت / ریست تاپیک‌ها</button>
            <button class="act btn-orange" onclick="testTopicsBtn()">🧪 تست تاپیک‌ها</button>
        </div>
        <div class="hint">پس از تنظیم/تغییر گروه‌ها، حتماً دکمه «ساخت/ریست تاپیک‌ها» را بزنید تا تاپیک‌های چهارگانه (ورود کاربران، گزارش لحظه‌ای، بکاپ سیستم، گزارش استخراج) در گروه ساخته شوند. گروه‌ها باید فوروم (Topics) فعال داشته باشند و ربات باید ادمین آن‌ها باشد.</div>
        <div class="log-box" id="report-log" style="display:none;"></div>
    </div>
</div>

<!-- ================= کرون ================= -->
<div class="menu-section" id="section-cron">
    <div class="glass box">
        <h3>⏱ تنظیمات کرون‌جاب بکاپ خودکار</h3>
        <?php $cronUrl = currentPanelUrl('bot.php') . '?action=cron_backup&token=' . getCronToken(); ?>
        <div class="hint" style="margin-top:0;">شما باید کرون‌جاب هاست را روی هر ۱ دقیقه صدا زدن آدرس زیر تنظیم کنید؛ از اینجا مشخص می‌کنید هر چند وقت یک‌بار بکاپ واقعاً ارسال شود. این آدرس (و توکنش) مخصوص همین نصب شماست، آن را در جای امنی نگه دارید:<br><code style="direction:ltr; display:inline-block; margin-top:6px; word-break:break-all;"><?= htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') ?></code></div>
        <div class="actions" style="margin-top:14px;" id="cron-options">
            <button class="act btn-ghost" data-val="0" onclick="setCron(0)">🔴 خاموش</button>
            <button class="act btn-ghost" data-val="300" onclick="setCron(300)">۵ دقیقه</button>
            <button class="act btn-ghost" data-val="3600" onclick="setCron(3600)">۱ ساعت</button>
            <button class="act btn-ghost" data-val="43200" onclick="setCron(43200)">۱۲ ساعت</button>
            <button class="act btn-ghost" data-val="86400" onclick="setCron(86400)">۲۴ ساعت</button>
        </div>
        <div class="field-row" style="margin-top:16px;">
            <label>مقدار دلخواه (ثانیه)</label>
            <input type="number" id="cron-custom" placeholder="مثلاً 1800" min="0">
            <button class="act btn-success" onclick="setCronCustom()">💾 ثبت مقدار دلخواه</button>
        </div>
        <div class="hint">وضعیت فعلی: <b id="cron-current-label"><?= htmlspecialchars($cronInterval, ENT_QUOTES, 'UTF-8') ?> ثانیه</b></div>
    </div>
</div>

<!-- ================= مدیران ================= -->
<div class="menu-section" id="section-admins">
    <div class="glass box">
        <h3>👮‍♂️ مدیران ربات <span class="badge">مدیر کل: <code style="direction:ltr; display:inline-block;"><?= htmlspecialchars($supAdminId ?? '—', ENT_QUOTES, 'UTF-8') ?></code></span></h3>
        <div class="field-row">
            <label>افزودن مدیر جدید</label>
            <input type="text" id="new-admin-id" placeholder="آیدی عددی کاربر">
            <button class="act btn-success" onclick="addAdmin()">➕ افزودن</button>
        </div>
        <div id="admins-table-wrap"><div class="empty-note">در حال بارگذاری...</div></div>
    </div>
</div>

<!-- ================= کاربران ================= -->
<div class="menu-section" id="section-users">
    <div class="search-box"><input type="text" id="user-search" placeholder="جستجوی کاربر با آیدی عددی..." onkeydown="if(event.key==='Enter') loadUsers(0)"><span class="search-icon">🔍</span></div>
    <div class="glass box">
        <h3>👥 لیست کاربران</h3>
        <div id="users-table-wrap"><div class="empty-note">در حال بارگذاری...</div></div>
        <div class="pager" id="users-pager"></div>
    </div>
    <div class="glass box">
        <h3>✍️ ارسال پیام مستقیم به یک کاربر</h3>
        <div class="field-row"><label>آیدی کاربر</label><input type="text" id="dm-user-id" placeholder="آیدی عددی"></div>
        <textarea id="dm-text" placeholder="متن پیام..."></textarea>
        <div class="actions"><button class="act btn-primary" onclick="sendDirectMessage()">📤 ارسال پیام</button></div>
    </div>
</div>

<!-- ================= مدیریت استخراج‌ها ================= -->
<div class="menu-section" id="section-extractions">
    <div class="dash-grid">
        <div class="glass dash-card accent-blue"><div class="dash-icon">📡</div><div class="dash-num" id="ex-total">—</div><div class="dash-label">کل استخراج‌ها</div></div>
        <div class="glass dash-card accent-green"><div class="dash-icon">✅</div><div class="dash-num" id="ex-active">—</div><div class="dash-label">فعال</div></div>
        <div class="glass dash-card accent-pink"><div class="dash-icon">⛔️</div><div class="dash-num" id="ex-expired">—</div><div class="dash-label">منقضی‌شده</div></div>
        <div class="glass dash-card accent-purple"><div class="dash-icon">👥</div><div class="dash-num" id="ex-users">—</div><div class="dash-label">کاربران دارای استخراج</div></div>
        <div class="glass dash-card accent-orange"><div class="dash-icon">🧩</div><div class="dash-num" id="ex-configs">—</div><div class="dash-label">مجموع کانفیگ‌ها</div></div>
    </div>
    <div class="search-box"><input type="text" id="ex-search" placeholder="جستجو با آیدی عددی کاربر یا بخشی از توکن..." onkeydown="if(event.key==='Enter') loadExtractions(0)"><span class="search-icon">🔍</span></div>
    <div class="glass box">
        <div class="field-row">
            <label>فیلتر وضعیت</label>
            <select id="ex-status-filter" onchange="loadExtractions(0)">
                <option value="all">همه</option>
                <option value="active">فقط فعال</option>
                <option value="expired">فقط منقضی‌شده</option>
            </select>
            <button class="act btn-ghost" onclick="loadExtractions(0); loadExtractionStats();">🔄 بروزرسانی</button>
        </div>
        <h3>📋 لیست استخراج‌های ثبت‌شده</h3>
        <div id="extractions-table-wrap"><div class="empty-note">در حال بارگذاری...</div></div>
        <div class="pager" id="extractions-pager"></div>
    </div>
</div>

<!-- ================= تنظیمات عمومی ================= -->
<div class="menu-section" id="section-general">
    <div class="glass box">
        <h3>🌐 وضعیت عمومی ربات <span class="pill <?= $publicModeOn ? 'on' : 'off' ?>" id="public-mode-pill"><?= $publicModeOn ? '🟢 روشن' : '🔴 خاموش' ?></span></h3>
        <div class="field-row">
            <label>دسترسی عمومی</label>
            <label class="switch"><input type="checkbox" id="public-mode-toggle" <?= $publicModeOn ? 'checked' : '' ?> onchange="togglePublicMode()"><span class="slider"></span></label>
            <span class="hint" style="margin:0;">اگر خاموش باشد، فقط مدیران می‌توانند از ربات استفاده کنند.</span>
        </div>
    </div>
    <div class="glass box">
        <h3>🗂 دکمه «حساب کاربری» در منوی اصلی <span class="pill <?= $showAccountBtn ? 'on' : 'off' ?>" id="account-btn-pill"><?= $showAccountBtn ? '🟢 نمایش' : '🔴 مخفی' ?></span></h3>
        <div class="field-row">
            <label>نمایش دکمه</label>
            <label class="switch"><input type="checkbox" id="account-btn-toggle" <?= $showAccountBtn ? 'checked' : '' ?> onchange="toggleAccountBtn()"><span class="slider"></span></label>
        </div>
        <div class="hint">این سوییچ با چیدمان تب «🏠 منوی اصلی» هماهنگ است؛ اگر خاموش باشد، دکمه حساب کاربری حتی در چیدمان هم نمایش داده نمی‌شود.</div>
    </div>
</div>

<!-- ================= پیام همگانی ================= -->
<div class="menu-section" id="section-broadcast">
    <div class="glass box">
        <h3>📣 ارسال پیام همگانی متنی</h3>
        <textarea id="broadcast-text" placeholder="متن پیام همگانی..."></textarea>
        <div class="actions"><button class="act btn-danger" onclick="sendBroadcast()">🚀 ارسال به همه کاربران غیرمسدود</button></div>
        <div class="hint">این ارسال فقط برای پیام متنی است. برای ارسال عکس/ویدیو/فایل به‌صورت همگانی از منوی «📢 پیام همگانی» داخل خود ربات در تلگرام استفاده کنید.</div>
        <div class="log-box" id="broadcast-log" style="display:none;"></div>
    </div>
</div>

<!-- ================= بکاپ ================= -->
<div class="menu-section" id="section-backup">
    <div class="glass box">
        <h3>💾 بکاپ کامل (دستی)</h3>
        <div class="actions">
            <a class="act btn-primary" href="?api=download_db_backup">💾 دانلود بکاپ دیتابیس (SQL)</a>
        </div>
        <div class="hint">این فایل‌ها مستقیماً از سرور دانلود می‌شوند و به گروه گزارشات ارسال نمی‌گردند (برای ارسال به گروه از دکمه‌های داخل ربات در تلگرام استفاده کنید). برای بکاپ سریع فقط تنظیمات این پنل (چیدمان/رنگ/قفل کانال/گزارشات/کرون و ...) از تب 🧭 داشبورد استفاده کنید.</div>
        <div class="hint">📥 برای «ایمپورت» بکاپ SQL (بازیابی امن، فقط رکوردهای جدید)، از دکمه «📥 ایمپورت بک‌آپ» که داخل زیرمنوی «💾 بک‌آپ» در منوی «⚙️ پنل مدیریت» داخل خود ربات در تلگرام قرار دارد استفاده کنید — این عملیات (آپلود فایل .sql) از داخل تلگرام انجام می‌شود، نه از این پنل وب.</div>
    </div>
</div>

<!-- ================= امنیت پنل ================= -->
<div class="menu-section" id="section-security">
    <div class="glass settings-box">
        <h3 style="color:#d8b4fe; font-weight:800;">🔑 تغییر رمز عبور پنل <?php if (!$hasCustomPassword): ?><span class="badge">رمز فعلی: پیش‌فرض</span><?php endif; ?></h3>
        <div class="hint" style="margin-top:0;">این رمز، رمز مشترک کل این پنل (چیدمان، تنظیمات، مدیران و ...) است.</div>
        <label>رمز عبور فعلی</label>
        <input type="password" id="current_password" placeholder="رمز فعلی را وارد کنید" autocomplete="current-password">
        <label>رمز عبور جدید</label>
        <input type="password" id="new_password" placeholder="حداقل ۴ کاراکتر" autocomplete="new-password">
        <label>تکرار رمز عبور جدید</label>
        <input type="password" id="confirm_password" placeholder="تکرار رمز جدید" autocomplete="new-password">
        <div class="actions">
            <button class="act btn-primary" onclick="changePassword()">💾 ذخیره رمز جدید</button>
        </div>
    </div>
    <div class="glass settings-box" style="margin-top:18px;">
        <h3 style="color:#d8b4fe; font-weight:800;">🛡 محدودیت ورود بر اساس IP</h3>
        <div class="hint" style="margin-top:0;">پیش‌فرض «دسترسی نامحدود» است (هر IP می‌تواند صفحه‌ی ورود پنل را ببیند). اگر «فقط IP فعلی من» را انتخاب کنید، IP فعلی شما (<b><?= htmlspecialchars($CLIENT_IP, ENT_QUOTES, 'UTF-8') ?></b>) به‌عنوان تنها IP مجاز ثبت می‌شود و بقیه‌ی IPها حتی صفحه‌ی ورود را هم نمی‌بینند.</div>
        <div class="actions" style="margin-top:14px;">
            <button class="act btn-ghost" id="ip-lock-unlimited-btn" onclick="setIpLockMode('unlimited')">🌐 دسترسی نامحدود</button>
            <button class="act btn-ghost" id="ip-lock-single-btn" onclick="setIpLockMode('single')">📍 فقط IP فعلی من</button>
        </div>
        <div class="hint">وضعیت فعلی: <b id="ip-lock-status-label">در حال بارگذاری...</b></div>
    </div>
    <div class="glass settings-box" style="margin-top:18px;">
        <h3 style="color:#d8b4fe; font-weight:800;">🌐 آدرس پنل وب</h3>
        <div class="url-box" id="panel-url-box"><?= htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8') ?></div>
        <button class="act btn-ghost copy-btn" onclick="copyPanelUrl()">📋 کپی آدرس</button>
        <div class="hint">این همان آدرسی است که در بخش «⚙️ تنظیمات» ربات (داخل تلگرام) به ادمین نمایش داده می‌شود.</div>
    </div>
</div>

</div><!-- /.page -->
</div><!-- /.page-wrap -->

<div class="toast" id="toast"></div>

<script>
const REGISTRY = <?= json_encode($registry, JSON_UNESCAPED_UNICODE) ?>;
const LAYOUTS = {
    main_menu:  <?= json_encode($layoutMain,  JSON_UNESCAPED_UNICODE) ?>,
    admin_menu: <?= json_encode($layoutAdmin, JSON_UNESCAPED_UNICODE) ?>,
    sub_menu:   <?= json_encode($layoutSub,   JSON_UNESCAPED_UNICODE) ?>
};
const UNUSED = {
    main_menu:  <?= json_encode($unusedMain,  JSON_UNESCAPED_UNICODE) ?>,
    admin_menu: <?= json_encode($unusedAdmin, JSON_UNESCAPED_UNICODE) ?>,
    sub_menu:   <?= json_encode($unusedSub,   JSON_UNESCAPED_UNICODE) ?>
};
const CUSTOM_LABELS       = <?= json_encode($customLabels, JSON_UNESCAPED_UNICODE) ?>;
const CUSTOM_COLORS       = <?= json_encode($customColors, JSON_UNESCAPED_UNICODE) ?>;
const CUSTOM_BTN_EMOJIS   = <?= json_encode($customBtnEmojis, JSON_UNESCAPED_UNICODE) ?>;
const CUSTOM_TEXT_EMOJIS  = <?= json_encode($customTextEmojis, JSON_UNESCAPED_UNICODE) ?>;
const TEXT_EMOJI_CHARS    = <?= json_encode($defaultTextEmojiChars, JSON_UNESCAPED_UNICODE) ?>;
const BACKUP_SUB_ONLY     = <?= json_encode($backupSubOnly, JSON_UNESCAPED_UNICODE) ?>;
const MENU_TITLES = { main_menu: 'منوی اصلی', admin_menu: 'پنل مدیریت', sub_menu: 'منوی استخراج ساب' };
const COLOR_DEFS = [
    { key: '',        label: 'پیش‌فرض', cls: 'btn-ghost'   },
    { key: 'primary', label: 'آبی',      cls: 'btn-primary' },
    { key: 'success', label: 'سبز',      cls: 'btn-success' },
    { key: 'danger',  label: 'قرمز',     cls: 'btn-danger'  }
];
const INITIAL_TAB = <?= json_encode($initialTab) ?>;

// وضعیت واقعی دیتابیس ربات - دقیقاً همان چیزی که bot.php هنگام ساخت کیبورد واقعی می‌خواند
const LIVE_SETTINGS = {
    show_account_btn: <?= $showAccountBtn ? 'true' : 'false' ?>,
    public_mode: <?= $publicModeOn ? 'true' : 'false' ?>
};

let CURRENT_PREVIEW_ROLE = 'supadmin'; // supadmin | admin | user
let ACTIVE_MENU = 'main_menu';
let CURRENT_USERS_PAGE = 0;

const ROLE_DESCRIPTIONS = {
    supadmin: 'در حالت «مدیر کل» همه دکمه‌ها نمایش داده می‌شوند. دکمه‌های 👑 فقط به مدیر کل (ADMIN_ID) و دکمه‌های 🛡 به ادمین‌ها نمایش داده می‌شوند.',
    admin: 'در این حالت دکمه‌های 👑 (مخصوص مدیر کل، طبق ADMIN_ID در bot.php) نمایش داده نمی‌شوند؛ دکمه‌های 🛡 مخصوص ادمین همچنان دیده می‌شوند.',
    user: 'در این حالت فقط دکمه‌های عمومی و بدون محدودیت نمایش داده می‌شوند؛ دکمه‌های 🛡 و 👑 مخفی می‌شوند.'
};

// ---------------- ابزار عمومی ----------------
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (type ? ' toast-' + type : '');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}
async function apiPost(api, body) {
    const res = await fetch('?api=' + api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    return res.json();
}
async function apiGet(api, qs) {
    const res = await fetch('?api=' + api + (qs ? '&' + qs : ''));
    return res.json();
}
function toFa(n) { const map={'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹'}; return String(n).replace(/[0-9]/g, d => map[d]); }
function copyKey(key) {
    navigator.clipboard.writeText(key).then(() => showToast('📋 کلید «' + key + '» کپی شد.', 'success')).catch(() => showToast('❌ کپی ناموفق بود.', 'error'));
}
function filterRows(containerId, query) {
    const q = query.trim().toLowerCase();
    const container = document.getElementById(containerId);
    container.querySelectorAll('.label-row').forEach(row => {
        const hay = (row.dataset.search || '').toLowerCase();
        row.classList.toggle('filtered-out', q !== '' && !hay.includes(q));
    });
    container.querySelectorAll('.label-group').forEach(group => {
        const anyVisible = Array.from(group.querySelectorAll('.label-row')).some(r => !r.classList.contains('filtered-out'));
        group.classList.toggle('hidden', !anyVisible);
    });
}

// آیا این دکمه برای نقش انتخاب‌شده در تلگرام واقعی نمایش داده می‌شود؟ (هماهنگ با buildMenuKeyboard در bot.php)
function visibleForRole(def, role, btnKey) {
    if (!def) return true;
    if (btnKey === 'btn_main_account' && !LIVE_SETTINGS.show_account_btn) return false;
    if (def.supadmin_only) return role === 'supadmin';
    if (def.admin_only) return role === 'supadmin' || role === 'admin';
    return true;
}
function labelForKey(btnKey) {
    const def = REGISTRY[btnKey];
    let label = (CUSTOM_LABELS[btnKey] && CUSTOM_LABELS[btnKey].trim() !== '') ? CUSTOM_LABELS[btnKey] : (def ? def.label : btnKey);
    if (def && def.dynamic) {
        label += ': ' + (LIVE_SETTINGS.public_mode ? '🟢 روشن' : '🔴 خاموش');
    }
    return label;
}

// ---------------- تب‌ها ----------------
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.menu-section').forEach(s => s.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('section-' + btn.dataset.tab).classList.add('active');

        const tab = btn.dataset.tab;
        const isLayoutTab = LAYOUTS.hasOwnProperty(tab);
        document.getElementById('role-bar-wrap').classList.toggle('hidden', !isLayoutTab);
        document.getElementById('tg-preview-wrap').classList.toggle('hidden', !isLayoutTab);
        if (isLayoutTab) { ACTIVE_MENU = tab; renderPreview(ACTIVE_MENU); }

        if (tab === 'dashboard') loadDashboard();
        if (tab === 'admins') loadAdmins();
        if (tab === 'users') loadUsers(0);
        if (tab === 'extractions') { loadExtractionStats(); loadExtractions(0); }
    });
});

// ---------------- سوییچ پیش‌نمایش نقش ----------------
function setPreviewRole(role) {
    CURRENT_PREVIEW_ROLE = role;
    document.querySelectorAll('#role-switch button').forEach(b => b.classList.toggle('active-role', b.dataset.role === role));
    document.getElementById('role-desc').textContent = ROLE_DESCRIPTIONS[role] || '';
    applyRoleDim();
    renderPreview(ACTIVE_MENU);
}
function applyRoleDim() {
    document.querySelectorAll('.chip').forEach(chip => {
        const def = REGISTRY[chip.dataset.key];
        chip.classList.toggle('restricted-dim', !visibleForRole(def, CURRENT_PREVIEW_ROLE, chip.dataset.key));
    });
}

// ---------------- ساخت چیپ دکمه ----------------
function makeChip(btnKey) {
    const def = REGISTRY[btnKey];
    const chip = document.createElement('div');
    chip.className = 'chip';
    chip.draggable = true;
    chip.dataset.key = btnKey;

    const labelSpan = document.createElement('span');
    labelSpan.textContent = labelForKey(btnKey);
    chip.appendChild(labelSpan);

    if (def && (def.supadmin_only || def.admin_only)) {
        const tag = document.createElement('span');
        tag.className = 'role-tag ' + (def.supadmin_only ? 'rt-supadmin' : 'rt-admin');
        tag.textContent = def.supadmin_only ? '👑 مدیر کل' : '🛡 ادمین';
        chip.appendChild(tag);
    }
    if (def && def.backup_sub_only) {
        const tag = document.createElement('span');
        tag.className = 'role-tag rt-admin';
        tag.textContent = '📦 فقط داخل بک‌آپ';
        chip.appendChild(tag);
    }

    if (!visibleForRole(def, CURRENT_PREVIEW_ROLE, btnKey)) chip.classList.add('restricted-dim');

    chip.addEventListener('dragstart', e => {
        chip.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', btnKey);
    });
    chip.addEventListener('dragend', () => chip.classList.remove('dragging'));
    return chip;
}

// ---------------- ساخت یک ردیف ----------------
function makeRow(menuKey, keys) {
    const row = document.createElement('div');
    row.className = 'row';
    row.dataset.menu = menuKey;
    keys.forEach(k => { if (REGISTRY[k]) row.appendChild(makeChip(k)); });

    const removeBtn = document.createElement('button');
    removeBtn.className = 'row-remove';
    removeBtn.type = 'button';
    removeBtn.textContent = '✕';
    removeBtn.title = 'حذف این ردیف';
    removeBtn.onclick = () => {
        row.querySelectorAll('.chip').forEach(c => document.getElementById('pool-' + menuKey).appendChild(c));
        row.remove();
        refreshLayoutUI(menuKey);
    };
    row.appendChild(removeBtn);
    attachDropzone(row, menuKey);
    return row;
}

// ---------------- درگ اند دراپ ----------------
function attachDropzone(row, menuKey) {
    row.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        row.classList.add('dragover');
    });
    row.addEventListener('dragleave', () => row.classList.remove('dragover'));
    row.addEventListener('drop', e => {
        e.preventDefault();
        row.classList.remove('dragover');

        const key = e.dataTransfer.getData('text/plain');
        if (!key || !REGISTRY[key] || REGISTRY[key].menu !== menuKey) return;
        if (menuKey === 'admin_menu' && BACKUP_SUB_ONLY.includes(key)) return;

        let chip = document.querySelector('.chip.dragging');
        if (!chip) chip = document.querySelector(`.chip[data-key="${CSS.escape(key)}"]`);
        if (!chip) chip = makeChip(key);

        if (chip.parentElement) chip.parentElement.removeChild(chip);

        const removeBtnEl = row.querySelector('.row-remove');
        const target = e.target.closest('.chip');
        if (target && target !== chip && row.contains(target)) {
            row.insertBefore(chip, target);
        } else {
            row.insertBefore(chip, removeBtnEl);
        }
        refreshLayoutUI(menuKey);
    });
}

function addRow(menuKey) {
    const container = document.getElementById('rows-' + menuKey);
    container.appendChild(makeRow(menuKey, []));
    refreshLayoutUI(menuKey);
}

function updateSectionStat(menuKey) {
    const layout = collectLayout(menuKey);
    let total = 0, locked = 0;
    layout.forEach(row => row.forEach(k => {
        total++;
        const def = REGISTRY[k];
        if (def && (def.supadmin_only || def.admin_only)) locked++;
    }));
    const el = document.getElementById('stat-' + menuKey);
    if (el) {
        el.textContent = total + ' دکمه' + (locked ? ' • ' + locked + ' محدود' : '');
        el.className = 'stat-pill' + (locked ? ' warn' : '');
    }
}

function renderPreview(menuKey) {
    document.getElementById('preview-menu-name').textContent = MENU_TITLES[menuKey] || menuKey;
    const layout = collectLayout(menuKey);
    const kb = document.getElementById('tg-preview-kb');
    kb.innerHTML = '';
    let visibleCount = 0, totalCount = 0;

    layout.forEach(rowKeys => {
        totalCount += rowKeys.length;
        const visibleKeys = rowKeys.filter(k => visibleForRole(REGISTRY[k], CURRENT_PREVIEW_ROLE, k));
        visibleCount += visibleKeys.length;
        if (visibleKeys.length === 0) return;
        const rowEl = document.createElement('div');
        rowEl.className = 'tg-kb-row';
        visibleKeys.forEach(k => {
            const btnEl = document.createElement('div');
            const color = CUSTOM_COLORS[k] || '';
            btnEl.className = 'tg-kb-btn' + (color ? ' style-' + color : '');
            btnEl.textContent = labelForKey(k);
            rowEl.appendChild(btnEl);
        });
        kb.appendChild(rowEl);
    });

    if (kb.children.length === 0) {
        kb.innerHTML = '<div class="tg-empty">هیچ دکمه‌ای برای این نقش در این منو نمایش داده نمی‌شود.</div>';
    }
    document.getElementById('preview-stat').textContent = visibleCount + ' از ' + totalCount + ' دکمه قابل‌نمایش';
}

function refreshLayoutUI(menuKey) {
    updateSectionStat(menuKey);
    applyRoleDim();
    if (menuKey === ACTIVE_MENU) renderPreview(menuKey);
    renderDashboard();
}

function renderMenu(menuKey) {
    const container = document.getElementById('rows-' + menuKey);
    container.innerHTML = '';
    (LAYOUTS[menuKey] || []).forEach(rowKeys => container.appendChild(makeRow(menuKey, rowKeys)));

    const pool = document.getElementById('pool-' + menuKey);
    pool.innerHTML = '';
    (UNUSED[menuKey] || []).forEach(k => pool.appendChild(makeChip(k)));
    attachDropzone(pool, menuKey);
    refreshLayoutUI(menuKey);
}
renderMenu('main_menu');
renderMenu('admin_menu');
renderMenu('sub_menu');
renderPreview(ACTIVE_MENU);

function collectLayout(menuKey) {
    const rows = document.querySelectorAll('#rows-' + menuKey + ' .row');
    const layout = [];
    rows.forEach(row => {
        const keys = Array.from(row.querySelectorAll('.chip')).map(c => c.dataset.key);
        if (keys.length > 0) layout.push(keys);
    });
    return layout;
}

async function saveLayout(menuKey) {
    const layout = collectLayout(menuKey);
    try {
        const data = await apiPost('save', { menu: menuKey, layout });
        showToast(data.ok ? '✅ چیدمان ذخیره شد.' : '❌ خطا در ذخیره.', data.ok ? 'success' : 'error');
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}
async function resetLayout(menuKey) {
    if (!confirm('چیدمان این منو به حالت پیش‌فرض برگردد؟')) return;
    try {
        const data = await apiPost('reset', { menu: menuKey });
        if (data.ok) { showToast('↩️ بازگشت به پیش‌فرض انجام شد. صفحه را رفرش کنید.', 'success'); setTimeout(() => location.reload(), 900); }
        else showToast('❌ خطا.', 'error');
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

// ---------------- تب متن دکمه‌ها ----------------
function renderLabelsTab() {
    const container = document.getElementById('labels-container');
    container.innerHTML = '';
    ['main_menu', 'admin_menu', 'sub_menu'].forEach(menuKey => {
        const group = document.createElement('div');
        group.className = 'glass label-group';
        const title = document.createElement('h3');
        title.textContent = MENU_TITLES[menuKey];
        group.appendChild(title);

        Object.keys(REGISTRY).forEach(btnKey => {
            const def = REGISTRY[btnKey];
            if (def.menu !== menuKey) return;

            const row = document.createElement('div');
            row.className = 'label-row';
            row.dataset.search = def.label + ' ' + btnKey;

            const keyLabel = document.createElement('div');
            keyLabel.className = 'label-key';
            const keyText = document.createElement('span');
            keyText.textContent = def.label + (def.admin_only ? ' (فقط ادمین)' : (def.supadmin_only ? ' (فقط مدیر کل)' : '')) + (def.backup_sub_only ? ' (زیرمنوی بک‌آپ)' : '');
            keyLabel.appendChild(keyText);
            const copyIcon = document.createElement('span');
            copyIcon.className = 'copy-key';
            copyIcon.textContent = '📋 ' + btnKey;
            copyIcon.title = 'کپی کلید';
            copyIcon.onclick = () => copyKey(btnKey);
            keyLabel.appendChild(copyIcon);
            row.appendChild(keyLabel);

            const input = document.createElement('input');
            input.className = 'label-input';
            input.type = 'text';
            input.dataset.key = btnKey;
            input.placeholder = def.label + ' (پیش‌فرض)';
            input.value = CUSTOM_LABELS[btnKey] || '';
            row.appendChild(input);

            const resetBtn = document.createElement('button');
            resetBtn.type = 'button';
            resetBtn.className = 'label-reset';
            resetBtn.textContent = '↩️ پیش‌فرض';
            resetBtn.onclick = () => resetSingleLabel(btnKey, input);
            row.appendChild(resetBtn);

            group.appendChild(row);
        });
        container.appendChild(group);
    });
}
renderLabelsTab();

async function resetSingleLabel(btnKey, inputEl) {
    try {
        const data = await apiPost('reset_label', { btn_key: btnKey });
        if (data.ok) {
            inputEl.value = '';
            delete CUSTOM_LABELS[btnKey];
            showToast('✅ متن به پیش‌فرض برگشت.', 'success');
            document.querySelectorAll(`.chip[data-key="${CSS.escape(btnKey)}"]`).forEach(c => { c.firstChild.textContent = labelForKey(btnKey); });
            renderPreview(ACTIVE_MENU);
            renderDashboard();
        } else { showToast('❌ خطا.', 'error'); }
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

async function saveAllLabels() {
    const labels = {};
    document.querySelectorAll('#labels-container .label-input').forEach(input => {
        const v = input.value.trim();
        if (v !== '') labels[input.dataset.key] = v;
    });
    try {
        const data = await apiPost('save_labels', { labels });
        if (data.ok) {
            Object.assign(CUSTOM_LABELS, labels);
            Object.keys(CUSTOM_LABELS).forEach(k => { if (!(k in labels)) delete CUSTOM_LABELS[k]; });
            document.querySelectorAll('.chip').forEach(c => { c.firstChild.textContent = labelForKey(c.dataset.key); });
            renderPreview(ACTIVE_MENU);
            renderDashboard();
            showToast('✅ متن دکمه‌ها ذخیره شد.', 'success');
        } else { showToast('❌ خطا در ذخیره.', 'error'); }
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

// ---------------- تب رنگ دکمه‌ها ----------------
function renderColorsTab() {
    const container = document.getElementById('colors-container');
    container.innerHTML = '';
    ['main_menu', 'admin_menu', 'sub_menu'].forEach(menuKey => {
        const group = document.createElement('div');
        group.className = 'glass label-group';
        const title = document.createElement('h3');
        title.textContent = MENU_TITLES[menuKey];
        group.appendChild(title);

        Object.keys(REGISTRY).forEach(btnKey => {
            const def = REGISTRY[btnKey];
            if (def.menu !== menuKey) return;

            const row = document.createElement('div');
            row.className = 'label-row';
            row.dataset.search = def.label + ' ' + btnKey;

            const keyLabel = document.createElement('div');
            keyLabel.className = 'label-key';
            const keyText = document.createElement('span');
            keyText.textContent = def.label;
            keyLabel.appendChild(keyText);
            const copyIcon = document.createElement('span');
            copyIcon.className = 'copy-key';
            copyIcon.textContent = '📋 ' + btnKey;
            copyIcon.onclick = () => copyKey(btnKey);
            keyLabel.appendChild(copyIcon);
            row.appendChild(keyLabel);

            const wrap = document.createElement('div');
            wrap.className = 'color-opt-wrap';
            wrap.dataset.btnKey = btnKey;

            const current = CUSTOM_COLORS[btnKey] || '';
            COLOR_DEFS.forEach(opt => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'act ' + opt.cls + ' color-opt' + (current === opt.key ? ' active-opt' : '');
                b.textContent = opt.label + (current === opt.key ? ' ✓' : '');
                b.onclick = () => setButtonColor(btnKey, opt.key);
                wrap.appendChild(b);
            });

            row.appendChild(wrap);
            group.appendChild(row);
        });
        container.appendChild(group);
    });
}
renderColorsTab();

async function setButtonColor(btnKey, color) {
    try {
        const data = await apiPost('set_color', { btn_key: btnKey, color });
        if (data.ok) {
            if (color === '') delete CUSTOM_COLORS[btnKey]; else CUSTOM_COLORS[btnKey] = color;
            renderColorsTab();
            renderPreview(ACTIVE_MENU);
            renderDashboard();
            showToast('✅ رنگ دکمه ذخیره شد.', 'success');
        } else { showToast('❌ خطا در ذخیره رنگ.', 'error'); }
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

// ---------------- تب ایموجی دکمه‌ها ----------------
function renderButtonEmojisTab() {
    const container = document.getElementById('button-emojis-container');
    container.innerHTML = '';
    ['main_menu', 'admin_menu', 'sub_menu'].forEach(menuKey => {
        const group = document.createElement('div');
        group.className = 'glass label-group';
        const title = document.createElement('h3');
        title.textContent = MENU_TITLES[menuKey];
        group.appendChild(title);

        Object.keys(REGISTRY).forEach(btnKey => {
            const def = REGISTRY[btnKey];
            if (def.menu !== menuKey) return;

            const row = document.createElement('div');
            row.className = 'label-row';
            row.dataset.search = def.label + ' ' + btnKey;

            const keyLabel = document.createElement('div');
            keyLabel.className = 'label-key';
            const keyText = document.createElement('span');
            keyText.textContent = def.label;
            keyLabel.appendChild(keyText);
            const copyIcon = document.createElement('span');
            copyIcon.className = 'copy-key';
            copyIcon.textContent = '📋 ' + btnKey;
            copyIcon.onclick = () => copyKey(btnKey);
            keyLabel.appendChild(copyIcon);
            row.appendChild(keyLabel);

            const input = document.createElement('input');
            input.className = 'label-input';
            input.type = 'text';
            input.dataset.key = btnKey;
            input.placeholder = 'آیدی عددی ایموجی متحرک (اختیاری)';
            input.value = CUSTOM_BTN_EMOJIS[btnKey] || '';
            row.appendChild(input);

            const resetBtn = document.createElement('button');
            resetBtn.type = 'button';
            resetBtn.className = 'label-reset';
            resetBtn.textContent = '↩️ حذف';
            resetBtn.onclick = () => resetButtonEmoji(btnKey, input);
            row.appendChild(resetBtn);

            group.appendChild(row);
        });
        container.appendChild(group);
    });
}
renderButtonEmojisTab();

async function resetButtonEmoji(btnKey, inputEl) {
    try {
        const data = await apiPost('reset_button_emoji', { btn_key: btnKey });
        if (data.ok) {
            inputEl.value = '';
            delete CUSTOM_BTN_EMOJIS[btnKey];
            showToast('✅ ایموجی دکمه حذف شد.', 'success');
            renderDashboard();
        } else { showToast('❌ خطا.', 'error'); }
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

async function saveButtonEmojis() {
    const emojis = {};
    document.querySelectorAll('#button-emojis-container .label-input').forEach(input => {
        const v = input.value.trim();
        if (v !== '') emojis[input.dataset.key] = v;
    });
    try {
        const data = await apiPost('save_button_emojis', { emojis });
        if (data.ok) {
            Object.assign(CUSTOM_BTN_EMOJIS, emojis);
            Object.keys(CUSTOM_BTN_EMOJIS).forEach(k => { if (!(k in emojis)) delete CUSTOM_BTN_EMOJIS[k]; });
            showToast('✅ آیدی ایموجی دکمه‌ها ذخیره شد.', 'success');
            renderDashboard();
        } else { showToast('❌ خطا در ذخیره.', 'error'); }
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

// ---------------- تب ایموجی متن‌ها ----------------
function renderTextEmojisTab() {
    const container = document.getElementById('text-emojis-container');
    container.innerHTML = '';
    const group = document.createElement('div');
    group.className = 'glass label-group';
    const title = document.createElement('h3');
    title.textContent = 'ایموجی‌های قابل جایگزینی در متن پیام‌ها (' + TEXT_EMOJI_CHARS.length + ' مورد)';
    group.appendChild(title);

    TEXT_EMOJI_CHARS.forEach(ch => {
        const row = document.createElement('div');
        row.className = 'label-row';
        row.dataset.search = ch;

        const keyLabel = document.createElement('div');
        keyLabel.className = 'label-key';
        keyLabel.style.flex = '0 0 46px';
        keyLabel.style.fontSize = '20px';
        keyLabel.style.textAlign = 'center';
        keyLabel.textContent = ch;
        row.appendChild(keyLabel);

        const input = document.createElement('input');
        input.className = 'label-input';
        input.type = 'text';
        input.dataset.char = ch;
        input.placeholder = 'آیدی عددی ایموجی متحرک (اختیاری)';
        input.value = CUSTOM_TEXT_EMOJIS[ch] || '';
        row.appendChild(input);

        const resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'label-reset';
        resetBtn.textContent = '↩️ حذف';
        resetBtn.onclick = () => resetTextEmoji(ch, input);
        row.appendChild(resetBtn);

        group.appendChild(row);
    });
    container.appendChild(group);
}
renderTextEmojisTab();

async function resetTextEmoji(ch, inputEl) {
    try {
        const data = await apiPost('reset_text_emoji', { char: ch });
        if (data.ok) {
            inputEl.value = '';
            delete CUSTOM_TEXT_EMOJIS[ch];
            showToast('✅ ایموجی متن حذف شد.', 'success');
            renderDashboard();
        } else { showToast('❌ خطا.', 'error'); }
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

async function saveTextEmojis() {
    const emojis = {};
    document.querySelectorAll('#text-emojis-container .label-input').forEach(input => {
        const v = input.value.trim();
        if (v !== '') emojis[input.dataset.char] = v;
    });
    try {
        const data = await apiPost('save_text_emojis', { emojis });
        if (data.ok) {
            Object.assign(CUSTOM_TEXT_EMOJIS, emojis);
            Object.keys(CUSTOM_TEXT_EMOJIS).forEach(k => { if (!(k in emojis)) delete CUSTOM_TEXT_EMOJIS[k]; });
            showToast('✅ آیدی ایموجی متن‌ها ذخیره شد.', 'success');
            renderDashboard();
        } else { showToast('❌ خطا در ذخیره.', 'error'); }
    } catch (e) { showToast('❌ خطای ارتباط با سرور.', 'error'); }
}

// ---------------- داشبورد (محلی: شمارش دکمه‌ها) ----------------
function renderDashboard() {
    const totalButtons = Object.keys(REGISTRY).length;
    const lockedButtons = Object.values(REGISTRY).filter(d => d.admin_only || d.supadmin_only).length;
    document.getElementById('dash-total-buttons').textContent = toFa(totalButtons);
    document.getElementById('dash-custom-labels').textContent = toFa(Object.keys(CUSTOM_LABELS).length);
    document.getElementById('dash-custom-colors').textContent = toFa(Object.keys(CUSTOM_COLORS).length);
    document.getElementById('dash-btn-emojis').textContent = toFa(Object.keys(CUSTOM_BTN_EMOJIS).length);
    document.getElementById('dash-text-emojis').textContent = toFa(Object.keys(CUSTOM_TEXT_EMOJIS).length);
    document.getElementById('dash-locked-buttons').textContent = toFa(lockedButtons);
}

// ---------------- داشبورد (ریموت: آمار زنده ربات) ----------------
async function loadBotStats() {
    try {
        const data = await apiGet('bot_stats');
        if (!data.ok) return;
        document.getElementById('d-total-users').textContent   = toFa(data.total_users);
        document.getElementById('d-blocked-users').textContent = toFa(data.blocked_users);
        document.getElementById('d-total-admins').textContent  = toFa(data.total_admins);
        document.getElementById('d-total-extractions').textContent = toFa(data.total_extractions);
        document.getElementById('d-ping').textContent          = toFa(data.ping);
        document.getElementById('d-bot-status').textContent    = data.bot_ok ? ('✅ @' + (data.bot_username || '')) : '❌ قطع';

        const pillsWrap = document.getElementById('dash-status-pills');
        pillsWrap.innerHTML = '';
        const items = [
            ['حالت عمومی ربات', data.public_mode === '1'],
            ['گزارشات خودکار', data.report_status === 'on'],
            ['قفل کانال', data.fj_status === 'on'],
        ];
        items.forEach(([label, on]) => {
            const p = document.createElement('span');
            p.className = 'pill ' + (on ? 'on' : 'off');
            p.textContent = (on ? '🟢 ' : '🔴 ') + label;
            pillsWrap.appendChild(p);
        });
    } catch (e) { showToast('❌ خطا در دریافت آمار.', 'error'); }
}
function loadDashboard() {
    renderDashboard();
    loadBotStats();
}

// ---------------- قفل کانال ----------------
async function toggleFj() {
    const data = await apiPost('toggle_fj');
    if (data.ok) {
        const on = data.status === 'on';
        const pill = document.getElementById('fj-status-pill');
        pill.className = 'pill ' + (on ? 'on' : 'off');
        pill.textContent = on ? '🟢 فعال' : '🔴 غیرفعال';
        showToast('✅ وضعیت قفل کانال تغییر کرد.', 'success');
    }
}
async function saveFjChannel() {
    const channel = document.getElementById('fj-channel').value.trim();
    const data = await apiPost('set_fj_channel', { channel });
    showToast(data.ok ? '✅ کانال ذخیره شد.' : '❌ فرمت آیدی کانال نامعتبر است (باید با @ یا -100 شروع شود).', data.ok ? 'success' : 'error');
}
async function removeFjChannel() {
    if (!confirm('کانال قفل عضویت حذف شود؟')) return;
    const data = await apiPost('remove_fj_channel');
    if (data.ok) { document.getElementById('fj-channel').value = ''; showToast('🗑 کانال حذف شد.', 'success'); }
}
async function testFjChannel() {
    const log = document.getElementById('fj-log');
    log.style.display = 'block'; log.textContent = '⏳ در حال تست...';
    const data = await apiPost('test_fj_channel');
    log.textContent = data.ok ? ('✅ دسترسی برقرار است. عنوان کانال: ' + (data.title || '-')) : ('❌ خطا: ' + (data.description || data.error || 'نامعلوم'));
}

// ---------------- گزارشات ----------------
async function toggleReport() {
    const data = await apiPost('toggle_report_status');
    if (data.ok) {
        const on = data.status === 'on';
        const pill = document.getElementById('report-status-pill');
        pill.className = 'pill ' + (on ? 'on' : 'off');
        pill.textContent = on ? '🟢 روشن' : '🔴 خاموش';
        showToast('✅ وضعیت گزارشات تغییر کرد.', 'success');
    }
}
async function saveReportGroup(slot) {
    const group_id = document.getElementById('report-group-' + slot).value.trim();
    if (!group_id) { showToast('❌ آیدی گروه را وارد کنید.', 'error'); return; }
    const data = await apiPost('set_report_group', { slot, group_id });
    if (data.ok) {
        showToast('✅ گروه ذخیره شد. حالا «ساخت/ریست تاپیک‌ها» را بزنید.', 'success');
        if (slot === 1) {
            document.getElementById('report-status-pill').className = 'pill on';
            document.getElementById('report-status-pill').textContent = '🟢 روشن';
            document.getElementById('report-toggle').checked = true;
        }
    } else { showToast('❌ خطا در ذخیره گروه.', 'error'); }
}
async function removeReportGroup2() {
    if (!confirm('گروه پشتیبان حذف شود؟')) return;
    const data = await apiPost('remove_report_group_2');
    if (data.ok) { document.getElementById('report-group-2').value = ''; showToast('🗑 گروه پشتیبان حذف شد.', 'success'); }
}
async function resetTopics() {
    const log = document.getElementById('report-log');
    log.style.display = 'block'; log.textContent = '⏳ در حال ساخت/ریست تاپیک‌ها...';
    const data = await apiPost('reset_report_topics');
    log.textContent = (data.log || []).join('\n');
    showToast('✅ عملیات انجام شد. لاگ را بررسی کنید.', 'success');
}
async function testTopicsBtn() {
    const log = document.getElementById('report-log');
    log.style.display = 'block'; log.textContent = '⏳ در حال ارسال پیام تست...';
    const data = await apiPost('test_report_topics');
    log.textContent = (data.log || []).join('\n');
    showToast('✅ پیام‌های تست ارسال شد.', 'success');
}

// ---------------- کرون ----------------
function markActiveCron(val) {
    document.querySelectorAll('#cron-options .act').forEach(b => {
        b.classList.toggle('btn-primary', b.dataset.val === String(val));
        b.classList.toggle('btn-ghost', b.dataset.val !== String(val));
    });
}
markActiveCron(<?= (int)$cronInterval ?>);
async function setCron(val) {
    const data = await apiPost('set_cron', { interval: val });
    if (data.ok) {
        markActiveCron(val);
        document.getElementById('cron-current-label').textContent = toFa(val) + ' ثانیه';
        showToast('✅ فاصله زمانی کرون ذخیره شد.', 'success');
    }
}
async function setCronCustom() {
    const val = parseInt(document.getElementById('cron-custom').value, 10);
    if (isNaN(val) || val < 0) { showToast('❌ مقدار نامعتبر.', 'error'); return; }
    await setCron(val);
}

// ---------------- مدیران ----------------
async function loadAdmins() {
    const wrap = document.getElementById('admins-table-wrap');
    wrap.innerHTML = '<div class="empty-note">در حال بارگذاری...</div>';
    const data = await apiGet('list_admins');
    if (!data.ok) { wrap.innerHTML = '<div class="empty-note">خطا در دریافت لیست.</div>'; return; }
    if (data.admins.length === 0) { wrap.innerHTML = '<div class="empty-note">هیچ مدیری (غیر از مدیر کل) ثبت نشده است.</div>'; return; }
    let html = '<table class="data-table"><thead><tr><th>آیدی</th><th>تاریخ افزودن</th><th>عملیات</th></tr></thead><tbody>';
    data.admins.forEach(a => {
        html += `<tr><td><code>${a.id}</code></td><td>${a.joined}</td><td><button class="mini-btn mb-danger" onclick="removeAdmin('${a.id}')">🔻 حذف دسترسی</button></td></tr>`;
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
}
async function addAdmin() {
    const id = document.getElementById('new-admin-id').value.trim();
    if (!/^\d+$/.test(id)) { showToast('❌ آیدی عددی معتبر وارد کنید.', 'error'); return; }
    const data = await apiPost('add_admin', { admin_id: id });
    if (data.ok) { document.getElementById('new-admin-id').value = ''; showToast('✅ مدیر افزوده شد.', 'success'); loadAdmins(); }
    else showToast('❌ خطا در افزودن.', 'error');
}
async function removeAdmin(id) {
    if (!confirm('دسترسی مدیریت این کاربر حذف شود؟')) return;
    const data = await apiPost('remove_admin', { admin_id: id });
    if (data.ok) { showToast('✅ دسترسی حذف شد.', 'success'); loadAdmins(); }
    else showToast('❌ ' + (data.error === 'cannot_remove_supadmin' ? 'مدیر کل قابل حذف نیست.' : 'خطا.'), 'error');
}

// ---------------- کاربران ----------------
async function loadUsers(page) {
    CURRENT_USERS_PAGE = page;
    const q = document.getElementById('user-search').value.trim();
    const wrap = document.getElementById('users-table-wrap');
    wrap.innerHTML = '<div class="empty-note">در حال بارگذاری...</div>';
    const data = await apiGet('list_users', 'page=' + page + '&q=' + encodeURIComponent(q));
    if (!data.ok) { wrap.innerHTML = '<div class="empty-note">خطا در دریافت لیست.</div>'; return; }
    if (data.users.length === 0) { wrap.innerHTML = '<div class="empty-note">کاربری یافت نشد.</div>'; document.getElementById('users-pager').innerHTML = ''; return; }
    let html = '<table class="data-table"><thead><tr><th>آیدی</th><th>تاریخ عضویت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
    data.users.forEach(u => {
        html += `<tr><td><code>${u.id}</code>${u.admin ? ' <span class="badge">مدیر</span>' : ''}</td><td>${u.joined}</td><td>${u.blocked ? '🔴 مسدود' : '🟢 فعال'}</td>
        <td><button class="mini-btn ${u.blocked ? 'mb-success' : 'mb-danger'}" onclick="toggleBlock('${u.id}')">${u.blocked ? '🔓 رفع مسدودی' : '🔒 مسدود کردن'}</button>
        <button class="mini-btn mb-primary" onclick="prepDm('${u.id}')">✍️ پیام</button></td></tr>`;
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;

    const totalPages = Math.max(1, Math.ceil(data.total / data.limit));
    let pager = '';
    if (page > 0) pager += `<button class="mini-btn" onclick="loadUsers(${page - 1})">⬅️ قبلی</button>`;
    pager += `<span>صفحه ${toFa(page + 1)} از ${toFa(totalPages)} (${toFa(data.total)} کاربر)</span>`;
    if ((page + 1) < totalPages) pager += `<button class="mini-btn" onclick="loadUsers(${page + 1})">بعدی ➡️</button>`;
    document.getElementById('users-pager').innerHTML = pager;
}
async function toggleBlock(id) {
    const data = await apiPost('toggle_block', { user_id: id });
    if (data.ok) { showToast(data.blocked ? '🔒 مسدود شد.' : '🔓 رفع مسدودی شد.', 'success'); loadUsers(CURRENT_USERS_PAGE); }
    else showToast('❌ خطا.', 'error');
}
function prepDm(id) {
    document.getElementById('dm-user-id').value = id;
    document.getElementById('dm-text').focus();
}
async function sendDirectMessage() {
    const user_id = document.getElementById('dm-user-id').value.trim();
    const text = document.getElementById('dm-text').value.trim();
    if (!/^\d+$/.test(user_id) || text === '') { showToast('❌ آیدی و متن را کامل وارد کنید.', 'error'); return; }
    const data = await apiPost('send_direct_message', { user_id, text });
    if (data.ok) { showToast('📤 پیام ارسال شد.', 'success'); document.getElementById('dm-text').value = ''; }
    else showToast('❌ ارسال ناموفق: ' + (data.description || 'نامعلوم'), 'error');
}

// ---------------- مدیریت استخراج‌ها ----------------
function fmtBytesJs(bytes) {
    bytes = Number(bytes) || 0;
    if (bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let pow = Math.floor(Math.log(bytes) / Math.log(1024));
    pow = Math.min(pow, units.length - 1);
    return (bytes / Math.pow(1024, pow)).toFixed(2) + ' ' + units[pow];
}
async function loadExtractionStats() {
    const data = await apiGet('extraction_stats');
    if (!data.ok) return;
    document.getElementById('ex-total').textContent = toFa(data.total);
    document.getElementById('ex-active').textContent = toFa(data.active);
    document.getElementById('ex-expired').textContent = toFa(data.expired);
    document.getElementById('ex-users').textContent = toFa(data.unique_users);
    document.getElementById('ex-configs').textContent = toFa(data.total_configs);
}
let CURRENT_EX_PAGE = 0;
async function loadExtractions(page) {
    CURRENT_EX_PAGE = page;
    const q = document.getElementById('ex-search').value.trim();
    const status = document.getElementById('ex-status-filter').value;
    const wrap = document.getElementById('extractions-table-wrap');
    wrap.innerHTML = '<div class="empty-note">در حال بارگذاری...</div>';
    const data = await apiGet('list_extractions', 'page=' + page + '&q=' + encodeURIComponent(q) + '&status=' + status);
    if (!data.ok) { wrap.innerHTML = '<div class="empty-note">خطا در دریافت لیست.</div>'; return; }
    if (data.extractions.length === 0) { wrap.innerHTML = '<div class="empty-note">هیچ استخراجی یافت نشد.</div>'; document.getElementById('extractions-pager').innerHTML = ''; return; }
    let html = '<table class="data-table"><thead><tr><th>کاربر</th><th>کانفیگ‌ها</th><th>حجم مصرفی</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>';
    data.extractions.forEach(e => {
        const volText = e.total_bytes > 0 ? (fmtBytesJs(e.used_bytes) + ' / ' + fmtBytesJs(e.total_bytes)) : (fmtBytesJs(e.used_bytes) + ' / نامحدود');
        html += `<tr><td><code>${e.user_id}</code></td><td>${toFa(e.total_configs)}</td><td>${volText}</td><td>${e.active ? '🟢 فعال' : '🔴 منقضی'}</td><td>${e.created_at}</td>
        <td><a class="mini-btn mb-primary" style="text-decoration:none; display:inline-block;" href="${e.view_url}" target="_blank">🖥 مشاهده</a>
        <button class="mini-btn mb-danger" onclick="deleteExtraction('${e.token}')">🗑 حذف</button></td></tr>`;
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;

    const totalPages = Math.max(1, Math.ceil(data.total / data.limit));
    let pager = '';
    if (page > 0) pager += `<button class="mini-btn" onclick="loadExtractions(${page - 1})">⬅️ قبلی</button>`;
    pager += `<span>صفحه ${toFa(page + 1)} از ${toFa(totalPages)} (${toFa(data.total)} استخراج)</span>`;
    if ((page + 1) < totalPages) pager += `<button class="mini-btn" onclick="loadExtractions(${page + 1})">بعدی ➡️</button>`;
    document.getElementById('extractions-pager').innerHTML = pager;
}
async function deleteExtraction(token) {
    if (!confirm('این استخراج حذف شود؟ لینک وب مربوطه دیگر کار نخواهد کرد.')) return;
    const data = await apiPost('delete_extraction', { token });
    if (data.ok) { showToast('🗑 حذف شد.', 'success'); loadExtractions(CURRENT_EX_PAGE); loadExtractionStats(); }
    else showToast('❌ خطا در حذف.', 'error');
}

// ---------------- تنظیمات عمومی ----------------
async function togglePublicMode() {
    const data = await apiPost('toggle_public_mode');
    if (data.ok) {
        const on = data.status === '1';
        LIVE_SETTINGS.public_mode = on;
        const pill = document.getElementById('public-mode-pill');
        pill.className = 'pill ' + (on ? 'on' : 'off');
        pill.textContent = on ? '🟢 روشن' : '🔴 خاموش';
        document.querySelectorAll('.chip').forEach(c => { c.firstChild.textContent = labelForKey(c.dataset.key); });
        renderPreview(ACTIVE_MENU);
        showToast('✅ وضعیت عمومی تغییر کرد.', 'success');
    }
}
async function toggleAccountBtn() {
    const data = await apiPost('toggle_show_account_btn');
    if (data.ok) {
        const on = data.status === 'on';
        LIVE_SETTINGS.show_account_btn = on;
        const pill = document.getElementById('account-btn-pill');
        pill.className = 'pill ' + (on ? 'on' : 'off');
        pill.textContent = on ? '🟢 نمایش' : '🔴 مخفی';
        applyRoleDim();
        renderPreview(ACTIVE_MENU);
        showToast('✅ ذخیره شد.', 'success');
    }
}

// ---------------- پیام همگانی ----------------
async function sendBroadcast() {
    const text = document.getElementById('broadcast-text').value.trim();
    if (text === '') { showToast('❌ متن پیام را وارد کنید.', 'error'); return; }
    if (!confirm('پیام برای همه کاربران غیرمسدود ارسال شود؟')) return;
    const log = document.getElementById('broadcast-log');
    log.style.display = 'block'; log.textContent = '⏳ در حال ارسال...';
    const data = await apiPost('broadcast', { text });
    log.textContent = data.ok ? `✅ تمام شد.\nموفق: ${data.sent}\nناموفق: ${data.failed}` : '❌ خطا در ارسال.';
    showToast(data.ok ? '✅ ارسال همگانی تمام شد.' : '❌ خطا.', data.ok ? 'success' : 'error');
}

// ---------------- خروجی / ورودی تنظیمات (دکمه‌ها + بات) ----------------
async function exportSettings() {
    try {
        const data = await apiGet('export_settings');
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'bot_panel_backup_' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
        showToast('⬇️ فایل پشتیبان دانلود شد.', 'success');
    } catch (e) { showToast('❌ خطا در تهیه فایل پشتیبان.', 'error'); }
}
function importSettings(evt) {
    const file = evt.target.files && evt.target.files[0];
    if (!file) return;
    if (!confirm('بازیابی از فایل پشتیبان، تمام تنظیمات فعلی این پنل را جایگزین می‌کند. ادامه می‌دهید؟')) { evt.target.value = ''; return; }
    const reader = new FileReader();
    reader.onload = async () => {
        try {
            const parsed = JSON.parse(reader.result);
            const payloadData = parsed && parsed.data ? parsed.data : parsed;
            const data = await apiPost('import_settings', { data: payloadData });
            if (data.ok) { showToast('✅ بازیابی شد. در حال بارگذاری مجدد...', 'success'); setTimeout(() => location.reload(), 1000); }
            else showToast('❌ فایل پشتیبان نامعتبر است.', 'error');
        } catch (e) { showToast('❌ خطا در خواندن فایل پشتیبان.', 'error'); }
        finally { evt.target.value = ''; }
    };
    reader.readAsText(file);
}

// ---------------- امنیت پنل ----------------
function copyPanelUrl() {
    const text = document.getElementById('panel-url-box').textContent;
    navigator.clipboard.writeText(text).then(() => showToast('📋 آدرس کپی شد.', 'success')).catch(() => showToast('❌ کپی ناموفق بود.', 'error'));
}
async function changePassword() {
    const current = document.getElementById('current_password').value;
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    if (!current || !newPass || !confirmPass) { showToast('❌ همه فیلدها را پر کنید.', 'error'); return; }
    if (newPass !== confirmPass) { showToast('❌ رمز جدید و تکرار آن یکسان نیستند.', 'error'); return; }
    if (newPass.length < 4) { showToast('❌ رمز جدید باید حداقل ۴ کاراکتر باشد.', 'error'); return; }
    const data = await apiPost('change_password', { current, new_password: newPass });
    if (data.ok) {
        showToast('✅ رمز عبور تغییر کرد.', 'success');
        document.getElementById('current_password').value = '';
        document.getElementById('new_password').value = '';
        document.getElementById('confirm_password').value = '';
    } else if (data.error === 'wrong_current') showToast('❌ رمز فعلی اشتباه است.', 'error');
    else if (data.error === 'too_short') showToast('❌ رمز جدید کوتاه است.', 'error');
    else showToast('❌ خطا.', 'error');
}

// ---------------- قفل IP پنل ----------------
async function loadIpLockStatus() {
    const data = await apiPost('ip_lock_status', {});
    if (!data.ok) return;
    const label = document.getElementById('ip-lock-status-label');
    const unlimitedBtn = document.getElementById('ip-lock-unlimited-btn');
    const singleBtn = document.getElementById('ip-lock-single-btn');
    if (data.mode === 'single' && data.allowed_ip) {
        label.textContent = 'فقط IP ' + data.allowed_ip + ' مجاز است';
        singleBtn.classList.add('active');
        unlimitedBtn.classList.remove('active');
    } else {
        label.textContent = 'دسترسی نامحدود (هر IP)';
        unlimitedBtn.classList.add('active');
        singleBtn.classList.remove('active');
    }
}
async function setIpLockMode(mode) {
    if (mode === 'single' && !confirm('IP فعلی شما به‌عنوان تنها IP مجاز ثبت می‌شود و بقیه‌ی IPها دیگر حتی صفحه‌ی ورود را هم نمی‌بینند. مطمئن هستید؟')) return;
    const data = await apiPost('set_ip_lock', { mode });
    if (data.ok) {
        showToast('✅ تنظیمات قفل IP ذخیره شد.', 'success');
        loadIpLockStatus();
    } else {
        showToast('❌ خطا در ذخیره‌سازی.', 'error');
    }
}
loadIpLockStatus();

// ---------------- میان‌بر کیبورد: Ctrl/Cmd+S برای ذخیره چیدمان فعال ----------------
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        if (LAYOUTS.hasOwnProperty(ACTIVE_MENU)) { e.preventDefault(); saveLayout(ACTIVE_MENU); }
    }
});

// ---------------- باز کردن تب اولیه (پشتیبانی از لینک‌های عمیق مثل webpanel.php?tab=fj) ----------------
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.querySelector('.tab-btn[data-tab="' + INITIAL_TAB + '"]') || document.querySelector('.tab-btn[data-tab="dashboard"]');
    if (btn) btn.click();
});
</script>
</body>
</html>