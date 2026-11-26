<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — STUDENT RESULT SHEET
   File: portal/student.php

   What a student / parent sees after signing in with the
   exam number and PIN:
     · Their Terminal Report Sheet for a chosen term
     · ONLY published results — drafts never appear here
     · CA 1, CA 2, Exam, Total, Grade and Remark per subject
     · Overall total, average, grade and position in class
       (position is computed from published scores only)
     · A print-friendly layout with signature lines

   READ-ONLY: nothing on this page can change any data.
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireStudent();

if (!defined('FALLBACK_SCHOOL_1')) define('FALLBACK_SCHOOL_1', 'Iriebe Town Secondary School');
if (!defined('FALLBACK_SCHOOL_2')) define('FALLBACK_SCHOOL_2', '(UBE), Port Harcourt');

/* ---------------- small local helpers ---------------- */

/* Grade + remark for a /100 total — uses config's gradeFor()
   whatever its shape, with the portal scheme as fallback.      */
function stuGrade($total) {
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

function stuGradePill($grade) {
    if ($grade === 'A' || $grade === 'B') return 'ok';
    if ($grade === 'C') return 'gold';
    if ($grade === 'D' || $grade === 'E') return 'warn';
    return 'bad';
}

/* "1st", "2nd", "3rd"… — prefers config's ordinal(). */
function stuPos($n) {
    if (function_exists('ordinal')) return ordinal($n);
    $suffix = 'th';
    $h = $n % 100;
    if ($h < 11 || $h > 13) {
        if ($n % 10 === 1) $suffix = 'st';
        elseif ($n % 10 === 2) $suffix = 'nd';
        elseif ($n % 10 === 3) $suffix = 'rd';
    }
    return $n . $suffix;
}

/* Trim a trailing ".0" so 70.0 shows as 70 but 70.5 stays. */
function fmt($n) {
    $s = number_format((float)$n, 1);
    return substr($s, -2) === '.0' ? substr($s, 0, -2) : $s;
}

/* Read the CA weights for the column headers (20/20/60). */
function stuLimits($pdo) {
    $L = function_exists('scoreLimits') ? scoreLimits($pdo) : array();
    $ca1 = 20; $ca2 = 20; $ex = 60;
    if (is_array($L)) {
        if (isset($L['ca1']))      $ca1 = (float)$L['ca1'];
        if (isset($L['ca2']))      $ca2 = (float)$L['ca2'];
        if (isset($L['exam']))     $ex  = (float)$L['exam'];
        if (isset($L['ca1_max']))  $ca1 = (float)$L['ca1_max'];
        if (isset($L['ca2_max']))  $ca2 = (float)$L['ca2_max'];
        if (isset($L['exam_max'])) $ex  = (float)$L['exam_max'];
    }
    return array($ca1, $ca2, $ex);
}

/* ---------------- who is signed in? ----------------
   Resolve the student record however auth.php stored it —
   a currentStudent() helper, a session id, or a session
   array — then always re-read fresh from the database.     */
$student = null;
$sid = 0;
if (function_exists('currentStudent')) {
    $s = currentStudent();
    if (is_array($s) && !empty($s['id'])) $sid = (int)$s['id'];
}
if (!$sid && isset($_SESSION['student_id'])) {
    $sid = (int)$_SESSION['student_id'];
}
if (!$sid && isset($_SESSION['student']) && is_array($_SESSION['student']) && !empty($_SESSION['student']['id'])) {
    $sid = (int)$_SESSION['student']['id'];
}
if (!$sid && function_exists('currentUser')) {
    $u = currentUser();
    if (is_array($u) && isset($u['role']) && $u['role'] === 'student' && !empty($u['id'])) {
        $sid = (int)$u['id'];
    }
}
if ($sid) {
    $q = $pdo->prepare(
        'SELECT s.*, c.name AS class_name FROM students s
         JOIN classes c ON c.id = s.class_id WHERE s.id = ?'
    );
    $q->execute(array($sid));
    $student = $q->fetch();
}
if (!$student) {
    header('Location: login.php?logout=1');
    exit;
}

/* ---------------- shared data ---------------- */
$school1 = getSetting($pdo, 'school_name_1', FALLBACK_SCHOOL_1);
$school2 = getSetting($pdo, 'school_name_2', FALLBACK_SCHOOL_2);
list($L1, $L2, $L3) = stuLimits($pdo);
$portalOpen = getSetting($pdo, 'portal_open', '1');
$portalOpen = !($portalOpen === '0' || $portalOpen === 0 || $portalOpen === false);

/* Terms in which this student has at least one PUBLISHED result. */
$termRows = array();
$tq = $pdo->prepare(
    'SELECT DISTINCT t.id, t.name, t.is_active FROM results r
     JOIN terms t ON t.id = r.term_id
     WHERE r.student_id = ? AND r.published = 1
     ORDER BY t.id DESC'
);
$tq->execute(array((int)$student['id']));
$termRows = $tq->fetchAll();

/* Pick the term: the requested one if allowed, else the active
   term if it has published results, else the newest that does. */
$viewTerm = (int)(isset($_GET['term_id']) ? $_GET['term_id'] : 0);
$termRow  = null;
if ($viewTerm) {
    foreach ($termRows as $t) if ((int)$t['id'] === $viewTerm) $termRow = $t;
}
if (!$termRow) {
    foreach ($termRows as $t) if (!empty($t['is_active'])) { $termRow = $t; break; }
}
if (!$termRow && $termRows) $termRow = $termRows[0];

/* ---------------- published results for the sheet ---------------- */
$results = array();
$pending = 0;   // subjects entered but not yet published
$total = 0; $count = 0;

if ($termRow && $portalOpen) {
    $rq = $pdo->prepare(
        'SELECT r.*, sub.name AS subject_name FROM results r
         JOIN subjects sub ON sub.id = r.subject_id
         WHERE r.student_id = ? AND r.term_id = ? AND r.published = 1
         ORDER BY sub.name'
    );
    $rq->execute(array((int)$student['id'], (int)$termRow['id']));
    $results = $rq->fetchAll();

    $pq = $pdo->prepare(
        'SELECT COUNT(*) FROM results
         WHERE student_id = ? AND term_id = ? AND published = 0'
    );
    $pq->execute(array((int)$student['id'], (int)$termRow['id']));
    $pending = (int)$pq->fetchColumn();

    foreach ($results as $r) {
        $total += (float)$r['ca1'] + (float)$r['ca2'] + (float)$r['exam'];
        $count++;
    }
}
$average  = $count ? $total / $count : 0;
$maxTotal = $count * 100;
$overall  = $count ? stuGrade($average) : array('', '');

/* ------------- position in class (published only) -------------
   Every student in the class is ranked by the sum of their
   PUBLISHED totals for this term — standard ranking 1,2,2,4.   */
$position = 0; $ranked = 0;
if ($termRow && $portalOpen && $count) {
    $cq = $pdo->prepare(
        'SELECT s.id, COALESCE(SUM(r.ca1 + r.ca2 + r.exam), 0) AS tot,
                COUNT(r.id) AS n
         FROM students s
         LEFT JOIN results r
           ON r.student_id = s.id AND r.term_id = ? AND r.published = 1
         WHERE s.class_id = ?
         GROUP BY s.id
         HAVING n > 0'
    );
    $cq->execute(array((int)$termRow['id'], (int)$student['class_id']));
    $totals = array();
    foreach ($cq->fetchAll() as $row) $totals[(int)$row['id']] = (float)$row['tot'];
    arsort($totals);
    $ranked = count($totals);
    $i = 0; $pos = 0; $prev = null;
    foreach ($totals as $id => $t) {
        $i++;
        if ($prev === null || $t < $prev) { $pos = $i; $prev = $t; }
        if ($id === (int)$student['id']) { $position = $pos; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Result — <?php echo h($school1 . ' ' . $school2); ?> Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="portal.css">
</head>
<body>
<div class="portal-top">
  <a class="pt-brand" href="student.php"><img src="../images/school-logo.png" alt="">
    <span class="pt-name"><strong><?php echo h($school1); ?></strong><span><?php echo h($school2); ?></span></span></a>
  <div class="pt-right">
    <span class="pt-user">🎓 <?php echo h($student['full_name']); ?> <em>Student</em></span>
    <a class="pt-logout" href="login.php?logout=1">Sign out</a>
  </div>
</div>
<nav class="portal-nav">
  <a class="pn-link on" href="student.php">📄 My Result</a>
  <a class="pn-link" href="../index.html" target="_blank">School Website ↗</a>
</nav>

<main class="portal-main">

<style>
  /* Furniture for the student sheet (st- prefix — no clashes
     with portal.css or the staff pages). */
  .st-head { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; justify-content:space-between; margin:18px 0 14px; }
  .st-head h1 { font-size:1.3rem; margin:0; }
  .st-head p  { margin:2px 0 0; opacity:.75; font-size:.9rem; }
  .st-filters { display:flex; gap:10px; align-items:center; }
  .st-filters select { padding:9px 12px; border:1px solid #cfd8d2; border-radius:10px; font:inherit; background:#fff; }
  .st-actions { display:flex; gap:10px; justify-content:center; margin:0 0 18px; flex-wrap:wrap; }
  .st-btn { padding:10px 22px; border-radius:10px; border:1px solid #0f6b3f; background:#0f6b3f; color:#fff; font:inherit; cursor:pointer; text-decoration:none; }
  .st-btn.ghost { background:#fff; color:#0f6b3f; }
  .st-wrap { max-width:820px; margin:0 auto; }
  .st-sheethead { text-align:center; margin-bottom:14px; }
  .st-sheethead img { height:68px; }
  .st-sheethead h2 { margin:6px 0 0; font-size:1.2rem; }
  .st-sheethead p { margin:2px 0; font-size:.85rem; opacity:.8; }
  .st-meta { display:flex; flex-wrap:wrap; gap:6px 26px; font-size:.92rem; margin:12px 0; }
  .st-meta b { font-weight:700; }
  .st-table th, .st-table td { text-align:center; }
  .st-table .st-subj { text-align:left; }
  .st-key { font-size:.75rem; opacity:.75; margin-top:10px; }
  .st-sig { display:flex; justify-content:space-between; gap:20px; margin-top:56px; }
  .st-sig div { flex:1; text-align:center; font-size:.82rem; }
  .st-sig div i { display:block; border-top:1px solid #333; margin-bottom:5px; }
  .st-foot { text-align:center; font-size:.72rem; opacity:.6; margin-top:18px; }
  .st-closed { text-align:center; padding:44px 20px; }
  .st-closed .st-big { font-size:2.4rem; }
  @media print {
    .portal-top, .portal-nav, .st-head, .st-actions, .st-filters { display:none !important; }
    .portal-main { padding:0 !important; }
    body { background:#fff; }
  }
</style>

<?php if (!$portalOpen) { ?>

  <div class="panel st-closed">
    <div class="st-big">🔒</div>
    <h1>Results are not available right now</h1>
    <p style="opacity:.75">The result portal is closed at the moment. Please check back later, or contact the school office.</p>
  </div>

<?php } elseif (!$termRow) { ?>

  <div class="panel st-closed">
    <div class="st-big">📭</div>
    <h1>No results yet</h1>
    <p style="opacity:.75">Your results have not been published yet. Once the school publishes them, sign in again with your exam number and PIN to view your report sheet.</p>
  </div>

<?php } else { ?>

  <div class="st-head">
    <div>
      <h1>📄 My Result Sheet</h1>
      <p><?php echo h($termRow['name']); ?> · <?php echo h($student['class_name']); ?></p>
    </div>
    <?php if (count($termRows) > 1) { ?>
    <form class="st-filters" method="get" action="student.php">
      <select name="term_id" onchange="this.form.submit()" aria-label="Term">
        <?php foreach ($termRows as $t) { ?>
          <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$t['id'] === (int)$termRow['id']) ? 'selected' : ''; ?>>
            <?php echo h($t['name']); ?><?php echo !empty($t['is_active']) ? ' (Current)' : ''; ?>
          </option>
        <?php } ?>
      </select>
    </form>
    <?php } ?>
  </div>

  <div class="st-actions">
    <button class="st-btn" type="button" onclick="window.print()">🖨️ Print Result</button>
  </div>

  <div class="st-wrap">
    <div class="sheet">
      <div class="st-sheethead">
        <img src="../images/school-logo.png" alt="School logo">
        <h2><?php echo h($school1 . ' ' . $school2); ?></h2>
        <p>TERMINAL REPORT SHEET — <?php echo h($termRow['name']); ?></p>
      </div>

      <div class="st-meta">
        <span>Name: <b><?php echo h($student['full_name']); ?></b></span>
        <span>Exam No: <b><?php echo h($student['exam_no']); ?></b></span>
        <span>Class: <b><?php echo h($student['class_name']); ?></b></span>
        <span>Position: <b><?php echo $position ? stuPos($position) . ' of ' . $ranked : '—'; ?></b></span>
      </div>

      <table class="ptable st-table">
        <thead>
          <tr>
            <th class="st-subj">Subject</th>
            <th>CA 1 <span style="opacity:.6;font-weight:400">/<?php echo fmt($L1); ?></span></th>
            <th>CA 2 <span style="opacity:.6;font-weight:400">/<?php echo fmt($L2); ?></span></th>
            <th>Exam <span style="opacity:.6;font-weight:400">/<?php echo fmt($L3); ?></span></th>
            <th>Total (100)</th>
            <th>Grade</th>
            <th>Remark</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r) {
            $t  = (float)$r['ca1'] + (float)$r['ca2'] + (float)$r['exam'];
            $gp = stuGrade($t); ?>
          <tr>
            <td class="st-subj"><?php echo h($r['subject_name']); ?></td>
            <td><?php echo fmt($r['ca1']); ?></td>
            <td><?php echo fmt($r['ca2']); ?></td>
            <td><?php echo fmt($r['exam']); ?></td>
            <td><strong><?php echo fmt($t); ?></strong></td>
            <td><span class="pill <?php echo stuGradePill($gp[0]); ?>"><?php echo h($gp[0]); ?></span></td>
            <td><?php echo h($gp[1]); ?></td>
          </tr>
        <?php } ?>
          <tr>
            <td class="st-subj"><strong>Overall</strong></td>
            <td colspan="3"></td>
            <td><strong><?php echo fmt($total); ?><?php echo $maxTotal ? ' / ' . $maxTotal : ''; ?></strong></td>
            <td><span class="pill <?php echo stuGradePill($overall[0]); ?>"><?php echo h($overall[0]); ?></span></td>
            <td><strong>Average: <?php echo fmt($average); ?>%</strong></td>
          </tr>
        </tbody>
      </table>

      <?php if ($pending > 0) { ?>
        <p class="st-key">⏳ <?php echo $pending; ?> more subject<?php echo $pending === 1 ? ' is' : 's are'; ?> still being processed and will appear here once published.</p>
      <?php } ?>

      <p class="st-key">Grading: A = 70–100 (Excellent) · B = 60–69 (Very Good) · C = 50–59 (Credit) · D = 45–49 (Merit) · E = 40–44 (Pass) · F = Below 40 (Fail). CA 1 + CA 2 + Exam = 100.</p>

      <div class="st-sig">
        <div><i></i>Class Teacher</div>
        <div><i></i>Principal</div>
        <div><i></i>Date</div>
      </div>

      <p class="st-foot">Printed from the <?php echo h($school1); ?> result portal on <?php echo date('j M Y'); ?>.</p>
    </div>
  </div>

<?php } ?>

</main>
</body>
</html>
