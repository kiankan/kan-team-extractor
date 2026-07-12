<?php
// bot.php - نسخه نهایی 2.3 | چیدمان و متن دکمه‌ها از پنل وب + تنظیمات پنل وب در ربات
declare(strict_types=1);

require_once 'config.php';

// ----------------------------------------------------
// سیستم استخراج فوق‌پیشرفته و ایزوله - سازگار با تمام فرمت‌ها
// ----------------------------------------------------
class AdvancedSubExtractor {

    private ?int $lastHttpCode = null;
    private ?string $lastCurlError = null;
    private ?string $lastRawSnippet = null;

    private const PROTOCOLS = [
        'vless', 'vmess', 'trojan', 'ss', 'ssr',
        'hy2', 'hysteria', 'hysteria2',
        'tuic', 'wireguard', 'wg',
        'naive', 'brook', 'juicity', 'custom', 'json'
    ];

    private const PROTOCOL_REGEX = '/(vless|vmess|trojan|ssr|ss|hysteria2|hysteria|hy2|tuic|wireguard|wg|naive|brook|juicity|custom|json):\/\/[^\s\'"<>\[\]]+/i';

    public function extractSubscription(string $url): ?array {
        $response = $this->fetchUrl($url);
        if ($response === null) {
            return ['total_configs' => 0, 'protocols' => [], 'configs_list' => [], 'error' => 'خطا در دریافت لینک یا بلاک شدن توسط سرور', 'debug' => $this->getDebugInfo()];
        }

        $configs = $this->parseAny($response);

        // بعضی سرورها (مثل Cloudflare Workers) درخواست با User-Agent کلاینت وی‌پی‌ان رو بلاک می‌کنن
        // اگه چیزی پیدا نشد، یک‌بار دیگه با هدر یک مرورگر معمولی امتحان می‌کنیم.
        if (empty($configs)) {
            $response2 = $this->fetchUrl($url, true);
            if ($response2 !== null && $response2 !== $response) {
                $configs2 = $this->parseAny($response2);
                if (!empty($configs2)) $configs = $configs2;
            }
        }

        $result = $this->buildResult($configs);
        if ($result['total_configs'] === 0) {
            $result['debug'] = $this->getDebugInfo();
        }
        return $result;
    }

    private function parseAny(string $response): array {
        if ($this->isClashYaml($response)) {
            return $this->parseClashYaml($response);
        }
        if ($this->isSingBoxJson($response)) {
            return $this->parseSingBoxJson($response);
        }
        return $this->parseRawOrBase64($response);
    }

    public function getDebugInfo(): array {
        return [
            'http_code'  => $this->lastHttpCode,
            'curl_error' => $this->lastCurlError,
            'snippet'    => $this->lastRawSnippet,
        ];
    }

    public function publicSafeBase64Decode(string $str): ?string {
        return $this->safeBase64Decode($str);
    }

    private function fetchUrl(string $url, bool $useBrowserUA = false): ?string {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => $useBrowserUA
                ? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
                : 'v2rayNG/1.8.18',
            CURLOPT_ENCODING       => "", 
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*', 
                'Accept-Encoding: gzip, deflate',
                'Connection: keep-alive'
            ],
        ]);
        
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->lastHttpCode  = (int)$httpCode;
        $this->lastCurlError = $curlError !== '' ? $curlError : null;

        if ($response === false || $httpCode === 0) return null;
        
        if (function_exists('gzdecode') && substr((string)$response, 0, 2) === "\x1f\x8b") {
            $response = gzdecode($response) ?: $response;
        }

        $this->lastRawSnippet = mb_substr((string)$response, 0, 200, 'UTF-8');
        
        return $response;
    }

    private function isClashYaml(string $content): bool {
        return (bool) preg_match('/^(proxies:|port:|mixed-port:|allow-lan:|mode:|log-level:|external-controller:)/mi', $content);
    }

    private function isSingBoxJson(string $content): bool {
        $trimmed = ltrim($content);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) return false;
        
        $data = json_decode($trimmed, true);
        if (!is_array($data)) return false;

        if (isset($data[0]) && is_array($data[0])) {
            return isset($data[0]['outbounds']) || isset($data[0]['inbounds']) || isset($data[0]['proxies']);
        }
        return isset($data['outbounds']) || isset($data['inbounds']) || isset($data['proxies']);
    }

    private function parseClashYaml(string $content): array {
        $configs = [];
        if (!preg_match('/^proxies:\s*\n((?:[ \t]+-[^\n]*\n(?:[ \t][^\n]*\n)*)*)/mi', $content, $m)) {
            return [];
        }

        $block = $m[1];
        preg_match_all('/[ \t]*-\s+name:\s*["\']?(.+?)["\']?\n((?:[ \t]+\S[^\n]*\n)*)/i', $block, $entries, PREG_SET_ORDER);

        foreach ($entries as $entry) {
            $name   = trim($entry[1]);
            $body   = $entry[2];
            $type   = $this->yamlVal($body, 'type');
            $server = $this->yamlVal($body, 'server');
            $port   = $this->yamlVal($body, 'port');

            if (!$type || !$server) continue;

            $proto = $this->normalizeProtocol($type);
            $raw   = $this->clashToRaw($proto, $name, $server, $port, $body);

            $configs[] = [
                'name'     => $name,
                'protocol' => strtoupper($proto),
                'raw'      => $raw,
                'server'   => $server,
                'port'     => $port,
            ];
        }
        return $configs;
    }

    private function yamlVal(string $block, string $key): string {
        if (preg_match('/^\s+' . preg_quote($key, '/') . ':\s*["\']?([^\s\'"#\n]+)["\']?/mi', $block, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function clashToRaw(string $proto, string $name, string $server, string $port, string $body): string {
        $encoded = base64_encode("{$proto}:{$server}:{$port}");
        return "{$proto}://{$encoded}#{$name}";
    }

    private function parseSingBoxJson(string $content): array {
        $configs = [];
        $data    = json_decode($content, true);
        
        $outbounds = [];
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $item) {
                if (!empty($item['outbounds']))  $outbounds = array_merge($outbounds, $item['outbounds']);
                if (!empty($item['proxies']))    $outbounds = array_merge($outbounds, $item['proxies']);
                if (!empty($item['inbounds']))   $outbounds = array_merge($outbounds, $item['inbounds']);
            }
        } else {
            if (!empty($data['outbounds'])) $outbounds = array_merge($outbounds, $data['outbounds']);
            if (!empty($data['proxies']))   $outbounds = array_merge($outbounds, $data['proxies']);
            if (!empty($data['inbounds']))  $outbounds = array_merge($outbounds, $data['inbounds']);
        }

        if (empty($outbounds)) return [];

        foreach ($outbounds as $out) {
            $type   = $out['type'] ?? $out['protocol'] ?? '';
            $tag    = $out['tag'] ?? $out['name'] ?? '';
            $server = $out['server'] ?? '';
            $port   = (string)($out['server_port'] ?? $out['port'] ?? '');

            if (empty($server)) {
                if (isset($out['settings']['vnext'][0])) {
                    $server = $out['settings']['vnext'][0]['address'] ?? '';
                    $port   = (string)($out['settings']['vnext'][0]['port'] ?? '');
                } elseif (isset($out['settings']['servers'][0])) {
                    $server = $out['settings']['servers'][0]['address'] ?? $out['settings']['servers'][0]['email'] ?? '';
                    $port   = (string)($out['settings']['servers'][0]['port'] ?? '');
                }
            }

            if (!$server || !$type) continue;
            if (in_array(strtolower($type), ['block', 'direct', 'dns', 'freedom', 'blackhole', 'dokodemo-door', 'socks', 'http'])) continue;

            $proto = $this->normalizeProtocol($type);
            if ($proto === 'unknown') continue;
            
            if (empty($tag) || in_array(strtolower($tag), ['proxy', 'node', 'outbound', 'config'])) {
                $tag = !empty($server) ? $server : 'Config';
            }
            
            $raw = $this->singboxToRaw($proto, $tag, $server, $port, $out);

            $configs[] = [
                'name'     => $tag,
                'protocol' => strtoupper($proto),
                'raw'      => $raw,
                'server'   => $server,
                'port'     => $port,
            ];
        }
        return $configs;
    }

    private function singboxToRaw(string $proto, string $name, string $server, string $port, array $out): string {
        $encoded = base64_encode("{$proto}:{$server}:{$port}");
        return "{$proto}://{$encoded}#{$name}";
    }

    private function parseRawOrBase64(string $content): array {
        $configs     = [];
        $uniqueCheck = [];

        // بعضی ساب‌ها (مثل ساب‌های چند-IP کلادفلر) کل بدنه‌شون Base64 و شامل
        // صدها کانفیگه؛ اگه توی متن خام هیچ پروتکل خامی پیدا نشه، دیکد می‌کنیم.
        if (!preg_match(self::PROTOCOL_REGEX, $content)) {
            $decoded = $this->safeBase64Decode($content);
            if ($decoded && preg_match(self::PROTOCOL_REGEX, $decoded)) {
                $content = $decoded;
            }
        }

        // برای ساب‌های خیلی حجیم (صدها کانفیگ)، اجرای یک regex سنگین روی کل متن
        // ممکنه به سقف pcre.backtrack_limit/recursion_limit بخوره و preg_match_all
        // بی‌سروصدا false برگردونه (یعنی خروجی صفر کانفیگ با اینکه ساب معتبره).
        // برای رفع این مشکل: سقف PCRE رو بالا می‌بریم و مهم‌تر از اون، به‌جای یک
        // اجرای عظیم روی کل متن، خط‌به‌خط پردازش می‌کنیم (خیلی سبک‌تر و امن‌تر).
        @ini_set('pcre.backtrack_limit', '20000000');
        @ini_set('pcre.recursion_limit', '20000000');

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [$content];
        if (count($lines) <= 1) {
            // بعضی سرورها خط‌ها رو با فاصله جدا می‌کنن نه newline
            $lines = preg_split('/\s+/', $content) ?: [$content];
        }

        foreach ($lines as $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === '' || str_starts_with($rawLine, '#') || str_starts_with($rawLine, '//')) continue;

            if (!preg_match_all(self::PROTOCOL_REGEX, $rawLine, $matches)) continue;

            foreach ($matches[0] ?? [] as $line) {
                $line = rtrim(trim($line), '.,;،');
                if (empty($line) || isset($uniqueCheck[$line])) continue;

                $server = '';
                $port   = '';
                if (preg_match('/:\/\/[^@]*@?([^:\/?#\s]+):(\d{1,5})/i', $line, $sp)) {
                    $server = $sp[1];
                    $port   = $sp[2];
                }

                // خیلی از ساب‌های رایگان یه کانفیگ ساختگی/تبلیغاتی با آدرس
                // 0.0.0.0 یا پورت 1 دارن که فقط برای نمایش یه پیام هشدار توی
                // نام کانفیگ استفاده میشه و اتصال واقعی نداره؛ نادیده می‌گیریم.
                if ($server === '0.0.0.0' || $port === '0' || $port === '1') continue;

                $uniqueCheck[$line] = true;

                $lowerLine = strtolower($line);
                $proto     = 'unknown';
                $name      = 'Config';

                foreach (self::PROTOCOLS as $p) {
                    if (str_starts_with($lowerLine, $p . '://')) {
                        $proto = $p;
                        break;
                    }
                }

                $proto = $this->normalizeProtocol($proto);

                if ($proto === 'vmess') {
                    $name = $this->extractVmessName($line);
                } elseif ($proto === 'custom' || $proto === 'json') {
                    $name = $this->extractSmartName($line);
                } else {
                    $name = $this->extractNameFromHash($line);
                }

                $configs[] = [
                    'name'     => $name ?: 'Config',
                    'protocol' => strtoupper($proto),
                    'raw'      => $line,
                    'server'   => $server,
                    'port'     => $port,
                ];
            }
        }
        return $configs;
    }

    private function normalizeProtocol(string $proto): string {
        $proto = strtolower(trim($proto));
        return match($proto) {
            'vless'                    => 'vless',
            'vmess'                    => 'vmess',
            'trojan'                   => 'trojan',
            'ss', 'shadowsocks'        => 'shadowsocks',
            'ssr', 'shadowsocksr'      => 'shadowsocksr',
            'hy2', 'hysteria2'         => 'hysteria2',
            'hysteria'                 => 'hysteria',
            'tuic'                     => 'tuic',
            'wg', 'wireguard'          => 'wireguard',
            'naive', 'naiveproxy'      => 'naive',
            'brook'                    => 'brook',
            'juicity'                  => 'juicity',
            'custom'                   => 'custom',
            'json'                     => 'json',
            default                    => 'unknown',
        };
    }

    private function buildResult(array $configs): array {
        $protocols = [];
        foreach ($configs as $c) {
            $p = strtolower($c['protocol']);
            $protocols[$p] = ($protocols[$p] ?? 0) + 1;
        }

        foreach (['vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria2', 'tuic', 'wireguard', 'custom', 'json', 'other'] as $key) {
            $protocols[$key] = $protocols[$key] ?? 0;
        }
        if (!empty($protocols['unknown'])) {
            $protocols['other'] += $protocols['unknown'];
            unset($protocols['unknown']);
        }

        return [
            'total_configs' => count($configs),
            'protocols'     => $protocols,
            'configs_list'  => $configs,
        ];
    }

    private function safeBase64Decode(string $str): ?string {
        $trimmed = trim($str);
        
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return null; 
        }

        $clean = preg_replace('/[\s\r\n]+/', '', $str);
        $clean = strtr($clean, '-_', '+/');
        $clean = preg_replace('/[^a-zA-Z0-9\+\/\=]/', '', $clean);
        
        if (strlen($clean) < (strlen($str) * 0.6)) {
            return null;
        }
        
        $pad = strlen($clean) % 4;
        if ($pad) $clean .= str_repeat('=', 4 - $pad);
        
        $decoded = base64_decode($clean, true);
        if ($decoded === false) {
            $decoded = base64_decode($clean); 
        }
        
        return ($decoded !== false) ? $decoded : null;
    }

    private function extractNameFromHash(string $link): string {
        $parts = explode('#', $link, 2);
        return (count($parts) === 2 && $parts[1] !== '') ? urldecode(trim($parts[1])) : '';
    }

    private function extractVmessName(string $link): string {
        $b64 = explode('#', explode('?', substr($link, 8))[0])[0];
        $dec = $this->safeBase64Decode($b64);
        if ($dec) {
            $json = json_decode($dec, true);
            if (isset($json['ps']) && $json['ps'] !== '') return (string)$json['ps'];
            if (isset($json['add']))                       return (string)$json['add'];
        }
        return 'VMess Config';
    }
    
    private function extractSmartName(string $link): string {
        $hashName = $this->extractNameFromHash($link);
        if ($hashName !== '') {
            return $hashName;
        }
        
        $parts = explode('://', $link, 2);
        if (count($parts) === 2) {
            $payload = explode('#', $parts[1])[0];
            $payload = explode('?', $payload)[0];
            
            $decoded = $this->safeBase64Decode($payload);
            if ($decoded) {
                $json = json_decode($decoded, true);
                if (is_array($json)) {
                    return (string)($json['ps'] ?? $json['remark'] ?? $json['name'] ?? $json['tag'] ?? 'Custom Config');
                }
            }
            
            $urldecoded = urldecode($payload);
            $json = json_decode($urldecoded, true);
            if (is_array($json)) {
                return (string)($json['ps'] ?? $json['remark'] ?? $json['name'] ?? $json['tag'] ?? 'Custom Config');
            }
        }
        return ucfirst($parts[0] ?? 'custom') . ' Config';
    }
}

date_default_timezone_set('Asia/Tehran');
set_time_limit(0);
ignore_user_abort(true);

$content = file_get_contents("php://input");
$update = json_decode($content, true);

$isCronRequest = (isset($_GET['action']) && $_GET['action'] === 'cron_backup');

// نکته: مسیر قدیمیِ bot.php?setup=1&admin_id=... به‌طور کامل حذف شد؛ چون بدون
// هیچ احراز هویتی هر بازدیدکننده‌ای می‌توانست خودش را ادمین کامل ربات کند.
// وبهوک از طریق installer/index.php تنظیم می‌شود و ADMIN_ID داخل config.php
// (که فقط مالک هاست موقع نصب تعیین می‌کند) به‌تنهایی دسترسی مدیر کل را می‌دهد،
// پس این مسیر اضافه و خطرناک بود و نیازی به وجودش نیست.
// ----------------------------------------------------

if (!$update && !$isCronRequest) {
    exit;
}

