<?php
// --- Timetable module hooks (DB-backed) ---
// Uses schema: timetables, timetable_settings, time_slots, streams, classes, subjects, users
// Assumes $conn is available globally (from connection/db.php included by auth_middleware.php)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * DB accessor
 */
function tt_db() {
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn || !($conn instanceof mysqli)) return null;
    return $conn;
}

/**
 * Resolve school_id from session (best-effort, supports multiple session shapes)
 */
function tt_school_id() {
    // common
    if (!empty($_SESSION['school_id'])) return (int)$_SESSION['school_id'];

    // sometimes stored under user array
    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['school_id'])) {
        return (int)$_SESSION['user']['school_id'];
    }

    // sometimes stored under auth middleware values
    if (!empty($_SESSION['auth']) && is_array($_SESSION['auth']) && !empty($_SESSION['auth']['school_id'])) {
        return (int)$_SESSION['auth']['school_id'];
    }

    // fallback: if user_id exists, query DB
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($user_id > 0) {
        $conn = tt_db();
        if ($conn) {
            $stmt = $conn->prepare("SELECT school_id FROM users WHERE user_id=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $stmt->close();
                    return (int)$row['school_id'];
                }
                $stmt->close();
            }
        }
    }

    return 0;
}

function tt_days_map() {
    return [
        'Mo' => 'Monday',
        'Tu' => 'Tuesday',
        'We' => 'Wednesday',
        'Th' => 'Thursday',
        'Fr' => 'Friday',
    ];
}

/**
 * UI col -> slot_number (13 total slots include breaks/lunch, UI has 10 lesson cols)
 */
function tt_col_to_slot_number() {
    return [
        'p1'  => 1,
        'p2'  => 2,
        'p3'  => 4,
        'p4'  => 5,
        'p5'  => 7,
        'p6'  => 8,
        'p7'  => 9,
        'p8'  => 11,
        'p9'  => 12,
        'p10' => 13,
    ];
}

function tt_slot_number_meta() {
    // slot_number => [is_break, is_teaching_slot, break_name]
    return [
        1  => [0, 1, null],
        2  => [0, 1, null],
        3  => [1, 0, 'Short Break'],
        4  => [0, 1, null],
        5  => [0, 1, null],
        6  => [1, 0, 'Break'],
        7  => [0, 1, null],
        8  => [0, 1, null],
        9  => [0, 1, null],
        10 => [1, 0, 'Lunch'],
        11 => [0, 1, null],
        12 => [0, 1, null],
        13 => [0, 1, null],
    ];
}

/**
 * Parse "08:00-08:40" or "08:00 - 08:40" or "08:00 to 08:40"
 * Returns [start,end] as "HH:MM:SS" or null
 */
