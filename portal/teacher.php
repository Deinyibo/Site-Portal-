<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — TEACHER SCORE ENTRY
   File: portal/teacher.php

   What teachers do here:
     · Pick a class, then a subject
     · Enter CA 1, CA 2 and Exam for each student — totals and
       grades appear live as they type
     · Save: new scores are inserted, drafts are updated

   The rules:
     · Scores always go into the ACTIVE term (set by the admin)
     · Published scores are locked — the admin must unpublish
       before they can change
     · A row is saved only when all three scores are filled
     · Clearing all three boxes of a DRAFT row and saving
       removes that draft
     · Teachers (and admins) can change their own password
       under "My Password"
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireStaff();

$me        = currentUser();
$roleLabel = (function_exists('isAdmin') && isAdmin()) ? 'Admin' : 'Teacher';

/* ---------------- small local helpers ---------------- */

/* Read the CA weights from config's scoreLimits(), whatever its
   exact key names, falling back to the portal scheme 20/20/60. */
function teachLimits($pdo) {
    $L = function_exists('scoreLimits') ? scoreLimits($pdo) : array();
    $ca1 = 20; $ca2 = 20; $ex = 60;
    if (is_array($L)) {
        if (isset($L['ca1']))     $ca1 = (float)$L['ca1'];
        if (isset($L['ca2']))     $ca2 = (float)$L['ca2'];
        if (isset($L['exam']))    $ex  = (float)$L['exam'];
        if (isset($L['ca1_max'])) $ca1 = (float)$L['ca1_max'];
        if (isset($L['ca2_max'])) $ca2 = (float)$L['ca2_max'];
        if (isset($L['exam_max'])) $ex = (float)$L['exam_max'];
    }
    return array($ca1, $ca2, $ex);
}

/* Grade + remark for a /100 total — uses config's gradeFor()
   whatever its shape, with the portal scheme as fallback.      */
function teachGrade($total) {
    if (function_exists('gradeFor')) {
        $g = gradeFor($total);
        if (is_array($g)) {
            return array(
                isset($g['grade'])  ? $g['grade']  : '',
                isset($g['remark']) ? $g['remark'] : ''
            );
        }
        if (is_string($g) && $g !== '') return array($g, '');
    }
    if ($total >= 70) return array('A', 'Excellent');
    if ($total >= 60) return array('B', 'Very Good');
    if ($total >= 50) return array('C', 'Credit');
    if ($total >= 45) return array('D', 'Merit');
    if ($total >= 40) return array('E', 'Pass');
    return array('F', 'Fail');
}

function teachGradePill($grade) {
    if ($grade === 'A' || $grade === 'B') return 'ok';
    if ($grade === 'C') return 'gold';
    if ($grade === 'D' || $grade === 'E') return 'warn';
    return 'bad';
}

/* Trim a trailing ".0" so 70.0 shows as 70 but 70.5 stays. */
function fmt($n) {
    $s = number_format((float)$n, 1);
    return substr($s, -2) === '.0' ? substr($s, 0, -2) : $s;
}

/* Normalise a typed score: "12,5" → "12.5". */
function cleanScore($v) {
    return str_replace(',', '.', trim((string)$v));
}

/* ---------------- shared data ---------------- */
$school1 = getSetting($pdo, 'school_name_1', FALLBACK_SCHOOL_1);
$school2 = getSetting($pdo, 'school_name_2', FALLBACK_SCHOOL_2);
list($L1, $L2, $L3) = teachLimits($pdo);
$term    = activeTerm($pdo);
$classes = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();
$subjects = $pdo->query('SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name')->fetchAll();

$page      = (isset($_GET['page']) && $_GET['page'] === 'password') ? 'password' : 'scores';
$classId   = (int)(isset($_REQUEST['class_id']) ? $_REQUEST['class_id'] : 0);
$subjectId = (int)(isset($_REQUEST['subject_id']) ? $_REQUEST['subject_id'] : 0);

$classRow = null;
foreach ($classes as $c) if ((int)$c['id'] === $classId) $classRow = $c;
$subjectRow = null;
foreach ($subjects as $s) if ((int)$s['id'] === $subjectId) $subjectRow = $s;
if ($classId && !$classRow)     $classId = 0;
if ($subjectId && !$subjectRow) $subjectId = 0;

