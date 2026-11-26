<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — AUTHENTICATION
   File: portal/auth.php

   Handles sessions, logins for all three roles (admin,
   teacher, student/parent), page guards, and the automatic
   creation of the first admin account.

   Requires config.php to be loaded BEFORE this file.
   ============================================================ */

/* ---------------- 1. SESSION (secure as the server allows) -------- */
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(array(
        'lifetime' => 0,          // ends when the browser closes
        'path'     => '/',
        'secure'   => $secure,    // HTTPS-only cookie when available
        'httponly' => true,       // invisible to JavaScript (anti-theft)
        'samesite' => 'Lax'
    ));
    session_name('ITSS_PORTAL');
    session_start();
}

/* ---------------- 2. AUTO-CREATE THE FIRST ADMIN ACCOUNT ----------
   If the users table has no admin at all (fresh install), create:
       username: admin     password: admin123
   You MUST change this password from the admin dashboard
   immediately after your first login.                          */
function ensureDefaultAdmin(PDO $pdo) {
    try {
        $count = (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin'"
        )->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, username, password_hash, role)
                 VALUES (?, ?, ?, 'admin')"
            );
            $stmt->execute(array(
                'School Administrator',
                'admin',
                password_hash('admin123', PASSWORD_DEFAULT)
            ));
            audit($pdo, null, 'system', 'admin_created',
                  'Default admin account auto-created on first run');
        }
    } catch (PDOException $e) {
        /* tables not imported yet — login page will show a friendly error */
    }
}
ensureDefaultAdmin($pdo);

/* ---------------- 3. WHO IS LOGGED IN? ----------------------------
   The session stores ONE of these shapes:
     staff:    ['type'=>'staff', 'id'=>.., 'name'=>.., 'role'=>'admin'|'teacher']
     student:  ['type'=>'student', 'id'=>.., 'name'=>.., 'exam_no'=>..]  */
function currentUser() {
    return isset($_SESSION['portal_user']) ? $_SESSION['portal_user'] : null;
}
function isStaff() {
    $u = currentUser();
    return $u && $u['type'] === 'staff';
}
function isAdmin() {
    return isStaff() && $_SESSION['portal_user']['role'] === 'admin';
}
function isTeacher() {
    return isStaff() && $_SESSION['portal_user']['role'] === 'teacher';
}
function isStudent() {
    $u = currentUser();
    return $u && $u['type'] === 'student';
}

/* ---------------- 4. PAGE GUARDS -----------------------------------
   Put ONE of these at the top of a protected page, e.g.:
       requireAdmin();   // admin.php
       requireStaff();   // teacher.php (admins may also view)
       requireStudent(); // student.php                                 */
function sendToLogin() {
    header('Location: login.php');
    exit;
}
function requireStaff() {
    if (!isStaff()) sendToLogin();
}
function requireAdmin() {
    if (!isAdmin()) {
        if (isTeacher()) { header('Location: teacher.php'); exit; }
        sendToLogin();
    }
}
function requireTeacherOrAdmin() {   // alias used by teacher.php
    if (!isStaff()) sendToLogin();
}
function requireStudent() {
    if (!isStudent()) sendToLogin();
}

/* ---------------- 5. LOGIN THROTTLING ------------------------------
   After 5 failed attempts from a session, enforce a 5-minute wait.
   Simple but stops casual password guessing.                        */
function loginBlocked() {
    if (empty($_SESSION['fail_count'])) return false;
    if ($_SESSION['fail_count'] < 5) return false;
    $wait = 300; // 5 minutes
    return (time() - $_SESSION['fail_time']) < $wait;
}
function loginFailed() {
    $_SESSION['fail_count'] = (int)(isset($_SESSION['fail_count']) ? $_SESSION['fail_count'] : 0) + 1;
    $_SESSION['fail_time']  = time();
}
function loginSucceeded() {
    unset($_SESSION['fail_count'], $_SESSION['fail_time']);
    // New session id on login — prevents session-fixation attacks.
    session_regenerate_id(true);
}
function blockedMessage() {
    return 'Too many failed attempts. Please wait 5 minutes and try again.';
}

