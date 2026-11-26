<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE)
   File: portal/admin_pdfs.php   (File 11 of 14)
   Admin screen — bulk-upload result PDFs, auto-generate
   student PINs, print PIN slips for parents.

   REQUIRES FIRST: portal_update.sql (File 10) run in phpMyAdmin.

   FILENAME RULE — name every result PDF like this:
        ITSS-2026-014 - Chidera Okonkwo.pdf
        [exam number] - [student full name].pdf
   The class comes from the "Class" box you pick at upload;
   the exam number's dashes become slashes (ITSS/2026/014).

   ACCESS: uses the portal admin login (admin / admin123) —
   if you are already signed in on portal/admin.php you come
   straight in; otherwise this screen asks you to sign in.
   ============================================================ */

session_start();
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/auth.php')) require_once __DIR__ . '/auth.php';

/* ---------------- database connection ---------------- */
function pdfdb() {
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
function slug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}
function human_size($b) {
    $b = (int)$b;
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b / 1024, 1) . ' KB';
    return $b . ' B';
}
function normalize_exam($s) {
    $s = strtoupper(trim((string)$s));
    $s = str_replace(array('-', '_', '–', '—', ' '), '/', $s);
    $s = preg_replace('#/+#', '/', $s);
    return trim($s, '/');
}
/* "ITSS-2026-014 - Chidera Okonkwo" -> array(exam, name) or null */
function parse_result_filename($base) {
    $parts = preg_split('/\s+-\s+/', trim($base), 2);
    if (count($parts) === 2) {
        $exam = normalize_exam($parts[0]);
        $name = trim(preg_replace('/\s+/', ' ', $parts[1]));
    } else {
        // fallback: first token that looks like an exam number
        if (!preg_match('/([A-Za-z]{2,6}[\/\-_][0-9A-Za-z\/\-_]+)/', $base, $m)) return null;
        $exam = normalize_exam($m[1]);
        $name = trim(preg_replace('/\s+/', ' ', trim(str_replace($m[1], '', $base), " -\t")));
    }
    if ($exam === '' || $name === '' || !preg_match('/\d/', $exam)) return null;
    return array($exam, $name);
}
function gen_pin(PDO $pdo) {
    for ($i = 0; $i < 50; $i++) {
        $pin = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        $st = $pdo->prepare('SELECT id FROM portal_students WHERE pin = ?');
        $st->execute(array($pin));
        if (!$st->fetch()) return $pin;
    }
    throw new Exception('Could not generate a unique PIN — try again.');
}
function default_session() {                 // e.g. "2026/2027"
    $y = (int)date('Y'); $m = (int)date('n');
    return $m >= 8 ? $y . '/' . ($y + 1) : ($y - 1) . '/' . $y;
}