function tt_parse_time_range($s) {
    $s = trim((string)$s);
    if ($s === '') return null;

    if (!preg_match_all('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $s, $m) || count($m[0]) < 2) {
        return null;
    }

    $t1 = $m[0][0] . ':00';
    $t2 = $m[0][1] . ':00';
    return [$t1, $t2];
}

function tt_minutes_diff($start, $end) {
    $s = strtotime("1970-01-01 $start UTC");
    $e = strtotime("1970-01-01 $end UTC");
    if ($s === false || $e === false) return null;
    $diff = (int)(($e - $s) / 60);
    return $diff > 0 ? $diff : null;
}

/**
 * Ensure at least one stream exists for class; returns stream_id
 */
function tt_ensure_stream_id($school_id, $class_id) {
    $conn = tt_db();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE school_id=? AND class_id=? ORDER BY stream_id ASC LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param("ii", $school_id, $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $stmt->close();
        return (int)$row['stream_id'];
    }
    $stmt->close();

    // create default stream
    $stream_name = 'A';
    $desc = 'Auto-created default stream';

    $stmt2 = $conn->prepare("INSERT INTO streams (school_id, class_id, stream_name, description) VALUES (?,?,?,?)");
    if (!$stmt2) return null;

    $stmt2->bind_param("iiss", $school_id, $class_id, $stream_name, $desc);
    $ok = $stmt2->execute();
    $new_id = $ok ? (int)$stmt2->insert_id : null;
    $stmt2->close();

    if ($new_id) return $new_id;

    // fallback fetch
    $stmt3 = $conn->prepare("SELECT stream_id FROM streams WHERE school_id=? AND class_id=? ORDER BY stream_id ASC LIMIT 1");
    if (!$stmt3) return null;

    $stmt3->bind_param("ii", $school_id, $class_id);
    $stmt3->execute();
    $r = $stmt3->get_result();
    $id = ($r && ($rw = $r->fetch_assoc())) ? (int)$rw['stream_id'] : null;
    $stmt3->close();
    return $id;
}

/**
 * Get or create school_settings row for school; returns setting_id
 */
function tt_get_or_create_school_setting_id($school_id) {
    $conn = tt_db();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT setting_id FROM school_settings WHERE school_id=? ORDER BY setting_id DESC LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $stmt->close();
        return (int)$row['setting_id'];
    }
    $stmt->close();

    // create minimal default term/year
    $term = 'Term 1';
    $year = (int)date('Y');

    $stmt2 = $conn->prepare("INSERT INTO school_settings (school_id, term_name, academic_year) VALUES (?,?,?)");
    if (!$stmt2) return null;

    $stmt2->bind_param("isi", $school_id, $term, $year);
    $ok = $stmt2->execute();
    $id = $ok ? (int)$stmt2->insert_id : null;
    $stmt2->close();

    return $id;
}

/**
 * Get or create timetable_settings row for school; returns timetable_setting_id
 */
function tt_get_or_create_timetable_setting_id($school_id) {
    $conn = tt_db();
    if (!$conn) return null;

    // active first
    $stmt = $conn->prepare("SELECT timetable_setting_id FROM timetable_settings WHERE school_id=? AND status='active' ORDER BY timetable_setting_id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $stmt->close();
            return (int)$row['timetable_setting_id'];
        }
        $stmt->close();
    }

    // latest
    $stmt2 = $conn->prepare("SELECT timetable_setting_id FROM timetable_settings WHERE school_id=? ORDER BY timetable_setting_id DESC LIMIT 1");
    if ($stmt2) {
        $stmt2->bind_param("i", $school_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($res2 && ($row2 = $res2->fetch_assoc())) {
            $stmt2->close();
            return (int)$row2['timetable_setting_id'];
        }
        $stmt2->close();
    }

    // create draft
    $setting_id = tt_get_or_create_school_setting_id($school_id);
    if (!$setting_id) return null;

    $start = '08:00:00';
    $end   = '16:00:00';
    $lesson_duration = 40;
    $periods = 10;
    $total_slots = 13;
    $days = 5;
    $target_week = 0;
    $short_break = 10;
    $long_break  = 20;
    $lunch        = 30;
    $allow_sat    = 0;
    $status       = 'draft';
    $comp = 6;
    $elec = 6;

    $stmt3 = $conn->prepare("
        INSERT INTO timetable_settings
        (setting_id, school_id, start_time, end_time, lesson_duration_minutes, periods_per_day, total_slots_per_day, days_per_week,
         total_target_lessons_per_week, short_break_duration_minutes, long_break_duration_minutes, lunch_break_duration_minutes,
         allow_saturday, status, compulsory_lessons_per_week, elective_lessons_per_week)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt3) return null;

    // ✅ FIXED types string: 16 types for 16 variables
    // i i s s  i i i i i i i i i  s  i i
    $stmt3->bind_param(
        "iissiiiiiiiiisii",
        $setting_id, $school_id, $start, $end,
        $lesson_duration, $periods, $total_slots, $days,
        $target_week, $short_break, $long_break, $lunch,
        $allow_sat, $status, $comp, $elec
    );

    $ok = $stmt3->execute();
    $id = $ok ? (int)$stmt3->insert_id : null;
    $stmt3->close();

    return $id;
}