/* Flash message carried across the save redirect. */
$flash = null;
if (isset($_SESSION['teach_flash'])) {
    $flash = $_SESSION['teach_flash'];
    unset($_SESSION['teach_flash']);
}

/* ======================= SAVE SCORES ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'scores'
    && isset($_POST['action']) && $_POST['action'] === 'save'
    && $term && $classRow && $subjectRow) {

    /* Students of this class — scores are only ever written for
       ids from this list, never for ids invented by the form.  */
    $st = $pdo->prepare(
        "SELECT id, full_name, status FROM students
         WHERE class_id = ? ORDER BY full_name"
    );
    $st->execute(array($classId));
    $classStudents = $st->fetchAll();

    /* Existing results for this class + subject + active term. */
    $rq = $pdo->prepare(
        'SELECT r.* FROM results r
         JOIN students s ON s.id = r.student_id
         WHERE s.class_id = ? AND r.subject_id = ? AND r.term_id = ?'
    );
    $rq->execute(array($classId, $subjectId, (int)$term['id']));
    $existing = array();
    foreach ($rq->fetchAll() as $r) $existing[(int)$r['student_id']] = $r;

    $ca1s = (isset($_POST['ca1']) && is_array($_POST['ca1'])) ? $_POST['ca1'] : array();
    $ca2s = (isset($_POST['ca2']) && is_array($_POST['ca2'])) ? $_POST['ca2'] : array();
    $exs  = (isset($_POST['ex'])  && is_array($_POST['ex']))  ? $_POST['ex']  : array();

    $upsert = $pdo->prepare(
        'INSERT INTO results (student_id, subject_id, term_id, ca1, ca2, exam, published)
         VALUES (?, ?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE ca1 = VALUES(ca1), ca2 = VALUES(ca2), exam = VALUES(exam)'
    );
    $delete = $pdo->prepare(
        'DELETE FROM results
         WHERE student_id = ? AND subject_id = ? AND term_id = ? AND published = 0'
    );

    $added = 0; $updated = 0; $removed = 0; $locked = 0; $badNames = array();

    foreach ($classStudents as $stu) {
        $sid = (int)$stu['id'];
        if ($stu['status'] !== 'active') continue;

        $a = cleanScore(isset($ca1s[$sid]) ? $ca1s[$sid] : '');
        $b = cleanScore(isset($ca2s[$sid]) ? $ca2s[$sid] : '');
        $e = cleanScore(isset($exs[$sid])  ? $exs[$sid]  : '');
        $row = isset($existing[$sid]) ? $existing[$sid] : null;

        /* Published rows are untouchable here. */
        if ($row && !empty($row['published'])) {
            $before = fmt($row['ca1']) . '|' . fmt($row['ca2']) . '|' . fmt($row['exam']);
            $after  = $a . '|' . $b . '|' . $e;
            if ($after !== $before) $locked++;
            continue;
        }

        /* All three blank: nothing to save — and if a draft
           exists, clearing the row removes it.               */
        if ($a === '' && $b === '' && $e === '') {
            if ($row) { $delete->execute(array($sid, $subjectId, (int)$term['id'])); $removed++; }
            continue;
        }

        /* Partly filled rows are never saved. */
        if ($a === '' || $b === '' || $e === ''
            || !is_numeric($a) || !is_numeric($b) || !is_numeric($e)
            || (float)$a < 0 || (float)$a > $L1
            || (float)$b < 0 || (float)$b > $L2
            || (float)$e < 0 || (float)$e > $L3) {
            $badNames[] = $stu['full_name'];
            continue;
        }

        $upsert->execute(array($sid, $subjectId, (int)$term['id'],
                               (float)$a, (float)$b, (float)$e));
        if ($row) $updated++; else $added++;
    }

    $parts = array();
    if ($added)   $parts[] = $added . ' added';
    if ($updated) $parts[] = $updated . ' updated';
    if ($removed) $parts[] = $removed . ' removed';
    $msg = $parts ? ('Saved — ' . implode(', ', $parts) . '.') : 'Nothing new to save.';
    $kind = 'ok';
    if ($locked)  { $msg .= ' ' . $locked . ' published row' . ($locked === 1 ? ' was' : 's were') . ' left untouched (locked).'; $kind = 'warn'; }
    if ($badNames) {
        $msg .= ' Not saved — incomplete or out of range: ' . implode(', ', $badNames) . '.';
        $kind = 'warn';
    }

    if (function_exists('audit')) {
        try { audit($pdo, 'save_scores',
            $roleLabel . ' "' . $me['name'] . '" saved scores: ' . $classRow['name']
            . ' / ' . $subjectRow['name'] . ' / ' . $term['name'] . ' — ' . $msg);
        } catch (Throwable $t) { /* audit must never block saving */ }
    }

    $_SESSION['teach_flash'] = array($kind, $msg);
    header('Location: teacher.php?class_id=' . $classId . '&subject_id=' . $subjectId);
    exit;
}