/* ---------------- results folder ---------------- */
define('RESULTS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'results');
function ensure_results_dir($class_slug) {
    if (!is_dir(RESULTS_DIR)) @mkdir(RESULTS_DIR, 0755, true);
    if (is_dir(RESULTS_DIR)) {
        $ht = RESULTS_DIR . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
        $ix = RESULTS_DIR . DIRECTORY_SEPARATOR . 'index.html';
        if (!file_exists($ix)) @file_put_contents($ix, '');
    }
    $dir = RESULTS_DIR . DIRECTORY_SEPARATOR . $class_slug;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/* ---------------- access control ---------------- */
function pdf_admin_ok() {
    if (function_exists('require_admin')) { require_admin(); return true; } // uses portal auth if present
    if (!empty($_SESSION['pdf_admin_ok'])) return true;
    if (!empty($_SESSION['admin_id'])) return true;
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin') return true;
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])
        && (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin')) return true;
    return false;
}
$login_error = '';
if (!function_exists('require_admin') && !pdf_admin_ok()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pdf_login'])) {
        $u = isset($_POST['u']) ? trim($_POST['u']) : '';
        $p = isset($_POST['p']) ? trim($_POST['p']) : '';
        if ($u === 'admin' && $p === 'admin123') {      // same credentials as portal admin
            $_SESSION['pdf_admin_ok'] = 1;
            header('Location: admin_pdfs.php'); exit;
        }
        $login_error = 'Wrong username or password.';
    }
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Admin Sign In — Results Upload</title>
<style>body{font-family:Roboto,Arial,sans-serif;background:#f4f6f4;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#fff;border:1px solid #d8e2da;border-radius:14px;padding:28px;width:340px;box-shadow:0 8px 30px rgba(0,0,0,.06)}
h1{font-size:1.15rem;color:#06331d;margin:0 0 4px}p{color:#667;font-size:.88rem}
label{font-weight:600;font-size:.9rem;display:block;margin:12px 0 4px}
input{width:100%;padding:10px;border:1px solid #d8e2da;border-radius:8px;font:inherit;box-sizing:border-box}
button{margin-top:16px;width:100%;padding:11px;border:0;border-radius:8px;background:#0f6b3f;color:#fff;font:inherit;font-weight:700;cursor:pointer}
.err{color:#b3261e;font-size:.85rem;margin-top:10px}</style></head><body>
<form class="card" method="post">
  <h1>📄 Results Upload — Admin</h1>
  <p>Sign in with the portal admin account.</p>
  <label>Username</label><input name="u" autocomplete="username" required>
  <label>Password</label><input name="p" type="password" autocomplete="current-password" required>
  <input type="hidden" name="pdf_login" value="1">
  <button type="submit">Sign In</button>
  <?php if ($login_error): ?><div class="err"><?php echo e($login_error); ?></div><?php endif; ?>
</form></body></html>
    <?php
    exit;
}

/* ---------------- check the upgrade was run ---------------- */
$pdo = pdfdb();
$tables_ready = true;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'portal_students'")->fetch();
    $chk2 = $pdo->query("SHOW TABLES LIKE 'result_pdfs'")->fetch();
    $tables_ready = $chk && $chk2;
} catch (Exception $ex) { $tables_ready = false; }

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['pdf_csrf'])) $_SESSION['pdf_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['pdf_csrf'];
function csrf_ok() { return isset($_POST['csrf'], $_SESSION['pdf_csrf']) && hash_equals($_SESSION['pdf_csrf'], $_POST['csrf']); }

$flash = array();   // array of array(kind => ok|err, text)

/* ---------------- ACTIONS ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && $tables_ready) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    /* ---- bulk PDF upload ---- */
    if ($action === 'upload') {
        @set_time_limit(300);
        $class   = trim(isset($_POST['class']) ? $_POST['class'] : '');
        $term    = trim(isset($_POST['term']) ? $_POST['term'] : '');
        $session = trim(isset($_POST['session']) ? $_POST['session'] : '');
        if ($class === '' || $term === '' || $session === '') {
            $flash[] = array('err', 'Class, term and session are all required.');
        } elseif (empty($_FILES['pdfs'])) {
            $flash[] = array('err', 'No files received. If you selected many PDFs, your server upload limit may be too small (check upload_max_filesize and post_max_size in php.ini).');
        } else {
            $dir = ensure_results_dir(slug($class));
            $files = $_FILES['pdfs'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                $orig = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $errc = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];

                if ($errc !== UPLOAD_ERR_OK) { $flash[] = array('err', "$orig — upload failed (server error code $errc)."); continue; }
                if (strtolower(pathinfo($orig, PATHINFO_EXTENSION)) !== 'pdf') { $flash[] = array('err', "$orig — skipped: not a .pdf file."); continue; }
                if ($size <= 0 || $size > 25 * 1024 * 1024) { $flash[] = array('err', "$orig — skipped: empty or over 25 MB."); continue; }

                $parsed = parse_result_filename(pathinfo($orig, PATHINFO_FILENAME));
                if (!$parsed) { $flash[] = array('err', "$orig — skipped: could not read exam number and name. Rename like: ITSS-2026-014 - Chidera Okonkwo.pdf"); continue; }
                list($exam, $name) = $parsed;

                try {
                    // find or create the student (PIN generated once, reused every term)
                    $st = $pdo->prepare('SELECT id, pin, student_name FROM portal_students WHERE exam_no = ?');
                    $st->execute(array($exam));
                    $student = $st->fetch();
                    if (!$student) {
                        $pin = gen_pin($pdo);
                        $ins = $pdo->prepare('INSERT INTO portal_students (exam_no, student_name, pin) VALUES (?,?,?)');
                        $ins->execute(array($exam, $name, $pin));
                        $flash[] = array('ok', "NEW student: $name ($exam) — PIN $pin");
                    }

                    $stored = slug($exam) . '-' . slug($term) . '-' . slug($session) . '.pdf';
                    $target_rel = slug($class) . '/' . $stored;
                    $target_abs = $dir . DIRECTORY_SEPARATOR . $stored;

                    // replacing an earlier upload for the same term? remove the old file
                    $old = $pdo->prepare('SELECT stored_file FROM result_pdfs WHERE exam_no=? AND term=? AND session=?');
                    $old->execute(array($exam, $term, $session));
                    $oldrow = $old->fetch();
                    if ($oldrow && $oldrow['stored_file'] !== $target_rel) {
                        $old_abs = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldrow['stored_file']);
                        if (is_file($old_abs)) @unlink($old_abs);
                    }

                    if (!@move_uploaded_file($tmp, $target_abs)) { $flash[] = array('err', "$orig — could not save to the results folder. Check folder permissions."); continue; }

                    $up = $pdo->prepare('INSERT INTO result_pdfs (exam_no, class_name, term, session, stored_file, original_file)
                                         VALUES (?,?,?,?,?,?)
                                         ON DUPLICATE KEY UPDATE class_name=VALUES(class_name),
                                             stored_file=VALUES(stored_file), original_file=VALUES(original_file), uploaded_at=CURRENT_TIMESTAMP');
                    $up->execute(array($exam, $class, $term, $session, $target_rel, $orig));
                    $flash[] = array('ok', "$exam — $name: saved" . ($oldrow ? ' (replaced previous upload)' : '') . '.');
                } catch (Exception $ex) {
                    $flash[] = array('err', "$orig — database error: " . $ex->getMessage());
                }
            }
        }
        $_SESSION['pdf_flash'] = $flash;
        header('Location: admin_pdfs.php'); exit;
    }

    /* ---- delete one result ---- */
    if ($action === 'delete_result' && !empty($_POST['id'])) {
        $st = $pdo->prepare('SELECT * FROM result_pdfs WHERE id = ?');
        $st->execute(array((int)$_POST['id']));
        if ($row = $st->fetch()) {
            $abs = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['stored_file']);
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare('DELETE FROM result_pdfs WHERE id = ?')->execute(array($row['id']));
            $_SESSION['pdf_flash'] = array(array('ok', 'Deleted: ' . $row['exam_no'] . ' — ' . $row['term'] . ' ' . $row['session'] . '.'));
        }
        header('Location: admin_pdfs.php'); exit;
    }

    /* ---- add a student by hand (also how you create the demo student) ---- */
    if ($action === 'add_student') {
        $exam = normalize_exam(isset($_POST['exam_no']) ? $_POST['exam_no'] : '');
        $name = trim(isset($_POST['student_name']) ? $_POST['student_name'] : '');
        if ($exam === '' || $name === '' || !preg_match('/\d/', $exam)) {
            $_SESSION['pdf_flash'] = array(array('err', 'Enter a valid exam number and student name.'));
        } else {
            $st = $pdo->prepare('SELECT id FROM portal_students WHERE exam_no = ?');
            $st->execute(array($exam));
            if ($st->fetch()) {
                $_SESSION['pdf_flash'] = array(array('err', "$exam already exists in the system."));
            } else {
                $pin = gen_pin($pdo);
                $pdo->prepare('INSERT INTO portal_students (exam_no, student_name, pin) VALUES (?,?,?)')->execute(array($exam, $name, $pin));
                $_SESSION['pdf_flash'] = array(array('ok', "Student added: $name ($exam) — PIN $pin. It appears in the PIN slips below."));
            }
        }
        header('Location: admin_pdfs.php'); exit;
    }

    /* ---- regenerate a PIN (lost slip) ---- */
    if ($action === 'regen_pin' && !empty($_POST['exam_no'])) {
        $exam = $_POST['exam_no'];
        $pin = gen_pin($pdo);
        $pdo->prepare('UPDATE portal_students SET pin = ? WHERE exam_no = ?')->execute(array($pin, $exam));
        $_SESSION['pdf_flash'] = array(array('ok', "New PIN for $exam: $pin — print a fresh slip; the old PIN no longer works."));
        header('Location: admin_pdfs.php'); exit;
    }
}
if (!empty($_SESSION['pdf_flash'])) { $flash = $_SESSION['pdf_flash']; unset($_SESSION['pdf_flash']); }

