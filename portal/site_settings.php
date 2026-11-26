<?php
/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE)
   File: portal/site_settings.php   (File 14 of 14)
   The bridge between the Site Builder (admin.html) and the
   live website.

   - admin.html POSTs the school settings here (colours, page
     text, photos, news, info boxes, ticker) -> saved on server.
   - Every page GETs them back on load -> all visitors see the
     changes. No code editing ever needed again.

   STORAGE: portal database first (table "site_settings",
   created automatically); if the database is unreachable it
   falls back to a JSON file in this folder. Either way the
   response says which one was used ("storage": "database"|"file").

   SECURITY: saving requires the Site Builder passcode
   (default iriebe2026 — change it below AND in admin.html to
   the same new phrase). Reading is open on purpose: the
   settings are public website content that pages must load
   for every visitor. A backup of the previous version is kept
   automatically before every save.
   ============================================================ */

require_once __DIR__ . '/config.php';

/* ---------------- configuration ---------------- */
define('BRIDGE_PASSCODE', 'iriebe2026');        // MUST match PASSCODE in admin.html — change both together
define('MAX_PAYLOAD',     2 * 1024 * 1024);     // refuse saves over 2 MB
define('STORE_KEY',       'site');
define('STORE_FILE',      __DIR__ . '/site_settings.data.json');
define('BACKUP_FILE',     __DIR__ . '/site_settings.backup.json');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* Allow saving/reading even when admin.html is previewed from
   another origin (e.g. opened as a local file). Writes still
   require the passcode below. */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Site-Passcode');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function reply($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/* ---------------- database (with automatic table) ---------------- */
function bridge_db() {
    static $pdo = null, $tried = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($tried) return null;
    $tried = true;
    try {
        if (function_exists('portal_db')) { $pdo = portal_db(); }
        elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) { $pdo = $GLOBALS['pdo']; }
        else {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            k VARCHAR(40) NOT NULL PRIMARY KEY,
            v MEDIUMTEXT,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { $pdo = null; }
    return $pdo;
}

/* ---------------- storage: database, file fallback ---------------- */
function store_read($key) {
    $pdo = bridge_db();
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT v FROM site_settings WHERE k = ?');
            $st->execute(array($key));
            $v = $st->fetchColumn();
            if ($v !== false) return array('json' => $v, 'where' => 'database');
        } catch (Exception $e) { /* fall through to file */ }
    }
    $f = ($key === STORE_KEY . '_backup') ? BACKUP_FILE : STORE_FILE;
    if (is_file($f)) {
        $v = @file_get_contents($f);
        if ($v !== false) return array('json' => $v, 'where' => 'file');
    }
    return null;
}
function store_write($key, $json) {
    $pdo = bridge_db();
    if ($pdo) {
        try {
            $st = $pdo->prepare('INSERT INTO site_settings (k, v) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE v = VALUES(v)');
            $st->execute(array($key, $json));
            return 'database';
        } catch (Exception $e) { /* fall through to file */ }
    }
    $f = ($key === STORE_KEY . '_backup') ? BACKUP_FILE : STORE_FILE;
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return null;
    @rename($tmp, $f);
    @chmod($f, 0644);
    return 'file';
}

/* ---------------- sanitizers ---------------- */
function clean_plain($s, $max = 20000) {                 // page text, news fields
    $s = mb_substr((string)$s, 0, $max);
    $s = preg_replace('/<\s*script.*?(<\s*\/\s*script\s*>|$)/is', '', $s);
    $s = str_ireplace(array('javascript:', '<?', '?>'), '', $s);
    return strip_tags($s);
}
function clean_html($s, $max = 50000) {                  // info boxes may hold simple HTML
    $s = mb_substr((string)$s, 0, $max);
    $s = preg_replace('/<\s*script.*?(<\s*\/\s*script\s*>|$)/is', '', $s);
    $s = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $s);
    $s = str_ireplace(array('javascript:', '<?', '?>'), '', $s);
    return $s;
}
function clean_color($s) {
    $s = trim((string)$s);
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $s)) return $s;
    if (preg_match('/^rgba?\([0-9\s.,%]+\)$/i', $s)) return $s;
    if (preg_match('/^[a-zA-Z]{3,20}$/', $s)) return $s;  // named colour
    return null;
}
function clean_url($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (preg_match('#^https?://#i', $s)) return $s;
    if (preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,[a-zA-Z0-9+/=\s]+$#i', $s)) return $s;
    if (preg_match('#^[a-zA-Z0-9_\-./]+$#', $s) && strpos($s, '..') === false) return $s;  // relative path
    return null;
}
function sanitize_settings($in) {
    $out = array('colors' => array(), 'texts' => array(), 'images' => array(), 'news' => array(), 'boxes' => array());
    if (!is_array($in)) return $out;

    if (!empty($in['colors']) && is_array($in['colors'])) {
        foreach ($in['colors'] as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$k);
            $c = clean_color($v);
            if ($k !== '' && $c !== null) $out['colors'][$k] = $c;
            if (count($out['colors']) >= 50) break;
        }
    }
    if (!empty($in['texts']) && is_array($in['texts'])) {
        foreach ($in['texts'] as $k => $v) {
            if (!is_scalar($v)) continue;
            $out['texts'][mb_substr((string)$k, 0, 200)] = clean_plain($v);
            if (count($out['texts']) >= 600) break;
        }
    }
    if (!empty($in['images']) && is_array($in['images'])) {
        foreach ($in['images'] as $k => $v) {
            $u = clean_url($v);
            if ($u !== null) $out['images'][mb_substr((string)$k, 0, 200)] = $u;
            if (count($out['images']) >= 300) break;
        }
    }
    if (!empty($in['news']) && is_array($in['news'])) {
        foreach ($in['news'] as $item) {                 // keep whatever fields each card has
            if (!is_array($item)) continue;
            $clean = array();
            foreach ($item as $ik => $iv) {
                if (is_scalar($iv)) $clean[mb_substr((string)$ik, 0, 60)] = clean_plain($iv, 5000);
            }
            if ($clean) $out['news'][] = $clean;
            if (count($out['news']) >= 200) break;
        }
    }
    if (!empty($in['boxes']) && is_array($in['boxes'])) {
        foreach ($in['boxes'] as $k => $v) {
            if (!is_scalar($v)) continue;
            $out['boxes'][mb_substr((string)$k, 0, 200)] = clean_html($v);
            if (count($out['boxes']) >= 120) break;
        }
    }
    return $out;
}

