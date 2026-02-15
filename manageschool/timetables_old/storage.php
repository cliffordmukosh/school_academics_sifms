<?php
/**
 * Timetable JSON Storage (Phase 2 Step 1)
 * - Writes/reads JSON files under /data
 * - Scoped per school_id if available in session
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tt_storage_base_dir() {
    return __DIR__ . DIRECTORY_SEPARATOR . 'data';
}

function tt_storage_scope_dir() {
    $base = tt_storage_base_dir();

    // Scope storage per school (recommended)
    $school_id = $_SESSION['school_id'] ?? null;
    if ($school_id !== null && $school_id !== '') {
        return $base . DIRECTORY_SEPARATOR . 'schools' . DIRECTORY_SEPARATOR . preg_replace('/[^0-9a-zA-Z_-]/', '', (string)$school_id);
    }

    // Fallback (if no session school_id)
    return $base . DIRECTORY_SEPARATOR . 'default';
}

function tt_storage_ensure_dirs() {
    $base = tt_storage_base_dir();
    $scope = tt_storage_scope_dir();
    $timetables = $scope . DIRECTORY_SEPARATOR . 'timetables';

    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    if (!is_dir($scope)) {
        @mkdir($scope, 0775, true);
    }
    if (!is_dir($timetables)) {
        @mkdir($timetables, 0775, true);
    }

    return is_dir($timetables);
}

function tt_storage_global_times_path() {
    tt_storage_ensure_dirs();
    return tt_storage_scope_dir() . DIRECTORY_SEPARATOR . 'global_times.json';
}

function tt_storage_class_timetable_path($class_id) {
    tt_storage_ensure_dirs();
    $safe = preg_replace('/[^0-9a-zA-Z_-]/', '', (string)$class_id);
    return tt_storage_scope_dir() . DIRECTORY_SEPARATOR . 'timetables' . DIRECTORY_SEPARATOR . $safe . '.json';
}

function tt_storage_read_json($path, $default = null) {
    if (!is_file($path)) return $default;

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return $default;

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return $default;

    return $data;
}

function tt_storage_write_json($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) return false;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;

    // Atomic write (tmp then rename)
    $tmp = $path . '.tmp';
    $fp = @fopen($tmp, 'wb');
    if (!$fp) return false;

    $ok = false;
    if (@flock($fp, LOCK_EX)) {
        $bytes = @fwrite($fp, $json);
        @fflush($fp);
        @flock($fp, LOCK_UN);
        $ok = ($bytes !== false);
    }
    @fclose($fp);

    if (!$ok) {
        @unlink($tmp);
        return false;
    }

    return @rename($tmp, $path);
}

function tt_storage_delete($path) {
    if (!is_file($path)) return true;
    return @unlink($path);
}