/* ---------------- PRINT: PIN SLIPS ---------------- */
if (isset($_GET['print']) && $_GET['print'] === 'slips' && $tables_ready) {
    $where = ''; $args = array();
    if (!empty($_GET['class'])) { $where = 'WHERE s.exam_no IN (SELECT exam_no FROM result_pdfs WHERE class_name = ?)'; $args[] = $_GET['class']; }
    $sql = "SELECT s.exam_no, s.student_name, s.pin,
                   (SELECT r.class_name FROM result_pdfs r WHERE r.exam_no = s.exam_no ORDER BY r.uploaded_at DESC LIMIT 1) AS class_name
            FROM portal_students s $where ORDER BY class_name, s.student_name";
    $st = $pdo->prepare($sql); $st->execute($args);
    $slips = $st->fetchAll();
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>PIN Slips — Iriebe Town Secondary School (UBE)</title>
<style>
  body{font-family:Roboto,Arial,sans-serif;margin:24px;color:#222}
  .bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
  button{padding:10px 18px;border:0;border-radius:8px;background:#0f6b3f;color:#fff;font:inherit;font-weight:700;cursor:pointer}
  a{color:#0f6b3f}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .slip{border:2px dashed #0f6b3f;border-radius:10px;padding:14px 16px;page-break-inside:avoid}
  .slip h2{font-size:.95rem;margin:0 0 2px;color:#06331d}
  .slip .conf{font-size:.7rem;letter-spacing:.08em;color:#b3261e;font-weight:700;margin-bottom:8px}
  .slip p{margin:3px 0;font-size:.88rem}
  .slip .pin{font-size:1.25rem;font-weight:800;letter-spacing:.06em}
  .slip .how{font-size:.75rem;color:#555;margin-top:8px;border-top:1px solid #d8e2da;padding-top:6px}
  @media print{.bar{display:none}.grid{gap:10px}}
</style></head><body>
  <div class="bar">
    <a href="admin_pdfs.php">← Back to uploads</a>
    <strong><?php echo count($slips); ?> slip(s)<?php echo !empty($_GET['class']) ? ' — ' . e($_GET['class']) : ''; ?></strong>
    <button onclick="window.print()">🖨️ Print Slips</button>
  </div>
  <?php if (!$slips): ?><p>No students yet. Upload result PDFs or add a student first.</p><?php endif; ?>
  <div class="grid">
    <?php foreach ($slips as $s): ?>
    <div class="slip">
      <h2>IRIEBE TOWN SECONDARY SCHOOL (UBE)</h2>
      <div class="conf">CONFIDENTIAL — FOR PARENT / GUARDIAN ONLY</div>
      <p><strong>Student:</strong> <?php echo e($s['student_name']); ?></p>
      <p><strong>Class:</strong> <?php echo e($s['class_name'] ?: '—'); ?></p>
      <p><strong>Exam No:</strong> <?php echo e($s['exam_no']); ?></p>
      <p><strong>Result PIN:</strong> <span class="pin"><?php echo e($s['pin']); ?></span></p>
      <p class="how">To check your child's result: open the school website → <strong>Check Result</strong> → enter the Exam Number and this PIN. Keep this slip safe — the same PIN is used every term.</p>
    </div>
    <?php endforeach; ?>
  </div>
</body></html>
    <?php
    exit;
}

/* ---------------- LISTING DATA ---------------- */
$rows = array(); $classes = array(); $students = array();
if ($tables_ready) {
    $classes = $pdo->query('SELECT DISTINCT class_name FROM result_pdfs ORDER BY class_name')->fetchAll(PDO::FETCH_COLUMN);
    $where = array(); $args = array();
    if (!empty($_GET['class'])) { $where[] = 'r.class_name = ?'; $args[] = $_GET['class']; }
    if (!empty($_GET['term']))  { $where[] = 'r.term = ?';       $args[] = $_GET['term']; }
    $sql = 'SELECT r.*, s.student_name, s.pin FROM result_pdfs r
            LEFT JOIN portal_students s ON s.exam_no = r.exam_no '
            . ($where ? 'WHERE ' . implode(' AND ', $where) : '') .
            ' ORDER BY r.uploaded_at DESC LIMIT 300';
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll();
    $students = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM result_pdfs r WHERE r.exam_no = s.exam_no) AS pdf_count
                            FROM portal_students s ORDER BY s.student_name LIMIT 500')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Result PDFs — Admin | Iriebe Town Secondary School (UBE)</title>
<style>
  body{font-family:Roboto,Arial,sans-serif;background:#f4f6f4;margin:0;color:#222}
  .wrap{max-width:1060px;margin:0 auto;padding:22px 16px 60px}
  .head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
  .head h1{margin:0;font-size:1.4rem;color:#06331d}
  .links a{margin-left:12px;color:#0f6b3f;font-weight:600;text-decoration:none}
  .card{background:#fff;border:1px solid #d8e2da;border-radius:14px;padding:18px 20px;margin-bottom:18px}
  .card h2{margin:0 0 4px;font-size:1.05rem;color:#06331d}
  .note{font-size:.85rem;color:#667}
  label{font-weight:600;font-size:.9rem;display:block;margin:10px 0 4px}
  input[type=text],select,input[type=file]{width:100%;padding:9px 11px;border:1px solid #d8e2da;border-radius:8px;font:inherit;box-sizing:border-box;background:#fff}
  .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  @media(max-width:640px){.row3{grid-template-columns:1fr}}
  .btn{display:inline-block;margin-top:14px;padding:11px 20px;border:0;border-radius:8px;background:#0f6b3f;color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
  .btn.gray{background:#fff;color:#0f6b3f;border:1px solid #0f6b3f}
  .btn.mini{padding:5px 10px;font-size:.8rem;margin:0}
  .btn.red{background:#fff;color:#7a1f1f;border:1px solid #e3c2c2}
  .flash{padding:9px 12px;border-radius:8px;margin:6px 0;font-size:.88rem}
  .flash.ok{background:#e8f5ee;border:1px solid #bfe3cf;color:#0a4a2a}
  .flash.err{background:#fdeceb;border:1px solid #f2c1bd;color:#8a2018}
  table{width:100%;border-collapse:collapse;font-size:.88rem;margin-top:10px}
  th,td{padding:8px 10px;border-bottom:1px solid #e6ece7;text-align:left;vertical-align:top}
  th{color:#06331d;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em}
  .pin{font-weight:800;letter-spacing:.04em}
  .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
  .filters label{margin:0 0 4px}
  .warnbox{background:#fdf3e3;border:1px solid #f0d9a8;color:#7a5510;padding:14px 16px;border-radius:10px;margin-bottom:16px;font-size:.92rem}
  code{background:#eef3ef;padding:1px 6px;border-radius:6px;font-size:.85em}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>📄 Result PDFs &amp; PINs</h1>
    <div class="links">
      <a href="admin.php">← Portal admin</a>
      <a href="../index.html" target="_blank">View website</a>
      <a href="admin_pdfs.php?print=slips" target="_blank">🖨️ Print PIN slips</a>
    </div>
  </div>

  <?php if (!$tables_ready): ?>
    <div class="warnbox">⚠️ The results tables are missing. Run <code>portal_update.sql</code> (File 10) in phpMyAdmin first, then reload this page.</div>
  <?php endif; ?>

  <?php foreach ($flash as $f): ?>
    <div class="flash <?php echo $f[0] === 'ok' ? 'ok' : 'err'; ?>"><?php echo e($f[1]); ?></div>
  <?php endforeach; ?>

  <!-- ============ BULK UPLOAD ============ -->
  <div class="card">
    <h2>1️⃣ Upload result PDFs (a whole class at once)</h2>
    <p class="note">Name every file <code>EXAMNUMBER - Student Name.pdf</code> (e.g. <code>ITSS-2026-014 - Chidera Okonkwo.pdf</code>).
    New exam numbers get a PIN automatically — print the slips afterwards from the link above. Uploading the same student + term again replaces the old PDF.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
      <input type="hidden" name="action" value="upload">
      <div class="row3">
        <div>
          <label>Class (the folder it belongs to)</label>
          <input type="text" name="class" list="classlist" placeholder="e.g. JSS 2A" required>
          <datalist id="classlist">
            <?php foreach ($classes as $c): ?><option value="<?php echo e($c); ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label>Term</label>
          <select name="term" required>
            <option value="">— choose —</option>
            <option>First Term</option><option>Second Term</option><option>Third Term</option>
          </select>
        </div>
        <div>
          <label>Session</label>
          <input type="text" name="session" value="<?php echo e(default_session()); ?>" required>
        </div>
      </div>
      <label style="margin-top:12px">Result PDFs (select many at once — Ctrl/⌘ + click, or selectall in a folder with Ctrl/⌘+A)</label>
      <input type="file" name="pdfs[]" accept=".pdf,application/pdf" multiple required>
      <button class="btn" type="submit">⬆️ Upload &amp; Generate PINs</button>
    </form>
  </div>

  <!-- ============ UPLOADED RESULTS ============ -->
  <div class="card">
    <h2>2️⃣ Uploaded results</h2>
    <form class="filters" method="get">
      <div>
        <label>Class</label>
        <select name="class">
          <option value="">All classes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?php echo e($c); ?>" <?php echo (isset($_GET['class']) && $_GET['class'] === $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Term</label>
        <select name="term">
          <option value="">All terms</option>
          <?php foreach (array('First Term','Second Term','Third Term') as $t): ?>
            <option <?php echo (isset($_GET['term']) && $_GET['term'] === $t) ? 'selected' : ''; ?>><?php echo $t; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button class="btn gray mini" type="submit" style="padding:9px 16px">Filter</button></div>
      <div><a class="btn gray mini" style="padding:9px 16px" href="admin_pdfs.php">Clear</a></div>
    </form>

    <?php if (!$rows): ?>
      <p class="note" style="margin-top:12px">No results uploaded yet<?php echo $where ? ' for this filter' : ''; ?>.</p>
    <?php else: ?>
    <table>
      <thead><tr>
        <th>Exam No</th><th>Student</th><th>Class</th><th>Term</th><th>Session</th><th>File</th><th>Uploaded</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?php echo e($r['exam_no']); ?></strong></td>
          <td><?php echo e($r['student_name']); ?></td>
          <td><?php echo e($r['class_name']); ?></td>
          <td><?php echo e($r['term']); ?></td>
          <td><?php echo e($r['session']); ?></td>
          <td class="note"><?php echo e($r['original_file']); ?></td>
          <td><?php echo e(date('d M Y', strtotime($r['uploaded_at']))); ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this result PDF? The student and PIN stay in the system.');">
              <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
              <input type="hidden" name="action" value="delete_result">
              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
              <button class="btn mini red" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ============ STUDENTS & PINS ============ -->
  <div class="card">
    <h2>3️⃣ Students &amp; PINs</h2>
    <p class="note">Students are created automatically on first upload — add one by hand only for testing or special cases.
    If a parent loses a slip, regenerate the PIN and print a fresh one (the old PIN stops working).</p>

    <form method="post" class="filters" style="margin-bottom:6px">
      <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
      <input type="hidden" name="action" value="add_student">
      <div>
        <label>Exam number</label>
        <input type="text" name="exam_no" placeholder="ITSS/2026/014" required>
      </div>
      <div>
        <label>Student name</label>
        <input type="text" name="student_name" placeholder="Chidera Okonkwo" required>
      </div>
      <div><button class="btn mini" type="submit" style="padding:9px 16px">＋ Add student</button></div>
    </form>

    <?php if ($students): ?>
    <table>
      <thead><tr>
        <th>Student</th><th>Exam No</th><th>PIN</th><th>PDFs</th><th>Added</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><strong><?php echo e($s['student_name']); ?></strong></td>
          <td><?php echo e($s['exam_no']); ?></td>
          <td class="pin"><?php echo e($s['pin']); ?></td>
          <td><?php echo (int)$s['pdf_count']; ?></td>
          <td><?php echo e(date('d M Y', strtotime($s['created_at']))); ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Generate a NEW PIN for <?php echo e($s['student_name']); ?>? The old PIN will stop working.');">
              <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
              <input type="hidden" name="action" value="regen_pin">
              <input type="hidden" name="exam_no" value="<?php echo e($s['exam_no']); ?>">
              <button class="btn mini red" type="submit">New PIN</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
