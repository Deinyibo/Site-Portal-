<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE)
   File: portal/student.php   (File 12 of 14)
   Parent / student result checker.

   A parent enters:
     1. Exam number  (from the PIN slip, e.g. ITSS/2026/014)
     2. Result PIN   (from the PIN slip, e.g. 4821-7356)
   …and sees every result PDF published for that child,
   with View / Download buttons.

   Downloads are served ONLY through portal/download.php
   (File 13), which checks this page's login — the PDF files
   themselves are blocked from direct access, so nobody can
   guess a URL.

   REQUIRES FIRST: portal_update.sql (File 10) run in phpMyAdmin.
   The homepage "Check Result" buttons link here.
   ============================================================ */

session_start();
require_once __DIR__ . '/config.php';

/* ---------------- database connection ---------------- */
function pdb() {
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

/* ---------------- small helpers ---------------- */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function normalize_exam($s) {                    // same rule as the admin screen
    $s = strtoupper(trim((string)$s));
    $s = str_replace(array('-', '_', '–', '—', ' '), '/', $s);
    $s = preg_replace('#/+#', '/', $s);
    return trim($s, '/');
}
function normalize_pin($s) {                     // "4821 7356" / "4821-7356" -> "48217356"
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$s));
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

/* ---------------- check the upgrade was run ---------------- */
$tables_ready = true;
try {
    $pdo = pdb();
    $tables_ready = (bool)$pdo->query("SHOW TABLES LIKE 'portal_students'")->fetch()
                 && (bool)$pdo->query("SHOW TABLES LIKE 'result_pdfs'")->fetch();
} catch (Exception $ex) { $tables_ready = false; }

/* ---------------- login session helpers ---------------- */
define('PARENT_IDLE_LIMIT', 30 * 60);            // auto log-out after 30 min idle

function parent_logged_in() {
    if (empty($_SESSION['portal_parent']) || empty($_SESSION['portal_parent']['exam_no'])) return false;
    if (time() - (int)$_SESSION['portal_parent']['t'] > PARENT_IDLE_LIMIT) {
        unset($_SESSION['portal_parent']);
        return false;
    }
    $_SESSION['portal_parent']['t'] = time();    // still active — refresh the clock
    return true;
}

/* log out */
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['portal_parent']);
    header('Location: student.php?out=1'); exit;
}

/* ---------------- handle sign-in ---------------- */
$error = '';
$locked_until = isset($_SESSION['plog_until']) ? (int)$_SESSION['plog_until'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login']) && $tables_ready) {

    /* simple form token */
    $tok_ok = isset($_POST['tok'], $_SESSION['plog_tok']) && hash_equals($_SESSION['plog_tok'], $_POST['tok']);

    if (time() < $locked_until) {
        $mins = max(1, ceil(($locked_until - time()) / 60));
        $error = "Too many wrong attempts. Please wait about $mins minute(s) and try again.";
    } elseif (!$tok_ok) {
        $error = 'The form expired — please try again.';
    } else {
        usleep(random_int(400000, 1200000));     // slow down PIN guessing

        $exam = normalize_exam(isset($_POST['exam_no']) ? $_POST['exam_no'] : '');
        $pin  = normalize_pin(isset($_POST['pin']) ? $_POST['pin'] : '');

        $student = null;
        if ($exam !== '' && $pin !== '') {
            $st = $pdo->prepare('SELECT * FROM portal_students WHERE exam_no = ?');
            $st->execute(array($exam));
            $student = $st->fetch();
        }

        if ($student && hash_equals(normalize_pin($student['pin']), $pin)) {
            session_regenerate_id(true);
            $_SESSION['portal_parent'] = array(
                'exam_no' => $student['exam_no'],
                'name'    => $student['student_name'],
                't'       => time(),
            );
            unset($_SESSION['plog_n'], $_SESSION['plog_until']);
            header('Location: student.php'); exit;
        }

        /* wrong details — one vague message, never say which part failed */
        $n = (int)(isset($_SESSION['plog_n']) ? $_SESSION['plog_n'] : 0) + 1;
        if ($n >= 5) {
            $_SESSION['plog_until'] = time() + 600;   // 10-minute lock-out
            $_SESSION['plog_n'] = 0;
            $error = 'Too many wrong attempts. Please wait 10 minutes and try again.';
        } else {
            $_SESSION['plog_n'] = $n;
            $error = 'Wrong exam number or PIN. Check the slip from the school and try again.';
        }
    }
}
if (empty($_SESSION['plog_tok'])) $_SESSION['plog_tok'] = bin2hex(random_bytes(16));
$TOK = $_SESSION['plog_tok'];

