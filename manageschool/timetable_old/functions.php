<?php
// timetable/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$school_id = $_SESSION['school_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$setting_id = (int)($_POST['setting_id'] ?? $_GET['setting_id'] ?? 0);
$stream_id  = (int)($_POST['stream_id']  ?? $_GET['stream_id']  ?? 0);
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
            'message' => $success ? 'Settings saved successfully' : $conn->error
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
            echo json_encode(['status' => 'error', 'message' => 'No timetable settings found for this term']);
            exit;
        }

        $tts_id = $settings['timetable_setting_id'];

        // Clear previous slots
        $conn->query("DELETE FROM time_slots WHERE timetable_setting_id = $tts_id");

        $start = new DateTime($settings['start_time']);
        $target_end = new DateTime($settings['end_time']);
        $lesson_min = $settings['lesson_duration_minutes'];
        $short_min = $settings['short_break_duration_minutes'];
        $long_min  = $settings['long_break_duration_minutes'];
        $lunch_min = $settings['lunch_break_duration_minutes'];

        $current = clone $start;
        $slot_num = 1;
        $teaching_count = 0;
        $slots = [];

        // Loop until we reach the required lessons or exceed end time
        while ($teaching_count < $settings['periods_per_day'] && $current < $target_end) {
            $start_str = $current->format('H:i');

            // Add a lesson slot
            $duration = $lesson_min;
            $is_break = false;
            $break_name = '';
            $is_teaching = true;

            $end = clone $current;
            $end->add(new DateInterval("PT{$duration}M"));
            if ($end > $target_end) {
                break;
            }
            $end_str = $end->format('H:i');

            // Insert lesson slot
            $stmt = $conn->prepare("
                INSERT INTO time_slots 
                (timetable_setting_id, slot_number, start_time, end_time, duration_minutes, is_break, break_name, is_teaching_slot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iissiiis", $tts_id, $slot_num, $start_str, $end_str, $duration, $is_break, $break_name, $is_teaching);
            $stmt->execute();
            $stmt->close();

            $slots[] = [
                'slot_number' => $slot_num,
                'start_time' => $start_str,
                'end_time' => $end_str,
                'duration_minutes' => $duration,
                'is_break' => $is_break,
                'break_name' => $break_name,
                'is_teaching_slot' => $is_teaching
            ];
            $slot_num++;
            $current = $end;
            $teaching_count++;

            // Now check if we need to add a break after this lesson
            $break_duration = 0;
            $break_name = '';

            if ($teaching_count == 3) {
                $break_duration = $short_min;
                $break_name = 'Short Break';
            } elseif ($teaching_count == 4) {
                $break_duration = $long_min;
                $break_name = 'Long Break';
            } elseif ($teaching_count == 8) {
                $break_duration = $lunch_min;
                $break_name = 'Lunch Break';
            }

            if ($break_duration > 0) {
                $start_str = $current->format('H:i');
                $end = clone $current;
                $end->add(new DateInterval("PT{$break_duration}M"));
                if ($end > $target_end) {
                    break;
                }
                $end_str = $end->format('H:i');

                $is_break = true;
                $is_teaching = false;

                // Insert break slot
                $stmt = $conn->prepare("
                    INSERT INTO time_slots 
                    (timetable_setting_id, slot_number, start_time, end_time, duration_minutes, is_break, break_name, is_teaching_slot)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iissiiis", $tts_id, $slot_num, $start_str, $end_str, $break_duration, $is_break, $break_name, $is_teaching);
                $stmt->execute();
                $stmt->close();

                $slots[] = [
                    'slot_number' => $slot_num,
                    'start_time' => $start_str,
                    'end_time' => $end_str,
                    'duration_minutes' => $break_duration,
                    'is_break' => $is_break,
                    'break_name' => $break_name,
                    'is_teaching_slot' => $is_teaching
                ];
                $slot_num++;
                $current = $end;
            }
        }

        // Debug info
        $debug = [
            'teaching_lessons_per_day' => $teaching_count,
            'total_slots_created'      => count($slots),
            'breaks_created'           => count(array_filter($slots, function ($s) {
                return $s['is_break'];
            })),
            'expected_teaching'        => $settings['periods_per_day'],
            'expected_total_slots'     => $settings['total_slots_per_day']
        ];

        echo json_encode([
            'status' => 'success',
            'data'   => $slots,
            'debug'  => $debug
        ]);
        break;

    case 'save_time_slots':
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
            $start    = $s['start_time'];
            $end      = $s['end_time'];
            $dur      = (int)$s['duration_minutes'];
            $is_break = $s['is_break'] ? 1 : 0;
            $name     = $s['break_name'] ?? '';
            $teaching = $s['is_teaching_slot'] ? 1 : 0;

            $stmt->bind_param("iissiiis", $tts_id, $slot_num, $start, $end, $dur, $is_break, $name, $teaching);
            $stmt->execute();
        }
        $stmt->close();

        echo json_encode(['status' => 'success', 'message' => 'Slots saved successfully']);
        break;

    case 'generate_timetable':
        $setting_id = (int)$_POST['setting_id'];
        $stream_id  = (int)$_POST['stream_id'];

        // Get settings
        $stmt = $conn->prepare("SELECT * FROM timetable_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$settings) {
            echo json_encode(['status' => 'error', 'message' => 'No settings found']);
            exit;
        }

        $tts_id = $settings['timetable_setting_id'];

        // Fetch only teaching slots
        $stmt = $conn->prepare("
            SELECT * FROM time_slots 
            WHERE timetable_setting_id = ? 
              AND is_teaching_slot = 1 
            ORDER BY slot_number
        ");
        $stmt->bind_param("i", $tts_id);
        $stmt->execute();
        $teaching_slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($teaching_slots)) {
            echo json_encode(['status' => 'error', 'message' => 'No teaching slots defined for this term']);
            exit;
        }

        // Get class_id from stream
        $stmt = $conn->prepare("SELECT class_id FROM streams WHERE stream_id = ?");
        $stmt->bind_param("i", $stream_id);
        $stmt->execute();
        $class_id = $stmt->get_result()->fetch_assoc()['class_id'] ?? 0;
        $stmt->close();

        if (!$class_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid stream']);
            exit;
        }

        // Get subjects + their type
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
            echo json_encode(['status' => 'error', 'message' => 'No subjects assigned to this class']);
            exit;
        }

        // Calculate required lessons per subject
        $allocations = [];
        $total_needed = 0;

        foreach ($subjects as $sub) {
            $lessons_per_week = ($sub['type'] === 'compulsory')
                ? $settings['compulsory_lessons_per_week']
                : $settings['elective_lessons_per_week'];

            if ($lessons_per_week > 0) {
                $allocations[$sub['subject_id']] = [
                    'initial' => $sub['subject_initial'] ?: substr($sub['name'] ?? 'SUB', 0, 3),
                    'lessons' => (int)$lessons_per_week
                ];
                $total_needed += $lessons_per_week;
            }
        }

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $available_slots = count($teaching_slots) * count($days);

        if ($total_needed > $available_slots) {
            echo json_encode([
                'status' => 'warning',
                'message' => "Not enough teaching slots. Needed: $total_needed, Available: $available_slots"
            ]);
            // Proceed anyway, but some subjects may not get full allocation
        }

        // Clear old timetable for this stream + term
        $stmt = $conn->prepare("DELETE FROM timetables WHERE timetable_setting_id = ? AND stream_id = ?");
        $stmt->bind_param("ii", $tts_id, $stream_id);
        $stmt->execute();
        $stmt->close();

        // Create list of available teaching positions
        $positions = [];
        foreach ($days as $day) {
            foreach ($teaching_slots as $slot) {
                $positions[] = [
                    'day'     => $day,
                    'slot_id' => $slot['time_slot_id']
                ];
            }
        }

        shuffle($positions); // Random distribution

        $stmt = $conn->prepare("
            INSERT INTO timetables 
            (timetable_setting_id, school_id, stream_id, day_of_week, time_slot_id, subject_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($allocations as $sub_id => $info) {
            for ($i = 0; $i < $info['lessons']; $i++) {
                if (empty($positions)) break 2; // No more slots

                $pos = array_shift($positions);

                $stmt->bind_param(
                    "iiisii",
                    $tts_id,
                    $school_id,
                    $stream_id,
                    $pos['day'],
                    $pos['slot_id'],
                    $sub_id
                );
                $stmt->execute();
            }
        }
        $stmt->close();

        echo json_encode([
            'status'  => 'success',
            'message' => 'Timetable generated successfully',
            'debug'   => [
                'teaching_slots_per_day' => count($teaching_slots),
                'total_available_slots'  => $available_slots,
                'total_needed'           => $total_needed
            ]
        ]);
        break;

    case 'get_timetable':
        $setting_id = (int)($_POST['setting_id'] ?? $_GET['setting_id'] ?? 0);
        $stream_id  = (int)($_POST['stream_id']  ?? $_GET['stream_id']  ?? 0);

        if ($setting_id <= 0 || $stream_id <= 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing or invalid setting_id or stream_id',
                'received' => ['setting_id' => $setting_id, 'stream_id' => $stream_id]
            ]);
            break;
        }

        $stmt = $conn->prepare("SELECT timetable_setting_id FROM timetable_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $tts_id = $stmt->get_result()->fetch_assoc()['timetable_setting_id'] ?? 0;
        $stmt->close();

        if (!$tts_id) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No timetable_setting_id found',
                'debug' => ['setting_id' => $setting_id, 'school_id' => $school_id]
            ]);
            break;
        }

        $stmt = $conn->prepare("SELECT * FROM time_slots WHERE timetable_setting_id = ? ORDER BY slot_number");
        $stmt->bind_param("i", $tts_id);
        $stmt->execute();
        $slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("
        SELECT t.day_of_week, t.time_slot_id, s.subject_initial
        FROM timetables t
        JOIN subjects s ON t.subject_id = s.subject_id
        WHERE t.timetable_setting_id = ? AND t.stream_id = ?
    ");
        $stmt->bind_param("ii", $tts_id, $stream_id);
        $stmt->execute();
        $assigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($assigns as $a) {
            $map[$a['time_slot_id']][$a['day_of_week']] = $a['subject_initial'] ?? '';
        }

        $data = [];
        foreach ($slots as $s) {
            $data[] = [
                'slot_number' => (int)$s['slot_number'],
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

        echo json_encode([
            'status' => 'success',
            'data'   => $data,
            'debug'  => [
                'slots_found' => count($slots),
                'assignments_found' => count($assigns),
                'tts_id' => $tts_id
            ]
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