/**
 * Ensure time_slots exist for timetable_setting_id (slot_number 1..13)
 */
function tt_ensure_time_slots($timetable_setting_id) {
    $conn = tt_db();
    if (!$conn) return false;

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM time_slots WHERE timetable_setting_id=?");
    if (!$stmt) return false;

    $stmt->bind_param("i", $timetable_setting_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = ($res && ($row = $res->fetch_assoc())) ? (int)$row['c'] : 0;
    $stmt->close();

    if ($count >= 13) return true;

    $meta = tt_slot_number_meta();

    for ($i = 1; $i <= 13; $i++) {
        $is_break   = (int)$meta[$i][0];
        $is_teach   = (int)$meta[$i][1];
        $break_name = $meta[$i][2];

        // placeholder times (will be updated by tt_save_global_times)
        $start = '08:00:00';
        $end   = '08:40:00';
        $dur   = 40;

        $stmt2 = $conn->prepare("
            INSERT INTO time_slots
            (timetable_setting_id, slot_number, start_time, end_time, duration_minutes, is_break, break_name, is_teaching_slot)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                is_break=VALUES(is_break),
                break_name=VALUES(break_name),
                is_teaching_slot=VALUES(is_teaching_slot)
        ");
        if (!$stmt2) continue;

        // ✅ FIXED types string (8 vars):
        // timetable_setting_id(i), slot_number(i), start(s), end(s), dur(i), is_break(i), break_name(s), is_teach(i)
        $stmt2->bind_param("iissiisi", $timetable_setting_id, $i, $start, $end, $dur, $is_break, $break_name, $is_teach);
        $stmt2->execute();
        $stmt2->close();
    }

    return true;
}

/**
 * Resolve subject name -> subject_id (create if missing)
 */
function tt_resolve_subject_id($school_id, $subject_name) {
    $conn = tt_db();
    if (!$conn) return null;

    $name = trim((string)$subject_name);
    if ($name === '') return null;

    $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE school_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("is", $school_id, $name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $stmt->close();
            return (int)$row['subject_id'];
        }
        $stmt->close();
    }

    // Also resolve by subject_initial (when grid shows abbreviations like MAT)
    $maybeInitial = strtoupper(trim((string)$name));
    if ($maybeInitial !== '') {
        $stmtI = $conn->prepare("SELECT subject_id FROM subjects WHERE school_id=? AND UPPER(subject_initial)=? AND deleted_at IS NULL LIMIT 1");
        if ($stmtI) {
            $stmtI->bind_param("is", $school_id, $maybeInitial);
            $stmtI->execute();
            $resI = $stmtI->get_result();
            if ($resI && ($rowI = $resI->fetch_assoc())) {
                $stmtI->close();
                return (int)$rowI['subject_id'];
            }
            $stmtI->close();
        }
    }

    $initial = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 5));
    if ($initial === '') $initial = null;

    $stmt2 = $conn->prepare("INSERT INTO subjects (school_id, name, subject_initial) VALUES (?,?,?)");
    if (!$stmt2) return null;

    $stmt2->bind_param("iss", $school_id, $name, $initial);
    $ok = $stmt2->execute();
    $id = $ok ? (int)$stmt2->insert_id : null;
    $stmt2->close();

    return $id;
}

/**
 * Resolve teacher text -> user_id (best effort, no user auto-create)
 */
