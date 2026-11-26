<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — RESULTS OVERVIEW
   File: portal/admin_results.php

   The administrator's window into results, before and after
   publishing:
     · Master Sheet     — every student × every subject, totals,
                          averages, positions, publish status
     · Subject Analysis — highest, lowest, average, pass rate
                          and grade spread per subject
     · Student Sheet    — preview/print exactly what a parent
                          will see, before results go live

   READ-ONLY: nothing on this page changes any data.
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

$me = currentUser();

/* ---------------- small local helpers ---------------- */

/* Total for one result row: CA1 + CA2 + Exam (out of 100). */
function resultTotal($r) {
    return (float)$r['ca1'] + (float)$r['ca2'] + (float)$r['exam'];
}

/* Grade + remark for a /100 total. Uses config.php's gradeFor()
   whatever its exact return shape, with the portal scheme as a
   fallback so this page can never disagree with itself.        */
function gradePair($total) {
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

/* Pill colour for a grade letter. */
function gradePill($grade) {
    if ($grade === 'A' || $grade === 'B') return 'ok';
    if ($grade === 'C') return 'gold';
    if ($grade === 'D' || $grade === 'E') return 'warn';
    return 'bad';
}

/* "1st", "2nd", "3rd"… — prefers config.php's ordinal(). */
function posLabel($n) {
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

/* ---------------- shared data ---------------- */
$school1 = getSetting($pdo, 'school_name_1', FALLBACK_SCHOOL_1);
$school2 = getSetting($pdo, 'school_name_2', FALLBACK_SCHOOL_2);
$L       = scoreLimits($pdo);
$term    = activeTerm($pdo);
$allTerms  = $pdo->query('SELECT * FROM terms ORDER BY id DESC')->fetchAll();
$classes   = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();
$actSubs   = $pdo->query('SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name')->fetchAll();

/* ---------------- choose term / class / view ---------------- */
$viewTerm = (int)(isset($_GET['term_id']) ? $_GET['term_id'] : 0);
if (!$viewTerm) $viewTerm = $term ? (int)$term['id'] : ($allTerms ? (int)$allTerms[0]['id'] : 0);
$termRow = null;
foreach ($allTerms as $t) if ((int)$t['id'] === $viewTerm) $termRow = $t;

$viewClass = (int)(isset($_GET['class_id']) ? $_GET['class_id'] : 0);
if (!$viewClass && $classes) $viewClass = (int)$classes[0]['id'];

$view  = (isset($_GET['view']) && $_GET['view'] === 'analysis') ? 'analysis' : 'master';
$error = '';

/* Student-sheet preview mode: the student's own class wins. */
$sheetStudent = null;
if (isset($_GET['student'])) {
    $q = $pdo->prepare(
        'SELECT s.*, c.name AS class_name FROM students s
         JOIN classes c ON c.id = s.class_id WHERE s.id = ?'
    );
    $q->execute(array((int)$_GET['student']));
    $sheetStudent = $q->fetch();
    if ($sheetStudent) {
        $viewClass = (int)$sheetStudent['class_id'];
        $view = 'sheet';
    } else {
        $error = 'That student could not be found.';
    }
}
$classRow = null;
foreach ($classes as $c) if ((int)$c['id'] === $viewClass) $classRow = $c;

/* ---------------- load the class + its results ----------------
   Used by every view: master, analysis, and the sheet's
   position-in-class calculation.                               */
$students = array();
if ($viewClass) {
    $st = $pdo->prepare(
        'SELECT id, full_name, exam_no, status FROM students
         WHERE class_id = ? ORDER BY full_name'
    );
    $st->execute(array($viewClass));
    $students = $st->fetchAll();
}

$resultRows = array();
if ($viewClass && $viewTerm) {
    $rq = $pdo->prepare(
        'SELECT r.*, sub.name AS subject_name, s.full_name AS student_name
         FROM results r
         JOIN subjects sub ON sub.id = r.subject_id
         JOIN students  s   ON s.id = r.student_id
         WHERE r.term_id = ? AND s.class_id = ?'
    );
    $rq->execute(array($viewTerm, $viewClass));
    $resultRows = $rq->fetchAll();
}

/* Map: student id → subject id → result row */
$byStudent = array();
foreach ($resultRows as $r) {
    $byStudent[(int)$r['student_id']][(int)$r['subject_id']] = $r;
}

/* Subject columns: all active subjects, plus any subject that has
   scores here even if it was later deactivated.                */
$subjectCols = array();
foreach ($actSubs as $a) $subjectCols[(int)$a['id']] = $a['name'];
foreach ($resultRows as $r) {
    if (!isset($subjectCols[(int)$r['subject_id']])) {
        $subjectCols[(int)$r['subject_id']] = $r['subject_name'];
    }
}

/* Totals, averages and positions (standard ranking: 1,2,2,4). */
$totals = array(); $counts = array(); $pubs = array();
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $entries = isset($byStudent[$sid]) ? $byStudent[$sid] : array();
    $sum = 0; $pub = 0;
    foreach ($entries as $r) { $sum += resultTotal($r); if ($r['published']) $pub++; }
    $counts[$sid] = count($entries);
    $pubs[$sid]   = $pub;
    if ($entries) $totals[$sid] = $sum;
}
arsort($totals);
$positions = array(); $i = 0; $pos = 0; $prev = null;
foreach ($totals as $sid => $t) {
    $i++;
    if ($prev === null || $t < $prev) { $pos = $i; $prev = $t; }
    $positions[$sid] = $pos;
}

/* Class-level headline numbers */
$nWithScores = count($totals);
$sumAvgs = 0;
foreach ($totals as $sid => $t) $sumAvgs += $counts[$sid] ? $t / $counts[$sid] : 0;
$classAvg  = $nWithScores ? $sumAvgs / $nWithScores : 0;
$nEntries  = array_sum($counts);
$nPub      = array_sum($pubs);
$pubPct    = $nEntries ? round($nPub / $nEntries * 100) : 0;

/* ---------------- page furniture ---------------- */
function roTopbar($me, $school1, $school2) {
    echo '<div class="portal-top">'
       . '<a class="pt-brand" href="admin.php"><img src="../images/school-logo.png" alt="">'
       . '<span class="pt-name"><strong>' . h($school1) . '</strong><span>' . h($school2) . '</span></span></a>'
       . '<div class="pt-right">'
       . '<span class="pt-user">🛡️ ' . h($me['name']) . ' <em>Admin</em></span>'
       . '<a class="pt-logout" href="login.php?logout=1">Sign out</a>'
       . '</div></div>'
       . '<nav class="portal-nav">'
       . '<a class="pn-link" href="admin.php">← Dashboard</a>'
       . '<a class="pn-link" href="admin.php?page=students">Students</a>'
       . '<a class="pn-link" href="admin.php?page=publish">Publish Results</a>'
       . '<a class="pn-link" href="../index.html" target="_blank">View Website ↗</a>'
       . '</nav>';
}

$selfQS = 'term_id=' . $viewTerm . '&class_id=' . $viewClass;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Results Overview — <?php echo h($school1 . ' ' . $school2); ?> Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="portal.css">
</head>
<body>
<?php roTopbar($me, $school1, $school2); ?>
<main class="portal-main">

<?php if ($error`!== '') { ?>`
