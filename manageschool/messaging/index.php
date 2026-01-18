<?php
// messaging/index.php
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];

// Fetch classes
$stmt = $conn->prepare("
    SELECT class_id, form_name
    FROM classes
    WHERE school_id = ?
    ORDER BY form_name
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch years from exams (extracted from created_at)
$stmt = $conn->prepare("
    SELECT DISTINCT YEAR(created_at) AS year
    FROM exams
    WHERE school_id = ? AND status = 'closed'
    AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
    ORDER BY year DESC
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$years = $stmt->get_result()->fetch_all(MYSQLI_NUM);
$years = array_column($years, 0);
$stmt->close();

// Fetch school details for message header
$stmt = $conn->prepare("SELECT name FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch SMS Balance (assuming a table like 'sms_credits' with columns school_id and balance; adjust if needed)
$sms_balance = 0.00; // Default
// $stmt = $conn->prepare("SELECT balance FROM sms_credits WHERE school_id = ? LIMIT 1");
// if ($stmt) {
//     $stmt->bind_param("i", $school_id);
//     $stmt->execute();
//     $result = $stmt->get_result()->fetch_assoc();
//     $sms_balance = $result['balance'] ?? 0.00;
//     $stmt->close();
// }

// Fetch Message Stats from messages_sent table
$total_sent = 0;
$total_failed = 0;
$total_queued = 0;
$total_messages = 0;

$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS total_sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS total_failed,
        SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS total_queued,
        COUNT(*) AS total_messages
    FROM messages_sent 
    WHERE school_id = ?
");
if ($stmt) {
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $total_sent = (int)($stats['total_sent'] ?? 0);
    $total_failed = (int)($stats['total_failed'] ?? 0);
    $total_queued = (int)($stats['total_queued'] ?? 0);
    $total_messages = (int)($stats['total_messages'] ?? 0);
    $stmt->close();
}
?>

<style>
    .table-container {
        max-width: 100%;
        overflow-x: auto;
    }
    th, td {
        min-width: 80px;
        text-align: center;
        padding: 4px;
        border: 1px solid #000;
    }
    .table th {
        background: #e9f2ff !important;
        color: #0d47a1;
        text-align: center;
    }
    .table th, .table td {
        padding: 2px 3px !important;
        vertical-align: middle;
        font-size: 9px;
    }
    .btn-custom {
        border-radius: 20px;
        padding: 4px 10px;
        font-size: 11px;
        margin-left: 5px;
    }
    .compose-step {
        display: none;
    }
    .compose-step.active {
        display: block;
    }
    .stats-badge {
        margin-left: 10px;
        font-size: 0.85em;
    }
    .stats-section {
        margin-left: auto;
    }
</style>

<div class="content">
  <div class="container-fluid">
    <div class="container py-4">
      <div class="d-flex align-items-center mb-4">
        <h3 class="d-flex align-items-center mb-0">
            <i class="bi bi-envelope me-2"></i> Messaging Center
        </h3>
        <div class="stats-section ms-auto">
            <span class="badge bg-primary stats-badge">Balance: <?php echo number_format($sms_balance, 2); ?> SMS</span>
            <span class="badge bg-success stats-badge">Sent: <?php echo $total_sent; ?></span>
            <span class="badge bg-danger stats-badge">Failed: <?php echo $total_failed; ?></span>
            <span class="badge bg-warning stats-badge">Queued: <?php echo $total_queued; ?></span>
            <span class="badge bg-secondary stats-badge">Total: <?php echo $total_messages; ?></span>
        </div>
      </div>

      <!-- Messaging Menu -->
      <div class="row g-4 mb-4">
        <!-- Compose Message -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100 text-center">
                <div class="card-body d-flex flex-column justify-content-center">
                    <i class="bi bi-pencil-square display-5 text-primary"></i>
                    <h5 class="mt-3">Compose Message</h5>
                    <p class="text-muted">Create and send messages to parents or teachers.</p>
                    <button class="btn btn-primary mt-auto" id="composeBtn">
                        <i class="bi bi-pencil me-2"></i> Compose
                    </button>
                </div>
            </div>
        </div>
        <!-- View Messages -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100 text-center">
                <div class="card-body d-flex flex-column justify-content-center">
                    <i class="bi bi-envelope-open display-5 text-primary"></i>
                    <h5 class="mt-3">View Messages</h5>
                    <p class="text-muted">View sent messages and their status.</p>
                    <button class="btn btn-primary mt-auto" id="viewMessagesBtn">
                        <i class="bi bi-eye me-2"></i> View Messages
                    </button>
                </div>
            </div>
        </div>
        <!-- Buy SMS -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100 text-center">
                <div class="card-body d-flex flex-column justify-content-center">
                    <i class="bi bi-credit-card display-5 text-primary"></i>
                    <h5 class="mt-3">Buy SMS</h5>
                    <p class="text-muted">Purchase SMS credits for messaging.</p>
                    <button class="btn btn-primary mt-auto" id="buySmsBtn">
                        <i class="bi bi-cart me-2"></i> Buy SMS
                    </button>
                </div>
            </div>
        </div>
      </div>

      <!-- Compose Message Modal -->
      <div class="modal fade" id="composeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center">
                        <i class="bi bi-pencil-square me-2"></i> Compose Message
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="composeForm">
                        <!-- Step 1: Select Recipient Type -->
                        <div class="compose-step active" id="step-recipient">
                            <h6>Select Recipient Type</h6>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_type" id="recipient_parents" value="parents">
                                <label class="form-check-label" for="recipient_parents">Parents</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_type" id="recipient_teachers" value="teachers">
                                <label class="form-check-label" for="recipient_teachers">Teachers</label>
                            </div>
                            <button type="button" class="btn btn-primary mt-3" id="next-to-message-type">Next</button>
                        </div>

                        <!-- Step 2: Select Message Type (for Parents only) -->
                        <div class="compose-step" id="step-message-type">
                            <h6>Select Message Type</h6>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="message_type" id="type_exam_results" value="exam_results">
                                <label class="form-check-label" for="type_exam_results">Exam Results</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="message_type" id="type_term_results" value="term_results">
                                <label class="form-check-label" for="type_term_results">Term Results (Transcript)</label>
                            </div>
                            <button type="button" class="btn btn-secondary mt-3 me-2" id="back-to-recipient">Back</button>
                            <button type="button" class="btn btn-primary mt-3" id="next-to-details">Next</button>
                        </div>

                        <!-- Step 3: Select Details (Year, Form, Exam/Term) (for Parents only) -->
                        <div class="compose-step" id="step-details">
                            <h6>Select Details</h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">Year</label>
                                    <select class="form-select" id="compose_year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php foreach ($years as $year): ?>
                                            <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Form</label>
                                    <select class="form-select" id="compose_class_id" name="class_id" required>
                                        <option value="">Select Form</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['class_id']; ?>">
                                                <?php echo htmlspecialchars($class['form_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4" id="compose_term_container" style="display: none;">
                                    <label class="form-label">Term</label>
                                    <select class="form-select" id="compose_term" name="term" disabled>
                                        <option value="">Select Term</option>
                                    </select>
                                </div>
                                <div class="col-md-4" id="compose_exam_container" style="display: none;">
                                    <label class="form-label">Exam</label>
                                    <select class="form-select" id="compose_exam_id" name="exam_id" disabled>
                                        <option value="">Select Exam</option>
                                    </select>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-3 me-2" id="back-to-message-type">Back</button>
                            <button type="button" class="btn btn-primary mt-3" id="next-to-recipients">Next</button>
                        </div>

                        <!-- Step 4: Select Specific Recipients (for Parents only) -->
                        <div class="compose-step" id="step-recipients">
                            <h6>Select Recipients</h6>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_scope" id="scope_entire_class" value="entire_class">
                                <label class="form-check-label" for="scope_entire_class">Entire Class</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_scope" id="scope_specific_stream" value="specific_stream">
                                <label class="form-check-label" for="scope_specific_stream">Specific Stream</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recipient_scope" id="scope_specific_student" value="specific_student">
                                <label class="form-check-label" for="scope_specific_student">Specific Student</label>
                            </div>
                            <div id="stream_select_container" style="display: none;" class="mt-3">
                                <label class="form-label">Stream</label>
                                <select class="form-select" id="compose_stream_id" name="stream_id" disabled>
                                    <option value="">Select Stream</option>
                                </select>
                            </div>
                            <div id="student_select_container" style="display: none;" class="mt-3">
                                <label class="form-label">Student</label>
                                <select class="form-select" id="compose_student_id" name="student_id" disabled>
                                    <option value="">Select Student</option>
                                </select>
                            </div>
                            <button type="button" class="btn btn-secondary mt-3 me-2" id="back-to-details">Back</button>
                            <button type="button" class="btn btn-primary mt-3" id="next-to-preview">Preview</button>
                        </div>

                        <!-- Step 5: Message Preview (for Parents only) -->
                        <div class="compose-step" id="step-preview">
                            <h6>Message Preview</h6>
                            <div id="preview_content" class="border p-3 mb-3" style="max-height: 300px; overflow-y: auto;"></div>
                            <button type="button" class="btn btn-secondary mt-3 me-2" id="back-to-recipients">Back</button>
                            <button type="button" class="btn btn-success mt-3" id="send_message">Send</button>
                        </div>

                        <!-- Step for Teachers: Compose Custom Message -->
                        <div class="compose-step" id="step-teacher-message">
                            <h6>Compose Message to Teachers</h6>
                            <div class="mb-3">
                                <label class="form-label">Subject</label>
                                <input type="text" class="form-control" id="teacher_message_subject" name="teacher_message_subject" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" id="teacher_message_content" name="teacher_message_content" rows="5" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Select Recipients</label>
                                <div class="form-check">
                                    <input class="form-check-input teacher-recipient" type="checkbox" name="teacher_recipient_type" id="all_class_teachers" value="all_class_teachers">
                                    <label class="form-check-label" for="all_class_teachers">All Class Teachers</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input teacher-recipient" type="checkbox" name="teacher_recipient_type" id="all_class_supervisors" value="all_class_supervisors">
                                    <label class="form-check-label" for="all_class_supervisors">All Class Supervisors</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input teacher-recipient" type="checkbox" name="teacher_recipient_type" id="all_teachers" value="all_teachers">
                                    <label class="form-check-label" for="all_teachers">All Teachers</label>
                                </div>
                                <div class="mt-3">
                                    <label class="form-label">Or Select Specific Teacher</label>
                                    <select class="form-select" id="specific_teacher_id" name="specific_teacher_id">
                                        <option value="">Select Teacher</option>
                                        <!-- Teachers will be loaded via AJAX -->
                                    </select>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-3 me-2" id="back-to-recipient-from-teacher">Back</button>
                            <button type="button" class="btn btn-success mt-3" id="send_teacher_message">Send</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
      </div>

      <!-- View Messages Modal -->
      <div class="modal fade" id="viewMessagesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center">
                        <i class="bi bi-envelope-open me-2"></i> View Messages
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="messages_list" class="table-container">
                        <!-- Messages will be loaded here via AJAX -->
                    </div>
                </div>
            </div>
        </div>
      </div>

      <!-- View Message Detail Modal -->
      <div class="modal fade" id="viewMessageDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Message Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <pre id="message_content" style="white-space: pre-wrap;"></pre>
                </div>
            </div>
        </div>
      </div>

      <!-- Buy SMS Modal -->
      <div class="modal fade" id="buySmsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center">
                        <i class="bi bi-cart me-2"></i> Buy SMS Credits
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="buySmsForm">
                        <div class="mb-3">
                            <label class="form-label">Number of Credits</label>
                            <input type="number" class="form-control" id="sms_credits" name="credits" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">Select Payment Method</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="card">Credit Card</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-primary" id="buy_sms">Purchase</button>
                    </form>
                </div>
            </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
    // Open Compose Modal
    $('#composeBtn').on('click', function () {
        $('#composeModal').modal('show');
    });

    // Open View Messages Modal and Load Messages
    $('#viewMessagesBtn').on('click', function () {
        $('#viewMessagesModal').modal('show');
        loadMessages();
    });

    function loadMessages() {
        $.post('messaging/functions.php', { action: 'get_messages' }, function (response) {
            if (response.status === 'success') {
                let html = '<table class="table table-bordered"><thead><tr><th>ID</th><th>Recipient</th><th>Type</th><th>Sent At</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
                response.messages.forEach(msg => {
                    html += `<tr><td>${msg.id}</td><td>${msg.recipient}</td><td>${msg.type}</td><td>${msg.sent_at}</td><td>${msg.status}</td><td><button class="btn btn-sm btn-info view-message" data-message="${msg.content.replace(/"/g, '&quot;')}">View</button></td></tr>`;
                });
                html += '</tbody></table>';
                $('#messages_list').html(html);
            } else {
                $('#messages_list').html('<p>No messages found.</p>');
            }
        }, 'json');
    }

    // View Full Message
    $(document).on('click', '.view-message', function() {
        const content = $(this).data('message');
        $('#message_content').text(content);
        $('#viewMessageDetailModal').modal('show');
    });

    // Open Buy SMS Modal
    $('#buySmsBtn').on('click', function () {
        $('#buySmsModal').modal('show');
    });

    // Handle Buy SMS (simulated)
    $('#buy_sms').on('click', function () {
        alert('SMS purchase simulated. Credits added.');
        $('#buySmsModal').modal('hide');
    });

    // Compose Form Navigation
    $('#next-to-message-type').on('click', function () {
        if ($('input[name="recipient_type"]:checked').length) {
            const recipientType = $('input[name="recipient_type"]:checked').val();
            $('#step-recipient').removeClass('active');
            if (recipientType === 'teachers') {
                // For teachers, skip to custom message step and load teachers
                $('#step-teacher-message').addClass('active');
                // Reset teacher selection fields
                $('.teacher-recipient').prop('checked', false);
                $('#specific_teacher_id').val('');
                // Load all teachers
                $.post('messaging/functions.php', { action: 'get_all_teachers' }, function (response) {
                    if (response.status === 'success') {
                        $('#specific_teacher_id').html('<option value="">Select Teacher</option>');
                        response.teachers.forEach(teacher => {
                            $('#specific_teacher_id').append(`<option value="${teacher.teacher_id}">${teacher.full_name}</option>`);
                        });
                    }
                }, 'json');
            } else {
                // For parents, proceed to message type
                $('#step-message-type').addClass('active');
            }
        } else {
            alert('Please select recipient type.');
        }
    });

    $('#back-to-recipient').on('click', function () {
        $('#step-message-type').removeClass('active');
        $('#step-recipient').addClass('active');
    });

    $('#back-to-recipient-from-teacher').on('click', function () {
        $('#step-teacher-message').removeClass('active');
        $('#step-recipient').addClass('active');
    });

    $('#next-to-details').on('click', function () {
        if ($('input[name="message_type"]:checked').length) {
            const messageType = $('input[name="message_type"]:checked').val();
            if (messageType === 'exam_results') {
                $('#compose_term_container').hide();
                $('#compose_exam_container').show();
            } else if (messageType === 'term_results') {
                $('#compose_exam_container').hide();
                $('#compose_term_container').show();
            }
            $('#step-message-type').removeClass('active');
            $('#step-details').addClass('active');
        } else {
            alert('Please select message type.');
        }
    });

    $('#back-to-message-type').on('click', function () {
        $('#step-details').removeClass('active');
        $('#step-message-type').addClass('active');
    });

    $('#next-to-recipients').on('click', function () {
        if ($('#compose_year').val() && $('#compose_class_id').val()) {
            const messageType = $('input[name="message_type"]:checked').val();
            if ((messageType === 'exam_results' && $('#compose_exam_id').val()) || (messageType === 'term_results' && $('#compose_term').val())) {
                $('#step-details').removeClass('active');
                $('#step-recipients').addClass('active');
            } else {
                alert('Please select all required details.');
            }
        } else {
            alert('Please select year and form.');
        }
    });

    $('#back-to-details').on('click', function () {
        $('#step-recipients').removeClass('active');
        $('#step-details').addClass('active');
    });

    $('#next-to-preview').on('click', function () {
        if ($('input[name="recipient_scope"]:checked').length) {
            const scope = $('input[name="recipient_scope"]:checked').val();
            if ((scope === 'specific_stream' && $('#compose_stream_id').val()) || (scope === 'specific_student' && $('#compose_student_id').val()) || scope === 'entire_class') {
                loadPreview();
            } else {
                alert('Please select the required recipient details.');
            }
        } else {
            alert('Please select recipient scope.');
        }
    });

    $('#back-to-recipients').on('click', function () {
        $('#step-preview').removeClass('active');
        $('#step-recipients').addClass('active');
    });

    // Handle Year and Class Change for Details
    $('#compose_year, #compose_class_id').on('change', function () {
        const year = $('#compose_year').val();
        const classId = $('#compose_class_id').val();
        const messageType = $('input[name="message_type"]:checked').val();
        if (year && classId && messageType) {
            if (messageType === 'term_results') {
                // Load terms
                $.post('messaging/functions.php', { action: 'get_terms_for_class_and_year', class_id: classId, year: year }, function (response) {
                    if (response.status === 'success') {
                        $('#compose_term').html('<option value="">Select Term</option>');
                        response.terms.forEach(term => {
                            $('#compose_term').append(`<option value="${term}">${term}</option>`);
                        });
                        $('#compose_term').prop('disabled', false);
                    }
                }, 'json');
            } else if (messageType === 'exam_results') {
                // Load exams
                $.post('messaging/functions.php', { action: 'get_exams_for_class_and_year', class_id: classId, year: year }, function (response) {
                    if (response.status === 'success') {
                        $('#compose_exam_id').html('<option value="">Select Exam</option>');
                        response.exams.forEach(exam => {
                            $('#compose_exam_id').append(`<option value="${exam.exam_id}">${exam.exam_name}</option>`);
                        });
                        $('#compose_exam_id').prop('disabled', false);
                    }
                }, 'json');
            }
        }
    });

    // Handle Recipient Scope Change
    $('input[name="recipient_scope"]').on('change', function () {
        const scope = $(this).val();
        const classId = $('#compose_class_id').val();
        $('#stream_select_container').hide();
        $('#student_select_container').hide();
        if (scope === 'specific_stream') {
            $('#stream_select_container').show();
            $.post('messaging/functions.php', { action: 'get_streams', class_id: classId }, function (response) {
                if (response.status === 'success') {
                    $('#compose_stream_id').html('<option value="">Select Stream</option>');
                    response.streams.forEach(stream => {
                        $('#compose_stream_id').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                    });
                    $('#compose_stream_id').prop('disabled', false);
                }
            }, 'json');
        } else if (scope === 'specific_student') {
            $('#student_select_container').show();
            $.post('messaging/functions.php', { action: 'get_students_for_class', class_id: classId }, function (response) {
                if (response.status === 'success') {
                    $('#compose_student_id').html('<option value="">Select Student</option>');
                    response.students.forEach(student => {
                        $('#compose_student_id').append(`<option value="${student.student_id}">${student.full_name} (${student.admission_no})</option>`);
                    });
                    $('#compose_student_id').prop('disabled', false);
                }
            }, 'json');
        }
    });

    // Ensure mutual exclusivity between checkboxes and specific teacher dropdown
    $('.teacher-recipient').on('change', function () {
        if ($(this).is(':checked')) {
            $('.teacher-recipient').not(this).prop('checked', false);
            $('#specific_teacher_id').val('').prop('disabled', true);
        } else {
            $('#specific_teacher_id').prop('disabled', false);
        }
    });

    $('#specific_teacher_id').on('change', function () {
        if ($(this).val()) {
            $('.teacher-recipient').prop('checked', false).prop('disabled', true);
        } else {
            $('.teacher-recipient').prop('disabled', false);
        }
    });

    // Load Preview (for Parents)
    function loadPreview() {
        const formData = $('#composeForm').serialize();
        $.post('messaging/functions.php', { action: 'get_preview', ...Object.fromEntries(new URLSearchParams(formData)) }, function (response) {
            if (response.status === 'success') {
                let html = '';
                response.previews.forEach(preview => {
                    html += `<div class="mb-3">
                        <strong>To: ${preview.recipient_note} (${preview.phone})</strong>
                        <p>${preview.message.replace(/\n/g, '<br>')}</p>
                    </div>`;
                });
                $('#preview_content').html(html);
                $('#step-recipients').removeClass('active');
                $('#step-preview').addClass('active');
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json');
    }

    // Send Message (for Parents)
    $('#send_message').on('click', function () {
        const formData = $('#composeForm').serialize();
        $.post('messaging/functions.php', { action: 'send_message', ...Object.fromEntries(new URLSearchParams(formData)) }, function (response) {
            if (response.status === 'success') {
                alert('Messages sent successfully!');
                $('#composeModal').modal('hide');
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json');
    });

    // Send Teacher Message
    $('#send_teacher_message').on('click', function () {
        const subject = $('#teacher_message_subject').val();
        const content = $('#teacher_message_content').val();
        const recipientType = $('input[name="teacher_recipient_type"]:checked').val();
        const specificTeacherId = $('#specific_teacher_id').val();

        if (!subject || !content) {
            alert('Please fill in subject and message.');
            return;
        }
        if (!recipientType && !specificTeacherId) {
            alert('Please select either a recipient type or a specific teacher.');
            return;
        }

        const postData = {
            action: 'send_teacher_message',
            subject: subject,
            message_content: content
        };

        if (specificTeacherId) {
            postData.teacher_recipient_type = 'specific_teacher';
            postData.specific_teacher_id = specificTeacherId;
        } else {
            postData.teacher_recipient_type = recipientType;
        }

        $.post('messaging/functions.php', postData, function (response) {
            if (response.status === 'success') {
                alert('Messages sent successfully!');
                $('#composeModal').modal('hide');
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json');
    });
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>