function tt_resolve_teacher_id($school_id, $teacher_text) {
    $conn = tt_db();
    if (!$conn) return null;

    $t = trim((string)$teacher_text);
    if ($t === '') return null;

    // username exact
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE school_id=? AND username=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("is", $school_id, $t);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $stmt->close();
            return (int)$row['user_id'];
        }
        $stmt->close();
    }

    // full name match
    $stmt2 = $conn->prepare("SELECT user_id FROM users WHERE school_id=? AND CONCAT(TRIM(first_name),' ',TRIM(other_names))=? LIMIT 1");
    if ($stmt2) {
        $stmt2->bind_param("is", $school_id, $t);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($res2 && ($row2 = $res2->fetch_assoc())) {
            $stmt2->close();
            return (int)$row2['user_id'];
        }
        $stmt2->close();
    }

    return null;
}

/**
 * Classes dropdown
 */
function tt_get_classes() {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return [];

    $stmt = $conn->prepare("SELECT class_id AS id, form_name AS name FROM classes WHERE school_id=? ORDER BY class_id DESC");
    if (!$stmt) return [];

    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $out[] = ['id' => (string)$row['id'], 'name' => (string)$row['name']];
    }
    $stmt->close();
    return $out;
}

/**
 * Subjects dropdown (id, name, subject_initial)
 */
function tt_get_subjects() {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return [];

    $stmt = $conn->prepare("SELECT subject_id AS id, name, subject_initial FROM subjects WHERE school_id=? AND deleted_at IS NULL ORDER BY name ASC");
    if (!$stmt) return [];

    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $out[] = [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'initial' => (string)($row['subject_initial'] ?? '')
        ];
    }
    $stmt->close();

    return $out;
}

/**
 * Best-effort academic year from school_settings
 */
function tt_get_current_academic_year($school_id) {
    $conn = tt_db();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT academic_year FROM school_settings WHERE school_id=? ORDER BY setting_id DESC LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $year = null;
    if ($res && ($row = $res->fetch_assoc())) {
        $year = $row['academic_year'] ?? null;
    }

    $stmt->close();

    if ($year === null || $year === '') return null;
    return (int)$year;
}

function tt_teacher_label_from_row($row) {
    $initials = trim((string)($row['initials'] ?? ''));
    $username = trim((string)($row['username'] ?? ''));
    $full = trim((string)($row['full_name'] ?? ''));

    if ($initials !== '') return $initials;
    if ($full !== '') return $full;
    if ($username !== '') return $username;

    return 'Teacher #' . (string)($row['user_id'] ?? '');
}

/**
 * Teachers dropdown (basic, all active users in school) - fallback helper
 */
function tt_get_teachers_basic() {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return [];

    $stmt = $conn->prepare("
        SELECT user_id, username, initials, CONCAT(TRIM(first_name),' ',TRIM(other_names)) AS full_name
        FROM users
        WHERE school_id=? AND deleted_at IS NULL AND (status IS NULL OR status='active')
        ORDER BY full_name ASC, username ASC
    ");
    if (!$stmt) return [];

    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $out[] = [
            'id' => (string)$row['user_id'],
            'name' => tt_teacher_label_from_row($row),
        ];
    }
    $stmt->close();

    return $out;
}

/**
 * Teachers for a subject (uses teacher_subjects mapping)
 * Filters by: school_id + subject_id
 * Best-effort constraints: class_id/stream_id/academic_year (NULL rows are treated as global)
 */
