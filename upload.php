<?php

function print_usage($script_name)
{
	if (PHP_SAPI === 'cli') {
		$usage = "Usage: php " . basename($script_name) . " <mode>\n\n"
			. "Modes (choose exactly one):\n"
			. "  -u, --upload      Upload new measurements to Google Health.\n"
			. "  -p, --preview     Show eligible measurements without changing anything.\n"
			. "  -m, --mark-only   Add eligible measurements to upload_history.txt without\n"
			. "                    uploading them.\n\n"
			. "Without a mode, this script only displays this usage. Existing history\n"
			. "entries are skipped in all modes.\n";
		fwrite(STDOUT, $usage);
		return;
	}

	if (!headers_sent()) {
		header('Content-Type: text/plain; charset=utf-8');
	}
	echo "Usage: upload.php?mode=<mode>\n\n"
		. "Modes (choose one):\n"
		. "  ?mode=upload      Upload new measurements to Google Health.\n"
		. "  ?mode=preview     Show eligible measurements without changing anything.\n"
		. "  ?mode=mark-only   Add eligible measurements to upload_history.txt without\n"
		. "                    uploading them.\n";
}

$mode = null;
if (PHP_SAPI === 'cli') {
	foreach (array_slice($argv, 1) as $argument) {
		$mode_aliases = [
			'--upload' => 'upload',
			'-u' => 'upload',
			'--preview' => 'preview',
			'-p' => 'preview',
			'--mark-only' => 'mark-only',
			'-m' => 'mark-only',
		];
		if (!isset($mode_aliases[$argument])) {
			fwrite(STDERR, "ERROR: Unknown option: $argument\n\n");
			print_usage($argv[0]);
			exit(2);
		}
		if ($mode !== null) {
			fwrite(STDERR, "ERROR: Choose exactly one mode.\n\n");
			print_usage($argv[0]);
			exit(2);
		}
		$mode = $mode_aliases[$argument];
	}
	if ($mode === null) {
		print_usage($argv[0]);
		exit(0);
	}
} else {
	$mode = $_GET['mode'] ?? null;
	if ($mode === null || $mode === '') {
		print_usage('upload.php');
		exit(0);
	}
	if (!in_array($mode, ['upload', 'preview', 'mark-only'], true)) {
		echo "ERROR: Unknown mode.\n\n";
		print_usage('upload.php');
		exit(2);
	}
}

$dry_run = $mode === 'preview';
$mark_uploaded_without_upload = $mode === 'mark-only';

if (file_exists(__DIR__ . '/config.php')) {
	require_once(__DIR__ . '/config.php');
} elseif (!$dry_run && !$mark_uploaded_without_upload) {
	fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'w'), "ERROR: config.php not found. Copy config_example.php to config.php and set your Google OAuth credentials.\n");
	exit(1);
}

require_once(__DIR__ . '/google_health_common.php');

if ($mode === 'upload' && !function_exists('curl_init')) {
	$php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	$message = "ERROR: -u cannot upload because the PHP cURL extension is not loaded for the runtime executing this script.\n\n"
		. "Install the extension for PHP " . $php_version . ":\n"
		. "  Debian/Ubuntu: sudo apt update && sudo apt install php-curl\n"
		. "  Versioned packages: install php" . $php_version . "-curl\n"
		. "  NAS systems: use the package manager to install/enable the cURL module\n"
		. "              matching the PHP runtime executing this script.\n\n"
		. "For CLI runs, check the loaded configuration with 'php --ini' and verify\n"
		. "that 'curl' appears in 'php -m'. If installed but not loaded, enable\n"
		. "extension=curl in the listed php.ini and retry. Browser uploads may use a\n"
		. "different PHP configuration; enable cURL there too and restart PHP-FPM or\n"
		. "the web server.\n\n"
		. "For a no-change preview use -p. The -m mode only adds entries to\n"
		. "upload_history.txt and suppresses future uploads; it does not test cURL.\n";
	fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'w'), $message);
	exit(1);
}

define('HISTORY_FILE', __DIR__ . '/upload_history.txt');
define('UPLOAD_DELAY', 1); // Seconds between successful uploads
define('ERROR_DELAY', 3); // Seconds after error or rate limit

date_default_timezone_set('Europe/Berlin');
setlocale(LC_TIME, "de_DE.UTF-8", "de_DE@euro", "de_DE", "deu_deu", "de", "ge");

if (PHP_SAPI !== 'cli') {
	header('Content-Type: text/plain; charset=utf-8');
	ob_start();
}

function output_progress($message)
{
	echo $message;
	if (PHP_SAPI === 'cli') {
		while (ob_get_level() > 0) {
			if (!@ob_end_flush()) {
				break;
			}
		}
		flush();
	}
}