if (php_sapi_name() !== 'cli' && !$isCronRequest) {
    http_response_code(200);
    header("Connection: close");
    header("Content-Length: 0");
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function gregorian_to_jalali($gy, $gm, $gd, $mod = '') {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + 33 * ((int)($days / 12053)); $days %= 12053;
    $jy += 4 * ((int)($days / 1461)); $days %= 1461;
    if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + (int)($days / 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + (int)(($days - 186) / 30); $jd = 1 + (($days - 186) % 30); }
    return ($mod === '') ? [$jy, $jm, $jd] : $jy . $mod . $jm . $mod . $jd;
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
    $search = ['Y', 'm', 'd', 'H', 'i', 's'];
    $replace = [$jy, $jm, $jd, $h, $i, $s];
    return str_replace($search, $replace, $format);
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (`user_id` BIGINT PRIMARY KEY, `points` INT DEFAULT 0, `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_states` (`user_id` BIGINT PRIMARY KEY, `state` VARCHAR(50) NOT NULL, `data` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (`setting_key` VARCHAR(50) PRIMARY KEY, `setting_value` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (`admin_id` BIGINT PRIMARY KEY, `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
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

    $cols = [
        'joined_at'  => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'is_blocked' => 'TINYINT(1) DEFAULT 0',
        'is_admin'   => 'TINYINT(1) DEFAULT 0'
    ];
    foreach ($cols as $col => $def) {
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE '$col'")->fetch())
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `$col` $def;");
    }
} catch (PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    exit;
}

function getSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function setSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

// توکن کرون مخصوص همین نصب: از روی BOT_TOKEN شما ساخته می‌شود (نه یک مقدار
// ثابت که در همه‌ی نصب‌های این کد یکسان باشد). همین مقدار را در آدرس کرون‌جاب
// هاست به‌عنوان token= قرار دهید؛ مقدارش در تب «⏱ کرون» پنل وب هم نمایش داده می‌شود.
function getCronToken(): string {
    return substr(hash('sha256', BOT_TOKEN . '|cron_backup_secret_v1'), 0, 24);
}

function createDbBackupFile($pdo) {
    $tables   = ['users', 'user_states', 'settings', 'admins'];
    $sqlDump  = "-- DB Backup\n-- Time: " . date('Y-m-d H:i:s') . "\n\n";
    $sqlDump .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll();
            if (count($rows) > 0) {
                foreach ($rows as $row) {
                    $keys = array_keys($row);
                    $vals = array_map(function ($v) use ($pdo) {
                        return is_null($v) ? 'NULL' : $pdo->quote((string)$v);
                    }, array_values($row));
                    $sqlDump .= "INSERT IGNORE INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $vals) . ");\n";
                }
                $sqlDump .= "\n";
            }
        } catch (Exception $e) { }
    }
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $fileName = 'db_backup_' . date('Y-m-d_H-i-s') . '.sql';
    file_put_contents($fileName, $sqlDump);
    return $fileName;
}

// ----------------------------------------------------
// ایمپورت هوشمند بک‌آپ دیتابیس (بازیابی امن از فایل .sql)
// ----------------------------------------------------

// جداکننده‌ی دستورات SQL که به‌درستی داخل رشته‌های تک/دابل‌کوت را نادیده می‌گیرد
// (برخلاف split ساده بر اساس newline، این روش با مقادیری که شامل \n واقعی هستند هم درست کار می‌کند)
function splitSqlStatements(string $sql): array {
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $inString   = false;
    $quoteChar  = '';

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        if ($inString) {
            $current .= $char;
            if ($char === '\\') {
                if ($i + 1 < $len) { $current .= $sql[++$i]; }
                continue;
            }
            if ($char === $quoteChar) { $inString = false; }
            continue;
        }

        if ($char === "'" || $char === '"') {
            $inString  = true;
            $quoteChar = $char;
            $current  .= $char;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') $statements[] = $trimmed;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') $statements[] = $trimmed;

    return $statements;
}

// ایمپورت امن: فقط دستورات INSERT روی جداول شناخته‌شده اجرا می‌شوند، همیشه با IGNORE
// (تا رکورد تکراری خطا ندهد یا داده‌ی فعلی را دور نزند)، و هر دستور خطرناک دیگری
// (DROP/DELETE/UPDATE/ALTER و ...) بی‌سروصدا نادیده گرفته می‌شود.
function importDbBackupFile($pdo, string $sqlContent): array {
    $result = ['valid' => false, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'total' => 0];

    // همه‌ی جدول‌های واقعی پروژه؛ یعنی بک‌آپ کامل واقعاً همه‌چیز رو برمی‌گردونه
    $allowedTables = ['users', 'user_states', 'settings', 'admins', 'extractions', 'backup_imports', 'panel_login_throttle'];

    if (stripos($sqlContent, 'INSERT') === false || stripos($sqlContent, 'INTO') === false) {
        return $result; // فایل معتبر نیست (حتی یک دستور INSERT هم ندارد)
    }

    // حذف خطوط کامنت (خطوطی که با -- شروع می‌شوند)
    $lines = explode("\n", $sqlContent);
    $cleanLines = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    $statements = splitSqlStatements($cleanSql);
    if (empty($statements)) return $result;

    $result['valid'] = true;

    foreach ($statements as $stmtTrim) {
        if ($stmtTrim === '') continue;

        // دستورات بی‌خطر تنظیمات کاراکترست/فارین‌کی مجاز هستند
        if (preg_match('/^SET\s+(NAMES|FOREIGN_KEY_CHECKS)/i', $stmtTrim)) {
            try { $pdo->exec($stmtTrim); } catch (\Throwable $e) { /* نادیده گرفته شود */ }
            continue;
        }

        // INSERT INTO `table` (`col1`,`col2`,...) VALUES (...)
        if (preg_match('/^INSERT\s+(IGNORE\s+)?INTO\s+`?([a-zA-Z_]+)`?\s*\(([^)]*)\)\s*VALUES\s*(.+)$/is', $stmtTrim, $m)) {
            $table = strtolower($m[2]);
            if (!in_array($table, $allowedTables, true)) {
                continue; // جدول ناشناخته/غیرمجاز برای امنیت رد می‌شود
            }

            $result['total']++;

            $cols = array_map(static function ($c) {
                return trim($c, " `\t\n\r\0\x0B");
            }, explode(',', $m[3]));

            // ستون‌هایی که واقعاً وجود دارند تشخیص داده می‌شوند تا با اسکیمای فعلی این نصب هم سازگار بماند
            try {
                $existingCols = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
            } catch (\Throwable $e) {
                $existingCols = $cols; // اگه به هر دلیلی نشد، فرض می‌کنیم همه‌ی ستون‌ها معتبرند
            }

            $updateParts = [];
            foreach ($cols as $c) {
                if ($c === '') continue;
                if (!in_array($c, $existingCols, true)) continue; // ستونی که دیگه وجود نداره، نادیده گرفته می‌شود
                $updateParts[] = "`$c` = VALUES(`$c`)";
            }

            $safeStmt = rtrim($stmtTrim, "; \t\n\r\0\x0B");
            if (!empty($updateParts)) {
                $safeStmt = preg_replace('/^INSERT\s+(IGNORE\s+)?INTO/i', 'INSERT INTO', $safeStmt, 1);
                $safeStmt .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);
            } else {
                // اگه هیچ ستونی برای آپدیت نبود (مثلاً جدول فقط یک ستون کلید دارد)، مثل قبل با IGNORE درج می‌شود
                $safeStmt = preg_replace('/^INSERT\s+(IGNORE\s+)?INTO/i', 'INSERT IGNORE INTO', $safeStmt, 1);
            }

            try {
                // در MySQL/MariaDB خروجی exec برای «INSERT ... ON DUPLICATE KEY UPDATE» این‌طور است:
                // 1 = رکورد کاملاً جدید درج شد | 2 = رکورد از قبل بود و مقادیرش بروزرسانی شد | 0 = از قبل بود و چیزی تغییر نکرد
                $affected = $pdo->exec($safeStmt);
                if ($affected === 1)      $result['inserted']++;
                elseif ($affected === 2)  $result['updated']++;
                else                      $result['unchanged']++;
            } catch (\Throwable $e) {
                $result['failed']++;
            }
            continue;
        }

        // INSERT INTO `table` VALUES (...)  (بدون فهرست ستون‌ها؛ نمی‌توان upsert امن ساخت، پس فقط IGNORE می‌شود)
        if (preg_match('/^INSERT\s+(IGNORE\s+)?INTO\s+`?([a-zA-Z_]+)`?\s+VALUES\s*(.+)$/is', $stmtTrim, $m)) {
            $table = strtolower($m[2]);
            if (!in_array($table, $allowedTables, true)) continue;

            $result['total']++;
            $safeStmt = preg_replace('/^INSERT\s+(IGNORE\s+)?INTO/i', 'INSERT IGNORE INTO', $stmtTrim, 1);
            try {
                $affected = $pdo->exec($safeStmt);
                if ($affected && $affected > 0) $result['inserted']++;
                else $result['unchanged']++;
            } catch (\Throwable $e) {
                $result['failed']++;
            }
            continue;
        }

        // سایر دستورات (CREATE/DROP/UPDATE/DELETE/ALTER و ...) به‌طور کامل نادیده گرفته می‌شوند
    }

    return $result;
}

// نکته: تابع بکاپ سورس (createSourceBackupFile) به‌طور کامل از پروژه حذف شد.
// بکاپ‌گیری فقط از دیتابیس انجام می‌شود (createDbBackupFile).

function removeBotEmojis($text) {
    $emojis = ['🔍','🗂','📞','👨‍💼','📊','👤','👥','📢','⚙️','🔐','📝','✨','💾','📦','✍️','🔓','🔒','🔻','🤖','🔙','✅','❌','📥','🌐','🏠','🔄','📄','🔲','🔋','⏳','📌','📡','🗣','🟢','🔴','🚪','➕','🗑','👨‍💻','📂','📁','⬅️','➡️','👮‍♂️','🛠','💳','🔊','🔇','🌟','🎨','⏱','🔓','💤'];
    return trim(str_replace($emojis, '', $text));
}

function getPremiumSettings() {
    global $pdo;
    static $premium = null;
    if ($premium === null) {
        $emojisDecoded = json_decode(getSetting($pdo, 'premium_emojis', '{}'), true);
        $colorsDecoded = json_decode(getSetting($pdo, 'premium_colors', '{}'), true);
        $premium = [
            'emojis' => is_array($emojisDecoded) ? $emojisDecoded : [],
            'colors' => is_array($colorsDecoded) ? $colorsDecoded : []
        ];
    }
    return $premium;
}

function createBtn($text, $callback_data, $style = null, $btnKey = null) {
    $btn = ['text' => $text, 'callback_data' => $callback_data];
    if ($style) {
        $btn['style'] = $style;
    }
    
    if ($btnKey) {
        $prem = getPremiumSettings();
        if (!empty($prem['emojis'][$btnKey])) {
            $btn['icon_custom_emoji_id'] = (string)$prem['emojis'][$btnKey];
            $btn['text'] = removeBotEmojis($text);
        }
        if (!empty($prem['colors'][$btnKey])) {
            $validStyles = ['primary', 'success', 'danger'];
            if (in_array($prem['colors'][$btnKey], $validStyles)) {
                $btn['style'] = $prem['colors'][$btnKey];
            }
        }
    }
    return $btn;
}

function createUrlBtn($text, $url, $style = null, $btnKey = null) {
    $btn = ['text' => $text, 'url' => $url];
    if ($style) {
        $btn['style'] = $style;
    }
    
    if ($btnKey) {
        $prem = getPremiumSettings();
        if (!empty($prem['emojis'][$btnKey])) {
            $btn['icon_custom_emoji_id'] = (string)$prem['emojis'][$btnKey];
            $btn['text'] = removeBotEmojis($text);
        }
        if (!empty($prem['colors'][$btnKey])) {
            $validStyles = ['primary', 'success', 'danger'];
            if (in_array($prem['colors'][$btnKey], $validStyles)) {
                $btn['style'] = $prem['colors'][$btnKey];
            }
        }
    }
    return $btn;
}

function splitTextSafely($text, $length = 3900) {
    $chunks = [];
    while (mb_strlen($text, 'UTF-8') > 0) {
        $chunk    = mb_substr($text, 0, $length, 'UTF-8');
        $chunks[] = $chunk;
        $text     = mb_substr($text, mb_strlen($chunk, 'UTF-8'), null, 'UTF-8');
    }
    return $chunks;
}

function formatBytes($bytes, $precision = 2) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow   = floor(log($bytes) / log(1024));
    $pow   = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getPremiumTextEmojis() {
    global $pdo;
    $defaults = [
        '🚀'=>'', '✅'=>'', '❌'=>'', '📊'=>'', '📦'=>'', '🔋'=>'', '⏳'=>'', '🟢'=>'', 
        '🔴'=>'', '👤'=>'', '⚙️'=>'', '🔍'=>'', '🗂'=>'', '👨‍💼'=>'', '📢'=>'', '🔐'=>'', 
        '👮‍♂️'=>'', '🌟'=>'', '⏱'=>'', '💾'=>'', '🔙'=>'', '🏠'=>'', '🆔'=>'', '📅'=>'', 
        '🔗'=>'', '🌐'=>'', '🔲'=>'', '📄'=>'', '🔄'=>'', '📈'=>'', '📉'=>'', '🔹'=>'', 
        '📝'=>'', '📌'=>'', '📡'=>'', '💬'=>'', '🎨'=>'', '✨'=>'', '👇'=>'', '🗑'=>'', 
        '➕'=>'', '⛔️'=>'', '🚪'=>'', '⚠️'=>'', '🔸'=>'', '✔️'=>'', '🌍'=>'', '𔲲'=>'', 
        '⌚️'=>'', '🏷'=>'', '👨‍💻'=>'', '📁'=>'', '⬅️'=>'', '➡️'=>'', '🛠'=>'', '💳'=>'', 
        '🔊'=>'', '🔇'=>'',
        '🇮🇷'=>'', '🇹🇷'=>'', '🇦🇪'=>'', '🇸🇦'=>'', '🇮🇶'=>'', '🇶🇦'=>'', '🇧🇭'=>'', '🇰🇼'=>'', '🇴🇲'=>'', '🇾🇪'=>'', '🇸🇾'=>'', '🇱🇧'=>'', '🇯🇴'=>'', '🇮🇱'=>'', '🇵🇸'=>'',
        '🇩🇪'=>'', '🇬🇧'=>'', '🇳🇱'=>'', '🇫🇷'=>'', '🇫🇮'=>'', '🇷🇺'=>'', '🇵🇱'=>'', '🇨🇭'=>'', '🇸🇪'=>'', '🇮🇹'=>'', '🇪🇸'=>'', '🇺🇦'=>'', '🇩🇰'=>'', '🇦🇹'=>'', '🇧🇬'=>'', '🇷🇴'=>'', '🇳🇴'=>'', '🇧🇪'=>'', '🇮🇪'=>'', '🇵🇹'=>'', '🇬🇷'=>'', '🇨🇿'=>'', '🇭🇺'=>'', '🇷🇸'=>'', '🇭🇷'=>'', '🇸🇰'=>'', '🇸🇮'=>'', '🇪🇪'=>'', '🇱🇻'=>'', '🇱🇹'=>'', '🇮🇸'=>'', '🇦🇱'=>'', '🇲🇰'=>'', '🇧🇦'=>'', '🇲🇩'=>'', '🇧🇾'=>'', '🇨🇾'=>'', '🇱🇺'=>'',
        '🇺🇸'=>'', '🇨🇦'=>'', '🇧🇷'=>'', '🇲🇽'=>'', '🇦🇷'=>'', '🇨🇱'=>'', '🇨🇴'=>'', '🇵🇪'=>'', '🇻🇪'=>'', '🇺🇾'=>'', '🇵🇦'=>'', '🇨🇺'=>'', '🇪🇨'=>'', '🇵🇾'=>'', '🇧🇴'=>'',
        '🇸🇬'=>'', '🇮🇳'=>'', '🇦🇺'=>'', '🇯🇵'=>'', '🇰🇷'=>'', '🇭🇰'=>'', '🇹🇼'=>'', '🇻🇳'=>'', '🇿🇦'=>'', '🇨🇳'=>'', '🇲🇾'=>'', '🇮🇩'=>'', '🇹🇭'=>'', '🇵🇰'=>'', '🇦🇫'=>'', '🇳🇿'=>'',
        '😀'=>'', '😃'=>'', '😄'=>'', '😁'=>'', '😆'=>'', '😅'=>'', '🤣'=>'', '😂'=>'', '🙂'=>'', '🙃'=>'',
        '😉'=>'', '😊'=>'', '😇'=>'', '🥰'=>'', '😍'=>'', '🤩'=>'', '😘'=>'', '😗'=>'', '😚'=>'', '😙'=>'',
        '😋'=>'', '😛'=>'', '😜'=>'', '🤪'=>'', '😝'=>'', '🤑'=>'', '🤗'=>'', '🤭'=>'', '🤫'=>'', '🤔'=>'',
        '🤐'=>'', '🤨'=>'', '😐'=>'', '😑'=>'', '😶'=>'', '😏'=>'', '😒'=>'', '🙄'=>'', '😬'=>'', '🤥'=>'',
        '😌'=>'', '😔'=>'', '😪'=>'', '🤤'=>'', '😴'=>'', '😷'=>'', '🤒'=>'', '🤕'=>'', '🤢'=>'', '🤮'=>'',
        '🤧'=>'', '🥵'=>'', '🥶'=>'', '🥴'=>'', '😵'=>'', '🤯'=>'', '🤠'=>'', '🥳'=>'', '😎'=>'', '🤓'=>'',
        '🧐'=>'', '😕'=>'', '😟'=>'', '🙁'=>'', '☹️'=>'', '😮'=>'', '😯'=>'', '😲'=>'', '😳'=>'', '🥺'=>'',
        '😦'=>'', '😧'=>'', '😨'=>'', '😰'=>'', '😥'=>'', '😢'=>'', '😭'=>'', '😱'=>'', '😖'=>'', '😣'=>''
    ];
    if (!$pdo) return $defaults;
    $saved = json_decode(getSetting($pdo, 'premium_text_emojis', '{}'), true);
    if (!is_array($saved)) $saved = [];
    return array_merge($defaults, $saved);
}

function applyPremiumToText($text) {
    if (empty($text)) return $text;
    $map = getPremiumTextEmojis();
    foreach ($map as $emoji => $id) {
        if (!empty($id)) {
            $text = str_replace($emoji, "<tg-emoji emoji-id=\"{$id}\">{$emoji}</tg-emoji>", $text);
        }
    }
    return $text;
}

