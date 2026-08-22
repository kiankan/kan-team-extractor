<?php
// inc_extractor.php
// شامل: کلاس AdvancedSubExtractor برای گرفتن و پارس‌کردن محتوای سابسکریپشن،
// و تابع getSubHeaderInfo برای خوندن هدر subscription-userinfo (حجم/تاریخ انقضا).
// نکته: این کلاس دقیقاً همون نسخه‌ی قوی‌تریه که bot.php داخلش داره (Clash YAML،
// sing-box JSON، fallback با User-Agent مرورگر، gzip) تا فیچر «بروزرسانی زنده»ی
// sub_view.php (که از همینجا از طریق sub_refresh.php استفاده می‌کنه) روی هر
// ساب‌اسکریپشنی که خودِ ربات می‌تونه استخراج کنه، شکست نخوره.
declare(strict_types=1);

if (!function_exists('curl_init')) {
    // اگه اکستنشن curl فعال نباشه، بهتره همین‌جا واضح خطا بدیم تا catch بالادستی بگیرتش
    throw new RuntimeException('اکستنشن cURL روی این هاست فعال نیست.');
}

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

    // پارس یک پاسخِ از قبل دریافت‌شده (بدون دوباره fetch کردن) — برای وقتی که خودِ
    // fetch با curl_multi و موازی با درخواست‌های دیگه انجام شده (fetchSubAndHeaderParallel)
    public function extractFromResponse(string $response): array {
        $configs = $this->parseAny($response);
        return $this->buildResult($configs);
    }

    // نسخه‌ی عمومی fetchUrl، برای زمانی که orchestration بیرونی (مثل curl_multi
    // موازی) نیاز به گرفتن پاسخ خام داره ولی پارسش رو بعداً با extractFromResponse انجام می‌ده
    public function fetchRaw(string $url, bool $useBrowserUA = false): ?string {
        return $this->fetchUrl($url, $useBrowserUA);
    }

    private function parseAny(string $response): array {
        if ($this->isWireguardConf($response)) {
            $configs = $this->parseWireguardConf($response);
            if (!empty($configs)) return $configs;
        }
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
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
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

    // خیلی از پنل‌های اختصاصی وایرگارد (مثل asan-ps) به جای لیست لینک، مستقیماً
    // فایل استاندارد wg-quick (بخش‌های [Interface]/[Peer]) رو به‌عنوان بدنه‌ی
    // ساب برمی‌گردونن؛ این تابع تشخیص می‌ده که پاسخ از این نوعه یا نه.
    private function isWireguardConf(string $content): bool {
        return (bool) preg_match('/^\s*\[Interface\]\s*$/mi', $content);
    }

    // یک یا چند بلاک کامل wg-quick رو (اگه ساب چند سرور رو پشت‌سرهم چسبونده باشه)
    // پارس می‌کنه و از هر بلاک، Endpoint داخل [Peer] رو برای host/port در میاره.
    // متن خامِ خودِ بلاک بدون تغییر به‌عنوان raw نگه داشته می‌شه تا مستقیماً به‌شکل
    // یک فایل .conf معتبر قابل استفاده/دانلود باشه.
    private function parseWireguardConf(string $content): array {
        $configs = [];
        $content = str_replace("\r\n", "\n", $content);

        $blocks = preg_split('/(?=^[ \t]*\[Interface\][ \t]*$)/mi', $content);
        if (!$blocks) return [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '' || !preg_match('/^\[Interface\]/i', $block)) continue;

            $section  = '';
            $iface    = [];
            $peers    = [];
            $peer     = [];
            $comments = [];

            foreach (explode("\n", $block) as $line) {
                $line = trim($line);
                if ($line === '') continue;

                if (preg_match('/^#+\s*(.+)$/', $line, $cm)) {
                    $comments[] = trim($cm[1]);
                    continue;
                }
                if (preg_match('/^\[(Interface|Peer)\]$/i', $line, $sm)) {
                    if (strcasecmp($section, 'Peer') === 0 && !empty($peer)) {
                        $peers[] = $peer;
                        $peer = [];
                    }
                    $section = strtolower($sm[1]);
                    continue;
                }
                if (strpos($line, '=') === false) continue;

                [$key, $val] = array_map('trim', explode('=', $line, 2));
                $key = strtolower($key);

                if ($section === 'interface') {
                    $iface[$key] = $val;
                } elseif ($section === 'peer') {
                    $peer[$key] = $val;
                }
            }
            if (!empty($peer)) $peers[] = $peer;

            if (empty($iface) || empty($peers)) continue;

            $server = '';
            $port   = '';
            $endpoint = $peers[0]['endpoint'] ?? '';
            if ($endpoint !== '' && preg_match('/^(\[[^\]]+\]|[^:\s]+):(\d{1,5})$/', $endpoint, $em)) {
                $server = trim($em[1], '[]');
                $port   = $em[2];
            }

            $name = '';
            foreach ($comments as $c) {
                if ($c !== '') { $name = $c; break; }
            }
            if ($name === '') {
                $name = $server !== '' ? $server : 'WireGuard Config';
            }

            $configs[] = [
                'name'     => $name,
                'protocol' => 'WIREGUARD',
                'raw'      => $block,
                'server'   => $server,
                'port'     => $port,
            ];
        }
        return $configs;
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

    /**
     * هاست و پورت واقعی سرور رو از متن خام کانفیگ در میاره (برای فیچر «تست سرورها»).
     * نسخه‌ی این کلاس داخل bot.php از inc_extractor.php جداست، برای همین این متد
     * اینجا هم جدا تعریف شده (منطقش با extractHostPort جاوااسکریپتی sub_view.php یکیه).
     */
    public function extractHostPort(string $raw): array {
        $empty = ['host' => '', 'port' => ''];
        if ($raw === '') return $empty;

        // بلاک خامِ wg-quick (خروجی parseWireguardConf) با اسکیم شروع نمی‌شه؛
        // Endpoint داخل [Peer] رو مستقیم از متن در میاریم.
        if (preg_match('/^\[Interface\]/i', trim($raw))) {
            if (preg_match('/^\s*Endpoint\s*=\s*(\[[^\]]+\]|[^:\s]+):(\d{1,5})\s*$/mi', $raw, $em)) {
                return ['host' => trim($em[1], '[]'), 'port' => $em[2]];
            }
            return $empty;
        }

        if (!preg_match('#^([a-z0-9]+)://#i', $raw, $sm)) return $empty;
        $scheme = strtolower($sm[1]);

        try {
            // vmess:// base64(json) با فیلدهای add و port
            if ($scheme === 'vmess') {
                $body = explode('#', substr($raw, 8))[0];
                $body = explode('?', $body)[0];
                $decoded = $this->safeBase64Decode($body);
                $json = $decoded !== null ? json_decode($decoded, true) : null;
                if (is_array($json)) {
                    return ['host' => (string)($json['add'] ?? ''), 'port' => (string)($json['port'] ?? '')];
                }
                return $empty;
            }

            // ssr:// base64(host:port:protocol:method:obfs:base64pass/?params)
            if ($scheme === 'ssr') {
                $body = explode('#', substr($raw, 6))[0];
                $decoded = $this->safeBase64Decode($body) ?? '';
                if (preg_match('/^([^:]+):(\d+):/', $decoded, $m)) {
                    return ['host' => $m[1], 'port' => $m[2]];
                }
                return $empty;
            }

            // ss:// دو شکل شناخته‌شده: ss://method:pass@host:port یا ss://BASE64(...)
            if ($scheme === 'ss') {
                $rest = explode('#', substr($raw, 5))[0];
                if (preg_match('/@([^:\/?#]+):(\d+)/', $rest, $m)) {
                    return ['host' => $m[1], 'port' => $m[2]];
                }
                $decoded = $this->safeBase64Decode(explode('?', $rest)[0]) ?? '';
                if (preg_match('/@([^:\/?#]+):(\d+)/', $decoded, $m)) {
                    return ['host' => $m[1], 'port' => $m[2]];
                }
                return $empty;
            }

            // فرم عمومی برای vless, trojan, hysteria, hysteria2/hy2, tuic, socks, http(s)
            if (preg_match('~^[a-z0-9]+://(?:[^@/?#]*@)?(\[[^\]]+\]|[^:/?#]+)(?::(\d+))?~i', $raw, $m)) {
                $host = trim($m[1], '[]');
                return ['host' => $host, 'port' => $m[2] ?? ''];
            }
        } catch (\Throwable $e) {
            return $empty;
        }

        return $empty;
    }
}

