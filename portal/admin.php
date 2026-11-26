<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — ADMIN DASHBOARD
   File: portal/admin.php

   The school administrator's control room:
   dashboard · students · teachers · terms · subjects ·
   publish results · PIN slips · settings · audit log ·
   change password.
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireAdmin();

$me   = currentUser();
$page = isset($_GET['page']) ? preg_replace('/[^a-z]/', '', $_GET['page']) : 'dashboard';

/* ---------------- flash + redirect helpers ---------------- */
function setFlash($type, $msg) { $_SESSION['flash'] = array($type, $msg); }
function getFlash() {
    if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}
function go($page, $extra = '') { header('Location: admin.php?page=' . $page . $extra); exit; }

/* ============================================================
   POST ACTIONS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    /* ---------- STUDENTS ---------- */
    if ($action === 'add_student') {
        $name   = trim(isset($_POST['full_name']) ? $_POST['full_name'] : '');
        $examNo = strtoupper(trim(isset($_POST['exam_no']) ? $_POST['exam_no'] : ''));
        $class  = (int)(isset($_POST['class_id']) ? $_POST['class_id'] : 0);
        if ($name === '' || $examNo === '' || $class < 1) {
            setFlash('bad', 'Name, exam number and class are all required.');
            go('students');
        }
        $dup = $pdo->prepare('SELECT id FROM students WHERE exam_no = ?');
        $dup->execute(array($examNo));
        if ($dup->fetch()) {
            setFlash('bad', 'A student with exam number ' . $examNo . ' already exists.');
            go('students');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO students (full_name, exam_no, class_id, status) VALUES (?, ?, ?, 'active')"
        );
        $stmt->execute(array($name, $examNo, $class));
        $newId = (int)$pdo->lastInsertId();
        audit($pdo, $me['id'], $me['name'], 'student_added', $name . ' (' . $examNo . ')');

        if (!empty($_POST['issue_pin'])) {
            $pin = issueStudentPin($pdo, $newId);
            $cname = $pdo->prepare('SELECT name FROM classes WHERE id = ?');
            $cname->execute(array($class));
            $cname = $cname->fetchColumn();
            $_SESSION['slips'] = array(array(
                'name' => $name, 'exam_no' => $examNo, 'pin' => $pin, 'class' => $cname
            ));
            go('pins');
        }
        setFlash('ok', 'Student "' . $name . '" registered. Issue a PIN when you are ready to print the slip.');
        go('students');
    }

    if ($action === 'update_student') {
        $id     = (int)$_POST['id'];
        $name   = trim($_POST['full_name']);
        $examNo = strtoupper(trim($_POST['exam_no']));
        $class  = (int)$_POST['class_id'];
        $dup = $pdo->prepare('SELECT id FROM students WHERE exam_no = ? AND id <> ?');
        $dup->execute(array($examNo, $id));
        if ($name === '' || $examNo === '' || $class < 1 || $dup->fetch()) {
            setFlash('bad', 'Check the details — the exam number may belong to another student.');
            go('students', '&edit=' . $id);
        }
        $pdo->prepare('UPDATE students SET full_name = ?, exam_no = ?, class_id = ? WHERE id = ?')
            ->execute(array($name, $examNo, $class, $id));
        audit($pdo, $me['id'], $me['name'], 'student_updated', $name . ' (' . $examNo . ')');
        setFlash('ok', 'Student record updated.');
        go('students');
    }

    if ($action === 'toggle_student') {
        $id = (int)$_POST['id'];
        $pdo->prepare(
            "UPDATE students SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?"
        )->execute(array($id));
        audit($pdo, $me['id'], $me['name'], 'student_status', 'Toggled status for student #' . $id);
        go('students');
    }

    if ($action === 'delete_student') {
        $id = (int)$_POST['id'];
        $has = $pdo->prepare('SELECT COUNT(*) FROM results WHERE student_id = ?');
        $has->execute(array($id));
        if ((int)$has->fetchColumn() > 0) {
            setFlash('bad', 'This student already has results on record and cannot be deleted. Deactivate the account instead.');
        } else {
            $pdo->prepare('DELETE FROM students WHERE id = ?')->execute(array($id));
            audit($pdo, $me['id'], $me['name'], 'student_deleted', 'Deleted student #' . $id);
            setFlash('ok', 'Student removed.');
        }
        go('students');
    }

    if ($action === 'issue_pin') {
        $id = (int)$_POST['id'];
        $st = $pdo->prepare('SELECT s.*, c.name AS class_name FROM students s
                             JOIN classes c ON c.id = s.class_id WHERE s.id = ?');
        $st->execute(array($id));
        $s = $st->fetch();
        if ($s) {
            $pin = issueStudentPin($pdo, $id);
            $_SESSION['slips'] = array(array(
                'name' => $s['full_name'], 'exam_no' => $s['exam_no'],
                'pin'  => $pin, 'class' => $s['class_name']
            ));
            go('pins');
        }
        go('students');
    }

    if ($action === 'issue_pins_class') {
        $classId = (int)$_POST['class_id'];
        $st = $pdo->prepare(
            "SELECT s.*, c.name AS class_name FROM students s
             JOIN classes c ON c.id = s.class_id
             WHERE s.class_id = ? AND s.status = 'active' ORDER BY s.full_name"
        );
        $st->execute(array($classId));
        $slips = array();
        foreach ($st->fetchAll() as $s) {
            $slips[] = array(
                'name' => $s['full_name'], 'exam_no' => $s['exam_no'],
                'pin'  => issueStudentPin($pdo, (int)$s['id']), 'class' => $s['class_name']
            );
        }
        if (!$slips) { setFlash('bad', 'No active students in that class.'); go('students'); }
        $_SESSION['slips'] = $slips;
        audit($pdo, $me['id'], $me['name'], 'pins_issued_class', count($slips) . ' PINs for class #' . $classId);
        go('pins');
    }

    /* ---------- TEACHERS ---------- */
    if ($action === 'add_teacher') {
        $name  = trim($_POST['full_name']);
        $uname = trim($_POST['username']);
        $pass  = trim(isset($_POST['password']) ? $_POST['password'] : '');
        if ($name === '' || $uname === '') { setFlash('bad', 'Name and username are required.'); go('teachers'); }
        $dup = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $dup->execute(array($uname));
        if ($dup->fetch()) { setFlash('bad', 'That username is already taken.'); go('teachers'); }
        if ($pass === '') $pass = generatePin();           // auto-generate, shown once
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, 'teacher')")
            ->execute(array($name, $uname, password_hash($pass, PASSWORD_DEFAULT)));
        audit($pdo, $me['id'], $me['name'], 'teacher_added', $name . ' (' . $uname . ')');
        setFlash('ok', 'Teacher account created for ' . $name . '. Username: ' . $uname .
                 ' — Password: ' . $pass . ' (write it down now; it will not be shown again).');
        go('teachers');
    }

    if ($action === 'reset_teacher') {
        $id = (int)$_POST['id'];
        $u = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
        $u->execute(array($id));
        $t = $u->fetch();
        if ($t) {
            $pass = generatePin();
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute(array(password_hash($pass, PASSWORD_DEFAULT), $id));
            audit($pdo, $me['id'], $me['name'], 'teacher_reset', 'Password reset for ' . $t['username']);
            setFlash('ok', 'New password for ' . $t['full_name'] . ': ' . $pass . ' (shown once — pass it to the teacher).');
        }
        go('teachers');
    }

    if ($action === 'delete_teacher') {
        $id = (int)$_POST['id'];
        $u = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $u->execute(array($id));
        $t = $u->fetch();
        if (!$t || $t['role'] === 'admin' || $id === (int)$me['id']) {
            setFlash('bad', 'That account cannot be deleted here.');
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute(array($id));
            audit($pdo, $me['id'], $me['name'], 'teacher_deleted', $t['full_name']);
            setFlash('ok', 'Teacher account removed.');
        }
        go('teachers');
    }

    /* ---------- TERMS ---------- */
    if ($action === 'add_term') {
        $name    = trim($_POST['name']);
        $session = trim($_POST['session']);
        if ($name === '' || $session === '') { setFlash('bad', 'Term name and session are required.'); go('terms'); }
        $pdo->prepare('INSERT INTO terms (name, session, is_active) VALUES (?, ?, 0)')
            ->execute(array($name, $session));
        audit($pdo, $me['id'], $me['name'], 'term_added', $name . ', ' . $session);
        setFlash('ok', 'Term added. Activate it when teachers should start entering scores for it.');
        go('terms');
    }

    if ($action === 'activate_term') {
        $id = (int)$_POST['id'];
        $pdo->exec('UPDATE terms SET is_active = 0');
        $pdo->prepare('UPDATE terms SET is_active = 1 WHERE id = ?')->execute(array($id));
        audit($pdo, $me['id'], $me['name'], 'term_activated', 'Term #' . $id . ' is now active');
        setFlash('ok', 'Active term switched. Teachers now enter scores against the new term.');
        go('terms');
    }

    if ($action === 'delete_term') {
        $id = (int)$_POST['id'];
        $has = $pdo->prepare('SELECT COUNT(*) FROM results WHERE term_id = ?');
        $has->execute(array($id));
        if ((int)$has->fetchColumn() > 0) {
            setFlash('bad', 'Results exist for this term — it cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM terms WHERE id = ?')->execute(array($id));
            setFlash('ok', 'Term removed.');
        }
        go('terms');
    }

    /* ---------- SUBJECTS ---------- */
    if ($action === 'add_subject') {
        $name = trim($_POST['name']);
        if ($name === '') { setFlash('bad', 'Subject name is required.'); go('subjects'); }
        $pdo->prepare('INSERT INTO subjects (name, is_active) VALUES (?, 1)')->execute(array($name));
        audit($pdo, $me['id'], $me['name'], 'subject_added', $name);
        setFlash('ok', 'Subject "' . $name . '" added.');
        go('subjects');
    }

    if ($action === 'toggle_subject') {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE subjects SET is_active = 1 - is_active WHERE id = ?')->execute(array($id));
        go('subjects');
    }

    if ($action === 'delete_subject') {
        $id = (int)$_POST['id'];
        $has = $pdo->prepare('SELECT COUNT(*) FROM results WHERE subject_id = ?');
        $has->execute(array($id));
        if ((int)$has->fetchColumn() > 0) {
            setFlash('bad', 'Scores exist for this subject — it cannot be deleted. You can deactivate it instead.');
        } else {
            $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute(array($id));
            setFlash('ok', 'Subject removed.');
        }
        go('subjects');
    }

    /* ---------- PUBLISH ---------- */
    if ($action === 'publish_class' || $action === 'unpublish_class') {
        $termId  = (int)$_POST['term_id'];
        $classId = (int)$_POST['class_id'];
        $val     = ($action === 'publish_class') ? 1 : 0;
        $stmt = $pdo->prepare(
            'UPDATE results r JOIN students s ON s.id = r.student_id
             SET r.published = ? WHERE r.term_id = ? AND s.class_id = ?'
        );
        $stmt->execute(array($val, $termId, $classId));
        $n = $stmt->rowCount();
        audit($pdo, $me['id'], $me['name'],
              $val ? 'results_published' : 'results_unpublished',
              $n . ' entries, class #' . $classId . ', term #' . $termId);
        setFlash($val ? 'ok' : 'warn',
                 ($val ? 'Published ' : 'Unpublished ') . $n . ' result entries.');
        go('publish', '&term_id=' . $termId);
    }

    if ($action === 'publish_all' || $action === 'unpublish_all') {
        $termId = (int)$_POST['term_id'];
        $val    = ($action === 'publish_all') ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE results SET published = ? WHERE term_id = ?');
        $stmt->execute(array($val, $termId));
        audit($pdo, $me['id'], $me['name'],
              $val ? 'results_published' : 'results_unpublished',
              $stmt->rowCount() . ' entries (whole school), term #' . $termId);
        setFlash($val ? 'ok' : 'warn',
                 ($val ? 'Published ' : 'Unpublished ') . $stmt->rowCount() . ' entries school-wide.');
        go('publish', '&term_id=' . $termId);
    }

    /* ---------- SETTINGS ---------- */
    if ($action === 'save_settings') {
        $ca1  = (float)$_POST['ca1_max'];
        $ca2  = (float)$_POST['ca2_max'];
        $exam = (float)$_POST['exam_max'];
        if (abs(($ca1 + $ca2 + $exam) - 100) > 0.001) {
            setFlash('bad', 'CA1 + CA2 + Exam must add up to exactly 100. Nothing was saved.');
            go('settings');
        }
        setSetting($pdo, 'school_name_1', trim($_POST['school_name_1']));
        setSetting($pdo, 'school_name_2', trim($_POST['school_name_2']));
        setSetting($pdo, 'ca1_max',  (string)$ca1);
        setSetting($pdo, 'ca2_max',  (string)$ca2);
        setSetting($pdo, 'exam_max', (string)$exam);
        setSetting($pdo, 'portal_open', !empty($_POST['portal_open']) ? '1' : '0');
        audit($pdo, $me['id'], $me['name'], 'settings_saved', 'Portal settings updated');
        setFlash('ok', 'Settings saved.');
        go('settings');
    }

    /* ---------- OWN PASSWORD ---------- */
    if ($action === 'change_password') {
        $err = changeStaffPassword(
            $pdo, (int)$me['id'],
            isset($_POST['old_pass']) ? $_POST['old_pass'] : '',
            isset($_POST['new_pass']) ? $_POST['new_pass'] : ''
        );
        setFlash($err ? 'bad' : 'ok', $err ? $err : 'Your password has been changed.');
        go('password');
    }

    go('dashboard');
}