function tt_get_teachers_for_subject($subject_id, $class_id = 0) {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return [];

    $subject_id = (int)$subject_id;
    if ($subject_id <= 0) return [];

    $class_id = (int)$class_id;
    $stream_id = null;
    if ($class_id > 0) {
        $stream_id = tt_ensure_stream_id($school_id, $class_id);
    }

    $acad_year = tt_get_current_academic_year($school_id);

    // Build query based on optional filters to keep bind_param simple and safe
    $base = "
        SELECT DISTINCT
            u.user_id,
            u.username,
            u.initials,
            CONCAT(TRIM(u.first_name),' ',TRIM(u.other_names)) AS full_name
        FROM teacher_subjects ts
        JOIN users u ON u.user_id = ts.user_id
        WHERE ts.school_id=?
          AND ts.subject_id=?
          AND u.deleted_at IS NULL
          AND (u.status IS NULL OR u.status='active')
    ";

    $conds = "";
    $types = "ii";
    $params = [$school_id, $subject_id];

    if ($class_id > 0) {
        $conds .= " AND (ts.class_id IS NULL OR ts.class_id=?) ";
        $types .= "i";
        $params[] = $class_id;
    }

    if (!empty($stream_id)) {
        $conds .= " AND (ts.stream_id IS NULL OR ts.stream_id=?) ";
        $types .= "i";
        $params[] = (int)$stream_id;
    }

    if (!empty($acad_year)) {
        $conds .= " AND (ts.academic_year IS NULL OR ts.academic_year=?) ";
        $types .= "i";
        $params[] = (int)$acad_year;
    }

    $sql = $base . $conds . " ORDER BY full_name ASC, username ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    // bind dynamically
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $out[] = [
            'id' => (string)$row['user_id'],
            'name' => tt_teacher_label_from_row($row),
        ];
    }
    $stmt->close();

    // If no mapped teachers found, fallback to all teachers (basic list)
    if (empty($out)) {
        return tt_get_teachers_basic();
    }

    return $out;
}

/**
 * Build subject_id => teachers[] map for a class (uses teacher_subjects mapping)
 */
function tt_get_teacher_subject_map($class_id) {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return [];

    $class_id = (int)$class_id;
    if ($class_id <= 0) return [];

    $stream_id = tt_ensure_stream_id($school_id, $class_id);
    $acad_year = tt_get_current_academic_year($school_id);

    $base = "
        SELECT DISTINCT
            ts.subject_id,
            u.user_id,
            u.username,
            u.initials,
            CONCAT(TRIM(u.first_name),' ',TRIM(u.other_names)) AS full_name
        FROM teacher_subjects ts
        JOIN users u ON u.user_id = ts.user_id
        WHERE ts.school_id=?
          AND u.deleted_at IS NULL
          AND (u.status IS NULL OR u.status='active')
    ";

    $conds = "";
    $types = "i";
    $params = [$school_id];

    // class/stream constraints (NULL treated as global)
    $conds .= " AND (ts.class_id IS NULL OR ts.class_id=?) ";
    $types .= "i";
    $params[] = $class_id;

    if (!empty($stream_id)) {
        $conds .= " AND (ts.stream_id IS NULL OR ts.stream_id=?) ";
        $types .= "i";
        $params[] = (int)$stream_id;
    }

    if (!empty($acad_year)) {
        $conds .= " AND (ts.academic_year IS NULL OR ts.academic_year=?) ";
        $types .= "i";
        $params[] = (int)$acad_year;
    }

    $sql = $base . $conds . " ORDER BY ts.subject_id ASC, full_name ASC, username ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $map = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $sid = (int)$row['subject_id'];
        if (!isset($map[$sid])) $map[$sid] = [];
        $map[$sid][] = [
            'id' => (string)$row['user_id'],
            'name' => tt_teacher_label_from_row($row),
        ];
    }

    $stmt->close();
    return $map;
}

/**
 * Load global times from time_slots:
 * returns {p1,p2,b1,p3,p4,b2,p5,p6,p7,l1,p8,p9,p10}
 */
function tt_load_global_times() {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return null;

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return null;

    tt_ensure_time_slots($tset_id);

    $stmt = $conn->prepare("SELECT slot_number, start_time, end_time FROM time_slots WHERE timetable_setting_id=? ORDER BY slot_number ASC");
    if (!$stmt) return null;

    $stmt->bind_param("i", $tset_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $slotToKey = [
        1=>'p1', 2=>'p2', 3=>'b1', 4=>'p3', 5=>'p4', 6=>'b2',
        7=>'p5', 8=>'p6', 9=>'p7', 10=>'l1', 11=>'p8', 12=>'p9', 13=>'p10'
    ];

    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $sn = (int)$row['slot_number'];
        $key = $slotToKey[$sn] ?? null;
        if (!$key) continue;

        $st = substr((string)$row['start_time'], 0, 5);
        $en = substr((string)$row['end_time'], 0, 5);
        $out[$key] = ($st && $en) ? ($st . " - " . $en) : "";
    }

    $stmt->close();
    return $out;
}

