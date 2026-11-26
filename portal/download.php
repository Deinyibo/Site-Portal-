<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE)
   File: portal/download.php   (File 13 of 14)
   Serves one result PDF — ONLY to the parent it belongs to.

   Parents never touch the files directly: this script checks
   the sign-in from portal/student.php, makes sure the result
   belongs to THEIR exam number, then streams the PDF.
     download.php?id=3          -> downloads the PDF
     download.php?id=3&view=1   -> opens it in the browser
   A signed-in admin may view any result (for checking uploads).
   ============================================================ */

session_start();
require_once __DIR__ . '/config.php';

/* ---------------- database connection ---------------- */
function dldb() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (function_exists('portal_db'))            { $pdo = portal_db(); return $pdo; }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) { $pdo = $GLOBALS['pdo']; return $pdo; }
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    return $pdo;
}

/* ---------------- same login clock as student.php ---------------- */
define('PARENT_IDLE_LIMIT', 30 * 60);

function dl_parent_exam() {                       // exam number of the signed-in parent, or null
    if (empty($_SESSION['portal_parent']) || empty($_SESSION['portal_parent']['exam_no'])) return null;
    if (time() - (int)$_SESSION['portal_parent']['t'] > PARENT_IDLE_LIMIT) {
        unset($_SESSION['portal_parent']);
        return null;
    }
    $_SESSION['portal_parent']['t'] = time();
    return $_SESSION['portal_parent']['exam_no'];
}
function dl_is_admin() {                          // any signed-in portal admin may preview
    if (!empty($_SESSION['pdf_admin_ok'])) return true;
    if (!empty($_SESSION['admin_id'])) return true;
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin') return true;
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])
        && isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin') return true;
    if (function_exists('is_admin')) { try { return (bool)is_admin(); } catch (Exception $x) {} }
    return false;
}

/* ---------------- friendly error page ---------------- */
function dl_fail($code, $title, $msg, $link_back = true) {
    http_response_code($code);
    header('X-Content-Type-Options: nosniff');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . ' — Iriebe Town Secondary School (UBE)</title>
<style>body{font-family:Roboto,Arial,sans-serif;background:#f4f6f4;margin:0;color:#222;display:flex;min-height:100vh;align-items:center;justify-content:center}
.card{background:#fff;border:1px solid #d8e2da;border-radius:14px;padding:28px;max-width:420px;text-align:center}
h1{font-size:1.15rem;color:#06331d;margin:0 0 8px}p{color:#667;font-size:.9rem;line-height:1.5}
a{display:inline-block;margin-top:14px;padding:10px 20px;border-radius:8px;background:#0f6b3f;color:#fff;font-weight:700;text-decoration:none}</style>
</head><body><div class="card"><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($msg) . '</p>'
. ($link_back ? '<a href="student.php">Go to Result Checker</a>' : '') . '</div></body></html>';
    exit;
}

/* ---------------- who is asking? ---------------- */
$parent_exam = dl_parent_exam();
$is_admin    = dl_is_admin();

if (!$parent_exam && !$is_admin) {
    // not signed in — send parents to the result checker
    header('Location: student.php');
    exit;
}

/* ---------------- which result? ---------------- */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) dl_fail(400, 'Bad request', 'That result link is not valid.');

$pdo = dldb();
$st = $pdo->prepare('SELECT r.*, s.student_name FROM result_pdfs r
                     LEFT JOIN portal_students s ON s.exam_no = r.exam_no
                     WHERE r.id = ?');
$st->execute(array($id));
$row = $st->fetch();
if (!$row) dl_fail(404, 'Result not found', 'This result could not be found. It may have been removed — please contact the school office.');

/* ---------------- the key check: is it THEIRS? ---------------- */
if (!$is_admin && $row['exam_no'] !== $parent_exam) {
    dl_fail(403, 'Not your result', 'This result belongs to a different student. Please sign in with the exam number and PIN from your own slip.');
}

/* ---------------- find the file, safely ---------------- */
$base = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'results');
$rel  = str_replace('\\', '/', (string)$row['stored_file']);
if ($base === false || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
    dl_fail(404, 'File not available', 'This result file is not available right now — please contact the school office.');
}
$abs = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
if ($abs === false || strpos($abs, $base) !== 0 || !is_file($abs) || !is_readable($abs)) {
    dl_fail(404, 'File not available', 'This result file is not available right now — please contact the school office.');
}

/* ---------------- name the download nicely ---------------- */
function dl_slug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}
$pretty = 'Result';
if (!empty($row['student_name'])) $pretty = dl_slug($row['student_name']);
$pretty .= '-' . dl_slug($row['term'] . ' ' . $row['session']) . '-result.pdf';
$pretty = ucwords($pretty, '-');

/* ---------------- stream it ---------------- */
$view = isset($_GET['view']) && $_GET['view'] === '1';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: ' . ($view ? 'inline' : 'attachment') . '; filename="' . $pretty . '"');
header('Cache-Control: private, no-store, must-revalidate');   // confidential — never cache
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$fh = fopen($abs, 'rb');
if ($fh === false) dl_fail(500, 'Could not open file', 'The result file could not be opened — please try again shortly.');
while (!feof($fh)) { echo fread($fh, 8192); flush(); }
fclose($fh);
exit;