/* ============================================================
   SHARED DATA FOR THE PAGE
   ============================================================ */
$school1 = getSetting($pdo, 'school_name_1', FALLBACK_SCHOOL_1);
$school2 = getSetting($pdo, 'school_name_2', FALLBACK_SCHOOL_2);
$term    = activeTerm($pdo);
$classes = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();
$flash   = getFlash();

/* ---------------- page furniture ---------------- */
function topbar($me, $page, $school1, $school2) {
    $links = array(
        'dashboard' => 'Dashboard',
        'students'  => 'Students',
        'teachers'  => 'Teachers',
        'publish'   => 'Publish',
        'pins'      => 'PIN Slips',
        'terms'     => 'Terms',
        'subjects'  => 'Subjects',
        'settings'  => 'Settings',
        'log'       => 'Audit Log',
        'password'  => 'Password'
    );
    echo '<div class="portal-top">'
       . '<a class="pt-brand" href="admin.php"><img src="../images/school-logo.png" alt="">'
       . '<span class="pt-name"><strong>' . h($school1) . '</strong><span>' . h($school2) . '</span></span></a>'
       . '<div class="pt-right">'
       . '<span class="pt-user">🛡️ ' . h($me['name']) . ' <em>Admin</em></span>'
       . '<a class="pt-logout" href="login.php?logout=1">Sign out</a>'
       . '</div></div>'
       . '<nav class="portal-nav">';
    foreach ($links as $key => $label) {
        echo '<a class="pn-link' . ($page === $key ? ' is-active' : '') . '" href="admin.php?page=' . $key . '">' . $label . '</a>';
    }
    echo '<a class="pn-link" href="admin_results.php">Results Overview →</a>';
    echo '<a class="pn-link" href="../index.html" target="_blank">View Website ↗</a>';
    echo '</nav>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin — <?php echo h($school1 . ' ' . $school2); ?> Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="portal.css">
</head>
<body>
<?php topbar($me, $page, $school1, $school2); ?>
<main class="portal-main">

<?php if ($flash): ?>
  <div class="flash <?php echo h($flash[0]); ?>"><?php echo h($flash[1]); ?></div>
<?php endif; ?>

<?php
/* ============================================================
   PAGE: DASHBOARD
   ============================================================ */
if ($page === 'dashboard'):
    $nStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
    $nTeachers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn();
    $nSubjects = (int)$pdo->query("SELECT COUNT(*) FROM subjects WHERE is_active=1")->fetchColumn();
    $nResults  = 0; $nPublished = 0;
    if ($term) {
        $q = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(published),0) FROM results WHERE term_id = ?');
        $q->execute(array($term['id']));
        list($nResults, $nPublished) = array_map('intval', $q->fetch(PDO::FETCH_NUM));
    }
    $portalOpen = getSetting($pdo, 'portal_open', '1') === '1';
    $recent = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 6')->fetchAll();