/**
 * Save global times into time_slots (parse strings, leave unparseable unchanged)
 */
function tt_save_global_times($times_json) {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return false;

    $times = json_decode((string)$times_json, true);
    if (!is_array($times)) return false;

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return false;

    tt_ensure_time_slots($tset_id);

    $keyToSlot = [
        'p1'=>1, 'p2'=>2, 'b1'=>3, 'p3'=>4, 'p4'=>5, 'b2'=>6,
        'p5'=>7, 'p6'=>8, 'p7'=>9, 'l1'=>10, 'p8'=>11, 'p9'=>12, 'p10'=>13
    ];
    $meta = tt_slot_number_meta();

    foreach ($keyToSlot as $key => $slot_number) {
        $val = $times[$key] ?? '';
        $range = tt_parse_time_range($val);
        if (!$range) continue;

        [$start, $end] = $range;
        $dur = tt_minutes_diff($start, $end);
        if (!$dur) continue;

        $is_break = (int)$meta[$slot_number][0];
        $is_teach = (int)$meta[$slot_number][1];
        $break_name = $meta[$slot_number][2];

        $stmt = $conn->prepare("
            UPDATE time_slots
            SET start_time=?, end_time=?, duration_minutes=?, is_break=?, is_teaching_slot=?, break_name=?
            WHERE timetable_setting_id=? AND slot_number=?
        ");
        if (!$stmt) continue;

        $stmt->bind_param("ssiiisii", $start, $end, $dur, $is_break, $is_teach, $break_name, $tset_id, $slot_number);
        $stmt->execute();
        $stmt->close();
    }

    // update timetable_settings start/end using p1 start and p10 end if parseable
    $p1 = tt_parse_time_range($times['p1'] ?? '');
    $p10 = tt_parse_time_range($times['p10'] ?? '');
    if ($p1 && $p10) {
        $start = $p1[0];
        $end   = $p10[1];

        $stmt2 = $conn->prepare("UPDATE timetable_settings SET start_time=?, end_time=?, total_slots_per_day=13, periods_per_day=10 WHERE timetable_setting_id=?");
        if ($stmt2) {
            $stmt2->bind_param("ssi", $start, $end, $tset_id);
            $stmt2->execute();
            $stmt2->close();
        }
    }

    return true;
}

/**
 * Get streams for a given class (scoped to school)
 */
function tt_get_streams_by_class(int $class_id): array {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $class_id <= 0 || $school_id <= 0) return [];

    $sql = "
        SELECT stream_id, stream_name
        FROM streams
        WHERE class_id = ?
          AND school_id = ?
        ORDER BY stream_name ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $class_id, $school_id);
    $stmt->execute();

    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}


/**
 * Validate that a stream belongs to a class
 */
function tt_stream_belongs_to_class($stream_id, $class_id) {
    $conn = tt_db();
    $school_id = tt_school_id();

    if (!$conn || $stream_id <= 0 || $class_id <= 0) return false;

    $sql = "
        SELECT 1
        FROM streams
        WHERE stream_id = ?
          AND class_id = ?
          AND school_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $stream_id, $class_id, $school_id);
    $stmt->execute();

    return (bool)$stmt->get_result()->num_rows;
}

