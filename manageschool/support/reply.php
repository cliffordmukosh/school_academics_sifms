<?php
// support/reply.php - Unified reply page for BOTH logged-in users and support emails (no account needed for support)
require __DIR__ . '/../../connection/db.php';

// Start session only if needed (for logged-in check)
session_start();

// Allowed support emails (no account needed)
$allowed_support_emails = [
    'bmunywoki65@gmail.com',
    'cliffordmukosh@gmail.com',
    // Add more if needed
];

// Default values
$authorized = false;
$entered_email = '';
$error = '';
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['school_id']);

// ──────────────────────────────────────────────────────────────
// Authorization logic
// ──────────────────────────────────────────────────────────────

if ($is_logged_in) {
    $authorized = true;
    $entered_email = $_SESSION['email'] ?? 'Logged-in User';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['support_email'])) {
    $entered_email = trim($_POST['support_email']);

    if (in_array(strtolower($entered_email), array_map('strtolower', $allowed_support_emails))) {
        $authorized = true;
    } else {
        $error = "Sorry, that email is not authorized for support access.";
    }
}

$ticket_id = (int)($_GET['ticket_id'] ?? 0);
if ($ticket_id <= 0) {
    die("Invalid ticket ID.");
}

// Only proceed if authorized
$ticket = null;
$messages = [];

if ($authorized) {
    // Fetch ticket details
    $stmt = $conn->prepare("
        SELECT t.ticket_id, t.subject, t.status, t.priority, t.created_at, t.school_id, t.user_id,
               s.name AS school_name, s.email AS school_email, s.phone AS school_phone,
               CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS sender_name
        FROM support_tickets t
        JOIN schools s ON t.school_id = s.school_id
        JOIN users u ON t.user_id = u.user_id
        WHERE t.ticket_id = ?
    ");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ticket) {
        die("Ticket not found.");
    }

    // Access check for logged-in users
    if ($is_logged_in) {
        $is_owner = $ticket['user_id'] == $_SESSION['user_id'];
        $is_support = in_array($_SESSION['user_id'], [1, 2]) ||
            in_array($_SESSION['role_name'] ?? '', ['Admin', 'Super Admin', 'Support']);

        if (!$is_owner && !$is_support) {
            die("Access denied: You do not own this ticket.");
        }
    }

    // Fetch messages
    $stmt = $conn->prepare("
        SELECT m.message_text, m.created_at, m.is_from_admin,
               CASE 
                   WHEN m.is_from_admin = 1 THEN 'Support Team'
                   ELSE COALESCE(CONCAT(u.first_name, ' ', u.other_names), 'User')
               END AS sender_name
        FROM support_messages m
        LEFT JOIN users u ON m.sender_id = u.user_id
        WHERE m.ticket_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reply to Ticket #<?= $ticket_id ?> - SIFMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            padding: 30px;
        }

        .auth-box {
            max-width: 500px;
            margin: 0 auto;
        }

        .ticket-header {
            background: #0d6efd;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
        }

        .message-bubble {
            max-width: 80%;
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 12px;
        }

        .message-from-user {
            background: #e9ecef;
            align-self: flex-start;
        }

        .message-from-support {
            background: #198754;
            color: white;
            align-self: flex-end;
        }

        #replyFeedback {
            min-height: 50px;
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1055;
        }
    </style>
</head>

