<?php
/**
 * Claude Code plugin — action endpoint.
 *
 * All actions are AJAX (JSON). Long-running operations (install / restore /
 * update / uninstall) are launched in the background and their output is
 * tailed by the page via the "progress" action. This avoids the blank-page
 * problem caused by emHTTP not flushing streamed PHP output during long
 * network installs.
 *
 * NOTE: This endpoint requires the session cookie to be passed from the browser.
 * If you see 504 Gateway Timeout errors, it's due to the Unraid auth middleware
 * (auth-request.php) timing out during the auth subrequest. This is a known issue
 * with certain PHP-FPM configurations and session handling.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak raw errors to the browser
ini_set('log_errors', '1');

// Add early timeout detection
set_time_limit(30);
register_shutdown_function(function() {
  $error = error_get_last();
  if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
    cc_debug("FATAL ERROR: " . $error['message'] . " at " . $error['file'] . ":" . $error['line']);
  }
});

$bootDir     = "/boot/config/plugins/claude-code";
$persist     = "$bootDir/persist";
$settingsFile = "$persist/cfg/settings.json";
$mcpFile      = "$persist/cfg/mcp.json";
$debugLog    = "$bootDir/debug.log";
$actionLog   = "$bootDir/last_action.log";
$statusFile  = "$bootDir/action.status";
$runnerSh    = "/tmp/cc-runner.sh";

/* --------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */
function cc_now() { return date('Y-m-d H:i:s'); }

function cc_debug($msg) {
    global $debugLog;
    $line = "[" . cc_now() . "] " . $msg . "\n";
    @file_put_contents($debugLog, $line, FILE_APPEND | LOCK_EX);
}

function cc_json($arr, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($arr);
    exit;
}

function cc_fail($msg, $code = 500) {
    cc_debug("FAIL: $msg");
    cc_json(['ok' => false, 'error' => $msg], $code);
}

// Turn PHP errors into logged entries (and visible via debug log).
set_error_handler(function ($no, $str, $file, $line) {
    cc_debug("PHP error [$no]: $str at $file:$line");
    return true; // suppress default output
});
set_exception_handler(function ($e) {
    cc_debug("EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    cc_fail('Server exception: ' . $e->getMessage());
});

/* --------------------------------------------------------------------------
 * Dispatch
 * ------------------------------------------------------------------------ */
// Accept parameters from both GET and POST sources
$action = $_GET['action'] ?? $_POST['action'] ?? '';
cc_debug("action.php invoked: action=" . ($action ?: '(none)') .
         " name=" . ($_GET['name'] ?? $_POST['name'] ?? ''));

// CSRF validation for POST requests (GET requests are exempt for compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $var;
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    $valid_csrf = $var['csrf_token'] ?? '';

    // For progress polling and log refresh, allow without CSRF (compatibility)
    // For all other POST actions, require valid CSRF token
    $csrf_exempt_actions = ['progress', 'get_log'];
    if (!in_array($action, $csrf_exempt_actions, true)) {
        if ($valid_csrf !== '' && $submitted_csrf !== $valid_csrf) {
            cc_debug("CSRF validation failed: submitted='$submitted_csrf' valid='$valid_csrf' action='$action'");
            cc_fail('Invalid CSRF token', 403);
        }
    }
}

$valid = ['save_settings', 'save_mcp', 'get_log', 'start', 'progress', 'cancel'];
if ($action !== '' && !in_array($action, $valid, true)) {
    cc_fail("Unknown action: $action");
}

