<?php
// inc_extractor.php
// شامل: کلاس AdvancedSubExtractor برای گرفتن و پارس‌کردن محتوای سابسکریپشن،
// و تابع getSubHeaderInfo برای خوندن هدر subscription-userinfo (حجم/تاریخ انقضا).
declare(strict_types=1);

if (!function_exists('curl_init')) {
    // اگه اکستنشن curl فعال نباشه، بهتره همین‌جا واضح خطا بدیم تا catch بالادستی بگیرتش
    throw new RuntimeException('اکستنشن cURL روی این هاست فعال نیست.');
}

class AdvancedSubExtractor
{
    private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) v2rayNG/1.8.29';

    /**
     * لینک ساب رو می‌گیره، دانلود می‌کنه، دیکد (در صورت نیاز) و پارس می‌کنه.
     * خروجی: ['total_configs'=>int, 'protocols'=>[PROTO=>count], 'configs_list'=>[['protocol','name','raw'],...], 'error'?=>string]
     */
    public function extractSubscription(string $url): array
    {
        $content = $this->fetchUrl($url);

        if ($content === false || trim($content) === '') {
            return [
                'total_configs' => 0,
                'protocols'     => [],
                'configs_list'  => [],
                'error'         => 'fetch_failed',
            ];
        }

        $trimmed = ltrim($content);
        if (stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0) {
            return [
                'total_configs' => 0,
                'protocols'     => [],
                'configs_list'  => [],
                'error'         => 'html_page',
            ];
        }

        $body = $this->normalizeToConfigLines($content);
        $lines = preg_split('/\r\n|\r|\n/', trim($body));

        $configs   = [];
        $protocols = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('#^([a-z0-9]+)://#i', $line, $m)) continue;

            $protocol = strtolower($m[1]);
            $name     = $this->extractName($line, $protocol);

            $configs[] = [
                'protocol' => $protocol,
                'name'     => $name,
                'raw'      => $line,
            ];

            $p = strtoupper($protocol);
            $protocols[$p] = ($protocols[$p] ?? 0) + 1;
        }

        return [
            'total_configs' => count($configs),
            'protocols'     => $protocols,
            'configs_list'  => $configs,
        ];
    }

    private function fetchUrl(string $url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return $body === false ? false : $body;
    }

    // محتوای دریافتی معمولاً کل‌ش base64 هست؛ گاهی هم مستقیم متن خام کانفیگ‌هاست
    private function normalizeToConfigLines(string $content): string
    {
        $clean = trim($content);

        if (strpos($clean, '://') !== false && substr_count($clean, '://') > 1) {
            return $clean; // احتمالاً از قبل متن خام کانفیگ‌هاست
        }

        $normalized = str_replace(['-', '_'], ['+', '/'], $clean);
        $decoded    = base64_decode($normalized, true);

        if ($decoded !== false && strpos($decoded, '://') !== false) {
            return $decoded;
        }

        return $clean;
    }

    private function extractName(string $line, string $protocol): string
    {
        $hashPos = strrpos($line, '#');
        if ($hashPos !== false) {
            $name = urldecode(substr($line, $hashPos + 1));
            $name = trim($name);
            if ($name !== '') return $name;
        }

        if ($protocol === 'vmess') {
            $b64     = substr($line, 8);
            $decoded = $this->decodeBase64Safe($b64);
            $json    = json_decode($decoded, true);
            if (is_array($json) && !empty($json['ps'])) {
                return (string)$json['ps'];
            }
        }

        return strtoupper($protocol) . ' Config';
    }

    private function decodeBase64Safe(string $str): string
    {
        $str = str_replace(['-', '_'], ['+', '/'], $str);
        $pad = strlen($str) % 4;
        if ($pad) $str .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode($str, true);
        return $decoded !== false ? $decoded : '';
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
