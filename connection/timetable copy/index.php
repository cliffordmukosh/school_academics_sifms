<?php
// timetable/index.php
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];

// Fetch current/active term
$stmt = $conn->prepare("
    SELECT setting_id, term_name, academic_year 
    FROM school_settings 
    WHERE school_id = ? 
    ORDER BY academic_year DESC, FIELD(term_name, 'Term 3', 'Term 2', 'Term 1') DESC 
    LIMIT 1
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$current_term = $stmt->get_result()->fetch_assoc();
$stmt->close();

$setting_id = $current_term['setting_id'] ?? null;
$term_display = $current_term ? "{$current_term['term_name']} {$current_term['academic_year']}" : "No term selected";

// Fetch streams
$stmt = $conn->prepare("
    SELECT s.stream_id, s.stream_name, c.form_name 
    FROM streams s 
    JOIN classes c ON s.class_id = c.class_id 
    WHERE s.school_id = ? 
    ORDER BY c.form_name, s.stream_name
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="content">
    <div class="container-fluid">
        <h3 class="mb-4">
            <i class="bi bi-calendar-week me-2"></i> Timetable Management
            <small class="text-muted ms-3">(<?= htmlspecialchars($term_display) ?>)</small>
        </h3>

        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="card shadow-sm h-100 text-center">
                    <div class="card-body d-flex flex-column">
                        <i class="bi bi-gear-fill display-4 text-primary mb-3"></i>
                        <h5>Timetable Settings</h5>
                        <p class="text-muted small">Times, lessons, breaks</p>
                        <button class="btn btn-primary mt-auto" data-bs-toggle="modal" data-bs-target="#timetableSettingsModal">
                            <i class="bi bi-pencil-square me-2"></i> Configure
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm h-100 text-center">
                    <div class="card-body d-flex flex-column">
                        <i class="bi bi-clock-history display-4 text-success mb-3"></i>
                        <h5>Periods & Breaks</h5>
                        <p class="text-muted small">Define daily structure</p>
                        <button class="btn btn-success mt-auto" data-bs-toggle="modal" data-bs-target="#manageSlotsModal">
                            <i class="bi bi-list-check me-2"></i> Manage Slots
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm h-100 text-center">
                    <div class="card-body d-flex flex-column">
                        <i class="bi bi-magic display-4 text-info mb-3"></i>
                        <h5>Generate Timetable</h5>
                        <p class="text-muted small">Auto-assign subjects</p>
                        <button class="btn btn-info text-white mt-auto" data-bs-toggle="modal" data-bs-target="#generateTimetableModal">
                            <i class="bi bi-play-circle me-2"></i> Generate
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm h-100 text-center">
                    <div class="card-body d-flex flex-column">
                        <i class="bi bi-eye display-4 text-warning mb-3"></i>
                        <h5>View Timetable</h5>
                        <p class="text-muted small">Preview per stream</p>
                        <button class="btn btn-warning mt-auto" data-bs-toggle="modal" data-bs-target="#previewTimetableModal">
                            <i class="bi bi-eye me-2"></i> Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Preview -->
        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-table me-2"></i> Quick Preview</h5>
                <select class="form-select form-select-sm w-auto" id="quickPreviewStream">
                    <option value="">Select Stream</option>
                    <?php foreach ($streams as $stream): ?>
                        <option value="<?= $stream['stream_id'] ?>">
                            <?= htmlspecialchars($stream['form_name'] . ' ' . $stream['stream_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0 text-center" id="quickTimetablePreview">
                        <thead class="table-dark">
                            <tr>
                                <th>Period / Time</th>
                                <th>Monday</th>
                                <th>Tuesday</th>
                                <th>Wednesday</th>
                                <th>Thursday</th>
                                <th>Friday</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6" class="text-muted py-4">Select a stream to preview</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Timetable Settings Modal -->
<div class="modal fade" id="timetableSettingsModal" tabindex="-1" aria-labelledby="settingsModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear me-2"></i> Timetable Settings – <?= htmlspecialchars($term_display) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="timetableSettingsForm">
                <div class="modal-body">
                    <input type="hidden" name="setting_id" value="<?= $setting_id ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <h6>Daily Structure</h6>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control" name="start_time" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-control" name="end_time" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Lesson Duration (min)</label>
                            <input type="number" class="form-control" name="lesson_duration_minutes" min="30" max="60" required value="40">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Teaching Lessons / Day</label>
                            <input type="number" class="form-control" name="periods_per_day" min="4" max="12" required value="8">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Slots / Day (lessons + breaks)</label>
                            <input type="number" class="form-control" name="total_slots_per_day" min="8" max="18" required value="13">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Days per Week</label>
                            <input type="number" class="form-control" name="days_per_week" min="5" max="6" required value="5">
                        </div>

                        <div class="col-12 mt-4">
                            <h6>Lessons per Subject per Week</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Compulsory Subjects</label>
                            <input type="number" class="form-control" name="compulsory_lessons_per_week" min="4" max="10" required value="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Elective Subjects</label>
                            <input type="number" class="form-control" name="elective_lessons_per_week" min="3" max="8" required value="6">
                        </div>

                        <div class="col-12 mt-4">
                            <h6>Break Defaults (minutes)</h6>
                        </div>
                        <div class="col-md-4">
                            <label>Short Break</label>
                            <input type="number" class="form-control" name="short_break_duration_minutes" min="5" max="20" value="10">
                        </div>
                        <div class="col-md-4">
                            <label>Long Break</label>
                            <input type="number" class="form-control" name="long_break_duration_minutes" min="20" max="45" value="30">
                        </div>
                        <div class="col-md-4">
                            <label>Lunch Break</label>
                            <input type="number" class="form-control" name="lunch_break_duration_minutes" min="30" max="90" value="60">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-2"></i> Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Slots Modal -->
<div class="modal fade" id="manageSlotsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="bi bi-clock me-2"></i> Manage Periods & Breaks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">
                    Auto-generate creates ~<?= $settings['periods_per_day'] ?? 8 ?> teaching slots + breaks to reach total slots,
                    but stops before end time. You can edit freely.
                </p>
                <button type="button" class="btn btn-success mb-4" id="autoGenerateSlots">
                    <i class="bi bi-magic me-2"></i> Auto-Generate Slots
                </button>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="slotsTable">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Duration (min)</th>
                                <th>Break?</th>
                                <th>Break Name</th>
                                <th>Teaching?</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="saveSlots"><i class="bi bi-save me-2"></i> Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Generate Timetable Modal -->
<div class="modal fade" id="generateTimetableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="bi bi-magic me-2"></i> Generate Timetable</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Select stream:</p>
                <select class="form-select" id="generateStreamSelect">
                    <option value="">— Select Stream —</option>
                    <?php foreach ($streams as $s): ?>
                        <option value="<?= $s['stream_id'] ?>">
                            <?= htmlspecialchars($s['form_name'] . ' ' . $s['stream_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info text-white" id="confirmGenerate">Generate</button>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewTimetableModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="bi bi-eye me-2"></i> Timetable Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <select class="form-select mb-3 w-auto" id="previewStreamSelect">
                    <option value="">— Select Stream —</option>
                    <?php foreach ($streams as $s): ?>
                        <option value="<?= $s['stream_id'] ?>">
                            <?= htmlspecialchars($s['form_name'] . ' ' . $s['stream_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm text-center" id="fullTimetablePreview">
                        <thead class="table-dark">
                            <tr>
                                <th>Period / Time</th>
                                <th>Monday</th>
                                <th>Tuesday</th>
                                <th>Wednesday</th>
                                <th>Thursday</th>
                                <th>Friday</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6" class="py-5 text-muted">Select stream to view</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Global variables
    const settingId = '<?= $setting_id ?>';
    let slotsData = [];

    // Load settings
    $('#timetableSettingsModal').on('show.bs.modal', function() {
        if (!settingId) return alert('No term selected');
        $.post('timetable/functions.php', {
            action: 'get_timetable_settings',
            setting_id: settingId
        }, function(res) {
            if (res.status === 'success' && res.data) {
                Object.entries(res.data).forEach(([k, v]) => $(`[name="${k}"]`).val(v));
            }
        }, 'json');
    });

    // Save settings
    $('#timetableSettingsForm').on('submit', function(e) {
        e.preventDefault();
        $.post('timetable/functions.php', $(this).serialize() + '&action=save_timetable_settings', function(res) {
            alert(res.message);
            if (res.status === 'success') $('#timetableSettingsModal').modal('hide');
        }, 'json');
    });

    // Load slots
    $('#manageSlotsModal').on('show.bs.modal', function() {
        $.post('timetable/functions.php', {
            action: 'get_time_slots',
            setting_id: settingId
        }, function(res) {
            if (res.status === 'success') {
                slotsData = res.data || [];
                renderSlots();
            } else {
                alert(res.message || 'Generate slots first');
            }
        }, 'json');
    });

    // Auto-generate slots
    $('#autoGenerateSlots').click(function() {
        $.post('timetable/functions.php', {
            action: 'auto_generate_slots',
            setting_id: settingId
        }, function(res) {
            if (res.status === 'success') {
                slotsData = res.data;
                renderSlots();
                alert(`Generated ${slotsData.length} slots (${res.summary.teaching_slots} teaching + ${res.summary.break_slots} breaks)`);
            } else {
                alert(res.message);
            }
        }, 'json');
    });

    function renderSlots() {
        let html = '';
        slotsData.forEach((slot, i) => {
            html += `
        <tr data-index="${i}">
            <td>${slot.slot_number}</td>
            <td><input type="time" class="form-control" value="${slot.start_time}" name="start_time"></td>
            <td><input type="time" class="form-control" value="${slot.end_time}" name="end_time" readonly></td>
            <td><input type="number" class="form-control" value="${slot.duration_minutes}" name="duration_minutes" min="5" max="90"></td>
            <td><input type="checkbox" ${slot.is_break ? 'checked' : ''} name="is_break"></td>
            <td><input type="text" class="form-control" value="${slot.break_name || ''}" name="break_name" ${!slot.is_break ? 'disabled' : ''}></td>
            <td><input type="checkbox" ${slot.is_teaching_slot ? 'checked' : ''} name="is_teaching_slot" ${slot.is_break ? 'disabled' : ''}></td>
            <td><button class="btn btn-sm btn-danger delete-slot"><i class="bi bi-trash"></i></button></td>
        </tr>`;
        });
        $('#slotsTable tbody').html(html);
    }

    // Recalculate end time on change
    $('#slotsTable').on('input', 'input[name="start_time"], input[name="duration_minutes"]', function() {
        const row = $(this).closest('tr');
        const start = row.find('[name="start_time"]').val();
        const dur = parseInt(row.find('[name="duration_minutes"]').val()) || 0;
        if (start && dur > 0) {
            const [h, m] = start.split(':').map(Number);
            let endM = m + dur;
            const endH = h + Math.floor(endM / 60);
            endM %= 60;
            row.find('[name="end_time"]').val(`${endH.toString().padStart(2,'0')}:${endM.toString().padStart(2,'0')}`);
        }
    });

    // Toggle break name
    $('#slotsTable').on('change', '[name="is_break"]', function() {
        const row = $(this).closest('tr');
        const isBreak = this.checked;
        row.find('[name="break_name"]').prop('disabled', !isBreak);
        row.find('[name="is_teaching_slot"]').prop('checked', !isBreak).prop('disabled', isBreak);
    });

    // Delete slot
    $('#slotsTable').on('click', '.delete-slot', function() {
        const idx = $(this).closest('tr').data('index');
        slotsData.splice(idx, 1);
        renderSlots();
    });

    // Save slots
    $('#saveSlots').click(function() {
        const updated = [];
        $('#slotsTable tbody tr').each(function() {
            const r = $(this);
            updated.push({
                slot_number: parseInt(r.find('td:first').text()),
                start_time: r.find('[name="start_time"]').val(),
                end_time: r.find('[name="end_time"]').val(),
                duration_minutes: parseInt(r.find('[name="duration_minutes"]').val()),
                is_break: r.find('[name="is_break"]').is(':checked'),
                break_name: r.find('[name="break_name"]').val(),
                is_teaching_slot: r.find('[name="is_teaching_slot"]').is(':checked')
            });
        });
        $.post('timetable/functions.php', {
            action: 'save_time_slots',
            setting_id: settingId,
            slots: JSON.stringify(updated)
        }, function(res) {
            alert(res.message);
            if (res.status === 'success') $('#manageSlotsModal').modal('hide');
        }, 'json');
    });

    // Generate timetable
    $('#confirmGenerate').click(function() {
        const stream = $('#generateStreamSelect').val();
        if (!stream) return alert('Select a stream');
        $.post('timetable/functions.php', {
            action: 'generate_timetable',
            setting_id: settingId,
            stream_id: stream
        }, function(res) {
            alert(res.message);
            if (res.status === 'success') $('#generateTimetableModal').modal('hide');
        }, 'json');
    });

    // Preview
    $('#quickPreviewStream, #previewStreamSelect').change(function() {
        const streamId = $(this).val();
        if (!streamId) return;
        const target = this.id === 'quickPreviewStream' ? '#quickTimetablePreview tbody' : '#fullTimetablePreview tbody';
        loadPreview(streamId, target);
    });

    function loadPreview(streamId, tbody) {
        $.post('timetable/functions.php', {
            action: 'get_timetable',
            setting_id: settingId,
            stream_id: streamId
        }, function(res) {
            if (res.status === 'success' && res.data?.length) {
                let html = '';
                res.data.forEach(r => {
                    html += `<tr>
                    <td>${r.start_time} – ${r.end_time}${r.is_break ? ' <small>('+(r.break_name||'Break')+')</small>' : ''}</td>
                    <td>${r.monday || (r.is_break ? '—' : '')}</td>
                    <td>${r.tuesday || (r.is_break ? '—' : '')}</td>
                    <td>${r.wednesday || (r.is_break ? '—' : '')}</td>
                    <td>${r.thursday || (r.is_break ? '—' : '')}</td>
                    <td>${r.friday || (r.is_break ? '—' : '')}</td>
                </tr>`;
                });
                $(tbody).html(html);
            } else {
                $(tbody).html('<tr><td colspan="6" class="text-center py-4">No timetable yet</td></tr>');
            }
        }, 'json');
    }
</script>

<?php include __DIR__ . '/../footer.php'; ?>