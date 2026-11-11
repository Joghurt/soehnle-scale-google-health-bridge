# soehnle-google-health-bridge

This PHP script emulates the terminated Soehnle/Leifheit data service for Webconnect body scales like the 063340 model. It allows continued use of the scale by logging weight measurements locally and forwarding them to Google Health.

The return strings have been determined via tcpdump and from `https://github.com/biggaeh/bathroom_scales`.

This is a fork of the `https://github.com/haklein/soehnle-webconnect-dataservice` project.

## Features

- **Weight Data Logging**: Parses and logs weight measurements from the scale to local log files.
- **Google Health Integration**: Automatically uploads weight data to Google Health for cloud-based tracking.
- **Batch Upload**: Uploads historical weight data from log files to Google Health, avoiding duplicates.
- **Web Dashboard**: Displays weight data with charts and statistics via a web interface.
- **Token Management**: Handles Google OAuth tokens with automatic refresh.

# Installation

## Prerequisites

- PHP with the cURL extension enabled for both the CLI runtime and the web-server PHP runtime
- Web server (e.g., Apache or Nginx) with PHP support
- Google Cloud project with the Google Health API enabled
- Google OAuth 2.0 web client

## Google Health Setup

1. **Create a Google Cloud OAuth client**:
   - Follow `https://developers.google.com/health/setup` to enable the Google Health API and create a Web application OAuth client.
   - Add `https://bridge1.soehnle.de/devicedataservice/dataservice?action=callback` as an authorized redirect URI (use your own host/path if different).
   - Under OAuth Data Access, allow `https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.writeonly`.
   - While the consent screen is in Testing, add your Google account as a test user. Complete any Google publication and verification requirements for production use.
   - Before authorizing, sign in to the Google Health mobile app with the same Google account and link or migrate your existing Fitbit account.

2. **Configure the Script**:
   - Copy the values from `config_example.php` into your local `config.php` (or update the existing file).
   - Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` to the values for your Google OAuth client. Do not reuse the retired Fitbit credentials.

3. **Authorize the App**:
   - Open `https://bridge1.soehnle.de/devicedataservice/dataservice?action=authorize` in a browser.
   - Sign in to the linked Google account and grant the weight permission.
   - Google tokens and the linked Health user ID are saved in `token.txt`. Existing Fitbit tokens cannot be reused.

## DNS Mangling

### One way

To redirect HTTP requests from the scale to this script, DNS manipulation is required. Using `unbound` as DNS cache, add the following to the `server:` section:

```
local-zone: "bridge1.soehnle.de" transparent
local-data: "bridge1.soehnle.de A 192.168.x.y"
```

Replace `192.168.x.y` with your webserver's IP address.

### Another way

If you use for e.g. `pi-hole` put

```
192.168.x.y   bridge1.soehnle.de
```

into its `/etc/hosts`.

## URL Rewriting

The script resides in `$WEB_ROOT/devicedataservice/dataservice.php`.

### One way

To handle extensionless PHP execution (mapping requests like `https://bridge1.soehnle.de/devicedataservice/dataservice?data=...` to `dataservice.php`), use the provided `.htaccess` file for Apache config:

```
RewriteEngine On
RewriteCond %{SCRIPT_FILENAME} !-d
RewriteRule ^([^.]+)$ $1.php [NC,L]
```

or use the provided `.htaccess` file.

For Nginx, add to your configuration:

```
location / {
    if (!-e $request_filename){
        rewrite ^(.*)$ /$1.php;
    }
    try_files $uri $uri/ =404;
}
```

## Log Directory

Create a `log` directory with proper permissions in the project directory for storing weight logs.

## Testing the Integration

Once you have completed the authorization steps, you can test the integration:

- Send weight data from your scale: `?data=...` (e.g., from your scale)
- The script parses the weight and automatically sends it to Google Health
- Weight data is logged to the `log/` directory (e.g., `log/240414.log`)
- Google Health upload results are logged to `google_health_log.txt`

## Token Management

- Google OAuth tokens and the linked Health user ID are stored in `token.txt`
- They are automatically renewed when they expire
- Errors and token refresh activities are logged in `google_health_log.txt`

# Usage

## Receiving Data from Scale

The scale sends data via GET requests to `https://bridge1.soehnle.de/devicedataservice/dataservice.php`. The script:

- Parses the data string for the weight.
- Logs the weight to a daily log file in the `log/` directory (format: `dd.mm.yy hh:mm:ss weight`).
- And automatically uploads the weight to Google Health.

Example log entry:
```
24.04.24 12:02:28 71.9
```

## Batch Upload of Historical Data

To upload existing log data to Google Health:

- **Browser**: Call `https://bridge1.soehnle.de/devicedataservice/upload.php?mode=upload`
- **Command line**: `php /path/to/upload.php --upload` or `php /path/to/upload.php -u`
- **Preview without uploading**: `php /path/to/upload.php --preview` or `php /path/to/upload.php -p`
- **Mark entries as done without uploading**: `php /path/to/upload.php --mark-only` or `php /path/to/upload.php -m`
- Calling `upload.php` without a mode only displays usage and never uploads.

The script performs the following steps:

1. Reads all `log/*.log` files
2. Parses the log format: `dd.mm.yy hh:mm:ss weight`
3. Tracks already uploaded entries in `upload_history.txt` to avoid duplicates
4. Uploads new entries to Google Health with rate limiting (1 second between successful uploads)
5. Implements error handling with longer delays (3 seconds) after errors or rate-limit responses

The script displays progress and errors. Choose exactly one mode: `--upload` performs real uploads, `--preview` reports candidates without changing anything, and `--mark-only` adds candidates to `upload_history.txt` without sending them to Google Health. Marked entries will be skipped by later runs. A parameterless CLI or browser request only displays usage; the browser endpoint requires an explicit `mode` query parameter.

## Viewing Data

- **Browser**: Call `https://bridge1.soehnle.de/devicedataservice/show.php`

- Current, minimum, maximum, and average weight.
- Interactive chart of weight over time.
- Statistics and data summary.

# Files Overview

- `config_example.php`: Google OAuth example configuration (Client ID, Secret, and redirect URI).
- `google_health_common.php`: Shared Google OAuth and Google Health API handling.
- `dataservice.php`: Main script handling scale data reception and Google Health upload.
- `upload.php`: Batch uploader for historical data.
- `show.php`: Web dashboard for viewing weight data.
- `.htaccess`: Apache URL rewriting rules.
- `token.txt`: Stores Google OAuth tokens and the linked Health user ID (auto-generated).
- `upload_history.txt`: Tracks uploaded entries (auto-generated).
- `google_health_log.txt`: Logs Google Health API interactions (auto-generated).
- `log/*.log`: Daily weight log files.

# Notes

- Ensure files like `token.txt`, `log/` directory, `google_health_log.txt`, and `upload_history.txt` are writable by the web server.
- Weight is sent to Google Health in grams; local logs remain in kg.
- Tokens are automatically refreshed when expired.
- Check logs in the `log/` directory and `google_health_log.txt` for any issues during operation.
- Repeated identical requests within 30 seconds are automatically ignored to prevent duplicate uploads.
