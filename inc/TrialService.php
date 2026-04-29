<?php

class TrialService
{
    public static function isEnabled(): bool
    {
        $enabled = strtolower((string) Config::get('TRIAL_ENABLED', '0'));
        return in_array($enabled, ['1', 'true', 'yes', 'on'], true);
    }

    public static function allowedOrigin(): string
    {
        return trim((string) Config::get('TRIAL_ALLOWED_ORIGIN', ''));
    }

    public static function siteKey(): string
    {
        return trim((string) Config::get('TRIAL_YANDEX_SMARTCAPTCHA_SITEKEY', ''));
    }

    public static function getClientIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $parts = explode(',', $xff);
            $candidate = trim((string) ($parts[0] ?? ''));
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        $xRealIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($xRealIp !== '' && filter_var($xRealIp, FILTER_VALIDATE_IP)) {
            return $xRealIp;
        }

        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return '0.0.0.0';
    }

    public static function sendCorsHeaders(): void
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $allowed = self::allowedOrigin();

        if ($allowed !== '') {
            if ($origin !== '' && $origin === $allowed) {
                header('Access-Control-Allow-Origin: ' . $allowed);
                header('Vary: Origin');
            }
        } elseif ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }

    public static function isOriginAllowed(): bool
    {
        $allowed = self::allowedOrigin();
        if ($allowed === '') {
            return true;
        }
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        return $origin !== '' && $origin === $allowed;
    }

    public static function verifyCaptcha(string $token, string $ip): bool
    {
        $provider = strtolower((string) Config::get('TRIAL_CAPTCHA_PROVIDER', 'yandex'));
        if ($provider !== 'yandex') {
            return false;
        }

        $secret = trim((string) Config::get('TRIAL_YANDEX_SMARTCAPTCHA_SECRET', ''));
        if ($secret === '' || $token === '') {
            return false;
        }

        $payload = http_build_query([
            'secret' => $secret,
            'token' => $token,
            'ip' => $ip,
        ]);

        $response = '';
        $url = 'https://smartcaptcha.yandexcloud.net/validate';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $response = (string) curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 10,
                ],
            ]);
            $response = (string) @file_get_contents($url, false, $ctx);
        }

        if ($response === '') {
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return false;
        }

        if (($decoded['status'] ?? '') === 'ok') {
            return true;
        }
        if (($decoded['success'] ?? false) === true) {
            return true;
        }

        return false;
    }

    public static function ensureRateLimit(string $ipHash): void
    {
        $hours = (int) Config::get('TRIAL_RATE_LIMIT_HOURS', '24');
        if ($hours <= 0) {
            $hours = 24;
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) 
            FROM trial_issuances 
            WHERE ip_hash = ? AND created_at >= (NOW() - INTERVAL ? HOUR)
        ');
        $stmt->execute([$ipHash, $hours]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            throw new Exception('trial_limit_reached');
        }
    }

    public static function resolveOwnerUserId(): int
    {
        $pdo = DB::conn();
        $adminEmail = trim((string) Config::get('ADMIN_EMAIL', ''));

        if ($adminEmail !== '') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$adminEmail]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        $id = (int) $stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        throw new Exception('admin_user_not_found');
    }

    public static function issueTrial(string $ip, string $userAgent): array
    {
        if (!self::isEnabled()) {
            throw new Exception('trial_disabled');
        }

        $serverId = (int) Config::get('TRIAL_SERVER_ID', '0');
        if ($serverId <= 0) {
            throw new Exception('trial_server_not_configured');
        }

        $protocolId = (int) Config::get('TRIAL_PROTOCOL_ID', '0');
        $durationSeconds = (int) Config::get('TRIAL_DURATION_SECONDS', '3600');
        if ($durationSeconds <= 0) {
            $durationSeconds = 3600;
        }
        $trafficMb = (int) Config::get('TRIAL_TRAFFIC_LIMIT_MB', '200');
        if ($trafficMb <= 0) {
            $trafficMb = 200;
        }

        $ipHash = hash('sha256', $ip . '|' . (string) Config::get('JWT_SECRET', ''));
        self::ensureRateLimit($ipHash);

        $ownerUserId = self::resolveOwnerUserId();
        $name = 'trial_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12);
        $token = bin2hex(random_bytes(16));

        $clientId = 0;
        try {
            $clientId = VpnClient::create(
                $serverId,
                $ownerUserId,
                $name,
                null,
                $protocolId > 0 ? $protocolId : null,
                null,
                $name
            );

            $expiresAt = gmdate('Y-m-d H:i:s', time() + $durationSeconds);
            VpnClient::setExpiration($clientId, $expiresAt);

            $client = new VpnClient($clientId);
            $client->setTrafficLimit($trafficMb * 1024 * 1024);
            $link = $client->getAmneziaLink();

            if ($link === '') {
                throw new Exception('trial_link_generation_failed');
            }

            $pdo = DB::conn();
            $ins = $pdo->prepare('
                INSERT INTO trial_issuances (token, client_id, ip_hash, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            $safeUa = function_exists('mb_substr') ? mb_substr($userAgent, 0, 255) : substr($userAgent, 0, 255);

            $ins->execute([
                $token,
                $clientId,
                $ipHash,
                $safeUa,
                $expiresAt,
            ]);
        } catch (Throwable $e) {
            if ($clientId > 0) {
                try {
                    $bad = new VpnClient($clientId);
                    $bad->delete();
                } catch (Throwable $cleanup) {
                }
            }
            throw $e;
        }

        return [
            'token' => $token,
            'client_id' => $clientId,
            'expires_at' => $expiresAt,
            'amnezia_link' => $link,
            'traffic_limit_mb' => $trafficMb,
            'duration_seconds' => $durationSeconds,
        ];
    }
}

