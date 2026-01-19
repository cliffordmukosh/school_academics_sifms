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

// Fetch streams for preview dropdowns
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
            <small class="text-muted ms-3">(<?php echo htmlspecialchars($term_display); ?>)</small>
        </h3>

        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100 text-center">
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
                <div class="card shadow-sm border-0 h-100 text-center">
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
                <div class="card shadow-sm border-0 h-100 text-center">
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
                <div class="card shadow-sm border-0 h-100 text-center">
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
        <div class="card shadow-sm border-0">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-table me-2"></i> Quick Preview</h5>
                <select class="form-select form-select-sm w-auto" id="quickPreviewStream">
                    <option value="">Select Stream</option>
                    <?php foreach ($streams as $stream): ?>
                        <option value="<?php echo $stream['stream_id']; ?>"><?php echo htmlspecialchars($stream['form_name'] . ' ' . $stream['stream_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm mb-0 text-center" id="quickTimetablePreview">
                        <thead class="table-dark">
                            <tr>
                                <th>Day / Period</th>
                                <th>Monday</th>
                                <th>Tuesday</th>
                                <th>Wednesday</th>
                                <th>Thursday</th>
                                <th>Friday</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6" class="text-muted py-4">Select a stream to preview.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Timetable Settings Modal -->
<div class="modal fade" id="timetableSettingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="settingsModalLabel"><i class="bi bi-gear me-2"></i> Timetable Settings for <?php echo htmlspecialchars($term_display); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="timetableSettingsForm">
                <div class="modal-body">
                    <input type="hidden" name="setting_id" value="<?php echo $setting_id; ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <h6 class="mb-3">Daily Structure</h6>
                        </div>
                        <div class="col-md-6">
                            <label for="start_time" class="form-label">Start Time</label>
                            <input type="time" class="form-control" id="start_time" name="start_time" value="08:00" required>
                        </div>
                        <div class="col-md-6">
                            <label for="end_time" class="form-label">End Time</label>
                            <input type="time" class="form-control" id="end_time" name="end_time" value="16:00" required>
                        </div>
                        <div class="col-md-4">
                            <label for="lesson_duration_minutes" class="form-label">Lesson Duration (min)</label>
                            <input type="number" class="form-control" id="lesson_duration_minutes" name="lesson_duration_minutes" value="40" min="30" max="60" required>
                        </div>
                        <div class="col-md-4">
                            <label for="periods_per_day" class="form-label">Teaching Periods per Day</label>
                            <input type="number" class="form-control" id="periods_per_day" name="periods_per_day" value="8" min="5" max="10" required>
                        </div>
                        <div class="col-md-4">
                            <label for="total_slots_per_day" class="form-label">Total Slots per Day (incl. breaks)</label>
                            <input type="number" class="form-control" id="total_slots_per_day" name="total_slots_per_day" value="13" min="8" max="15" required>
                        </div>
                        <div class="col-md-6">
                            <label for="days_per_week" class="form-label">Days per Week</label>
                            <input type="number" class="form-control" id="days_per_week" name="days_per_week" value="5" min="5" max="6" required>
                        </div>
                        <div class="col-md-6">
                            <label for="total_target_lessons_per_week" class="form-label">Target Lessons per Week</label>
                            <input type="number" class="form-control" id="total_target_lessons_per_week" name="total_target_lessons_per_week" value="40" min="30" max="50" required>
                        </div>

                        <!-- Lessons per Week -->
                        <div class="col-12 mt-3">
                            <h6 class="mb-3">Lessons per Week Defaults</h6>
                        </div>
                        <div class="col-md-4">
                            <label for="compulsory_lessons_per_week" class="form-label">Compulsory</label>
                            <input type="number" class="form-control" id="compulsory_lessons_per_week" name="compulsory_lessons_per_week" value="6" min="4" max="8">
                        </div>
                        <div class="col-md-4">
                            <label for="elective_lessons_per_week" class="form-label">Elective</label>
                            <input type="number" class="form-control" id="elective_lessons_per_week" name="elective_lessons_per_week" value="6" min="4" max="8">
                        </div>

                        <!-- Break Defaults -->
                        <div class="col-12 mt-3">
                            <h6 class="mb-3">Break Defaults (min)</h6>
                        </div>
                        <div class="col-md-4">
                            <label for="short_break_duration_minutes" class="form-label">Short Break</label>
                            <input type="number" class="form-control" id="short_break_duration_minutes" name="short_break_duration_minutes" value="10" min="5" max="15">
                        </div>
                        <div class="col-md-4">
                            <label for="long_break_duration_minutes" class="form-label">Long Break</label>
                            <input type="number" class="form-control" id="long_break_duration_minutes" name="long_break_duration_minutes" value="30" min="20" max="45">
                        </div>
                        <div class="col-md-4">
                            <label for="lunch_break_duration_minutes" class="form-label">Lunch Break</label>
                            <input type="number" class="form-control" id="lunch_break_duration_minutes" name="lunch_break_duration_minutes" value="60" min="30" max="90">
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
<div class="modal fade" id="manageSlotsModal" tabindex="-1" aria-labelledby="slotsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="slotsModalLabel"><i class="bi bi-clock me-2"></i> Manage Periods & Breaks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Auto-generate slots first, then select a row to edit break details. End time is auto-calculated.</p>
                <button type="button" class="btn btn-success mb-3" id="autoGenerateSlots"><i class="bi bi-magic me-2"></i> Auto-Generate Slots</button>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="slotsTable">
                        <thead class="table-dark">
                            <tr>
                                <th>Slot #</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Duration (min)</th>
                                <th>Break?</th>
                                <th>Break Name</th>
                                <th>Teaching Slot?</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Filled by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveSlots"><i class="bi bi-save me-2"></i> Save Slots</button>
            </div>
        </div>
    </div>
</div>

<!-- Generate Timetable Modal -->
<div class="modal fade" id="generateTimetableModal" tabindex="-1" aria-labelledby="generateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="generateModalLabel"><i class="bi bi-magic me-2"></i> Generate Timetable</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Select a stream to generate timetable for. It will assign subjects based on settings.</p>
                <select class="form-select" id="generateStreamSelect">
                    <option value="">Select Stream</option>
                    <?php foreach ($streams as $stream): ?>
                        <option value="<?php echo $stream['stream_id']; ?>"><?php echo htmlspecialchars($stream['form_name'] . ' ' . $stream['stream_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info text-white" id="confirmGenerate"><i class="bi bi-play-circle me-2"></i> Generate</button>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewTimetableModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalLabel"><i class="bi bi-eye me-2"></i> Timetable Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <select class="form-select mb-3 w-auto" id="previewStreamSelect">
                    <option value="">Select Stream</option>
                    <?php foreach ($streams as $stream): ?>
                        <option value="<?php echo $stream['stream_id']; ?>"><?php echo htmlspecialchars($stream['form_name'] . ' ' . $stream['stream_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="text-center mb-3">
                    <h4 id="previewTitle" class="fw-bold"></h4>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm text-center" id="fullTimetablePreview">
                        <thead class="table-dark"></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Global vars
    const settingId = '<?php echo $setting_id; ?>';
    const termDisplay = '<?php echo addslashes($term_display); ?>'; // ← FIXED: pass PHP var to JS
    let slotsData = [];

    // Load settings on modal open
    $('#timetableSettingsModal').on('show.bs.modal', function() {
        if (!settingId) return alert('No term found.');
        $.post('timetable/functions.php', {
            action: 'get_timetable_settings',
            setting_id: settingId
        }, function(res) {
            if (res.status === 'success' && res.data) {
                Object.keys(res.data).forEach(key => $(`#${key}`).val(res.data[key]));
            } else {
                alert('No settings found. Defaults loaded.');
            }
        }, 'json');
    });

    // Save settings
    $('#timetableSettingsForm').on('submit', function(e) {
        e.preventDefault();
        let formData = $(this).serialize() + '&action=save_timetable_settings';
        $.post('timetable/functions.php', formData, function(res) {
            alert(res.message);
            if (res.status === 'success') $('#timetableSettingsModal').modal('hide');
        }, 'json');
    });

    // Load slots on modal open
    $('#manageSlotsModal').on('show.bs.modal', function() {
        if (!settingId) return alert('No term found.');
        $.post('timetable/functions.php', {
            action: 'get_time_slots',
            timetable_setting_id: settingId
        }, function(res) {
            if (res.status === 'success') {
                slotsData = res.data;
                renderSlotsTable();
            } else {
                alert('No slots found. Auto-generate to start.');
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
                renderSlotsTable();
                alert('Slots auto-generated based on settings.');
            } else {
                alert(res.message);
            }
        }, 'json');
    });

    // Render slots table
    function renderSlotsTable() {
        let html = '';
        slotsData.forEach((slot, index) => {
            html += `
        <tr data-index="${index}">
            <td>${slot.slot_number}</td>
            <td><input type="time" class="form-control" value="${slot.start_time}" name="start_time"></td>
            <td><input type="time" class="form-control" value="${slot.end_time}" name="end_time" readonly></td>
            <td><input type="number" class="form-control" value="${slot.duration_minutes}" name="duration_minutes" min="5" max="90"></td>
            <td><input type="checkbox" name="is_break" ${slot.is_break ? 'checked' : ''}></td>
            <td><input type="text" class="form-control" value="${slot.break_name || ''}" name="break_name" ${!slot.is_break ? 'disabled' : ''}></td>
            <td><input type="checkbox" name="is_teaching_slot" ${slot.is_teaching_slot ? 'checked' : ''} ${slot.is_break ? 'disabled' : ''}></td>
            <td>
                <button class="btn btn-sm btn-danger delete-slot"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`;
        });
        $('#slotsTable tbody').html(html);
    }

    // Dynamic updates: calculate end time on change, and chain to subsequent rows
    $('#slotsTable tbody').on('change', 'input[name="start_time"], input[name="duration_minutes"]', function() {
        let row = $(this).closest('tr');
        let index = row.data('index');
        let start = row.find('input[name="start_time"]').val();
        let dur = parseInt(row.find('input[name="duration_minutes"]').val()) || 0;

        if (start && dur) {
            let [h, m] = start.split(':').map(Number);
            let endM = m + dur;
            let endH = h + Math.floor(endM / 60);
            endM %= 60;
            let end = `${endH.toString().padStart(2, '0')}:${endM.toString().padStart(2, '0')}`;
            row.find('input[name="end_time"]').val(end);

            // Chain update to subsequent rows' start times
            let nextStart = end;
            for (let i = index + 1; i < slotsData.length; i++) {
                let nextRow = $('#slotsTable tbody tr[data-index="' + i + '"]');
                nextRow.find('input[name="start_time"]').val(nextStart);
                let nextDur = parseInt(nextRow.find('input[name="duration_minutes"]').val()) || 0;
                let [nh, nm] = nextStart.split(':').map(Number);
                let nendM = nm + nextDur;
                let nendH = nh + Math.floor(nendM / 60);
                nendM %= 60;
                let nend = `${nendH.toString().padStart(2, '0')}:${nendM.toString().padStart(2, '0')}`;
                nextRow.find('input[name="end_time"]').val(nend);
                nextStart = nend;
            }
        }
    });

    // Toggle break name enable
    $('#slotsTable tbody').on('change', 'input[name="is_break"]', function() {
        let row = $(this).closest('tr');
        row.find('input[name="break_name"]').prop('disabled', !this.checked);
        row.find('input[name="is_teaching_slot"]').prop('checked', !this.checked).prop('disabled', this.checked);
    });

    // Delete slot
    $('#slotsTable tbody').on('click', '.delete-slot', function() {
        let index = $(this).closest('tr').data('index');
        slotsData.splice(index, 1);
        renderSlotsTable();
    });

    // Save slots
    $('#saveSlots').click(function() {
        let updatedSlots = [];
        $('#slotsTable tbody tr').each(function() {
            let row = $(this);
            updatedSlots.push({
                slot_number: parseInt(row.find('td:first').text()),
                start_time: row.find('input[name="start_time"]').val(),
                end_time: row.find('input[name="end_time"]').val(),
                duration_minutes: parseInt(row.find('input[name="duration_minutes"]').val()),
                is_break: row.find('input[name="is_break"]').prop('checked'),
                break_name: row.find('input[name="break_name"]').val(),
                is_teaching_slot: row.find('input[name="is_teaching_slot"]').prop('checked')
            });
        });
        $.post('timetable/functions.php', {
            action: 'save_time_slots',
            setting_id: settingId,
            slots: JSON.stringify(updatedSlots)
        }, function(res) {
            alert(res.message);
            if (res.status === 'success') $('#manageSlotsModal').modal('hide');
        }, 'json');
    });

    // Confirm generate
    $('#confirmGenerate').click(function() {
        let streamId = $('#generateStreamSelect').val();
        if (!streamId) return alert('Select a stream.');
        $.post('timetable/functions.php', {
            action: 'generate_timetable',
            setting_id: settingId,
            stream_id: streamId
        }, function(res) {
            alert(res.message);
            if (res.status === 'success') $('#generateTimetableModal').modal('hide');
        }, 'json');
    });

    // Load quick preview on stream select
    $('#quickPreviewStream').change(function() {
        let streamId = $(this).val();
        if (!streamId) return;
        loadTimetablePreview(streamId, '#quickTimetablePreview');
    });

    // Load full preview on stream select
    $('#previewStreamSelect').change(function() {
        let streamId = $(this).val();
        if (!streamId) return;
        loadTimetablePreview(streamId, '#fullTimetablePreview');
    });

    // ────────────────────────────────────────────────
    // FIXED & IMPROVED loadTimetablePreview function (FULL VERSION)
    // ────────────────────────────────────────────────
    function loadTimetablePreview(streamId, tableId) {
        if (!streamId) return;

        console.log('Loading timetable for stream:', streamId, 'table:', tableId);

        // Show loading state
        $(tableId + ' tbody').html('<tr><td colspan="20" class="text-center py-4">Loading timetable...</td></tr>');

        $.post('timetable/functions.php', {
            action: 'get_timetable',
            setting_id: settingId,
            stream_id: streamId
        }, function(res) {
            console.log('AJAX success - raw response:', res);

            if (res.status === 'success' && res.data && res.data.length > 0) {
                let data = res.data;

                // Title – use the JS variable we passed from PHP
                let streamName = $('#' + (tableId.includes('quick') ? 'quickPreviewStream' : 'previewStreamSelect') + ' option:selected').text().trim();
                let title = `TERM ${termDisplay.split(' ')[1] || ''} ${termDisplay.split(' ')[0] || ''} – ${streamName.toUpperCase()}`;
                if (!tableId.includes('quick')) $('#previewTitle').html(title);

                // Build headers – one column per slot + day column
                let periodRow = '<tr><th>Day / Period</th>';
                let timeRow = '<tr><th>Time</th>';
                let periodNum = 1;

                data.forEach(slot => {
                    if (slot.is_break) {
                        let label = (slot.break_name && slot.break_name.trim() !== '' && slot.break_name.trim() !== '0') ?
                            slot.break_name.trim().toUpperCase() :
                            'BREAK';
                        periodRow += `<th>${label}</th>`;
                    } else {
                        periodRow += `<th>${periodNum++}</th>`;
                    }
                    timeRow += `<th>${slot.start_time} – ${slot.end_time}</th>`;
                });

                periodRow += '</tr>';
                timeRow += '</tr>';

                $(tableId + ' thead').html(periodRow + timeRow);

                // Build body – exactly same number of columns
                let daysShort = ['Mo', 'Tu', 'We', 'Th', 'Fr'];
                let daysFull = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                let bodyHtml = '';

                daysFull.forEach((day, idx) => {
                    bodyHtml += `<tr><td class="fw-bold text-nowrap">${daysShort[idx]}</td>`;

                    data.forEach(slot => {
                        let content = '';
                        if (!slot.is_break) {
                            content = slot[day.toLowerCase()] || '';
                        } else {
                            content = '<small class="text-muted">—</small>';
                        }
                        bodyHtml += `<td>${content}</td>`;
                    });

                    bodyHtml += '</tr>';
                });

                $(tableId + ' tbody').html(bodyHtml);
            } else {
                console.log('No valid data received:', res);
                $(tableId + ' thead').html('<tr><th colspan="20">No data</th></tr>');
                $(tableId + ' tbody').html('<tr><td colspan="20" class="text-center py-4">No timetable generated for this stream yet.</td></tr>');
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX failed:', textStatus, errorThrown);
            console.log('Status code:', jqXHR.status);
            console.log('Response text:', jqXHR.responseText);
            $(tableId + ' tbody').html('<tr><td colspan="20" class="text-center py-4 text-danger">Failed to load: ' + textStatus + '</td></tr>');
        });
    }
</script>

<?php include __DIR__ . '/../footer.php'; ?>