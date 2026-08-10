<?php
// sub_view.php - نمایش عمومی وضعیت اشتراک (لینک قابل اشتراک‌گذاری) - نسخه‌ی خفن‌تر با تم بنفش/نئونی
declare(strict_types=1);
date_default_timezone_set('Asia/Tehran'); // باید هم‌راستا با bot.php باشه
require_once 'config.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));

function renderNotFound(string $model = 'modern', string $theme = 'black'): void {
    $palettesModern = [
        'black'   => ['bg' => '#07070b', 'bg1' => '79,240,255',  'bg2' => '160,107,255', 'boxBg' => 'rgba(15,15,22,.75)',  'boxBorder' => '34,34,47',   'icon' => '79,240,255',  'text' => '#f3f3f7', 'muted' => '#8b8b9a', 'grad1' => '#f3f3f7', 'grad2' => '#4ff0ff'],
        'white'   => ['bg' => '#f2f2f5', 'bg1' => '47,107,255',  'bg2' => '122,60,255',  'boxBg' => 'rgba(255,255,255,.9)', 'boxBorder' => '220,220,227', 'icon' => '47,107,255',  'text' => '#14141b', 'muted' => '#6a6a76', 'grad1' => '#14141b', 'grad2' => '#2f6bff'],
        'red'     => ['bg' => '#0a0505', 'bg1' => '255,59,78',   'bg2' => '255,156,79',  'boxBg' => 'rgba(21,8,8,.75)',    'boxBorder' => '43,18,18',   'icon' => '255,59,78',   'text' => '#fbecec', 'muted' => '#a17f7f', 'grad1' => '#fbecec', 'grad2' => '#ff9c4f'],
        'purple'  => ['bg' => '#0a0713', 'bg1' => '176,107,255', 'bg2' => '79,240,255',  'boxBg' => 'rgba(19,13,32,.75)',  'boxBorder' => '36,26,56',   'icon' => '176,107,255', 'text' => '#f2edfb', 'muted' => '#9186ac', 'grad1' => '#f2edfb', 'grad2' => '#b06bff'],
        'ocean'   => ['bg' => '#020617', 'bg1' => '34,211,238',  'bg2' => '59,130,246',  'boxBg' => 'rgba(10,22,40,.75)',  'boxBorder' => '22,50,79',   'icon' => '34,211,238',  'text' => '#e0f2fe', 'muted' => '#7ea3c2', 'grad1' => '#e0f2fe', 'grad2' => '#22d3ee'],
        'emerald' => ['bg' => '#020e0a', 'bg1' => '16,185,129',  'bg2' => '52,211,153',  'boxBg' => 'rgba(5,32,24,.75)',   'boxBorder' => '15,61,44',   'icon' => '16,185,129',  'text' => '#e7fbf3', 'muted' => '#7fc9a8', 'grad1' => '#e7fbf3', 'grad2' => '#34d399'],
    ];
    $palettesClassic = [
        'default' => ['bg' => '#050107', 'bg1' => '168,85,247', 'bg2' => '236,72,153', 'boxBg' => 'rgba(24,16,36,.55)', 'boxBorder' => '216,180,254', 'icon' => '217,70,239', 'text' => '#f3e8ff', 'muted' => '#a78bc4', 'grad1' => '#e9d5ff', 'grad2' => '#f0abfc'],
        'ocean'   => ['bg' => '#020617', 'bg1' => '14,165,233',  'bg2' => '6,182,212',  'boxBg' => 'rgba(10,22,40,.55)', 'boxBorder' => '186,230,253', 'icon' => '6,182,212',  'text' => '#e0f2fe', 'muted' => '#8fb8d9', 'grad1' => '#e0f2fe', 'grad2' => '#67e8f9'],
        'emerald' => ['bg' => '#020e0a', 'bg1' => '5,150,105',   'bg2' => '16,185,129', 'boxBg' => 'rgba(5,32,24,.55)',  'boxBorder' => '167,243,208', 'icon' => '16,185,129', 'text' => '#e7fbf3', 'muted' => '#8fceb2', 'grad1' => '#e7fbf3', 'grad2' => '#6ee7b7'],
        'ember'   => ['bg' => '#0d0602', 'bg1' => '245,158,11',  'bg2' => '249,115,22', 'boxBg' => 'rgba(50,24,6,.55)',  'boxBorder' => '254,215,170', 'icon' => '249,115,22', 'text' => '#fff4e6', 'muted' => '#d9ac7d', 'grad1' => '#fff4e6', 'grad2' => '#fbbf24'],
        'gold'    => ['bg' => '#060503', 'bg1' => '212,175,55',  'bg2' => '245,197,66', 'boxBg' => 'rgba(40,32,10,.55)', 'boxBorder' => '245,197,66',  'icon' => '245,197,66', 'text' => '#fdf6e3', 'muted' => '#cbb677', 'grad1' => '#fdf6e3', 'grad2' => '#ffe08a'],
        'crimson' => ['bg' => '#0c0206', 'bg1' => '225,29,72',   'bg2' => '244,63,94',  'boxBg' => 'rgba(50,10,24,.55)', 'boxBorder' => '254,205,211', 'icon' => '244,63,94',  'text' => '#ffe4e9', 'muted' => '#d999a8', 'grad1' => '#ffe4e9', 'grad2' => '#fda4af'],
    ];
    $palettesTerminal = [
        'green' => ['bg' => '#020603', 'bg1' => '0,255,102',  'bg2' => '79,255,122', 'boxBg' => 'rgba(5,15,7,.85)',  'boxBorder' => '26,61,36',  'icon' => '0,255,102',  'text' => '#4fff7a', 'muted' => '#2f8a49', 'grad1' => '#4fff7a', 'grad2' => '#00ff66'],
        'amber' => ['bg' => '#0a0602', 'bg1' => '255,176,0',  'bg2' => '255,204,51', 'boxBg' => 'rgba(21,13,5,.85)', 'boxBorder' => '61,42,15',  'icon' => '255,176,0',  'text' => '#ffb000', 'muted' => '#a06b0a', 'grad1' => '#ffb000', 'grad2' => '#ffcc33'],
        'blue'  => ['bg' => '#01040a', 'bg1' => '90,200,255', 'bg2' => '127,219,255','boxBg' => 'rgba(5,12,28,.85)', 'boxBorder' => '15,42,74',  'icon' => '90,200,255', 'text' => '#5ac8ff', 'muted' => '#2f6ea3', 'grad1' => '#5ac8ff', 'grad2' => '#7fdbff'],
        'red'   => ['bg' => '#0a0202', 'bg1' => '255,95,95',  'bg2' => '255,128,128','boxBg' => 'rgba(23,5,5,.85)',  'boxBorder' => '61,16,16',  'icon' => '255,95,95',  'text' => '#ff5f5f', 'muted' => '#a33a3a', 'grad1' => '#ff5f5f', 'grad2' => '#ff8080'],
        'white' => ['bg' => '#f4f4f0', 'bg1' => '10,122,61',  'bg2' => '46,164,94',  'boxBg' => 'rgba(255,255,255,.9)', 'boxBorder' => '216,216,208', 'icon' => '10,122,61', 'text' => '#1a1a18', 'muted' => '#6b6b64', 'grad1' => '#1a1a18', 'grad2' => '#0a7a3d'],
        'cyan'  => ['bg' => '#020a0a', 'bg1' => '77,255,242', 'bg2' => '0,255,242',  'boxBg' => 'rgba(4,20,20,.85)', 'boxBorder' => '15,61,58',  'icon' => '77,255,242', 'text' => '#4dfff2', 'muted' => '#2a8a82', 'grad1' => '#4dfff2', 'grad2' => '#00fff2'],
    ];
    $palettesElite = [
        'gold'     => ['bg' => '#0a0906', 'bg1' => '201,168,118', 'bg2' => '232,207,154', 'boxBg' => 'rgba(17,16,8,.85)', 'boxBorder' => '58,51,32',  'icon' => '201,168,118', 'text' => '#f2ede0', 'muted' => '#8a8270', 'grad1' => '#f2ede0', 'grad2' => '#c9a876'],
        'platinum' => ['bg' => '#0a0a0b', 'bg1' => '199,204,212', 'bg2' => '238,241,245', 'boxBg' => 'rgba(17,17,19,.85)', 'boxBorder' => '51,53,58', 'icon' => '199,204,212', 'text' => '#eef0f2', 'muted' => '#83898f', 'grad1' => '#eef0f2', 'grad2' => '#c7ccd4'],
        'rosegold' => ['bg' => '#0c0807', 'bg1' => '217,168,148', 'bg2' => '240,201,184', 'boxBg' => 'rgba(20,15,13,.85)', 'boxBorder' => '61,42,36', 'icon' => '217,168,148', 'text' => '#f3e9e4', 'muted' => '#8f7168', 'grad1' => '#f3e9e4', 'grad2' => '#d9a894'],
        'emerald'  => ['bg' => '#070b09', 'bg1' => '168,201,168', 'bg2' => '201,232,201', 'boxBg' => 'rgba(13,19,16,.85)', 'boxBorder' => '35,58,44', 'icon' => '168,201,168', 'text' => '#e9f3ea', 'muted' => '#6f8a75', 'grad1' => '#e9f3ea', 'grad2' => '#a8c9a8'],
        'sapphire' => ['bg' => '#070a0e', 'bg1' => '168,189,217', 'bg2' => '201,219,240', 'boxBg' => 'rgba(13,19,27,.85)', 'boxBorder' => '35,48,71', 'icon' => '168,189,217', 'text' => '#e9eef3', 'muted' => '#6f7f8f', 'grad1' => '#e9eef3', 'grad2' => '#a8bdd9'],
        'onyx'     => ['bg' => '#050505', 'bg1' => '232,232,232', 'bg2' => '255,255,255', 'boxBg' => 'rgba(12,12,12,.85)', 'boxBorder' => '44,44,44', 'icon' => '232,232,232', 'text' => '#f5f5f5', 'muted' => '#828282', 'grad1' => '#f5f5f5', 'grad2' => '#e8e8e8'],
    ];
    if ($model === 'classic') { $palettes = $palettesClassic; $fallbackKey = 'default'; }
    elseif ($model === 'terminal') { $palettes = $palettesTerminal; $fallbackKey = 'green'; }
    elseif ($model === 'elite') { $palettes = $palettesElite; $fallbackKey = 'gold'; }
    else { $palettes = $palettesModern; $fallbackKey = 'black'; }
    $p = $palettes[$theme] ?? $palettes[$fallbackKey];
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>یافت نشد</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css"></noscript>
        <style>
            *{ box-sizing:border-box; }
            body{
                font-family:'Vazirmatn',Tahoma,sans-serif; background:<?= $p['bg'] ?>; color:<?= $p['text'] ?>;
                display:flex; align-items:center; justify-content:center; height:100vh; margin:0; text-align:center;
                background-image:
                    radial-gradient(circle at 15% 20%, rgba(<?= $p['bg1'] ?>,.25), transparent 45%),
                    radial-gradient(circle at 85% 80%, rgba(<?= $p['bg2'] ?>,.18), transparent 45%);
            }
            .box{
                background:<?= $p['boxBg'] ?>; border:1px solid rgba(<?= $p['boxBorder'] ?>,.6); border-radius:20px;
                padding:44px 32px; max-width:340px; backdrop-filter:blur(16px);
                box-shadow:0 0 40px rgba(<?= $p['bg1'] ?>,.15), inset 0 0 30px rgba(<?= $p['bg1'] ?>,.05);
            }
            .icon{ font-size:44px; margin-bottom:12px; filter:drop-shadow(0 0 14px rgba(<?= $p['icon'] ?>,.6)); }
            h2{ margin:0 0 8px; font-size:16px; background:linear-gradient(90deg,<?= $p['grad1'] ?>,<?= $p['grad2'] ?>); -webkit-background-clip:text; background-clip:text; color:transparent; }
            p{ color:<?= $p['muted'] ?>; font-size:13px; line-height:1.8; }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="icon">🔍</div>
            <h2>این لینک یافت نشد</h2>
            <p>ممکن است لینک اشتباه باشد یا اطلاعات آن پاک شده باشد. لطفاً دوباره از داخل ربات استخراج کنید.</p>
        </div>
    </body>
    </html>
    <?php
}

if ($token === '') { http_response_code(404); renderNotFound(); exit; }

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('خطای اتصال به دیتابیس');
}

// سه تا مدل کاملاً متفاوت داری (نه فقط رنگ): «کلاسیک» (کارت‌های شیشه‌ای بنفش/چندساختاری)،
// «مدرن» (طراحی جدید با گرید اپ‌ها و افکت‌های ذره‌ای)، «ترمینال» (پنجره‌ی ترمینال با بارون
// ماتریکسی و افکت CRT)، و «الیت» (طراحی خاص/لاکچری مینیمال با حاشیه‌های طلایی).
// هر مدل تم‌های رنگی خودش رو داره.
// همین یه‌بار می‌خونیمش و هم برای صفحه‌ی اصلی هم برای صفحه‌ی «یافت نشد/منقضی‌شده» استفاده می‌شه.
$subViewModel = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sub_view_model'")->fetchColumn() ?: 'modern');
if (!in_array($subViewModel, ['classic', 'modern', 'terminal', 'elite'], true)) $subViewModel = 'modern';

if ($subViewModel === 'classic') {
    $allowedThemes = ['default', 'ocean', 'emerald', 'ember', 'gold', 'crimson'];
    $subViewTheme = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sub_view_theme_classic'")->fetchColumn() ?: 'default');
    if (!in_array($subViewTheme, $allowedThemes, true)) $subViewTheme = 'default';
} elseif ($subViewModel === 'terminal') {
    $allowedThemes = ['green', 'amber', 'blue', 'red', 'white', 'cyan'];
    $subViewTheme = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sub_view_theme_terminal'")->fetchColumn() ?: 'green');
    if (!in_array($subViewTheme, $allowedThemes, true)) $subViewTheme = 'green';
} elseif ($subViewModel === 'elite') {
    $allowedThemes = ['gold', 'platinum', 'rosegold', 'emerald', 'sapphire', 'onyx'];
    $subViewTheme = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sub_view_theme_elite'")->fetchColumn() ?: 'gold');
    if (!in_array($subViewTheme, $allowedThemes, true)) $subViewTheme = 'gold';
} else {
    $allowedThemes = ['black', 'white', 'red', 'purple', 'ocean', 'emerald'];
    $subViewTheme = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sub_view_theme_modern'")->fetchColumn() ?: 'black');
    if (!in_array($subViewTheme, $allowedThemes, true)) $subViewTheme = 'black';
}

const EXTRACTION_TTL_SECONDS = 300;

$stmt = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(created_at) AS created_at_ts FROM extractions WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) { http_response_code(404); renderNotFound($subViewModel, $subViewTheme); exit; }

// نکته حریم‌خصوصی: هر لینک استخراج فقط ۵ دقیقه معتبره. اگه منقضی شده، همین‌جا
// حذفش می‌کنیم و دقیقاً همون صفحه‌ی «یافت نشد» رو نشون می‌دیم (نه پیام جداگانه‌ی
// «منقضی شده») تا از بیرون قابل تشخیص نباشه که این توکن قبلاً واقعاً وجود داشته یا نه.
$createdAtTs = (int)($row['created_at_ts'] ?? 0);
if (time() - $createdAtTs > EXTRACTION_TTL_SECONDS) {
    $pdo->prepare("DELETE FROM extractions WHERE token = ?")->execute([$token]);
    http_response_code(404);
    renderNotFound($subViewModel, $subViewTheme);
    exit;
}

function fmtBytes($bytes): string {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow   = (int)floor(log($bytes) / log(1024));
    $pow   = min($pow, count($units) - 1);
    return round($bytes / (1 << (10 * $pow)), 2) . ' ' . $units[$pow];
}

$totalBytes  = (float)$row['total_bytes'];
$usedBytes   = (float)$row['used_bytes'];
$remainBytes = max(0, $totalBytes - $usedBytes);
$hasVolumeLimit = $totalBytes > 0;
$usedPercent = $hasVolumeLimit ? min(100, round(($usedBytes / $totalBytes) * 100)) : 0;

$expireTs = (int)$row['expire_ts'];
$now      = time();
$hasExpiry = $expireTs > 0;
$daysLeft  = $hasExpiry ? max(0, (int)ceil(($expireTs - $now) / 86400)) : null;
$expiredByTime   = $hasExpiry && $expireTs <= $now;
$expiredByVolume = $hasVolumeLimit && $remainBytes <= 0;
$isActive = !$expiredByTime && !$expiredByVolume;

$configs = json_decode($row['configs_json'] ?? '[]', true);
if (!is_array($configs)) $configs = [];

$protocolCounts = [];
$firstVless = null;
foreach ($configs as $c) {
    $p = strtoupper((string)($c['protocol'] ?? 'OTHER'));
    $protocolCounts[$p] = ($protocolCounts[$p] ?? 0) + 1;
    if ($firstVless === null && strtolower($p) === 'vless') $firstVless = $c;
}
arsort($protocolCounts);