/**
 * هدر subscription-userinfo رو از سرور ساب می‌خونه.
 * فرمت استاندارد: "subscription-userinfo: upload=123; download=456; total=789; expire=1735689600"
 * خروجی: ['total'=>float, 'upload'=>float, 'download'=>float, 'expire'=>int]
 */
function getSubHeaderInfo(string $url): array
{
    $result = ['total' => 0.0, 'upload' => 0.0, 'download' => 0.0, 'expire' => 0];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) v2rayNG/1.8.29',
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return $result;
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr((string)$response, 0, $headerSize);

    if (preg_match('/subscription-userinfo:\s*(.+)/i', $headers, $m)) {
        $line = trim($m[1]);
        // ممکنه چند خط هدر باشه (چند ریدایرکت)، فقط خط اول کافیه
        $line = strtok($line, "\r\n");

        foreach (explode(';', $line) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, '=') === false) continue;
            [$key, $val] = array_map('trim', explode('=', $pair, 2));
            $key = strtolower($key);
            switch ($key) {
                case 'upload':   $result['upload']   = (float)$val; break;
                case 'download': $result['download'] = (float)$val; break;
                case 'total':    $result['total']    = (float)$val; break;
                case 'expire':   $result['expire']   = (int)$val;   break;
            }
        }
    }

    return $result;
}
