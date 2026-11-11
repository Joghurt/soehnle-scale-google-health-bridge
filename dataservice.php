<?php

if (!file_exists(__DIR__ . '/config.php')) {
    echo "ERROR: config.php not found. Copy config_example.php to config.php and set your Google OAuth credentials.\n";
    exit;
}

require_once('config.php');
require_once(__DIR__ . '/google_health_common.php');

if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', '');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', '');
}
if (!defined('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', '');
}

define('DUPLICATE_REQUEST_WINDOW', 30); // seconds within which repeated identical requests are ignored

date_default_timezone_set('Europe/Berlin');
setlocale(LC_TIME, "de_DE.UTF-8", "de_DE@euro", "de_DE", "deu_deu", "de", "ge");

header('Content-Type: text/plain; charset=utf-8');
ob_start();

$action = $_GET['action'] ?? null;

if ($action === 'authorize') {
    if (!google_health_configured()) {
        echo "Google OAuth is not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in config.php.\n";
        send_response_and_exit();
    }

    session_start();
    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(32));
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'response_type' => 'code',
        'scope' => GOOGLE_HEALTH_WEIGHT_SCOPE,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $_SESSION['google_oauth_state'],
    ]);
    header('Location: ' . $auth_url);
    ob_end_clean();
    header('Content-Length: 0');
    exit;
} elseif ($action === 'callback') {
    session_start();
    $expected_state = $_SESSION['google_oauth_state'] ?? '';
    unset($_SESSION['google_oauth_state']);
    if ($expected_state === '' || !hash_equals($expected_state, $_GET['state'] ?? '')) {
        echo "Authorization failed: invalid OAuth state. Start again at dataservice?action=authorize.\n";
        send_response_and_exit();
    }

    $code = $_GET['code'] ?? null;
    if ($code) {
        if (!google_health_configured()) {
            echo "Google OAuth is not configured. Set your Google credentials in config.php.\n";
            send_response_and_exit();
        }

        $result = google_health_token_request([
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'code' => $code,
        ]);

        if ($result['status'] === 200 && !empty($result['data']['access_token'])) {
            $granted_scopes = preg_split('/\s+/', trim($result['data']['scope'] ?? ''));
            if (!in_array(GOOGLE_HEALTH_WEIGHT_SCOPE, $granted_scopes, true)) {
                echo "Authorization failed: Google Health weight permission was not granted. Please authorize again.\n";
                send_response_and_exit();
            }

            $identity_result = google_health_get_identity($result['data']['access_token']);
            if ($identity_result['status'] === 200 && !empty($identity_result['data']['healthUserId'])) {
                $token_data = $result['data'];
                $token_data['provider'] = 'google_health';
                $token_data['health_user_id'] = $identity_result['data']['healthUserId'];
                $token_data['legacy_user_id'] = $identity_result['data']['legacyUserId'] ?? null;
                $token_data['expires_at'] = time() + intval($token_data['expires_in'] ?? 3600);

                if (save_token($token_data)) {
                    google_health_log('Google Health authorization completed for user ' . $token_data['health_user_id']);
                    echo "Google Health authorization successful. Weight uploads are connected.";
                } else {
                    echo "Authorization succeeded, but the token could not be saved. Check file permissions for token.txt.";
                }
            } elseif (($identity_result['data']['error']['status'] ?? '') === 'FAILED_PRECONDITION') {
                echo "Google account is not linked to Google Health. Open the Google Health app, link or migrate your account, then authorize again: https://fitbit.google.com/auth/signup";
            } else {
                google_health_log('Identity lookup failed: ' . ($identity_result['response'] ?: $identity_result['curl_error']));
                echo "Authorization failed: could not verify the Google Health account. Check google_health_log.txt.";
            }
        } else {
            google_health_log('Token exchange failed: ' . ($result['response'] ?: $result['curl_error']));
            echo "Authorization failed during Google token exchange. Check google_health_log.txt.";
        }
    } else {
        echo "Google authorization was not completed: " . htmlspecialchars($_GET['error'] ?? 'no code received', ENT_QUOTES, 'UTF-8');
    }
    send_response_and_exit();
}

function wh_log($log_msg)
{
    $log_filename = "log";
    if (!file_exists($log_filename))
    {
        // create directory/folder uploads.
        mkdir($log_filename, 0777, true);
    }
    $log_file_data = $log_filename.'/' . date('ymd') . '.log';
    // if you don't add `FILE_APPEND`, the file will be erased each time you add a log
    file_put_contents($log_file_data, $log_msg . "\n", FILE_APPEND);
}

function is_duplicate_request($id_str, $weight)
{
    $history_file = __DIR__ . '/last_request.txt';
    if (!file_exists($history_file)) {
        return false;
    }

    $line = trim(file_get_contents($history_file));
    if ($line === '') {
        return false;
    }

    $parts = explode('|', $line);
    if (count($parts) !== 3) {
        return false;
    }

    list($prev_id, $prev_weight, $prev_timestamp) = $parts;
    if ($prev_id !== $id_str) {
        return false;
    }
    if (floatval($prev_weight) !== floatval($weight)) {
        return false;
    }
    if ((time() - intval($prev_timestamp)) > DUPLICATE_REQUEST_WINDOW) {
        return false;
    }

    return true;
}

function update_last_request($id_str, $weight)
{
    $history_file = __DIR__ . '/last_request.txt';
    $entry = sprintf('%s|%s|%s', $id_str, $weight, time());
    file_put_contents($history_file, $entry);
}

function send_response_and_exit() {
    $body = ob_get_contents();
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($body));
    }
    ob_end_flush();
    exit;
}

$data_string = $_GET['data'];

$data_id = hexdec(substr($data_string, 0,2));
$mac = substr($data_string, 2, 12);
$id1 = hexdec(substr($data_string, 14, 6));
$id2 = hexdec(substr($data_string, 20, 6));
$id_str = str_pad($id1, 8, '0', STR_PAD_LEFT) . "-" . str_pad($id2, 8, '0', STR_PAD_LEFT);
$weight = hexdec(substr($data_string, 38, 4))/100;

if ($id1 && $id2 && $weight > 0) {
    if (is_duplicate_request($id_str, $weight)) {
        google_health_log("Duplicate request skipped for scale $id_str with weight $weight kg");
    } else {
        wh_log(date("d.m.y H:i:s") . " " . $weight);
        if (send_to_google_health($weight)) {
            update_last_request($id_str, $weight);
        }
    }
}

# just return what we've sniffed with tcpdump, and taken from https://github.com/biggaeh/bathroom_scales/blob/master/dataservice
switch($data_id) {
    case 0x24:
        echo 'A00000000000000001000000000000000000000000000000bec650a1';
        break;
    case 0x22:
        echo 'A20000000000000000000000000000000000000000000000c9950d3f';
        break;
    case 0x25:
        echo 'A00000000000000001000000000000000000000000000000bec650a1';
        break;
    case 0x28:
        echo 'A5000000000000000100000000000000000000000000000056e5abd9';
        break;
    case 0x21:
        echo 'A10000000000000009c4914c0000000000000000000000001d095ec4';
        break;
}

if (!headers_sent()) {
    header('Content-Length: ' . ob_get_length());
}

ob_end_flush();