<body>

    <div class="container">

        <?php if (!$authorized): ?>
            <!-- Authorization screen (only for non-logged-in users) -->
            <div class="card shadow-lg auth-box">
                <div class="card-header bg-primary text-white text-center">
                    <h4>Support Team Reply Access</h4>
                </div>
                <div class="card-body">
                    <p class="text-center mb-4">
                        This is the public reply page for authorized support team members.<br>
                        Please enter your support email to continue.
                    </p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" id="authForm">
                        <div class="mb-3">
                            <input type="email" class="form-control form-control-lg"
                                name="support_email" placeholder="your.support@email.com"
                                value="<?= htmlspecialchars($entered_email) ?>" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-shield-lock me-2"></i> Access Reply Page
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Ticket & Reply Area -->
            <div class="card shadow-lg">
                <div class="ticket-header">
                    <h4 class="mb-1">Ticket #<?= $ticket_id ?> - <?= htmlspecialchars($ticket['subject']) ?></h4>
                    <small>
                        <?= $is_logged_in ? 'Logged in as: ' . htmlspecialchars($_SESSION['full_name'] ?? 'User') : 'Support Access: ' . htmlspecialchars($entered_email) ?>
                    </small><br>
                    <small>Status:
                        <span class="badge <?= $ticket['status'] === 'open' ? 'bg-warning' : 'bg-success' ?>">
                            <?= ucfirst($ticket['status']) ?>
                        </span>
                    </small>
                </div>

                <div class="card-body">
                    <!-- Ticket Info -->
                    <div class="mb-4 p-3 bg-light rounded">
                        <strong>School:</strong> <?= htmlspecialchars($ticket['school_name']) ?><br>
                        <strong>From:</strong> <?= htmlspecialchars($ticket['sender_name']) ?><br>
                        <strong>Contact:</strong> <?= htmlspecialchars($ticket['school_email']) ?> | <?= htmlspecialchars($ticket['school_phone']) ?>
                    </div>

                    <!-- Messages -->
                    <h5>Conversation</h5>
                    <div id="messagesContainer" class="d-flex flex-column gap-3 mb-4">
                        <?php foreach ($messages as $msg): ?>
                            <?php
                            $is_support = $msg['is_from_admin'] == 1;
                            $sender = $msg['sender_name'];
                            $bubble = $is_support ? 'message-from-support ms-auto' : 'message-from-user';
                            ?>
                            <div class="message-bubble <?= $bubble ?>">
                                <small class="d-block mb-2 opacity-75">
                                    <?= htmlspecialchars($sender) ?> • <?= date('M d, Y H:i', strtotime($msg['created_at'])) ?>
                                </small>
                                <?= nl2br(htmlspecialchars($msg['message_text'])) ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($messages)): ?>
                            <p class="text-center text-muted py-5">No messages yet</p>
                        <?php endif; ?>
                    </div>

                    <!-- Reply Form with AJAX + Toast + Spinner -->
                    <?php if ($ticket['status'] === 'closed'): ?>
                        <div class="alert alert-warning text-center">
                            This ticket has been closed. No further replies allowed.
                        </div>
                    <?php else: ?>
                        <form id="replyForm" class="mt-4">
                            <input type="hidden" name="action" value="add_reply">
                            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                            <?php if (!$is_logged_in): ?>
                                <input type="hidden" name="support_email" value="<?= htmlspecialchars($entered_email) ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Your Reply</label>
                                <textarea class="form-control" name="message" rows="5" required
                                    placeholder="Type your response here..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100" id="sendReplyBtn">
                                <span id="btnText"><i class="bi bi-send-fill me-2"></i> Send Reply</span>
                                <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Toast Container (top-right) -->
    <div class="toast-container">
        <div id="successToast" class="toast bg-success text-white" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-success text-white">
                <strong class="me-auto">Success</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                Reply sent successfully!
            </div>
        </div>

        <div id="errorToast" class="toast bg-danger text-white" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-danger text-white">
                <strong class="me-auto">Error</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body" id="errorToastMessage">
                Something went wrong.
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize toasts
            const successToast = new bootstrap.Toast($('#successToast'));
            const errorToast = new bootstrap.Toast($('#errorToast'));

            // Handle reply form submission with AJAX
            $('#replyForm').on('submit', function(e) {
                e.preventDefault();

                const btn = $('#sendReplyBtn');
                const btnText = $('#btnText');
                const btnSpinner = $('#btnSpinner');

                // Show spinner and disable button
                btn.prop('disabled', true);
                btnText.addClass('d-none');
                btnSpinner.removeClass('d-none');

                $.ajax({
                    url: 'functions.php',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        // Reset button
                        btn.prop('disabled', false);
                        btnText.removeClass('d-none');
                        btnSpinner.addClass('d-none');

                        if (response.status === 'success') {
                            // Show success toast
                            successToast.show();

                            // Clear textarea
                            $('#replyForm textarea').val('');

                            // Reload page to show new message (simple way)
                            location.reload();
                        } else {
                            // Show error toast
                            $('#errorToastMessage').text(response.message || 'Failed to send reply');
                            errorToast.show();
                        }
                    },
                    error: function() {
                        // Reset button
                        btn.prop('disabled', false);
                        btnText.removeClass('d-none');
                        btnSpinner.addClass('d-none');

                        $('#errorToastMessage').text('Server error. Please try again.');
                        errorToast.show();
                    }
                });
            });
        });
    </script>
</body>

</html>