try {
    switch ($action) {

        /* ---- settings.json ---- */
        case 'save_settings': {
            $content = $_GET['content'] ?? $_POST['content'] ?? '';
            json_decode($content);
            if (json_last_error() !== JSON_ERROR_NONE) {
                cc_fail('Invalid JSON: ' . json_last_error_msg(), 400);
            }
            $dir = dirname($settingsFile);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
                cc_fail("Could not create directory: $dir");
            }
            $tmp = $settingsFile . '.tmp';
            if (file_put_contents($tmp, $content) === false) {
                cc_fail('Could not write settings file');
            }
            if (!@rename($tmp, $settingsFile)) {
                @unlink($tmp);
                cc_fail('Could not finalize settings file');
            }
            cc_json(['ok' => true]);
            break;
        }

        /* ---- mcp.json ---- */
        case 'save_mcp': {
            $content = $_GET['content'] ?? $_POST['content'] ?? '';
            json_decode($content);
            if (json_last_error() !== JSON_ERROR_NONE) {
                cc_fail('Invalid JSON: ' . json_last_error_msg(), 400);
            }
            $dir = dirname($mcpFile);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
                cc_fail("Could not create directory: $dir");
            }
            $tmp = $mcpFile . '.tmp';
            if (file_put_contents($tmp, $content) === false) {
                cc_fail('Could not write MCP file');
            }
            if (!@rename($tmp, $mcpFile)) {
                @unlink($tmp);
                cc_fail('Could not finalize MCP file');
            }
            // Link ~/.claude.json so Claude Code picks up user-scope MCP.
            $userClaude = '/root/.claude.json';
            if (!file_exists($userClaude) || is_link($userClaude)) {
                @unlink($userClaude);
                @symlink($mcpFile, $userClaude);
            }
            cc_json(['ok' => true]);
            break;
        }

        /* ---- combined log view ---- */
        case 'get_log': {
            $syslog = shell_exec("grep -i 'claude' /var/log/syslog 2>/dev/null | tail -80") ?? '';
            $debug  = is_file($debugLog) ? tail_file($debugLog, 80) : '';
            $action = is_file($actionLog) ? tail_file($actionLog, 80) : '';
            cc_json(['ok' => true, 'syslog' => $syslog, 'debug' => $debug, 'action' => $action]);
            break;
        }

        /* ---- start a background action ---- */
        case 'start': {
            $name = $_GET['name'] ?? $_POST['name'] ?? '';
            $allowed = ['install', 'restore', 'update', 'uninstall'];
            if (!in_array($name, $allowed, true)) {
                cc_fail("Invalid action name: $name", 400);
            }

            // Refuse to start if something is already running.
            $cur = is_file($statusFile) ? trim(file_get_contents($statusFile)) : '';
            if (strpos($cur, 'RUNNING:') === 0) {
                cc_fail("Another action is already running ($cur). Wait for it or cancel first.");
            }

            // Resolve the script + args for this action.
            if ($name === 'update') {
                $script = "$bootDir/update-claude.sh";
                // The plugin ships update-claude.sh via the .plg. Only write a
                // minimal fallback inline if it is somehow missing.
                if (!is_file($script)) {
                    $updateBody = "#!/bin/bash\n" .
                        "set -o pipefail\n" .
                        "export PATH=/root/.local/bin:\$PATH\n" .
                        "echo 'Running: claude update'\n" .
                        "claude update 2>&1\n" .
                        "RC=\$?\n" .
                        "if [ \$RC -ne 0 ]; then\n" .
                        "  echo \"claude update failed (exit \$RC) - falling back to installer...\"\n" .
                        "  curl -fsSL https://claude.ai/install.sh | bash || { echo 'Installer failed'; exit 1; }\n" .
                        "fi\n";
                    if (file_put_contents($script, $updateBody) === false) {
                        cc_fail("Could not write update script: $script");
                    }
                    chmod($script, 0755);
                }
                $args = '';
            } else {
                $map = [
                    'install'   => ["$bootDir/install-claude.sh", ''],
                    'restore'   => ["$bootDir/rc.claude-code", 'start'],
                    'uninstall' => ["$bootDir/uninstall-claude.sh", ''],
                ];
                list($script, $args) = $map[$name];
            }

            if (!is_file($script)) {
                cc_fail("Script not found: $script — reinstall the plugin.");
            }

            // Build a small runner that records DONE status + exit code.
            // (PHP owns the RUNNING status, written below with the pid for cancel.)
            $run  = "#!/bin/bash\n";
            $run .= "logger -t claude-code 'action $name started'\n";
            $run .= "bash " . escapeshellarg($script);
            if ($args !== '') $run .= " " . escapeshellarg($args);
            $run .= " > " . escapeshellarg($actionLog) . " 2>&1\n";
            $run .= "RC=\$?\n";
            $run .= "echo \"DONE:\$RC:" . $name . "\" > " . escapeshellarg($statusFile) . "\n";
            $run .= "logger -t claude-code 'action $name finished exit '\$RC\n";
            if (file_put_contents($runnerSh, $run) === false) {
                cc_fail("Could not write runner script: $runnerSh");
            }
            chmod($runnerSh, 0755);

            // Fresh log, launch detached in its own session (so we can kill the group).
            @file_put_contents($actionLog, '');
            @file_put_contents($statusFile, "RUNNING:$name");
            $launch = "setsid bash " . escapeshellarg($runnerSh) . " > /dev/null 2>&1 & echo $!";
            $pid = trim((string)shell_exec($launch));
            cc_debug("started $name runner_pid=$pid script=$script");

            // Rewrite status to carry the pid for cancellation.
            @file_put_contents($statusFile, "RUNNING:$name:$pid");

            cc_json(['ok' => true, 'action' => $name, 'pid' => $pid]);
            break;
        }

        /* ---- poll progress ---- */
        case 'progress': {
            $status = is_file($statusFile) ? trim(file_get_contents($statusFile)) : 'IDLE';
            $log    = is_file($actionLog) ? (string)file_get_contents($actionLog) : '';
            if (strlen($log) > 200000) {
                $log = "...(truncated)\n" . substr($log, -200000);
            }
            $running = (strpos($status, 'RUNNING:') === 0);
            $exit = null;
            if (preg_match('/^DONE:(\d+):(.+)/', $status, $m)) {
                $exit = (int)$m[1];
            }
            cc_json([
                'ok'      => true,
                'status'  => $status,
                'running' => $running,
                'exit'    => $exit,
                'log'     => $log,
            ]);
            break;
        }

        /* ---- cancel a running action ---- */
        case 'cancel': {
            $cur = is_file($statusFile) ? trim(file_get_contents($statusFile)) : '';
            $pid = null;
            if (preg_match('/^RUNNING:[^:]+:(\d+)/', $cur, $m)) $pid = $m[1];
            if ($pid) {
                // Kill the whole process group (negative pid).
                shell_exec("kill -- -" . escapeshellarg($pid) . " 2>/dev/null");
                shell_exec("pkill -f " . escapeshellarg($runnerSh) . " 2>/dev/null");
            }
            @file_put_contents($statusFile, "CANCELLED");
            @file_put_contents($actionLog, "<<< cancelled by user >>>\n", FILE_APPEND);
            cc_debug("cancel: killed pid=$pid");
            cc_json(['ok' => true]);
            break;
        }

        default:
            cc_fail('No action specified', 400);
    }
} catch (Throwable $e) {
    cc_fail('Error: ' . $e->getMessage());
}

function tail_file($path, $lines = 80) {
    $out = shell_exec("tail -n " . (int)$lines . " " . escapeshellarg($path) . " 2>/dev/null");
    return $out ?: '';
}
