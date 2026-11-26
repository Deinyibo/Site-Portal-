<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — PORTAL LOGIN
   File: portal/login.php
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/* Already signed in? Go straight to the right dashboard. */
if (isAdmin())        { header('Location: admin.php');   exit; }
if (isTeacher())      { header('Location: teacher.php'); exit; }
if (isStudent())      { header('Location: student.php'); exit; }

/* Logout link (?logout=1) */
if (isset($_GET['logout'])) {
    portalLogout($pdo);
    header('Location: login.php');
    exit;
}

$error    = '';
$tab      = 'student';   // which tab is shown first / reopened after error
$loggedOut = isset($_GET['bye']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = (isset($_POST['tab']) && $_POST['tab'] === 'staff') ? 'staff' : 'student';
    if ($tab === 'staff') {
        $error = attemptStaffLogin(
            $pdo,
            isset($_POST['username']) ? $_POST['username'] : '',
            isset($_POST['password']) ? $_POST['password'] : ''
        );
        if ($error === null) {
            header('Location: ' . (isAdmin() ? 'admin.php' : 'teacher.php'));
            exit;
        }
    } else {
        $error = attemptStudentLogin(
            $pdo,
            isset($_POST['exam_no']) ? $_POST['exam_no'] : '',
            isset($_POST['pin'])     ? $_POST['pin']     : ''
        );
        if ($error === null) {
            header('Location: student.php');
            exit;
        }
    }
}

$schoolName = schoolName($pdo);
$school1 = getSetting($pdo, 'school_name_1', FALLBACK_SCHOOL_1);
$school2 = getSetting($pdo, 'school_name_2', FALLBACK_SCHOOL_2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal Login — <?php echo h($schoolName); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../styles.css">
  <link rel="stylesheet" href="portal.css">
</head>
<body class="portal-body">

  <div class="login-wrap">

    <div class="login-card">
      <!-- Brand -->
      <a class="login-brand" href="../index.html">
        <img src="../images/school-logo.png" alt="School logo" data-fallback>
        <span class="login-school">
          <strong><?php echo h($school1); ?></strong>
          <span><?php echo h($school2); ?></span>
        </span>
      </a>
      <h1 class="login-title">Results Portal</h1>
      <p class="login-sub">Sign in to continue</p>

      <!-- Tabs -->
      <div class="login-tabs" role="tablist">
        <button type="button" id="tabBtnStudent"
                class="login-tab <?php echo $tab === 'student' ? 'is-active' : ''; ?>"
                onclick="showTab('student')">🎓 Students &amp; Parents</button>
        <button type="button" id="tabBtnStaff"
                class="login-tab <?php echo $tab === 'staff' ? 'is-active' : ''; ?>"
                onclick="showTab('staff')">👩🏾‍🏫 Staff</button>
      </div>

      <?php if ($loggedOut): ?>
        <p class="login-note ok">You have been signed out safely.</p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="login-note bad"><?php echo h($error); ?></p>
      <?php endif; ?>

      <!-- STUDENT / PARENT FORM -->
      <form method="post" id="formStudent"
            class="login-form <?php echo $tab === 'student' ? 'is-active' : ''; ?>">
        <input type="hidden" name="tab" value="student">
        <label for="exam_no">Exam Number</label>
        <input type="text" id="exam_no" name="exam_no" required autocomplete="username"
               placeholder="e.g. ITSS/2026/014"
               value="<?php echo h(isset($_POST['exam_no']) ? $_POST['exam_no'] : ''); ?>">
        <label for="pin">Result PIN</label>
        <input type="password" id="pin" name="pin" required autocomplete="off"
               placeholder="e.g. 7K2P-9Q4M">
        <button type="submit" class="btn btn-primary login-submit">Check My Result</button>
        <p class="login-hint">Your exam number and PIN are on the result slip issued by the school office.</p>
      </form>

      <!-- STAFF FORM -->
      <form method="post" id="formStaff"
            class="login-form <?php echo $tab === 'staff' ? 'is-active' : ''; ?>">
        <input type="hidden" name="tab" value="staff">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username"
               value="<?php echo h(isset($_POST['username']) ? $_POST['username'] : ''); ?>">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <button type="submit" class="btn btn-primary login-submit">Sign In</button>
        <p class="login-hint">For the Head Teacher, administrators and teachers with accounts created by the school.</p>
      </form>

      <a class="login-back" href="../index.html">← Back to school website</a>
    </div>

    <p class="login-foot">© <?php echo date('Y'); ?> <?php echo h($schoolName); ?> · Leadership with Distinction</p>
  </div>

  <script>
    function showTab(which) {
      document.getElementById('formStudent').classList.toggle('is-active', which === 'student');
      document.getElementById('formStaff').classList.toggle('is-active',   which === 'staff');
      document.getElementById('tabBtnStudent').classList.toggle('is-active', which === 'student');
      document.getElementById('tabBtnStaff').classList.toggle('is-active',   which === 'staff');
    }
    // image fallback if logo file is not uploaded yet
    document.querySelectorAll('img[data-fallback]').forEach(function (img) {
      img.addEventListener('error', function () { img.style.display = 'none'; });
    });
  </script>
</body>
</html>
