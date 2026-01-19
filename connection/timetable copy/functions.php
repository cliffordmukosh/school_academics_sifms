<?php
// timetable/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$school_id = $_SESSION['school_id'];
$action = $_POST['action'] ?? '';
header('Content-Type: application/json');

switch ($action) {
    case 'get_timetable_settings':
        $setting_id = (int)($_POST['setting_id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM timetable_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        echo json_encode(['status' => $data ? 'success' : 'error', 'data' => $data]);
        $stmt->close();
        break;

    case 'save_timetable_settings':
        $setting_id = (int)$_POST['setting_id'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $lesson_duration_minutes = (int)$_POST['lesson_duration_minutes'];
        $periods_per_day = (int)$_POST['periods_per_day'];
        $total_slots_per_day = (int)$_POST['total_slots_per_day'];
        $days_per_week = (int)$_POST['days_per_week'];
        $compulsory_lessons_per_week = (int)$_POST['compulsory_lessons_per_week'];
        $elective_lessons_per_week = (int)$_POST['elective_lessons_per_week'];
        $short_break = (int)($_POST['short_break_duration_minutes'] ?? 10);
        $long_break = (int)($_POST['long_break_duration_minutes'] ?? 30);
        $lunch_break = (int)($_POST['lunch_break_duration_minutes'] ?? 60);

        $exists = $conn->query("SELECT 1 FROM timetable_settings WHERE setting_id = $setting_id AND school_id = $school_id")->num_rows > 0;

        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE timetable_settings SET
                    start_time = ?, end_time = ?, lesson_duration_minutes = ?,
                    periods_per_day = ?, total_slots_per_day = ?, days_per_week = ?,
                    compulsory_lessons_per_week = ?, elective_lessons_per_week = ?,
                    short_break_duration_minutes = ?, long_break_duration_minutes = ?, lunch_break_duration_minutes = ?
                WHERE setting_id = ? AND school_id = ?
            ");
            $stmt->bind_param(
                "ssiiiiiiiiiii",
                $start_time,
                $end_time,
                $lesson_duration_minutes,
                $periods_per_day,
                $total_slots_per_day,
                $days_per_week,
                $compulsory_lessons_per_week,
                $elective_lessons_per_week,
                $short_break,
                $long_break,
                $lunch_break,
                $setting_id,
                $school_id
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO timetable_settings (
                    setting_id, school_id, start_time, end_time, lesson_duration_minutes,
                    periods_per_day, total_slots_per_day, days_per_week,
                    compulsory_lessons_per_week, elective_lessons_per_week,
                    short_break_duration_minutes, long_break_duration_minutes, lunch_break_duration_minutes
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param(
                "iissiiiiiiiii",
                $setting_id,
                $school_id,
                $start_time,
                $end_time,
                $lesson_duration_minutes,
                $periods_per_day,
                $total_slots_per_day,
                $days_per_week,
                $compulsory_lessons_per_week,
                $elective_lessons_per_week,
                $short_break,
                $long_break,
                $lunch_break
            );
        }

        $success = $stmt->execute();
        echo json_encode([
            'status' => $success ? 'success' : 'error',
            'message' => $success ? 'Settings saved' : $conn->error
        ]);
        $stmt->close();
        break;

    case 'get_time_slots':
        $setting_id = (int)$_POST['setting_id'];
        $stmt = $conn->prepare("
            SELECT ts.*
            FROM time_slots ts
            JOIN timetable_settings tts ON ts.timetable_setting_id = tts.timetable_setting_id
            WHERE tts.setting_id = ? AND tts.school_id = ?
            ORDER BY ts.slot_number
        ");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
        $stmt->close();
        break;

    case 'auto_generate_slots':
        $setting_id = (int)$_POST['setting_id'];
        $stmt = $conn->prepare("SELECT * FROM timetable_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$settings) {
            echo json_encode(['status' => 'error', 'message' => 'No timetable settings found']);
            exit;
        }

        $tts_id = $settings['timetable_setting_id'];

        $target_teaching = (int)$settings['periods_per_day'];
        $target_total   = (int)$settings['total_slots_per_day'];
        $target_breaks  = max(0, $target_total - $target_teaching);

        if ($target_teaching < 4 || $target_teaching > 12 || $target_total < $target_teaching) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid periods_per_day or total_slots_per_day']);
            exit;
        }

        $lesson_min = $settings['lesson_duration_minutes'];
        $short_min  = $settings['short_break_duration_minutes'] ?: 10;
        $long_min   = $settings['long_break_duration_minutes']  ?: 30;
        $lunch_min  = $settings['lunch_break_duration_minutes'] ?: 60;

        $current = new DateTime($settings['start_time']);
        $target_end = new DateTime($settings['end_time']);

        $slots = [];
        $slot_num = 1;
        $teaching_placed = 0;
        $breaks_placed = 0;

        // Heuristic: short break every ~3-4 lessons
        $lessons_per_short_interval = max(3, floor($target_teaching / max(1, $target_breaks)));

        while ($current < $target_end && $teaching_placed < $target_teaching) {
            $make_break = false;
            $break_type = 'short';

            // Regular short breaks
            if ($teaching_placed > 0 && $teaching_placed % $lessons_per_short_interval === 0) {
                if ($breaks_placed < $target_breaks) {
                    $make_break = true;
                    $breaks_placed++;
                }
            }

            // Long break mid-morning
            if ($teaching_placed === floor($target_teaching / 3) && $breaks_placed < $target_breaks) {
                $make_break = true;
                $break_type = 'long';
                $breaks_placed++;
            }

            // Lunch mid-day
            if ($teaching_placed === floor($target_teaching / 2) && $breaks_placed < $target_breaks) {
                $make_break = true;
                $break_type = 'lunch';
                $breaks_placed++;
            }

            $duration = $lesson_min;
            $is_break = false;
            $break_name = '';

            if ($make_break) {
                $is_break = true;
                if ($break_type === 'long') {
                    $duration = $long_min;
                    $break_name = 'Long Break';
                } elseif ($break_type === 'lunch') {
                    $duration = $lunch_min;
                    $break_name = 'Lunch Break';
                } else {
                    $duration = $short_min;
                    $break_name = 'Short Break';
                }
            }

            $end = clone $current;
            $end->add(new DateInterval("PT{$duration}M"));

            if ($end > $target_end) break;

            $slots[] = [
                'slot_number'     => $slot_num++,
                'start_time'      => $current->format('H:i'),
                'end_time'        => $end->format('H:i'),
                'duration_minutes' => $duration,
                'is_break'        => $is_break,
                'break_name'      => $break_name,
                'is_teaching_slot' => !$is_break
            ];

            if (!$is_break) $teaching_placed++;

            $current = $end;
        }

        // Insert into DB
        $conn->query("DELETE FROM time_slots WHERE timetable_setting_id = $tts_id");

        $insert = $conn->prepare("
            INSERT INTO time_slots 
            (timetable_setting_id, slot_number, start_time, end_time, duration_minutes, is_break, break_name, is_teaching_slot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($slots as $s) {
            $insert->bind_param(
                "iissiiis",
                $tts_id,
                $s['slot_number'],
                $s['start_time'],
                $s['end_time'],
                $s['duration_minutes'],
                $s['is_break'],
                $s['break_name'],
                $s['is_teaching_slot']
            );
            $insert->execute();
        }
        $insert->close();

        echo json_encode([
            'status' => 'success',
            'data'   => $slots,
            'summary' => [
                'teaching_slots' => $teaching_placed,
                'break_slots'    => count($slots) - $teaching_placed,
                'total_slots'    => count($slots)
            ]
        ]);
        break;

    case 'save_time_slots':
        // (same as before - no change needed)
        $setting_id = (int)$_POST['setting_id'];
        $slots = json_decode($_POST['slots'] ?? '[]', true);

        $stmt = $conn->prepare("SELECT timetable_setting_id FROM timetable_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $tts_id = $stmt->get_result()->fetch_assoc()['timetable_setting_id'] ?? 0;
        $stmt->close();

        if (!$tts_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid term']);
            exit;
        }

        $conn->query("DELETE FROM time_slots WHERE timetable_setting_id = $tts_id");

        $stmt = $conn->prepare("
            INSERT INTO time_slots 
            (timetable_setting_id, slot_number, start_time, end_time, duration_minutes, is_break, break_name, is_teaching_slot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($slots as $s) {
            $slot_num = (int)$s['slot_number'];
            $start = $s['start_time'];
            $end = $s['end_time'];
            $dur = (int)$s['duration_minutes'];
            $is_break = $s['is_break'] ? 1 : 0;
            $name = $s['break_name'] ?? '';
            $teaching = $s['is_teaching_slot'] ? 1 : 0;

            $stmt->bind_param("iissiiis", $tts_id, $slot_num, $start, $end, $dur, $is_break, $name, $teaching);
            $stmt->execute();
        }
        $stmt->close();

        echo json_encode(['status' => 'success', 'message' => 'Slots saved successfully']);
        break;

    case 'generate_timetable':
        // (same as before - no change needed for now)
        $setting_id = (int)$_POST['setting_id'];
        $stream_id = (int)$_POST['stream_id'];

        $stmt = $conn->prepare("SELECT * FROM timetable_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$settings) {
            echo json_encode(['status' => 'error', 'message' => 'No settings']);
            exit;
        }

        $tts_id = $settings['timetable_setting_id'];

        $stmt = $conn->prepare("SELECT * FROM time_slots WHERE timetable_setting_id = ? AND is_teaching_slot = 1 ORDER BY slot_number");
        $stmt->bind_param("i", $tts_id);
        $stmt->execute();
        $teaching_slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($teaching_slots)) {
            echo json_encode(['status' => 'error', 'message' => 'No teaching slots']);
            exit;
        }

        $stmt = $conn->prepare("SELECT class_id FROM streams WHERE stream_id = ?");
        $stmt->bind_param("i", $stream_id);
        $stmt->execute();
        $class_id = $stmt->get_result()->fetch_assoc()['class_id'] ?? 0;
        $stmt->close();

        if (!$class_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid stream']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT s.subject_id, s.subject_initial, cs.type
            FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.subject_id
            WHERE cs.class_id = ?
        ");
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($subjects)) {
            echo json_encode(['status' => 'error', 'message' => 'No subjects']);
            exit;
        }

        $allocations = [];
        $total_needed = 0;

        foreach ($subjects as $sub) {
            $lessons = 0;
            if ($sub['type'] === 'compulsory') $lessons = (int)$settings['compulsory_lessons_per_week'];
            elseif ($sub['type'] === 'elective') $lessons = (int)$settings['elective_lessons_per_week'];

            if ($lessons > 0) {
                $allocations[$sub['subject_id']] = [
                    'initial' => $sub['subject_initial'] ?: substr($sub['name'] ?? 'SUB', 0, 3),
                    'lessons' => $lessons
                ];
                $total_needed += $lessons;
            }
        }

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $available = count($teaching_slots) * count($days);

        if ($total_needed > $available) {
            echo json_encode(['status' => 'error', 'message' => "Not enough slots. Need $total_needed, have $available"]);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM timetables WHERE timetable_setting_id = ? AND stream_id = ?");
        $stmt->bind_param("ii", $tts_id, $stream_id);
        $stmt->execute();
        $stmt->close();

        $positions = [];
        foreach ($days as $day) {
            foreach ($teaching_slots as $slot) {
                $positions[] = ['day' => $day, 'slot_id' => $slot['time_slot_id']];
            }
        }
        shuffle($positions);

        $stmt = $conn->prepare("
            INSERT INTO timetables 
            (timetable_setting_id, school_id, stream_id, day_of_week, time_slot_id, subject_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($allocations as $sub_id => $info) {
            for ($i = 0; $i < $info['lessons']; $i++) {
                if (empty($positions)) break;
                $pos = array_shift($positions);
                $stmt->bind_param("iiisii", $tts_id, $school_id, $stream_id, $pos['day'], $pos['slot_id'], $sub_id);
                $stmt->execute();
            }
        }
        $stmt->close();

        echo json_encode(['status' => 'success', 'message' => 'Timetable generated']);
        break;

    case 'get_timetable':
        $setting_id = (int)$_POST['setting_id'];
        $stream_id = (int)$_POST['stream_id'];

        $stmt = $conn->prepare("SELECT timetable_setting_id FROM timetable_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $tts_id = $stmt->get_result()->fetch_assoc()['timetable_setting_id'] ?? 0;
        $stmt->close();

        if (!$tts_id) {
            echo json_encode(['status' => 'error', 'message' => 'Settings not found']);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM time_slots WHERE timetable_setting_id = ? ORDER BY slot_number");
        $stmt->bind_param("i", $tts_id);
        $stmt->execute();
        $slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT t.day_of_week, t.time_slot_id, s.subject_initial
            FROM timetables t JOIN subjects s ON t.subject_id = s.subject_id
            WHERE t.timetable_setting_id = ? AND t.stream_id = ?
        ");
        $stmt->bind_param("ii", $tts_id, $stream_id);
        $stmt->execute();
        $assigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($assigns as $a) {
            $map[$a['time_slot_id']][$a['day_of_week']] = $a['subject_initial'];
        }

        $data = [];
        foreach ($slots as $s) {
            $data[] = [
                'slot_number' => $s['slot_number'],
                'start_time'  => $s['start_time'],
                'end_time'    => $s['end_time'],
                'is_break'    => (bool)$s['is_break'],
                'break_name'  => $s['break_name'] ?? '',
                'monday'      => $map[$s['time_slot_id']]['Monday'] ?? '',
                'tuesday'     => $map[$s['time_slot_id']]['Tuesday'] ?? '',
                'wednesday'   => $map[$s['time_slot_id']]['Wednesday'] ?? '',
                'thursday'    => $map[$s['time_slot_id']]['Thursday'] ?? '',
                'friday'      => $map[$s['time_slot_id']]['Friday'] ?? ''
            ];
        }

        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