$subUrl   = (string)($row['sub_url'] ?? '');
$qrUrl    = "https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=" . urlencode($subUrl) . "&bgcolor=255-255-255&color=0-0-0&margin=1";
$updatedAt = $row['created_at'] ?? '';
?>
<?php if ($subViewModel === "classic"): ?>
<!doctype html>
<html lang="fa" dir="rtl" data-theme="<?= htmlspecialchars($subViewTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<title>وضعیت اشتراک شما</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css"></noscript>
<style>
    :root{
        --bg:#050107;
        --panel:rgba(30,14,46,.55);
        --panel-solid:#160a22;
        --border:rgba(216,180,254,.14);
        --border-strong:color-mix(in srgb, var(--primary2) 40%, transparent);
        --primary:#a855f7;
        --primary2:#d946ef;
        --accent3:#6d28d9;
        --neon:#e879f9;
        --success:#34d399;
        --danger:#fb7185;
        --muted:#b09ac9;
        --text:#f3e8ff;
    }
    /* 🌊 اقیانوسی - آبی/فیروزه‌ای */
    :root[data-theme="ocean"]{
        --bg:#020617;
        --panel:rgba(12,32,58,.55);
        --panel-solid:#081a2e;
        --border:rgba(186,230,253,.14);
        --primary:#0ea5e9;
        --primary2:#06b6d4;
        --accent3:#0369a1;
        --neon:#67e8f9;
        --muted:#8fb8d9;
        --text:#e0f2fe;
    }
    /* 🟢 زمرد - سبز */
    :root[data-theme="emerald"]{
        --bg:#020e0a;
        --panel:rgba(6,42,32,.55);
        --panel-solid:#052018;
        --border:rgba(167,243,208,.14);
        --primary:#059669;
        --primary2:#10b981;
        --accent3:#047857;
        --neon:#6ee7b7;
        --muted:#8fceb2;
        --text:#e7fbf3;
    }
    /* 🔥 آتشین - کهربایی/نارنجی */
    :root[data-theme="ember"]{
        --bg:#0d0602;
        --panel:rgba(50,24,6,.55);
        --panel-solid:#241004;
        --border:rgba(254,215,170,.16);
        --primary:#f59e0b;
        --primary2:#f97316;
        --accent3:#b45309;
        --neon:#fbbf24;
        --muted:#d9ac7d;
        --text:#fff4e6;
    }
    /* 🏆 طلایی - لاکچری مشکی/طلایی */
    :root[data-theme="gold"]{
        --bg:#060503;
        --panel:rgba(40,32,10,.55);
        --panel-solid:#1a1509;
        --border:rgba(245,197,66,.16);
        --primary:#d4af37;
        --primary2:#f5c542;
        --accent3:#8b6f1f;
        --neon:#ffe08a;
        --muted:#cbb677;
        --text:#fdf6e3;
    }
    /* 🌹 یاقوتی - قرمز/رز */
    :root[data-theme="crimson"]{
        --bg:#0c0206;
        --panel:rgba(50,10,24,.55);
        --panel-solid:#220513;
        --border:rgba(254,205,211,.14);
        --primary:#e11d48;
        --primary2:#f43f5e;
        --accent3:#9f1239;
        --neon:#fda4af;
        --muted:#d999a8;
        --text:#ffe4e9;
    }
    *{ box-sizing:border-box; }
    body{
        font-family:'Vazirmatn',Tahoma,sans-serif; background:var(--bg); color:var(--text);
        margin:0; padding:24px 16px 60px; min-height:100vh; position:relative; overflow-x:hidden;
        background-image:
            radial-gradient(circle at 10% 10%, color-mix(in srgb, var(--primary) 22%, transparent), transparent 40%),
            radial-gradient(circle at 90% 15%, color-mix(in srgb, var(--primary2) 14%, transparent), transparent 40%),
            radial-gradient(circle at 50% 100%, color-mix(in srgb, var(--accent3) 22%, transparent), transparent 45%);
    }
    .bg-blob{ position:fixed; border-radius:50%; filter:blur(70px); opacity:.35; z-index:-1; pointer-events:none; animation:drift 18s ease-in-out infinite; }
    .bg1{ width:440px; height:440px; background:var(--primary); top:-170px; right:-110px; }
    .bg2{ width:400px; height:400px; background:var(--accent3); bottom:-170px; left:-130px; animation-delay:-6s; }
    .bg3{ width:300px; height:300px; background:var(--neon); top:42%; left:58%; opacity:.22; animation-delay:-11s; }
    @keyframes drift{ 0%,100%{ transform:translate(0,0) scale(1);} 50%{ transform:translate(-30px,25px) scale(1.1);} }

    .noise{ position:fixed; inset:0; pointer-events:none; z-index:-1; opacity:.035;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); }

    .wrap{ max-width:460px; margin:0 auto; }
    .head{ text-align:center; margin-bottom:20px; display:flex; flex-direction:column; align-items:center; gap:10px; }
    .head .icon{
        width:76px; height:76px; border-radius:50%; margin:0 auto;
        background:linear-gradient(135deg, var(--primary), var(--primary2));
        display:flex; align-items:center; justify-content:center; font-size:32px;
        box-shadow:0 0 0 1px rgba(255,255,255,.08), 0 0 35px color-mix(in srgb, var(--primary2) 55%, transparent), 0 10px 30px color-mix(in srgb, var(--accent3) 50%, transparent);
        animation:pulseGlow 2.6s ease-in-out infinite;
    }
    @keyframes pulseGlow{
        0%,100%{ box-shadow:0 0 0 1px rgba(255,255,255,.08), 0 0 25px color-mix(in srgb, var(--primary2) 40%, transparent), 0 10px 30px color-mix(in srgb, var(--accent3) 40%, transparent); }
        50%{ box-shadow:0 0 0 1px rgba(255,255,255,.12), 0 0 48px color-mix(in srgb, var(--primary2) 75%, transparent), 0 10px 40px color-mix(in srgb, var(--accent3) 60%, transparent); }
    }
    .head h1{
        font-size:19px; margin:0 0 6px; font-weight:800;
        background:linear-gradient(90deg,#f3e8ff,#f0abfc 50%,#e9d5ff);
        -webkit-background-clip:text; background-clip:text; color:transparent;
        letter-spacing:.2px;
    }
    .head .sub{ color:var(--muted); font-size:12.5px; }
    .head .sub b{ color:#f0abfc; }

    .status-badge{
        display:block; text-align:center; padding:14px; border-radius:14px; font-weight:800; font-size:13.5px; margin-bottom:16px;
        border:1px solid; transition:.25s; letter-spacing:.2px;
    }
    .status-active{ background:rgba(52,211,153,.1); border-color:rgba(52,211,153,.35); color:#5eead4; box-shadow:0 0 22px rgba(52,211,153,.15); }
    .status-inactive{ background:rgba(251,113,133,.1); border-color:rgba(251,113,133,.35); color:#fda4af; box-shadow:0 0 22px rgba(251,113,133,.15); }

    .card{
        background:var(--panel); backdrop-filter:blur(16px); border:1px solid var(--border); border-radius:18px;
        padding:18px; margin-bottom:14px; position:relative; overflow:hidden;
        box-shadow:0 4px 24px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.03);
    }
    .card::before{
        content:''; position:absolute; inset:0; border-radius:18px; padding:1px;
        background:linear-gradient(135deg, rgba(216,180,254,.25), color-mix(in srgb, var(--primary2) 0%, transparent) 40%, color-mix(in srgb, var(--primary2) 15%, transparent) 100%);
        -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none;
    }
    .card-title{ display:flex; align-items:center; justify-content:space-between; font-size:13px; color:var(--muted); font-weight:700; margin-bottom:12px; gap:8px; }
    .card-percent{ font-size:15px; font-weight:800; white-space:nowrap; color:#f0abfc; }
    .bar-bg{ width:100%; height:9px; border-radius:99px; background:rgba(255,255,255,.06); overflow:hidden; margin-bottom:12px; }
    .bar-fill{ height:100%; border-radius:99px; background:linear-gradient(90deg, var(--primary), var(--neon)); transition:width .5s; box-shadow:0 0 12px color-mix(in srgb, var(--primary2) 60%, transparent); }
    .bar-fill.warn{ background:linear-gradient(90deg, var(--danger), #f59e0b); box-shadow:0 0 12px rgba(251,113,133,.6); }
    .card-foot{ display:flex; justify-content:space-between; font-size:12.5px; color:var(--muted); }
    .card-foot b{ color:var(--text); }

    .protocols{ display:flex; flex-wrap:wrap; gap:8px; }
    .proto-pill{
        font-size:12px; padding:6px 12px; border-radius:999px; background:color-mix(in srgb, var(--primary2) 12%, transparent); color:#f0abfc;
        border:1px solid color-mix(in srgb, var(--primary2) 30%, transparent); font-weight:700; box-shadow:0 0 10px color-mix(in srgb, var(--primary2) 12%, transparent);
    }

    .actions{ display:flex; flex-direction:column; gap:10px; }
    .action-btn{
        display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-radius:13px;
        background:rgba(255,255,255,.03); border:1px solid var(--border); cursor:pointer; transition:.18s;
        font-family:inherit; color:var(--text); font-size:13.5px; font-weight:700; width:100%; text-align:right;
    }
    .action-btn:hover{ border-color:var(--border-strong); transform:translateY(-1px); box-shadow:0 6px 22px color-mix(in srgb, var(--primary2) 18%, transparent); }
    .action-btn:disabled{ opacity:.5; cursor:not-allowed; transform:none; }
    .action-btn .copy-tag{ font-size:11px; color:#f0abfc; background:color-mix(in srgb, var(--primary2) 14%, transparent); padding:4px 10px; border-radius:8px; white-space:nowrap; }
    .action-btn.copied .copy-tag{ color:#5eead4; background:rgba(52,211,153,.14); }
    .action-btn.primary-action{
        background:linear-gradient(135deg, color-mix(in srgb, var(--primary) 22%, transparent), color-mix(in srgb, var(--primary2) 22%, transparent));
        border-color:color-mix(in srgb, var(--primary2) 40%, transparent); box-shadow:0 0 20px color-mix(in srgb, var(--primary2) 15%, transparent);
    }

    .spin{ display:inline-block; width:13px; height:13px; border-radius:50%; border:2px solid rgba(255,255,255,.25); border-top-color:#fff; animation:spin 0.7s linear infinite; vertical-align:-2px; }
    @keyframes spin{ to{ transform:rotate(360deg); } }

    .qr-wrap{ text-align:center; }
    .qr-wrap img{ width:200px; height:200px; border-radius:14px; background:#fff; padding:10px; box-shadow:0 0 30px color-mix(in srgb, var(--primary2) 25%, transparent); }

    .search-box{
        width:100%; padding:11px 14px; border-radius:11px; border:1px solid var(--border); background:rgba(12,6,20,.6);
        color:var(--text); font-size:13px; font-family:inherit; margin-bottom:12px;
    }
    .search-box::placeholder{ color:#8a76a8; }
    .search-box:focus{ outline:none; border-color:var(--primary); box-shadow:0 0 0 3px color-mix(in srgb, var(--primary) 15%, transparent); }
    .config-list{ display:flex; flex-direction:column; gap:8px; max-height:420px; overflow-y:auto; padding-inline-end:4px; }
    .config-item{
        display:flex; align-items:center; gap:10px; background:rgba(255,255,255,.03); border:1px solid var(--border);
        border-radius:12px; padding:10px 12px; transition:.15s;
    }
    .config-item:hover{ border-color:var(--border-strong); box-shadow:0 4px 16px color-mix(in srgb, var(--primary2) 15%, transparent); }
    .config-item .ci-flag{ font-size:20px; flex-shrink:0; line-height:1; filter:drop-shadow(0 0 4px rgba(0,0,0,.4)); }
    .config-item .ci-info{ flex:1; min-width:0; }
    .config-item .ci-name{
        font-size:12.5px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        direction:ltr; unicode-bidi:plaintext; text-align:right; color:var(--text);
    }
    .config-item .ci-proto{
        font-size:10.5px; color:var(--muted); margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        direction:ltr; unicode-bidi:plaintext; text-align:right; font-family:'Vazirmatn',Tahoma,monospace; letter-spacing:.2px;
    }
    .config-item .ci-proto b{ color:#f0abfc; font-weight:700; }
    .config-item .ci-actions{ display:flex; align-items:center; gap:6px; flex-shrink:0; }
    .config-item .ci-btn{
        font-size:11px; color:#f0abfc; background:color-mix(in srgb, var(--primary2) 14%, transparent); padding:6px 10px; border-radius:8px;
        border:1px solid transparent; cursor:pointer; font-family:inherit; transition:.15s; white-space:nowrap;
    }
    .config-item .ci-btn:hover{ border-color:color-mix(in srgb, var(--primary2) 40%, transparent); transform:translateY(-1px); }
    .config-item .ci-btn.ci-copy.copied{ color:#5eead4; background:rgba(52,211,153,.14); }
    .config-item .ci-btn.ci-qr{ color:#93c5fd; background:rgba(59,130,246,.14); }
    .config-item .ci-btn.ci-qr:hover{ border-color:rgba(59,130,246,.4); }
    .config-empty{ text-align:center; color:var(--muted); font-size:12.5px; padding:20px; }

    .toast{
        position:fixed; bottom:22px; left:50%; transform:translateX(-50%) translateY(10px); background:rgba(22,10,34,.92);
        backdrop-filter:blur(14px); border:1px solid var(--border-strong); padding:12px 22px; border-radius:13px; font-size:13px;
        opacity:0; transition:.3s; pointer-events:none; z-index:999; box-shadow:0 10px 34px color-mix(in srgb, var(--primary2) 25%, transparent); font-weight:600;
    }
    .toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

    .footer{ text-align:center; color:var(--muted); font-size:11.5px; margin-top:22px; line-height:1.9; }
    .footer b{ background:linear-gradient(90deg,#f0abfc,#c4b5fd); -webkit-background-clip:text; background-clip:text; color:transparent; }

    ::-webkit-scrollbar{ width:6px; }
    ::-webkit-scrollbar-thumb{ background:color-mix(in srgb, var(--primary2) 35%, transparent); border-radius:99px; }

    .qr-modal{
        position:fixed; inset:0; background:rgba(4,2,8,.78); backdrop-filter:blur(8px);
        display:none; align-items:center; justify-content:center; z-index:1000; padding:20px;
    }
    .qr-modal.show{ display:flex; }
    .qr-modal-inner{
        background:var(--panel-solid); border:1px solid var(--border-strong); border-radius:20px; padding:24px;
        max-width:300px; width:100%; text-align:center; box-shadow:0 0 50px color-mix(in srgb, var(--primary2) 30%, transparent);
    }
    .qr-modal-title{
        font-size:13px; font-weight:700; margin-bottom:16px; color:var(--text);
        direction:ltr; unicode-bidi:plaintext; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .qr-modal-inner img{ width:220px; height:220px; border-radius:14px; background:#fff; padding:10px; box-shadow:0 0 24px color-mix(in srgb, var(--primary2) 20%, transparent); }
    .qr-modal-close{
        margin-top:16px; width:100%; padding:11px; border-radius:11px; border:1px solid var(--border);
        background:rgba(255,255,255,.04); color:var(--text); cursor:pointer; font-family:inherit; font-weight:700; font-size:13px;
    }
    .qr-modal-close:hover{ border-color:var(--border-strong); }
</style>
</head>
<body>

<div class="noise"></div>
<div class="bg-blob bg1"></div>
<div class="bg-blob bg2"></div>
<div class="bg-blob bg3"></div>

<div class="wrap">
    <div class="head">
        <div class="icon" id="headIcon"><?= $isActive ? '🛡️' : '⛔️' ?></div>
        <h1>وضعیت اشتراک شما</h1>
        <div class="sub">آخرین بروزرسانی: <b id="updatedAtText"><?= htmlspecialchars((string)$updatedAt, ENT_QUOTES, 'UTF-8') ?></b></div>
        <div class="sub" style="margin-top:4px; opacity:.75;">⏳ به‌دلیل حفظ حریم‌خصوصی، این لینک حداکثر تا ۵ دقیقه بعد از آخرین استخراج/بروزرسانی در دسترسه و بعدش خودکار حذف می‌شه.</div>
    </div>

    <div class="status-badge <?= $isActive ? 'status-active' : 'status-inactive' ?>" id="statusBadge">
        <?= $isActive ? '✅ وضعیت اشتراک: فعال' : '⛔️ وضعیت اشتراک: منقضی شده' ?>
    </div>

    <div class="card">
        <div class="card-title">
            <span>📊 میزان حجم مصرفی</span>
            <span class="card-percent" id="volPercentText"><?= $hasVolumeLimit ? $usedPercent . '%' : 'نامحدود' ?></span>
        </div>
        <div class="bar-bg"><div class="bar-fill <?= $usedPercent >= 85 ? 'warn' : '' ?>" id="volBar" style="width:<?= $hasVolumeLimit ? $usedPercent : 3 ?>%"></div></div>
        <div class="card-foot">
            <span>مصرف شده: <b id="volUsedText"><?= fmtBytes($usedBytes) ?></b></span>
            <span>حجم کل: <b id="volTotalText"><?= $hasVolumeLimit ? fmtBytes($totalBytes) : 'نامحدود' ?></b></span>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>⏳ زمان باقی‌مانده اشتراک</span>
            <span class="card-percent" id="daysLeftText"><?= $hasExpiry ? $daysLeft . ' روز' : 'نامحدود' ?></span>
        </div>
        <?php if ($hasExpiry): ?>
        <div class="bar-bg"><div class="bar-fill <?= $daysLeft !== null && $daysLeft <= 3 ? 'warn' : '' ?>" id="timeBar" style="width:<?= $expiredByTime ? 0 : 100 ?>%"></div></div>
        <?php endif; ?>
        <div class="card-foot">
            <span>تعداد کانفیگ‌ها: <b id="configCountText"><?= (int)($row['total_configs'] ?? 0) ?></b></span>
            <span>انقضا: <b id="expireDateText"><?= $hasExpiry ? date('Y/m/d', $expireTs) : 'نامحدود' ?></b></span>
        </div>
    </div>

    <div class="card" id="protocolsCard" <?= empty($protocolCounts) ? 'style="display:none"' : '' ?>>
        <div class="card-title"><span>🔌 پروتکل‌های موجود</span></div>
        <div class="protocols" id="protoPills">
            <?php foreach ($protocolCounts as $proto => $count): ?>
                <span class="proto-pill" style="cursor:pointer;" onclick="filterByProtocol('<?= htmlspecialchars(addslashes($proto), ENT_QUOTES, 'UTF-8') ?>')"><?= htmlspecialchars($proto, ENT_QUOTES, 'UTF-8') ?>: <?= $count ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($subUrl !== ''): ?>
    <div class="card qr-wrap">
        <div class="card-title" style="justify-content:center; margin-bottom:14px;"><span>🔲 QR کد ساب‌اسکریپشن</span></div>
        <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR Code" loading="lazy">
        <div class="actions" style="margin-top:14px;">
            <a class="action-btn" href="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" style="text-decoration:none;">
                <span>⬇️ دانلود تصویر QR</span>
                <span class="copy-tag">باز کردن</span>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title"><span>📥 دریافت کانفیگ و اشتراک‌ها</span></div>
        <div class="actions">
            <button class="action-btn primary-action" id="refreshBtn" onclick="refreshData()">
                <span id="refreshBtnLabel">🔄 بروزرسانی زنده اطلاعات</span>
                <span class="copy-tag">به‌روز کن</span>
            </button>
            <?php if ($subUrl !== ''): ?>
            <button class="action-btn" onclick="copyText(this, SUB_URL)">
                <span>🔗 کپی لینک ساب‌اسکریپشن</span>
                <span class="copy-tag">کپی</span>
            </button>
            <?php endif; ?>
            <?php if ($firstVless): ?>
            <button class="action-btn" onclick="copyText(this, FIRST_VLESS)">
                <span>🚀 کپی کانفیگ VLESS (مستقیم)</span>
                <span class="copy-tag">کپی</span>
            </button>
            <?php endif; ?>
            <button class="action-btn" onclick="copyAllConfigs(this)">
                <span>📋 کپی همه‌ی کانفیگ‌ها</span>
                <span class="copy-tag">کپی</span>
            </button>
            <button class="action-btn" onclick="downloadAllConfigs()">
                <span>⬇️ دانلود فایل کانفیگ‌ها (txt)</span>
                <span class="copy-tag">دانلود</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>🗂 لیست کانفیگ‌ها</span>
            <span class="card-percent" id="configListCount">۰</span>
        </div>
        <input type="text" class="search-box" id="configSearch" placeholder="🔍 جستجو بر اساس نام یا پروتکل...">
        <div class="config-list" id="configList"></div>
    </div>

    <div class="footer">
        این صفحه به‌صورت خودکار از داخل ربات ساخته شده است — <b>team kan</b>
    </div>
</div>

<div class="toast" id="toast"></div>

<div class="qr-modal" id="qrModal" onclick="closeQrModal(event)">
    <div class="qr-modal-inner">
        <div class="qr-modal-title" id="qrModalTitle"></div>
        <img id="qrModalImg" src="" alt="QR Code">
        <button class="qr-modal-close" onclick="closeQrModal(event)">بستن ✕</button>
    </div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
const SUB_URL = <?= json_encode($subUrl, JSON_UNESCAPED_SLASHES) ?>;
const FIRST_VLESS = <?= json_encode($firstVless['raw'] ?? '', JSON_UNESCAPED_SLASHES) ?>;
let CONFIGS = <?= json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => t.classList.remove('show'), 2400);
}

function copyText(btn, text) {
    if (!text) { showToast('❌ چیزی برای کپی وجود ندارد.'); return; }
    navigator.clipboard.writeText(text).then(() => {
        const tag = btn.querySelector('.copy-tag');
        const original = tag.textContent;
        btn.classList.add('copied');
        tag.textContent = 'کپی شد ✓';
        setTimeout(() => { btn.classList.remove('copied'); tag.textContent = original; }, 1800);
    }).catch(() => showToast('❌ کپی ناموفق بود.'));
}

function copyAllConfigs(btn) {
    if (!CONFIGS.length) { showToast('❌ کانفیگی موجود نیست.'); return; }
    const text = CONFIGS.map(c => c.raw).join('\n');
    copyText(btn, text);
}

function downloadAllConfigs() {
    if (!CONFIGS.length) { showToast('❌ کانفیگی موجود نیست.'); return; }
    const text = CONFIGS.map(c => c.raw).join('\n');
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'configs_' + TOKEN + '.txt';
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    showToast('⬇️ فایل کانفیگ‌ها دانلود شد.');
}

function filterByProtocol(proto) {
    const box = document.getElementById('configSearch');
    box.value = proto;
    renderConfigList(proto);
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function showConfigQR(raw, name) {
    if (!raw) { showToast('❌ چیزی برای نمایش QR نیست.'); return; }
    document.getElementById('qrModalTitle').textContent = name || 'Config';
    document.getElementById('qrModalImg').src =
        'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' + encodeURIComponent(raw) + '&bgcolor=255-255-255&color=0-0-0&margin=1';
    document.getElementById('qrModal').classList.add('show');
}

function closeQrModal(e) {
    if (e.target.id === 'qrModal' || e.target.classList.contains('qr-modal-close')) {
        document.getElementById('qrModal').classList.remove('show');
    }
}

function decodeBase64Safe(str) {
    try {
        str = str.replace(/-/g, '+').replace(/_/g, '/');
        while (str.length % 4) str += '=';
        return decodeURIComponent(escape(atob(str)));
    } catch (e) { return ''; }
}

function extractHostPort(raw) {
    if (!raw) return { host: '', port: '' };
    try {
        const schemeMatch = raw.match(/^([a-z0-9]+):\/\//i);
        const scheme = schemeMatch ? schemeMatch[1].toLowerCase() : '';

        // vmess:// base64(json) with { add, port }
        if (scheme === 'vmess') {
            const json = JSON.parse(decodeBase64Safe(raw.slice(8).split('#')[0].split('?')[0]));
            return { host: json.add || '', port: String(json.port || '') };
        }

        // ssr:// base64(host:port:protocol:method:obfs:base64pass/?params)
        if (scheme === 'ssr') {
            const body = decodeBase64Safe(raw.slice(6).split('#')[0]);
            const m = body.match(/^([^:]+):(\d+):/);
            if (m) return { host: m[1], port: m[2] };
        }

        // ss:// two known shapes: ss://method:pass@host:port  OR  ss://BASE64(method:pass@host:port)
        if (scheme === 'ss') {
            const rest = raw.slice(5).split('#')[0];
            let m = rest.match(/@([^:\/?#]+):(\d+)/);
            if (m) return { host: m[1], port: m[2] };
            const decoded = decodeBase64Safe(rest.split('?')[0]);
            m = decoded.match(/@([^:\/?#]+):(\d+)/);
            if (m) return { host: m[1], port: m[2] };
        }

        // generic form used by vless, trojan, hysteria, hysteria2/hy2, tuic, socks, http(s)
        // scheme://[userinfo@]host[:port][/path][?query][#fragment]
        const m = raw.match(/^[a-z0-9]+:\/\/(?:[^@\/?#]*@)?(\[[^\]]+\]|[^:\/?#]+)(?::(\d+))?/i);
        if (m) {
            const host = m[1].replace(/^\[|\]$/g, ''); // strip IPv6 brackets
            return { host, port: m[2] || '' };
        }
    } catch (e) {}
    return { host: '', port: '' };
}

function codeToFlag(code) {
    return code.toUpperCase().replace(/./g, ch => String.fromCodePoint(127397 + ch.charCodeAt(0)));
}

const COUNTRY_MAP = {
    'germany':'DE','frankfurt':'DE','deutschland':'DE','france':'FR','paris':'FR',
    'usa':'US','united states':'US','america':'US','uk':'GB','england':'GB','london':'GB','britain':'GB',
    'netherlands':'NL','amsterdam':'NL','holland':'NL','turkey':'TR','istanbul':'TR','türkiye':'TR',
    'finland':'FI','poland':'PL','russia':'RU','moscow':'RU','iran':'IR','tehran':'IR',
    'uae':'AE','dubai':'AE','emirates':'AE','singapore':'SG','japan':'JP','tokyo':'JP','canada':'CA',
    'sweden':'SE','switzerland':'CH','italy':'IT','spain':'ES','south korea':'KR','korea':'KR',
    'india':'IN','brazil':'BR','austria':'AT','belgium':'BE','romania':'RO','ukraine':'UA',
    'hongkong':'HK','hong kong':'HK','australia':'AU','denmark':'DK','norway':'NO','czech':'CZ',
    'ireland':'IE','portugal':'PT','greece':'GR','bulgaria':'BG','lithuania':'LT','latvia':'LV',
    'estonia':'EE','kazakhstan':'KZ','malaysia':'MY','thailand':'TH','vietnam':'VN','indonesia':'ID',
    'mexico':'MX','argentina':'AR','south africa':'ZA','egypt':'EG','israel':'IL','saudi':'SA'
};

function guessFlag(text) {
    if (!text) return '';
    const flagMatch = text.match(/[\u{1F1E6}-\u{1F1FF}]{2}/u);
    if (flagMatch) return flagMatch[0];
    const lower = text.toLowerCase();
    for (const key in COUNTRY_MAP) {
        if (lower.includes(key)) return codeToFlag(COUNTRY_MAP[key]);
    }
    return '🌐';
}

function renderConfigList(filterText) {
    const container = document.getElementById('configList');
    const q = (filterText || '').trim().toLowerCase();

    const filtered = CONFIGS.filter(c => {
        if (!q) return true;
        return (c.name || '').toLowerCase().includes(q) || (c.protocol || '').toLowerCase().includes(q);
    });

    document.getElementById('configListCount').textContent = filtered.length + ' از ' + CONFIGS.length;

    if (filtered.length === 0) {
        container.innerHTML = '<div class="config-empty">کانفیگی با این مشخصات یافت نشد.</div>';
        return;
    }

    const fragment = document.createDocumentFragment();

    filtered.forEach(c => {
        const item = document.createElement('div');
        item.className = 'config-item';

        const { host, port } = extractHostPort(c.raw || '');
        const address = host ? (host + (port ? ':' + port : '')) : 'آدرس نامشخص';

        const flagEl = document.createElement('span');
        flagEl.className = 'ci-flag';
        flagEl.textContent = guessFlag((c.name || '') + ' ' + host);

        const info = document.createElement('div');
        info.className = 'ci-info';
        const nameEl = document.createElement('div');
        nameEl.className = 'ci-name';
        nameEl.dir = 'ltr';
        nameEl.textContent = c.name || 'Config';
        const protoEl = document.createElement('div');
        protoEl.className = 'ci-proto';
        protoEl.dir = 'ltr';
        const protoLabel = document.createElement('b');
        protoLabel.textContent = (c.protocol || '').toUpperCase();
        protoEl.appendChild(protoLabel);
        protoEl.appendChild(document.createTextNode(' • ' + address));
        info.appendChild(nameEl);
        info.appendChild(protoEl);

        const actions = document.createElement('div');
        actions.className = 'ci-actions';

        const copyBtn = document.createElement('button');
        copyBtn.className = 'ci-btn ci-copy';
        copyBtn.textContent = 'کپی';
        copyBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            navigator.clipboard.writeText(c.raw || '').then(() => {
                copyBtn.classList.add('copied');
                const original = copyBtn.textContent;
                copyBtn.textContent = 'کپی شد ✓';
                setTimeout(() => { copyBtn.classList.remove('copied'); copyBtn.textContent = original; }, 1500);
            }).catch(() => showToast('❌ کپی ناموفق بود.'));
        });

        const qrBtn = document.createElement('button');
        qrBtn.className = 'ci-btn ci-qr';
        qrBtn.textContent = '🔳 QR';
        qrBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showConfigQR(c.raw || '', c.name || c.protocol || 'Config');
        });

        actions.appendChild(copyBtn);
        actions.appendChild(qrBtn);

        item.appendChild(flagEl);
        item.appendChild(info);
        item.appendChild(actions);

        fragment.appendChild(item);
    });

    container.innerHTML = '';
    container.appendChild(fragment);
}
renderConfigList('');

document.getElementById('configSearch').addEventListener('input', e => renderConfigList(e.target.value));

function fmtBytesJs(bytes) {
    bytes = parseFloat(bytes) || 0;
    if (bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let pow = Math.floor(Math.log(bytes) / Math.log(1024));
    pow = Math.min(pow, units.length - 1);
    return (bytes / Math.pow(1024, pow)).toFixed(2) + ' ' + units[pow];
}

async function refreshData() {
    const btn = document.getElementById('refreshBtn');
    const label = document.getElementById('refreshBtnLabel');
    if (btn.disabled) return;
    btn.disabled = true;
    const originalLabel = label.textContent;
    label.innerHTML = '<span class="spin"></span> در حال بروزرسانی...';

    try {
        const res = await fetch('sub_refresh.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: TOKEN })
        });
        const data = await res.json();

        if (!data.ok) {
            showToast('❌ ' + (data.error || 'بروزرسانی ناموفق بود.'));
            return;
        }

        CONFIGS = data.configs || [];
        renderConfigList(document.getElementById('configSearch').value);

        const totalBytes = parseFloat(data.total_bytes) || 0;
        const usedBytes   = parseFloat(data.used_bytes) || 0;
        const hasVolume   = totalBytes > 0;
        const remainBytes = Math.max(0, totalBytes - usedBytes);
        const usedPercent = hasVolume ? Math.min(100, Math.round((usedBytes / totalBytes) * 100)) : 0;

        document.getElementById('volPercentText').textContent = hasVolume ? usedPercent + '%' : 'نامحدود';
        document.getElementById('volUsedText').textContent = fmtBytesJs(usedBytes);
        document.getElementById('volTotalText').textContent = hasVolume ? fmtBytesJs(totalBytes) : 'نامحدود';
        const volBar = document.getElementById('volBar');
        volBar.style.width = (hasVolume ? usedPercent : 3) + '%';
        volBar.classList.toggle('warn', usedPercent >= 85);

        const expireTs = parseInt(data.expire_ts, 10) || 0;
        const hasExpiry = expireTs > 0;
        const now = Math.floor(Date.now() / 1000);
        const daysLeft = hasExpiry ? Math.max(0, Math.ceil((expireTs - now) / 86400)) : null;
        document.getElementById('daysLeftText').textContent = hasExpiry ? daysLeft + ' روز' : 'نامحدود';
        document.getElementById('expireDateText').textContent = hasExpiry ? new Date(expireTs * 1000).toLocaleDateString('fa-IR') : 'نامحدود';
        const timeBar = document.getElementById('timeBar');
        if (timeBar) {
            const expiredByTime = hasExpiry && expireTs <= now;
            timeBar.style.width = (expiredByTime ? 0 : 100) + '%';
            timeBar.classList.toggle('warn', daysLeft !== null && daysLeft <= 3);
        }

        document.getElementById('configCountText').textContent = data.total_configs;

        const expiredByVolume = hasVolume && remainBytes <= 0;
        const expiredByTime = hasExpiry && expireTs <= now;
        const isActive = !expiredByTime && !expiredByVolume;
        const badge = document.getElementById('statusBadge');
        badge.className = 'status-badge ' + (isActive ? 'status-active' : 'status-inactive');
        badge.textContent = isActive ? '✅ وضعیت اشتراک: فعال' : '⛔️ وضعیت اشتراک: منقضی شده';
        document.getElementById('headIcon').textContent = isActive ? '🛡️' : '⛔️';

        const protoPills = document.getElementById('protoPills');
        const protoCard = document.getElementById('protocolsCard');
        protoPills.innerHTML = '';
        const protoEntries = Object.entries(data.protocols || {}).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
        protoCard.style.display = protoEntries.length ? '' : 'none';
        protoEntries.forEach(([proto, count]) => {
            const pill = document.createElement('span');
            pill.className = 'proto-pill';
            pill.textContent = proto.toUpperCase() + ': ' + count;
            protoPills.appendChild(pill);
        });

        document.getElementById('updatedAtText').textContent = data.updated_at;

        showToast('✅ اطلاعات با موفقیت بروزرسانی شد.');
    } catch (e) {
        showToast('❌ خطای ارتباط با سرور.');
    } finally {
        btn.disabled = false;
        label.textContent = originalLabel;
    }
}
</script>
</body>
</html>
<?php elseif ($subViewModel === "terminal"): ?>
<!doctype html>
<html lang="fa" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>وضعیت اشتراک شما — Team Kan</title>
<link rel="preload" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&family=Vazirmatn:wght@400;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet"></noscript>
<style>
  :root{ --radius:10px; --font-mono:'JetBrains Mono', monospace; --font-fa:'Vazirmatn', Tahoma, sans-serif; }

  body[data-theme="green"]{ --term-bg:#020603; --term-panel:#050f07; --term-fg:#4fff7a; --term-dim:#2f8a49; --term-accent:#00ff66; --term-danger:#ff5f56; --term-border:#1a3d24; }
  body[data-theme="amber"]{ --term-bg:#0a0602; --term-panel:#150d05; --term-fg:#ffb000; --term-dim:#a06b0a; --term-accent:#ffcc33; --term-danger:#ff5f56; --term-border:#3d2a0f; }
  body[data-theme="blue"]{ --term-bg:#01040a; --term-panel:#050c1c; --term-fg:#5ac8ff; --term-dim:#2f6ea3; --term-accent:#7fdbff; --term-danger:#ff5f56; --term-border:#0f2a4a; }
  body[data-theme="red"]{ --term-bg:#0a0202; --term-panel:#170505; --term-fg:#ff5f5f; --term-dim:#a33a3a; --term-accent:#ff8080; --term-danger:#ffb000; --term-border:#3d1010; }
  body[data-theme="white"]{ --term-bg:#f4f4f0; --term-panel:#ffffff; --term-fg:#1a1a18; --term-dim:#6b6b64; --term-accent:#0a7a3d; --term-danger:#c0392b; --term-border:#d8d8d0; }
  body[data-theme="cyan"]{ --term-bg:#020a0a; --term-panel:#041414; --term-fg:#4dfff2; --term-dim:#2a8a82; --term-accent:#00fff2; --term-danger:#ff5f56; --term-border:#0f3d3a; }

  *{ box-sizing:border-box; margin:0; padding:0; }
  html,body{ height:100%; }
  body{
    background:var(--term-bg); color:var(--term-fg); font-family:var(--font-mono);
    min-height:100vh; padding:22px 14px 50px; position:relative; overflow-x:hidden;
    transition:background .3s ease, color .3s ease;
  }
  .fa{ font-family:var(--font-fa); }

  canvas#matrixCanvas{ position:fixed; inset:0; z-index:0; opacity:.16; pointer-events:none; }

  .crt-overlay{
    position:fixed; inset:0; z-index:5; pointer-events:none;
    background:repeating-linear-gradient(0deg, rgba(0,0,0,0) 0px, rgba(0,0,0,0) 2px, rgba(0,0,0,.12) 3px, rgba(0,0,0,0) 4px);
  }
  .crt-vignette{ position:fixed; inset:0; z-index:5; pointer-events:none; box-shadow:inset 0 0 140px rgba(0,0,0,.55); }

  .wrap{ max-width:760px; margin:0 auto; position:relative; z-index:1; }

  .term-window{
    background:var(--term-panel); border:1px solid var(--term-border); border-radius:var(--radius);
    box-shadow:0 0 30px color-mix(in srgb, var(--term-fg) 12%, transparent), 0 12px 40px rgba(0,0,0,.5);
    overflow:hidden; margin-bottom:16px;
  }
  .term-titlebar{
    display:flex; align-items:center; gap:8px; padding:10px 14px; background:color-mix(in srgb, var(--term-fg) 6%, var(--term-panel));
    border-bottom:1px solid var(--term-border);
  }
  .term-dot{ width:11px; height:11px; border-radius:50%; }
  .term-dot.red{ background:#ff5f56; } .term-dot.yellow{ background:#ffbd2e; } .term-dot.green{ background:#27c93f; }
  .term-title{ flex:1; text-align:center; font-size:11.5px; color:var(--term-dim); letter-spacing:.5px; }
  .term-body{ padding:18px 20px; }

  .prompt{ color:var(--term-dim); }
  .prompt .path{ color:var(--term-accent); }
  .cursor{ display:inline-block; width:8px; height:15px; background:var(--term-fg); vertical-align:middle; animation:blink 1s step-end infinite; margin-inline-start:2px; }
  @keyframes blink{ 0%,49%{ opacity:1; } 50%,100%{ opacity:0; } }

  .glitch-line{ position:relative; }
  @keyframes glitchFlicker{ 0%,94%,100%{ opacity:1; transform:translateX(0); } 95%{ opacity:.7; transform:translateX(-1px); } 96%{ opacity:1; transform:translateX(1px); } 97%{ opacity:.85; } }
  .glitch-line{ animation:glitchFlicker 6s ease-in-out infinite; }

  h1.term-h{
    font-size:clamp(18px, 4vw, 24px); font-weight:800; margin:10px 0 4px; color:var(--term-fg);
    text-shadow:0 0 12px color-mix(in srgb, var(--term-fg) 60%, transparent);
  }
  .term-sub{ color:var(--term-dim); font-size:12px; margin-bottom:16px; }

  .kv-line{ display:flex; justify-content:space-between; gap:10px; padding:7px 0; border-bottom:1px dashed var(--term-border); font-size:12.5px; }
  .kv-line:last-child{ border-bottom:none; }
  .kv-key{ color:var(--term-dim); }
  .kv-val{ color:var(--term-fg); font-weight:700; }
  .kv-val.danger{ color:var(--term-danger); }

  .ascii-bar-line{ font-size:12.5px; margin-top:4px; letter-spacing:1px; }
  .ascii-bar-line .fill{ color:var(--term-accent); }
  .ascii-bar-line .empty{ color:var(--term-border); }

  .status-line{ display:inline-flex; align-items:center; gap:7px; font-size:12.5px; padding:6px 12px; border:1px solid var(--term-border); border-radius:6px; margin-bottom:14px; }
  .status-line .dot{ width:7px; height:7px; border-radius:50%; background:var(--term-accent); box-shadow:0 0 8px var(--term-accent); animation:blink 1.6s ease-in-out infinite; }
  .status-line.down .dot{ background:var(--term-danger); box-shadow:0 0 8px var(--term-danger); }
  .status-line.down{ color:var(--term-danger); border-color:color-mix(in srgb, var(--term-danger) 40%, transparent); }

  .btn-term{
    font-family:var(--font-mono); font-size:12px; font-weight:700; color:var(--term-fg);
    background:transparent; border:1px solid var(--term-fg); border-radius:6px; padding:9px 16px;
    cursor:pointer; transition:.15s ease; white-space:nowrap;
  }
  .btn-term:hover{ background:color-mix(in srgb, var(--term-fg) 12%, transparent); }
  .btn-term.solid{ background:var(--term-fg); color:var(--term-bg); }
  .btn-term.solid:hover{ opacity:.85; }
  .btn-row{ display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }

  .link-line{
    font-size:11.5px; direction:ltr; text-align:left; word-break:break-all; color:var(--term-accent);
    background:color-mix(in srgb, var(--term-fg) 5%, transparent); border:1px solid var(--term-border);
    border-radius:6px; padding:10px 12px; margin:10px 0;
  }

  .theme-picker{ display:flex; gap:6px; flex-wrap:wrap; }
  .theme-dot{ width:20px; height:20px; border-radius:4px; cursor:pointer; border:1px solid var(--term-border); }
  .theme-dot.active{ box-shadow:0 0 0 2px var(--term-fg); }
  .td-green{ background:#00ff66; } .td-amber{ background:#ffb000; } .td-blue{ background:#5ac8ff; }
  .td-red{ background:#ff5f5f; } .td-white{ background:#f4f4f0; } .td-cyan{ background:#00fff2; }

  .qr-term{ background:#fff; border-radius:6px; padding:6px; display:inline-block; }
  .qr-term img{ display:block; width:110px; height:110px; }

  .search-term{
    width:100%; padding:9px 12px; border-radius:6px; border:1px solid var(--term-border);
    background:transparent; color:var(--term-fg); font-family:var(--font-mono); font-size:12.5px; margin-bottom:10px;
  }
  .search-term::placeholder{ color:var(--term-dim); }

  .file-row{
    display:flex; align-items:center; gap:8px; padding:7px 4px; font-size:12px; border-bottom:1px solid color-mix(in srgb, var(--term-border) 60%, transparent);
    flex-wrap:wrap;
  }
  .file-row:hover{ background:color-mix(in srgb, var(--term-fg) 4%, transparent); }
  .file-perm{ color:var(--term-dim); flex-shrink:0; }
  .file-name{ flex:1; min-width:120px; color:var(--term-fg); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; direction:ltr; text-align:left; }
  .file-proto{ color:var(--term-dim); flex-shrink:0; }
  .file-actions{ display:flex; gap:5px; flex-shrink:0; }
  .file-actions button{ font-family:var(--font-mono); font-size:10px; color:var(--term-fg); background:transparent; border:1px solid var(--term-border); border-radius:4px; padding:3px 7px; cursor:pointer; }
  .file-actions button:hover{ border-color:var(--term-fg); }
  .config-list-term{ max-height:360px; overflow-y:auto; }
  .empty-term{ color:var(--term-dim); font-size:12px; padding:14px 0; }

  footer.term-footer{ text-align:center; color:var(--term-dim); font-size:10.5px; margin-top:20px; }

  .toast-term{
    position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(15px); z-index:20;
    background:var(--term-panel); border:1px solid var(--term-fg); color:var(--term-fg); font-family:var(--font-mono);
    font-size:11.5px; padding:9px 16px; border-radius:6px; opacity:0; pointer-events:none; transition:.25s ease;
  }
  .toast-term.show{ opacity:1; transform:translateX(-50%) translateY(0); }

  .qr-modal-term{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.8); z-index:30; align-items:center; justify-content:center; padding:20px; }
  .qr-modal-term.show{ display:flex; }
  .qr-modal-term-inner{ background:var(--term-panel); border:1px solid var(--term-border); border-radius:var(--radius); padding:22px; text-align:center; max-width:300px; width:100%; }
  .qr-modal-term-inner img{ width:200px; height:200px; border-radius:6px; background:#fff; padding:8px; }
  .qr-modal-term-title{ font-size:12px; margin-bottom:14px; color:var(--term-fg); word-break:break-all; }

  @media (max-width:520px){ .term-body{ padding:14px 16px; } }
</style>
</head>
<body data-theme="<?= htmlspecialchars($subViewTheme, ENT_QUOTES, 'UTF-8') ?>">

<canvas id="matrixCanvas"></canvas>
<div class="crt-overlay"></div>
<div class="crt-vignette"></div>

<div class="wrap">
  <div class="term-window">
    <div class="term-titlebar">
      <div class="term-dot red"></div><div class="term-dot yellow"></div><div class="term-dot green"></div>
      <div class="term-title">Team Kan — Subscription Monitor</div>
      <div class="theme-picker" id="termThemePicker">
        <div class="theme-dot td-green" data-theme="green" title="green"></div>
        <div class="theme-dot td-amber" data-theme="amber" title="amber"></div>
        <div class="theme-dot td-blue" data-theme="blue" title="blue"></div>
        <div class="theme-dot td-red" data-theme="red" title="red"></div>
        <div class="theme-dot td-white" data-theme="white" title="white"></div>
        <div class="theme-dot td-cyan" data-theme="cyan" title="cyan"></div>
      </div>
    </div>
    <div class="term-body">
      <div class="prompt glitch-line">$ <span class="path">~/sub</span> status --check<span class="cursor"></span></div>
      <h1 class="term-h fa">وضعیت اشتراک شما</h1>
      <div class="term-sub fa">آخرین بروزرسانی: <span id="updatedAtText"><?= htmlspecialchars((string)$updatedAt, ENT_QUOTES, 'UTF-8') ?></span></div>

      <div class="status-line <?= $isActive ? '' : 'down' ?>" id="statusLine">
        <span class="dot"></span><span class="fa"><?= $isActive ? 'اشتراک فعال است' : 'اشتراک منقضی شده' ?></span>
      </div>

      <div class="kv-line">
        <span class="kv-key fa">حجم مصرفی</span>
        <span class="kv-val" id="volText"><?= $hasVolumeLimit ? (fmtBytes($usedBytes) . ' / ' . fmtBytes($totalBytes)) : 'unlimited' ?></span>
      </div>
      <div class="ascii-bar-line" id="volAsciiLine"></div>
      <div class="kv-line">
        <span class="kv-key fa">روزهای باقیمانده</span>
        <span class="kv-val" id="daysLeftText"><?= $hasExpiry ? $daysLeft . ' day(s)' : 'unlimited' ?></span>
      </div>
      <div class="kv-line">
        <span class="kv-key fa">تعداد کانفیگ‌ها</span>
        <span class="kv-val" id="configCountText"><?= (int)($row['total_configs'] ?? 0) ?></span>
      </div>

      <?php if ($subUrl !== ''): ?>
      <div class="prompt" style="margin-top:16px;">$ <span class="path">~/sub</span> cat subscription.link</div>
      <div class="link-line" id="subLink"><?= htmlspecialchars($subUrl, ENT_QUOTES, 'UTF-8') ?></div>
      <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
        <div class="qr-term"><img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR" loading="lazy"></div>
        <div class="btn-row" style="margin-top:0;">
          <button class="btn-term solid" onclick="copyLink()">[ COPY LINK ]</button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="term-window">
    <div class="term-titlebar">
      <div class="term-dot red"></div><div class="term-dot yellow"></div><div class="term-dot green"></div>
      <div class="term-title">~/sub/configs.list</div>
    </div>
    <div class="term-body">
      <div class="prompt">$ <span class="path">~/sub</span> ls -la ./configs</div>
      <input type="text" class="search-term" id="configSearch" placeholder="grep --name-or-protocol ...">
      <div class="config-list-term" id="configList"></div>
      <div class="btn-row">
        <button class="btn-term" onclick="copyAllConfigs()">[ COPY ALL ]</button>
        <button class="btn-term" onclick="downloadAllConfigs()">[ DOWNLOAD .txt ]</button>
        <button class="btn-term" id="refreshBtn" onclick="refreshData()"><span id="refreshBtnLabel">[ REFRESH ]</span></button>
      </div>
    </div>
  </div>

  <footer class="term-footer fa">این صفحه به‌صورت خودکار از داخل ربات ساخته شده است — Team Kan · exit code 0</footer>
</div>

<div class="toast-term" id="toast"></div>

<div class="qr-modal-term" id="qrModal" onclick="closeQrModal(event)">
  <div class="qr-modal-term-inner">
    <div class="qr-modal-term-title" id="qrModalTitle"></div>
    <img id="qrModalImg" src="" alt="QR Code">
    <button class="btn-term" style="margin-top:14px; width:100%;" onclick="closeQrModal(event)">[ CLOSE ]</button>
  </div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
const SUB_URL = <?= json_encode($subUrl, JSON_UNESCAPED_SLASHES) ?>;
let CONFIGS = <?= json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_VOL_PERCENT = <?= $hasVolumeLimit ? (int)$usedPercent : 0 ?>;
const HAS_VOLUME = <?= $hasVolumeLimit ? 'true' : 'false' ?>;

// ---------------- تم ----------------
const tDots = document.querySelectorAll('#termThemePicker .theme-dot');
function setActiveTDot(theme){ tDots.forEach(d => d.classList.toggle('active', d.dataset.theme === theme)); }
setActiveTDot(document.body.getAttribute('data-theme') || 'green');
tDots.forEach(dot=>{
  dot.addEventListener('click', ()=>{
    setActiveTDot(dot.dataset.theme);
    document.body.setAttribute('data-theme', dot.dataset.theme);
  });
});

// ---------------- toast / کپی ----------------
function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(window._tt);
  window._tt = setTimeout(()=> t.classList.remove('show'), 2200);
}
function fallbackCopy(text){
  try {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch(e){ return false; }
}
function copyText(text, msg){
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(()=>showToast(msg)).catch(()=>showToast(fallbackCopy(text) ? msg : '$ error: copy failed'));
  } else {
    showToast(fallbackCopy(text) ? msg : '$ error: copy failed');
  }
}
function copyLink(){ copyText(SUB_URL, '$ ok: link copied'); }

// ---------------- نوار اسکی حجم ----------------
function renderAsciiBar(percent){
  const el = document.getElementById('volAsciiLine');
  const total = 26;
  const filled = Math.round((percent / 100) * total);
  el.innerHTML = '[<span class="fill">' + '#'.repeat(filled) + '</span><span class="empty">' + '-'.repeat(total - filled) + '</span>] ' + (HAS_VOLUME ? percent + '%' : '');
}
renderAsciiBar(INITIAL_VOL_PERCENT);

// ---------------- کمکی: هاست/پرچم ----------------
function decodeBase64Safe(str) {
  try { str = str.replace(/-/g,'+').replace(/_/g,'/'); while (str.length % 4) str += '='; return atob(str); }
  catch(e){ return ''; }
}
function extractHostPort(raw) {
  if (!raw) return { host:'', port:'' };
  const sm = raw.match(/^([a-z0-9]+):\/\//i);
  if (!sm) return { host:'', port:'' };
  const scheme = sm[1].toLowerCase();
  try {
    if (scheme === 'vmess') {
      let body = raw.slice(8).split('#')[0].split('?')[0];
      const json = JSON.parse(decodeBase64Safe(body));
      return { host: json.add || '', port: String(json.port || '') };
    }
    if (scheme === 'ss') {
      let rest = raw.slice(5).split('#')[0];
      let m = rest.match(/@([^:/?#]+):(\d+)/);
      if (m) return { host:m[1], port:m[2] };
      const decoded = decodeBase64Safe(rest.split('?')[0]);
      m = decoded.match(/@([^:/?#]+):(\d+)/);
      if (m) return { host:m[1], port:m[2] };
      return { host:'', port:'' };
    }
    const m = raw.match(/^[a-z0-9]+:\/\/(?:[^@/?#]*@)?(\[[^\]]+\]|[^:/?#]+)(?::(\d+))?/i);
    if (m) return { host:m[1].replace(/[[\]]/g,''), port:m[2] || '' };
  } catch(e){}
  return { host:'', port:'' };
}

// ---------------- لیست کانفیگ‌ها (سبک ls -la) ----------------
function renderConfigList(filterText){
  const container = document.getElementById('configList');
  const q = (filterText || '').trim().toLowerCase();
  const filtered = CONFIGS.filter(c => !q || (c.name||'').toLowerCase().includes(q) || (c.protocol||'').toLowerCase().includes(q));

  if (filtered.length === 0) {
    container.innerHTML = '<div class="empty-term">$ ls: no matches found</div>';
    return;
  }

  const fragment = document.createDocumentFragment();
  filtered.forEach(c => {
    const { host, port } = extractHostPort(c.raw || '');
    const address = host ? (host + (port ? ':' + port : '')) : 'unknown';

    const row = document.createElement('div');
    row.className = 'file-row';

    const perm = document.createElement('span');
    perm.className = 'file-perm'; perm.textContent = '-rw-r--r--';

    const name = document.createElement('span');
    name.className = 'file-name'; name.textContent = c.name || 'config';

    const proto = document.createElement('span');
    proto.className = 'file-proto'; proto.textContent = (c.protocol||'').toUpperCase() + ' @ ' + address;

    const actions = document.createElement('div');
    actions.className = 'file-actions';

    const copyBtn = document.createElement('button');
    copyBtn.textContent = 'cp';
    copyBtn.addEventListener('click', () => {
      navigator.clipboard.writeText(c.raw || '').then(() => showToast('$ ok: copied ' + (c.name || 'config')))
        .catch(() => showToast('$ error: copy failed'));
    });
    const qrBtn = document.createElement('button');
    qrBtn.textContent = 'qr';
    qrBtn.addEventListener('click', () => showConfigQR(c.raw || '', c.name || c.protocol || 'config'));

    actions.appendChild(copyBtn); actions.appendChild(qrBtn);
    row.appendChild(perm); row.appendChild(name); row.appendChild(proto); row.appendChild(actions);
    fragment.appendChild(row);
  });
  container.innerHTML = '';
  container.appendChild(fragment);
}
renderConfigList('');
document.getElementById('configSearch').addEventListener('input', e => renderConfigList(e.target.value));

function copyAllConfigs(){
  if (CONFIGS.length === 0) { showToast('$ error: no configs'); return; }
  copyText(CONFIGS.map(c => c.raw || '').filter(Boolean).join('\n'), '$ ok: ' + CONFIGS.length + ' configs copied');
}
function downloadAllConfigs(){
  if (CONFIGS.length === 0) { showToast('$ error: no configs'); return; }
  const text = CONFIGS.map(c => c.raw || '').filter(Boolean).join('\n');
  const blob = new Blob([text], { type:'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = 'configs.txt';
  document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  showToast('$ ok: file downloaded');
}
function showConfigQR(raw, name){
  if (!raw) { showToast('$ error: nothing to encode'); return; }
  document.getElementById('qrModalTitle').textContent = name || 'config';
  document.getElementById('qrModalImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' + encodeURIComponent(raw) + '&bgcolor=255-255-255&color=0-0-0&margin=1';
  document.getElementById('qrModal').classList.add('show');
}
function closeQrModal(e){
  if (e.target.id === 'qrModal' || e.target.textContent === '[ CLOSE ]') document.getElementById('qrModal').classList.remove('show');
}

// ---------------- بروزرسانی زنده ----------------
function fmtBytesJs(bytes){
  bytes = parseFloat(bytes) || 0;
  if (bytes <= 0) return '0 B';
  const units = ['B','KB','MB','GB','TB'];
  let pow = Math.floor(Math.log(bytes) / Math.log(1024));
  pow = Math.min(pow, units.length - 1);
  return (bytes / Math.pow(1024, pow)).toFixed(2) + ' ' + units[pow];
}
async function refreshData(){
  const label = document.getElementById('refreshBtnLabel');
  const original = label.textContent;
  label.textContent = '[ SYNCING... ]';
  try {
    const res = await fetch('sub_refresh.php', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: TOKEN })
    });
    const data = await res.json();
    if (!data.ok) { showToast('$ error: ' + (data.error || 'sync failed')); label.textContent = original; return; }

    CONFIGS = data.configs || [];
    renderConfigList(document.getElementById('configSearch').value);

    const totalBytes = parseFloat(data.total_bytes) || 0;
    const usedBytes = parseFloat(data.used_bytes) || 0;
    const hasVolume = totalBytes > 0;
    const usedPercent = hasVolume ? Math.min(100, Math.round((usedBytes / totalBytes) * 100)) : 0;
    document.getElementById('volText').textContent = hasVolume ? (fmtBytesJs(usedBytes) + ' / ' + fmtBytesJs(totalBytes)) : 'unlimited';
    renderAsciiBar(hasVolume ? usedPercent : 0);

    const expireTs = parseInt(data.expire_ts, 10) || 0;
    const hasExpiry = expireTs > 0;
    const now = Math.floor(Date.now()/1000);
    const daysLeft = hasExpiry ? Math.max(0, Math.ceil((expireTs - now) / 86400)) : null;
    document.getElementById('daysLeftText').textContent = hasExpiry ? daysLeft + ' day(s)' : 'unlimited';
    document.getElementById('configCountText').textContent = data.total_configs;

    const expiredByTime = hasExpiry && expireTs <= now;
    const expiredByVolume = hasVolume && usedBytes >= totalBytes;
    const isActive = !expiredByTime && !expiredByVolume;
    const line = document.getElementById('statusLine');
    line.classList.toggle('down', !isActive);
    line.querySelector('span.fa').textContent = isActive ? 'اشتراک فعال است' : 'اشتراک منقضی شده';

    if (data.updated_at) document.getElementById('updatedAtText').textContent = data.updated_at;
    showToast('$ ok: sync complete');
  } catch(e) {
    showToast('$ error: connection failed');
  } finally {
    label.textContent = original;
  }
}

// ---------------- بارون ماتریکسی ----------------
(function initMatrixRain(){
  const canvas = document.getElementById('matrixCanvas');
  const ctx = canvas.getContext('2d');
  let w, h, columns, drops;
  const chars = 'ｱｲｳｴｵｶｷｸｹｺサシスセソタチツテト0123456789ABCDEF';
  function resize(){
    w = canvas.width = window.innerWidth;
    h = canvas.height = window.innerHeight;
    columns = Math.floor(w / 16);
    drops = new Array(columns).fill(0).map(() => Math.random() * -50);
  }
  resize();
  window.addEventListener('resize', resize);
  function getColor(){
    const style = getComputedStyle(document.body);
    return style.getPropertyValue('--term-fg').trim() || '#4fff7a';
  }
  function draw(){
    ctx.fillStyle = 'rgba(0,0,0,0.06)';
    ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = getColor();
    ctx.font = '14px monospace';
    for (let i = 0; i < columns; i++){
      const text = chars[Math.floor(Math.random() * chars.length)];
      ctx.fillText(text, i * 16, drops[i] * 16);
      if (drops[i] * 16 > h && Math.random() > 0.975) drops[i] = 0;
      drops[i]++;
    }
    requestAnimationFrame(draw);
  }
  draw();
})();
</script>
</body>
</html>
<?php elseif ($subViewModel === "elite"): ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>وضعیت اشتراک شما — Team Kan</title>
<link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css"></noscript>
<style>
  :root{ --radius:2px; --radius-lg:4px; --font:'Vazirmatn', Tahoma, sans-serif; }

  body[data-theme="gold"]{ --bg:#0a0906; --panel:#111008; --line:#3a3320; --accent:#c9a876; --accent2:#e8cf9a; --text:#f2ede0; --muted:#8a8270; }
  body[data-theme="platinum"]{ --bg:#0a0a0b; --panel:#111113; --line:#33353a; --accent:#c7ccd4; --accent2:#eef1f5; --text:#eef0f2; --muted:#83898f; }
  body[data-theme="rosegold"]{ --bg:#0c0807; --panel:#140f0d; --line:#3d2a24; --accent:#d9a894; --accent2:#f0c9b8; --text:#f3e9e4; --muted:#8f7168; }
  body[data-theme="emerald"]{ --bg:#070b09; --panel:#0d1310; --line:#233a2c; --accent:#a8c9a8; --accent2:#c9e8c9; --text:#e9f3ea; --muted:#6f8a75; }
  body[data-theme="sapphire"]{ --bg:#070a0e; --panel:#0d131b; --line:#233047; --accent:#a8bdd9; --accent2:#c9dbf0; --text:#e9eef3; --muted:#6f7f8f; }
  body[data-theme="onyx"]{ --bg:#050505; --panel:#0c0c0c; --line:#2c2c2c; --accent:#e8e8e8; --accent2:#ffffff; --text:#f5f5f5; --muted:#828282; }

  *{ box-sizing:border-box; margin:0; padding:0; }
  html,body{ height:100%; }
  body{
    background:var(--bg); color:var(--text); font-family:var(--font);
    min-height:100vh; padding:40px 18px 60px; transition:background .4s ease, color .4s ease;
  }
  .wrap{ max-width:480px; margin:0 auto; }

  .crest{ display:flex; flex-direction:column; align-items:center; gap:10px; margin-bottom:36px; }
  .crest-mark{
    width:52px; height:52px; border:1px solid var(--accent); border-radius:50%;
    display:flex; align-items:center; justify-content:center; font-size:20px; color:var(--accent);
    position:relative;
  }
  .crest-mark::before{
    content:''; position:absolute; inset:-6px; border:1px solid color-mix(in srgb, var(--accent) 35%, transparent); border-radius:50%;
  }
  .brand-word{ font-size:12px; letter-spacing:6px; color:var(--muted); text-transform:uppercase; }
  .brand-word b{ color:var(--accent); font-weight:600; }

  .card{
    background:var(--panel); border:1px solid var(--line); border-radius:var(--radius-lg);
    padding:36px 30px; position:relative; overflow:hidden; margin-bottom:20px;
  }
  .card::before{
    content:''; position:absolute; inset:0; pointer-events:none;
    background:linear-gradient(115deg, transparent 35%, color-mix(in srgb, var(--accent) 7%, transparent) 50%, transparent 65%);
    background-size:240% 240%; animation:eliteSweep 8s ease-in-out infinite;
  }
  @keyframes eliteSweep{ 0%,100%{ background-position:130% 0; } 50%{ background-position:-30% 100%; } }

  .status-row{ display:flex; align-items:center; justify-content:space-between; margin-bottom:26px; position:relative; z-index:1; }
  .status-pill{
    font-size:10.5px; letter-spacing:1.5px; text-transform:uppercase; color:var(--accent);
    border:1px solid color-mix(in srgb, var(--accent) 45%, transparent); border-radius:99px; padding:5px 14px;
  }
  .status-pill.down{ color:#c47a6f; border-color:color-mix(in srgb, #c47a6f 45%, transparent); }
  .update-mini{ font-size:10.5px; color:var(--muted); }

  h1.elite-h{ font-size:20px; font-weight:600; letter-spacing:.5px; margin-bottom:6px; color:var(--text); position:relative; z-index:1; }
  .elite-sub{ font-size:12px; color:var(--muted); margin-bottom:30px; position:relative; z-index:1; }

  .stat-line{
    display:flex; align-items:baseline; justify-content:space-between; padding:14px 0;
    border-top:1px solid var(--line); position:relative; z-index:1;
  }
  .stat-line:first-of-type{ border-top:none; }
  .stat-label{ font-size:11.5px; color:var(--muted); letter-spacing:.3px; }
  .stat-num{ font-size:17px; font-weight:600; color:var(--text); }
  .stat-num.accent{ color:var(--accent); }

  .hairline-bar{ height:1px; background:var(--line); margin-top:6px; position:relative; overflow:hidden; }
  .hairline-bar .fill{ position:absolute; inset:0 auto 0 0; height:100%; background:var(--accent); transition:width .6s ease; }

  .divider-label{
    display:flex; align-items:center; gap:10px; margin:30px 0 16px; font-size:10.5px; letter-spacing:2px;
    color:var(--muted); text-transform:uppercase;
  }
  .divider-label::before, .divider-label::after{ content:''; flex:1; height:1px; background:var(--line); }

  .sub-link-elite{
    font-size:11px; direction:ltr; text-align:left; color:var(--muted); word-break:break-all;
    border:1px solid var(--line); border-radius:var(--radius); padding:12px 14px; margin-bottom:14px;
  }
  .qr-elite{ display:flex; justify-content:center; margin-bottom:18px; }
  .qr-elite-inner{ background:#fff; padding:10px; border-radius:var(--radius); border:1px solid var(--accent); }
  .qr-elite-inner img{ display:block; width:130px; height:130px; }

  .btn-elite{
    display:block; width:100%; text-align:center; font-family:var(--font); font-size:12.5px; font-weight:600;
    letter-spacing:1px; color:var(--accent); background:transparent; border:1px solid var(--accent);
    border-radius:var(--radius); padding:13px; cursor:pointer; transition:.25s ease; margin-bottom:10px;
  }
  .btn-elite:hover{ background:var(--accent); color:var(--bg); }
  .btn-elite.ghost{ color:var(--muted); border-color:var(--line); }
  .btn-elite.ghost:hover{ background:var(--line); color:var(--text); }

  .theme-row{ display:flex; justify-content:center; gap:10px; margin-top:8px; margin-bottom:30px; }
  .theme-dot{ width:16px; height:16px; border-radius:50%; cursor:pointer; border:1px solid var(--line); }
  .theme-dot.active{ box-shadow:0 0 0 2px var(--accent); }
  .td-gold{ background:#c9a876; } .td-platinum{ background:#c7ccd4; } .td-rosegold{ background:#d9a894; }
  .td-emerald{ background:#a8c9a8; } .td-sapphire{ background:#a8bdd9; } .td-onyx{ background:#e8e8e8; }

  .search-elite{
    width:100%; padding:11px 14px; border:1px solid var(--line); border-radius:var(--radius);
    background:transparent; color:var(--text); font-family:var(--font); font-size:12.5px; margin-bottom:14px;
  }
  .search-elite::placeholder{ color:var(--muted); }

  .config-row-elite{
    display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 0;
    border-top:1px solid var(--line); font-size:12px;
  }
  .config-row-elite:first-child{ border-top:none; }
  .config-name-elite{ font-weight:600; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .config-meta-elite{ font-size:10.5px; color:var(--muted); direction:ltr; text-align:left; }
  .config-actions-elite{ display:flex; gap:6px; flex-shrink:0; }
  .config-actions-elite button{
    font-family:var(--font); font-size:10px; letter-spacing:.5px; color:var(--accent);
    background:transparent; border:1px solid var(--line); border-radius:var(--radius); padding:5px 9px; cursor:pointer;
  }
  .config-actions-elite button:hover{ border-color:var(--accent); }
  .config-list-elite{ max-height:380px; overflow-y:auto; }
  .empty-elite{ text-align:center; color:var(--muted); font-size:12px; padding:20px 0; }

  footer.elite-footer{ text-align:center; margin-top:30px; color:var(--muted); font-size:10.5px; letter-spacing:.5px; }

  .toast-elite{
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(15px); z-index:20;
    background:var(--panel); border:1px solid var(--accent); color:var(--text); font-size:12px;
    padding:10px 20px; border-radius:99px; opacity:0; pointer-events:none; transition:.3s ease;
  }
  .toast-elite.show{ opacity:1; transform:translateX(-50%) translateY(0); }

  .qr-modal-elite{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.82); z-index:30; align-items:center; justify-content:center; padding:20px; }
  .qr-modal-elite.show{ display:flex; }
  .qr-modal-elite-inner{ background:var(--panel); border:1px solid var(--line); border-radius:var(--radius-lg); padding:26px; text-align:center; max-width:300px; width:100%; }
  .qr-modal-elite-inner img{ width:200px; height:200px; border-radius:var(--radius); background:#fff; padding:10px; border:1px solid var(--accent); }
  .qr-modal-elite-title{ font-size:12.5px; margin-bottom:16px; color:var(--text); }
</style>
</head>
<body data-theme="<?= htmlspecialchars($subViewTheme, ENT_QUOTES, 'UTF-8') ?>">
<div class="wrap">

  <div class="crest">
    <div class="crest-mark">TK</div>
    <div class="brand-word"><b>TEAM</b> KAN</div>
  </div>

  <div class="card">
    <div class="status-row">
      <span class="status-pill <?= $isActive ? '' : 'down' ?>" id="statusPill"><?= $isActive ? 'اشتراک فعال' : 'منقضی شده' ?></span>
      <span class="update-mini fa">بروزرسانی: <span id="updatedAtText"><?= htmlspecialchars((string)$updatedAt, ENT_QUOTES, 'UTF-8') ?></span></span>
    </div>

    <h1 class="elite-h">وضعیت اشتراک شما</h1>
    <div class="elite-sub">دسترسی اختصاصی و امن به شبکه‌ی Team Kan</div>

    <div class="stat-line">
      <span class="stat-label">حجم مصرفی</span>
      <span class="stat-num accent" id="volText"><?= $hasVolumeLimit ? (fmtBytes($usedBytes) . ' / ' . fmtBytes($totalBytes)) : 'نامحدود' ?></span>
    </div>
    <div class="hairline-bar"><div class="fill" id="volBar" style="width:<?= $hasVolumeLimit ? $usedPercent : 3 ?>%"></div></div>

    <div class="stat-line">
      <span class="stat-label">روزهای باقیمانده</span>
      <span class="stat-num" id="daysLeftText"><?= $hasExpiry ? $daysLeft . ' روز' : 'نامحدود' ?></span>
    </div>
    <div class="stat-line">
      <span class="stat-label">تعداد کانفیگ‌ها</span>
      <span class="stat-num" id="configCountText"><?= (int)($row['total_configs'] ?? 0) ?></span>
    </div>

    <?php if ($subUrl !== ''): ?>
    <div class="divider-label">دسترسی اشتراک</div>
    <div class="sub-link-elite" id="subLink"><?= htmlspecialchars($subUrl, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="qr-elite"><div class="qr-elite-inner"><img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR" loading="lazy"></div></div>
    <button class="btn-elite" onclick="copyLink()">کپی لینک اشتراک</button>
    <button class="btn-elite ghost" id="refreshBtn" onclick="refreshData()"><span id="refreshBtnLabel">بروزرسانی زنده اطلاعات</span></button>
    <?php endif; ?>

    <div class="theme-row" id="themePicker">
      <div class="theme-dot td-gold" data-theme="gold" title="طلایی"></div>
      <div class="theme-dot td-platinum" data-theme="platinum" title="پلاتینیوم"></div>
      <div class="theme-dot td-rosegold" data-theme="rosegold" title="رزگلد"></div>
      <div class="theme-dot td-emerald" data-theme="emerald" title="زمرد"></div>
      <div class="theme-dot td-sapphire" data-theme="sapphire" title="یاقوت‌کبود"></div>
      <div class="theme-dot td-onyx" data-theme="onyx" title="اونیکس"></div>
    </div>
  </div>

  <div class="card">
    <div class="divider-label">فهرست کانفیگ‌ها</div>
    <input type="text" class="search-elite" id="configSearch" placeholder="جستجو بر اساس نام یا پروتکل">
    <div class="config-list-elite" id="configList"></div>
    <div style="margin-top:16px;">
      <button class="btn-elite ghost" onclick="copyAllConfigs()">کپی همه‌ی کانفیگ‌ها</button>
      <button class="btn-elite ghost" onclick="downloadAllConfigs()">دانلود فایل کانفیگ‌ها</button>
    </div>
  </div>

  <footer class="elite-footer">این صفحه به‌صورت خودکار از داخل ربات ساخته شده است — Team Kan</footer>
</div>

<div class="toast-elite" id="toast"></div>

<div class="qr-modal-elite" id="qrModal" onclick="closeQrModal(event)">
  <div class="qr-modal-elite-inner">
    <div class="qr-modal-elite-title" id="qrModalTitle"></div>
    <img id="qrModalImg" src="" alt="QR Code">
    <button class="btn-elite" style="margin-top:16px;" onclick="closeQrModal(event)">بستن</button>
  </div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
const SUB_URL = <?= json_encode($subUrl, JSON_UNESCAPED_SLASHES) ?>;
let CONFIGS = <?= json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const eDots = document.querySelectorAll('#themePicker .theme-dot');
function setActiveEDot(theme){ eDots.forEach(d => d.classList.toggle('active', d.dataset.theme === theme)); }
setActiveEDot(document.body.getAttribute('data-theme') || 'gold');
eDots.forEach(dot=>{
  dot.addEventListener('click', ()=>{
    setActiveEDot(dot.dataset.theme);
    document.body.setAttribute('data-theme', dot.dataset.theme);
  });
});

function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(window._tt);
  window._tt = setTimeout(()=> t.classList.remove('show'), 2200);
}
function fallbackCopy(text){
  try {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch(e){ return false; }
}
function copyText(text, msg){
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(()=>showToast(msg)).catch(()=>showToast(fallbackCopy(text) ? msg : '❌ کپی ناموفق بود.'));
  } else {
    showToast(fallbackCopy(text) ? msg : '❌ کپی ناموفق بود.');
  }
}
function copyLink(){ copyText(SUB_URL, '✅ لینک اشتراک کپی شد'); }

function decodeBase64Safe(str) {
  try { str = str.replace(/-/g,'+').replace(/_/g,'/'); while (str.length % 4) str += '='; return atob(str); }
  catch(e){ return ''; }
}
function extractHostPort(raw) {
  if (!raw) return { host:'', port:'' };
  const sm = raw.match(/^([a-z0-9]+):\/\//i);
  if (!sm) return { host:'', port:'' };
  const scheme = sm[1].toLowerCase();
  try {
    if (scheme === 'vmess') {
      let body = raw.slice(8).split('#')[0].split('?')[0];
      const json = JSON.parse(decodeBase64Safe(body));
      return { host: json.add || '', port: String(json.port || '') };
    }
    if (scheme === 'ss') {
      let rest = raw.slice(5).split('#')[0];
      let m = rest.match(/@([^:/?#]+):(\d+)/);
      if (m) return { host:m[1], port:m[2] };
      const decoded = decodeBase64Safe(rest.split('?')[0]);
      m = decoded.match(/@([^:/?#]+):(\d+)/);
      if (m) return { host:m[1], port:m[2] };
      return { host:'', port:'' };
    }
    const m = raw.match(/^[a-z0-9]+:\/\/(?:[^@/?#]*@)?(\[[^\]]+\]|[^:/?#]+)(?::(\d+))?/i);
    if (m) return { host:m[1].replace(/[[\]]/g,''), port:m[2] || '' };
  } catch(e){}
  return { host:'', port:'' };
}

function renderConfigList(filterText){
  const container = document.getElementById('configList');
  const q = (filterText || '').trim().toLowerCase();
  const filtered = CONFIGS.filter(c => !q || (c.name||'').toLowerCase().includes(q) || (c.protocol||'').toLowerCase().includes(q));

  if (filtered.length === 0) {
    container.innerHTML = '<div class="empty-elite">کانفیگی یافت نشد.</div>';
    return;
  }
  const fragment = document.createDocumentFragment();
  filtered.forEach(c => {
    const { host, port } = extractHostPort(c.raw || '');
    const address = host ? (host + (port ? ':' + port : '')) : 'آدرس نامشخص';

    const row = document.createElement('div');
    row.className = 'config-row-elite';

    const left = document.createElement('div');
    left.style.minWidth = '0'; left.style.flex = '1';
    const nameEl = document.createElement('div');
    nameEl.className = 'config-name-elite'; nameEl.dir = 'ltr'; nameEl.textContent = c.name || 'Config';
    const metaEl = document.createElement('div');
    metaEl.className = 'config-meta-elite'; metaEl.textContent = (c.protocol||'').toUpperCase() + ' • ' + address;
    left.appendChild(nameEl); left.appendChild(metaEl);

    const actions = document.createElement('div');
    actions.className = 'config-actions-elite';
    const copyBtn = document.createElement('button');
    copyBtn.textContent = 'کپی';
    copyBtn.addEventListener('click', () => {
      navigator.clipboard.writeText(c.raw || '').then(() => showToast('✅ کپی شد')).catch(() => showToast('❌ کپی ناموفق بود.'));
    });
    const qrBtn = document.createElement('button');
    qrBtn.textContent = 'QR';
    qrBtn.addEventListener('click', () => showConfigQR(c.raw || '', c.name || c.protocol || 'Config'));
    actions.appendChild(copyBtn); actions.appendChild(qrBtn);

    row.appendChild(left); row.appendChild(actions);
    fragment.appendChild(row);
  });
  container.innerHTML = '';
  container.appendChild(fragment);
}
renderConfigList('');
document.getElementById('configSearch').addEventListener('input', e => renderConfigList(e.target.value));

function copyAllConfigs(){
  if (CONFIGS.length === 0) { showToast('❌ کانفیگی موجود نیست.'); return; }
  copyText(CONFIGS.map(c => c.raw || '').filter(Boolean).join('\n'), '✅ همه‌ی کانفیگ‌ها کپی شد');
}
function downloadAllConfigs(){
  if (CONFIGS.length === 0) { showToast('❌ کانفیگی موجود نیست.'); return; }
  const text = CONFIGS.map(c => c.raw || '').filter(Boolean).join('\n');
  const blob = new Blob([text], { type:'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = 'configs.txt';
  document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  showToast('⬇️ فایل دانلود شد.');
}
function showConfigQR(raw, name){
  if (!raw) { showToast('❌ این کانفیگ QR ندارد.'); return; }
  document.getElementById('qrModalTitle').textContent = name || 'QR Code';
  document.getElementById('qrModalImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' + encodeURIComponent(raw) + '&bgcolor=255-255-255&color=0-0-0&margin=1';
  document.getElementById('qrModal').classList.add('show');
}
function closeQrModal(e){
  if (e.target.id === 'qrModal' || e.target.textContent === 'بستن') document.getElementById('qrModal').classList.remove('show');
}

function fmtBytesJs(bytes){
  bytes = parseFloat(bytes) || 0;
  if (bytes <= 0) return '0 B';
  const units = ['B','KB','MB','GB','TB'];
  let pow = Math.floor(Math.log(bytes) / Math.log(1024));
  pow = Math.min(pow, units.length - 1);
  return (bytes / Math.pow(1024, pow)).toFixed(2) + ' ' + units[pow];
}
async function refreshData(){
  const label = document.getElementById('refreshBtnLabel');
  const original = label.textContent;
  label.textContent = 'در حال بروزرسانی...';
  try {
    const res = await fetch('sub_refresh.php', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: TOKEN })
    });
    const data = await res.json();
    if (!data.ok) { showToast('❌ ' + (data.error || 'بروزرسانی ناموفق بود.')); label.textContent = original; return; }

    CONFIGS = data.configs || [];
    renderConfigList(document.getElementById('configSearch').value);

    const totalBytes = parseFloat(data.total_bytes) || 0;
    const usedBytes = parseFloat(data.used_bytes) || 0;
    const hasVolume = totalBytes > 0;
    const usedPercent = hasVolume ? Math.min(100, Math.round((usedBytes / totalBytes) * 100)) : 0;
    document.getElementById('volText').textContent = hasVolume ? (fmtBytesJs(usedBytes) + ' / ' + fmtBytesJs(totalBytes)) : 'نامحدود';
    document.getElementById('volBar').style.width = (hasVolume ? usedPercent : 3) + '%';

    const expireTs = parseInt(data.expire_ts, 10) || 0;
    const hasExpiry = expireTs > 0;
    const now = Math.floor(Date.now()/1000);
    const daysLeft = hasExpiry ? Math.max(0, Math.ceil((expireTs - now) / 86400)) : null;
    document.getElementById('daysLeftText').textContent = hasExpiry ? daysLeft + ' روز' : 'نامحدود';
    document.getElementById('configCountText').textContent = data.total_configs;

    const expiredByTime = hasExpiry && expireTs <= now;
    const expiredByVolume = hasVolume && usedBytes >= totalBytes;
    const isActive = !expiredByTime && !expiredByVolume;
    const pill = document.getElementById('statusPill');
    pill.classList.toggle('down', !isActive);
    pill.textContent = isActive ? 'اشتراک فعال' : 'منقضی شده';

    if (data.updated_at) document.getElementById('updatedAtText').textContent = data.updated_at;
    showToast('✅ بروزرسانی شد.');
  } catch(e) {
    showToast('❌ خطا در برقراری ارتباط.');
  } finally {
    label.textContent = original;
  }
}
</script>
</body>
</html>
<?php else: ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>وضعیت اشتراک شما</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preload" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
<style>
  :root{
    --radius: 18px;
    --radius-sm: 10px;
    --font-display: 'Vazirmatn', Tahoma, sans-serif;
    --font-mono: 'JetBrains Mono', monospace;
  }

  body[data-theme="black"]{
    --bg:#07070b; --bg-elev:#0f0f16; --bg-card:#12121b;
    --text:#f3f3f7; --text-dim:#8b8b9a; --border:#22222f;
    --accent:#4ff0ff; --accent-2:#a06bff; --glow:rgba(79,240,255,0.45);
  }
  body[data-theme="white"]{
    --bg:#f2f2f5; --bg-elev:#ffffff; --bg-card:#ffffff;
    --text:#14141b; --text-dim:#6a6a76; --border:#dcdce3;
    --accent:#2f6bff; --accent-2:#7a3cff; --glow:rgba(47,107,255,0.28);
  }
  body[data-theme="red"]{
    --bg:#0a0505; --bg-elev:#150808; --bg-card:#1a0a0a;
    --text:#fbecec; --text-dim:#a17f7f; --border:#2b1212;
    --accent:#ff3b4e; --accent-2:#ff9c4f; --glow:rgba(255,59,78,0.5);
  }
  body[data-theme="purple"]{
    --bg:#0a0713; --bg-elev:#130d20; --bg-card:#170f27;
    --text:#f2edfb; --text-dim:#9186ac; --border:#241a38;
    --accent:#b06bff; --accent-2:#4ff0ff; --glow:rgba(176,107,255,0.5);
  }
  body[data-theme="ocean"]{
    --bg:#020617; --bg-elev:#0a1628; --bg-card:#0d1d33;
    --text:#e0f2fe; --text-dim:#7ea3c2; --border:#16324f;
    --accent:#22d3ee; --accent-2:#3b82f6; --glow:rgba(34,211,238,0.45);
  }
  body[data-theme="emerald"]{
    --bg:#020e0a; --bg-elev:#052018; --bg-card:#07271d;
    --text:#e7fbf3; --text-dim:#7fc9a8; --border:#0f3d2c;
    --accent:#10b981; --accent-2:#34d399; --glow:rgba(16,185,129,0.45);
  }

  *{ box-sizing:border-box; margin:0; padding:0; }
  html,body{ height:100%; }
  body{
    background: var(--bg); color: var(--text); font-family: var(--font-display);
    transition: background .4s ease, color .4s ease;
    min-height:100vh; padding: 28px 16px 60px; position:relative; overflow-x:hidden;
  }
  body::before{
    content:""; position:fixed; inset:0;
    background:
      radial-gradient(600px 400px at 15% 0%, var(--glow), transparent 60%),
      radial-gradient(500px 350px at 100% 20%, var(--glow), transparent 55%);
    opacity:.22; pointer-events:none; transition: background .5s ease;
    animation: bgDrift 14s ease-in-out infinite; z-index:0;
  }
  @keyframes bgDrift{
    0%,100%{ transform: translate(0,0) scale(1); opacity:.22; }
    50%{ transform: translate(2%,3%) scale(1.05); opacity:.36; }
  }

  .light-curtain{ position:fixed; inset:0; z-index:0; pointer-events:none; overflow:hidden; }
  .beam{
    position:absolute; top:-18%; height:140%; width:110px; filter: blur(28px);
    mix-blend-mode: screen; opacity:.16;
    animation-name: beamSway; animation-timing-function: ease-in-out; animation-iteration-count: infinite;
  }
  @keyframes beamSway{
    0%,100%{ transform: rotate(calc(var(--rot, 0deg) - 4deg)) scaleY(1); opacity:.12; }
    50%{ transform: rotate(calc(var(--rot, 0deg) + 4deg)) scaleY(1.06); opacity:.26; }
  }

  .particles{ position:fixed; inset:0; pointer-events:none; z-index:0; overflow:hidden; }
  .particle{
    position:absolute; bottom:-10px; width:3px; height:3px; border-radius:50%;
    background: var(--accent); box-shadow: 0 0 8px 2px var(--glow);
    opacity:0; animation: floatUp linear infinite;
  }
  @keyframes floatUp{
    0%{ transform: translateY(0) scale(1); opacity:0; }
    10%{ opacity:.8; } 90%{ opacity:.5; }
    100%{ transform: translateY(-100vh) scale(.4); opacity:0; }
  }

  @keyframes neonPulse{
    0%,100%{ box-shadow: 0 0 14px var(--glow); }
    50%{ box-shadow: 0 0 32px var(--glow); }
  }
  .neon-frame{ animation: neonPulse 3.6s ease-in-out infinite; position:relative; }

  @keyframes scan{
    0%{ top:0%; opacity:0; } 12%{ opacity:.9; } 88%{ opacity:.9; } 100%{ top:100%; opacity:0; }
  }
  .scan-line{
    content:""; position:absolute; left:6%; right:6%; height:2px;
    background: linear-gradient(90deg, transparent, var(--accent), var(--accent-2), transparent);
    filter: blur(.5px); animation: scan 3.4s linear infinite; pointer-events:none;
  }

  @keyframes shine{ to{ background-position: -200% 0; } }
  .wrap{ max-width: 860px; margin: 0 auto; position:relative; z-index:1; }

  header{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom: 30px; }
  .brand{ display:flex; align-items:center; gap:10px; }
  .brand-mark{
    width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center;
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    font-family: var(--font-mono); font-weight:700; color:#04040a; font-size:15px;
    animation: markGlow 3s ease-in-out infinite;
  }
  @keyframes markGlow{
    0%,100%{ box-shadow: 0 0 14px var(--glow); } 50%{ box-shadow: 0 0 28px var(--glow); }
  }
  .brand-name{ font-weight:800; font-size:18px; letter-spacing:.2px; }
  .brand-sub{ font-family:var(--font-mono); font-size:11px; color:var(--text-dim); }

  .theme-picker{ display:flex; gap:8px; background:var(--bg-elev); padding:6px; border-radius:999px; flex-wrap:wrap; }
  .theme-dot{
    width:24px; height:24px; border-radius:50%; cursor:pointer; border:2px solid transparent;
    transition: transform .2s ease, border-color .2s ease; position:relative;
  }
  .theme-dot:hover{ transform: scale(1.12); }
  .theme-dot.active{ border-color: var(--text); }
  .theme-dot.active::after{
    content:""; position:absolute; inset:-6px; border-radius:50%;
    border:1px solid var(--accent); opacity:.6; animation: ringPulse 1.8s ease-out infinite;
  }
  @keyframes ringPulse{ 0%{ transform: scale(1); opacity:.7; } 100%{ transform: scale(1.35); opacity:0; } }
  .dot-black{ background:#0c0c14; }
  .dot-white{ background:#f4f4f7; }
  .dot-red{ background:#ff3b4e; }
  .dot-purple{ background:#b06bff; }
  .dot-ocean{ background:#22d3ee; }
  .dot-emerald{ background:#10b981; }

  .hero{
    background: linear-gradient(180deg, var(--bg-card), var(--bg-elev));
    border:1px solid var(--border); border-radius: var(--radius);
    padding: 28px; position:relative; overflow:hidden; margin-bottom: 18px;
  }
  .pulse-wrap{ position:absolute; left:-40px; top:-40px; width:200px; height:200px; pointer-events:none; }
  .pulse-ring{ position:absolute; inset:0; border-radius:50%; border:1px solid var(--accent); opacity:0; animation: pulse 3.2s ease-out infinite; }
  .pulse-ring:nth-child(2){ animation-delay:1.05s; }
  .pulse-ring:nth-child(3){ animation-delay:2.1s; }
  @keyframes pulse{ 0%{ transform: scale(.3); opacity:.55; } 100%{ transform: scale(1.4); opacity:0; } }

  .status-row{ display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:22px; position:relative; z-index:1; }
  .status-badge{
    display:inline-flex; align-items:center; gap:8px; font-family: var(--font-mono); font-size:12px; font-weight:600;
    padding:7px 14px; border-radius:999px; background: color-mix(in srgb, var(--accent) 14%, transparent);
    color: var(--accent); border:1px solid color-mix(in srgb, var(--accent) 40%, transparent);
  }
  .status-badge.inactive{
    background: color-mix(in srgb, #ff3b4e 14%, transparent); color:#ff8a95;
    border-color: color-mix(in srgb, #ff3b4e 40%, transparent);
  }
  .status-badge .d{ width:7px; height:7px; border-radius:50%; background:currentColor; box-shadow:0 0 8px currentColor; }

  h1{
    font-size: clamp(20px, 4vw, 27px); font-weight:800; margin-bottom:6px;
    background: linear-gradient(90deg, var(--text), var(--accent), var(--accent-2), var(--text));
    background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; color: transparent;
    animation: shine 5s linear infinite;
  }
  .hero-sub{ color: var(--text-dim); font-size:13px; margin-bottom:22px; }

  .stats{ display:grid; grid-template-columns: repeat(3, 1fr); gap:14px; position:relative; z-index:1; }
  .stat{ background: var(--bg-elev); border:1px solid var(--border); border-radius: var(--radius-sm); padding:14px; }
  .stat-label{ font-size:11px; color:var(--text-dim); margin-bottom:6px; }
  .stat-val{ font-family: var(--font-mono); font-size:16px; font-weight:600; }
  .bar{ height:6px; border-radius:999px; background:var(--border); margin-top:10px; overflow:hidden; }
  .bar-fill{ height:100%; border-radius:999px; background: linear-gradient(90deg, var(--accent), var(--accent-2)); transition:width .5s; box-shadow:0 0 10px var(--glow); }
  .bar-fill.warn{ background: linear-gradient(90deg, #ff3b4e, #f59e0b); }

  .sub-card{
    display:flex; gap:18px; align-items:center; margin-top:20px; position:relative; z-index:1;
    background: var(--bg-elev); border:1px solid var(--border); border-radius: var(--radius-sm); padding:16px; flex-wrap:wrap;
  }
  .qr-box{ width:74px; height:74px; border-radius:10px; flex-shrink:0; background:#fff; padding:4px; }
  .qr-box img{ width:100%; height:100%; object-fit:contain; border-radius:6px; }
  .link-col{ flex:1; min-width:180px; }
  .link-label{ font-size:11px; color:var(--text-dim); margin-bottom:6px; }
  .link-box{ font-family: var(--font-mono); font-size:12px; color:var(--text-dim); direction:ltr; text-align:left; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .copy-btn{
    font-family: var(--font-display); font-weight:700; font-size:13px;
    background: linear-gradient(135deg, var(--accent), var(--accent-2)); color:#04040a; border:none;
    border-radius: 999px; padding:11px 20px; cursor:pointer; box-shadow: 0 0 20px var(--glow);
    transition: transform .15s ease; position:relative; overflow:hidden;
  }
  .copy-btn::after{
    content:""; position:absolute; top:0; bottom:0; left:-60%; width:40%;
    background: linear-gradient(115deg, transparent, rgba(255,255,255,.55), transparent); animation: sweep 2.6s ease-in-out infinite;
  }
  @keyframes sweep{ 0%{ left:-60%; } 60%,100%{ left:130%; } }
  .copy-btn:active{ transform: scale(.96); }

  .section-title{ font-size:15px; font-weight:700; margin: 26px 0 12px; display:flex; align-items:center; gap:8px; }
  .section-title .tag{ font-family: var(--font-mono); font-size:10px; color:var(--accent); padding:2px 8px; border-radius:999px; background: color-mix(in srgb, var(--accent) 12%, transparent); }

  .platform-tabs{ display:flex; gap:8px; overflow-x:auto; padding-bottom:4px; margin-bottom:14px; }
  .ptab{
    font-family: var(--font-mono); font-size:12px; white-space:nowrap; padding:9px 16px; border-radius:999px; cursor:pointer;
    background: var(--bg-card); color:var(--text-dim); border:1px solid var(--border); transition: all .18s ease;
  }
  .ptab.active{ color:#04040a; background: linear-gradient(135deg, var(--accent), var(--accent-2)); border-color: transparent; box-shadow:0 0 16px var(--glow); }

  .app-grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(150px,1fr)); gap:14px; }
  .app-card{
    background: var(--bg-card); border:1px solid var(--border); border-radius: var(--radius-sm);
    padding:16px 14px; cursor:pointer; transition: border-color .18s ease, transform .18s ease;
    display:flex; flex-direction:column; gap:10px;
  }
  .app-card:hover{ transform: translateY(-3px); border-color: var(--accent); }
  .app-card.selected{ border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent), 0 0 18px var(--glow); }
  .app-icon{ width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; background: var(--bg-elev); font-size:17px; overflow:hidden; }
  .app-name{ font-weight:700; font-size:13.5px; }
  .app-tag{ font-family: var(--font-mono); font-size:10px; color:var(--text-dim); }
  .app-actions{ display:none; gap:8px; margin-top:2px; }
  .app-card.selected .app-actions{ display:flex; }
  .mini-btn{
    flex:1; font-family: var(--font-mono); font-size:10.5px; text-align:center; padding:7px 6px; border-radius:8px;
    background:var(--bg-elev); color:var(--text); border:1px solid var(--border); cursor:pointer; text-decoration:none; display:block;
  }
  .mini-btn.primary{ background: var(--accent); color:#04040a; border-color:transparent; font-weight:600; }

  .config-card{ background: var(--bg-card); border:1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; margin-bottom: 22px; position:relative; z-index:1; }
  .config-header{ display:flex; align-items:center; justify-content:space-between; gap:10px; cursor:pointer; }
  .config-app-row{ display:flex; align-items:center; gap:10px; }
  .config-app-icon{ width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; background: var(--bg-elev); font-size:15px; overflow:hidden; }
  .config-app-title{ font-weight:700; font-size:14px; }
  .config-app-hint{ font-size:11px; color:var(--text-dim); font-family: var(--font-mono); }
  .chevron{ flex-shrink:0; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; background: var(--bg-elev); color: var(--text-dim); transition: transform .3s ease; }
  .config-card.open .chevron{ transform: rotate(180deg); color: var(--accent); }
  .config-body{ max-height:0; overflow:hidden; opacity:0; transition: max-height .38s ease, opacity .28s ease, margin-top .38s ease; }
  .config-card.open .config-body{ max-height:2000px; opacity:1; margin-top:16px; }

  .search-box{ width:100%; padding:11px 14px; border-radius:11px; border:1px solid var(--border); background:var(--bg-elev); color:var(--text); font-family:inherit; font-size:13px; margin-bottom:12px; }
  .config-list{ max-height:400px; overflow-y:auto; }
  .config-item{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:12px; background:var(--bg-elev); border:1px solid var(--border); margin-bottom:8px; }
  .ci-flag{ font-size:18px; flex-shrink:0; }
  .ci-info{ flex:1; min-width:0; }
  .ci-name{ font-weight:700; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .ci-proto{ font-size:11px; color:var(--text-dim); font-family:var(--font-mono); }
  .ci-actions{ display:flex; gap:6px; flex-shrink:0; }
  .ci-btn{ font-family:var(--font-mono); font-size:11px; padding:6px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-card); color:var(--text); cursor:pointer; }
  .ci-btn.copied{ background: var(--accent); color:#04040a; border-color:transparent; }
  .config-empty{ text-align:center; color:var(--text-dim); font-size:12.5px; padding:20px; }

  .config-actions{ display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; }
  .config-actions .copy-btn{ flex:1; min-width:140px; }
  .config-actions .mini-btn{ flex:1; min-width:140px; padding:11px 14px; font-size:12px; }

  footer{ text-align:center; margin-top:32px; color:var(--text-dim); font-size:11.5px; font-family: var(--font-mono); }

  .toast{
    position:fixed; bottom:22px; left:50%; transform: translateX(-50%) translateY(20px);
    background: var(--bg-card); border:1px solid var(--accent); color:var(--text); font-size:12.5px;
    padding:10px 18px; border-radius:999px; box-shadow: 0 0 20px var(--glow); opacity:0; pointer-events:none;
    transition: all .25s ease; z-index:10; font-family: var(--font-display);
  }
  .toast.show{ opacity:1; transform: translateX(-50%) translateY(0); }

  .qr-modal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:20; align-items:center; justify-content:center; padding:20px; }
  .qr-modal.show{ display:flex; }
  .qr-modal-inner{ background: var(--bg-card); border:1px solid var(--border); border-radius: var(--radius); padding:24px; text-align:center; max-width:320px; width:100%; }
  .qr-modal-inner img{ width:220px; height:220px; border-radius:14px; background:#fff; padding:10px; }
  .qr-modal-title{ font-size:13px; font-weight:700; margin-bottom:16px; }
  .qr-modal-close{ margin-top:16px; width:100%; padding:11px; border-radius:11px; border:1px solid var(--border); background:var(--bg-elev); color:var(--text); font-family:inherit; cursor:pointer; }

  @media (max-width:520px){
    .stats{ grid-template-columns: 1fr 1fr; }
    .stats .stat:last-child{ grid-column: span 2; }
  }
</style>
</head>
<body data-theme="<?= htmlspecialchars($subViewTheme, ENT_QUOTES, 'UTF-8') ?>">

<div class="light-curtain" id="lightCurtain"></div>
<div class="particles" id="particles"></div>

<div class="wrap">

  <header>
    <div class="brand">
      <div class="brand-mark">TK</div>
      <div>
        <div class="brand-name">Team Kan</div>
        <div class="brand-sub">SUB · PANEL</div>
      </div>
    </div>
    <div class="theme-picker" id="themePicker">
      <div class="theme-dot dot-black" data-theme="black" title="سیاه"></div>
      <div class="theme-dot dot-white" data-theme="white" title="سفید"></div>
      <div class="theme-dot dot-red" data-theme="red" title="قرمز"></div>
      <div class="theme-dot dot-purple" data-theme="purple" title="بنفش"></div>
      <div class="theme-dot dot-ocean" data-theme="ocean" title="اقیانوسی"></div>
      <div class="theme-dot dot-emerald" data-theme="emerald" title="زمرد"></div>
    </div>
  </header>

  <div class="hero neon-frame">
    <div class="scan-line"></div>
    <div class="pulse-wrap">
      <div class="pulse-ring"></div><div class="pulse-ring"></div><div class="pulse-ring"></div>
    </div>

    <div class="status-row">
      <span class="status-badge <?= $isActive ? '' : 'inactive' ?>" id="statusBadge"><span class="d"></span><?= $isActive ? 'اشتراک فعال' : 'اشتراک منقضی شده' ?></span>
      <span style="font-family:var(--font-mono); font-size:11px; color:var(--text-dim);">آخرین بروزرسانی: <span id="updatedAtText"><?= htmlspecialchars((string)$updatedAt, ENT_QUOTES, 'UTF-8') ?></span></span>
    </div>

    <h1>اتصال امن، بدون مرز</h1>
    <p class="hero-sub">لینک اشتراک خود را کپی کنید یا از بین برنامه‌های زیر، برنامه دستگاه‌تان را انتخاب کنید.</p>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">حجم مصرفی</div>
        <div class="stat-val" id="volText"><?= $hasVolumeLimit ? (fmtBytes($usedBytes) . ' / ' . fmtBytes($totalBytes)) : 'نامحدود' ?></div>
        <div class="bar"><div class="bar-fill <?= $usedPercent >= 85 ? 'warn' : '' ?>" id="volBar" style="width:<?= $hasVolumeLimit ? $usedPercent : 3 ?>%"></div></div>
      </div>
      <div class="stat">
        <div class="stat-label">روزهای باقیمانده</div>
        <div class="stat-val" id="daysLeftText"><?= $hasExpiry ? $daysLeft . ' روز' : 'نامحدود' ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">تعداد کانفیگ‌ها</div>
        <div class="stat-val" id="configCountText"><?= (int)($row['total_configs'] ?? 0) ?></div>
      </div>
    </div>

    <?php if ($subUrl !== ''): ?>
    <div class="sub-card">
      <div class="qr-box"><img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR" loading="lazy"></div>
      <div class="link-col">
        <div class="link-label">لینک اشتراک</div>
        <div class="link-box" id="subLink"><?= htmlspecialchars($subUrl, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <button class="copy-btn" onclick="copyLink()">کپی لینک</button>
    </div>
    <?php endif; ?>
  </div>

  <div class="section-title">انتخاب برنامه <span class="tag">بر اساس دستگاه</span></div>

  <div class="platform-tabs" id="platformTabs">
    <div class="ptab active" data-p="android">اندروید</div>
    <div class="ptab" data-p="ios">آیفون / آیپد</div>
    <div class="ptab" data-p="windows">ویندوز</div>
    <div class="ptab" data-p="mac">مک</div>
    <div class="ptab" data-p="linux">لینوکس</div>
  </div>

  <div class="app-grid" id="appGrid"></div>

  <div class="section-title" style="margin-top:26px;">📥 دریافت کانفیگ و اشتراک‌ها</div>
  <div class="config-card neon-frame open" id="configCard">
    <div class="config-header" id="configHeader" onclick="toggleConfig()">
      <div class="config-app-row">
        <div class="config-app-icon">🗂</div>
        <div>
          <div class="config-app-title">لیست کانفیگ‌ها</div>
          <div class="config-app-hint" id="configListCount">۰ کانفیگ</div>
        </div>
      </div>
      <div class="chevron" id="configChevron">⌄</div>
    </div>
    <div class="config-body" id="configBody">
      <input type="text" class="search-box" id="configSearch" placeholder="🔍 جستجو بر اساس نام یا پروتکل...">
      <div class="config-list" id="configList"></div>
      <div class="config-actions">
        <button class="copy-btn" onclick="copyAllConfigs(this)">📋 کپی همه‌ی کانفیگ‌ها</button>
        <div class="mini-btn" onclick="downloadAllConfigs()">⬇️ دانلود فایل (txt)</div>
        <div class="mini-btn" id="refreshBtn" onclick="refreshData()"><span id="refreshBtnLabel">🔄 بروزرسانی زنده</span></div>
      </div>
    </div>
  </div>

  <footer>این صفحه به‌صورت خودکار از داخل ربات ساخته شده است — <b>Team Kan</b> · این لینک تا ۵ دقیقه بعد از آخرین بروزرسانی معتبر است</footer>
</div>

<div class="toast" id="toast"></div>

<div class="qr-modal" id="qrModal" onclick="closeQrModal(event)">
  <div class="qr-modal-inner">
    <div class="qr-modal-title" id="qrModalTitle"></div>
    <img id="qrModalImg" src="" alt="QR Code">
    <button class="qr-modal-close" onclick="closeQrModal(event)">بستن ✕</button>
  </div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
const SUB_URL = <?= json_encode($subUrl, JSON_UNESCAPED_SLASHES) ?>;
const FIRST_VLESS = <?= json_encode($firstVless['raw'] ?? '', JSON_UNESCAPED_SLASHES) ?>;
let CONFIGS = <?= json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

// ---------------- تم ----------------
const dots = document.querySelectorAll('.theme-dot');
function setActiveDot(theme){
  dots.forEach(d => d.classList.toggle('active', d.dataset.theme === theme));
}
setActiveDot(document.body.getAttribute('data-theme') || 'black');
dots.forEach(dot=>{
  dot.addEventListener('click', ()=>{
    setActiveDot(dot.dataset.theme);
    document.body.setAttribute('data-theme', dot.dataset.theme);
  });
});

// ---------------- toast / کپی ----------------
function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(window._tt);
  window._tt = setTimeout(()=> t.classList.remove('show'), 2200);
}
function fallbackCopy(text){
  try {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch (e) { return false; }
}
function copyText(text, successMsg){
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text)
      .then(() => showToast(successMsg))
      .catch(() => showToast(fallbackCopy(text) ? successMsg : '❌ کپی خودکار امکان‌پذیر نبود.'));
  } else {
    showToast(fallbackCopy(text) ? successMsg : '❌ کپی خودکار امکان‌پذیر نبود.');
  }
}
function copyLink(){ copyText(SUB_URL, '✅ لینک اشتراک کپی شد'); }

// ---------------- برنامه‌های پیشنهادی (لینک‌های دانلود رسمی) ----------------
const HIDDIFY_LINK = 'https://github.com/hiddify/hiddify-app/releases';
const apps = {
  android: [
    {name:'V2rayNG', icon:'🚀', tag:'محبوب‌ترین', link:'https://play.google.com/store/apps/details?id=com.v2ray.ang'},
    {name:'Hiddify Next', icon:'🛡️', tag:'همه‌کاره', link:HIDDIFY_LINK},
    {name:'NapsternetV', icon:'📡', tag:'سبک', link:'https://play.google.com/store/apps/details?id=com.napsternetlabs.napsternetv'},
    {name:'Clash Meta', icon:'⚡', tag:'پیشرفته', link:'https://github.com/MetaCubeX/ClashMetaForAndroid/releases'},
  ],
  ios: [
    {name:'Streisand', icon:'🌀', tag:'پیشنهادی', link:'https://apps.apple.com/app/streisand/id6450534064'},
    {name:'Shadowrocket', icon:'🚀', tag:'محبوب (پولی)', link:'https://apps.apple.com/app/shadowrocket/id932747118'},
    {name:'FoXray', icon:'🦊', tag:'رایگان', link:'https://apps.apple.com/search?term=FoXray'},
    {name:'Hiddify Next', icon:'🛡️', tag:'همه‌کاره', link:HIDDIFY_LINK},
  ],
  windows: [
    {name:'v2rayN', icon:'🖥️', tag:'کلاسیک', link:'https://github.com/2dust/v2rayN/releases'},
    {name:'Hiddify Next', icon:'🛡️', tag:'همه‌کاره', link:HIDDIFY_LINK},
    {name:'Clash Verge', icon:'⚡', tag:'پیشرفته', link:'https://github.com/clash-verge-rev/clash-verge-rev/releases'},
  ],
  mac: [
    {name:'Streisand', icon:'🌀', tag:'پیشنهادی', link:'https://apps.apple.com/app/streisand/id6450534064'},
    {name:'Hiddify Next', icon:'🛡️', tag:'همه‌کاره', link:HIDDIFY_LINK},
    {name:'ClashX Meta', icon:'⚡', tag:'پیشرفته', link:'https://github.com/MetaCubeX/ClashX.Meta/releases'},
  ],
  linux: [
    {name:'Hiddify Next', icon:'🛡️', tag:'همه‌کاره', link:HIDDIFY_LINK},
    {name:'NekoRay', icon:'🐱', tag:'ترمینال‌دوست', link:'https://github.com/MatsuriDayo/nekoray/releases'},
  ],
};

const grid = document.getElementById('appGrid');
const tabs = document.querySelectorAll('.ptab');

function renderApps(platform){
  grid.innerHTML = '';
  apps[platform].forEach(app=>{
    const card = document.createElement('div');
    card.className = 'app-card';
    card.innerHTML = `
      <div class="app-icon">${app.icon}</div>
      <div>
        <div class="app-name">${app.name}</div>
        <div class="app-tag">${app.tag}</div>
      </div>
      <div class="app-actions">
        <div class="mini-btn primary" onclick="event.stopPropagation(); getConfigForApp('${app.name}')">دریافت کانفیگ</div>
        <a class="mini-btn" href="${app.link}" target="_blank" rel="noopener" onclick="event.stopPropagation();">دانلود</a>
      </div>
    `;
    card.addEventListener('click', ()=>{
      document.querySelectorAll('.app-card').forEach(c=>c.classList.remove('selected'));
      card.classList.add('selected');
    });
    grid.appendChild(card);
  });
}
tabs.forEach(tab=>{
  tab.addEventListener('click', ()=>{
    tabs.forEach(t=>t.classList.remove('active'));
    tab.classList.add('active');
    renderApps(tab.dataset.p);
  });
});
renderApps('android');

function getConfigForApp(name){
  const text = FIRST_VLESS || SUB_URL;
  if (!text) { showToast('❌ کانفیگی برای کپی موجود نیست.'); return; }
  copyText(text, '✅ کانفیگ برای ' + name + ' کپی شد');
}

function toggleConfig(forceOpen){
  const card = document.getElementById('configCard');
  const isOpen = card.classList.contains('open');
  const next = typeof forceOpen === 'boolean' ? forceOpen : !isOpen;
  card.classList.toggle('open', next);
}

// ---------------- کمکی: تشخیص هاست/پرچم ----------------
function decodeBase64Safe(str) {
  try {
    str = str.replace(/-/g, '+').replace(/_/g, '/');
    while (str.length % 4) str += '=';
    return atob(str);
  } catch (e) { return ''; }
}
function extractHostPort(raw) {
  if (!raw) return { host: '', port: '' };
  const schemeMatch = raw.match(/^([a-z0-9]+):\/\//i);
  if (!schemeMatch) return { host: '', port: '' };
  const scheme = schemeMatch[1].toLowerCase();
  try {
    if (scheme === 'vmess') {
      let body = raw.slice(8).split('#')[0].split('?')[0];
      const json = JSON.parse(decodeBase64Safe(body));
      return { host: json.add || '', port: String(json.port || '') };
    }
    if (scheme === 'ss') {
      let rest = raw.slice(5).split('#')[0];
      let m = rest.match(/@([^:/?#]+):(\d+)/);
      if (m) return { host: m[1], port: m[2] };
      const decoded = decodeBase64Safe(rest.split('?')[0]);
      m = decoded.match(/@([^:/?#]+):(\d+)/);
      if (m) return { host: m[1], port: m[2] };
      return { host: '', port: '' };
    }
    const m = raw.match(/^[a-z0-9]+:\/\/(?:[^@/?#]*@)?(\[[^\]]+\]|[^:/?#]+)(?::(\d+))?/i);
    if (m) return { host: m[1].replace(/[[\]]/g, ''), port: m[2] || '' };
  } catch (e) {}
  return { host: '', port: '' };
}
const COUNTRY_MAP = {
  iran:'🇮🇷', ir:'🇮🇷', germany:'🇩🇪', de:'🇩🇪', usa:'🇺🇸', us:'🇺🇸', finland:'🇫🇮', fi:'🇫🇮',
  netherlands:'🇳🇱', nl:'🇳🇱', turkey:'🇹🇷', tr:'🇹🇷', france:'🇫🇷', fr:'🇫🇷', uk:'🇬🇧', england:'🇬🇧',
  russia:'🇷🇺', ru:'🇷🇺', uae:'🇦🇪', dubai:'🇦🇪', canada:'🇨🇦', ca:'🇨🇦', japan:'🇯🇵', jp:'🇯🇵',
  singapore:'🇸🇬', sg:'🇸🇬', poland:'🇵🇱', pl:'🇵🇱',
};
function guessFlag(text) {
  const t = (text || '').toLowerCase();
  for (const key in COUNTRY_MAP) { if (t.includes(key)) return COUNTRY_MAP[key]; }
  return '🌐';
}

// ---------------- لیست کانفیگ‌ها ----------------
function renderConfigList(filterText) {
  const container = document.getElementById('configList');
  const q = (filterText || '').trim().toLowerCase();
  const filtered = CONFIGS.filter(c => {
    if (!q) return true;
    return (c.name || '').toLowerCase().includes(q) || (c.protocol || '').toLowerCase().includes(q);
  });
  document.getElementById('configListCount').textContent = filtered.length + ' از ' + CONFIGS.length + ' کانفیگ';

  if (filtered.length === 0) {
    container.innerHTML = '<div class="config-empty">کانفیگی با این مشخصات یافت نشد.</div>';
    return;
  }

  const fragment = document.createDocumentFragment();
  filtered.forEach(c => {
    const item = document.createElement('div');
    item.className = 'config-item';
    const { host, port } = extractHostPort(c.raw || '');
    const address = host ? (host + (port ? ':' + port : '')) : 'آدرس نامشخص';

    const flagEl = document.createElement('span');
    flagEl.className = 'ci-flag';
    flagEl.textContent = guessFlag((c.name || '') + ' ' + host);

    const info = document.createElement('div');
    info.className = 'ci-info';
    const nameEl = document.createElement('div');
    nameEl.className = 'ci-name'; nameEl.dir = 'ltr'; nameEl.textContent = c.name || 'Config';
    const protoEl = document.createElement('div');
    protoEl.className = 'ci-proto'; protoEl.dir = 'ltr';
    protoEl.textContent = (c.protocol || '').toUpperCase() + ' • ' + address;
    info.appendChild(nameEl); info.appendChild(protoEl);

    const actions = document.createElement('div');
    actions.className = 'ci-actions';
    const copyBtn = document.createElement('button');
    copyBtn.className = 'ci-btn'; copyBtn.textContent = 'کپی';
    copyBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      navigator.clipboard.writeText(c.raw || '').then(() => {
        copyBtn.classList.add('copied');
        const original = copyBtn.textContent;
        copyBtn.textContent = 'کپی شد ✓';
        setTimeout(() => { copyBtn.classList.remove('copied'); copyBtn.textContent = original; }, 1500);
      }).catch(() => showToast('❌ کپی ناموفق بود.'));
    });
    const qrBtn = document.createElement('button');
    qrBtn.className = 'ci-btn'; qrBtn.textContent = '🔳 QR';
    qrBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      showConfigQR(c.raw || '', c.name || c.protocol || 'Config');
    });
    actions.appendChild(copyBtn); actions.appendChild(qrBtn);

    item.appendChild(flagEl); item.appendChild(info); item.appendChild(actions);
    fragment.appendChild(item);
  });
  container.innerHTML = '';
  container.appendChild(fragment);
}
renderConfigList('');
document.getElementById('configSearch').addEventListener('input', e => renderConfigList(e.target.value));

function copyAllConfigs(btn) {
  if (CONFIGS.length === 0) { showToast('❌ کانفیگی موجود نیست.'); return; }
  const text = CONFIGS.map(c => c.raw || '').filter(Boolean).join('\n');
  copyText(text, '✅ همه‌ی کانفیگ‌ها کپی شد (' + CONFIGS.length + ' مورد)');
}
function downloadAllConfigs() {
  if (CONFIGS.length === 0) { showToast('❌ کانفیگی موجود نیست.'); return; }
  const text = CONFIGS.map(c => c.raw || '').filter(Boolean).join('\n');
  const blob = new Blob([text], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = 'configs.txt';
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
  showToast('⬇️ فایل کانفیگ‌ها دانلود شد.');
}

// ---------------- مودال QR تکی ----------------
function showConfigQR(raw, name) {
  if (!raw) { showToast('❌ این کانفیگ قابل نمایش QR نیست.'); return; }
  document.getElementById('qrModalTitle').textContent = name || 'QR Code';
  document.getElementById('qrModalImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' + encodeURIComponent(raw) + '&bgcolor=255-255-255&color=0-0-0&margin=1';
  document.getElementById('qrModal').classList.add('show');
}
function closeQrModal(e) {
  if (e.target.id === 'qrModal' || e.target.classList.contains('qr-modal-close')) {
    document.getElementById('qrModal').classList.remove('show');
  }
}

// ---------------- بروزرسانی زنده ----------------
function fmtBytesJs(bytes) {
  bytes = parseFloat(bytes) || 0;
  if (bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let pow = Math.floor(Math.log(bytes) / Math.log(1024));
  pow = Math.min(pow, units.length - 1);
  return (bytes / Math.pow(1024, pow)).toFixed(2) + ' ' + units[pow];
}
async function refreshData() {
  const label = document.getElementById('refreshBtnLabel');
  const original = label.textContent;
  label.textContent = '⏳ در حال بروزرسانی...';
  try {
    const res = await fetch('sub_refresh.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN })
    });
    const data = await res.json();
    if (!data.ok) { showToast('❌ ' + (data.error || 'بروزرسانی ناموفق بود.')); label.textContent = original; return; }

    CONFIGS = data.configs || [];
    renderConfigList(document.getElementById('configSearch').value);

    const totalBytes = parseFloat(data.total_bytes) || 0;
    const usedBytes   = parseFloat(data.used_bytes) || 0;
    const hasVolume   = totalBytes > 0;
    const usedPercent = hasVolume ? Math.min(100, Math.round((usedBytes / totalBytes) * 100)) : 0;

    document.getElementById('volText').textContent = hasVolume ? (fmtBytesJs(usedBytes) + ' / ' + fmtBytesJs(totalBytes)) : 'نامحدود';
    const volBar = document.getElementById('volBar');
    volBar.style.width = (hasVolume ? usedPercent : 3) + '%';
    volBar.classList.toggle('warn', usedPercent >= 85);

    const expireTs = parseInt(data.expire_ts, 10) || 0;
    const hasExpiry = expireTs > 0;
    const now = Math.floor(Date.now() / 1000);
    const daysLeft = hasExpiry ? Math.max(0, Math.ceil((expireTs - now) / 86400)) : null;
    document.getElementById('daysLeftText').textContent = hasExpiry ? daysLeft + ' روز' : 'نامحدود';
    document.getElementById('configCountText').textContent = data.total_configs;

    const expiredByTime = hasExpiry && expireTs <= now;
    const expiredByVolume = hasVolume && usedBytes >= totalBytes;
    const isActive = !expiredByTime && !expiredByVolume;
    const badge = document.getElementById('statusBadge');
    badge.classList.toggle('inactive', !isActive);
    badge.innerHTML = '<span class="d"></span>' + (isActive ? 'اشتراک فعال' : 'اشتراک منقضی شده');

    if (data.updated_at) document.getElementById('updatedAtText').textContent = data.updated_at;

    showToast('✅ اطلاعات بروزرسانی شد.');
  } catch (e) {
    showToast('❌ خطا در برقراری ارتباط.');
  } finally {
    label.textContent = original;
  }
}

// ---------------- افکت‌های تزئینی ----------------
(function initBeams(){
  const wrap = document.getElementById('lightCurtain');
  const count = 6;
  const palette = ['var(--accent)', 'var(--accent-2)'];
  for (let i = 0; i < count; i++){
    const b = document.createElement('div');
    b.className = 'beam';
    const leftPct = (i / (count - 1)) * 100;
    const rot = (Math.random() * 10 - 5).toFixed(1);
    const dur = (7 + Math.random() * 6).toFixed(1);
    const delay = (Math.random() * 5).toFixed(1);
    const color = palette[i % palette.length];
    b.style.left = leftPct + '%';
    b.style.setProperty('--rot', rot + 'deg');
    b.style.animationDuration = dur + 's';
    b.style.animationDelay = delay + 's';
    b.style.background = `linear-gradient(180deg, ${color} 0%, transparent 75%)`;
    wrap.appendChild(b);
  }
})();
(function initParticles(){
  const wrap = document.getElementById('particles');
  const count = 20;
  for (let i = 0; i < count; i++){
    const p = document.createElement('div');
    p.className = 'particle';
    const left = Math.random() * 100;
    const duration = 9 + Math.random() * 10;
    const delay = Math.random() * 12;
    const size = 2 + Math.random() * 3;
    p.style.left = left + 'vw';
    p.style.width = size + 'px';
    p.style.height = size + 'px';
    p.style.animationDuration = duration + 's';
    p.style.animationDelay = delay + 's';
    wrap.appendChild(p);
  }
})();
</script>
</body>
</html>
<?php endif; ?>