function addFlagToConfigName($name) {
    if (preg_match('/[\x{1F1E6}-\x{1F1FF}]{2}/u', $name)) {
        return $name; 
    }

    $countryFlags = [
        '🇮🇷' => ['ir', 'iran', 'ایران', 'tehran', 'mci', 'mtn', 'rightel', 'irancell', 'shatel', 'mkh', 'hwb'],
        '🇹🇷' => ['tr', 'turkey', 'ترکیه', 'istanbul', 'bursa', 'ankara'],
        '🇦🇪' => ['ae', 'uae', 'امارات', 'دبی', 'dubai', 'abudhabi'],
        '🇩🇪' => ['de', 'germany', 'آلمان', 'frankfurt', 'hetzner', 'berlin', 'nbg', 'fsn'],
        '🇬🇧' => ['uk', 'gb', 'england', 'انگلیس', 'بریتانیا', 'london', 'manchester'],
        '🇳🇱' => ['nl', 'netherlands', 'هلند', 'amsterdam', 'rotterdam'],
        '🇫🇷' => ['fr', 'france', 'فرانسه', 'paris', 'strasbourg', 'marseille'],
        '🇫🇮' => ['fi', 'finland', 'فنلاند', 'helsinki'],
        '🇷🇺' => ['ru', 'russia', 'روسیه', 'moscow', 'st petersburg'],
        '🇺🇸' => ['us', 'usa', 'america', 'آمریکا', 'ایالات متحده', 'new york', 'los angeles', 'miami', 'chicago', 'ashburn', 'seattle', 'dallas', 'atlanta'],
        '🇨🇦' => ['ca', 'canada', 'کانادا', 'toronto', 'montreal', 'vancouver'],
        '🇸🇬' => ['sg', 'singapore', 'سنگاپور'],
    ];

    $lowerName = strtolower($name);
    foreach ($countryFlags as $flag => $keywords) {
        foreach ($keywords as $kw) {
            if (strlen($kw) == 2) {
                if (preg_match('/(^|[^a-z])(' . $kw . ')([^a-z]|$)/i', $lowerName)) {
                    return $flag . ' ' . $name;
                }
            } else {
                if (strpos($lowerName, $kw) !== false) {
                    return $flag . ' ' . $name;
                }
            }
        }
    }
    return '🌍 ' . $name; 
}

function answerCallback($callbackId, $text = '', $showAlert = false) {
    static $isAnswered = false;
    if ($isAnswered) return;
    $postData = ['callback_query_id' => $callbackId];
    if ($text !== '') {
        $postData['text'] = $text;
        if ($showAlert) $postData['show_alert'] = true;
    }
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
    curl_close($ch);
    $isAnswered = true;
}

// آپدیت شده برای ارسال به گروه دوم (پشتیبان)
function sendTopicReport($pdo, string $reportText, string $topicName, string $threadKey, ?string $filePath = null): void {
    $reportText = applyPremiumToText($reportText); 
    $status = getSetting($pdo, 'report_status', 'off');
    if ($status !== 'on') return;
    
    // آرایه گروه‌ها برای ارسال همزمان
    $groups = [
        ['id' => getSetting($pdo, 'report_group_id', ''), 'key' => $threadKey],
        ['id' => getSetting($pdo, 'report_group_id_2', ''), 'key' => $threadKey . '_2']
    ];

    foreach ($groups as $grp) {
        $groupId = $grp['id'];
        $tKey = $grp['key'];
        
        if (empty($groupId)) continue; // اگر گروه ثبت نشده بود رد کن
        
        $threadId = getSetting($pdo, $tKey, '');
        
        if ($threadId === 'NOT_FORUM') {
            $threadId = ''; 
        } elseif (empty($threadId)) {
            $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/createForumTopic");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $groupId, 'name' => $topicName], CURLOPT_TIMEOUT => 10]);
            $resData = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            
            if (!empty($resData['ok'])) {
                $threadId = (string)$resData['result']['message_thread_id'];
                setSetting($pdo, $tKey, $threadId);
            } else {
                if (isset($resData['description']) && (strpos(strtolower($resData['description']), 'not a forum') !== false || strpos(strtolower($resData['description']), 'not enough rights') !== false)) {
                    setSetting($pdo, $tKey, 'NOT_FORUM');
                }
                $threadId = '';
            }
        }

        $postData = ['chat_id' => $groupId, 'parse_mode' => 'HTML'];
        if (!empty($threadId) && $threadId !== 'NOT_FORUM') {
            $postData['message_thread_id'] = (int)$threadId;
        }

        if ($filePath && file_exists($filePath)) {
            $endpoint = "/sendDocument";
            $postData['caption'] = $reportText;
            $postData['document'] = new CURLFile(realpath($filePath));
        } else {
            $endpoint = "/sendMessage";
            $postData['text'] = $reportText;
            $postData['disable_web_page_preview'] = true;
        }

        $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . $endpoint);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 30]);
        $sendRes = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($sendRes['ok']) && !$sendRes['ok'] && strpos(strtolower($sendRes['description'] ?? ''), 'message thread not found') !== false) {
            setSetting($pdo, $tKey, ''); 
            unset($postData['message_thread_id']);
            if ($filePath && file_exists($filePath)) {
                $postData['document'] = new CURLFile(realpath($filePath));
            }
            $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . $endpoint);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 30]);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

function sendBotText($chatId, string $text, ?array $replyMarkup = null): void {
    $text = applyPremiumToText($text); 
    $postData = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($replyMarkup) $postData['reply_markup'] = json_encode($replyMarkup);
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    curl_close($ch);
}

function sendMessage($chatId, string $text, ?array $replyMarkup = null): void {
    sendBotText($chatId, $text, $replyMarkup);
}

function editMessageText($chatId, int $messageId, string $text, ?array $replyMarkup = null): void {
    $text = applyPremiumToText($text); 
    $postData = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($replyMarkup) $postData['reply_markup'] = json_encode($replyMarkup);
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    curl_close($ch);
}

function getSubHeaderInfo($url) {
    $info = ['upload' => 0, 'download' => 0, 'total' => 0, 'expire' => 0, 'error_details' => null];
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'v2rayNG/1.8.18',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADER         => true, 
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        $info['error_details'] = "cURL Error: " . $curlError;
    } 
    elseif ($httpCode !== 200) {
        $info['error_details'] = "Server Error: " . $httpCode;
    }

    if (preg_match('/subscription-userinfo:\s*(.+)/i', (string)$response, $matches)) {
        foreach (explode(';', trim($matches[1])) as $part) {
            $kv = explode('=', trim($part));
            if (count($kv) == 2) $info[trim($kv[0])] = (float)trim($kv[1]);
        }
    }
    
    return $info;
}

function getWebRootUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $dir  = dirname($_SERVER['REQUEST_URI'] ?? '/bot.php');
    if ($dir === '\\' || $dir === '.') $dir = '';
    $dir  = rtrim($dir, '/');
    return "https://{$host}{$dir}";
}

function generateExtractionToken(): string {
    return bin2hex(random_bytes(12));
}

function saveExtraction($pdo, string $token, $userId, string $subUrl, array $subData, array $headerInfo): void {
    $totalBytes  = (float)($headerInfo['total'] ?? 0);
    $usedBytes   = (float)($headerInfo['upload'] ?? 0) + (float)($headerInfo['download'] ?? 0);
    $expireTs    = (int)($headerInfo['expire'] ?? 0);
    $configsJson = json_encode($subData['configs_list'] ?? [], JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("INSERT INTO extractions (token, user_id, sub_url, total_configs, total_bytes, used_bytes, expire_ts, configs_json, created_at)
        VALUES (?,?,?,?,?,?,?,?, NOW())
        ON DUPLICATE KEY UPDATE sub_url=VALUES(sub_url), total_configs=VALUES(total_configs), total_bytes=VALUES(total_bytes), used_bytes=VALUES(used_bytes), expire_ts=VALUES(expire_ts), configs_json=VALUES(configs_json), created_at=NOW()");
    $stmt->execute([$token, $userId, $subUrl, $subData['total_configs'] ?? 0, $totalBytes, $usedBytes, $expireTs, $configsJson]);
}

function isUserInChannel($userId, string $channelId): bool {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getChatMember";
    $postData = ['chat_id' => $channelId, 'user_id' => $userId];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 10]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res ?: '', true);
    if (isset($data['ok']) && $data['ok'] && isset($data['result']['status'])) {
        return in_array($data['result']['status'], ['creator', 'administrator', 'member', 'restricted']);
    }
    return false;
}

// ----------------------------------------------------
// رجیستری مرکزی دکمه‌ها + سیستم چیدمان/متن پویا (سازگار با webpanel.php)
// ----------------------------------------------------
function getBtnRegistry(): array {
    return [
        // منوی اصلی
        'btn_main_analyze' => ['label' => '🔍 آنالیز و استخراج ساب',   'callback' => 'main_analyze',        'style' => 'primary', 'menu' => 'main_menu'],
        'btn_main_account' => ['label' => '🗂 حساب کاربری',            'callback' => 'main_account',        'style' => 'success', 'menu' => 'main_menu'],
        'btn_main_admin'   => ['label' => '👨‍💼 پنل مدیریت ربات',      'callback' => 'main_admin',          'style' => 'danger',  'menu' => 'main_menu', 'admin_only' => true],

        // منوی پنل مدیریت
        'btn_admin_stats'         => ['label' => '📊 آمار ربات',             'callback' => 'bot_stats_nav',          'style' => 'primary', 'menu' => 'admin_menu'],
        'btn_admin_users'         => ['label' => '📄 لیست کاربران',          'callback' => 'users_list_page_0',      'style' => 'success', 'menu' => 'admin_menu'],
        'btn_admin_broadcast'     => ['label' => '📢 پیام همگانی',           'callback' => 'broadcast_message_nav',  'style' => 'danger',  'menu' => 'admin_menu'],
        'btn_admin_status'        => ['label' => '⚙️ وضعیت ربات',            'callback' => 'toggle_public_mode',     'style' => 'danger',  'menu' => 'admin_menu', 'dynamic' => 'public_status'],
        'btn_admin_lock'          => ['label' => '🔐 قفل کانال',             'callback' => 'force_join_manage',      'style' => 'primary', 'menu' => 'admin_menu'],
        'btn_admin_admins'        => ['label' => '👮‍♂️ مدیران',              'callback' => 'admins_manage_nav',      'style' => 'primary', 'menu' => 'admin_menu', 'supadmin_only' => true],
        'btn_admin_reports'       => ['label' => '📢 تنظیمات گزارشات',        'callback' => 'manage_reports_nav',     'style' => 'success', 'menu' => 'admin_menu'],
        'btn_admin_premium'       => ['label' => '🌟 دکمه ها',                'callback' => 'premium_folder_nav',     'style' => 'danger',  'menu' => 'admin_menu'],
        'btn_admin_cron'          => ['label' => '⏱ کرون',                   'callback' => 'cron_settings_nav',      'style' => 'primary', 'menu' => 'admin_menu'],
        'btn_admin_webpanel'      => ['label' => '🌐 تنظیمات پنل وب',         'callback' => 'webpanel_settings_nav',  'style' => 'primary', 'menu' => 'admin_menu', 'supadmin_only' => true],
        'btn_admin_settingspanel' => ['label' => '⚙️ پنل تنظیمات کامل',       'callback' => 'settings_panel_nav',     'style' => 'success', 'menu' => 'admin_menu', 'supadmin_only' => true],
        'btn_admin_settings'      => ['label' => '⚙️ تنظیمات',                'callback' => 'settings_folder_nav',    'style' => 'success', 'menu' => 'admin_menu'],
        'btn_admin_backup'        => ['label' => '💾 بک‌آپ',                  'callback' => 'backup_folder_nav',      'style' => 'success', 'menu' => 'admin_menu'],
        // نکته مهم: سه دکمه زیر (دیتابیس/سورس/ایمپورت) دیگر در منوی اصلی ادمین
        // رندر نمی‌شوند حتی اگر در چیدمان ذخیره‌شده (از پنل وب) به‌اشتباه در
        // admin_menu قرار گرفته باشند. این کار توسط فیلتر امنیتی داخل تابع
        // buildMenuKeyboard() انجام می‌شود تا همیشه فقط از داخل دکمه‌ی
        // «💾 بک‌آپ» (backup_folder_nav) در دسترس باشند.
        'btn_admin_db'            => ['label' => '💾 بکاپ دیتابیس',           'callback' => 'backup_db_manual',       'style' => 'primary', 'menu' => 'admin_menu'],
        'btn_admin_restore'       => ['label' => '📥 ایمپورت بک‌آپ',          'callback' => 'restore_backup_nav',     'style' => 'danger',  'menu' => 'admin_menu', 'supadmin_only' => true],
        'btn_admin_back'          => ['label' => '🔙 بازگشت به منوی اصلی',    'callback' => 'back_to_main_menu',      'style' => 'success', 'menu' => 'admin_menu'],

        // منوی استخراج (ساب)
        'btn_sub_update' => ['label' => '🔄 بروزرسانی اطلاعات', 'callback' => 'update_sub_data',       'style' => 'primary', 'menu' => 'sub_menu'],
        'btn_sub_text'   => ['label' => '📄 دریافت کانفیگ',     'callback' => 'get_extracted_configs', 'style' => 'success', 'menu' => 'sub_menu'],
        'btn_sub_ip'     => ['label' => '🌐 دریافت آی‌پی',       'callback' => 'get_extracted_ips',     'style' => 'primary', 'menu' => 'sub_menu'],
        'btn_sub_qr1'    => ['label' => '🔲 QR کانفیگ‌ها',       'callback' => 'get_configs_qr',        'style' => 'success', 'menu' => 'sub_menu'],
        'btn_sub_qr2'    => ['label' => '🔲 QR ساب',            'callback' => 'get_qr_code',           'style' => 'primary', 'menu' => 'sub_menu'],
        // این دکمه همیشه به‌صورت ثابت به‌عنوان آخرین ردیف زیر نتیجه‌ی استخراج
        // نمایش داده می‌شود (چون آدرسش برای هر استخراج فرق می‌کنه) و به همین
        // دلیل در ادیتور چیدمان/ردیف‌های «سطح استخراج» قابل جابه‌جایی نیست؛
        // ولی متن، رنگ و ایموجی‌اش از تب‌های «برچسب‌ها/رنگ‌ها/ایموجی‌ها» در پنل
        // وب کاملاً قابل تغییره.
        'btn_web_view'   => ['label' => '🖥 مشاهده در وب',     'callback' => null,                    'style' => 'success', 'menu' => 'sub_menu', 'fixed_row' => true],
    ];
}

function getDefaultMenuLayouts(): array {
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

function getCustomBtnLabels($pdo): array {
    static $labels = null;
    if ($labels === null) {
        $decoded = json_decode(getSetting($pdo, 'btn_labels', '{}'), true);
        $labels  = is_array($decoded) ? $decoded : [];
    }
    return $labels;
}

function getMenuLayout($pdo, string $menuKey): array {
    $defaults = getDefaultMenuLayouts();
    $saved    = json_decode(getSetting($pdo, 'layout_' . $menuKey, ''), true);
    return (is_array($saved) && !empty($saved)) ? $saved : ($defaults[$menuKey] ?? []);
}

// کلیدهای دکمه‌ای که همیشه باید فقط زیرمجموعه‌ی دکمه‌ی «💾 بک‌آپ» باشند
// و هرگز مستقیم در سطح اول منوی ادمین رندر نشوند (حتی اگر چیدمان
// سفارشیِ ذخیره‌شده از پنل وب اشتباهاً آن‌ها را در همان سطح قرار داده باشد).
function getBackupSubOnlyKeys(): array {
    return ['btn_admin_db', 'btn_admin_restore'];
}

// می‌سازد کیبورد شیشه‌ای یک منو را، بر اساس چیدمان و متن‌های ذخیره‌شده از پنل وب (webpanel.php)
function buildMenuKeyboard($pdo, string $menuKey, bool $isAdmin = false, bool $isSupAdmin = false): array {
    $registry = getBtnRegistry();
    $labels   = getCustomBtnLabels($pdo);
    $layout   = getMenuLayout($pdo, $menuKey);

    $publicStatus   = getSetting($pdo, 'public_mode', '0') === '1' ? '🟢 روشن' : '🔴 خاموش';
    $showAccountBtn = getSetting($pdo, 'show_account_btn', 'on') === 'on';
    $backupSubOnly  = getBackupSubOnlyKeys();

    $keyboard = [];
    foreach ($layout as $row) {
        if (!is_array($row)) continue;
        $kbRow = [];
        foreach ($row as $btnKey) {
            // فیلتر امنیتی: دکمه‌های دیتابیس/سورس/ایمپورت هرگز مستقیم در
            // منوی ادمین ظاهر نمی‌شوند، فقط از داخل زیرمنوی بک‌آپ در دسترس‌اند.
            if ($menuKey === 'admin_menu' && in_array($btnKey, $backupSubOnly, true)) continue;

            $def = $registry[$btnKey] ?? null;
            if (!$def || $def['menu'] !== $menuKey) continue;
            if (!empty($def['admin_only']) && !$isAdmin) continue;
            if (!empty($def['supadmin_only']) && !$isSupAdmin) continue;
            if ($btnKey === 'btn_main_account' && !$showAccountBtn) continue;

            $label = !empty($labels[$btnKey]) ? $labels[$btnKey] : $def['label'];
            if (($def['dynamic'] ?? '') === 'public_status') {
                $label .= ': ' . $publicStatus;
            }

            $kbRow[] = createBtn($label, $def['callback'], $def['style'] ?? null, $btnKey);
        }
        if (!empty($kbRow)) $keyboard[] = $kbRow;
    }
    return ['inline_keyboard' => $keyboard];
}

function getMainInlineMarkup(bool $isAdmin, $pdo, bool $isSupAdmin = false): array {
    return buildMenuKeyboard($pdo, 'main_menu', $isAdmin, $isSupAdmin);
}

function getAdminInlineMarkup($pdo, bool $isAdmin = true, bool $isSupAdmin = false): array {
    return buildMenuKeyboard($pdo, 'admin_menu', $isAdmin, $isSupAdmin);
}

function getSubInlineMarkup($pdo): array {
    return buildMenuKeyboard($pdo, 'sub_menu', false, false);
}

function sendUserManagePanel($chatId, $targetId, $pdo, $messageId = null) {
    global $isSupAdmin;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$targetId]);
    $u = $stmt->fetch();

    if (!$u) {
        $pdo->prepare("INSERT IGNORE INTO users (user_id) VALUES (?)")->execute([$targetId]);
        $stmt->execute([$targetId]);
        $u = $stmt->fetch();
    }

    $isBlocked  = empty($u['is_blocked']) ? 0 : 1;
    $uIsAdmin   = empty($u['is_admin'])   ? 0 : 1;
    $joinDate   = jdate('Y/m/d H:i', safe_timestamp($u['joined_at'] ?? ''));

    $keyboard = ['inline_keyboard' => [
        [
            createBtn('✍️ ارسال پیام', "umsg_{$targetId}", 'primary', 'btn_send_msg'),
            createBtn($isBlocked ? '🔓 رفع مسدود' : '🔒 مسدود', "ublock_{$targetId}", $isBlocked ? 'success' : 'danger', 'btn_block')
        ]
    ]];

    if ($isSupAdmin && defined('ADMIN_ID') && $targetId != ADMIN_ID) {
        $keyboard['inline_keyboard'][] = [
            createBtn(($uIsAdmin == 1) ? '🔻 حذف کل ادمین' : '🤖 افزودن ادمین', "uadmin_{$targetId}", 'danger', 'btn_admin')
        ];
    }
    
    $keyboard['inline_keyboard'][] = [createBtn('🔙 پاک کردن پیام', 'delete_this_msg', 'danger', 'btn_back')];

    $statusText = $isBlocked ? "🔴 مسدود" : "🟢 فعال";

    $text  = "👤 <b>پروفایل کاربری پیشرفته</b>\n\n";
    $text .= "🆔 شناسه: <code>{$targetId}</code>\n";
    $text .= "📅 تاریخ عضویت: {$joinDate}\n";
    $text .= "وضعیت ربات: {$statusText}";

    if ($messageId) editMessageText($chatId, $messageId, $text, $keyboard);
    else            sendMessage($chatId, $text, $keyboard);
}

