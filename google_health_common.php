<?php

define('TOKEN_FILE', __DIR__ . '/token.txt');
define('GOOGLE_HEALTH_API_URL', 'https://health.googleapis.com/v4');
define('GOOGLE_HEALTH_WEIGHT_SCOPE', 'https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.writeonly');

function load_token()
{
    if (!file_exists(TOKEN_FILE)) {
        return null;
    }

    $token_data = json_decode(file_get_contents(TOKEN_FILE), true);
    return is_array($token_data) ? $token_data : null;
}

function save_token($token_data)
{
    $encoded = json_encode($token_data, JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents(TOKEN_FILE, $encoded, LOCK_EX) === false) {
        return false;
    }

    @chmod(TOKEN_FILE, 0600);
    return true;
}

function google_health_log($message)
{
    $log_file = __DIR__ . '/google_health_log.txt';
    $timestamp = date('d.m.y H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

function google_health_configured()
{
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''
        && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== ''
        && defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI !== '';
}

function google_health_curl_unavailable_result()
{
    return [
        'status' => 0,
        'data' => null,
        'response' => null,
        'curl_error' => 'PHP cURL extension is not loaded.',
    ];
}

function google_health_token_request($data)
{
    if (!function_exists('curl_init')) {
        return google_health_curl_unavailable_result();
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $http_code,
        'data' => is_string($response) ? json_decode($response, true) : null,
        'response' => $response,
        'curl_error' => $curl_error,
    ];
}

function refresh_token($token_data)
{
    if (empty($token_data['refresh_token']) || !google_health_configured()) {
        return null;
    }

    $result = google_health_token_request([
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $token_data['refresh_token'],
        'grant_type' => 'refresh_token',
    ]);

    if ($result['status'] !== 200 || empty($result['data']['access_token'])) {
        google_health_log('Token refresh failed: ' . ($result['response'] ?: $result['curl_error']));
        return null;
    }

    $updated_token = array_merge($token_data, $result['data']);
    $updated_token['provider'] = 'google_health';
    $updated_token['refresh_token'] = $result['data']['refresh_token'] ?? $token_data['refresh_token'];
    $updated_token['expires_at'] = time() + intval($result['data']['expires_in'] ?? 3600);
    save_token($updated_token);

    return $updated_token['access_token'];
}

function google_health_api_request($method, $url, $access_token, $body = null)
{
    if (!function_exists('curl_init')) {
        return google_health_curl_unavailable_result();
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $http_code,
        'data' => is_string($response) ? json_decode($response, true) : null,
        'response' => $response,
        'curl_error' => $curl_error,
    ];
}

function google_health_get_identity($access_token)
{
    return google_health_api_request('GET', GOOGLE_HEALTH_API_URL . '/users/me/identity', $access_token);
}

function send_to_google_health($weight, $date = null, $time = null)
{
    if (!function_exists('curl_init')) {
        google_health_log('PHP cURL extension is not loaded; Google Health upload skipped.');
        return false;
    }

    $token_data = load_token();
    if (!$token_data || ($token_data['provider'] ?? null) !== 'google_health'
        || empty($token_data['health_user_id'])) {
        google_health_log('Google Health is not connected; authorize at dataservice?action=authorize');
        return false;
    }

    try {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $measured_at = new DateTimeImmutable(($date ?? date('Y-m-d')) . ' ' . ($time ?? date('H:i:s')), $timezone);
    } catch (Exception $exception) {
        google_health_log('Invalid measurement date/time: ' . $exception->getMessage());
        return false;
    }

    $weight_grams = (int) round((float) $weight * 1000);
    if ($weight_grams <= 0 || $weight_grams > 1000000) {
        google_health_log('Weight outside Google Health API limits: ' . $weight);
        return false;
    }

    $offset_seconds = $measured_at->getOffset();
    $payload = [
        'dataSource' => [
            'recordingMethod' => 'ACTIVELY_MEASURED',
            'device' => [
                'formFactor' => 'SCALE',
                'manufacturer' => 'Soehnle',
                'displayName' => 'Webconnect scale',
            ],
        ],
        'weight' => [
            'sampleTime' => [
                'physicalTime' => $measured_at->format(DateTimeInterface::RFC3339),
                'utcOffset' => $offset_seconds . 's',
            ],
            'weightGrams' => $weight_grams,
        ],
    ];

    $url = GOOGLE_HEALTH_API_URL . '/users/me/dataTypes/weight/dataPoints';
    $access_token = $token_data['access_token'] ?? '';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $result = google_health_api_request('POST', $url, $access_token, $payload);

        if ($result['status'] >= 200 && $result['status'] < 300) {
            if (!empty($result['data']['error'])) {
                google_health_log('Google Health rejected weight: ' . json_encode($result['data']['error']));
                return false;
            }
            google_health_log('Weight uploaded: ' . $weight . ' kg at ' . $measured_at->format(DateTimeInterface::RFC3339));
            return true;
        }

        if ($result['status'] === 401 && $attempt === 0) {
            $access_token = refresh_token($token_data);
            if ($access_token) {
                continue;
            }
        }

        if ($result['status'] === 429 && $attempt < 2) {
            sleep(3 * ($attempt + 1));
            continue;
        }

        google_health_log('Weight upload failed: HTTP ' . $result['status'] . ' - '
            . ($result['response'] ?: $result['curl_error']));
        return false;
    }

    return false;
}