/* ---------------- dashboard data (when signed in) ---------------- */
$me = null; $results = array(); $class_now = '';
if ($tables_ready && parent_logged_in()) {
    $me = $_SESSION['portal_parent'];
    $st = $pdo->prepare('SELECT * FROM result_pdfs WHERE exam_no = ? ORDER BY uploaded_at DESC');
    $st->execute(array($me['exam_no']));
    $results = $st->fetchAll();
    if ($results) $class_now = $results[0]['class_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Check Result — Iriebe Town Secondary School (UBE)</title>
<style>
  body{font-family:Roboto,Arial,sans-serif;background:#f4f6f4;margin:0;color:#222}
  .topbar{background:#06331d;color:#fff;padding:14px 16px;text-align:center}
  .topbar h1{margin:0;font-size:1.1rem;letter-spacing:.02em}
  .topbar p{margin:3px 0 0;font-size:.8rem;opacity:.85}
  .wrap{max-width:760px;margin:0 auto;padding:26px 16px 60px}
  .card{background:#fff;border:1px solid #d8e2da;border-radius:14px;padding:22px 20px;margin-bottom:18px}
  .card h2{margin:0 0 4px;font-size:1.05rem;color:#06331d}
  .note{font-size:.85rem;color:#667}
  label{font-weight:600;font-size:.9rem;display:block;margin:12px 0 4px}
  input{width:100%;padding:11px;border:1px solid #d8e2da;border-radius:8px;font:inherit;box-sizing:border-box}
  input:focus{outline:2px solid #0f6b3f;outline-offset:1px;border-color:#0f6b3f}
  .btn{display:inline-block;margin-top:16px;padding:12px 22px;border:0;border-radius:8px;background:#0f6b3f;color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
  .btn.full{width:100%;box-sizing:border-box;text-align:center}
  .btn.gray{background:#fff;color:#0f6b3f;border:1px solid #0f6b3f}
  .btn.mini{padding:7px 14px;font-size:.82rem;margin:0}
  .err{background:#fdeceb;border:1px solid #f2c1bd;color:#8a2018;padding:10px 14px;border-radius:8px;font-size:.9rem;margin-top:14px}
  .ok{background:#e8f5ee;border:1px solid #bfe3cf;color:#0a4a2a;padding:10px 14px;border-radius:8px;font-size:.9rem;margin-top:14px}
  .who{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
  .who h2{margin:0}
  .who small{color:#667}
  table{width:100%;border-collapse:collapse;font-size:.9rem;margin-top:12px}
  th,td{padding:10px;border-bottom:1px solid #e6ece7;text-align:left;vertical-align:middle}
  th{color:#06331d;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
  .actions{white-space:nowrap}
  .actions .btn{margin-left:6px}
  .tip{background:#eef6f0;border:1px solid #cfe6d8;border-radius:10px;padding:12px 14px;font-size:.85rem;color:#24503a;margin-top:16px}
  .back{text-align:center;margin-top:18px}
  .back a{color:#0f6b3f;font-weight:600;text-decoration:none;font-size:.9rem}
  @media(max-width:560px){
    th.hide,td.hide{display:none}
    .actions .btn{display:inline-block;margin:2px 0}
  }
</style>
</head>
<body>

<div class="topbar">
  <h1>IRIEBE TOWN SECONDARY SCHOOL (UBE)</h1>
  <p>Online Result Checker</p>
</div>

<div class="wrap">

<?php if (!$tables_ready): ?>
  <div class="card">
    <h2>Result checker not ready yet</h2>
    <p class="note">The school is still setting up the online result system. Please check back soon, or contact the school office.</p>
  </div>

<?php elseif ($me): ?>
  <!-- ============ SIGNED IN: RESULT LIST ============ -->
  <div class="card">
    <div class="who">
      <div>
        <h2><?php echo e($me['name']); ?></h2>
        <small>Exam No: <strong><?php echo e($me['exam_no']); ?></strong><?php echo $class_now ? ' &nbsp;•&nbsp; Class: <strong>' . e($class_now) . '</strong>' : ''; ?></small>
      </div>
      <a class="btn gray mini" href="student.php?action=logout">Log out</a>
    </div>

    <?php if (!$results): ?>
      <div class="tip" style="margin-top:16px">No result has been published yet for this exam number. Please check back after the school uploads the term's results.</div>
    <?php else: ?>
    <table>
      <thead><tr>
        <th>Term</th><th>Session</th><th class="hide">Class</th><th class="hide">Published</th><th>Result</th>
      </tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><strong><?php echo e($r['term']); ?></strong></td>
          <td><?php echo e($r['session']); ?></td>
          <td class="hide"><?php echo e($r['class_name']); ?></td>
          <td class="hide"><?php echo e(date('d M Y', strtotime($r['uploaded_at']))); ?></td>
          <td class="actions">
            <a class="btn mini gray" href="download.php?id=<?php echo (int)$r['id']; ?>&amp;view=1" target="_blank">👁 View</a>
            <a class="btn mini" href="download.php?id=<?php echo (int)$r['id']; ?>">⬇ Download</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <div class="tip">🔒 Your results are private — this page logs out by itself after 30 minutes. Tap <strong>Log out</strong> when you finish, especially on a shared phone or computer. Keep your PIN slip safe: the same PIN is used every term.</div>
  </div>

<?php else: ?>
  <!-- ============ SIGN-IN FORM ============ -->
  <div class="card">
    <h2>Check your child's result</h2>
    <p class="note">Enter the <strong>Exam Number</strong> and <strong>Result PIN</strong> exactly as they appear on the slip from the school.</p>

    <form method="post" autocomplete="off">
      <input type="hidden" name="do_login" value="1">
      <input type="hidden" name="tok" value="<?php echo e($TOK); ?>">

      <label>Exam Number</label>
      <input name="exam_no" maxlength="40" placeholder="e.g. ITSS/2026/014" required>

      <label>Result PIN</label>
      <input name="pin" maxlength="20" placeholder="e.g. 4821-7356" required>

      <button class="btn full" type="submit">View Result</button>
    </form>

    <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (isset($_GET['out'])): ?><div class="ok">You have been logged out safely.</div><?php endif; ?>

    <div class="tip">📄 No PIN slip yet? The school gives one slip per child after results are uploaded — please ask at the school office. The same PIN works every term, so keep it safe.</div>
  </div>
<?php endif; ?>

  <div class="back"><a href="../index.html">← Back to the school website</a></div>
</div>

</body>
</html>