// ----------------------------------------------------
// سیستم کرون‌جاب بکاپ خودکار
// ----------------------------------------------------
if ($isCronRequest) {
    // نکته امنیتی: قبلاً یک توکن ثابت و یکسان (MY_SECURE_CRON_TOKEN_123) در کد
    // عمومی وجود داشت که برای همه‌ی نصب‌های این ربات یکی بود. حالا توکن کرون
    // به‌صورت خودکار و مخصوص همین نصب، از روی BOT_TOKEN شما ساخته می‌شود؛ برای
    // دیدن مقدار دقیقش به تب «⏱ کرون» در پنل وب مراجعه کنید.
    if (isset($_GET['token']) && hash_equals(getCronToken(), (string)$_GET['token'])) {
        $interval = (int)getSetting($pdo, 'cron_interval', '300');
        if ($interval === 0) {
            echo json_encode(["status" => "skipped", "message" => "Cron backup is disabled from admin panel."]);
            exit;
        }
        
        $lastBackup = (int)getSetting($pdo, 'last_cron_backup', '0');
        if (time() - $lastBackup < $interval) {
            echo json_encode(["status" => "skipped", "message" => "Time interval not reached yet."]);
            exit;
        }
        
        setSetting($pdo, 'last_cron_backup', (string)time());

        // نکته: بکاپ سورس از کل پروژه حذف شده؛ کرون فقط از دیتابیس بکاپ می‌گیرد.
        $dbFile = createDbBackupFile($pdo);

        $captionBase = "🔄 <b>بکاپ خودکار دیتابیس (کرون‌جاب)</b>\n🕒 زمان: " . jdate('Y/m/d H:i:s');
        
        if ($dbFile && file_exists($dbFile)) {
            $caption = "💾 " . $captionBase;
            sendTopicReport($pdo, $caption, 'بکاپ سیستم 📦', 'report_backup_thread_id', $dbFile);
            
            if (defined('ADMIN_ID')) {
                $caption = applyPremiumToText($caption); 
                $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, 
                    CURLOPT_POST => true, 
                    CURLOPT_POSTFIELDS => ['chat_id' => ADMIN_ID, 'document' => new CURLFile(realpath($dbFile)), 'caption' => $caption, 'parse_mode' => 'HTML'], 
                    CURLOPT_TIMEOUT => 30
                ]);
                curl_exec($ch); curl_close($ch);
            }
            unlink($dbFile);
        }
        echo json_encode(["status" => "success", "message" => "Backup executed and sent successfully."]);
        exit;
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Invalid token."]);
        exit;
    }
}

