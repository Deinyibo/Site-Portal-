?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — PORTAL CONFIGURATION
   File: portal/config.php

   THIS IS THE ONLY FILE YOU MUST EDIT.
   Fill in the four database values below (from your cPanel
   > MySQL Databases), then never touch this file again.
   ============================================================ */

/* ---------------- 1. DATABASE SETTINGS — EDIT THESE ---------------- */
define('DB_HOST', 'localhost');          // almost always "localhost" on cPanel
define('DB_NAME', 'youruser_iriebe');    // the database you created
define('DB_USER', 'youruser_dbadmin');   // the database user you created
define('DB_PASS', 'PUT-YOUR-DATABASE-PASSWORD-HERE');

/* ---------------- 2. SCHOOL IDENTITY (fallback defaults) -----------
   The admin dashboard can change these later without code;
   these are only used if the database is unreachable.        */
define('FALLBACK_SCHOOL_1', 'Iriebe Town');
define('FALLBACK_SCHOOL_2', 'Secondary School (UBE)');

/* ---------------- 3. DATABASE CONNECTION ---------------------------
   Creates $pdo — a secure connection used by every portal file. */
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false   // real prepared statements
        )
    );
} catch (PDOException $e) {
    // Never show the real error to visitors — it can leak the password.
    die('<div style="font-family:Arial;padding:2rem;max-width:560px;margin:4rem auto;'
      . 'border:2px solid #e02424;border-radius:12px;text-align:center;">'
      . '<h2 style="color:#e02424;">Portal temporarily unavailable</h2>'
      . '<p>The results portal cannot reach its database. If you are the '
      . 'administrator, check the database settings in <b>portal/config.php</b>.</p>'
      . '</div>');
}

/* ============================================================
   SHARED HELPERS — used by every other portal file.
   You should not need to edit anything below this line.
   ============================================================ */

/* ---------- Output escaping (prevents code injection in pages) ---- */
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/* ---------- Read one setting from the settings table --------------- */
function getSetting(PDO $pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare('SELECT v FROM settings WHERE k = ? LIMIT 1');
        $stmt->execute(array($key));
        $row = $stmt->fetch();
        return ($row && $row['v'] !== '') ? $row['v'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/* ---------- Save one setting (admin dashboard uses this) ----------- */
function setSetting(PDO $pdo, $key, $value) {
    $stmt = $pdo->prepare(
        'INSERT INTO settings (k, v) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $stmt->execute(array($key, $value));
}

/* ---------- School name as one line, for page titles --------------- */
function schoolName(PDO $pdo) {
    return getSetting($pdo, 'school_name_1', FALLBACK_SCHOOL_1) . ' ' .
           getSetting($pdo, 'school_name_2', FALLBACK_SCHOOL_2);
}

/* ---------- The currently active term (for entering results) ------- */
function activeTerm(PDO $pdo) {
    $stmt = $pdo->query('SELECT * FROM terms WHERE is_active = 1 LIMIT 1');
    $term = $stmt->fetch();
    if (!$term) {
        $stmt = $pdo->query('SELECT * FROM terms ORDER BY id DESC LIMIT 1');
        $term = $stmt->fetch();
    }
    return $term;   // array with id, name, session, is_active
}

/* ---------- Score limits (admin can change these, no code) --------- */
function scoreLimits(PDO $pdo) {
    return array(
        'ca1'  => (float)getSetting($pdo, 'ca1_max',  '20'),
        'ca2'  => (float)getSetting($pdo, 'ca2_max',  '20'),
        'exam' => (float)getSetting($pdo, 'exam_max', '60')
    );
}

/* ---------- Grade + remark from a total out of 100 -----------------
   70–100 A Excellent | 60–69 B Very Good | 50–59 C Credit
   45–49 D Pass | 40–44 E Weak Pass | 0–39 F Fail               */
function gradeFor($total) {
    if ($total === null) return array('', '');
    $t = (float)$total;
    if ($t >= 70) return array('A', 'Excellent');
    if ($t >= 60) return array('B', 'Very Good');
    if ($t >= 50) return array('C', 'Credit');
    if ($t >= 45) return array('D', 'Pass');
    if ($t >= 40) return array('E', 'Weak Pass');
    return array('F', 'Fail');
}

/* ---------- Position suffix: 1st, 2nd, 3rd, 4th... ----------------- */
function ordinal($n) {
    $n = (int)$n;
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    switch ($n % 10) {
        case 1:  return $n . 'st';
        case 2:  return $n . 'nd';
        case 3:  return $n . 'rd';
        default: return $n . 'th';
    }
}

/* ---------- Audit log: who did what, when ---------------------------
   Called after important actions (login, publish, score saved...).   */
function audit(PDO $pdo, $userId, $userName, $action, $detail = '') {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, user_name, action, detail)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(array($userId, $userName, $action, $detail));
    } catch (PDOException $e) {
        /* logging must never break the page */
    }
}

/* ---------- Generate a readable result-check PIN (e.g. 7K2P-9Q4M) ---
   Excludes confusing characters (0/O, 1/I) so slips are easy to read.*/
function generatePin($length = 8) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pin = '';
    for ($i = 0; $i < $length; $i++) {
        $pin .= $chars[random_int(0, strlen($chars) - 1)];
        if ($i === 3) $pin .= '-';
    }
    return $pin;
}
?>