/* ===================== CHANGE PASSWORD ===================== */
$pwMsg = ''; $pwOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'password') {
    $new1 = isset($_POST['new1']) ? (string)$_POST['new1'] : '';
    $new2 = isset($_POST['new2']) ? (string)$_POST['new2'] : '';
    if (strlen($new1) < 8) {
        $pwMsg = 'Your new password must be at least 8 characters.';
    } elseif ($new1 !== $new2) {
        $pwMsg = 'The two passwords do not match — please type them again.';
    } else {
        $myId = isset($me['id']) ? (int)$me['id'] : 0;
        try {
            if (!$myId || !function_exists('changeStaffPassword')) {
                throw new Exception('unavailable');
            }
            $res = changeStaffPassword($pdo, $myId, $new1);
            if ($res === false || (is_array($res) && isset($res['ok']) && !$res['ok'])) {
                $pwMsg = (is_array($res) && !empty($res['error']))
                       ? (string)$res['error']
                       : 'That password could not be set — please try another.';
            } else {
                $pwOk = true;
                $pwMsg = 'Your password has been changed. Use it next time you sign in.';
                if (function_exists('audit')) {
                    try { audit($pdo, 'change_password', $roleLabel . ' "' . $me['name'] . '" changed their password.'); }
                    catch (Throwable $t) { }
                }
            }
        } catch (Throwable $t) {
            $pwMsg = 'Passwords cannot be changed here right now — please ask the administrator to reset yours.';
        }
    }
}

/* ================== DATA FOR THE SCORE GRID ================== */
$gridStudents = array();   // active + inactive, for display
$gridExisting = array();   // sid → result row
$subProgress  = array();   // subject id → entered count (class chosen, no subject yet)
$stats = array('active' => 0, 'entered' => 0, 'published' => 0, 'avg' => 0);