// ----------------------------------------------------
// پردازش اصلی
// ----------------------------------------------------
try {
    $message       = $update['message'] ?? null;
    $callbackQuery = $update['callback_query'] ?? null;
    $chatId        = $message['chat']['id'] ?? $callbackQuery['message']['chat']['id'] ?? 0;
    $userId        = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? 0;

    if ($chatId === 0) exit;

    $firstName   = $message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? 'کاربر';
    $lastName    = $message['from']['last_name']  ?? $callbackQuery['from']['last_name']  ?? '';
    $fullName    = trim($firstName . ' ' . $lastName);
    $username    = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
    $userMention = $username ? "@{$username}" : "<a href='tg://user?id={$userId}'>{$fullName}</a>";

    $isSupAdmin = (defined('ADMIN_ID') && $userId == ADMIN_ID);
    $isAdmin    = $isSupAdmin;

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT admin_id FROM admins WHERE admin_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            $isAdmin = true;
        }
    }

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $uRole = $stmt->fetch();
        if ($uRole && ($uRole['is_admin'] ?? 0) == 1) { 
            $isAdmin = true; 
        }
    }

    $isPublic  = (getSetting($pdo, 'public_mode', '0') === '1');
    $startText = "به ربات استخراج سریع سابسکریپشن خوش آمدید 🚀\nاز منوی شیشه‌ای زیر استفاده کنید 👇";

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT is_blocked FROM users WHERE user_id = ?");
        $stmt->execute([$chatId]);
        $uInfo = $stmt->fetch();
        if ($uInfo && ($uInfo['is_blocked'] ?? 0) == 1) {
            if ($message && isset($message['text'])) sendMessage($chatId, "❌ حساب کاربری شما مسدود شده است.");
            exit;
        }
    }

    if (!$isAdmin && !$isPublic) {
        if ($message && isset($message['text'])) sendMessage($chatId, "❌ ربات در حال حاضر غیرفعال است.");
        exit;
    }

    $fj_status  = getSetting($pdo, 'fj_status', 'off');
    $fj_channel = getSetting($pdo, 'fj_channel', '');
    
    if (!$isAdmin && $fj_status === 'on' && !empty($fj_channel)) {
        if (!isUserInChannel($chatId, $fj_channel)) {
            if ($callbackQuery && $callbackQuery['data'] === 'check_join') {
                answerCallback($callbackQuery['id'], '❌ هنوز در کانال عضو نشده‌اید!', true);
                exit;
            }
            $channelUrl  = str_starts_with($fj_channel, '-100')
                ? "https://t.me/c/" . substr($fj_channel, 4) . "/1"
                : "https://t.me/" . str_replace('@', '', $fj_channel);
            $joinKeyboard = ['inline_keyboard' => [
                [createUrlBtn('📢 ورود به کانال', $channelUrl, 'primary', 'btn_channel')],
                [createBtn('✅ عضو شدم', 'check_join', 'success', 'btn_check_join')]
            ]];
            $joinText = "⛔️ <b>کاربر گرامی!</b>\nجهت استفاده از خدمات باید در کانال اسپانسر عضو شوید.";
            if ($callbackQuery) editMessageText($chatId, $callbackQuery['message']['message_id'], $joinText, $joinKeyboard);
            else                sendMessage($chatId, $joinText, $joinKeyboard);
            exit;
        } else {
            if ($callbackQuery && $callbackQuery['data'] === 'check_join') {
                answerCallback($callbackQuery['id'], '✅ عضویت تایید شد.', true);
                sendMessage($chatId, $startText, getMainInlineMarkup($isAdmin, $pdo));
                exit;
            }
        }
    }

    // ====================================================
    // پردازش پیام‌های متنی
    // ====================================================
    if ($message) {
        $text = $message['text'] ?? '';

        // --- بررسی حالت انتظار فایل بک‌آپ (ایمپورت هوشمند) قبل از پردازش عمومی فایل‌ها ---
        if (isset($message['document'])) {
            $stmtChk = $pdo->prepare("SELECT state FROM user_states WHERE user_id = ?");
            $stmtChk->execute([$chatId]);
            $pendingState = $stmtChk->fetchColumn();

            if ($pendingState === 'WAITING_FOR_BACKUP_RESTORE') {
                if (!$isSupAdmin) { exit; }

                $docName = $message['document']['file_name'] ?? '';
                if (!preg_match('/\.sql$/i', $docName)) {
                    sendMessage($chatId, "❌ فقط فایل بک‌آپ دیتابیس با پسوند .sql پذیرفته می‌شود.\nلطفاً فایل صحیح را ارسال کنید یا برای انصراف دکمه زیر را بزنید:", ['inline_keyboard' => [[createBtn('❌ انصراف', 'backup_folder_nav', 'danger', 'btn_back')]]]);
                    exit;
                }

                $fileId = $message['document']['file_id'];
                $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/getFile");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['file_id' => $fileId]]);
                $fileRes = json_decode(curl_exec($ch), true);
                curl_close($ch);

                $sqlContent = null;
                if (!empty($fileRes['result']['file_path'])) {
                    $fileUrl = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $fileRes['result']['file_path'];
                    $ch2 = curl_init($fileUrl);
                    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true]);
                    $sqlContent = curl_exec($ch2);
                    curl_close($ch2);
                }

                if (empty($sqlContent)) {
                    sendMessage($chatId, "❌ خطا در دریافت فایل از سرور تلگرام. مجدد تلاش کنید.");
                    exit;
                }

                sendMessage($chatId, "⏳ در حال بررسی و ایمپورت هوشمند بک‌آپ...");
                $result = importDbBackupFile($pdo, (string)$sqlContent);
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);

                if ($result['valid'] === false) {
                    sendMessage($chatId, "❌ فایل ارسالی یک بک‌آپ معتبر ربات نیست یا فرمت آن ناشناخته است.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'backup_folder_nav', 'danger', 'btn_admin_back')]]]);
                    exit;
                }

                $pdo->prepare("INSERT INTO backup_imports (user_id, inserted_count, skipped_count, failed_count, total_count) VALUES (?,?,?,?,?)")
                    ->execute([$chatId, $result['inserted'] + $result['updated'], $result['unchanged'], $result['failed'], $result['total']]);

                $totalImports = (int)$pdo->query("SELECT COUNT(*) FROM backup_imports")->fetchColumn();

                $reportText  = "✅ <b>ایمپورت بک‌آپ با موفقیت انجام شد</b>\n\n";
                $reportText .= "➕ رکوردهای کاملاً جدید: <code>{$result['inserted']}</code>\n";
                $reportText .= "🔄 رکوردهای موجود که بروزرسانی شدند: <code>{$result['updated']}</code>\n";
                $reportText .= "➖ رکوردهای بدون تغییر: <code>{$result['unchanged']}</code>\n";
                $reportText .= "❌ خطاها: <code>{$result['failed']}</code>\n";
                $reportText .= "📄 تعداد کل دستورات پردازش‌شده: <code>{$result['total']}</code>\n";
                $reportText .= "📜 مجموع ایمپورت‌های انجام‌شده تاکنون: <code>{$totalImports}</code>\n\n";
                $reportText .= "ℹ️ توجه: این یک بازیابی کامل است؛ رکوردهایی که در دیتابیس فعلی هم وجود داشتند، با مقادیر فایل بک‌آپ بروزرسانی شدند.";

                sendMessage($chatId, $reportText, ['inline_keyboard' => [[createBtn('🔙 بازگشت به بک‌آپ', 'backup_folder_nav', 'success', 'btn_admin_back')]]]);
                sendTopicReport($pdo, "📥 <b>ایمپورت بک‌آپ انجام شد</b>\nتوسط: {$userMention}\nآیدی: <code>{$chatId}</code>\nجدید: {$result['inserted']} | بروزرسانی‌شده: {$result['updated']} | بدون تغییر: {$result['unchanged']} | خطا: {$result['failed']}\nزمان: " . jdate('Y/m/d H:i:s'), 'بکاپ سیستم 📦', 'report_backup_thread_id');
                exit;
            }
        }

        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];
            $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/getFile");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['file_id' => $fileId]]);
            $fileRes = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (!empty($fileRes['result']['file_path'])) {
                $fileUrl = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $fileRes['result']['file_path'];
                $ch2 = curl_init($fileUrl);
                curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true]);
                $fileContent = curl_exec($ch2);
                curl_close($ch2);
                
                if ($fileContent) {
                    $text = $fileContent; 
                }
            }
        }

        if (str_starts_with($text, '/start')) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$chatId]);
            if (!$stmt->fetch()) {
                $pdo->prepare("INSERT INTO users (user_id) VALUES (?)")->execute([$chatId]);
                sendTopicReport($pdo, "👤 <b>کاربر جدید!</b>\nنام: " . htmlspecialchars($fullName) . "\nآیدی: {$userMention}\nعددی: <code>{$chatId}</code>\nزمان: " . jdate('Y/m/d H:i:s'), 'ورود کاربران 🚪', 'report_join_thread_id');
            }
            sendTopicReport($pdo, "🔄 <b>استارت ربات</b>\nکاربر: {$userMention}\nآیدی: <code>{$chatId}</code>\nزمان: " . jdate('Y/m/d H:i:s'), 'گزارش لحظه‌ای ⏳', 'report_realtime_thread_id');
            $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
            
            $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => applyPremiumToText('⏳ در حال بارگذاری کیبورد جدید...'), 'reply_markup' => json_encode(['remove_keyboard' => true])], CURLOPT_TIMEOUT => 5]);
            $res = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            if (!empty($res['result']['message_id'])) {
                $ch2 = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/deleteMessage");
                curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'message_id' => $res['result']['message_id']], CURLOPT_TIMEOUT => 5]);
                curl_exec($ch2); curl_close($ch2);
            }
            
            sendMessage($chatId, $startText, getMainInlineMarkup($isAdmin, $pdo));
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM user_states WHERE user_id = ?");
        $stmt->execute([$chatId]);
        $userState = $stmt->fetch();

        $currentState = $userState ? $userState['state'] : null;
        $stateData    = $userState ? (json_decode($userState['data'] ?? '[]', true) ?: []) : [];

        if ($text !== '') {
            $isUrl = filter_var(trim($text), FILTER_VALIDATE_URL);
            $ignoredStates = ['WAITING_FOR_FJ_CHANNEL', 'WAITING_FOR_REPORT_GROUP', 'WAITING_FOR_REPORT_GROUP_2', 'WAITING_FOR_BROADCAST_MESSAGE', 'WAITING_FOR_DIRECT_MESSAGE', 'WAITING_FOR_WEBPANEL_PASSWORD'];
            if ($isUrl && !in_array($currentState, $ignoredStates)) {
                $currentState = 'WAITING_FOR_SUB_URL'; 
            }
        }

        if ($currentState) {

            if ($currentState === 'WAITING_FOR_TEXT_EMOJI') {
                if (!$isAdmin) exit;
                $emojiChar = $stateData['text_emoji_char'] ?? '';
                if ($emojiChar) {
                    $textTrim = trim($text);
                    $customEmojiId = null;
                    if (isset($message['entities'])) {
                        foreach ($message['entities'] as $ent) {
                            if ($ent['type'] === 'custom_emoji' && isset($ent['custom_emoji_id'])) {
                                $customEmojiId = $ent['custom_emoji_id'];
                                break;
                            }
                        }
                    }
                    $emojisJson = getSetting($pdo, 'premium_text_emojis', '{}');
                    $emojis = json_decode($emojisJson, true) ?: [];
                    if ($textTrim === '0' || strtolower($textTrim) === 'off') {
                        unset($emojis[$emojiChar]);
                        setSetting($pdo, 'premium_text_emojis', json_encode($emojis));
                        sendMessage($chatId, "✅ ایموجی متن به حالت پیش‌فرض برگشت.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'text_manage_emojis_0', 'danger')]]]);
                    } elseif ($customEmojiId) {
                        $emojis[$emojiChar] = $customEmojiId;
                        setSetting($pdo, 'premium_text_emojis', json_encode($emojis));
                        sendMessage($chatId, "✅ ایموجی متحرک جایگزین شد.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'text_manage_emojis_0', 'success')]]]);
                    } elseif (is_numeric($textTrim)) {
                        $emojis[$emojiChar] = $textTrim;
                        setSetting($pdo, 'premium_text_emojis', json_encode($emojis));
                        sendMessage($chatId, "✅ آیدی عددی ایموجی ثبت شد.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'text_manage_emojis_0', 'success')]]]);
                    }
                }
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                exit;
            }

            if ($currentState === 'WAITING_FOR_EMOJI_ID') {
                if (!$isAdmin) exit;
                $btnKey = $stateData['btn_key'] ?? '';
                if ($btnKey) {
                    $textTrim = trim($text);
                    $customEmojiId = null;
                    if (isset($message['entities'])) {
                        foreach ($message['entities'] as $ent) {
                            if ($ent['type'] === 'custom_emoji' && isset($ent['custom_emoji_id'])) {
                                $customEmojiId = $ent['custom_emoji_id'];
                                break;
                            }
                        }
                    }
                    $emojisJson = getSetting($pdo, 'premium_emojis', '{}');
                    $emojis = json_decode($emojisJson, true) ?: [];
                    if ($textTrim === '0' || strtolower($textTrim) === 'off') {
                        unset($emojis[$btnKey]);
                        setSetting($pdo, 'premium_emojis', json_encode($emojis));
                        sendMessage($chatId, "✅ ایموجی دکمه لغو شد.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'premium_manage_emojis', 'danger')]]]);
                    } elseif ($customEmojiId) {
                        $emojis[$btnKey] = $customEmojiId;
                        setSetting($pdo, 'premium_emojis', json_encode($emojis));
                        sendMessage($chatId, "✅ ایموجی متحرک روی دکمه ست شد.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'premium_manage_emojis', 'success')]]]);
                    } elseif (is_numeric($textTrim)) {
                        $emojis[$btnKey] = $textTrim;
                        setSetting($pdo, 'premium_emojis', json_encode($emojis));
                        sendMessage($chatId, "✅ آیدی عددی ایموجی ثبت شد.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'premium_manage_emojis', 'success')]]]);
                    }
                }
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                exit;
            }

            if ($currentState === 'WAITING_FOR_SUB_URL') {
                if (!filter_var($text, FILTER_VALIDATE_URL)) { sendMessage($chatId, "❌ آدرس نامعتبر است. مجدد بفرستید:"); exit; }
                sendMessage($chatId, "⏳ در حال استخراج...");
                $api = new AdvancedSubExtractor();
                $subData = $api->extractSubscription($text);
                $headerInfo = getSubHeaderInfo($text);

                if (is_array($subData) && isset($subData['total_configs']) && $subData['total_configs'] > 0) {
                    $totalBytes = (float)($headerInfo['total'] ?? 0);
                    $usedBytes  = (float)($headerInfo['upload'] ?? 0) + (float)($headerInfo['download'] ?? 0);
                    $volStr     = $totalBytes > 0 ? formatBytes($totalBytes) : 'نامحدود';
                    $usedStr    = $usedBytes > 0 ? formatBytes($usedBytes) : '0 B';
                    $remainStr  = $totalBytes > 0 ? formatBytes(max(0, $totalBytes - $usedBytes)) : 'نامحدود';
                    $expStr     = ($headerInfo['expire'] ?? 0) > 0 ? jdate('Y/m/d H:i', (int)$headerInfo['expire']) : 'نامحدود';

                    $otherText = "";
                    if (($subData['protocols']['custom'] ?? 0) > 0) $otherText .= " | Custom: {$subData['protocols']['custom']}";
                    if (($subData['protocols']['json'] ?? 0) > 0)   $otherText .= " | JSON: {$subData['protocols']['json']}";
                    
                    $resText = "📊 <b>گزارش استخراج:</b>\n\n📦 کل کانفیگ‌ها: {$subData['total_configs']}\n📈 حجم کل: {$volStr}\n📉 مصرف شده: {$usedStr}\n🔋 باقیمانده: {$remainStr}\n⏳ انقضا: {$expStr}\n\n🔹 <b>پروتکل‌ها:</b>\nVLESS: {$subData['protocols']['vless']} | VMess: {$subData['protocols']['vmess']}{$otherText}\n\n📝 <b>نام‌ها:</b>\n";
                    foreach ($subData['configs_list'] as $i => $c) {
                        if ($i >= 30) { $resText .= "\n... و " . ($subData['total_configs'] - 30) . " تای دیگر.\n"; break; }
                        $resText .= ($i + 1) . ". " . addFlagToConfigName($c['name']) . "\n";
                    }

                    // گزارش لحظه‌ای استخراج به تاپیک گزارشات
                    sendTopicReport($pdo, "📊 <b>استخراج ساب انجام شد</b>\nکاربر: {$userMention}\nآیدی: <code>{$chatId}</code>\nتعداد کانفیگ: {$subData['total_configs']}\nزمان: " . jdate('Y/m/d H:i:s'), 'گزارش استخراج 📊', 'report_extract_thread_id');

                    $viewToken = generateExtractionToken();
                    saveExtraction($pdo, $viewToken, $chatId, $text, $subData, $headerInfo);
                    $viewUrl = getWebRootUrl() . "/sub_view.php?id=" . $viewToken;

                    $kb = buildMenuKeyboard($pdo, 'sub_menu', false, false);
                    $webViewLabel = getCustomBtnLabels($pdo)['btn_web_view'] ?? '🖥 مشاهده در وب';
                    $kb['inline_keyboard'][] = [createUrlBtn($webViewLabel, $viewUrl, 'success', 'btn_web_view')];
                    $statePayload = json_encode(['sub_url' => $text, 'time' => time(), 'view_token' => $viewToken]);
                    $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'HAS_SUB_DATA', ?) ON DUPLICATE KEY UPDATE state='HAS_SUB_DATA', data=?")->execute([$chatId, $statePayload, $statePayload]);
                    sendMessage($chatId, $resText, $kb);
                } else {
                    if (($subData['error'] ?? '') === 'html_page') {
                        $errMsg = "🌐 <b>این یک ساب مستقیم نیست!</b>\n\nلینکی که فرستادید یک صفحه‌ی وب (Web Sub) است، نه یک لینک خام (Raw) ساب‌اسکریپشن؛ به همین دلیل ربات نمی‌تواند از آن کانفیگ استخراج کند.\n\n✅ لطفاً لینک ساب مستقیم (معمولاً از داخل پنل یا اپلیکیشن VPN) را ارسال کنید.";
                    } else {
                        $errMsg = "❌ استخراج با شکست مواجه شد.";
                        if ($isAdmin && !empty($subData['debug'])) {
                            $d = $subData['debug'];
                            $errMsg .= "\n\n🛠 <b>اطلاعات فنی (فقط قابل مشاهده برای ادمین):</b>\n";
                            $errMsg .= "کد HTTP: <code>" . ($d['http_code'] ?? '-') . "</code>\n";
                            if (!empty($d['curl_error'])) {
                                $errMsg .= "خطای cURL: <code>" . htmlspecialchars((string)$d['curl_error'], ENT_QUOTES, 'UTF-8') . "</code>\n";
                            }
                            if (!empty($d['snippet'])) {
                                $errMsg .= "نمونه پاسخ سرور:\n<code>" . htmlspecialchars((string)$d['snippet'], ENT_QUOTES, 'UTF-8') . "</code>";
                            }
                        }
                    }
                    sendMessage($chatId, $errMsg);
                }
                exit;
            }

            if ($currentState === 'WAITING_FOR_REPORT_GROUP') {
                if (!$isAdmin) exit;
                $newGroupId = trim($text);
                setSetting($pdo, 'report_group_id', $newGroupId);
                setSetting($pdo, 'report_status', 'on');
                setSetting($pdo, 'report_join_thread_id', '');
                setSetting($pdo, 'report_realtime_thread_id', '');
                setSetting($pdo, 'report_backup_thread_id', '');
                setSetting($pdo, 'report_extract_thread_id', '');
                
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                sendMessage($chatId, "✅ آیدی گروه اصلی با موفقیت تنظیم شد و گزارشات روشن شد.\n\n⚠️ <b>توجه:</b> لطفاً از پنل مدیریت به بخش «گزارشات» بروید و دکمه <b>ساخت/ریست تاپیک‌ها 🔄</b> را بزنید تا تاپیک‌های ربات ساخته شوند.");
                exit;
            }

            if ($currentState === 'WAITING_FOR_REPORT_GROUP_2') {
                if (!$isAdmin) exit;
                $newGroupId = trim($text);
                setSetting($pdo, 'report_group_id_2', $newGroupId);
                setSetting($pdo, 'report_join_thread_id_2', '');
                setSetting($pdo, 'report_realtime_thread_id_2', '');
                setSetting($pdo, 'report_backup_thread_id_2', '');
                setSetting($pdo, 'report_extract_thread_id_2', '');
                
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                sendMessage($chatId, "✅ آیدی گروه پشتیبان (دوم) با موفقیت تنظیم شد.\n\n⚠️ <b>توجه:</b> لطفاً دکمه <b>ساخت/ریست تاپیک‌ها 🔄</b> را بزنید تا تاپیک‌ها در این گروه هم ایجاد شوند.");
                exit;
            }

            if ($currentState === 'WAITING_FOR_WEBPANEL_PASSWORD') {
                if (!$isSupAdmin) exit;
                $newPass = trim($text);
                if (mb_strlen($newPass, 'UTF-8') < 4) {
                    sendMessage($chatId, "❌ رمز جدید باید حداقل ۴ کاراکتر باشد. مجدداً ارسال کنید:");
                    exit;
                }
                setSetting($pdo, 'webpanel_password_hash', password_hash($newPass, PASSWORD_DEFAULT));
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                sendMessage($chatId, "✅ رمز عبور پنل وب با موفقیت تغییر کرد.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'webpanel_settings_nav', 'success', 'btn_admin_back')]]]);
                exit;
            }

            if ($currentState === 'WAITING_FOR_FJ_CHANNEL') {
                if (!$isAdmin) exit;
                setSetting($pdo, 'fj_channel', trim($text));
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                sendMessage($chatId, "✅ کانال ثبت شد.");
                exit;
            }
            if ($currentState === 'WAITING_FOR_USER_ID' || $currentState === 'WAITING_FOR_ADMIN_ID') {
                $targetId = preg_replace('/[^0-9]/', '', $text);
                if (!empty($targetId)) {
                    $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                    sendUserManagePanel($chatId, $targetId, $pdo);
                }
                exit;
            }
            if ($currentState === 'WAITING_FOR_BROADCAST_MESSAGE') {
                if (!$isAdmin) exit;
                $stateData['msg_id'] = $message['message_id'];
                $pdo->prepare("UPDATE user_states SET state='WAITING_FOR_BROADCAST_TYPE', data=? WHERE user_id=?")->execute([json_encode($stateData), $chatId]);
                $kb = ['inline_keyboard' => [
                    [createBtn('📢 فوروارد (نقل قول)', 'broad_fwd', 'primary'), createBtn('📝 کپی پیام', 'broad_copy', 'success')],
                    [createBtn('لغو ارسال', 'cancel_action', 'danger')]
                ]];
                sendMessage($chatId, "✅ پیام دریافت شد. نحوه ارسال؟", $kb);
                exit;
            }
            if ($currentState === 'WAITING_FOR_DIRECT_MESSAGE') {
                if (!$isAdmin) exit;
                $targetId = $stateData['target_id'] ?? 0;
                if ($targetId) {
                    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/copyMessage");
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $targetId, 'from_chat_id' => $chatId, 'message_id' => $message['message_id']], CURLOPT_TIMEOUT => 15]);
                    $resData = json_decode((string)curl_exec($ch), true); curl_close($ch);
                    if (!empty($resData['ok'])) sendMessage($chatId, "✅ پیام شما برای کاربر ارسال شد.");
                    sendUserManagePanel($chatId, $targetId, $pdo);
                }
                $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                exit;
            }
        }
    }

    // ====================================================
    // پردازش دکمه‌های شیشه‌ای (Callbacks)
    // ====================================================
    if ($callbackQuery) {
        $callbackId = $callbackQuery['id'];
        $messageId  = $callbackQuery['message']['message_id'] ?? 0;
        $data       = $callbackQuery['data'];

        // نکته: قبلاً اینجا answerCallback($callbackId) بدون متن صدا زده می‌شد که باعث می‌شد
        // چون هر callback فقط یک بار قابل پاسخ‌دهی است، آلارم‌های بعدی (مثل پیام خطا یا تایید)
        // که در ادامه کد با answerCallback($callbackId, "متن", true) صدا زده می‌شوند هرگز به کاربر نمایش داده نشوند.
        // به همین دلیل پاسخ خالی اولیه حذف شد؛ هر بخش خودش در صورت نیاز پاپ‌آپ را نمایش می‌دهد.

        if ($data === 'cancel_action' || $data === 'delete_this_msg') {
            $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
            editMessageText($chatId, $messageId, "✅ عملیات لغو و منو بسته شد.");
            exit;
        }

        if ($data === 'main_analyze') {
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_SUB_URL', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_SUB_URL', data='{}'")->execute([$chatId]);
            editMessageText($chatId, $messageId, "🔗 لطفاً <b>لینک ساب‌اسکریپشن</b> را بفرستید:\n\n(برای انصراف دکمه زیر را بزنید)", ['inline_keyboard' => [[createBtn('❌ انصراف', 'back_to_main_menu', 'danger', 'btn_back')]]]);
            exit;
        }

        if ($data === 'main_account') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$chatId]);
            $uInfo      = $stmt->fetch();
            $joinedAt  = jdate('Y/m/d - H:i:s', safe_timestamp($uInfo['joined_at'] ?? ''));
            $userGroup = $isSupAdmin ? 'مدیریت کل' : ($isAdmin ? 'ادمین' : 'کاربر عادی');
            $safeName  = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
            editMessageText($chatId, $messageId, "🗂 <b>حساب کاربری شما:</b>\n\n🆔 شناسه کاربری: <code>{$chatId}</code>\n👤 نام: {$safeName}\n⌚️ زمان ثبت نام: {$joinedAt}\n🏷 گروه کاربری: {$userGroup}", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'back_to_main_menu', 'success', 'btn_back')]]]);
            exit;
        }

        if ($data === 'main_admin') {
            if (!$isAdmin) exit;
            editMessageText($chatId, $messageId, "⚙️ <b>پنل مدیریت ربات:</b>\nاز منوی پایین یک گزینه را انتخاب کنید 👇", getAdminInlineMarkup($pdo, $isAdmin, $isSupAdmin));
            exit;
        }

        if ($data === 'settings_folder_nav') {
            if (!$isAdmin) exit;
            $kb = ['inline_keyboard' => []];
            $kb['inline_keyboard'][] = [createBtn('📢 تنظیمات گزارشات', 'manage_reports_nav', 'success', 'btn_admin_reports')];
            if ($isSupAdmin) {
                $kb['inline_keyboard'][] = [createBtn('🌐 تنظیمات پنل وب', 'webpanel_settings_nav', 'primary', 'btn_admin_webpanel')];
                $kb['inline_keyboard'][] = [createBtn('⚙️ پنل تنظیمات کامل', 'settings_panel_nav', 'success', 'btn_admin_settingspanel')];
            }
            $kb['inline_keyboard'][] = [createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')];
            editMessageText($chatId, $messageId, "⚙️ <b>تنظیمات</b>\n\nاز منوی زیر بخش مورد نظر را انتخاب کنید:", $kb);
            exit;
        }

        if ($data === 'backup_folder_nav') {
            if (!$isAdmin) exit;
            $kb = ['inline_keyboard' => []];
            $kb['inline_keyboard'][] = [createBtn('💾 بکاپ دیتابیس', 'backup_db_manual', 'primary', 'btn_admin_db')];
            if ($isSupAdmin) {
                $kb['inline_keyboard'][] = [createBtn('📥 ایمپورت بک‌آپ', 'restore_backup_nav', 'danger', 'btn_admin_restore')];
            }
            $kb['inline_keyboard'][] = [createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')];
            editMessageText($chatId, $messageId, "💾 <b>مدیریت بک‌آپ</b>\n\nاز منوی زیر گزینه مورد نظر را انتخاب کنید:\n\n• 💾 بکاپ دیتابیس: دریافت فایل SQL از دیتابیس فعلی\n• 📥 ایمپورت بک‌آپ: بازیابی امن فایل SQL (فقط مدیر کل)", $kb);
            exit;
        }

        if ($data === 'webpanel_settings_nav') {
            if (!$isSupAdmin) exit;
            $panelUrl = getWebRootUrl() . "/webpanel.php?tab=dashboard";
            $kb = ['inline_keyboard' => [
                [createUrlBtn('🌐 باز کردن پنل وب', $panelUrl, 'primary', 'btn_open_webpanel')],
                [createBtn('🔑 تغییر رمز پنل وب', 'change_webpanel_password_nav', 'success', 'btn_change_webpanel_pass')],
                [createBtn('🔙 بازگشت', 'settings_folder_nav', 'danger', 'btn_admin_back')]
            ]];
            editMessageText($chatId, $messageId, "🌐 <b>تنظیمات پنل وب</b>\n\nآدرس: <code>{$panelUrl}</code>\n\nاز اینجا می‌توانید چیدمان، متن، رنگ و ایموجی دکمه‌ها را مدیریت کنید. رمز پنل هم از همین‌جا قابل تغییر است.", $kb);
            exit;
        }

        if ($data === 'settings_panel_nav') {
            if (!$isSupAdmin) exit;
            $settingsPanelUrl = getWebRootUrl() . "/webpanel.php?tab=fj";
            $kb = ['inline_keyboard' => [
                [createUrlBtn('⚙️ باز کردن پنل تنظیمات کامل', $settingsPanelUrl, 'success', 'btn_open_settings_panel')],
                [createBtn('🔑 تغییر رمز پنل وب', 'change_webpanel_password_nav', 'primary', 'btn_change_webpanel_pass')],
                [createBtn('🔙 بازگشت', 'settings_folder_nav', 'danger', 'btn_admin_back')]
            ]];
            editMessageText($chatId, $messageId, "⚙️ <b>پنل تنظیمات کامل</b>\n\nآدرس: <code>{$settingsPanelUrl}</code>\n\nقفل کانال، گزارشات، کرون، مدیران، کاربران، تنظیمات عمومی، پیام همگانی و بکاپ همه از همین پنل یکپارچه (webpanel.php) مدیریت می‌شوند.\nرمز ورود دقیقاً همان رمز پنل دکمه‌ها است.", $kb);
            exit;
        }

        if ($data === 'change_webpanel_password_nav') {
            if (!$isSupAdmin) exit;
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_WEBPANEL_PASSWORD', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_WEBPANEL_PASSWORD', data='{}'")->execute([$chatId]);
            editMessageText($chatId, $messageId, "🔑 رمز عبور جدید پنل وب را ارسال کنید (حداقل ۴ کاراکتر):", ['inline_keyboard' => [[createBtn('❌ انصراف', 'webpanel_settings_nav', 'danger', 'btn_back')]]]);
            exit;
        }

        if ($data === 'restore_backup_nav') {
            if (!$isSupAdmin) { answerCallback($callbackId, '❌ این بخش فقط برای مدیر کل فعال است.', true); exit; }
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_BACKUP_RESTORE', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_BACKUP_RESTORE', data='{}'")->execute([$chatId]);
            editMessageText($chatId, $messageId, "📥 <b>ایمپورت هوشمند بک‌آپ</b>\n\nفایل بک‌آپ دیتابیس (<code>.sql</code>) که قبلاً از همین ربات (بکاپ دستی یا خودکار) گرفته شده را ارسال کنید.\n\n⚠️ <b>توجه:</b>\n- فقط رکوردهای <b>جدید</b> اضافه می‌شوند؛ اطلاعات فعلی شما تغییر یا حذف نمی‌شود (ایمپورت کاملاً امن).\n- فقط فایل‌های <code>.sql</code> پذیرفته می‌شوند.\n- فقط جداول شناخته‌شده‌ی ربات (کاربران، تنظیمات، مدیران، ساب‌ها) ایمپورت می‌شوند.", ['inline_keyboard' => [[createBtn('❌ انصراف', 'backup_folder_nav', 'danger', 'btn_back')]]]);
            exit;
        }

        if ($data === 'back_to_main_menu') {
            $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
            editMessageText($chatId, $messageId, "🏠 منوی اصلی ربات:", getMainInlineMarkup($isAdmin, $pdo));
            exit;
        }

        if ($data === 'bot_stats_nav') {
            if (!$isAdmin) exit;
            $pingStart = microtime(true);
            $ch        = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/getMe");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
            $botPing      = round((microtime(true) - $pingStart) * 1000);
            $totalUsers   = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $blockedUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE is_blocked=1")->fetchColumn();
            editMessageText($chatId, $messageId, "📊 <b>آمار ربات:</b>\n\n👥 کاربران: <code>{$totalUsers}</code> نفر\n🚫 مسدود شده: <code>{$blockedUsers}</code> نفر\n⏱ پینگ: <code>{$botPing}ms</code>\n🏷 نسخه: <b>v1.0 team kan</b>", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'main_admin', 'success', 'btn_admin_back')]]]);
            exit;
        }

        if ($data === 'manage_single_user_nav') {
            if (!$isAdmin) exit;
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_USER_ID', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_USER_ID', data='{}'")->execute([$chatId]);
            editMessageText($chatId, $messageId, "🆔 <b>آیدی عددی</b> کاربر را جهت جستجو و مدیریت بفرستید:", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')]]]);
            exit;
        }

        if ($data === 'broadcast_message_nav') {
            if (!$isAdmin) exit;
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_BROADCAST_MESSAGE', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_BROADCAST_MESSAGE', data='{}'")->execute([$chatId]);
            editMessageText($chatId, $messageId, "📢 لطفاً پیام همگانی را بفرستید (متن، عکس، ویدیو و ... مجاز است):", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')]]]);
            exit;
        }

        if ($data === 'toggle_public_mode') {
            if (!$isAdmin) exit;
            $current = getSetting($pdo, 'public_mode', '0');
            if ($current === '1') {
                setSetting($pdo, 'public_mode', '0');
            } else {
                setSetting($pdo, 'public_mode', '1');
            }
            answerCallback($callbackId, "وضعیت تغییر کرد.", true);
            editMessageText($chatId, $messageId, "⚙️ <b>پنل مدیریت ربات:</b>\nاز منوی پایین یک گزینه را انتخاب کنید 👇", getAdminInlineMarkup($pdo, $isAdmin, $isSupAdmin));
            exit;
        }

        if ($data === 'force_join_manage') {
            if (!$isAdmin) exit;
            $fj_s       = getSetting($pdo, 'fj_status', 'off') === 'on' ? '🟢 فعال' : '🔴 غیرفعال';
            $fj_c       = getSetting($pdo, 'fj_channel', '');
            $fj_channel = !empty($fj_c) ? $fj_c : 'تنظیم نشده';
            
            $kb = ['inline_keyboard' => [
                [createBtn('تغییر وضعیت 🔄', 'fj_toggle', 'primary', 'btn_fj_toggle')],
                [createBtn('حذف کانال 🗑', 'fj_remove', 'danger', 'btn_fj_remove'), createBtn('تنظیم کانال ➕', 'fj_set', 'success', 'btn_fj_set')],
                [createBtn('🔙 بازگشت', 'main_admin', 'primary', 'btn_admin_back')]
            ]];
            editMessageText($chatId, $messageId, "📢 <b>قفل کانال (جوین اجباری):</b>\nوضعیت: {$fj_s}\nآیدی: <code>{$fj_channel}</code>", $kb);
            exit;
        }

        if ($data === 'admins_manage_nav') {
            if (!$isSupAdmin) {
                answerCallback($callbackId, "❌ این بخش فقط برای مدیر کل فعال است.", true);
                exit;
            }
            $admins = $pdo->query("SELECT user_id, is_admin FROM users WHERE is_admin = 1")->fetchAll();
            $text   = "👮‍♂️ <b>مدیریت مدیران ربات</b>\n\nلیست فعلی:\n";
            $kb     = ['inline_keyboard' => []];
            $row    = [];
            foreach ($admins as $ad) {
                $role       = 'ادمین';
                $safeAdId   = htmlspecialchars((string)$ad['user_id'], ENT_QUOTES, 'UTF-8');
                $text      .= "🔸 <code>{$safeAdId}</code> - ({$role})\n";
                $row[]      = createBtn("مدیریت " . $safeAdId, "manage_user_{$safeAdId}", 'primary', 'btn_admin_users');
                if (count($row) == 2) { $kb['inline_keyboard'][] = $row; $row = []; }
            }
            if (!empty($row)) $kb['inline_keyboard'][] = $row;
            $kb['inline_keyboard'][] = [createBtn('➕ افزودن مدیر با آیدی', 'add_admin_nav', 'success', 'btn_admin')];
            $kb['inline_keyboard'][] = [createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')];
            editMessageText($chatId, $messageId, $text, $kb);
            exit;
        }

        if ($data === 'manage_reports_nav') {
            if (!$isAdmin) exit;
            $repStatus = getSetting($pdo, 'report_status', 'off') === 'on' ? '🟢 روشن' : '🔴 خاموش';
            $repGroup  = getSetting($pdo, 'report_group_id', '');
            $repGroupText = !empty($repGroup) ? $repGroup : 'تنظیم نشده';
            $repGroup2  = getSetting($pdo, 'report_group_id_2', '');
            $repGroupText2 = !empty($repGroup2) ? $repGroup2 : 'تنظیم نشده';
            
            $kb = ['inline_keyboard' => [
                [createBtn('تغییر وضعیت 🔄', 'toggle_report_status', 'primary', 'btn_report_toggle')],
                [createBtn('تنظیم گروه اصلی ⚙️', 'set_report_group', 'success'), createBtn('گروه پشتیبان ⚙️', 'set_report_group_2', 'primary')],
                [createBtn('حذف گروه پشتیبان 🗑', 'del_report_group_2', 'danger')],
                [createBtn('ساخت/ریست تاپیک‌ها 🔄', 'reset_report_topics', 'primary'), createBtn('تست تاپیک‌ها 🧪', 'test_report_topics', 'success')],
                [createBtn('🔙 بازگشت', 'settings_folder_nav', 'danger', 'btn_admin_back')]
            ]];
            editMessageText($chatId, $messageId, "📢 <b>تنظیمات گزارشات خودکار (اصلی و پشتیبان)</b>\nوضعیت کلی: {$repStatus}\n\nگروه اصلی: <code>{$repGroupText}</code>\nگروه پشتیبان: <code>{$repGroupText2}</code>\n\nنکته: پس از تنظیم گروه‌ها، حتماً دکمه «ساخت تاپیک‌ها» را بزنید.", $kb);
            exit;
        }

        if ($data === 'cron_settings_nav') {
            if (!$isAdmin) exit;
            $interval = (int)getSetting($pdo, 'cron_interval', '300');
            if ($interval == 0) $status = "🔴 غیرفعال";
            elseif ($interval == 300) $status = "۵ دقیقه";
            elseif ($interval == 3600) $status = "۱ ساعت";
            elseif ($interval == 43200) $status = "۱۲ ساعت";
            elseif ($interval == 86400) $status = "۲۴ ساعت";
            else $status = $interval . " ثانیه";

            $kb = ['inline_keyboard' => [
                [createBtn('۵ دقیقه', 'set_cron_300', 'primary'), createBtn('۱ ساعت', 'set_cron_3600', 'primary')],
                [createBtn('۱۲ ساعت', 'set_cron_43200', 'primary'), createBtn('۲۴ ساعت', 'set_cron_86400', 'primary')],
                [createBtn('🔴 خاموش', 'set_cron_0', 'danger')],
                [createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')]
            ]];
            editMessageText($chatId, $messageId, "⏱ <b>تنظیمات کرون‌جاب بکاپ</b>\n\nوضعیت فعلی: <b>{$status}</b>\n\nنکته: شما باید کرون‌جاب هاست را روی ۱ دقیقه تنظیم کنید، سپس از اینجا مشخص کنید هر چند وقت یکبار بکاپ ارسال شود.", $kb);
            exit;
        }

        if (str_starts_with($data, 'set_cron_')) {
            if (!$isAdmin) exit;
            $val = str_replace('set_cron_', '', $data);
            setSetting($pdo, 'cron_interval', $val);
            answerCallback($callbackId, "✅ زمان کرون تغییر کرد.", true);
            
            $interval = (int)$val;
            if ($interval == 0) $status = "🔴 غیرفعال";
            elseif ($interval == 300) $status = "۵ دقیقه";
            elseif ($interval == 3600) $status = "۱ ساعت";
            elseif ($interval == 43200) $status = "۱۲ ساعت";
            elseif ($interval == 86400) $status = "۲۴ ساعت";
            else $status = $interval . " ثانیه";

            $kb = ['inline_keyboard' => [
                [createBtn('۵ دقیقه', 'set_cron_300', 'primary'), createBtn('۱ ساعت', 'set_cron_3600', 'primary')],
                [createBtn('۱۲ ساعت', 'set_cron_43200', 'primary'), createBtn('۲۴ ساعت', 'set_cron_86400', 'primary')],
                [createBtn('🔴 خاموش', 'set_cron_0', 'danger')],
                [createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')]
            ]];
            editMessageText($chatId, $messageId, "⏱ <b>تنظیمات کرون‌جاب بکاپ</b>\n\nوضعیت فعلی: <b>{$status}</b>", $kb);
            exit;
        }

        if ($data === 'backup_db_manual') {
            if (!$isAdmin) exit;
            answerCallback($callbackId, "⏳ در حال آماده‌سازی و ارسال...");
            $file = createDbBackupFile($pdo);
            
            if ($file && file_exists($file)) {
                $caption = "💾 <b>بکاپ دستی دیتابیس</b>";
                
                $adminCaption = applyPremiumToText($caption); 
                $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'document' => new CURLFile(realpath($file)), 'caption' => $adminCaption, 'parse_mode' => 'HTML'],
                    CURLOPT_TIMEOUT => 30]);
                curl_exec($ch); curl_close($ch);
                
                sendTopicReport($pdo, $caption . "\n👤 تهیه شده توسط: <code>{$chatId}</code>\n🕒 زمان: " . jdate('Y/m/d H:i:s'), 'بکاپ سیستم 📦', 'report_backup_thread_id', $file);
                
                unlink($file);
            } else {
                sendMessage($chatId, "❌ خطا در ساخت بکاپ.");
            }
            exit;
        }

        if ($data === 'premium_folder_nav' || $data === 'toggle_account_btn') {
            if (!$isAdmin) exit;
            
            if ($data === 'toggle_account_btn') {
                $curr = getSetting($pdo, 'show_account_btn', 'on');
                if ($curr === 'on') {
                    setSetting($pdo, 'show_account_btn', 'off');
                } else {
                    setSetting($pdo, 'show_account_btn', 'on');
                }
            }
            
            $accStatus = getSetting($pdo, 'show_account_btn', 'on') === 'on' ? '🟢 روشن' : '🔴 خاموش';
            $kb = ['inline_keyboard' => [
                [createBtn('✨ ایموجی دکمه‌ها', 'premium_manage_emojis_0', 'primary', 'btn_admin_emoji'), createBtn('📝 ایموجی متن‌ها', 'text_manage_emojis_0', 'success', 'btn_admin_text_emoji')],
                [createBtn('🎨 تنظیم رنگ دکمه‌ها', 'premium_manage_colors_0', 'primary', 'btn_admin_color')],
                [createBtn('🗂 دکمه حساب کاربری: ' . $accStatus, 'toggle_account_btn', 'primary', 'btn_main_account')],
                [createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')]
            ]];
            editMessageText($chatId, $messageId, "🌟 <b>تنظیمات پیشرفته (پرمیوم)</b>\n\nمدیریت استایل‌ها، ایموجی دکمه‌ها و متن‌ها:", $kb);
            exit;
        }

        if (str_starts_with($data, 'text_manage_emojis_')) {
            if (!$isAdmin) exit;
            $pageStr = str_replace('text_manage_emojis_', '', $data);
            $page    = is_numeric($pageStr) ? max(0, (int)$pageStr) : 0;
            $limit   = 40; 
            $offset  = $page * $limit;
            
            $emojis = array_keys(getPremiumTextEmojis());
            $totalEmojis = count($emojis);
            $pagedEmojis = array_slice($emojis, $offset, $limit);
            
            $kb = ['inline_keyboard' => []];
            $row = [];
            foreach ($pagedEmojis as $em) {
                $row[] = createBtn($em, 'set_txemoji_' . $em, 'primary');
                if (count($row) === 4) { $kb['inline_keyboard'][] = $row; $row = []; }
            }
            if (!empty($row)) $kb['inline_keyboard'][] = $row;
            
            $navRow = [];
            if ($page > 0) {
                $navRow[] = createBtn('⬅️ قبلی', 'text_manage_emojis_' . ($page - 1), 'primary');
            }
            if ($offset + $limit < $totalEmojis) {
                $navRow[] = createBtn('بعدی ➡️', 'text_manage_emojis_' . ($page + 1), 'primary');
            }
            if (!empty($navRow)) $kb['inline_keyboard'][] = $navRow;
            
            $kb['inline_keyboard'][] = [createBtn('🔙 بازگشت به تنظیمات', 'premium_folder_nav', 'danger')];
            $totalPages = ceil($totalEmojis / $limit);
            
            editMessageText($chatId, $messageId, "📝 <b>تنظیم ایموجی متن‌ها</b>\n\n📄 صفحه " . ($page + 1) . " از {$totalPages}\nبرای تنظیم یک ایموجی متحرک، روی آیکون موردنظر کلیک کنید:", $kb);
            exit;
        }

        if (str_starts_with($data, 'set_txemoji_')) {
            if (!$isAdmin) exit;
            $emojiChar = str_replace('set_txemoji_', '', $data);
            $sd = json_encode(['text_emoji_char' => $emojiChar]);
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_TEXT_EMOJI', ?) ON DUPLICATE KEY UPDATE state='WAITING_FOR_TEXT_EMOJI', data=?")->execute([$chatId, $sd, $sd]);
            editMessageText($chatId, $messageId, "📝 برای ایموجی <b>{$emojiChar}</b> یک ایموجی متحرک بفرستید.\n\n(برای لغو عدد 0 بفرستید)", ['inline_keyboard' => [[createBtn('🔙 لغو', 'text_manage_emojis_0', 'danger')]]]);
            exit;
        }

        if (str_starts_with($data, 'premium_manage_emojis') || str_starts_with($data, 'premium_manage_colors')) {
            if (!$isAdmin) exit;

            $isEmojiMode = str_starts_with($data, 'premium_manage_emojis');
            $modePrefix  = $isEmojiMode ? 'premium_manage_emojis' : 'premium_manage_colors';
            $pageStr     = str_replace($modePrefix . '_', '', $data);
            $page        = ($pageStr === $modePrefix || !is_numeric($pageStr)) ? 0 : max(0, (int)$pageStr);

            $registry = getBtnRegistry();
            $allKeys  = array_keys($registry);
            $limit    = 10;
            $offset   = $page * $limit;
            $pagedKeys = array_slice($allKeys, $offset, $limit);
            $totalKeys = count($allKeys);

            $setPrefix = $isEmojiMode ? 'set_emoji_' : 'set_color_';
            $title     = $isEmojiMode ? '✨ تنظیم ایموجی متحرک دکمه‌ها' : '🎨 تنظیم رنگ دکمه‌ها';

            $kb  = ['inline_keyboard' => []];
            $row = [];
            foreach ($pagedKeys as $btnKey) {
                $label = $registry[$btnKey]['label'] ?? $btnKey;
                $row[] = createBtn($label, $setPrefix . $btnKey, 'primary');
                if (count($row) === 2) { $kb['inline_keyboard'][] = $row; $row = []; }
            }
            if (!empty($row)) $kb['inline_keyboard'][] = $row;

            $navRow = [];
            if ($page > 0) {
                $navRow[] = createBtn('⬅️ قبلی', $modePrefix . '_' . ($page - 1), 'primary');
            }
            if ($offset + $limit < $totalKeys) {
                $navRow[] = createBtn('بعدی ➡️', $modePrefix . '_' . ($page + 1), 'primary');
            }
            if (!empty($navRow)) $kb['inline_keyboard'][] = $navRow;

            $kb['inline_keyboard'][] = [createBtn('🔙 بازگشت به منو', 'premium_folder_nav', 'success')];
            $totalPages = max(1, (int)ceil($totalKeys / $limit));

            editMessageText($chatId, $messageId, "{$title}\n\n📄 صفحه " . ($page + 1) . " از {$totalPages} (مجموع {$totalKeys} دکمه)\nروی نام دکمه مورد نظر کلیک کنید:", $kb);
            exit;
        }

        if (str_starts_with($data, 'set_emoji_')) {
            if (!$isAdmin) exit;
            $btnKey = str_replace('set_emoji_', '', $data);
            $sd     = json_encode(['btn_key' => $btnKey]);
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_EMOJI_ID', ?) ON DUPLICATE KEY UPDATE state='WAITING_FOR_EMOJI_ID', data=?")->execute([$chatId, $sd, $sd]);
            editMessageText($chatId, $messageId, "✨ برای کلید <b><code>{$btnKey}</code></b> یک ایموجی متحرک بفرستید.\n\n(برای حذف ایموجی عدد 0 بفرستید)", ['inline_keyboard' => [[createBtn('🔙 لغو', 'premium_manage_emojis_0', 'danger', 'btn_back')]]]);
            exit;
        }

        if (str_starts_with($data, 'set_color_')) {
            if (!$isAdmin) exit;
            $btnKey = str_replace('set_color_', '', $data);
            $sd     = json_encode(['btn_key' => $btnKey]);
            $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_BTN_COLOR', ?) ON DUPLICATE KEY UPDATE state='WAITING_FOR_BTN_COLOR', data=?")->execute([$chatId, $sd, $sd]);
            
            $colorMenu = ['inline_keyboard' => [
                [createBtn('آبی (Primary)', "apply_color_primary", 'primary'), createBtn('سبز (Success)', "apply_color_success", 'success')],
                [createBtn('قرمز (Danger)', "apply_color_danger", 'danger'), createBtn('حذف رنگ (Default)', "apply_color_remove")],
                [createBtn('🔙 لغو', 'premium_manage_colors_0', 'danger', 'btn_back')]
            ]];
            editMessageText($chatId, $messageId, "🎨 استایل رنگی دکمه <b><code>{$btnKey}</code></b> را انتخاب کنید:\n\n(فقط ۳ رنگ اصلی رسمی پشتیبانی می‌شود)", $colorMenu);
            exit;
        }

        if (str_starts_with($data, 'apply_color_')) {
            if (!$isAdmin) exit;
            $color = str_replace('apply_color_', '', $data);
            
            if (in_array($color, ['secondary', 'warning'])) {
                answerCallback($callbackId, "❌ این رنگ در آپدیت جدید پشتیبانی نمی‌شود.", true);
                exit;
            }

            $stmt = $pdo->prepare("SELECT data FROM user_states WHERE user_id = ? AND state = 'WAITING_FOR_BTN_COLOR'");
            $stmt->execute([$chatId]);
            $state = $stmt->fetch();
            if ($state) {
                $stateData = json_decode($state['data'], true);
                $btnKey    = $stateData['btn_key'] ?? '';
                
                if ($btnKey) {
                    $colorsJson = getSetting($pdo, 'premium_colors', '{}');
                    $colors = json_decode($colorsJson, true);
                    if (!is_array($colors)) $colors = [];
                    
                    if ($color === 'remove') {
                        unset($colors[$btnKey]);
                    } else {
                        $colors[$btnKey] = $color;
                    }
                    
                    setSetting($pdo, 'premium_colors', json_encode($colors));
                    $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                    
                    editMessageText($chatId, $messageId, "✅ رنگ دکمه با موفقیت تنظیم شد.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'premium_manage_colors_0', 'success', 'btn_back')]]]);
                    exit;
                }
            }
            answerCallback($callbackId, "❌ خطا در تنظیم رنگ.", true);
            exit;
        }

        if (str_starts_with($data, 'users_list_page_')) {
            if (!$isAdmin) exit;
            $pageStr = str_replace('users_list_page_', '', $data);
            $page    = is_numeric($pageStr) ? max(0, (int)$pageStr) : 0;
            $limit   = 10;
            $offset  = $page * $limit;

            $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $users = $pdo->query("SELECT user_id, joined_at FROM users ORDER BY joined_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset)->fetchAll();

            $text = "👥 <b>بخش مدیریت کاربران</b>\n\n📊 تعداد کل کاربران: <code>{$totalUsers}</code> نفر\n📄 صفحه فعلی: " . ($page + 1) . "\n\n👇 جهت مدیریت، روی آیدی مورد نظر کلیک کنید:";
            $kb   = ['inline_keyboard' => []];
            $row  = [];

            if (count($users) > 0) {
                foreach ($users as $u) {
                    $safeUserId   = htmlspecialchars((string)$u['user_id'], ENT_QUOTES, 'UTF-8');
                    $row[]        = createBtn("👤 " . $safeUserId, "manage_user_{$safeUserId}", 'primary', 'btn_admin_users');
                    if (count($row) == 2) { $kb['inline_keyboard'][] = $row; $row = []; }
                }
                if (!empty($row)) $kb['inline_keyboard'][] = $row;
            } else {
                $text .= "\n\n❌ کاربری یافت نشد.";
            }

            $kb['inline_keyboard'][] = [createBtn('🔍 جستجو کاربر', 'manage_single_user_nav', 'success', 'btn_admin_search')];

            $navRow = [];
            if ($page > 0)                      $navRow[] = createBtn('⬅️ قبلی', "users_list_page_" . ($page - 1), 'primary', 'btn_nav_prev');
            if ($offset + $limit < $totalUsers) $navRow[] = createBtn('بعدی ➡️', "users_list_page_" . ($page + 1), 'primary', 'btn_nav_next');
            if (!empty($navRow)) $kb['inline_keyboard'][] = $navRow;
            
            $kb['inline_keyboard'][] = [createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')];

            editMessageText($chatId, $messageId, $text, $kb);
            exit;
        }

        if (str_starts_with($data, 'manage_user_') && is_numeric(str_replace('manage_user_', '', $data))) {
            if (!$isAdmin) exit;
            $targetId = str_replace('manage_user_', '', $data);
            sendUserManagePanel($chatId, $targetId, $pdo, $messageId);
            exit;
        }

        if (preg_match('/^(umsg|ublock|uadmin)_(\d+)$/', $data, $matches)) {
            if (!$isAdmin) exit;
            $action   = $matches[1];
            $targetId = $matches[2];

            if ($action === 'umsg') {
                $sd = json_encode(['target_id' => $targetId]);
                $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_DIRECT_MESSAGE', ?) ON DUPLICATE KEY UPDATE state='WAITING_FOR_DIRECT_MESSAGE', data=?")->execute([$chatId, $sd, $sd]);
                editMessageText($chatId, $messageId, "💬 پیام خود را بفرستید (متن، عکس و...):", ['inline_keyboard' => [[createBtn('❌ لغو ارسال', "manage_user_{$targetId}", 'danger', 'btn_back')]]]);
            } else {
                $stmt = $pdo->prepare("SELECT is_blocked, is_admin FROM users WHERE user_id = ?");
                $stmt->execute([$targetId]);
                $uStatus = $stmt->fetch();
                if ($uStatus) {
                    if ($action === 'ublock') { 
                        $newVal = empty($uStatus['is_blocked']) ? 1 : 0; 
                        $pdo->prepare("UPDATE users SET is_blocked = ? WHERE user_id = ?")->execute([$newVal, $targetId]); 
                    }
                    if ($action === 'uadmin') { 
                        if (!$isSupAdmin) exit; 
                        $newVal = empty($uStatus['is_admin']) ? 1 : 0; 
                        $pdo->prepare("UPDATE users SET is_admin = ? WHERE user_id = ?")->execute([$newVal, $targetId]); 
                    }
                }
                sendUserManagePanel($chatId, $targetId, $pdo, $messageId);
            }
            exit;
        }

        if (in_array($data, ['get_extracted_configs', 'get_extracted_ips', 'update_sub_data', 'get_qr_code', 'get_configs_qr'])) {
            $stmt = $pdo->prepare("SELECT data FROM user_states WHERE user_id = ? AND state = 'HAS_SUB_DATA'");
            $stmt->execute([$chatId]);
            $state = $stmt->fetch();

            if ($state) {
                $stateData = json_decode($state['data'], true);
                $subUrl    = $stateData['sub_url'] ?? '';
                $stateTime = $stateData['time'] ?? 0;

                // دکمه‌ی «مشاهده در وب» که باید در تمام صفحه‌های زیرمنوی استخراج
                // (دریافت کانفیگ/آی‌پی/QR و بروزرسانی) هم در دسترس بماند
                $viewToken   = $stateData['view_token'] ?? '';
                $webViewRow  = [];
                if ($viewToken) {
                    $webViewLabel = getCustomBtnLabels($pdo)['btn_web_view'] ?? '🖥 مشاهده در وب';
                    $webViewUrl   = getWebRootUrl() . "/sub_view.php?id=" . $viewToken;
                    $webViewRow   = [createUrlBtn($webViewLabel, $webViewUrl, 'success', 'btn_web_view')];
                }

                if (time() - $stateTime > 300) {
                    answerCallback($callbackId, '❌ زمان ۵ دقیقه‌ای این پنل تمام شده است.', true);
                    $pdo->prepare("DELETE FROM user_states WHERE user_id = ? AND state = 'HAS_SUB_DATA'")->execute([$chatId]);
                    editMessageText($chatId, $messageId, applyPremiumToText("❌ <b>سشن منقضی شد!</b>\n\nلطفاً لینک ساب‌اسکریپشن خود را مجدداً ارسال کنید."));
                    exit;
                }

                if ($subUrl) {
                    if ($data === 'update_sub_data') {
                        editMessageText($chatId, $messageId, "⏳ لطفا چند لحظه صبر کنید...");
                        $headerInfo = getSubHeaderInfo($subUrl);
                        $subData    = class_exists('AdvancedSubExtractor') ? (new AdvancedSubExtractor())->extractSubscription($subUrl) : null;
                        if (is_array($subData) && isset($subData['total_configs']) && $subData['total_configs'] > 0) {
                            $totalBytes      = (float)($headerInfo['total']   ?? 0);
                            $usedBytes       = (float)($headerInfo['upload']  ?? 0) + (float)($headerInfo['download'] ?? 0);
                            $remainBytes     = max(0, $totalBytes - $usedBytes);
                            $expireTimestamp = (int)($headerInfo['expire'] ?? 0);
                            $volStr    = $totalBytes > 0      ? formatBytes($totalBytes)  : 'نامحدود';
                            $usedStr   = $usedBytes > 0       ? formatBytes($usedBytes)   : '0 B';
                            $remainStr = $totalBytes > 0      ? formatBytes($remainBytes) : 'نامحدود';
                            $expStr    = $expireTimestamp > 0 ? jdate('Y/m/d H:i', $expireTimestamp) : 'نامحدود';

                            $otherText = "";
                            if (($subData['protocols']['custom'] ?? 0) > 0) $otherText .= " | Custom: {$subData['protocols']['custom']}";
                            if (($subData['protocols']['json'] ?? 0) > 0)   $otherText .= " | JSON: {$subData['protocols']['json']}";
                            if (($subData['protocols']['other'] ?? 0) > 0)  $otherText .= " | سایر: {$subData['protocols']['other']}";
                            
                            $resText = "📊 <b>گزارش استخراج (بروزرسانی شده):</b>\n\n📦 کل کانفیگ‌ها: {$subData['total_configs']}\n📈 حجم کل: {$volStr}\n📉 مصرف شده: {$usedStr}\n🔋 باقیمانده: {$remainStr}\n⏳ انقضا: {$expStr}\n\n🔹 <b>پروتکل‌ها:</b>\nVLESS: {$subData['protocols']['vless']} | VMess: {$subData['protocols']['vmess']} | Trojan: {$subData['protocols']['trojan']}{$otherText}\n\n📝 <b>نام‌ها:</b>\n";
                            
                            foreach ($subData['configs_list'] as $i => $c) {
                                if ($i >= 30) { $resText .= "\n... و " . ($subData['total_configs'] - 30) . " تای دیگر.\n"; break; }
                                $nameWithFlag = addFlagToConfigName($c['name']);
                                $resText .= ($i + 1) . ". {$nameWithFlag}\n";
                            }

                            $viewToken = $stateData['view_token'] ?? generateExtractionToken();
                            saveExtraction($pdo, $viewToken, $chatId, $subUrl, $subData, $headerInfo);
                            $viewUrl = getWebRootUrl() . "/sub_view.php?id=" . $viewToken;

                            $newStatePayload = json_encode(['sub_url' => $subUrl, 'time' => time(), 'view_token' => $viewToken]);
                            $pdo->prepare("UPDATE user_states SET data = ? WHERE user_id = ? AND state = 'HAS_SUB_DATA'")->execute([$newStatePayload, $chatId]);

                            $kb = buildMenuKeyboard($pdo, 'sub_menu', false, false);
                            $webViewLabel = getCustomBtnLabels($pdo)['btn_web_view'] ?? '🖥 مشاهده در وب';
                            $kb['inline_keyboard'][] = [createUrlBtn($webViewLabel, $viewUrl, 'success', 'btn_web_view')];
                            editMessageText($chatId, $messageId, $resText, $kb);
                        } else {
                            if (is_array($subData) && ($subData['error'] ?? '') === 'html_page') {
                                editMessageText($chatId, $messageId, "🌐 <b>این یک ساب مستقیم نیست!</b>\n\nلینک ثبت‌شده یک صفحه‌ی وب (Web Sub) است، نه یک لینک خام؛ بنابراین امکان بروزرسانی کانفیگ‌ها وجود ندارد.\n\n✅ لطفاً یک لینک ساب مستقیم (Raw) جدید ارسال کنید.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'back_to_main_menu', 'danger', 'btn_back')]]]);
                            } else {
                                editMessageText($chatId, $messageId, "❌ خطا در بروزرسانی.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'back_to_main_menu', 'danger', 'btn_back')]]]);
                            }
                        }
                        exit;
                    }

                    if ($data === 'get_extracted_configs') {
                        if (!class_exists('AdvancedSubExtractor')) { answerCallback($callbackId, "❌ اتصال برقرار نیست.", true); exit; }
                        $subData = (new AdvancedSubExtractor())->extractSubscription($subUrl);
                        if (is_array($subData) && !empty($subData['configs_list'])) {
                            $messages    = [];
                            $currentText = "✅ <b>کانفیگ‌های شما:</b>\n\n";
                            foreach ($subData['configs_list'] as $c) {
                                $nameWithFlag = addFlagToConfigName($c['name']);
                                $block = "📌 نام: {$nameWithFlag}\n📡 پروتکل: " . strtolower($c['protocol']) . "\n<code>{$c['raw']}</code>\n<b>team kan</b>\n\n";
                                if (mb_strlen($currentText . $block, 'UTF-8') > 3900) { $messages[] = $currentText; $currentText = ""; }
                                $currentText .= $block;
                            }
                            if (!empty($currentText)) $messages[] = $currentText;
                            foreach ($messages as $idx => $msg) {
                                $kb = ($idx == count($messages) - 1) ? ['inline_keyboard' => array_values(array_filter([$webViewRow, [createBtn('🔙 بازگشت', 'back_to_main_menu', 'success', 'btn_back')]]))] : null;
                                sendMessage($chatId, $msg, $kb);
                                usleep(250000);
                            }
                        } else {
                            $emptyText = (is_array($subData) && ($subData['error'] ?? '') === 'html_page')
                                ? "🌐 این لینک یک صفحه‌ی وب است، نه ساب مستقیم؛ کانفیگی برای دریافت وجود ندارد."
                                : "❌ کانفیگی یافت نشد.";
                            sendMessage($chatId, $emptyText, ['inline_keyboard' => array_values(array_filter([$webViewRow, [createBtn('🔙 بازگشت', 'back_to_main_menu', 'danger', 'btn_back')]]))]);
                        }
                        exit;
                    }

                    if ($data === 'get_configs_qr') {
                        if (!class_exists('AdvancedSubExtractor')) { answerCallback($callbackId, "❌ اتصال برقرار نیست.", true); exit; }
                        $subData = (new AdvancedSubExtractor())->extractSubscription($subUrl);
                        if (is_array($subData) && !empty($subData['configs_list'])) {
                            sendMessage($chatId, "⏳ در حال ساخت بارکد...");
                            $mediaGroup = [];
                            $count      = 0;
                            foreach ($subData['configs_list'] as $c) {
                                $qrUrl        = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($c['raw']) . "&bgcolor=255-255-255&color=0-0-0&margin=1";
                                $nameWithFlag = addFlagToConfigName($c['name']);
                                $caption = applyPremiumToText("📌 <b>{$nameWithFlag}</b>\n📡 " . strtoupper($c['protocol']) . "\n<b>team kan</b>"); 
                                $mediaGroup[] = ['type' => 'photo', 'media' => $qrUrl, 'caption' => $caption, 'parse_mode' => 'HTML'];
                                $count++;
                                if (count($mediaGroup) == 10 || $count == count($subData['configs_list'])) {
                                    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMediaGroup");
                                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'media' => json_encode($mediaGroup)], CURLOPT_TIMEOUT => 20]);
                                    curl_exec($ch); curl_close($ch);
                                    $mediaGroup = [];
                                    usleep(500000);
                                }
                            }
                            sendMessage($chatId, "✅ بارکدها ارسال شد.", ['inline_keyboard' => array_values(array_filter([$webViewRow, [createBtn('🔙 بازگشت', 'back_to_main_menu', 'success', 'btn_back')]]))]);
                        } else {
                            $emptyText = (is_array($subData) && ($subData['error'] ?? '') === 'html_page')
                                ? "🌐 این لینک یک صفحه‌ی وب است، نه ساب مستقیم؛ QR کدی برای ساخت وجود ندارد."
                                : "❌ کانفیگی یافت نشد.";
                            sendMessage($chatId, $emptyText);
                        }
                        exit;
                    }

                    if ($data === 'get_extracted_ips') {
                        if (!class_exists('AdvancedSubExtractor')) { answerCallback($callbackId, "❌ اتصال برقرار نیست.", true); exit; }
                        $subData = (new AdvancedSubExtractor())->extractSubscription($subUrl);
                        $ips     = [];
                        if (is_array($subData) && !empty($subData['configs_list'])) {
                            foreach ($subData['configs_list'] as $c) {
                                $raw = $c['raw'];
                                if (preg_match('/@([^:\/?]+)/', $raw, $m)) { $h = trim($m[1]); if (!empty($h) && $h !== '127.0.0.1') $ips[$h] = true; }
                                if (preg_match('/(\?|&)(sni|host)=([^&#]+)/', $raw, $m)) { $h = trim($m[3]); if (!empty($h)) $ips[$h] = true; }
                            }
                        }
                        $ipList = array_keys($ips);
                        if (count($ipList) > 0) {
                            $ipText = "🌐 <b>لیست آی‌پی‌ها:</b>\n\n";
                            foreach ($ipList as $host) {
                                $resolvedIp = gethostbyname($host);
                                if ($resolvedIp !== $host && filter_var($resolvedIp, FILTER_VALIDATE_IP)) $ipText .= "🔗 دامنه: <code>{$host}</code>\n🟢 آی‌پی: <code>{$resolvedIp}</code>\n\n";
                                else                                                                        $ipText .= "🟢 آی‌پی/دامنه: <code>{$host}</code>\n\n";
                            }
                            foreach (splitTextSafely($ipText, 3900) as $idx => $msg) {
                                $kb = ($idx == count(splitTextSafely($ipText, 3900)) - 1) ? ['inline_keyboard' => array_values(array_filter([$webViewRow, [createBtn('🔙 بازگشت', 'back_to_main_menu', 'success', 'btn_back')]]))] : null;
                                sendMessage($chatId, $msg, $kb);
                                usleep(250000);
                            }
                        } else {
                            $emptyText = (is_array($subData) && ($subData['error'] ?? '') === 'html_page')
                                ? "🌐 این لینک یک صفحه‌ی وب است، نه ساب مستقیم؛ آی‌پی قابل استخراج نیست."
                                : "❌ هیچ آی‌پی یافت نشد.";
                            sendMessage($chatId, $emptyText, ['inline_keyboard' => array_values(array_filter([$webViewRow, [createBtn('🔙 بازگشت', 'back_to_main_menu', 'danger', 'btn_back')]]))]);
                        }
                        exit;
                    }

                    if ($data === 'get_qr_code') {
                        $qrUrl    = "https://quickchart.io/qr?text=" . urlencode($subUrl) . "&size=500&margin=2&dark=000000&light=ffffff";
                        $ch       = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto");
                        $caption  = applyPremiumToText("𔲲 <b>کیو‌آر کد ساب‌اسکریپشن شما</b>\n\nلینک: <code>{$subUrl}</code>"); 
                        $postData = ['chat_id' => $chatId, 'photo' => $qrUrl, 'caption' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => array_values(array_filter([$webViewRow, [createBtn('🔙 بازگشت', 'back_to_main_menu', 'success', 'btn_back')]]))])];
                        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 15]);
                        curl_exec($ch); curl_close($ch);
                        exit;
                    }
                }
            } else {
                answerCallback($callbackId, '❌ اطلاعات ساب منقضی شده.', true);
            }
            exit;
        }

        if ($data === 'reset_report_topics') {
            if (!$isAdmin) exit;
            if (getSetting($pdo, 'report_status', 'off') !== 'on') {
                answerCallback($callbackId, '❌ اول باید گزارشات را روشن کنید!', true);
                exit;
            }
            answerCallback($callbackId, '⏳ در حال ریست و ساخت مجدد تاپیک‌ها...', false);

            setSetting($pdo, 'report_join_thread_id', '');
            setSetting($pdo, 'report_realtime_thread_id', '');
            setSetting($pdo, 'report_backup_thread_id', '');
            setSetting($pdo, 'report_extract_thread_id', '');
            
            setSetting($pdo, 'report_join_thread_id_2', '');
            setSetting($pdo, 'report_realtime_thread_id_2', '');
            setSetting($pdo, 'report_backup_thread_id_2', '');
            setSetting($pdo, 'report_extract_thread_id_2', '');

            sendTopicReport($pdo, "✅ تاپیک <b>ورود کاربران</b> با موفقیت ساخته و متصل شد.", 'ورود کاربران 🚪', 'report_join_thread_id');
            sendTopicReport($pdo, "✅ تاپیک <b>گزارشات لحظه‌ای</b> با موفقیت ساخته و متصل شد.", 'گزارش لحظه‌ای ⏳', 'report_realtime_thread_id');
            sendTopicReport($pdo, "✅ تاپیک <b>بکاپ سیستم</b> با موفقیت ساخته و متصل شد.", 'بکاپ سیستم 📦', 'report_backup_thread_id');
            sendTopicReport($pdo, "✅ تاپیک <b>گزارش استخراج</b> با موفقیت ساخته و متصل شد.", 'گزارش استخراج 📊', 'report_extract_thread_id');

            sendMessage($chatId, "✅ <b>عملیات موفق!</b>\nتاپیک‌های گزارشات در گروه‌های تنظیم‌شده ریست و مجدداً ساخته شدند.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'manage_reports_nav', 'success')]]]);
            exit;
        }

        if ($data === 'test_report_topics') {
            if (!$isAdmin) exit;
            if (getSetting($pdo, 'report_status', 'off') !== 'on') {
                answerCallback($callbackId, '❌ گزارشات خاموش است!', true);
                exit;
            }
            answerCallback($callbackId, '⏳ در حال ارسال پیام تست...', false);

            sendTopicReport($pdo, "🧪 <b>تست تاپیک:</b> ارتباط با بخش ورود کاربران برقرار است.", 'ورود کاربران 🚪', 'report_join_thread_id');
            sendTopicReport($pdo, "🧪 <b>تست تاپیک:</b> ارتباط با بخش گزارش لحظه‌ای برقرار است.", 'گزارش لحظه‌ای ⏳', 'report_realtime_thread_id');
            sendTopicReport($pdo, "🧪 <b>تست تاپیک:</b> ارتباط با بخش بکاپ سیستم برقرار است.", 'بکاپ سیستم 📦', 'report_backup_thread_id');
            sendTopicReport($pdo, "🧪 <b>تست تاپیک:</b> ارتباط با بخش گزارش استخراج برقرار است.", 'گزارش استخراج 📊', 'report_extract_thread_id');

            sendMessage($chatId, "✅ پیام‌های تست به تاپیک‌ها ارسال شد.\nگروه‌های خود را چک کنید.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'manage_reports_nav', 'success')]]]);
            exit;
        }

        switch ($data) {
            case 'toggle_report_status':
                if (!$isAdmin) exit;
                $current = getSetting($pdo, 'report_status', 'off');
                $newStat = $current === 'on' ? 'off' : 'on';
                setSetting($pdo, 'report_status', $newStat);
                
                $repStatus = $newStat === 'on' ? '🟢 روشن' : '🔴 خاموش';
                $repGroup  = getSetting($pdo, 'report_group_id', '');
                $repGroupText = !empty($repGroup) ? $repGroup : 'تنظیم نشده';
                $repGroup2 = getSetting($pdo, 'report_group_id_2', '');
                $repGroupText2 = !empty($repGroup2) ? $repGroup2 : 'تنظیم نشده';
                
                editMessageText($chatId, $messageId, "📢 <b>تنظیمات گزارشات خودکار (اصلی و پشتیبان)</b>\nوضعیت کلی: {$repStatus}\n\nگروه اصلی: <code>{$repGroupText}</code>\nگروه پشتیبان: <code>{$repGroupText2}</code>\n\nنکته: پس از تنظیم گروه‌ها، حتماً دکمه «ساخت تاپیک‌ها» را بزنید.", ['inline_keyboard' => [
                    [createBtn('تغییر وضعیت 🔄', 'toggle_report_status', 'primary', 'btn_report_toggle')],
                    [createBtn('تنظیم گروه اصلی ⚙️', 'set_report_group', 'success'), createBtn('گروه پشتیبان ⚙️', 'set_report_group_2', 'primary')],
                    [createBtn('حذف گروه پشتیبان 🗑', 'del_report_group_2', 'danger')],
                    [createBtn('ساخت/ریست تاپیک‌ها 🔄', 'reset_report_topics', 'primary'), createBtn('تست تاپیک‌ها 🧪', 'test_report_topics', 'success')],
                    [createBtn('🔙 بازگشت', 'settings_folder_nav', 'danger', 'btn_admin_back')]
                ]]);
                break;

            case 'set_report_group':
                if (!$isAdmin) exit;
                $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_REPORT_GROUP', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_REPORT_GROUP', data='{}'")->execute([$chatId]);
                editMessageText($chatId, $messageId, "📢 لطفا آیدی گروه اصلی (مثلاً -100...) را بفرستید:\n\nربات باید ادمین گروه باشد.", ['inline_keyboard' => [[createBtn('لغو', 'manage_reports_nav', 'danger', 'btn_back')]]]);
                break;
                
            case 'set_report_group_2':
                if (!$isAdmin) exit;
                $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_REPORT_GROUP_2', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_REPORT_GROUP_2', data='{}'")->execute([$chatId]);
                editMessageText($chatId, $messageId, "📢 لطفا آیدی گروه پشتیبان (دوم) را بفرستید (مثلاً -100...):\n\nربات باید در این گروه نیز ادمین باشد تا بتواند تاپیک بسازد.", ['inline_keyboard' => [[createBtn('لغو', 'manage_reports_nav', 'danger', 'btn_back')]]]);
                break;

            case 'del_report_group_2':
                if (!$isAdmin) exit;
                setSetting($pdo, 'report_group_id_2', '');
                setSetting($pdo, 'report_join_thread_id_2', '');
                setSetting($pdo, 'report_realtime_thread_id_2', '');
                setSetting($pdo, 'report_backup_thread_id_2', '');
                setSetting($pdo, 'report_extract_thread_id_2', '');
                answerCallback($callbackId, '✅ گروه پشتیبان با موفقیت حذف شد.', true);
                
                $repStatus = getSetting($pdo, 'report_status', 'off') === 'on' ? '🟢 روشن' : '🔴 خاموش';
                $repGroup  = getSetting($pdo, 'report_group_id', '');
                $repGroupText = !empty($repGroup) ? $repGroup : 'تنظیم نشده';
                $repGroup2 = getSetting($pdo, 'report_group_id_2', '');
                $repGroupText2 = !empty($repGroup2) ? $repGroup2 : 'تنظیم نشده';
                
                editMessageText($chatId, $messageId, "📢 <b>تنظیمات گزارشات خودکار (اصلی و پشتیبان)</b>\nوضعیت کلی: {$repStatus}\n\nگروه اصلی: <code>{$repGroupText}</code>\nگروه پشتیبان: <code>{$repGroupText2}</code>\n\nنکته: پس از تنظیم گروه‌ها، حتماً دکمه «ساخت تاپیک‌ها» را بزنید.", ['inline_keyboard' => [
                    [createBtn('تغییر وضعیت 🔄', 'toggle_report_status', 'primary', 'btn_report_toggle')],
                    [createBtn('تنظیم گروه اصلی ⚙️', 'set_report_group', 'success'), createBtn('گروه پشتیبان ⚙️', 'set_report_group_2', 'primary')],
                    [createBtn('حذف گروه پشتیبان 🗑', 'del_report_group_2', 'danger')],
                    [createBtn('ساخت/ریست تاپیک‌ها 🔄', 'reset_report_topics', 'primary'), createBtn('تست تاپیک‌ها 🧪', 'test_report_topics', 'success')],
                    [createBtn('🔙 بازگشت', 'settings_folder_nav', 'danger', 'btn_admin_back')]
                ]]);
                break;

            case 'fj_toggle':
            case 'fj_remove':
                if (!$isAdmin) exit;
                if ($data === 'fj_toggle') { 
                    $curr = getSetting($pdo, 'fj_status', 'off');
                    if ($curr === 'on') {
                        setSetting($pdo, 'fj_status', 'off');
                    } else {
                        setSetting($pdo, 'fj_status', 'on');
                    }
                }
                if ($data === 'fj_remove') { 
                    setSetting($pdo, 'fj_channel', ''); 
                }
                
                $fj_s       = getSetting($pdo, 'fj_status', 'off') === 'on' ? '🟢 فعال' : '🔴 غیرفعال';
                $fj_c       = getSetting($pdo, 'fj_channel', '');
                $fj_channel = !empty($fj_c) ? $fj_c : 'تنظیم نشده';
                
                editMessageText($chatId, $messageId, "📢 <b>قفل کانال:</b>\nوضعیت: {$fj_s}\nآیدی: <code>{$fj_channel}</code>", ['inline_keyboard' => [
                    [createBtn('تغییر وضعیت 🔄', 'fj_toggle', 'primary', 'btn_fj_toggle')],
                    [createBtn('حذف کانال 🗑', 'fj_remove', 'danger', 'btn_fj_remove'), createBtn('تنظیم کانال ➕', 'fj_set', 'success', 'btn_fj_set')],
                    [createBtn('🔙 بازگشت', 'main_admin', 'primary', 'btn_admin_back')]
                ]]);
                break;

            case 'fj_set':
                if (!$isAdmin) exit;
                $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_FJ_CHANNEL', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_FJ_CHANNEL', data='{}'")->execute([$chatId]);
                editMessageText($chatId, $messageId, "آیدی کانال را با `@` یا `-100` بفرستید:\n\n⚠️ ربات باید در کانال ادمین باشد.", ['inline_keyboard' => [[createBtn('❌ انصراف', 'force_join_manage', 'danger', 'btn_back')]]]);
                break;

            case 'add_admin_nav':
                if (!$isSupAdmin) exit;
                $pdo->prepare("INSERT INTO user_states (user_id, state, data) VALUES (?, 'WAITING_FOR_ADMIN_ID', '{}') ON DUPLICATE KEY UPDATE state='WAITING_FOR_ADMIN_ID', data='{}'")->execute([$chatId]);
                editMessageText($chatId, $messageId, "🆔 لطفاً <b>آیدی عددی</b> شخصی که می‌خواهید دسترسی بدهید را بفرستید:", ['inline_keyboard' => [[createBtn('❌ انصراف', 'admins_manage_nav', 'danger', 'btn_back')]]]);
                break;

            case 'broad_fwd':
            case 'broad_copy':
                if (!$isAdmin) exit;
                $stmt = $pdo->prepare("SELECT data FROM user_states WHERE user_id = ? AND state = 'WAITING_FOR_BROADCAST_TYPE'");
                $stmt->execute([$chatId]);
                $state = $stmt->fetch();
                if ($state) {
                    $stateData = json_decode($state['data'], true);
                    $msgId     = $stateData['msg_id'];
                    $pdo->prepare("DELETE FROM user_states WHERE user_id = ?")->execute([$chatId]);
                    editMessageText($chatId, $messageId, "⏳ در حال ارسال همگانی...");
                    $users  = $pdo->query("SELECT user_id FROM users")->fetchAll();
                    $method = ($data === 'broad_fwd') ? '/forwardMessage' : '/copyMessage';
                    $sent   = 0;
                    $failed = 0;
                    foreach ($users as $u) {
                        $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . $method);
                        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => ['chat_id' => $u['user_id'], 'from_chat_id' => $chatId, 'message_id' => $msgId],
                            CURLOPT_TIMEOUT => 5]);
                        $res = json_decode((string)curl_exec($ch), true);
                        curl_close($ch);
                        if (!empty($res['ok'])) $sent++; else $failed++;
                        usleep(50000);
                    }
                    sendMessage($chatId, "✅ ارسال همگانی تمام شد.\n\n✔️ موفق: {$sent}\n❌ ناموفق: {$failed}", ['inline_keyboard' => [[createBtn('🔙 بازگشت به پنل', 'main_admin', 'success', 'btn_admin_back')]]]);
                } else {
                    sendMessage($chatId, "❌ سشن منقضی شده است.", ['inline_keyboard' => [[createBtn('🔙 بازگشت', 'main_admin', 'danger', 'btn_admin_back')]]]);
                }
                break;
        }
    }

} catch (\Throwable $e) {
    error_log("Bot Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    if (defined('ADMIN_ID')) {
        $errorMsg = applyPremiumToText("⚠️ <b>خطای سیستم!</b>\n\n<code>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</code>\n\nفایل: " . basename($e->getFile()) . " (خط " . $e->getLine() . ")"); 
        $ch       = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['chat_id' => ADMIN_ID, 'text' => $errorMsg, 'parse_mode' => 'HTML'],
            CURLOPT_TIMEOUT        => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
