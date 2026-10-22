<?php
// One command contract for FPP 8-10; optional features are discovered separately.
const PSS_VERSION = '2026.10.21.1';

function pss_fppVersion() {
    return function_exists('getFPPVersion') ? (string)getFPPVersion() : 'Unknown';
}

function pss_fppMajorVersion() {
    return preg_match('/^v?(\d+)\./i', pss_fppVersion(), $m) ? (int)$m[1] : 0;
}

function pss_parseSettingsText($text) {
    $data = array();
    foreach (preg_split('/\r?\n/', (string)$text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') $value = substr($value, 1, -1);
        $data[trim($key)] = $value;
    }
    return $data;
}

function pss_shareFileWithFpp($path) {
    // Root worker and FPP's web user both need to update plugin-owned files.
    @chmod($path, 0664);
    $dir = dirname($path);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($path, fileowner($dir));
        @chgrp($path, filegroup($dir));
    }
}

function pss_setPluginSettings($changes) {
    global $pluginConfigFile, $pluginSettings;
    $fd = @fopen($pluginConfigFile, 'c+');
    if (!$fd || !flock($fd, LOCK_EX)) {
        if ($fd) fclose($fd);
        pss_logEntry('Unable to lock plugin settings for writing');
        return false;
    }
    $oldText = stream_get_contents($fd);
    $data = pss_parseSettingsText($oldText);
    foreach ($changes as $key => $value) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string)$key)) continue;
        // Encode quote/backslash/newline values so FPP 8's own settings writer
        // can safely parse them too. Existing simple settings stay unchanged.
        $data[$key] = (string)$value;
    }
    $text = '';
    foreach ($data as $key => $value) {
        if (strpbrk($value, "\"\\\r\n") !== false) $value = rawurlencode($value);
        $data[$key] = $value;
        $text .= $key . ' = "' . $value . '"' . "\n";
    }
    $ok = true;
    if ($text !== $oldText) {
        rewind($fd);
        $ok = fwrite($fd, $text) === strlen($text);
        if ($ok) $ok = ftruncate($fd, strlen($text)) && fflush($fd);
    }
    flock($fd, LOCK_UN);
    fclose($fd);
    if ($ok) {
        $pluginSettings = $data;
        pss_shareFileWithFpp($pluginConfigFile);
    } else {
        pss_logEntry('Unable to save plugin settings');
    }
    return $ok;
}

function pss_fppResponseOk($response) {
    if (!is_array($response) || empty($response['ok'])) return false;
    $body = trim(isset($response['body']) ? (string)$response['body'] : '');
    if (stripos($body, '<!doctype') === 0 || stripos($body, '<html') === 0) return false;
    $data = json_decode($body, true);
    if (is_array($data)) {
        foreach (array('ok', 'success', 'Success') as $key) {
            if (isset($data[$key]) && ($data[$key] === false || $data[$key] === 0)) return false;
        }
        foreach (array('Status', 'status') as $key) {
            if (isset($data[$key]) && in_array(strtolower((string)$data[$key]), array('error', 'failed', 'failure'), true)) return false;
        }
    }
    return !preg_match('/^(No Command:|Not Found$|Timeout running command|Could not |Error:)/i', $body);
}

function pss_commandRequest($command, $args, $baseUrl = '') {
    $base = $baseUrl === '' ? 'http://127.0.0.1' : pss_normalizeRemoteFppBaseUrl($baseUrl);
    if ($base === '') return array('ok' => false, 'status' => 0, 'body' => 'Invalid FPP address');
    // FPP 8, 9.4 and 10 all accept this POST body. GET paths are unsuitable for
    // arbitrary model/text names (slashes, #, Unicode) and are not needed.
    $response = pss_httpRequest($base . '/api/command', 'POST', array(
        'command' => (string)$command, 'args' => array_map('strval', array_values($args))
    ), 'text/plain, application/json');
    $response['ok'] = pss_fppResponseOk($response);
    return $response;
}

function pss_runtimePath($name) {
    global $settings, $pluginName;
    if ($name === 'worker' || $name === 'worker-lock') {
        $identity = substr(sha1($settings['configDirectory'] . '/' . $pluginName), 0, 12);
        return sys_get_temp_dir() . '/pss-' . $identity . '-' . $name . '.json';
    }
    return rtrim($settings['configDirectory'], '/') . '/plugin.' . $pluginName . '-' . $name . '.json';
}

function pss_workerHealth() {
    $raw = @file_get_contents(pss_runtimePath('worker'));
    $data = $raw === false ? array() : json_decode($raw, true);
    if (!is_array($data)) $data = array();
    $data['healthy'] = isset($data['heartbeat']) && time() - (int)$data['heartbeat'] <= 90;
    return $data;
}

function pss_writeWorkerHealth($lastPoll, $nextPoll, $error = '') {
    $path = pss_runtimePath('worker');
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode(array('pid' => getmypid(), 'heartbeat' => time(),
        'lastPoll' => $lastPoll, 'nextPoll' => $nextPoll, 'error' => $error,
        'pluginVersion' => PSS_VERSION, 'fppVersion' => pss_fppVersion()));
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path)) pss_shareFileWithFpp($path);
    else @unlink($tmp);
}

function pss_pluginBuildInfo() {
    $git = __DIR__ . '/.git/';
    $head = trim((string)@file_get_contents($git . 'HEAD'));
    $branch = 'unknown';
    $commit = $head;
    if (strpos($head, 'ref: refs/heads/') === 0) {
        $ref = substr($head, 5);
        $branch = substr($ref, strlen('refs/heads/'));
        $commit = '';
        if (strpos($ref, '..') === false && preg_match('#^refs/heads/[a-zA-Z0-9_./-]+$#', $ref)) {
            $commit = trim((string)@file_get_contents($git . $ref));
            if ($commit === '' && is_file($git . 'packed-refs')) {
                foreach (file($git . 'packed-refs') as $line) {
                    if (substr(trim($line), -strlen($ref)) === $ref) $commit = substr($line, 0, 40);
                }
            }
        }
    } elseif (preg_match('/^[0-9a-f]{40}$/', $head)) {
        $branch = 'detached';
    }
    return array('branch' => $branch, 'commit' => preg_match('/^[0-9a-f]{40}$/', $commit) ? substr($commit, 0, 9) : 'unknown');
}