/* ---------------- passcode ---------------- */
function supplied_passcode($decoded) {
    if (!empty($_POST['passcode'])) return (string)$_POST['passcode'];
    if (!empty($_SERVER['HTTP_X_SITE_PASSCODE'])) return (string)$_SERVER['HTTP_X_SITE_PASSCODE'];
    if (is_array($decoded) && !empty($decoded['passcode'])) return (string)$decoded['passcode'];
    if (!empty($_GET['passcode'])) return (string)$_GET['passcode'];   // used only for backup fetch
    return '';
}
function passcode_ok($decoded) {
    $given = supplied_passcode($decoded);
    $ok = ($given !== '') && hash_equals(BRIDGE_PASSCODE, $given);
    if (!$ok) usleep(500000);                            // slow down guessing
    return $ok;
}

/* ============================================================
   ROUTES
   ============================================================ */
$method = $_SERVER['REQUEST_METHOD'];

/* ---------- ping: lets admin.html detect the bridge is live ---------- */
if ($method === 'GET' && isset($_GET['ping'])) {
    reply(200, array('ok' => true, 'bridge' => 'up', 'time' => date('c')));
}

/* ---------- READ: pages load the saved settings ---------- */
if ($method === 'GET') {
    header('Cache-Control: no-store');
    $want_backup = isset($_GET['backup']);
    if ($want_backup && !passcode_ok(null)) {
        reply(403, array('ok' => false, 'error' => 'Wrong passcode.'));
    }
    $got = store_read($want_backup ? STORE_KEY . '_backup' : STORE_KEY);
    if (!$got) reply(200, array('ok' => true, 'saved' => false, 'settings' => null));
    reply(200, array(
        'ok'       => true,
        'saved'    => true,
        'storage'  => $got['where'],
        'settings' => json_decode($got['json'], true),
    ));
}

/* ---------- SAVE: the Site Builder posts here ---------- */
if ($method === 'POST') {
    $raw = isset($_POST['data']) ? (string)$_POST['data'] : (string)file_get_contents('php://input');
    if (strlen($raw) > MAX_PAYLOAD) reply(413, array('ok' => false, 'error' => 'Settings too large (over 2 MB).'));

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) reply(422, array('ok' => false, 'error' => 'Could not read the settings data sent.'));

    if (!passcode_ok($decoded)) {
        reply(403, array('ok' => false, 'error' => 'Wrong passcode. Saving refused.'));
    }

    // accept both {"settings":{...}} and a bare settings object
    $settings = (isset($decoded['settings']) && is_array($decoded['settings'])) ? $decoded['settings'] : $decoded;
    unset($settings['passcode']);

    $clean = sanitize_settings($settings);
    $json  = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) reply(422, array('ok' => false, 'error' => 'Could not encode the settings.'));

    // keep the previous version as a backup before overwriting
    $prev = store_read(STORE_KEY);
    if ($prev) store_write(STORE_KEY . '_backup', $prev['json']);

    $where = store_write(STORE_KEY, $json);
    if (!$where) reply(500, array('ok' => false, 'error' => 'Could not save on the server — check that the portal/ folder is writable, or that the database is reachable.'));

    reply(200, array(
        'ok'       => true,
        'storage'  => $where,
        'saved_at' => date('c'),
        'counts'   => array(
            'colors' => count($clean['colors']),
            'texts'  => count($clean['texts']),
            'images' => count($clean['images']),
            'news'   => count($clean['news']),
            'boxes'  => count($clean['boxes']),
        ),
    ));
}

reply(405, array('ok' => false, 'error' => 'Use GET to read settings or POST to save.'));
