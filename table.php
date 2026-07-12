<?php
// table.php - هماهنگ با اسکیمای فعلی bot.php / webpanel.php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die("<div style='color:red; font-family:tahoma; direction:rtl; text-align:center; margin-top:50px;'><h2>❌ خطا: فایل config.php پیدا نشد!</h2></div>");
}
require_once $configFile;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // کاربران ربات (نکته: ستون‌های first_name/username دیگر استفاده نمی‌شوند و
    // به‌جایشان points / is_blocked / is_admin در bot.php و پنل وب خوانده می‌شوند)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `user_id` BIGINT PRIMARY KEY,
        `points` INT DEFAULT 0,
        `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `is_blocked` TINYINT(1) DEFAULT 0,
        `is_admin` TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // وضعیت مراحل مکالمه هر کاربر (مثل ورود لینک ساب، تنظیمات و ...)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_states` (
        `user_id` BIGINT PRIMARY KEY,
        `state` VARCHAR(50) NOT NULL,
        `data` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // تمام تنظیمات پویای ربات و پنل وب (چیدمان دکمه‌ها، رنگ‌ها، ایموجی‌ها،
    // قفل کانال، گزارشات، کرون، حالت عمومی و ...) از همین جدول key/value خوانده می‌شود
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `setting_key` VARCHAR(50) PRIMARY KEY,
        `setting_value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // مدیران ربات (غیر از مدیر کل که از ADMIN_ID در config.php خوانده می‌شود)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
        `admin_id` BIGINT PRIMARY KEY,
        `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // نتایج استخراج ساب (برای صفحه‌ی وب sub_view.php و دکمه‌های بروزرسانی/دریافت کانفیگ)
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // لاگ ایمپورت‌های بک‌آپ (برای پیگیری تاریخچه‌ی بازیابی‌های انجام‌شده توسط مدیر کل)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `backup_imports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` BIGINT,
        `inserted_count` INT DEFAULT 0,
        `skipped_count` INT DEFAULT 0,
        `failed_count` INT DEFAULT 0,
        `total_count` INT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ارتقای دیتابیس‌های قدیمی: اگر جدول users از نسخه قبلی (با ستون‌های
    // first_name/username) مانده باشد، ستون‌های جدید را اضافه کن تا خطا ندهد
    $neededCols = [
        'points'     => "INT DEFAULT 0",
        'joined_at'  => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'is_blocked' => "TINYINT(1) DEFAULT 0",
        'is_admin'   => "TINYINT(1) DEFAULT 0",
    ];
    foreach ($neededCols as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM `users` LIKE '$col'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `$col` $definition;");
        }
    }

    // جدول قدیمی و غیراستفاده «panels» دیگر توسط ربات یا پنل وب خوانده نمی‌شود؛
    // برای جلوگیری از حذف ناخواسته‌ی داده، به‌صورت خودکار حذفش نمی‌کنیم، فقط دیگر ساخته نمی‌شود.

    if (basename($_SERVER['PHP_SELF']) === 'table.php') {
        echo "<div style='font-family:tahoma; direction:rtl; text-align:center; margin-top:50px;'>
                <h2 style='color:green;'>✅ جدول‌ها با موفقیت ساخته یا به‌روزرسانی شدند!</h2>
                <p style='color:#555; font-size:13px;'>جداول: users, user_states, settings, admins, extractions, backup_imports</p>
              </div>";
    }
} catch (PDOException $e) {
    die("<h2 style='color:red; text-align:center; font-family:tahoma; margin-top:50px;'>❌ خطا در دیتابیس: " . htmlspecialchars($e->getMessage()) . "</h2>");
}