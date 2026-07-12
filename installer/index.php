<?php
// installer/index.php - نصب‌کننده شیشه‌ای ربات (منطق نصب دست‌نخورده، فقط ظاهر آپگرید شد)
declare(strict_types=1);

$messageBox = '';
$messageType = ''; // success | warning | danger

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $botToken = $_POST['bot_token'] ?? '';
    $adminId = $_POST['admin_id'] ?? '';
    $dbHost = $_POST['db_host'] ?? 'localhost';
    $dbName = $_POST['db_name'] ?? '';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_pass'] ?? '';

    // ۱. ساخت محتوای فایل config.php با سینتکس کاملاً دقیق
    $configContent = "<?php\n";
    $configContent .= "declare(strict_types=1);\n\n";
    $configContent .= "define('BOT_TOKEN', '" . addslashes($botToken) . "');\n";
    $configContent .= "define('ADMIN_ID', " . (int)$adminId . ");\n\n";
    $configContent .= "define('DB_HOST', '" . addslashes($dbHost) . "');\n";
    $configContent .= "define('DB_NAME', '" . addslashes($dbName) . "');\n";
    $configContent .= "define('DB_USER', '" . addslashes($dbUser) . "');\n";
    $configContent .= "define('DB_PASS', '" . addslashes($dbPass) . "');\n";

    $configFilepath = dirname(__DIR__) . '/config.php';

    if (file_put_contents($configFilepath, $configContent)) {

        // ۲. فراخوانی خودکار فایل جداگانه table.php برای ایجاد جدول‌ها
        $tableFilepath = dirname(__DIR__) . '/table.php';
        if (file_exists($tableFilepath)) {
            include_once $tableFilepath;
        }

        // ۳. تنظیم خودکار وبهوک تلگرام
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $botUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['REQUEST_URI'])) . '/bot.php';

        $telegramUrl = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$botUrl}";
        $webhookResult = @file_get_contents($telegramUrl);
        $webhookJson = $webhookResult ? json_decode($webhookResult, true) : null;

        if ($webhookJson && ($webhookJson['ok'] ?? false)) {
            $messageType = 'success';
            $messageBox = '✅ نصب با موفقیت انجام شد! فایل تنظیمات ایجاد، جدول‌ها از طریق table.php ساخته و وبهوک فعال شد.';
        } else {
            $messageType = 'warning';
            $messageBox = '⚠️ تنظیمات و جدول‌ها اعمال شدند، اما تلگرام نتوانست وبهوک را ست کند. توکن یا SSL را بررسی کنید.';
        }
    } else {
        $messageType = 'danger';
        $messageBox = '❌ خطا: امکان ایجاد فایل config.php وجود ندارد. دسترسی پوشه اصلی هاست را بررسی کنید.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نصب ربات Team Kan</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<style>
:root{
    --bg1:#06070c; --bg2:#030308;
    --blue:#0a84ff; --purple:#bf5af2; --pink:#ff375f; --green:#30d158; --red:#ff453a; --orange:#ff9f0a;
    --glass-1:rgba(255,255,255,.055); --glass-2:rgba(255,255,255,.09);
    --glass-border:rgba(255,255,255,.14); --glass-border-strong:rgba(255,255,255,.4);
    --text:#f5f5f7; --muted:#98a0b3;
    --ease:cubic-bezier(.22,1,.36,1);
}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ scroll-behavior:smooth; }
body{
    margin:0; min-height:100vh; padding:26px; position:relative; overflow-x:hidden;
    font-family:'Vazirmatn', Tahoma, 'Segoe UI', sans-serif; color:var(--text);
    background:
        radial-gradient(1100px 750px at 12% -10%, rgba(10,132,255,.14), transparent 60%),
        radial-gradient(950px 680px at 108% 8%, rgba(191,90,242,.13), transparent 55%),
        radial-gradient(850px 850px at 50% 118%, rgba(255,55,95,.09), transparent 55%),
        linear-gradient(180deg, var(--bg1), var(--bg2));
    display:flex; align-items:center; justify-content:center;
}
.bg-blob{ position:fixed; border-radius:50%; filter:blur(120px); z-index:-2; pointer-events:none; animation:drift 20s ease-in-out infinite; mix-blend-mode:screen; }
.bg1{ width:520px; height:520px; background:var(--blue); opacity:.22; top:-180px; right:-140px; }
.bg2{ width:480px; height:480px; background:var(--purple); opacity:.20; bottom:-200px; left:-150px; animation-delay:-7s; }
.bg3{ width:340px; height:340px; background:var(--pink); opacity:.14; top:50%; left:52%; animation-delay:-13s; }
@keyframes drift{ 0%,100%{ transform:translate(0,0) scale(1);} 50%{ transform:translate(-40px,30px) scale(1.12);} }
.grain{ position:fixed; inset:0; z-index:-1; pointer-events:none; background-image:radial-gradient(rgba(255,255,255,.035) 1px, transparent 1px); background-size:3px 3px; opacity:.35; }