/* ---------------- 6. STAFF LOGIN (admin & teacher) -----------------
   Returns an error string, or null on success.                       */
function attemptStaffLogin(PDO $pdo, $username, $password) {
    if (loginBlocked()) return blockedMessage();

    $stmt = $pdo->prepare(
        "SELECT * FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->execute(array(trim($username)));
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        loginFailed();
        audit($pdo, null, $username, 'login_failed', 'Bad staff credentials');
        return 'Incorrect username or password.';
    }

    loginSucceeded();
    $_SESSION['portal_user'] = array(
        'type' => 'staff',
        'id'   => (int)$user['id'],
        'name' => $user['full_name'],
        'role' => $user['role']
    );
    audit($pdo, $user['id'], $user['full_name'], 'login',
          ucfirst($user['role']) . ' signed in');
    return null;
}

/* ---------------- 7. STUDENT / PARENT LOGIN ------------------------
   Exam number + PIN (the PIN from the slip issued by the school).
   Returns an error string, or null on success.                       */
function attemptStudentLogin(PDO $pdo, $examNo, $pin) {
    if (loginBlocked()) return blockedMessage();

    // Admin can close the whole student side with one switch.
    if (getSetting($pdo, 'portal_open', '1') !== '1') {
        return 'The results portal is temporarily closed. Please check back later or contact the school.';
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM students WHERE exam_no = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute(array(trim($examNo)));
    $student = $stmt->fetch();

    $pin = strtoupper(trim(str_replace(' ', '', $pin)));
    if (!$student || empty($student['pin_hash']) ||
        !password_verify($pin, $student['pin_hash'])) {
        loginFailed();
        audit($pdo, null, $examNo, 'login_failed', 'Bad student credentials');
        return 'Incorrect exam number or PIN. Check your result slip and try again.';
    }

    loginSucceeded();
    $_SESSION['portal_user'] = array(
        'type'    => 'student',
        'id'      => (int)$student['id'],
        'name'    => $student['full_name'],
        'exam_no' => $student['exam_no']
    );
    audit($pdo, $student['id'], $student['full_name'], 'login',
          'Student/parent signed in (' . $student['exam_no'] . ')');
    return null;
}

/* ---------------- 8. LOGOUT ---------------------------------------- */
function portalLogout(PDO $pdo) {
    $u = currentUser();
    if ($u) {
        audit($pdo, $u['id'], $u['name'], 'logout', 'Signed out');
    }
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------------- 9. PASSWORD / PIN HELPERS ------------------------- */

/* Change a staff member's password (their own, from any dashboard). */
function changeStaffPassword(PDO $pdo, $userId, $oldPass, $newPass) {
    if (strlen($newPass) < 8) {
        return 'New password must be at least 8 characters.';
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute(array($userId));
    $row = $stmt->fetch();
    if (!$row || !password_verify($oldPass, $row['password_hash'])) {
        return 'Your current password is incorrect.';
    }
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute(array(password_hash($newPass, PASSWORD_DEFAULT), $userId));
    audit($pdo, $userId, null, 'password_changed', 'Staff changed own password');
    return null;
}

/* Issue (or re-issue) a student's result PIN. Returns the plain PIN
   so the admin can print it on the slip — it is shown ONCE only.    */
function issueStudentPin(PDO $pdo, $studentId) {
    $pin = generatePin();
    $stmt = $pdo->prepare('UPDATE students SET pin_hash = ? WHERE id = ?');
    $stmt->execute(array(password_hash($pin, PASSWORD_DEFAULT), $studentId));
    audit($pdo, null, null, 'pin_issued', 'PIN issued for student #' . $studentId);
    return $pin;
}
?>