function load_history() {
	$history = [];
	if (file_exists(HISTORY_FILE)) {
		$lines = file(HISTORY_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		foreach ($lines as $line) {
			$parts = explode('|', $line);
			if (count($parts) === 3) {
				$key = $parts[0] . '|' . $parts[1]; // date|weight
				$history[$key] = true;
			}
		}
	}
	return $history;
}

function save_to_history($date, $weight, $time) {
	$entry = "$date|$weight|$time\n";
	return file_put_contents(HISTORY_FILE, $entry, FILE_APPEND | LOCK_EX) !== false;
}

function parse_log_date($date_str) {
	// Convert dd.mm.yy to yyyy-mm-dd
	$parts = explode('.', $date_str);
	if (count($parts) === 3) {
		$day = $parts[0];
		$month = $parts[1];
		$year = '20' . $parts[2]; // assume 20xx
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}
	return null;
}

function parse_line($line) {
	$line = trim($line);
	if (empty($line)) {
		return null;
	}

	// Check for new format: "dd.mm.yy hh:mm:ss weight"
	if (preg_match('/^(\d{2}\.\d{2}\.\d{2}) (\d{2}:\d{2}:\d{2}) (\d+(?:\.\d+)?)$/', $line, $matches)) {
		$date_str = $matches[1];
		$time_str = $matches[2];
		$weight = floatval($matches[3]);
		return [$date_str, $time_str, $weight];
	}

	// Check for old format: "RFC822 datetime ... weight: weight"
	if (preg_match('/^(.+) (?:[0-9a-z-_]+) weight: (\d+(?:\.\d+)?)$/u', $line, $matches)) {
		$datetime_str = trim($matches[1]);
		$weight = floatval($matches[2]);
		try {
			$datetime = new DateTime($datetime_str);
			$datetime->setTimezone(new DateTimeZone(date_default_timezone_get()));
			$date_str = $datetime->format('d.m.y');
			$time_str = $datetime->format('H:i:s');
			return [$date_str, $time_str, $weight];
		} catch (Exception $e) {
			echo $e->getMessage() . "\n";
			return null; // Invalid datetime
		}
	}

	return null; // Unrecognized format
}

// Main logic
echo "Starting batch upload from logs...\n";
if ($dry_run) {
	echo "PREVIEW: no API uploads or history changes will be made.\n";
} elseif ($mark_uploaded_without_upload) {
	echo "MARK-ONLY: eligible measurements will be added to upload_history.txt only.\n";
}
echo "================================\n";
echo "Rate limit delay: " . UPLOAD_DELAY . "s between uploads, " . ERROR_DELAY . "s after errors\n";
echo "================================\n\n";

$history = load_history();
$log_dir = __DIR__ . '/log';
$uploaded_count = 0;
$dry_run_count = 0;
$marked_count = 0;
$duplicate_count = 0;
$error_count = 0;
$last_date = null;
$last_weight = null;
if (!is_dir($log_dir)) {
	echo "ERROR: log directory not found\n";
	exit;
}

$log_files = glob($log_dir . '/*.log');
if (empty($log_files)) {
	echo "No log files found\n";
	exit;
}

echo "Found " . count($log_files) . " log file(s)\n";
echo "Loaded " . count($history) . " already uploaded entries from history\n";
echo "Processing...\n\n";

foreach ($log_files as $log_file) {
	echo "Reading: " . basename($log_file) . "\n";
	$lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$parsed_count = 0;

	foreach ($lines as $line) {
		$parsed = parse_line($line);
		if ($parsed) {
			list($date_str, $time_str, $weight) = $parsed;

			$date = parse_log_date($date_str);

			if ($date && $weight > 0) {
				if ($date === $last_date && $weight === $last_weight) {
					echo "  ⊘ Skipping consecutive duplicate entry: $weight kg on $date\n";
					continue;
				}
				$last_date = $date;
				$last_weight = $weight;
				$parsed_count++;
				$history_key = "$date|$weight"; // Track by date and weight to avoid duplicates

				if (!isset($history[$history_key])) {
					if ($dry_run) {
						echo "Would upload: $weight kg on $date at $time_str\n";
						$dry_run_count++;
					} elseif ($mark_uploaded_without_upload) {
						if (save_to_history($date, $weight, $time_str)) {
							$history[$history_key] = true;
							echo "Marked as uploaded without sending: $weight kg on $date at $time_str\n";
							$marked_count++;
						} else {
							echo "ERROR: Could not write upload_history.txt for $weight kg on $date at $time_str\n";
							$error_count++;
						}
					} else {
						output_progress("Uploading: $weight kg on $date at $time_str...\n");
						if (send_to_google_health($weight, $date, $time_str)) {
							echo "Uploaded: $weight kg on $date at $time_str\n";
							sleep(UPLOAD_DELAY);
							if (save_to_history($date, $weight, $time_str)) {
								$history[$history_key] = true;
								$uploaded_count++;
							} else {
								echo "ERROR: Upload succeeded but upload_history.txt could not be updated.\n";
								$error_count++;
							}
						} else {
							echo "ERROR: Google Health upload failed for $weight kg on $date at $time_str\n";
							sleep(ERROR_DELAY);
							$error_count++;
						}
					}
				} else {
					$duplicate_count++;
					echo " ⊘ Already uploaded: $weight kg on $date\n";
				}
			}
		}
	}
	if ($parsed_count > 0) {
		echo " (Parsed: $parsed_count entries)\n";
	}
}

echo "\n================================\n";
echo "Summary:\n";
echo " Uploaded: $uploaded_count\n";
if ($dry_run) {
	echo " Would upload: $dry_run_count\n";
}
if ($mark_uploaded_without_upload) {
	echo " Marked without upload: $marked_count\n";
}
echo " Duplicates skipped: $duplicate_count\n";
echo " Errors: $error_count\n";
echo "================================\n";


if (PHP_SAPI !== 'cli') {
	if (!headers_sent()) {
		header('Content-Length: ' . ob_get_length());
	}
	ob_end_flush();
}
