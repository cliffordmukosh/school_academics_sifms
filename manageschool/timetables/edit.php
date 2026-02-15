<?php
require_once __DIR__ . '/../../apis/auth_middleware.php';
require_once __DIR__ . '/functions.php';

/**
 * ============================================================
 * EDIT.PHP FLOW LOGGING (SERVER-SIDE)
 * - Writes JSON lines to manageschool/timetables/logs/edit_flow.log (auto-fallback to system temp)
 * - Logs input (GET/POST), normalization, selection validation, POST actions, save/clear outcomes,
 *   and data-loading calls + result shapes.
 * - Does NOT echo/print logs to browser.
 * ============================================================
 */

/**
 * Resolve class teacher label for a stream (best-effort).
 * Tries common streams columns, then resolves name from users.
 * Returns '' if not found (no placeholders).
 */
if (!function_exists('tt_stream_class_teacher_label')) {
    /**
     * Resolve class teacher label for a stream (DB-backed via class_teachers).
     * Prefers current academic year if available; falls back to latest row.
     * Returns '' if not found (no placeholders).
     */
    function tt_stream_class_teacher_label(int $stream_id): string {
        $conn = $GLOBALS['conn'] ?? null;
        $school_id = tt_school_id();
        if (!$conn || !($conn instanceof mysqli) || $school_id <= 0 || $stream_id <= 0) return '';

        // Prefer current academic year from school_settings if available
        $acad_year = null;
        if (function_exists('tt_get_current_academic_year')) {
            $acad_year = tt_get_current_academic_year($school_id);
        }
        $acad_year = (int)($acad_year ?? 0);

        // Pull teacher assignment from class_teachers for this stream
        if ($acad_year > 0) {
            $stmt = $conn->prepare("
                SELECT ct.user_id
                FROM class_teachers ct
                WHERE ct.school_id=? AND ct.stream_id=? AND ct.academic_year=?
                ORDER BY ct.created_at DESC, ct.class_teacher_id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param("iii", $school_id, $stream_id, $acad_year);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = ($res && ($r = $res->fetch_assoc())) ? $r : null;
                $stmt->close();

                if (is_array($row) && (int)($row['user_id'] ?? 0) > 0) {
                    $teacher_id = (int)$row['user_id'];
                    return tt_user_label_by_id($conn, $school_id, $teacher_id);
                }
            }
        }

        // Fallback: latest mapping for this stream (any year)
        $stmt2 = $conn->prepare("
            SELECT ct.user_id
            FROM class_teachers ct
            WHERE ct.school_id=? AND ct.stream_id=?
            ORDER BY ct.academic_year DESC, ct.created_at DESC, ct.class_teacher_id DESC
            LIMIT 1
        ");
        if (!$stmt2) return '';

        $stmt2->bind_param("ii", $school_id, $stream_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $row2 = ($res2 && ($r2 = $res2->fetch_assoc())) ? $r2 : null;
        $stmt2->close();

        $teacher_id = is_array($row2) ? (int)($row2['user_id'] ?? 0) : 0;
        if ($teacher_id <= 0) return '';

        return tt_user_label_by_id($conn, $school_id, $teacher_id);
    }

    /**
     * Resolve a display label for a user_id (initials/fullname/username).
     */
    function tt_user_label_by_id(mysqli $conn, int $school_id, int $user_id): string {
        if ($user_id <= 0 || $school_id <= 0) return '';

        $stmt = $conn->prepare("
            SELECT user_id, username, initials,
                   CONCAT(TRIM(first_name),' ',TRIM(other_names)) AS full_name
            FROM users
            WHERE user_id=? AND school_id=?
            LIMIT 1
        ");
        if (!$stmt) return '';

        $stmt->bind_param("ii", $user_id, $school_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $urow = ($res && ($u = $res->fetch_assoc())) ? $u : null;
        $stmt->close();

        if (!is_array($urow)) return '';

        if (function_exists('tt_teacher_label_from_row')) {
            return trim((string)tt_teacher_label_from_row($urow));
        }

        $initials = trim((string)($urow['initials'] ?? ''));
        $full     = trim((string)($urow['full_name'] ?? ''));
        $username = trim((string)($urow['username'] ?? ''));

        if ($initials !== '') return $initials;
        if ($full !== '') return $full;
        if ($username !== '') return $username;

        return '';
    }
}


if (!function_exists('tt_edit_log_init')) {
    function tt_edit_log_init(): array {
        // Prefer log folder next to this file: /timetables/logs
        $dir1 = __DIR__ . '/logs';
        $dir2 = __DIR__ . '/../logs';
        $dir  = is_dir($dir1) ? $dir1 : (is_dir($dir2) ? $dir2 : $dir1);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = rtrim($dir, '/\\') . '/edit_flow.log';

        // If not writable, fallback to system temp
        if (!@is_writable($dir) && !@is_writable($file)) {
            $tmp = sys_get_temp_dir();
            $file = rtrim($tmp, '/\\') . '/edit_flow.log';
        }

        $rid = null;
        try {
            $rid = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $rid = uniqid('rid_', true);
        }

        return [
            'file' => $file,
            'rid'  => $rid,
        ];
    }

    function tt_edit_log_sanitize($v) {
        // Keep logs safe & readable (no huge blobs)
        if (is_string($v)) {
            $s = $v;
            if (strlen($s) > 2000) $s = substr($s, 0, 2000) . '…(truncated)';
            return $s;
        }
        if (is_int($v) || is_float($v) || is_bool($v) || $v === null) return $v;

        if (is_array($v)) {
            $out = [];
            $i = 0;
            foreach ($v as $k => $vv) {
                $out[$k] = tt_edit_log_sanitize($vv);
                $i++;
                if ($i >= 50) {
                    $out['__truncated__'] = 'array truncated at 50 keys';
                    break;
                }
            }
            return $out;
        }

        if (is_object($v)) {
            if ($v instanceof \JsonSerializable) {
                return tt_edit_log_sanitize($v->jsonSerialize());
            }
            return ['__object__' => get_class($v)];
        }

        return ['__type__' => gettype($v)];
    }

    function tt_edit_log(string $level, string $event, array $context = []): void {
        static $cfg = null;
        if ($cfg === null) $cfg = tt_edit_log_init();

        $ts = (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('c');

        $payload = [
            'ts'    => $ts,
            'level' => strtoupper($level),
            'event' => $event,
            'rid'   => $cfg['rid'],
            'file'  => basename(__FILE__),
            'uri'   => ($_SERVER['REQUEST_URI'] ?? ''),
            'method'=> ($_SERVER['REQUEST_METHOD'] ?? ''),
        ];

        // Safe, bounded context
        if (!empty($context)) {
            $payload['context'] = tt_edit_log_sanitize($context);
        }

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = '{"ts":"' . $ts . '","level":"ERROR","event":"LOG_JSON_ENCODE_FAILED","rid":"' . $cfg['rid'] . '"}';
        }

        @file_put_contents($cfg['file'], $line . PHP_EOL, FILE_APPEND);
    }

    function tt_edit_shape($v): array {
        // Compact shape descriptor for arrays/objects
        if ($v === null) return ['type'=>'null'];
        if (is_bool($v)) return ['type'=>'bool', 'value'=>$v];
        if (is_int($v)) return ['type'=>'int', 'value'=>$v];
        if (is_float($v)) return ['type'=>'float', 'value'=>$v];
        if (is_string($v)) return ['type'=>'string', 'len'=>strlen($v)];
        if (is_array($v)) {
            $keys = array_keys($v);
            $sampleKeys = array_slice($keys, 0, 15);
            return [
                'type' => 'array',
                'count'=> count($v),
                'keys_sample' => $sampleKeys,
            ];
        }
        if (is_object($v)) {
            return ['type'=>'object', 'class'=>get_class($v)];
        }
        return ['type'=>gettype($v)];
    }
}

// ---- Start request log
tt_edit_log('INFO', 'REQUEST_START', [
    'script' => __FILE__,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
]);

// Build absolute URL to THIS edit.php (immune to <base href>)
$TT_EDIT_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/edit.php';
tt_edit_log('INFO', 'EDIT_URL_BUILT', ['TT_EDIT_URL' => $TT_EDIT_URL]);

/* ---------------- selectors ---------------- */
tt_edit_log('INFO', 'LOAD_CLASSES_CALL', ['fn' => 'tt_get_classes', 'expected_return' => 'array[{id,name,...}]']);
$classes = tt_get_classes();
tt_edit_log('INFO', 'LOAD_CLASSES_RETURN', ['shape' => tt_edit_shape($classes)]);

$selected_class_id  = $_GET['class_id']  ?? $_POST['class_id']  ?? '';
$selected_stream_id = $_GET['stream_id'] ?? $_POST['stream_id'] ?? '';

tt_edit_log('INFO', 'INPUT_RAW_SELECTORS', [
    'GET.class_id'  => $_GET['class_id']  ?? null,
    'POST.class_id' => $_POST['class_id'] ?? null,
    'GET.stream_id' => $_GET['stream_id'] ?? null,
    'POST.stream_id'=> $_POST['stream_id'] ?? null,
]);

tt_edit_log('INFO', 'INPUT_NORMALIZED_SELECTORS', [
    'selected_class_id'  => $selected_class_id,
    'selected_stream_id' => $selected_stream_id,
    'types' => [
        'selected_class_id'  => gettype($selected_class_id),
        'selected_stream_id' => gettype($selected_stream_id),
    ],
]);

$streams = [];
if ($selected_class_id !== '') {
    tt_edit_log('INFO', 'LOAD_STREAMS_CALL', [
        'fn' => 'tt_get_streams_by_class',
        'param.class_id_raw' => $selected_class_id,
        'param.class_id_int' => (int)$selected_class_id,
        'expected_return' => 'array[{stream_id,stream_name,...}]'
    ]);
    $streams = tt_get_streams_by_class((int)$selected_class_id);
    tt_edit_log('INFO', 'LOAD_STREAMS_RETURN', ['shape' => tt_edit_shape($streams)]);
} else {
    tt_edit_log('INFO', 'LOAD_STREAMS_SKIPPED', ['reason' => 'selected_class_id empty']);
}

// validate stream belongs to class
$stream_ok = false;
if ($selected_class_id && $selected_stream_id && $streams) {
    tt_edit_log('INFO', 'STREAM_VALIDATE_START', [
        'selected_class_id' => $selected_class_id,
        'selected_stream_id'=> $selected_stream_id,
        'streams_count' => is_array($streams) ? count($streams) : 0,
        'expected_stream_key' => 'stream_id'
    ]);

    foreach ($streams as $s) {
        $sid = isset($s['stream_id']) ? (string)$s['stream_id'] : '';
        if ($sid !== '' && $sid === (string)$selected_stream_id) {
            $stream_ok = true;
            break;
        }
    }

    tt_edit_log('INFO', 'STREAM_VALIDATE_RESULT', [
        'stream_ok' => $stream_ok,
        'selected_stream_id' => (string)$selected_stream_id,
    ]);
} else {
    tt_edit_log('INFO', 'STREAM_VALIDATE_SKIPPED', [
        'reason' => 'missing selected_class_id or selected_stream_id or streams empty',
        'selected_class_id_present' => (bool)$selected_class_id,
        'selected_stream_id_present'=> (bool)$selected_stream_id,
        'streams_present' => !empty($streams),
    ]);
}

$can_edit = ($selected_class_id && $selected_stream_id && $stream_ok);
tt_edit_log('INFO', 'CAN_EDIT_COMPUTED', [
    'can_edit' => $can_edit,
    'inputs' => [
        'selected_class_id' => $selected_class_id,
        'selected_stream_id'=> $selected_stream_id,
        'stream_ok' => $stream_ok
    ]
]);

$flash = [
    'saved'   => (int)($_GET['saved'] ?? 0),
    'cleared' => (int)($_GET['cleared'] ?? 0),
    'error'   => (string)($_GET['error'] ?? ''),
];

tt_edit_log('INFO', 'FLASH_STATE', [
    'flash' => $flash,
    'expected' => [
        'saved:int', 'cleared:int', 'error:string'
    ]
]);

/* ---------------- POST handling ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    tt_edit_log('INFO', 'POST_RECEIVED', [
        'keys' => array_keys($_POST),
        'tt_action' => $_POST['tt_action'] ?? null,
        'class_id'  => $_POST['class_id'] ?? null,
        'stream_id' => $_POST['stream_id'] ?? null,
        'timetable_payload_len' => isset($_POST['timetable_payload']) ? strlen((string)$_POST['timetable_payload']) : 0,
        'times_payload_len'     => isset($_POST['times_payload']) ? strlen((string)$_POST['times_payload']) : 0,
        // safe hint only (no full payload)
        'timetable_payload_preview' => isset($_POST['timetable_payload']) ? substr((string)$_POST['timetable_payload'], 0, 180) : null,
        'times_payload_preview'     => isset($_POST['times_payload']) ? substr((string)$_POST['times_payload'], 0, 180) : null,
    ]);

    $action    = $_POST['tt_action'] ?? 'save';
    $class_id  = trim($_POST['class_id'] ?? '');
    $stream_id = trim($_POST['stream_id'] ?? '');

    tt_edit_log('INFO', 'POST_NORMALIZED', [
        'action' => $action,
        'class_id' => $class_id,
        'stream_id'=> $stream_id,
        'types' => [
            'action' => gettype($action),
            'class_id'=> gettype($class_id),
            'stream_id'=>gettype($stream_id),
        ],
        'expected_contract' => [
            'tt_action' => 'save|clear',
            'class_id'  => 'non-empty string numeric',
            'stream_id' => 'non-empty string numeric',
            'timetable_payload' => 'JSON string {meta:object, days:object}',
            'times_payload'     => 'JSON string (times object)',
        ],
    ]);

    if ($class_id === '') {
        tt_edit_log('WARN', 'POST_REJECTED', ['reason' => 'class_id empty', 'redirect' => 'error Please select a class']);
        header("Location: {$TT_EDIT_URL}?error=" . urlencode("Please select a class."));
        exit;
    }
    if ($stream_id === '') {
        tt_edit_log('WARN', 'POST_REJECTED', ['reason' => 'stream_id empty', 'redirect' => 'error Please select a stream', 'class_id' => $class_id]);
        header("Location: {$TT_EDIT_URL}?class_id={$class_id}&error=" . urlencode("Please select a stream."));
        exit;
    }

    // Optional: lightweight JSON validity checks (do not block saves; just log)
    $ttPayloadRaw = (string)($_POST['timetable_payload'] ?? '{}');
    $timesRaw     = (string)($_POST['times_payload'] ?? '{}');

    $ttDecoded = json_decode($ttPayloadRaw, true);
    $ttJsonOk  = (json_last_error() === JSON_ERROR_NONE);

    $timesDecoded = json_decode($timesRaw, true);
    $timesJsonOk  = (json_last_error() === JSON_ERROR_NONE);

    tt_edit_log('INFO', 'POST_JSON_VALIDATION', [
        'timetable_payload_json_ok' => $ttJsonOk,
        'timetable_payload_shape'   => tt_edit_shape($ttDecoded),
        'times_payload_json_ok'     => $timesJsonOk,
        'times_payload_shape'       => tt_edit_shape($timesDecoded),
        'notes' => 'Validation is non-blocking; used for tracing expected formats.',
    ]);

    if ($action === 'clear') {
        tt_edit_log('INFO', 'CLEAR_ACTION_CALL', [
            'fn' => 'tt_clear_timetable_for_stream',
            'param.stream_id_raw' => $stream_id,
            'param.stream_id_int' => (int)$stream_id
        ]);
        tt_clear_timetable_for_stream((int)$stream_id);
        tt_edit_log('INFO', 'CLEAR_ACTION_DONE', ['redirect' => 'cleared=1', 'class_id' => $class_id, 'stream_id' => $stream_id]);
        header("Location: {$TT_EDIT_URL}?class_id={$class_id}&stream_id={$stream_id}&cleared=1");
        exit;
    }

    tt_edit_log('INFO', 'SAVE_ACTION_CALL', [
        'fn1' => 'tt_save_timetable_for_stream',
        'fn2' => 'tt_save_global_times',
        'param.stream_id_int' => (int)$stream_id,
        'timetable_payload_len' => strlen($ttPayloadRaw),
        'times_payload_len'     => strlen($timesRaw),
        'expected_timetable_payload' => '{ meta: object, days: object }',
    ]);

    $ok1 = tt_save_timetable_for_stream((int)$stream_id, $_POST['timetable_payload'] ?? '{}');
    $ok2 = tt_save_global_times($_POST['times_payload'] ?? '{}');

    tt_edit_log('INFO', 'SAVE_ACTION_RESULT', [
        'ok1_tt_save_timetable_for_stream' => (bool)$ok1,
        'ok2_tt_save_global_times'         => (bool)$ok2,
        'redirect_success' => ($ok1 && $ok2),
    ]);

    header(
        "Location: {$TT_EDIT_URL}?class_id={$class_id}&stream_id={$stream_id}&" .
        ($ok1 && $ok2 ? 'saved=1' : 'error=Save+failed')
    );
    exit;
}

/* ---------------- Load data ---------------- */
tt_edit_log('INFO', 'LOAD_DATA_PHASE_START', [
    'can_edit' => $can_edit,
    'selected_class_id' => $selected_class_id,
    'selected_stream_id'=> $selected_stream_id,
]);

$loaded_timetable = null;
if ($can_edit) {
    tt_edit_log('INFO', 'LOAD_TIMETABLE_CALL', [
        'fn' => 'tt_load_timetable_for_stream',
        'param.stream_id_int' => (int)$selected_stream_id,
        'expected_return' => 'array|null (timetable object)'
    ]);
    $loaded_timetable = tt_load_timetable_for_stream((int)$selected_stream_id);
    // Auto-fill Class Teacher from stream (display/meta only)
    if (is_array($loaded_timetable)) {
        $ct = tt_stream_class_teacher_label((int)$selected_stream_id);

        // No placeholders: only set if we truly found something
        if ($ct !== '') {
            if (!isset($loaded_timetable['meta']) || !is_array($loaded_timetable['meta'])) {
                $loaded_timetable['meta'] = [];
            }

            // Your normalizeLoadedMeta() already supports classTeacher → teacher mapping,
            // but we set both to be bulletproof for the grid meta contract.
            $loaded_timetable['meta']['classTeacher'] = $ct;
            $loaded_timetable['meta']['teacher'] = $ct;

            tt_edit_log('INFO', 'CLASS_TEACHER_AUTOFILL_SET', [
                'stream_id' => (int)$selected_stream_id,
                'classTeacher' => $ct
            ]);
        } else {
            tt_edit_log('INFO', 'CLASS_TEACHER_AUTOFILL_EMPTY', [
                'stream_id' => (int)$selected_stream_id,
                'note' => 'No class teacher found for this stream using known columns.'
            ]);
        }
    }
    
    tt_edit_log('INFO', 'LOAD_TIMETABLE_RETURN', [
        'shape' => tt_edit_shape($loaded_timetable),
        'has_meta' => is_array($loaded_timetable) ? array_key_exists('meta', $loaded_timetable) : null,
        'has_days' => is_array($loaded_timetable) ? array_key_exists('days', $loaded_timetable) : null,
    ]);
} else {
    tt_edit_log('INFO', 'LOAD_TIMETABLE_SKIPPED', ['reason' => 'can_edit false']);
}

tt_edit_log('INFO', 'LOAD_GLOBAL_TIMES_CALL', [
    'fn' => 'tt_load_global_times',
    'expected_return' => 'array (times object)'
]);
$global_times = tt_load_global_times();
tt_edit_log('INFO', 'LOAD_GLOBAL_TIMES_RETURN', ['shape' => tt_edit_shape($global_times)]);

// STRICT: subjects ONLY from class_subjects
$subjects = [];
if ($selected_class_id) {
    tt_edit_log('INFO', 'LOAD_SUBJECTS_CALL', [
        'fn' => 'tt_get_subjects_for_class',
        'param.class_id_int' => (int)$selected_class_id,
        'expected_return' => 'array[{id,name,...}]'
    ]);
    $subjects = tt_get_subjects_for_class((int)$selected_class_id);
    tt_edit_log('INFO', 'LOAD_SUBJECTS_RETURN', ['shape' => tt_edit_shape($subjects)]);
} else {
    tt_edit_log('INFO', 'LOAD_SUBJECTS_SKIPPED', ['reason' => 'selected_class_id empty']);
}

$teacher_map = [];
if ($can_edit) {
    tt_edit_log('INFO', 'LOAD_TEACHER_MAP_CALL', [
        'fn' => 'tt_get_teacher_subject_map_for_stream',
        'param.stream_id_int' => (int)$selected_stream_id,
        'expected_return' => 'map{ subjectId: [{id,name},...] }'
    ]);
    $teacher_map = tt_get_teacher_subject_map_for_stream((int)$selected_stream_id);
    tt_edit_log('INFO', 'LOAD_TEACHER_MAP_RETURN', ['shape' => tt_edit_shape($teacher_map)]);
} else {
    tt_edit_log('INFO', 'LOAD_TEACHER_MAP_SKIPPED', ['reason' => 'can_edit false']);
}

tt_edit_log('INFO', 'LOAD_DATA_PHASE_DONE', [
    'classes' => tt_edit_shape($classes),
    'streams' => tt_edit_shape($streams),
    'subjects'=> tt_edit_shape($subjects),
    'teacher_map' => tt_edit_shape($teacher_map),
]);

/* ---------------- Layout ---------------- */
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../sidebar.php';
?>

<style>
.content{
    margin-left:260px;
    padding:24px;
    width:calc(100% - 260px);
    max-width:none;
}
</style>

<div class="content">
<div class="container-fluid">

<?php if ($flash['saved']): ?>
  <div class="alert alert-success">Timetable saved successfully.</div>
<?php endif; ?>
<?php if ($flash['cleared']): ?>
  <div class="alert alert-warning">Timetable cleared.</div>
<?php endif; ?>
<?php if ($flash['error']): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash['error']) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-4">
<div class="card-body">

<form id="ttEditorForm" method="post">

<!-- ROW 1 -->
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Select Class</label>
        <select name="class_id" id="ttClassSelect" class="form-select" required>
            <option value="">-- Select Class --</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['id']==$selected_class_id?'selected':'' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Select Stream</label>
        <select name="stream_id" id="ttStreamSelect" class="form-select" <?= !$selected_class_id?'disabled':'' ?> required>
            <option value="">-- Select Stream --</option>
            <?php foreach ($streams as $s): ?>
                <option value="<?= $s['stream_id'] ?>" <?= $s['stream_id']==$selected_stream_id?'selected':'' ?>>
                    <?= htmlspecialchars($s['stream_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="text-muted small mt-1">Timetables are stream-based.</div>
    </div>
</div>

<!-- ROW 2 -->
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Select Subject</label>
        <select id="ttSubjectSelect" class="form-select" <?= !$can_edit?'disabled':'' ?>>
            <option value="">-- Select Subject --</option>
            <?php foreach ($subjects as $s): ?>
                <option value="<?= $s['id'] ?>">
                    <?= htmlspecialchars($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Assigned Teacher</label>
        <div class="form-control bg-light" id="ttTeacherAutoLabel" style="min-height:38px">
            <span class="text-muted">Teacher auto-assigned.</span>
        </div>
    </div>
</div>

<!-- ROW 3 -->
<div class="alert alert-info mb-4">
    <strong>How to fill the timetable</strong><br>
    1) Select class<br>
    2) Select stream<br>
    3) Select subject<br>
    4) Click lesson cell
</div>

<!-- ROW 4 -->
<div class="d-flex justify-content-end gap-2">
    <button type="button" id="ttBtnNew" class="btn btn-outline-primary" <?= !$can_edit?'disabled':'' ?>>New</button>
    <button type="button" id="ttBtnClear" class="btn btn-outline-danger" <?= !$can_edit?'disabled':'' ?>>Clear</button>
    <button type="submit" id="ttBtnSave" class="btn btn-primary" <?= !$can_edit?'disabled':'' ?>>Save</button>
</div>

<input type="hidden" name="tt_action" id="ttAction" value="save">
<input type="hidden" name="timetable_payload" id="ttPayload">
<input type="hidden" name="times_payload" id="ttTimesPayload">

</form>
</div>
</div>

<?php if ($can_edit): ?>
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php
            tt_edit_log('INFO', 'INCLUDE_TIMETABLE_GRID', [
                'file' => __DIR__ . '/timetable_grid.php',
                'note' => 'Rendering grid component (PHP include).'
            ]);
            require __DIR__ . '/timetable_grid.php';
        ?>
    </div>
</div>
<?php else: ?>
<?php
    tt_edit_log('INFO', 'GRID_NOT_RENDERED', [
        'reason' => 'can_edit false (must pick class + stream and stream must belong to class)',
        'selected_class_id' => $selected_class_id,
        'selected_stream_id'=> $selected_stream_id,
        'stream_ok' => $stream_ok
    ]);
?>
<?php endif; ?>

</div>
</div>

<script>
(function(){

  // NO CLIENT-SIDE LOGGING (no console.*)
  function ttClientLog(event, context){ /* intentionally blank */ }

  const EDIT_URL = <?= json_encode($TT_EDIT_URL) ?>;
  const TEACHER_MAP = <?= json_encode($teacher_map, JSON_UNESCAPED_UNICODE) ?>;

  const LOADED_TIMETABLE = <?= json_encode($loaded_timetable ?? null, JSON_UNESCAPED_UNICODE) ?>;
  const GLOBAL_TIMES     = <?= json_encode($global_times ?? null, JSON_UNESCAPED_UNICODE) ?>; // single declaration (fix)

  const els = {
    classSel:   document.getElementById('ttClassSelect'),
    streamSel:  document.getElementById('ttStreamSelect'),
    subjSel:    document.getElementById('ttSubjectSelect'),
    teacherLbl: document.getElementById('ttTeacherAutoLabel'),

    form:       document.getElementById('ttEditorForm'),
    actionInp:  document.getElementById('ttAction'),
    payloadInp: document.getElementById('ttPayload'),
    timesInp:   document.getElementById('ttTimesPayload'),

    btnClear:   document.getElementById('ttBtnClear'),
    btnNew:     document.getElementById('ttBtnNew'),
  };

  // ✅ Backend (functions.php) + Grid component both use these exact day keys
  const DAY_KEYS = ['Mo','Tu','We','Th','Fr'];

  // ✅ Only teaching slots are saved/loaded by functions.php
  const TEACHING_SLOTS = ['p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'];

  // Single source of truth for current selection
  const selection = {
    subjectId: null,
    subjectName: '',
    teacherId: null,
    teacherName: ''
  };

  function escHtml(s){
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  function getFirstTeacherForSubject(subjectId){
    const list = (subjectId && TEACHER_MAP[String(subjectId)]) ? TEACHER_MAP[String(subjectId)] : (TEACHER_MAP[subjectId] || []);
    if (!Array.isArray(list) || !list.length) return null;
    const t = list[0];
    const id = parseInt(t.id, 10);
    return {
      id: Number.isFinite(id) && id > 0 ? id : null,
      name: String(t.name ?? '').trim()
    };
  }

  function renderAssignedTeacher(){
    if (!els.teacherLbl) return;

    if (!selection.subjectId) {
      els.teacherLbl.innerHTML = '<span class="text-muted">Teacher auto-assigned.</span>';
      return;
    }

    if (selection.teacherId && selection.teacherName) {
      els.teacherLbl.innerHTML = '<strong>' + escHtml(selection.teacherName) + '</strong>';
      return;
    }

    els.teacherLbl.innerHTML = '<span class="text-muted">No teacher mapped.</span>';
  }

  function setSelection(subjectId){
    const sid = subjectId ? (parseInt(subjectId, 10) || null) : null;

    selection.subjectId = sid;
    selection.subjectName = '';
    selection.teacherId = null;
    selection.teacherName = '';

    if (els.subjSel && sid) {
      const opt = els.subjSel.querySelector('option[value="' + CSS.escape(String(sid)) + '"]');
      selection.subjectName = opt ? opt.textContent.trim() : '';
    }

    if (sid) {
      const t = getFirstTeacherForSubject(sid);
      if (t && t.id) {
        selection.teacherId = t.id;
        selection.teacherName = t.name;
      }
    }

    renderAssignedTeacher();
  }

  // Class/Stream redirects (server-side reload)
  if (els.classSel) {
    els.classSel.addEventListener('change', () => {
      const cid = els.classSel.value || '';
      window.location.href = cid
        ? EDIT_URL + '?class_id=' + encodeURIComponent(cid)
        : EDIT_URL;
    });
  }

  if (els.streamSel) {
    els.streamSel.addEventListener('change', () => {
      const cid = els.classSel ? (els.classSel.value || '') : '';
      const sid = els.streamSel.value || '';
      if (!cid) return;
      window.location.href = sid
        ? EDIT_URL + '?class_id=' + encodeURIComponent(cid) + '&stream_id=' + encodeURIComponent(sid)
        : EDIT_URL + '?class_id=' + encodeURIComponent(cid);
    });
  }

  // Subject selection drives selection state (authoritative)
  if (els.subjSel) {
    els.subjSel.addEventListener('change', () => {
      setSelection(els.subjSel.value || null);
    });
  }

  function isLessonCell(td){
    // lesson cells have [data-field="subject"] + [data-field="teacher"]
    return !!(td && td.querySelector && td.querySelector('[data-field="subject"]') && td.querySelector('[data-field="teacher"]'));
  }

  function applySelectionToCell(td){
    if (!td || !selection.subjectId) return;

    const subjEl  = td.querySelector('[data-field="subject"]');
    const teachEl = td.querySelector('[data-field="teacher"]');
    if (!subjEl || !teachEl) return;

    // Write visible text
    subjEl.textContent = selection.subjectName || subjEl.textContent || '';
    teachEl.textContent = (selection.teacherId && selection.teacherName) ? selection.teacherName : '';

    // Write deterministic IDs (dataset is the single persistence source for the grid)
    td.dataset.subjectId = String(selection.subjectId);
    td.dataset.teacherId = (selection.teacherId ? String(selection.teacherId) : '');

    // Trigger auto-fit if the grid component listens for it
    subjEl.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Grid click handling: state-driven, not DOM guessing
  document.addEventListener('click', (e) => {
    const td = e.target.closest('td.tt-slot');
    if (!td) return;
    if (!isLessonCell(td)) return;
    if (!selection.subjectId) return;

    applySelectionToCell(td);
  });

  function normalizeLoadedMeta(meta){
    const m = meta && typeof meta === 'object' ? meta : {};
    // Accept either naming style (old backend vs TT component)
    return {
      dept:   (m.dept ?? m.department ?? '').toString(),
      teacher:(m.teacher ?? m.classTeacher ?? '').toString(),
      term:   (m.term ?? '').toString(),
      form:   (m.form ?? '').toString(),
      codes:  (m.codes ?? m.footerCodes ?? '').toString(),
      brand:  (m.brand ?? m.footerBrand ?? '').toString(),
    };
  }

  function bootLoadIntoGrid(){
    if (!LOADED_TIMETABLE) return;
    if (!window.TT || typeof window.TT.setData !== 'function') return;

    const meta  = normalizeLoadedMeta(LOADED_TIMETABLE.meta || {});
    const times = (GLOBAL_TIMES && typeof GLOBAL_TIMES === 'object') ? GLOBAL_TIMES : {};

    // ✅ functions.php returns: { days: { Mo:{p1:{...}}, Tu:{...} } }
    const rawDays = (LOADED_TIMETABLE.days && typeof LOADED_TIMETABLE.days === 'object')
      ? LOADED_TIMETABLE.days
      : {};

    const days = {};

    for (const dayKey of DAY_KEYS) {
      const dayObj = rawDays[dayKey];
      if (!dayObj || typeof dayObj !== 'object') continue;

      days[dayKey] = {};

      for (const slotKey of TEACHING_SLOTS) {
        const cell = dayObj[slotKey];
        if (!cell || typeof cell !== 'object') continue;

        days[dayKey][slotKey] = {
          subject: cell.subject ?? '',
          teacher: cell.teacher ?? '',
          subject_id: (cell.subject_id ?? cell.subjectId ?? 0) || 0,
          teacher_id: (cell.teacher_id ?? cell.teacherId ?? 0) || 0
        };
      }
    }

    window.TT.setData({
      meta,
      times,
      grid: { days }
    });
  }
  
  // Ensure TT grid exists before loading server data
  function isGridBuilt(){
    // after build, timetable_grid.php creates td.tt-slot cells
    return !!document.querySelector('td.tt-slot');
  }

  (function waitForGrid(attempts){
    if (window.TT && typeof window.TT.setData === 'function' && isGridBuilt()) {
      bootLoadIntoGrid();
      return;
    }
    if (attempts <= 0) {
      return;
    }
    requestAnimationFrame(() => waitForGrid(attempts - 1));
  })(120);


  /* ---------- Form actions + deterministic payload ---------- */
  if (els.form) {
    function setAction(val){
      if (els.actionInp) els.actionInp.value = val;
    }

    function fillPayloads(){
      const ttData = (window.TT && typeof window.TT.getData === 'function') ? window.TT.getData() : null;
      const timesObj = (window.TT && typeof window.TT.getTimes === 'function') ? window.TT.getTimes() : {};

      // timetable_payload = { meta: {...}, days: {...} }
      const meta = ttData && ttData.meta ? ttData.meta : {};
      const days = (ttData && ttData.grid && ttData.grid.days) ? ttData.grid.days : {};

      const payload = { meta, days };

      if (els.payloadInp) els.payloadInp.value = JSON.stringify(payload);
      if (els.timesInp) els.timesInp.value = JSON.stringify(timesObj);
    }

    if (els.btnClear) {
      els.btnClear.addEventListener('click', () => {
        setAction('clear');
        fillPayloads();
        els.form.submit();
      });
    }

    if (els.btnNew) {
      els.btnNew.addEventListener('click', () => {
        setAction('save');
        if (window.TT && typeof window.TT.clear === 'function') {
          window.TT.clear(false); // false = keep meta
        }
      });
    }

    els.form.addEventListener('submit', () => {
      if (!els.actionInp || !els.actionInp.value) setAction('save');
      fillPayloads();
    });
  }

  // Initialize teacher label on page load if a subject is already selected
  if (els.subjSel && els.subjSel.value) {
    setSelection(els.subjSel.value);
  } else {
    renderAssignedTeacher();
  }

})();
</script>

<?php
tt_edit_log('INFO', 'REQUEST_END', [
    'note' => 'Page rendered (footer next).'
]);
require_once __DIR__ . '/../footer.php';
?>
