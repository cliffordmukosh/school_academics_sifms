<?php
// support/index.php - Main Support Dashboard
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

// ────────────────────────────────────────────────
// Security: Require login + school context
// ────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$school_id  = $_SESSION['school_id'];
$full_name  = $_SESSION['full_name'] ?? 'User';

// Determine if current user is support/admin
$is_support_staff = in_array($user_id, [1, 2]) ||
    in_array($_SESSION['role_name'] ?? '', ['Admin', 'Super Admin', 'Support']);

// ────────────────────────────────────────────────
// Fetch MY tickets (always visible)
// ────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT t.ticket_id, t.subject, t.status, t.priority, t.created_at,
           CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS sender_name,
           s.name AS school_name
    FROM support_tickets t
    JOIN users u ON t.user_id = u.user_id
    JOIN schools s ON t.school_id = s.school_id
    WHERE t.school_id = ? AND t.user_id = ?
    ORDER BY t.created_at DESC
");
$stmt->bind_param("ii", $school_id, $user_id);
$stmt->execute();
$my_tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ────────────────────────────────────────────────
// Fetch ALL tickets (support staff only - shows every school)
// ────────────────────────────────────────────────
// ────────────────────────────────────────────────
// Fetch ALL tickets for THIS SCHOOL (support staff only)
// ────────────────────────────────────────────────
$all_tickets = [];
if ($is_support_staff) {
    $stmt = $conn->prepare("
        SELECT t.ticket_id, t.subject, t.status, t.priority, t.created_at,
               CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS sender_name,
               s.name AS school_name, s.email AS school_email, s.phone AS school_phone
        FROM support_tickets t
        JOIN users u ON t.user_id = u.user_id
        JOIN schools s ON t.school_id = s.school_id
        WHERE t.school_id = ?                      /* ← added this line */
        ORDER BY t.created_at DESC
    ");
    $stmt->bind_param("i", $school_id);            /* ← bind the session school_id */
    $stmt->execute();
    $all_tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<div class="content">
    <div class="container-fluid">
        <div class="container py-4">
            <h3 class="mb-4 d-flex align-items-center">
                <i class="bi bi-life-preserver me-2 text-primary"></i>
                Support & Helpdesk
                <span class="badge bg-primary ms-3 fs-6">My Tickets: <?= count($my_tickets) ?></span>
            </h3>

            <!-- Quick Actions -->
            <div class="row g-4 mb-5">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-plus-circle display-4 text-success mb-3"></i>
                            <h5>New Support Ticket</h5>
                            <p class="text-muted small">Describe your issue or question</p>
                            <button class="btn btn-success mt-2" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                                Create Ticket
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-whatsapp display-4 text-success mb-3"></i>
                            <h5>Instant WhatsApp Help</h5>
                            <p class="text-muted small">Chat with support team directly</p>
                            <a href="https://wa.me/254794872775?text=Hello%2C%20I%20need%20help%20with%20SIFMS%20system"
                                class="btn btn-success mt-2" target="_blank">
                                Open WhatsApp
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 text-center <?= $is_support_staff ? 'bg-light' : '' ?>">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-shield-check <?= $is_support_staff ? 'text-primary' : 'text-muted' ?> display-4 mb-3"></i>
                            <h5>Support Access</h5>
                            <p class="text-muted small">
                                <?= $is_support_staff ? 'You have full support access' : 'Regular user access' ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Tickets -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i> My Support Tickets</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($my_tickets)): ?>
                        <div class="alert alert-info text-center py-4">
                            You haven't created any support tickets yet.
                        </div>
                    <?php else: ?>
                        <div class="accordion" id="myTicketsAccordion">
                            <?php foreach ($my_tickets as $ticket): ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed d-flex justify-content-between align-items-center"
                                            type="button" data-bs-toggle="collapse"
                                            data-bs-target="#ticketCollapse<?= $ticket['ticket_id'] ?>">
                                            <div>
                                                <strong><?= htmlspecialchars($ticket['subject']) ?></strong>
                                            </div>
                                            <div>
                                                <span class="badge 
    <?php
                                if ($ticket['status'] === 'open') {
                                    echo 'bg-warning';
                                } elseif ($ticket['status'] === 'resolved') {
                                    echo 'bg-success';
                                } else {
                                    echo 'bg-secondary';
                                }
    ?> ms-2">
                                                    <?= ucfirst($ticket['status']) ?>
                                                </span>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="ticketCollapse<?= $ticket['ticket_id'] ?>" class="accordion-collapse collapse">
                                        <div class="accordion-body">
                                            <small class="text-muted d-block mb-3">
                                                Created: <?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?>
                                            </small>
                                            <div class="messages-container mb-3" data-ticket-id="<?= $ticket['ticket_id'] ?>">
                                                <div class="text-center py-4">
                                                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                                </div>
                                            </div>
                                            <form class="reply-form" data-ticket-id="<?= $ticket['ticket_id'] ?>">
                                                <div class="input-group">
                                                    <textarea class="form-control" name="message" rows="2"
                                                        placeholder="Type your reply..." required></textarea>
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-send"></i>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- All Tickets - Support Staff Only -->
            <?php if ($is_support_staff): ?>
                <div class="card shadow-sm border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-ticket-detailed"></i> All Support Tickets</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($all_tickets)): ?>
                            <div class="alert alert-info text-center py-4">
                                No support tickets exist in the system yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-success">
                                        <tr>
                                            <th>Subject</th>
                                            <th>School</th>
                                            <th>From</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_tickets as $t): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($t['subject']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($t['school_name']) ?><br>
                                                    <small><?= htmlspecialchars($t['school_email'] ?? '—') ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($t['sender_name']) ?></td>
                                                <td>
                                                    <span class="badge <?= match ($t['priority'] ?? 'medium') {
                                                                            'high'   => 'bg-danger',
                                                                            'medium' => 'bg-warning',
                                                                            default  => 'bg-info',
                                                                        } ?>">
                                                        <?= ucfirst($t['priority'] ?? 'medium') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?= match ($t['status']) {
                                                                            'open'     => 'bg-warning',
                                                                            'resolved' => 'bg-success',
                                                                            default    => 'bg-secondary',
                                                                        } ?>">
                                                        <?= ucfirst($t['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('M d, Y H:i', strtotime($t['created_at'])) ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary view-ticket-btn"
                                                        data-ticket-id="<?= $t['ticket_id'] ?>">
                                                        View / Reply
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Ticket Modal -->
<div class="modal fade" id="newTicketModal" tabindex="-1" aria-labelledby="newTicketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newTicketModalLabel">
                    <i class="bi bi-plus-circle me-2"></i> Create New Support Ticket
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="newTicketForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject</label>
                        <input type="text" class="form-control" name="subject" required placeholder="Brief description of your issue">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message / Description</label>
                        <textarea class="form-control" name="message_text" rows="6" required
                            placeholder="Please describe your problem or question in detail..."></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-send me-2"></i> Send Ticket
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Ticket View/Reply Modal -->
<div class="modal fade" id="ticketViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Ticket #<span id="viewTicketId"></span> - <span id="viewSubject"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                <div id="ticketDetails" class="mb-3"></div>
                <div id="messagesContainer"></div>
            </div>
            <div class="modal-footer">
                <form id="replyForm" class="w-100">
                    <input type="hidden" name="ticket_id" id="replyTicketId">
                    <div class="input-group">
                        <textarea class="form-control" name="message" rows="2" placeholder="Type your reply..." required></textarea>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // ────────────────────────────────────────────────
    // JavaScript - AJAX handling for tickets & replies
    // ────────────────────────────────────────────────
    $(document).ready(function() {

        // Create new ticket
        $('#newTicketForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: 'support/functions.php',
                method: 'POST',
                data: $(this).serialize() + '&action=create_ticket',
                dataType: 'json',
                success: function(response) {
                    alert(response.message);
                    if (response.status === 'success') {
                        $('#newTicketModal').modal('hide');
                        location.reload();
                    }
                },
                error: function() {
                    alert('Error creating ticket');
                }
            });
        });

        // Load messages when expanding accordion row
        $('.accordion-button').on('click', function() {
            const collapse = $(this).attr('data-bs-target');
            const container = $(collapse).find('.messages-container');
            const ticketId = container.data('ticket-id');

            if (container.find('.message-item').length === 0) {
                loadMessages(ticketId, container);
            }
        });

        // View ticket detail (for support staff)
        $(document).on('click', '.view-ticket-btn', function() {
            const ticketId = $(this).data('ticket-id');
            loadTicketDetails(ticketId);
            $('#replyTicketId').val(ticketId);
            $('#ticketViewModal').modal('show');
        });

        // Submit reply from modal
        $('#replyForm').on('submit', function(e) {
            e.preventDefault();
            const ticketId = $('#replyTicketId').val();
            const message = $(this).find('textarea').val().trim();

            if (!message) return alert('Please type a message');

            sendReply(ticketId, message, function() {
                $('#replyForm textarea').val('');
                loadMessages(ticketId, $('#messagesContainer'));
            });
        });

        // Submit reply from accordion
        $(document).on('submit', '.reply-form', function(e) {
            e.preventDefault();
            const form = $(this);
            const ticketId = form.data('ticket-id');
            const message = form.find('textarea').val().trim();

            if (!message) return alert('Please type a message');

            sendReply(ticketId, message, function() {
                form.find('textarea').val('');
                loadMessages(ticketId, form.closest('.accordion-body').find('.messages-container'));
            });
        });

        function sendReply(ticketId, message, callback) {
            $.ajax({
                url: 'support/functions.php',
                method: 'POST',
                data: {
                    action: 'add_reply',
                    ticket_id: ticketId,
                    message: message
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        callback();
                    } else {
                        alert(response.message);
                    }
                },
                error: function() {
                    alert('Error sending reply');
                }
            });
        }

        function loadMessages(ticketId, targetContainer) {
            $.ajax({
                url: 'support/functions.php',
                method: 'POST',
                data: {
                    action: 'get_messages',
                    ticket_id: ticketId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        let html = '';
                        response.messages.forEach(msg => {
                            const isMe = msg.is_from_admin == 0;
                            const sender = msg.is_from_admin ? 'Support Team' : msg.sender_name;
                            const bgClass = isMe ? 'bg-primary text-white ms-auto' : 'bg-light';

                            html += `
                        <div class="d-flex mb-3 ${isMe ? 'justify-content-end' : 'justify-content-start'} message-item">
                            <div class="p-3 rounded shadow-sm ${bgClass}" style="max-width:80%;">
                                <small class="d-block mb-1 opacity-75">
                                    ${sender} • ${new Date(msg.created_at).toLocaleString()}
                                </small>
                                ${msg.message_text.replace(/\n/g, '<br>')}
                            </div>
                        </div>`;
                        });
                        targetContainer.html(html || '<p class="text-center text-muted py-4">No messages yet</p>');
                    } else {
                        targetContainer.html('<p class="text-danger">Error loading messages</p>');
                    }
                },
                error: function() {
                    targetContainer.html('<p class="text-danger">Failed to load messages</p>');
                }
            });
        }

        function loadTicketDetails(ticketId) {
            $.ajax({
                url: 'support/functions.php',
                method: 'POST',
                data: {
                    action: 'get_ticket_details',
                    ticket_id: ticketId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        const d = response.details;
                        $('#viewTicketId').text(d.ticket_id);
                        $('#viewSubject').text(d.subject);
                        $('#ticketDetails').html(`
                        <div class="bg-light p-3 rounded mb-3">
                            <strong>School:</strong> ${d.school_name}<br>
                            <strong>From:</strong> ${d.sender_name}<br>
                            <strong>Created:</strong> ${new Date(d.created_at).toLocaleString()}<br>
                            <strong>Status:</strong> <span class="badge bg-${d.status === 'open' ? 'warning' : 'success'}">${d.status.toUpperCase()}</span>
                        </div>
                    `);
                        loadMessages(ticketId, $('#messagesContainer'));
                    } else {
                        $('#messagesContainer').html('<p class="text-danger">Ticket not found</p>');
                    }
                }
            });
        }

        // Auto-refresh active ticket messages every 30 seconds
        setInterval(function() {
            const active = $('.accordion-collapse.show .messages-container, #messagesContainer');
            if (active.length) {
                const ticketId = active.data('ticket-id') || $('#replyTicketId').val();
                if (ticketId) loadMessages(ticketId, active);
            }
        }, 30000);
    });
</script>

<?php include __DIR__ . '/../footer.php'; ?>