?>
  <div class="pm-head">
    <div>
      <h1>Welcome, <?php echo h(explode(' ', $me['name'])[0]); ?> 👋</h1>
      <p>Active term: <strong><?php echo $term ? h($term['name'] . ' · ' . $term['session']) : 'none set'; ?></strong>
         · Student portal: <span class="pill <?php echo $portalOpen ? 'ok' : 'bad'; ?>"><?php echo $portalOpen ? 'Open' : 'Closed'; ?></span></p>
    </div>
    <div class="pm-actions">
      <a class="btn btn-primary btn-sm" href="admin.php?page=students">+ Register Student</a>
      <a class="btn btn-gold btn-sm" href="admin.php?page=publish">Publish Results</a>
    </div>
  </div>

  <div class="tiles">
    <div class="tile"><span class="tile-num"><?php echo $nStudents; ?></span><span class="tile-label">Active Students</span></div>
    <div class="tile"><span class="tile-num"><?php echo $nTeachers; ?></span><span class="tile-label">Teachers</span></div>
    <div class="tile"><span class="tile-num"><?php echo $nSubjects; ?></span><span class="tile-label">Subjects</span></div>
    <div class="tile"><span class="tile-num"><?php echo $nResults; ?></span><span class="tile-label">Scores Entered (term)</span></div>
    <div class="tile"><span class="tile-num <?php echo $nPublished ? '' : 'warn'; ?>"><?php echo $nPublished; ?></span><span class="tile-label">Published</span></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Recent Activity</h2><a class="tact" href="admin.php?page=log">Full log →</a></div>
    <div class="panel-body flush">
      <?php if (!$recent): ?>
        <div class="empty"><span class="big">📭</span>No activity recorded yet.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="ptable">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="muted"><?php echo h($r['created_at']); ?></td>
            <td><?php echo h($r['user_name'] ?: '—'); ?></td>
            <td><span class="pill info"><?php echo h($r['action']); ?></span></td>
            <td class="muted"><?php echo h($r['detail']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: STUDENTS
   ============================================================ */
elseif ($page === 'students'):
    $filterClass = (int)(isset($_GET['class_id']) ? $_GET['class_id'] : 0);
    $q           = trim(isset($_GET['q']) ? $_GET['q'] : '');
    $editId      = (int)(isset($_GET['edit']) ? $_GET['edit'] : 0);

    $sql = 'SELECT s.*, c.name AS class_name,
            (SELECT COUNT(*) FROM results r WHERE r.student_id = s.id) AS n_results
            FROM students s JOIN classes c ON c.id = s.class_id WHERE 1=1';
    $args = array();
    if ($filterClass) { $sql .= ' AND s.class_id = ?'; $args[] = $filterClass; }
    if ($q !== '')    { $sql .= ' AND (s.full_name LIKE ? OR s.exam_no LIKE ?)'; $args[] = '%' . $q . '%'; $args[] = '%' . $q . '%'; }
    $sql .= ' ORDER BY c.id, s.full_name LIMIT 300';
    $st = $pdo->prepare($sql); $st->execute($args);
    $students = $st->fetchAll();

    $editStudent = null;
    if ($editId) {
        $e = $pdo->prepare('SELECT * FROM students WHERE id = ?');
        $e->execute(array($editId));
        $editStudent = $e->fetch();
    }
?>
  <div class="pm-head">
    <div><h1>Students</h1><p>Register students, print result-check PINs, manage accounts.</p></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2><?php echo $editStudent ? 'Edit Student' : 'Register a Student'; ?></h2></div>
    <div class="panel-body">
      <form method="post" class="pform">
        <input type="hidden" name="action" value="<?php echo $editStudent ? 'update_student' : 'add_student'; ?>">
        <?php if ($editStudent): ?><input type="hidden" name="id" value="<?php echo (int)$editStudent['id']; ?>"><?php endif; ?>
        <div class="fgrid fgrid-3">
          <div>
            <label>Full Name</label>
            <input type="text" name="full_name" required placeholder="e.g. Adaeze Okonkwo"
                   value="<?php echo h($editStudent ? $editStudent['full_name'] : ''); ?>">
          </div>
          <div>
            <label>Exam Number</label>
            <input type="text" name="exam_no" required placeholder="e.g. ITSS/2026/014"
                   value="<?php echo h($editStudent ? $editStudent['exam_no'] : ''); ?>">
          </div>
          <div>
            <label>Class</label>
            <select name="class_id" required>
              <option value="">— choose —</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>"
                  <?php echo ($editStudent && (int)$editStudent['class_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                  <?php echo h($c['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php if (!$editStudent): ?>
          <label class="fcheck"><input type="checkbox" name="issue_pin" value="1" checked>
            Issue a result PIN immediately (you'll be taken to the printable slip)</label>
        <?php endif; ?>
        <div class="form-foot">
          <button class="btn btn-primary" type="submit"><?php echo $editStudent ? 'Save Changes' : 'Register Student'; ?></button>
          <?php if ($editStudent): ?><a class="btn btn-outline" href="admin.php?page=students">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head">
      <h2>Class PINs</h2><span class="muted">Print slips for a whole class at once</span>
    </div>
    <div class="panel-body">
      <form method="post" class="row-flex"
            onsubmit="return confirm('Issue NEW PINs for every active student in this class? Old PINs stop working.');">
        <input type="hidden" name="action" value="issue_pins_class">
        <select name="class_id" required style="padding:.6rem .9rem;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;">
          <option value="">— choose class —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-gold btn-sm" type="submit">🖨️ Issue PINs for Whole Class</button>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head">
      <h2>All Students</h2>
      <form method="get" class="row-flex" style="gap:.4rem">
        <input type="hidden" name="page" value="students">
        <select name="class_id" style="padding:.5rem .7rem;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;">
          <option value="0">All classes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo $filterClass === (int)$c['id'] ? 'selected' : ''; ?>>
              <?php echo h($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" placeholder="Search name or exam no…"
               value="<?php echo h($q); ?>"
               style="padding:.5rem .8rem;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;">
        <button class="btn btn-outline btn-sm" type="submit">Filter</button>
      </form>
    </div>
    <div class="panel-body flush">
      <?php if (!$students): ?>
        <div class="empty"><span class="big">🎓</span>No students found. Register the first one above.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="ptable">
        <thead><tr>
          <th>Name</th><th>Exam No</th><th>Class</th><th class="c">Results</th>
          <th class="c">PIN</th><th class="c">Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td><strong class="nm"><?php echo h($s['full_name']); ?></strong></td>
            <td><?php echo h($s['exam_no']); ?></td>
            <td><?php echo h($s['class_name']); ?></td>
            <td class="c"><span class="pill info"><?php echo (int)$s['n_results']; ?></span></td>
            <td class="c">
              <?php if (!empty($s['pin_hash'])): ?>
                <span class="pill ok">Issued</span>
              <?php else: ?>
                <span class="pill warn">None</span>
              <?php endif; ?>
            </td>
            <td class="c">
              <span class="pill <?php echo $s['status'] === 'active' ? 'ok' : 'bad'; ?>">
                <?php echo h(ucfirst($s['status'])); ?>
              </span>
            </td>
            <td>
              <a class="tact" href="admin.php?page=students&edit=<?php echo (int)$s['id']; ?>">Edit</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="issue_pin">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <button class="tact gold" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Issue a NEW PIN for <?php echo h($s['full_name']); ?>? The old one stops working.');">
                  <?php echo empty($s['pin_hash']) ? 'Issue PIN' : 'New PIN'; ?>
                </button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_student">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <button class="tact" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;">
                  <?php echo $s['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                </button>
              </form>
              <?php if ((int)$s['n_results'] === 0): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete_student">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <button class="tact danger" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Delete <?php echo h($s['full_name']); ?> permanently?');">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: TEACHERS
   ============================================================ */
elseif ($page === 'teachers'):
    $teachers = $pdo->query("SELECT * FROM users WHERE role = 'teacher' ORDER BY full_name")->fetchAll();
?>
  <div class="pm-head">
    <div><h1>Teachers</h1><p>Create accounts so teachers can sign in and enter scores.</p></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Create a Teacher Account</h2></div>
    <div class="panel-body">
      <form method="post" class="pform">
        <input type="hidden" name="action" value="add_teacher">
        <div class="fgrid fgrid-3">
          <div>
            <label>Full Name</label>
            <input type="text" name="full_name" required placeholder="e.g. Mrs. Ngozi Amadi">
          </div>
          <div>
            <label>Username</label>
            <input type="text" name="username" required placeholder="e.g. namadi">
          </div>
          <div>
            <label>Password <span class="muted" style="text-transform:none">(blank = auto-generate)</span></label>
            <input type="text" name="password" placeholder="Leave blank to auto-generate">
          </div>
        </div>
        <div class="form-foot">
          <button class="btn btn-primary" type="submit">Create Account</button>
        </div>
        <p class="muted" style="font-size:.84rem;margin:.8rem 0 0">
          The password is shown <strong>once</strong> after creation — write it down and give it to the teacher.
        </p>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Teacher Accounts</h2></div>
    <div class="panel-body flush">
      <?php if (!$teachers): ?>
        <div class="empty"><span class="big">👩🏾‍🏫</span>No teacher accounts yet. Create the first one above.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="ptable">
        <thead><tr><th>Name</th><th>Username</th><th class="c">Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($teachers as $t): ?>
          <tr>
            <td><strong class="nm"><?php echo h($t['full_name']); ?></strong></td>
            <td><?php echo h($t['username']); ?></td>
            <td class="c muted"><?php echo h(isset($t['created_at']) ? substr($t['created_at'], 0, 10) : '—'); ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="reset_teacher">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="tact gold" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Reset the password for <?php echo h($t['full_name']); ?>?');">Reset Password</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete_teacher">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="tact danger" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Delete the account for <?php echo h($t['full_name']); ?>?');">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: TERMS
   ============================================================ */
elseif ($page === 'terms'):
    $terms = $pdo->query(
        'SELECT t.*, (SELECT COUNT(*) FROM results r WHERE r.term_id = t.id) AS n_results
         FROM terms t ORDER BY t.id DESC'
    )->fetchAll();
?>
  <div class="pm-head">
    <div><h1>Terms &amp; Sessions</h1><p>Only one term is active at a time — teachers enter scores against the active term.</p></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Add a Term</h2></div>
    <div class="panel-body">
      <form method="post" class="pform row-flex" style="align-items:flex-end">
        <input type="hidden" name="action" value="add_term">
        <div style="flex:1;min-width:160px">
          <label>Term Name</label>
          <input type="text" name="name" required placeholder="e.g. First Term">
        </div>
        <div style="flex:1;min-width:160px">
          <label>Session</label>
          <input type="text" name="session" required placeholder="e.g. 2026/2027">
        </div>
        <button class="btn btn-primary" type="submit" style="margin-bottom:.9rem">Add Term</button>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>All Terms</h2></div>
    <div class="panel-body flush">
      <?php if (!$terms): ?>
        <div class="empty"><span class="big">📅</span>No terms yet — did you import portal.sql? It seeds the 2026/2027 terms.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="ptable">
        <thead><tr><th>Term</th><th>Session</th><th class="c">Scores</th><th class="c">Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($terms as $t): ?>
          <tr>
            <td><strong class="nm"><?php echo h($t['name']); ?></strong></td>
            <td><?php echo h($t['session']); ?></td>
            <td class="c"><span class="pill info"><?php echo (int)$t['n_results']; ?></span></td>
            <td class="c">
              <?php if ($t['is_active']): ?>
                <span class="pill ok">Active</span>
              <?php else: ?>
                <span class="pill warn">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$t['is_active']): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="activate_term">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="tact" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Make <?php echo h($t['name'] . ' ' . $t['session']); ?> the ACTIVE term? Teachers will enter scores against it.');">Set Active</button>
              </form>
              <?php endif; ?>
              <?php if ((int)$t['n_results'] === 0): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete_term">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="tact danger" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Delete this term?');">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: SUBJECTS
   ============================================================ */
elseif ($page === 'subjects'):
    $subjects = $pdo->query(
        'SELECT s.*, (SELECT COUNT(*) FROM results r WHERE r.subject_id = s.id) AS n_results
         FROM subjects s ORDER BY s.name'
    )->fetchAll();
?>
  <div class="pm-head">
    <div><h1>Subjects</h1><p>The subjects teachers enter scores for. Inactive subjects are hidden from the entry grid.</p></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Add a Subject</h2></div>
    <div class="panel-body">
      <form method="post" class="pform row-flex" style="align-items:flex-end">
        <input type="hidden" name="action" value="add_subject">
        <div style="flex:1;min-width:200px">
          <label>Subject Name</label>
          <input type="text" name="name" required placeholder="e.g. Agricultural Science">
        </div>
        <button class="btn btn-primary" type="submit" style="margin-bottom:.9rem">Add Subject</button>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>All Subjects</h2></div>
    <div class="panel-body flush">
      <?php if (!$subjects): ?>
        <div class="empty"><span class="big">📚</span>No subjects — import portal.sql to seed the 14 UBE subjects.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="ptable">
        <thead><tr><th>Subject</th><th class="c">Scores on Record</th><th class="c">Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
          <tr>
            <td><strong class="nm"><?php echo h($s['name']); ?></strong></td>
            <td class="c"><span class="pill info"><?php echo (int)$s['n_results']; ?></span></td>
            <td class="c"><span class="pill <?php echo $s['is_active'] ? 'ok' : 'warn'; ?>"><?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_subject">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <button class="tact" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;">
                  <?php echo $s['is_active'] ? 'Deactivate' : 'Activate'; ?>
                </button>
              </form>
              <?php if ((int)$s['n_results'] === 0): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete_subject">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <button class="tact danger" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Delete this subject?');">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: PUBLISH
   ============================================================ */
elseif ($page === 'publish'):
    $viewTerm = (int)(isset($_GET['term_id']) ? $_GET['term_id'] : ($term ? $term['id'] : 0));
    $allTerms = $pdo->query('SELECT * FROM terms ORDER BY id DESC')->fetchAll();
    $stats = array();
    if ($viewTerm) {
        $st = $pdo->prepare(
            'SELECT c.id, c.name,
                    COUNT(DISTINCT s.id)                                        AS n_students,
                    COUNT(r.id)                                                 AS n_results,
                    COALESCE(SUM(r.published), 0)                               AS n_published
             FROM classes c
             LEFT JOIN students s ON s.class_id = c.id AND s.status = "active"
             LEFT JOIN results  r ON r.student_id = s.id AND r.term_id = ?
             GROUP BY c.id, c.name ORDER BY c.id'
        );
        $st->execute(array($viewTerm));
        $stats = $st->fetchAll();
    }
    $portalOpen = getSetting($pdo, 'portal_open', '1') === '1';
?>
  <div class="pm-head">
    <div>
      <h1>Publish Results</h1>
      <p>Scores stay invisible to parents until you publish them here. Student portal:
        <span class="pill <?php echo $portalOpen ? 'ok' : 'bad'; ?>"><?php echo $portalOpen ? 'Open' : 'Closed'; ?></span>
        <?php if (!$portalOpen): ?> — open it in <a href="admin.php?page=settings">Settings</a>.<?php endif; ?>
      </p>
    </div>
    <div class="pm-actions">
      <form method="get" class="row-flex" style="gap:.4rem">
        <input type="hidden" name="page" value="publish">
        <select name="term_id" onchange="this.form.submit()"
                style="padding:.5rem .8rem;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;">
          <?php foreach ($allTerms as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>" <?php echo $viewTerm === (int)$t['id'] ? 'selected' : ''; ?>>
              <?php echo h($t['name'] . ' · ' . $t['session']); ?><?php echo $t['is_active'] ? ' (active)' : ''; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>By Class</h2><span class="muted">Publish when a class's scores are complete and checked</span></div>
    <div class="panel-body flush">
      <div class="table-wrap"><table class="ptable">
        <thead><tr>
          <th>Class</th><th class="c">Students</th><th class="c">Scores Entered</th>
          <th class="c">Published</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($stats as $row):
            $allPub = ((int)$row['n_results'] > 0 && (int)$row['n_published'] === (int)$row['n_results']);
        ?>
          <tr>
            <td><strong class="nm"><?php echo h($row['name']); ?></strong></td>
            <td class="c"><?php echo (int)$row['n_students']; ?></td>
            <td class="c"><span class="pill info"><?php echo (int)$row['n_results']; ?></span></td>
            <td class="c">
              <span class="pill <?php echo $allPub ? 'ok' : ((int)$row['n_published'] ? 'gold' : 'warn'); ?>">
                <?php echo (int)$row['n_published']; ?><?php echo $allPub ? ' ✓' : ''; ?>
              </span>
            </td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="term_id" value="<?php echo $viewTerm; ?>">
                <input type="hidden" name="class_id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="publish_class">
                <button class="tact" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Publish ALL results for <?php echo h($row['name']); ?>? Parents will see them immediately.');">Publish</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="term_id" value="<?php echo $viewTerm; ?>">
                <input type="hidden" name="class_id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="unpublish_class">
                <button class="tact danger" type="submit" style="background:none;border:0;cursor:pointer;font-family:inherit;"
                        onclick="return confirm('Unpublish <?php echo h($row['name']); ?>? Parents will no longer see these results.');">Unpublish</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Whole School</h2></div>
    <div class="panel-body row-flex">
      <form method="post">
        <input type="hidden" name="term_id" value="<?php echo $viewTerm; ?>">
        <input type="hidden" name="action" value="publish_all">
        <button class="btn btn-primary" type="submit"
                onclick="return confirm('Publish EVERY result for this term, all classes?');">✅ Publish Entire Term</button>
      </form>
      <form method="post">
        <input type="hidden" name="term_id" value="<?php echo $viewTerm; ?>">
        <input type="hidden" name="action" value="unpublish_all">
        <button class="btn btn-danger" type="submit"
                onclick="return confirm('Unpublish EVERY result for this term? Parents will see nothing.');">Unpublish Entire Term</button>
      </form>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: PIN SLIPS (printable)
   ============================================================ */
elseif ($page === 'pins'):
    $slips = isset($_SESSION['slips']) ? $_SESSION['slips'] : array();
    unset($_SESSION['slips']);
?>
  <div class="pm-head no-print">
    <div><h1>Result-Check Slips</h1><p>Print, cut along the dashed lines, and give each student their slip.</p></div>
    <div class="pm-actions">
      <?php if ($slips): ?><button class="btn btn-primary" onclick="window.print()">🖨️ Print Slips</button><?php endif; ?>
      <a class="btn btn-outline" href="admin.php?page=students">← Back to Students</a>
    </div>
  </div>

  <?php if (!$slips): ?>
    <div class="panel"><div class="empty"><span class="big">🎟️</span>
      No slips to print. Issue a PIN from the Students page — for one student or a whole class — and the slips will appear here.
    </div></div>
  <?php else: ?>
    <div class="panel"><div class="panel-body">
      <?php foreach ($slips as $sl): ?>
        <div class="slip">
          <div>
            <div class="s-name"><?php echo h($sl['name']); ?> <span class="muted">· <?php echo h($sl['class']); ?></span></div>
            <div class="s-detail"><?php echo h($school1 . ' ' . $school2); ?> — Results Portal</div>
            <div class="s-detail">Exam No: <strong><?php echo h($sl['exam_no']); ?></strong></div>
          </div>
          <div style="text-align:right">
            <div class="s-detail">Result PIN</div>
            <div class="s-pin"><?php echo h($sl['pin']); ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <p class="muted" style="font-size:.82rem;margin-top:1rem">
        Keep PINs private — anyone with an exam number and its PIN can view that result.
        PINs are shown only on this page, this once; if lost, issue a new one from the Students page.
      </p>
    </div></div>
  <?php endif; ?>

<?php
/* ============================================================
   PAGE: SETTINGS
   ============================================================ */
elseif ($page === 'settings'):
    $L = scoreLimits($pdo);
    $portalOpen = getSetting($pdo, 'portal_open', '1') === '1';
?>
  <div class="pm-head">
    <div><h1>Portal Settings</h1><p>School identity, grading scheme and the student-portal switch.</p></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Edit Settings</h2></div>
    <div class="panel-body">
      <form method="post" class="pform">
        <input type="hidden" name="action" value="save_settings">
        <div class="fgrid">
          <div>
            <label>School Name — Line 1</label>
            <input type="text" name="school_name_1" value="<?php echo h(getSetting($pdo, 'school_name_1', FALLBACK_SCHOOL_1)); ?>" required>
          </div>
          <div>
            <label>School Name — Line 2</label>
            <input type="text" name="school_name_2" value="<?php echo h(getSetting($pdo, 'school_name_2', FALLBACK_SCHOOL_2)); ?>" required>
          </div>
        </div>
        <hr class="hr">
        <div class="fgrid fgrid-3">
          <div>
            <label>CA1 Maximum</label>
            <input type="number" name="ca1_max" step="0.5" min="0" max="100" value="<?php echo h($L['ca1']); ?>" required>
          </div>
          <div>
            <label>CA2 Maximum</label>
            <input type="number" name="ca2_max" step="0.5" min="0" max="100" value="<?php echo h($L['ca2']); ?>" required>
          </div>
          <div>
            <label>Exam Maximum</label>
            <input type="number" name="exam_max" step="0.5" min="0" max="100" value="<?php echo h($L['exam']); ?>" required>
          </div>
        </div>
        <p class="muted" style="font-size:.84rem">The three maximums must add up to <strong>exactly 100</strong> — e.g. 20 + 20 + 60.</p>
        <hr class="hr">
        <label class="fcheck">
          <input type="checkbox" name="portal_open" value="1" <?php echo $portalOpen ? 'checked' : ''; ?>>
          Student portal is OPEN — untick to close result checking for all students and parents
        </label>
        <div class="form-foot">
          <button class="btn btn-primary" type="submit">Save Settings</button>
        </div>
      </form>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: AUDIT LOG
   ============================================================ */
elseif ($page === 'log'):
    $log = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 200')->fetchAll();
?>
  <div class="pm-head">
    <div><h1>Audit Log</h1><p>Every important action on the portal, newest first (last 200).</p></div>
  </div>
  <div class="panel">
    <div class="panel-body flush">
      <?php if (!$log): ?>
        <div class="empty"><span class="big">📭</span>Nothing recorded yet.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="ptable">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($log as $r): ?>
          <tr>
            <td class="muted" style="white-space:nowrap"><?php echo h($r['created_at']); ?></td>
            <td><?php echo h($r['user_name'] ?: '—'); ?></td>
            <td><span class="pill info"><?php echo h($r['action']); ?></span></td>
            <td class="muted"><?php echo h($r['detail']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

<?php
/* ============================================================
   PAGE: CHANGE PASSWORD
   ============================================================ */
elseif ($page === 'password'):
?>
  <div class="pm-head">
    <div><h1>Change My Password</h1><p>Minimum 8 characters. You stay signed in on this device.</p></div>
  </div>
  <div class="panel" style="max-width:520px">
    <div class="panel-body">
      <form method="post" class="pform">
        <input type="hidden" name="action" value="change_password">
        <label>Current Password</label>
        <input type="password" name="old_pass" required autocomplete="current-password">
        <label>New Password</label>
        <input type="password" name="new_pass" required minlength="8" autocomplete="new-password">
        <div class="form-foot">
          <button class="btn btn-primary" type="submit">Change Password</button>
        </div>
      </form>
      <p class="muted" style="font-size:.84rem;margin-top:1rem">
        If this is your first sign-in and you are still using <strong>admin123</strong>, change it now.
      </p>
    </div>
  </div>

<?php endif; ?>
</main>

<script>
  // hide missing logo gracefully
  document.querySelectorAll('.pt-brand img').forEach(function (img) {
    img.addEventListener('error', function () { img.style.display = 'none'; });
  });
</script>
</body>
</html>