function tt_load_timetable_for_stream($stream_id) {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return null;

    $stream_id = (int)$stream_id;
    if ($stream_id <= 0) return null;

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return null;

    tt_ensure_time_slots($tset_id);

    $dayMap = tt_days_map();
    $colToSlot = tt_col_to_slot_number();

    $data = [
        'meta' => [
            'department' => '',
            'classTeacher' => '',
            'term' => '',
            'form' => '',
            'footerCodes' => '',
            'footerBrand' => ''
        ],
        'days' => []
    ];

    // Meta.form should show Class + Stream label
    $stmtM = $conn->prepare("
        SELECT c.form_name, st.stream_name
        FROM streams st
        JOIN classes c ON c.class_id = st.class_id
        WHERE st.stream_id=? AND st.school_id=?
        LIMIT 1
    ");
    if ($stmtM) {
        $stmtM->bind_param("ii", $stream_id, $school_id);
        $stmtM->execute();
        $rm = $stmtM->get_result();
        if ($rm && ($m = $rm->fetch_assoc())) {
            $form = trim((string)($m['form_name'] ?? ''));
            $sname = trim((string)($m['stream_name'] ?? ''));
            $data['meta']['form'] = trim($form . ($sname !== '' ? " {$sname}" : ''));
        }
        $stmtM->close();
    }

    // invert slot_number -> col key
    $slotToCol = [];
    foreach ($colToSlot as $col => $sn) $slotToCol[(int)$sn] = $col;

    $stmt = $conn->prepare("
        SELECT
            t.day_of_week,
            ts.slot_number,
            t.subject_id,
            t.teacher_id,
            s.subject_initial AS subject_initial,
            s.name AS subject_name,
            u.initials AS teacher_initials,
            u.username AS teacher_username,
            CONCAT(TRIM(u.first_name),' ',TRIM(u.other_names)) AS teacher_fullname
        FROM timetables t
        JOIN time_slots ts ON ts.time_slot_id = t.time_slot_id
        LEFT JOIN subjects s ON s.subject_id = t.subject_id
        LEFT JOIN users u ON u.user_id = t.teacher_id
        WHERE t.school_id=? AND t.stream_id=? AND t.timetable_setting_id=?
    ");
    if (!$stmt) return $data;

    $stmt->bind_param("iii", $school_id, $stream_id, $tset_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $day_full = (string)$row['day_of_week'];
        $slot_number = (int)$row['slot_number'];

        $col = $slotToCol[$slot_number] ?? null;
        if (!$col) continue;

        // full day -> Mo/Tu...
        $day_key = null;
        foreach ($dayMap as $k => $full) {
            if ($full === $day_full) { $day_key = $k; break; }
        }
        if (!$day_key) continue;

        $teacher = trim((string)($row['teacher_initials'] ?? ''));
        if ($teacher === '') $teacher = trim((string)($row['teacher_fullname'] ?? ''));
        if ($teacher === '') $teacher = trim((string)($row['teacher_username'] ?? ''));

        $subjInitial = trim((string)($row['subject_initial'] ?? ''));
        $subjName = trim((string)($row['subject_name'] ?? ''));
        $subjectDisp = $subjInitial !== '' ? $subjInitial : $subjName;

        if (!isset($data['days'][$day_key])) $data['days'][$day_key] = [];
        $data['days'][$day_key][$col] = [
            'subject' => $subjectDisp,
            'teacher' => $teacher,
            'subject_id' => (int)($row['subject_id'] ?? 0),
            'teacher_id' => (int)($row['teacher_id'] ?? 0),
        ];
    }

    $stmt->close();
    return $data;
}


function tt_clear_timetable_for_stream($stream_id) {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return false;

    $stream_id = (int)$stream_id;
    if ($stream_id <= 0) return false;

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return false;

    $stmt = $conn->prepare("DELETE FROM timetables WHERE school_id=? AND stream_id=? AND timetable_setting_id=?");
    if (!$stmt) return false;

    $stmt->bind_param("iii", $school_id, $stream_id, $tset_id);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool)$ok;
}


function tt_get_teacher_subject_map_for_stream(int $stream_id): array {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $stream_id <= 0 || $school_id <= 0) return [];
    $sql = "
        SELECT
            ts.subject_id,
            u.user_id AS teacher_id,
            TRIM(
                COALESCE(
                    NULLIF(CONCAT(TRIM(u.first_name),' ',TRIM(u.other_names)), ''),
                    NULLIF(u.username, ''),
                    NULLIF(u.initials, ''),
                    CAST(u.user_id AS CHAR)
                )
            ) AS teacher_name
        FROM teacher_subjects ts
        JOIN users u ON u.user_id = ts.user_id
        WHERE ts.school_id = ?
        AND u.deleted_at IS NULL
        AND (u.status IS NULL OR u.status = 'active')
        ORDER BY ts.subject_id ASC, teacher_name ASC
    ";


    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $school_id);
    $stmt->execute();

    $res = $stmt->get_result();
    if (!$res) return [];

    $map = [];
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['subject_id'];
        if (!isset($map[$sid])) $map[$sid] = [];
        $map[$sid][] = [
            'id'   => (int)$row['teacher_id'],
            'name' => $row['teacher_name']
        ];
    }

    return $map;
}

