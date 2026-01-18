<?php
// reports/download_reports.php
// session_start();
require __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Adjust path to TCPDF

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$term = isset($_GET['term']) ? $_GET['term'] : '';
$stream_id = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;

if (!$class_id || !$term || !$stream_id) {
    die('Required parameters missing.');
}

// Fetch report card HTML content
ob_start();
include 'reportcard.php';
$html = ob_get_clean();

// Initialize TCPDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('School System');
$pdf->SetTitle('Termly Report Cards');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->AddPage();
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('report_cards_' . $term . '_stream_' . $stream_id . '.pdf', 'D');
exit;