if ($page === 'scores' && $term && $classRow) {
    $st = $pdo->prepare(
        "SELECT id, full_name, exam_no, status FROM students
         WHERE class_id = ? ORDER BY full_name"
    );
    $st->execute(array($classId));
    $gridStudents = $st->fetchAll();

    /* Progress per subject — shown on the subject picker cards. */
    $pq = $pdo->prepare(
        'SELECT r.subject_id, COUNT(*) AS n FROM results r
         JOIN students s ON s.id = r.student_id
         WHERE s.class_id = ? AND r.term_id = ? GROUP BY r.subject_id'
    );
    $pq->execute(array($classId, (int)$term['id']));
    foreach ($pq->fetchAll() as $p) $subProgress[(int)$p['subject_id']] = (int)$p['n'];

    if ($subjectRow) {
        $rq = $pdo->prepare(
            'SELECT r.* FROM results r
             JOIN students s ON s.id = r.student_id
             WHERE s.class_id = ? AND r.subject_id = ? AND r.term_id = ?'
        );
        $rq->execute(array($classId, $subjectId, (int)$term['id']));
        foreach ($rq->fetchAll() as $r) $gridExisting[(int)$r['student_id']] = $r;

        $sum = 0;
        foreach ($gridStudents as $stu) {
            if ($stu['status'] !== 'active') continue;
            $stats['active']++;
            $sid = (int)$stu['id'];
            if (isset($gridExisting[$sid])) {
                $stats['entered']++;
                $sum += (float)$gridExisting[$sid]['ca1']
                      + (float)$gridExisting[$sid]['ca2']
                      + (float)$gridExisting[$sid]['exam'];
                if (!empty($gridExisting[$sid]['published'])) $stats['published']++;
            }
        }
        if ($stats['entered']) $stats['avg'] = $sum / $stats['entered'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Score Entry — <?php echo h($school1 . ' ' . $school2); ?> Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="portal.css">
</head>
<body>
<div class="portal-top">
  <a class="pt-brand" href="<?php echo (function_exists('isAdmin') && isAdmin()) ? 'admin.php' : 'teacher.php'; ?>"><img src="../images/school-logo.png" alt="">
    <span class="pt-name"><strong><?php echo h($school1); ?></strong><span><?php echo h($school2); ?></span></span></a>
  <div class="pt-right">
    <span class="pt-user">👩‍🏫 <?php echo h($me['name']); ?> <em><?php echo h($roleLabel); ?></em></span>
    <a class="pt-logout" href="login.php?logout=1">Sign out</a>
  </div>
</div>
<nav class="portal-nav">
  <a class="pn-link<?php echo $page === 'scores' ? ' on' : ''; ?>" href="teacher.php">📝 Score Entry</a>
  <a class="pn-link<?php echo $page === 'password' ? ' on' : ''; ?>" href="teacher.php?page=password">🔑 My Password</a>
  <?php if (function_exists('isAdmin') && isAdmin()) { ?>
    <a class="pn-link" href="admin.php">← Admin Dashboard</a>
  <?php } ?>
  <a class="pn-link" href="../index.html" target="_blank">View Website ↗</a>
</nav>

<main class="portal-main">

<style>
  /* Furniture for the teacher pages (te- prefix — no clashes
     with portal.css or the admin pages). */
  .te-head { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; justify-content:space-between; margin:18px 0 6px; }
  .te-head h1 { font-size:1.35rem; margin:0; }
  .te-head p  { margin:2px 0 0; opacity:.75; font-size:.9rem; }
  .te-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin:14px 0 18px; }
  .te-stat { background:#fff; border:1px solid #e2e9e4; border-radius:14px; padding:14px 16px; }
  .te-stat b { display:block; font-size:1.35rem; color:#0f6b3f; }
  .te-stat span { font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; opacity:.65; }
  .te-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin:14px 0 6px; }
  .te-card { display:block; background:#fff; border:1px solid #e2e9e4; border-radius:14px; padding:16px; text-decoration:none; color:#24403a; transition:border-color .15s, box-shadow .15s; }
  .te-card:hover { border-color:#0f6b3f; box-shadow:0 4px 14px rgba(15,107,63,.12); }
  .te-card.on { border-color:#0f6b3f; background:#f2faf5; }
  .te-card b { display:block; font-size:1.05rem; margin-bottom:4px; }
  .te-card span { font-size:.8rem; opacity:.7; }
  .te-card .te-prog { display:inline-block; margin-top:8px; font-size:.72rem; font-weight:600; padding:2px 10px; border-radius:999px; background:#e2f5e9; color:#0f6b3f; }
  .te-card .te-prog.none { background:#fdeee0; color:#9a5b00; }
  .te-grid th, .te-grid td { text-align:center; }
  .te-grid .te-name { position:sticky; left:0; background:#fff; text-align:left; z-index:2; box-shadow:2px 0 0 #e2e9e4; min-width:180px; }
  .te-grid thead .te-name { background:#f4f8f5; }
  .te-grid input.score-in { width:64px; text-align:center; padding:7px 6px; border:1px solid #cfd8d2; border-radius:8px; font:inherit; }
  .te-grid input.score-in:focus { outline:2px solid #0f6b3f33; border-color:#0f6b3f; }
  .te-grid input.score-in:disabled { background:#eef2ef; color:#98a8a0; }
  .te-grid tr.te-locked input.score-in { background:#eef2ef; }
  .te-grid tr.te-locked td { opacity:.85; }
  .te-total { font-weight:700; }
  .te-savebar { position:sticky; bottom:0; background:#ffffffee; backdrop-filter:blur(4px); border-top:1px solid #e2e9e4; padding:12px 16px; display:flex; gap:12px; align-items:center; justify-content:flex-end; border-radius:0 0 14px 14px; }
  .te-savebar .te-note { margin-right:auto; font-size:.82rem; opacity:.75; }
  .te-btn { padding:11px 26px; border:0; border-radius:10px; background:#0f6b3f; color:#fff; font:inherit; font-weight:600; cursor:pointer; }
  .te-btn:hover { background:#0a4d2d; }
  .te-btn.ghost { background:#fff; color:#0f6b3f; border:1px solid #0f6b3f; }
  .te-scroll { overflow-x:auto; }
  .te-pw { max-width:460px; }
  @media (max-width:640px) {
    .te-grid input.score-in { width:54px; }
  }
</style>

<?php if ($flash) { ?>
  <div class="flash <?php echo h($flash[0]); ?>"><?php echo h($flash[1]); ?></div>
<?php } ?>

<?php /* ==================== MY PASSWORD PAGE ==================== */ ?>
<?php if ($page === 'password') { ?>

  <div class="te-head">
    <div>
      <h1>🔑 My Password</h1>
      <p>Change the password you use to sign in to the portal.</p>
    </div>
  </div>

  <div class="panel te-pw">
    <?php if ($pwMsg !== '') { ?>
      <div class="flash <?php echo $pwOk ? 'ok' : 'bad'; ?>"><?php echo h($pwMsg); ?></div>
    <?php } ?>
    <form class="pform" method="post" action="teacher.php?page=password">
      <div class="fgrid">
        <label>New password
          <input type="password" name="new1" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
        </label>
        <label>Repeat new password
          <input type="password" name="new2" minlength="8" required autocomplete="new-password" placeholder="Type it again">
        </label>
      </div>
      <button class="te-btn" type="submit">Change Password</button>
    </form>
  </div>

<?php /* ==================== SCORE ENTRY PAGE ==================== */ ?>
<?php } else { ?>

  <div class="te-head">
    <div>
      <h1>📝 Score Entry</h1>
      <p>
        <?php if ($term) { ?>
          Entering scores for <strong><?php echo h($term['name']); ?></strong> — CA&nbsp;1&nbsp;/<?php echo fmt($L1); ?>, CA&nbsp;2&nbsp;/<?php echo fmt($L2); ?>, Exam&nbsp;/<?php echo fmt($L3); ?>.
        <?php } else { ?>
          No active term — ask the administrator to activate one before entering scores.
        <?php } ?>
      </p>
    </div>
    <?php if ($classRow && $subjectRow && $term) { ?>
      <a class="te-btn ghost" href="teacher.php?class_id=<?php echo $classId; ?>">← Change subject</a>
    <?php } elseif ($classRow) { ?>
      <a class="te-btn ghost" href="teacher.php">← Change class</a>
    <?php } ?>
  </div>

  <?php if (!$term) { ?>

    <div class="panel"><div class="empty">⏳ Score entry opens as soon as the administrator activates a term.</div></div>

  <?php } elseif (!$classes) { ?>

    <div class="panel"><div class="empty">No classes have been created yet — the administrator adds them from the dashboard.</div></div>

  <?php } elseif (!$classRow) { /* -------- STEP 1: pick a class -------- */ ?>

    <div class="te-cards">
      <?php foreach ($classes as $c) { ?>
        <a class="te-card" href="teacher.php?class_id=<?php echo (int)$c['id']; ?>">
          <b><?php echo h($c['name']); ?></b>
          <span>Tap to enter scores</span>
        </a>
      <?php } ?>
    </div>

  <?php } elseif (!$subjectRow) { /* -------- STEP 2: pick a subject -------- */ ?>

    <?php if (!$subjects) { ?>
      <div class="panel"><div class="empty">No subjects yet — the administrator adds them from <em>Subjects</em>.</div></div>
    <?php } else { ?>
      <div class="te-cards">
        <?php foreach ($subjects as $s) {
            $done = isset($subProgress[(int)$s['id']]) ? $subProgress[(int)$s['id']] : 0; ?>
          <a class="te-card" href="teacher.php?class_id=<?php echo $classId; ?>&subject_id=<?php echo (int)$s['id']; ?>">
            <b><?php echo h($s['name']); ?></b>
            <span><?php echo h($classRow['name']); ?> · <?php echo h($term['name']); ?></span><br>
            <span class="te-prog<?php echo $done ? '' : ' none'; ?>">
              <?php echo $done ? $done . ' student' . ($done === 1 ? '' : 's') . ' entered' : 'Not started'; ?>
            </span>
          </a>
        <?php } ?>
      </div>
    <?php } ?>

  <?php } else { /* -------- STEP 3: the score grid -------- */ ?>

    <div class="te-stats">
      <div class="te-stat"><b><?php echo $stats['active']; ?></b><span>Students</span></div>
      <div class="te-stat"><b><?php echo $stats['entered']; ?></b><span>Entered</span></div>
      <div class="te-stat"><b><?php echo $stats['published']; ?></b><span>Published (locked)</span></div>
      <div class="te-stat"><b><?php echo $stats['entered'] ? fmt($stats['avg']) : '—'; ?></b><span>Subject average</span></div>
    </div>

    <?php if (!$gridStudents) { ?>
      <div class="panel"><div class="empty">No students in <?php echo h($classRow['name']); ?> yet — the administrator adds them from <em>Students</em>.</div></div>
    <?php } else { ?>

    <form method="post" action="teacher.php" id="te-form">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
      <input type="hidden" name="subject_id" value="<?php echo $subjectId; ?>">

      <div class="panel">
        <div class="te-scroll">
          <table class="ptable te-grid" id="te-table"
                 data-l1="<?php echo fmt($L1); ?>" data-l2="<?php echo fmt($L2); ?>" data-l3="<?php echo fmt($L3); ?>">
            <thead>
              <tr>
                <th class="te-name">Student</th>
                <th>CA 1 <span style="opacity:.6;font-weight:400">/<?php echo fmt($L1); ?></span></th>
                <th>CA 2 <span style="opacity:.6;font-weight:400">/<?php echo fmt($L2); ?></span></th>
                <th>Exam <span style="opacity:.6;font-weight:400">/<?php echo fmt($L3); ?></span></th>
                <th>Total</th>
                <th>Grade</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($gridStudents as $stu) {
                $sid  = (int)$stu['id'];
                $row  = isset($gridExisting[$sid]) ? $gridExisting[$sid] : null;
                $pub  = $row && !empty($row['published']);
                $off  = $stu['status'] !== 'active';
                $dis  = $pub || $off;
                $a = $row ? fmt($row['ca1']) : '';
                $b = $row ? fmt($row['ca2']) : '';
                $e = $row ? fmt($row['exam']) : '';
            ?>
              <tr class="<?php echo $dis ? 'te-locked' : ''; ?>" data-row="<?php echo $sid; ?>">
                <td class="te-name">
                  <?php echo h($stu['full_name']); ?>
                  <span style="display:block;font-size:.72rem;opacity:.6"><?php echo h($stu['exam_no']); ?></span>
                </td>
                <td><input class="score-in" type="text" inputmode="decimal" name="ca1[<?php echo $sid; ?>]" value="<?php echo h($a); ?>" <?php echo $dis ? 'disabled' : ''; ?>></td>
                <td><input class="score-in" type="text" inputmode="decimal" name="ca2[<?php echo $sid; ?>]" value="<?php echo h($b); ?>" <?php echo $dis ? 'disabled' : ''; ?>></td>
                <td><input class="score-in" type="text" inputmode="decimal" name="ex[<?php echo $sid; ?>]"  value="<?php echo h($e); ?>" <?php echo $dis ? 'disabled' : ''; ?>></td>
                <td class="te-total" data-total><?php echo $row ? fmt((float)$row['ca1'] + (float)$row['ca2'] + (float)$row['exam']) : '—'; ?></td>
                <td data-grade>
                  <?php if ($row) { $gp = teachGrade((float)$row['ca1'] + (float)$row['ca2'] + (float)$row['exam']); ?>
                    <span class="pill <?php echo teachGradePill($gp[0]); ?>"><?php echo h($gp[0]); ?></span>
                  <?php } else { echo '—'; } ?>
                </td>
                <td>
                  <?php if ($off) { ?><span class="pill bad">Inactive</span>
                  <?php } elseif ($pub) { ?><span class="pill ok">Published 🔒</span>
                  <?php } elseif ($row) { ?><span class="pill gold">Draft</span>
                  <?php } else { ?><span style="opacity:.5">—</span><?php } ?>
                </td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>

        <div class="te-savebar">
          <span class="te-note">A row saves only when all three scores are filled and within range. Published rows are locked — the administrator unpublishes them if a correction is needed.</span>
          <button class="te-btn" type="submit">💾 Save Scores</button>
        </div>
      </div>
    </form>

    <?php } ?>
  <?php } ?>
<?php } ?>

</main>

<script>
(function () {
  var table = document.getElementById('te-table');
  if (!table) return;

  var L1 = parseFloat(table.getAttribute('data-l1')) || 20;
  var L2 = parseFloat(table.getAttribute('data-l2')) || 20;
  var L3 = parseFloat(table.getAttribute('data-l3')) || 60;

  function gradeFor(t) {
    if (t >= 70) return ['A', 'ok'];
    if (t >= 60) return ['B', 'ok'];
    if (t >= 50) return ['C', 'gold'];
    if (t >= 45) return ['D', 'warn'];
    if (t >= 40) return ['E', 'warn'];
    return ['F', 'bad'];
  }
  function fmt(n) {
    var s = n.toFixed(1);
    return s.slice(-2) === '.0' ? s.slice(0, -2) : s;
  }
  function num(v) {
    v = (v || '').replace(',', '.').trim();
    if (v === '' || isNaN(v)) return null;
    return parseFloat(v);
  }

  function refreshRow(tr) {
    var inputs = tr.querySelectorAll('input.score-in');
    if (inputs.length < 3) return;
    var a = num(inputs[0].value), b = num(inputs[1].value), e = num(inputs[2].value);
    var totalCell = tr.querySelector('[data-total]');
    var gradeCell = tr.querySelector('[data-grade]');
    if (!totalCell || !gradeCell) return;

    /* Out-of-range boxes get a red edge so mistakes are seen
       before saving. */
    var lims = [L1, L2, L3], vals = [a, b, e];
    for (var i = 0; i < 3; i++) {
      var bad = vals[i] !== null && (vals[i] < 0 || vals[i] > lims[i]);
      inputs[i].style.borderColor = bad ? '#c0392b' : '';
      inputs[i].style.background  = bad ? '#fdeceb' : '';
    }

    if (a === null || b === null || e === null) {
      if (a === null && b === null && e === null) { totalCell.textContent = '—'; gradeCell.innerHTML = '—'; }
      return; /* partially filled — leave whatever was there */
    }
    var t = a + b + e;
    totalCell.textContent = fmt(t);
    var g = gradeFor(t);
    gradeCell.innerHTML = '<span class="pill ' + g[1] + '">' + g[0] + '</span>';
  }

  table.addEventListener('input', function (ev) {
    var tr = ev.target.closest ? ev.target.closest('tr') : null;
    if (tr) refreshRow(tr);
  });

  /* Enter moves down the same column, like a mark book. */
  table.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    var inp = ev.target;
    if (!inp.classList || !inp.classList.contains('score-in')) return;
    ev.preventDefault();
    var tr = inp.closest('tr');
    var idx = Array.prototype.indexOf.call(tr.querySelectorAll('input.score-in'), inp);
    var next = tr.nextElementSibling;
    while (next) {
      var boxes = next.querySelectorAll('input.score-in:not(:disabled)');
      if (boxes.length > idx) { boxes[idx].focus(); boxes[idx].select(); return; }
      next = next.nextElementSibling;
    }
  });
})();
</script>

</body>
</html>