function tt_save_timetable_for_stream($stream_id, $payload_json) {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return false;

    $stream_id = (int)$stream_id;
    if ($stream_id <= 0) return false;

    $payload = json_decode((string)$payload_json, true);
    if (!is_array($payload)) return false;

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return false;

    tt_ensure_time_slots($tset_id);

    // slot_number -> time_slot_id
    $slotMap = [];
    $stmtSlots = $conn->prepare("SELECT time_slot_id, slot_number FROM time_slots WHERE timetable_setting_id=?");
    if ($stmtSlots) {
        $stmtSlots->bind_param("i", $tset_id);
        $stmtSlots->execute();
        $rSlots = $stmtSlots->get_result();
        while ($rSlots && ($rw = $rSlots->fetch_assoc())) {
            $slotMap[(int)$rw['slot_number']] = (int)$rw['time_slot_id'];
        }
        $stmtSlots->close();
    }

    $dayMap = tt_days_map();
    $colToSlot = tt_col_to_slot_number();

    // clear existing for THIS stream
    $stmtDel = $conn->prepare("DELETE FROM timetables WHERE school_id=? AND stream_id=? AND timetable_setting_id=?");
    if ($stmtDel) {
        $stmtDel->bind_param("iii", $school_id, $stream_id, $tset_id);
        $stmtDel->execute();
        $stmtDel->close();
    }

    $days = $payload['days'] ?? [];
    if (!is_array($days)) $days = [];

    $stmtIns = $conn->prepare("
        INSERT INTO timetables
        (timetable_setting_id, school_id, stream_id, day_of_week, time_slot_id, subject_id, teacher_id, classroom_id, notes)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmtIns) return false;

    foreach ($days as $dayKey => $cols) {
        if (!isset($dayMap[$dayKey])) continue;
        $dayFull = $dayMap[$dayKey];
        if (!is_array($cols)) continue;

        foreach ($cols as $colKey => $cell) {
            if (!isset($colToSlot[$colKey])) continue;

            $slot_number = (int)$colToSlot[$colKey];
            $time_slot_id = $slotMap[$slot_number] ?? null;
            if (!$time_slot_id) continue;

            // subject_id must exist
            $subject_id = (int)(is_array($cell) ? ($cell['subject_id'] ?? 0) : 0);
            if ($subject_id <= 0) continue;

            // teacher is auto-selected in UI already; keep teacher_id from dataset
            $teacher_id = (int)(is_array($cell) ? ($cell['teacher_id'] ?? 0) : 0);

            $classroom_id = null;
            $notes = null;

            $stmtIns->bind_param(
                "iiisiiiis",
                $tset_id,
                $school_id,
                $stream_id,
                $dayFull,
                $time_slot_id,
                $subject_id,
                $teacher_id,
                $classroom_id,
                $notes
            );

            $stmtIns->execute();
        }
    }

    $stmtIns->close();
    return true;
}

