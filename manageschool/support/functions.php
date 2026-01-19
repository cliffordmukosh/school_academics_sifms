<?php
// support/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
$action = $_POST['action'] ?? '';

// Allowed support emails (no account needed)
$allowed_support_emails = [
    'email2@gmail.com',
    'cliffordmukosh@gmail.com',
    // Add more if needed
];

// ──────────────────────────────────────────────────────────────
// Access control
// ──────────────────────────────────────────────────────────────

$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['school_id']);

if ($action === 'add_reply') {
    $support_email = trim($_POST['support_email'] ?? '');

    $is_valid_support = !empty($support_email) &&
        in_array(strtolower($support_email), array_map('strtolower', $allowed_support_emails));

    // Allow reply if logged in OR valid support email
    if (!$is_logged_in && !$is_valid_support) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Unauthorized: Please login or use a valid support email'
        ]);
        exit;
    }

    $is_support_reply = $is_valid_support && !$is_logged_in;
} else {
    // Other actions require login
    if (!$is_logged_in) {
        echo json_encode(['status' => 'error', 'message' => 'Please login first']);
        exit;
    }
}

// ────────────────────────────────────────────────────────────────
// Main switch
// ────────────────────────────────────────────────────────────────

switch ($action) {
    case 'create_ticket':
        $subject      = trim($_POST['subject'] ?? '');
        $message_text = trim($_POST['message_text'] ?? '');

        if (empty($subject) || empty($message_text)) {
            echo json_encode(['status' => 'error', 'message' => 'Subject and message required']);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO support_tickets 
            (school_id, user_id, subject, status, priority) 
            VALUES (?, ?, ?, 'open', 'medium')
        ");
        $stmt->bind_param("iis", $_SESSION['school_id'], $_SESSION['user_id'], $subject);
        $stmt->execute();
        $ticket_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO support_messages 
            (ticket_id, sender_id, message_text, is_from_admin) 
            VALUES (?, ?, ?, 0)
        ");
        $stmt->bind_param("iis", $ticket_id, $_SESSION['user_id'], $message_text);
        $stmt->execute();
        $stmt->close();

        // Get full ticket info for email
        $stmt = $conn->prepare("
            SELECT t.subject,
                   CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS sender_name,
                   s.name AS school_name,
                   s.email AS school_email,
                   s.phone AS school_phone,
                   u.personal_email AS user_email
            FROM support_tickets t
            JOIN users u ON t.user_id = u.user_id
            JOIN schools s ON t.school_id = s.school_id
            WHERE t.ticket_id = ?
        ");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // For new ticket → admins get per-ticket link
        $reply_link = "http://192.168.100.145/online/schoolacademics/manageschool/support/reply.php?ticket_id=$ticket_id";

        // Rich HTML email
        $email_body = '
        <html>
        <head><title>New Support Ticket</title></head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <h2 style="color: #0d6efd;">New Support Ticket Created</h2>
            <p><strong>Ticket ID:</strong> #' . $ticket_id . '</p>
            <p><strong>Subject:</strong> ' . htmlspecialchars($info['subject']) . '</p>
            <p><strong>From:</strong> ' . htmlspecialchars($info['sender_name']) . '</p>
            <p><strong>School:</strong> ' . htmlspecialchars($info['school_name']) . '</p>
            <p><strong>School Email:</strong> ' . htmlspecialchars($info['school_email']) . '</p>
            <p><strong>School Phone:</strong> ' . htmlspecialchars($info['school_phone']) . '</p>
            <hr>
            <h4>Message:</h4>
            <p style="white-space: pre-wrap;">' . nl2br(htmlspecialchars($message_text)) . '</p>
            <br>
            <a href="' . $reply_link . '" style="display: inline-block; padding: 12px 24px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">
                View & Reply to Ticket
            </a>
            <p style="margin-top: 30px; font-size: 12px; color: #777;">
                This is an automated message from SIFMS Support System.<br>
                Do not reply directly to this email.
            </p>
        </body>
        </html>';

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.sifms.co.ke';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'communications@sifms.co.ke';
            $mail->Password   = 'QO8EXcAZeW-cwkCL';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            $mail->setFrom('communications@sifms.co.ke', 'SIFMS Support');
            $mail->addAddress('email2@gmail.com');
            $mail->addAddress('cliffordmukosh@gmail.com');

            $mail->isHTML(true);
            $mail->Subject = "New Support Ticket #$ticket_id - " . htmlspecialchars($info['subject']);
            $mail->Body    = $email_body;
            $mail->AltBody = strip_tags($email_body);
            $mail->send();
        } catch (Exception $e) {
            error_log("Create ticket email failed: " . $mail->ErrorInfo);
        }

        echo json_encode(['status' => 'success', 'message' => 'Ticket created. Support notified.']);
        break;

    case 'add_reply':
        $ticket_id     = (int)($_POST['ticket_id'] ?? 0);
        $message       = trim($_POST['message'] ?? '');
        $support_email = trim($_POST['support_email'] ?? '');

        if ($ticket_id <= 0 || empty($message)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
            exit;
        }

        // Get ticket details for rich email
        $stmt = $conn->prepare("
            SELECT t.user_id, t.status, t.subject,
                   CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS sender_name,
                   s.name AS school_name,
                   s.email AS school_email,
                   s.phone AS school_phone,
                   u.personal_email AS user_email,
                   u.email AS system_email
            FROM support_tickets t
            JOIN users u ON t.user_id = u.user_id
            JOIN schools s ON t.school_id = s.school_id
            WHERE t.ticket_id = ?
        ");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ticket) {
            echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
            exit;
        }

        if ($ticket['status'] === 'closed') {
            echo json_encode(['status' => 'error', 'message' => 'Ticket is closed']);
            exit;
        }

        // Who is replying?
        if ($is_support_reply) {
            $sender_id     = null;
            $is_from_admin = 1;
            $reply_from    = "Support Team ($support_email)";
        } else {
            $sender_id     = $_SESSION['user_id'];
            $is_from_admin = 0;
            $reply_from    = "User ({$ticket['sender_name']})";
        }

        // Insert reply
        $query = "INSERT INTO support_messages (ticket_id, sender_id, message_text, is_from_admin";
        $types = "iisi";
        $values = [$ticket_id, $sender_id, $message, $is_from_admin];

        if ($is_support_reply && $conn->query("SHOW COLUMNS FROM support_messages LIKE 'sender_email'")->num_rows > 0) {
            $query .= ", sender_email";
            $types .= "s";
            $values[] = $support_email;
        }

        $query .= ") VALUES (" . implode(',', array_fill(0, count($values), '?')) . ")";

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$values);
        $success = $stmt->execute();
        $stmt->close();

        // Update ticket status
        if ($ticket['status'] === 'open') {
            $conn->query("UPDATE support_tickets SET status = 'in_progress' WHERE ticket_id = $ticket_id");
        }
        $conn->query("UPDATE support_tickets SET updated_at = NOW() WHERE ticket_id = $ticket_id");

        // ────────────────────────────────────────────────
        // DIFFERENT LINKS BASED ON WHO IS REPLYING
        // ────────────────────────────────────────────────
        if ($is_support_reply) {
            // Allowed support emails (admins) → per-ticket reply link
            $button_link = "http://192.168.100.145/online/schoolacademics/manageschool/support/reply.php?ticket_id=$ticket_id";
            $button_text = "View & Reply to Ticket";
        } else {
            // Normal school user replied → link to school dashboard
            $button_link = "http://localhost/online/schoolacademics/manageschool/support/index.php";
            $button_text = "View Support Dashboard";
        }

        // Rich HTML email notification
        $email_body = '
        <html>
        <head><title>New Reply on Ticket #' . $ticket_id . '</title></head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <h2 style="color: #198754;">New Reply Received</h2>
            <p><strong>Ticket ID:</strong> #' . $ticket_id . '</p>
            <p><strong>Subject:</strong> ' . htmlspecialchars($ticket['subject']) . '</p>
            <p><strong>School:</strong> ' . htmlspecialchars($ticket['school_name']) . '</p>
            <p><strong>Original Sender:</strong> ' . htmlspecialchars($ticket['sender_name']) . '</p>
            <p><strong>Reply From:</strong> ' . htmlspecialchars($reply_from) . '</p>
            <hr>
            <h4>Reply Message:</h4>
            <p style="white-space: pre-wrap; background: #f8f9fa; padding: 15px; border-left: 4px solid #198754;">'
            . nl2br(htmlspecialchars($message)) . '</p>
            <br>
            <a href="' . $button_link . '" style="display: inline-block; padding: 12px 24px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">
                ' . $button_text . '
            </a>
            <p style="margin-top: 30px; font-size: 12px; color: #777;">
                This is an automated message from SIFMS Support System.<br>
                Do not reply directly to this email.
            </p>
        </body>
        </html>';

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.sifms.co.ke';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'communications@sifms.co.ke';
            $mail->Password   = 'QO8EXcAZeW-cwkCL';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            $mail->setFrom('communications@sifms.co.ke', 'SIFMS Support');

            if ($is_support_reply) {
                // Support replied → notify the school's official email
                if (!empty($ticket['school_email'])) {
                    $mail->addAddress($ticket['school_email']);
                }
            } else {
                // User replied → notify support team
                $mail->addAddress('email2@gmail.com');
                $mail->addAddress('cliffordmukosh@gmail.com');
            }

            $mail->isHTML(true);
            $mail->Subject = "New Reply on Ticket #$ticket_id - " . htmlspecialchars($ticket['subject']);
            $mail->Body    = $email_body;
            $mail->AltBody = strip_tags($email_body);
            $mail->send();
        } catch (Exception $e) {
            error_log("Reply email failed: " . $mail->ErrorInfo);
        }

        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'Reply sent successfully' : 'Failed to send reply'
        ]);
        break;

    // get_messages & get_ticket_details remain unchanged
    case 'get_messages':
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        if ($ticket_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ticket']);
            exit;
        }

        if (!$is_logged_in) {
            echo json_encode(['status' => 'error', 'message' => 'Please login first']);
            exit;
        }

        $stmt = $conn->prepare("SELECT user_id FROM support_tickets WHERE ticket_id = ?");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $is_owner = ($ticket && $ticket['user_id'] == $_SESSION['user_id']);
        $is_support = in_array($_SESSION['user_id'], [1, 2]) ||
            in_array($_SESSION['role_name'] ?? '', ['Admin', 'Super Admin', 'Support']);

        if (!$is_owner && !$is_support) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT 
                m.message_text, 
                m.created_at, 
                m.is_from_admin,
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

        echo json_encode([
            'status'   => 'success',
            'messages' => $messages
        ]);
        break;

    case 'get_ticket_details':
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        if ($ticket_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ticket']);
            exit;
        }

        if (!$is_logged_in) {
            echo json_encode(['status' => 'error', 'message' => 'Please login first']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT 
                t.subject, 
                t.status, 
                t.created_at,
                CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS sender_name,
                s.name AS school_name
            FROM support_tickets t
            JOIN users u ON t.user_id = u.user_id
            JOIN schools s ON t.school_id = s.school_id
            WHERE t.ticket_id = ?
        ");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $details = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        echo json_encode([
            'status'  => $details ? 'success' : 'error',
            'details' => $details ?: null
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