.container{
    position:relative; width:100%; max-width:460px;
    background:linear-gradient(165deg, var(--glass-2), var(--glass-1));
    backdrop-filter:blur(30px) saturate(180%); -webkit-backdrop-filter:blur(30px) saturate(180%);
    border:1px solid var(--glass-border); border-radius:28px;
    box-shadow:0 26px 70px rgba(0,0,0,.55), inset 0 0 0 1px rgba(255,255,255,.03);
    padding:36px 32px 30px; animation:riseIn .5s var(--ease);
}
@keyframes riseIn{ from{ opacity:0; transform:translateY(18px);} to{ opacity:1; transform:translateY(0);} }
.container::before{
    content:''; position:absolute; inset:0; border-radius:28px; padding:1px; pointer-events:none;
    background:linear-gradient(135deg, var(--glass-border-strong), rgba(255,255,255,0) 32%, rgba(255,255,255,0) 68%, rgba(255,255,255,.18));
    -webkit-mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite:xor; mask-composite:exclude;
}

.logo{ text-align:center; font-size:40px; margin-bottom:6px; filter:drop-shadow(0 6px 20px rgba(191,90,242,.55)); }
h2{
    text-align:center; margin:0 0 6px; font-size:19px; font-weight:800; letter-spacing:-.3px;
    background:linear-gradient(90deg,#8ec7ff,#d8b4fe 45%,#ffb3c9 85%);
    -webkit-background-clip:text; background-clip:text; color:transparent;
}
.sub{ text-align:center; color:var(--muted); font-size:12.5px; margin-bottom:24px; }

.alert{ padding:14px 16px; border-radius:14px; margin-bottom:20px; font-size:13px; line-height:1.9; border:1px solid; animation:riseIn .35s var(--ease); }
.alert.success{ background:rgba(48,209,88,.12); color:#8ff5ac; border-color:rgba(48,209,88,.35); }
.alert.warning{ background:rgba(255,159,10,.12); color:#ffcf8a; border-color:rgba(255,159,10,.35); }
.alert.danger{ background:rgba(255,69,58,.12); color:#ff9d97; border-color:rgba(255,69,58,.35); }

.section-title{
    display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:800; color:#d8b4fe;
    margin:22px 0 12px; padding-bottom:8px; border-bottom:1px solid var(--glass-border);
}
.section-title:first-of-type{ margin-top:0; }

.field{ position:relative; margin-bottom:14px; }
.field label{ display:block; font-size:11.5px; color:var(--muted); font-weight:700; margin-bottom:6px; }
.field input{
    width:100%; padding:13px 14px; border-radius:13px; border:1px solid var(--glass-border);
    background:rgba(0,0,0,.32); color:var(--text); font-size:13.5px; font-family:inherit;
    transition:.2s var(--ease); direction:ltr; text-align:left;
}
.field input::placeholder{ color:#5b6472; }
.field input:focus{ outline:none; border-color:var(--blue); box-shadow:0 0 0 4px rgba(10,132,255,.18); background:rgba(0,0,0,.45); }
.field.rtl-field input{ direction:rtl; text-align:right; }

.toggle-eye{
    position:absolute; left:12px; top:38px; cursor:pointer; color:var(--muted); font-size:14px;
    background:none; border:none; padding:4px; user-select:none;
}
.toggle-eye:hover{ color:#fff; }

button.submit-btn{
    width:100%; padding:15px; margin-top:22px; border:none; border-radius:14px;
    background:linear-gradient(135deg, var(--blue), var(--purple) 60%, var(--pink));
    color:#fff; font-size:14.5px; font-weight:800; cursor:pointer; letter-spacing:.2px;
    box-shadow:0 12px 30px rgba(139,92,246,.4), inset 0 1px 0 rgba(255,255,255,.4);
    transition:.15s transform var(--ease), .15s filter var(--ease);
    display:flex; align-items:center; justify-content:center; gap:8px;
}
button.submit-btn:hover{ filter:brightness(1.12); transform:translateY(-2px); }
button.submit-btn:active{ transform:scale(.97); }
button.submit-btn:disabled{ opacity:.6; cursor:not-allowed; transform:none; }

.spinner{
    width:16px; height:16px; border-radius:50%; border:2.5px solid rgba(255,255,255,.4);
    border-top-color:#fff; animation:spin .7s linear infinite; display:none;
}
@keyframes spin{ to{ transform:rotate(360deg); } }
button.submit-btn.loading .spinner{ display:inline-block; }
button.submit-btn.loading .btn-label{ opacity:.85; }

.hint{ font-size:11px; color:var(--muted-2, #6b7386); text-align:center; margin-top:16px; line-height:1.9; }
.hint code{ background:rgba(0,0,0,.3); padding:2px 8px; border-radius:8px; direction:ltr; display:inline-block; }
</style>
</head>
<body>
<div class="bg-blob bg1"></div><div class="bg-blob bg2"></div><div class="bg-blob bg3"></div>
<div class="grain"></div>

<div class="container">
    <div class="logo">🚀</div>
    <h2>نصب‌کننده ربات Team Kan</h2>
    <div class="sub">اطلاعات زیر را وارد کنید تا ربات، دیتابیس و وبهوک به‌صورت خودکار راه‌اندازی شوند.</div>

    <?php if ($messageBox): ?>
        <div class="alert <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>"><?= $messageBox ?></div>
    <?php endif; ?>

    <form method="POST" id="install-form">
        <div class="section-title">🤖 تنظیمات ربات تلگرام</div>

        <div class="field">
            <label>توکن ربات (از @BotFather)</label>
            <input type="text" name="bot_token" placeholder="123456789:AAExxxxxxxxxxxxxxxxxxxxxxxxxxxxx" required autocomplete="off">
        </div>

        <div class="field rtl-field">
            <label>آیدی عددی ادمین (از @userinfobot)</label>
            <input type="number" name="admin_id" placeholder="مثلاً 123456789" required>
        </div>

        <div class="section-title">🗄 تنظیمات دیتابیس (phpMyAdmin)</div>

        <div class="field rtl-field">
            <label>هاست دیتابیس</label>
            <input type="text" name="db_host" value="localhost" placeholder="معمولاً localhost">
        </div>

        <div class="field rtl-field">
            <label>نام دیتابیس</label>
            <input type="text" name="db_name" placeholder="مثلاً ifrjowno_botdb" required>
        </div>

        <div class="field rtl-field">
            <label>نام کاربری دیتابیس</label>
            <input type="text" name="db_user" placeholder="مثلاً ifrjowno_user" required>
        </div>

        <div class="field">
            <label>رمز عبور دیتابیس</label>
            <input type="password" name="db_pass" id="db-pass-input" placeholder="رمز دیتابیس">
            <button type="button" class="toggle-eye" onclick="togglePass()">👁</button>
        </div>

        <button type="submit" class="submit-btn" id="submit-btn">
            <span class="spinner"></span>
            <span class="btn-label">🚀 راه‌اندازی پروژه</span>
        </button>

        <div class="hint">با کلیک روی دکمه، فایل <code>config.php</code> ساخته می‌شود، جدول‌های دیتابیس از طریق <code>table.php</code> ایجاد می‌گردند و وبهوک تلگرام به‌صورت خودکار روی <code>bot.php</code> تنظیم می‌شود.</div>
    </form>
</div>

<script>
function togglePass() {
    const input = document.getElementById('db-pass-input');
    input.type = input.type === 'password' ? 'text' : 'password';
}
document.getElementById('install-form').addEventListener('submit', function () {
    const btn = document.getElementById('submit-btn');
    btn.classList.add('loading');
    btn.disabled = true;
    btn.querySelector('.btn-label').textContent = 'در حال نصب...';
});
</script>
</body>
</html>
