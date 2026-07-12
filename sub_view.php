<?php
// sub_view.php - نمایش عمومی وضعیت اشتراک (لینک قابل اشتراک‌گذاری) - نسخه‌ی خفن‌تر با تم بنفش/نئونی
declare(strict_types=1);
require_once 'config.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));

function renderNotFound(): void {
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>یافت نشد</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css">
        <style>
            *{ box-sizing:border-box; }
            body{
                font-family:'Vazirmatn',Tahoma,sans-serif; background:#040308; color:#eee;
                display:flex; align-items:center; justify-content:center; height:100vh; margin:0; text-align:center;
                background-image:
                    radial-gradient(circle at 15% 20%, rgba(168,85,247,.25), transparent 45%),
                    radial-gradient(circle at 85% 80%, rgba(236,72,153,.18), transparent 45%);
            }
            .box{
                background:rgba(24,16,36,.55); border:1px solid rgba(216,180,254,.18); border-radius:20px;
                padding:44px 32px; max-width:340px; backdrop-filter:blur(16px);
                box-shadow:0 0 40px rgba(168,85,247,.15), inset 0 0 30px rgba(168,85,247,.05);
            }
            .icon{ font-size:44px; margin-bottom:12px; filter:drop-shadow(0 0 14px rgba(217,70,239,.6)); }
            h2{ margin:0 0 8px; font-size:16px; background:linear-gradient(90deg,#e9d5ff,#f0abfc); -webkit-background-clip:text; background-clip:text; color:transparent; }
            p{ color:#a78bc4; font-size:13px; line-height:1.8; }
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

$stmt = $pdo->prepare("SELECT * FROM extractions WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) { http_response_code(404); renderNotFound(); exit; }

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
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>وضعیت اشتراک شما</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css">
<style>
    :root{
        --bg:#050107;
        --panel:rgba(30,14,46,.55);
        --panel-solid:#160a22;
        --border:rgba(216,180,254,.14);
        --border-strong:rgba(217,70,239,.4);
        --primary:#a855f7;
        --primary2:#d946ef;
        --accent3:#6d28d9;
        --neon:#e879f9;
        --success:#34d399;
        --danger:#fb7185;
        --muted:#b09ac9;
        --text:#f3e8ff;
    }
    *{ box-sizing:border-box; }
    body{
        font-family:'Vazirmatn',Tahoma,sans-serif; background:var(--bg); color:var(--text);
        margin:0; padding:24px 16px 60px; min-height:100vh; position:relative; overflow-x:hidden;
        background-image:
            radial-gradient(circle at 10% 10%, rgba(168,85,247,.22), transparent 40%),
            radial-gradient(circle at 90% 15%, rgba(217,70,239,.14), transparent 40%),
            radial-gradient(circle at 50% 100%, rgba(109,40,217,.22), transparent 45%);
    }
    .bg-blob{ position:fixed; border-radius:50%; filter:blur(120px); opacity:.35; z-index:-1; pointer-events:none; animation:drift 18s ease-in-out infinite; }
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
        box-shadow:0 0 0 1px rgba(255,255,255,.08), 0 0 35px rgba(217,70,239,.55), 0 10px 30px rgba(109,40,217,.5);
        animation:pulseGlow 2.6s ease-in-out infinite;
    }
    @keyframes pulseGlow{
        0%,100%{ box-shadow:0 0 0 1px rgba(255,255,255,.08), 0 0 25px rgba(217,70,239,.4), 0 10px 30px rgba(109,40,217,.4); }
        50%{ box-shadow:0 0 0 1px rgba(255,255,255,.12), 0 0 48px rgba(217,70,239,.75), 0 10px 40px rgba(109,40,217,.6); }
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
        background:linear-gradient(135deg, rgba(216,180,254,.25), rgba(217,70,239,0) 40%, rgba(217,70,239,.15) 100%);
        -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none;
    }
    .card-title{ display:flex; align-items:center; justify-content:space-between; font-size:13px; color:var(--muted); font-weight:700; margin-bottom:12px; gap:8px; }
    .card-percent{ font-size:15px; font-weight:800; white-space:nowrap; color:#f0abfc; }
    .bar-bg{ width:100%; height:9px; border-radius:99px; background:rgba(255,255,255,.06); overflow:hidden; margin-bottom:12px; }
    .bar-fill{ height:100%; border-radius:99px; background:linear-gradient(90deg, var(--primary), var(--neon)); transition:width .5s; box-shadow:0 0 12px rgba(217,70,239,.6); }
    .bar-fill.warn{ background:linear-gradient(90deg, var(--danger), #f59e0b); box-shadow:0 0 12px rgba(251,113,133,.6); }
    .card-foot{ display:flex; justify-content:space-between; font-size:12.5px; color:var(--muted); }
    .card-foot b{ color:var(--text); }

    .protocols{ display:flex; flex-wrap:wrap; gap:8px; }
    .proto-pill{
        font-size:12px; padding:6px 12px; border-radius:999px; background:rgba(217,70,239,.12); color:#f0abfc;
        border:1px solid rgba(217,70,239,.3); font-weight:700; box-shadow:0 0 10px rgba(217,70,239,.12);
    }

    .actions{ display:flex; flex-direction:column; gap:10px; }
    .action-btn{
        display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-radius:13px;
        background:rgba(255,255,255,.03); border:1px solid var(--border); cursor:pointer; transition:.18s;
        font-family:inherit; color:var(--text); font-size:13.5px; font-weight:700; width:100%; text-align:right;
    }
    .action-btn:hover{ border-color:var(--border-strong); transform:translateY(-1px); box-shadow:0 6px 22px rgba(217,70,239,.18); }
    .action-btn:disabled{ opacity:.5; cursor:not-allowed; transform:none; }
    .action-btn .copy-tag{ font-size:11px; color:#f0abfc; background:rgba(217,70,239,.14); padding:4px 10px; border-radius:8px; white-space:nowrap; }
    .action-btn.copied .copy-tag{ color:#5eead4; background:rgba(52,211,153,.14); }
    .action-btn.primary-action{
        background:linear-gradient(135deg, rgba(168,85,247,.22), rgba(217,70,239,.22));
        border-color:rgba(217,70,239,.4); box-shadow:0 0 20px rgba(217,70,239,.15);
    }

    .spin{ display:inline-block; width:13px; height:13px; border-radius:50%; border:2px solid rgba(255,255,255,.25); border-top-color:#fff; animation:spin 0.7s linear infinite; vertical-align:-2px; }
    @keyframes spin{ to{ transform:rotate(360deg); } }

    .qr-wrap{ text-align:center; }
    .qr-wrap img{ width:200px; height:200px; border-radius:14px; background:#fff; padding:10px; box-shadow:0 0 30px rgba(217,70,239,.25); }

    .search-box{
        width:100%; padding:11px 14px; border-radius:11px; border:1px solid var(--border); background:rgba(12,6,20,.6);
        color:var(--text); font-size:13px; font-family:inherit; margin-bottom:12px;
    }
    .search-box::placeholder{ color:#8a76a8; }
    .search-box:focus{ outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(168,85,247,.15); }
    .config-list{ display:flex; flex-direction:column; gap:8px; max-height:420px; overflow-y:auto; padding-inline-end:4px; }
    .config-item{
        display:flex; align-items:center; gap:10px; background:rgba(255,255,255,.03); border:1px solid var(--border);
        border-radius:12px; padding:10px 12px; transition:.15s;
    }
    .config-item:hover{ border-color:var(--border-strong); box-shadow:0 4px 16px rgba(217,70,239,.15); }
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
        font-size:11px; color:#f0abfc; background:rgba(217,70,239,.14); padding:6px 10px; border-radius:8px;
        border:1px solid transparent; cursor:pointer; font-family:inherit; transition:.15s; white-space:nowrap;
    }
    .config-item .ci-btn:hover{ border-color:rgba(217,70,239,.4); transform:translateY(-1px); }
    .config-item .ci-btn.ci-copy.copied{ color:#5eead4; background:rgba(52,211,153,.14); }
    .config-item .ci-btn.ci-qr{ color:#93c5fd; background:rgba(59,130,246,.14); }
    .config-item .ci-btn.ci-qr:hover{ border-color:rgba(59,130,246,.4); }
    .config-empty{ text-align:center; color:var(--muted); font-size:12.5px; padding:20px; }

    .toast{
        position:fixed; bottom:22px; left:50%; transform:translateX(-50%) translateY(10px); background:rgba(22,10,34,.92);
        backdrop-filter:blur(14px); border:1px solid var(--border-strong); padding:12px 22px; border-radius:13px; font-size:13px;
        opacity:0; transition:.3s; pointer-events:none; z-index:999; box-shadow:0 10px 34px rgba(217,70,239,.25); font-weight:600;
    }
    .toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

    .footer{ text-align:center; color:var(--muted); font-size:11.5px; margin-top:22px; line-height:1.9; }
    .footer b{ background:linear-gradient(90deg,#f0abfc,#c4b5fd); -webkit-background-clip:text; background-clip:text; color:transparent; }

    ::-webkit-scrollbar{ width:6px; }
    ::-webkit-scrollbar-thumb{ background:rgba(217,70,239,.35); border-radius:99px; }

    .qr-modal{
        position:fixed; inset:0; background:rgba(4,2,8,.78); backdrop-filter:blur(8px);
        display:none; align-items:center; justify-content:center; z-index:1000; padding:20px;
    }
    .qr-modal.show{ display:flex; }
    .qr-modal-inner{
        background:var(--panel-solid); border:1px solid var(--border-strong); border-radius:20px; padding:24px;
        max-width:300px; width:100%; text-align:center; box-shadow:0 0 50px rgba(217,70,239,.3);
    }
    .qr-modal-title{
        font-size:13px; font-weight:700; margin-bottom:16px; color:var(--text);
        direction:ltr; unicode-bidi:plaintext; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .qr-modal-inner img{ width:220px; height:220px; border-radius:14px; background:#fff; padding:10px; box-shadow:0 0 24px rgba(217,70,239,.2); }
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
    container.innerHTML = '';

    const filtered = CONFIGS.filter(c => {
        if (!q) return true;
        return (c.name || '').toLowerCase().includes(q) || (c.protocol || '').toLowerCase().includes(q);
    });

    document.getElementById('configListCount').textContent = filtered.length + ' از ' + CONFIGS.length;

    if (filtered.length === 0) {
        container.innerHTML = '<div class="config-empty">کانفیگی با این مشخصات یافت نشد.</div>';
        return;
    }

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

        container.appendChild(item);
    });
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