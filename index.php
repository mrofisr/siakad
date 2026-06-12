<?php
// ============================================================
// SIAKAD v1 - Sistem Informasi Akademik
// Single-file PHP + SQLite
// Built-in functions only. No external libs.
// ============================================================

// Secure session cookie settings (works behind Cloudflare/reverse proxy)
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
$DB_PATH = __DIR__ . '/siakad.db';

function log_message(string $level, string $message, array $context = []): void {
    static $log_file = null;
    if ($log_file === null) {
        $log_file = __DIR__ . '/logs/app.log';
        if (!is_dir(dirname($log_file))) {
            mkdir(dirname($log_file), 0755, true);
        }
    }
    
    $timestamp = gmdate('Y-m-d\TH:i:s.u\Z');
    $trace_id = $_SESSION['trace_id'] ??= bin2hex(random_bytes(8));
    
    $log_entry = sprintf(
        "[%s] %s: %s%s\n",
        $timestamp,
        $level,
        $message,
        !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
    );
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_debug(string $message, array $context = []): void { log_message('DEBUG', $message, $context); }
function log_info(string $message, array $context = []): void { log_message('INFO', $message, $context); }
function log_notice(string $message, array $context = []): void { log_message('NOTICE', $message, $context); }
function log_warning(string $message, array $context = []): void { log_message('WARNING', $message, $context); }
function log_error(string $message, array $context = []): void { log_message('ERROR', $message, $context); }
function log_critical(string $message, array $context = []): void { log_message('CRITICAL', $message, $context); }
function log_alert(string $message, array $context = []): void { log_message('ALERT', $message, $context); }
function log_emergency(string $message, array $context = []): void { log_message('EMERGENCY', $message, $context); }

function get_trace_id(): string {
    return $_SESSION['trace_id'] ??= bin2hex(random_bytes(8));
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . $GLOBALS['DB_PATH']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    }
    return $pdo;
}

function db_transaction(callable $callback) {
    $db = db();
    $db->beginTransaction();
    try {
        $result = $callback($db);
        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function migrate(): void {
    $db = db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS prodi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kode TEXT UNIQUE NOT NULL,
            nama TEXT NOT NULL,
            jenjang TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS mahasiswa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nim TEXT UNIQUE NOT NULL,
            nama TEXT NOT NULL,
            prodi_id INTEGER REFERENCES prodi(id),
            angkatan TEXT NOT NULL,
            alamat TEXT DEFAULT '',
            no_telp TEXT DEFAULT '',
            email TEXT DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS dosen (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nidn TEXT UNIQUE NOT NULL,
            nama TEXT NOT NULL,
            prodi_id INTEGER REFERENCES prodi(id),
            alamat TEXT DEFAULT '',
            no_telp TEXT DEFAULT '',
            email TEXT DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mata_kuliah (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kode TEXT UNIQUE NOT NULL,
            nama TEXT NOT NULL,
            sks INTEGER NOT NULL,
            prodi_id INTEGER REFERENCES prodi(id),
            semester INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS tahun_akademik (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tahun TEXT NOT NULL,
            semester TEXT NOT NULL,
            is_active INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS kelas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mk_id INTEGER REFERENCES mata_kuliah(id),
            dosen_id INTEGER REFERENCES dosen(id),
            tahun_akademik_id INTEGER REFERENCES tahun_akademik(id),
            nama_kelas TEXT NOT NULL,
            kuota INTEGER DEFAULT 40
        );
        CREATE TABLE IF NOT EXISTS jadwal (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kelas_id INTEGER REFERENCES kelas(id) ON DELETE CASCADE,
            hari TEXT NOT NULL,
            jam_mulai TEXT NOT NULL,
            jam_selesai TEXT NOT NULL,
            ruang TEXT DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS krs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mahasiswa_id INTEGER REFERENCES mahasiswa(id),
            kelas_id INTEGER REFERENCES kelas(id),
            tahun_akademik_id INTEGER REFERENCES tahun_akademik(id),
            status TEXT DEFAULT 'disetujui',
            UNIQUE(mahasiswa_id, kelas_id)
        );
        CREATE TABLE IF NOT EXISTS nilai (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            krs_id INTEGER REFERENCES krs(id) ON DELETE CASCADE,
            nilai_angka REAL
        );
        CREATE TABLE IF NOT EXISTS presensi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kelas_id INTEGER REFERENCES kelas(id),
            mahasiswa_id INTEGER REFERENCES mahasiswa(id),
            tanggal TEXT NOT NULL,
            status TEXT NOT NULL,
            UNIQUE(kelas_id, mahasiswa_id, tanggal)
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            linked_id INTEGER
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT DEFAULT '',
            is_read INTEGER DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_notif_user_unread
            ON notifications(user_id, is_read, created_at);
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS landing_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slot_name TEXT UNIQUE NOT NULL,
            original_filename TEXT,
            file_size INTEGER,
            mime_type TEXT,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            uploaded_by INTEGER REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $st = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $st->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }

    $default_settings = [
        'school_name' => 'Sistem Informasi Akademik',
        'accent_color' => '#2c7a4b',
        'hero_title' => 'Selamat Datang di Portal Akademik',
        'hero_subtitle' => 'Portal akademik untuk mahasiswa dan dosen',
        'card_1_title' => 'KRS Online',
        'card_1_desc' => 'Pengisian Kartu Rencana Studi secara online',
        'card_1_icon' => 'krs',
        'card_2_title' => 'Nilai & Transkrip',
        'card_2_desc' => 'Lihat nilai dan transkrip akademik',
        'card_2_icon' => 'nilai',
        'card_3_title' => 'Jadwal Kuliah',
        'card_3_desc' => 'Akses jadwal perkuliahan mingguan',
        'card_3_icon' => 'jadwal',
        'footer_address' => 'Jl. Pendidikan No. 123, Kota, Indonesia',
        'footer_email' => 'info@example.ac.id',
        'footer_phone' => '(000) 000-0000',
        'footer_copyright' => '© 2026 {school_name}. SIAKAD.',
    ];
    foreach ($default_settings as $key => $value) {
        $st = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $st->execute([$key, $value]);
    }
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_role(string ...$roles): void {
    $u = current_user();
    if (!$u) {
        log_warning('Access denied - not authenticated', ['trace_id' => get_trace_id()]);
        redirect('?page=login');
    }
    if ($u['role'] === 'admin') return;
    if (!in_array($u['role'], $roles)) {
        log_warning('Access denied', ['role' => $u['role'], 'required_roles' => $roles, 'trace_id' => get_trace_id()]);
        die('Akses ditolak.');
    }
}

function do_login(string $username, string $password): ?string {
    log_debug('Attempting login', ['username' => $username, 'trace_id' => get_trace_id()]);
    $st = db()->prepare("SELECT id, username, password_hash, role, linked_id FROM users WHERE username = ?");
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        log_warning('Login failed', ['username' => $username, 'trace_id' => get_trace_id()]);
        return null;
    }

    $name = $username;
    if ($u['role'] === 'mahasiswa' && $u['linked_id']) {
        $m = db()->prepare("SELECT nama FROM mahasiswa WHERE id = ?");
        $m->execute([$u['linked_id']]);
        $name = $m->fetchColumn() ?: $username;
    } elseif ($u['role'] === 'dosen' && $u['linked_id']) {
        $d = db()->prepare("SELECT nama FROM dosen WHERE id = ?");
        $d->execute([$u['linked_id']]);
        $name = $d->fetchColumn() ?: $username;
    }

    $_SESSION['user'] = [
        'id' => $u['id'], 'username' => $u['username'],
        'role' => $u['role'], 'linked_id' => $u['linked_id'], 'name' => $name,
    ];
    session_regenerate_id(true);
    log_notice('User logged in', ['username' => $username, 'role' => $u['role'], 'trace_id' => get_trace_id()]);
    return $u['role'];
}

function do_logout(): void {
    $u = current_user();
    log_info('User logged out', ['username' => $u['username'] ?? 'unknown', 'trace_id' => get_trace_id()]);
    session_destroy();
    redirect('?page=login');
}

function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): void { header("Location: $url"); exit; }
function method(string $m): bool { return strtoupper($_SERVER['REQUEST_METHOD']) === strtoupper($m); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }

function verify_csrf(): void {
    if (!method('POST')) return;
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
        log_warning('CSRF validation failed', ['trace_id' => get_trace_id()]);
        die('CSRF token tidak valid.');
    }
}

function get_setting(string $key, string $default = ''): string {
    $st = db()->prepare("SELECT value FROM settings WHERE key = ?");
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return $value !== false ? $value : $default;
}

function get_all_settings(): array {
    $rows = db()->query("SELECT key, value FROM settings");
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

function update_setting(string $key, string $value): void {
    $st = db()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $st->execute([$key, $value]);
}

function handle_settings_save(): void {
    verify_csrf();
    
    $settings = [
        'school_name' => $_POST['school_name'] ?? '',
        'accent_color' => $_POST['accent_color'] ?? '#2c7a4b',
        'hero_title' => $_POST['hero_title'] ?? '',
        'hero_subtitle' => $_POST['hero_subtitle'] ?? '',
        'card_1_title' => $_POST['card_1_title'] ?? '',
        'card_1_desc' => $_POST['card_1_desc'] ?? '',
        'card_1_icon' => $_POST['card_1_icon'] ?? 'krs',
        'card_2_title' => $_POST['card_2_title'] ?? '',
        'card_2_desc' => $_POST['card_2_desc'] ?? '',
        'card_2_icon' => $_POST['card_2_icon'] ?? 'nilai',
        'card_3_title' => $_POST['card_3_title'] ?? '',
        'card_3_desc' => $_POST['card_3_desc'] ?? '',
        'card_3_icon' => $_POST['card_3_icon'] ?? 'jadwal',
        'footer_address' => $_POST['footer_address'] ?? '',
        'footer_email' => $_POST['footer_email'] ?? '',
        'footer_phone' => $_POST['footer_phone'] ?? '',
        'footer_copyright' => $_POST['footer_copyright'] ?? '',
    ];
    
    foreach ($settings as $key => $value) {
        update_setting($key, $value);
    }
    
    flash_set('success', 'Pengaturan disimpan.');
    redirect('?page=settings');
}

function handle_settings_upload(): void {
    verify_csrf();
    
    $upload_dir = __DIR__ . '/uploads';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $allowed_logo_types = ['image/png', 'image/jpeg', 'image/svg+xml'];
    $allowed_hero_types = ['image/png', 'image/jpeg'];
    $max_size = 2 * 1024 * 1024;
    
    if (!empty($_FILES['logo']['tmp_name'])) {
        $file = $_FILES['logo'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            if ($file['size'] > $max_size) {
                flash_set('error', 'Ukuran logo terlalu besar (max 2MB).');
                redirect('?page=settings');
            }
            if (!in_array($file['type'], $allowed_logo_types)) {
                flash_set('error', 'Format logo tidak valid (PNG, JPEG, SVG).');
                redirect('?page=settings');
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_name = 'logo.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], "$upload_dir/$new_name")) {
                flash_set('error', 'Gagal mengunggah logo.');
                redirect('?page=settings');
            }
            log_info('Logo uploaded', ['filename' => $new_name, 'trace_id' => get_trace_id()]);
        }
    }
    
    if (!empty($_FILES['hero_image']['tmp_name'])) {
        $file = $_FILES['hero_image'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            if ($file['size'] > $max_size) {
                flash_set('error', 'Ukuran hero image terlalu besar (max 2MB).');
                redirect('?page=settings');
            }
            if (!in_array($file['type'], $allowed_hero_types)) {
                flash_set('error', 'Format hero image tidak valid (PNG, JPEG).');
                redirect('?page=settings');
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_name = 'hero.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], "$upload_dir/$new_name")) {
                flash_set('error', 'Gagal mengunggah hero image.');
                redirect('?page=settings');
            }
            log_info('Hero image uploaded', ['filename' => $new_name, 'trace_id' => get_trace_id()]);
        }
    }
    
    flash_set('success', 'Gambar berhasil diunggah.');
    redirect('?page=settings');
}

function flash_get(): array {
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function flash_set(string $key, string $msg): void { $_SESSION['flash'][$key] = $msg; }

function notify(int $user_id, string $type, string $title, string $body = ''): void {
    $st = db()->prepare("INSERT INTO notifications (user_id, type, title, body, created_at) VALUES (?, ?, ?, ?, datetime('now'))");
    $st->execute([$user_id, $type, $title, $body]);
}

function notify_role(string $role, string $type, string $title, string $body = ''): void {
    $users = db()->prepare("SELECT id FROM users WHERE role = ?");
    $users->execute([$role]);
    foreach ($users as $u) {
        notify($u['id'], $type, $title, $body);
    }
}

function notify_all(string $type, string $title, string $body = ''): void {
    $users = db()->query("SELECT id FROM users");
    foreach ($users as $u) {
        notify($u['id'], $type, $title, $body);
    }
}

function input(string $name, string $label, string $value = '', string $type = 'text', bool $req = false): string {
    $r = $req ? ' required' : ''; $v = e($value);
    return "<label>$label<input type=\"$type\" name=\"$name\" value=\"$v\"$r></label>";
}
function textarea(string $name, string $label, string $value = '', bool $req = false): string {
    $r = $req ? ' required' : ''; $v = e($value);
    return "<label>$label<textarea name=\"$name\"$r>$v</textarea></label>";
}
function select_opts(string $table, string $label, int|string|null $sel = null, string $where = '', string $order = ''): string {
    $allowed_tables = ['prodi', 'mahasiswa', 'dosen', 'mata_kuliah', 'tahun_akademik'];
    $allowed_labels = ['nama', 'kode', 'jenjang', 'nidn', 'nim'];
    if (!in_array($table, $allowed_tables) || !in_array($label, $allowed_labels)) {
        die('Invalid table or column reference.');
    }
    $sql = "SELECT id, $label FROM $table";
    if ($where) $sql .= " WHERE $where";
    if ($order) $sql .= " ORDER BY $order"; else $sql .= " ORDER BY $label";
    $rows = db()->query($sql); $h = '';
    foreach ($rows as $r) {
        $s = $r['id'] == $sel ? ' selected' : '';
        $h .= "<option value=\"{$r['id']}\"$s>" . e($r[$label]) . "</option>";
    }
    return $h;
}

function hari_opts(?string $sel = null): string {
    $hari = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu']; $h = '';
    foreach ($hari as $h2) { $s = $h2 === $sel ? ' selected' : ''; $h .= "<option$s>$h2</option>"; }
    return $h;
}

function title(string $s): string { return ucwords(str_replace('_', ' ', $s)); }

function layout(string $title, string $content): void {
    $u = current_user();
    $nav = '';
    if ($u) {
        $items = [];
        if ($u['role'] === 'admin') {
            $items['dashboard'] = 'Dashboard';
            $items['prodi'] = 'Prodi';
            $items['mahasiswa'] = 'Mahasiswa';
            $items['dosen'] = 'Dosen';
            $items['mata_kuliah'] = 'Mata Kuliah';
            $items['tahun_akademik'] = 'T.A.';
            $items['kelas'] = 'Kelas';
            $items['krs'] = 'KRS';
            $items['nilai'] = 'Nilai';
            $items['presensi'] = 'Presensi';
            $items['khs'] = 'KHS';
            $items['broadcast'] = 'Broadcast';
            $items['settings'] = 'Pengaturan';
        } elseif ($u['role'] === 'dosen') {
            $items['dashboard'] = 'Dashboard';
            $items['presensi'] = 'Presensi';
            $items['nilai'] = 'Nilai';
        } elseif ($u['role'] === 'mahasiswa') {
            $items['dashboard'] = 'Dashboard';
            $items['krs'] = 'KRS';
            $items['khs'] = 'KHS';
        }
        $nav_l = '';
        foreach ($items as $p => $l) {
            $active = ($_GET['page'] ?? '') === $p ? ' class="nav-active"' : '';
            $nav_l .= "<a href=\"?page=$p\"$active>$l</a>\n";
        }
        $role_label = ucfirst($u['role']);
        $nav = <<<HTML
        <nav class="site-nav">
            <div class="nav-inner">
                <div class="nav-brand">
                    <span class="brand-mark">S</span>
                    <span class="brand-text">SIAKAD</span>
                </div>
                <div class="nav-links">$nav_l</div>
                <div class="nav-user">
                    <span class="notif-badge" id="notif-badge" style="display:none">0</span>
                    <span class="user-badge">$role_label</span>
                    <a href="?page=logout" class="nav-logout">{$u['name']}</a>
                </div>
            </div>
        </nav>
HTML;
    }
    $flash = '';
    foreach (flash_get() as $type => $msg) {
        $cls = $type === 'error' ? 'flash-error' : 'flash-success';
        $flash .= "<div class=\"flash $cls\">" . e($msg) . "</div>\n";
    }
    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} - SIAKAD</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,600;1,6..72,400&family=Geist+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
$nav
<main class="container">
$flash
$content
</main>
<script src="assets/js/main.js"></script>
</body>
</html>
HTML;
}

function handle_sse(): void {
    $u = current_user();
    if (!$u) { http_response_code(401); exit; }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    echo "retry: 5000\n\n";
    ob_flush(); flush();

    $last_id = 0;
    $max_iterations = 100;
    $i = 0;

    while ($i < $max_iterations) {
        // Cleanup old notifications (30 days)
        db()->exec("DELETE FROM notifications WHERE created_at < datetime('now', '-30 days')");

        $st = db()->prepare("SELECT id, type, title, body, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND id > ? ORDER BY id ASC");
        $st->execute([$u['id'], $last_id]);
        $rows = $st->fetchAll();

        foreach ($rows as $row) {
            $data = json_encode([
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'body' => $row['body'],
                'created_at' => $row['created_at'],
            ], JSON_UNESCAPED_SLASHES);
            echo "id: {$row['id']}\n";
            echo "data: $data\n\n";
            $last_id = $row['id'];
        }

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = db()->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders)");
            $upd->execute($ids);
        }

        ob_flush(); flush();

        if (connection_aborted()) break;
        sleep(3);
        $i++;
    }
    exit;
}

function handle_notif_count(): void {
    $u = current_user();
    if (!$u) { http_response_code(401); echo json_encode(['count' => 0]); exit; }

    header('Content-Type: application/json');
    $st = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $st->execute([$u['id']]);
    $count = (int) $st->fetchColumn();
    echo json_encode(['count' => $count]);
    exit;
}

function handle_broadcast(): string {
    require_role('admin');
    if (method('POST')) {
        verify_csrf();
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $target = $_POST['target'] ?? 'all';

        if ($title === '') {
            flash_set('error', 'Judul notifikasi wajib diisi.');
            redirect('?page=broadcast');
        }

        if ($target === 'all') {
            notify_all('broadcast', $title, $body);
        } else {
            notify_role($target, 'broadcast', $title, $body);
        }

        log_info('Broadcast sent', ['target' => $target, 'title' => $title, 'trace_id' => get_trace_id()]);
        flash_set('success', 'Notifikasi broadcast terkirim.');
        redirect('?page=broadcast');
    }

    ob_start(); ?>
    <h1>Broadcast Notifikasi</h1>
    <form method="post">
        <?=csrf_field()?>
        <?=input('title', 'Judul', '', 'text', true)?>
        <?=textarea('body', 'Isi Pesan (opsional)')?>
        <label>Target <select name="target">
            <option value="all">Semua Pengguna</option>
            <option value="mahasiswa">Mahasiswa</option>
            <option value="dosen">Dosen</option>
        </select></label>
        <button type="submit">Kirim Broadcast</button>
    </form>
    <?php return ob_get_clean();
}

function validate_image_magic_bytes(string $file_path, string $extension): bool {
    $extension = strtolower($extension);
    $magic_bytes = file_get_contents($file_path, false, null, 0, 12);
    
    $signatures = [
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89PNG\r\n\x1a\n"],
        'gif' => ["\x47\x49\x46\x38"],
        'webp' => null, // WEBP is complex, checked via fileinfo
    ];
    
    if (!isset($signatures[$extension])) {
        return false;
    }
    
    if ($extension === 'webp') {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file_path);
        finfo_close($finfo);
        return $mime === 'image/webp';
    }
    
    foreach ($signatures[$extension] as $sig) {
        if (strpos($magic_bytes, $sig) === 0) {
            return true;
        }
    }
    
    return false;
}

function validate_image_upload(array $file): array {
    $errors = [];
    
    // File size check (2MB = 2097152 bytes)
    if ($file['size'] > 2097152) {
        $errors[] = 'File size exceeds 2MB limit';
    }
    
    // Extension whitelist
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed_extensions)) {
        $errors[] = 'File type not allowed. Only JPG, PNG, GIF, WebP allowed';
    }
    
    // Verify valid upload before file I/O
    if (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Upload error - file is not a valid upload';
    }
    
    // MIME type check
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed_mimes)) {
        $errors[] = 'Invalid MIME type';
    }
    
    // Magic bytes validation (only if earlier checks pass)
    if (empty($errors) && file_exists($file['tmp_name']) && !validate_image_magic_bytes($file['tmp_name'], $ext)) {
        $errors[] = 'File header does not match extension (possible tampering)';
    }
    
    return $errors;
}

function handle_login(): string {
    if (method('POST')) {
        verify_csrf();
        $role = do_login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($role) redirect('?page=dashboard');
        flash_set('error', 'Username atau password salah.');
    }
    ob_start(); ?>
    <article style="max-width:400px;margin:4rem auto">
        <hgroup><h1>Login</h1><p>Sistem Informasi Akademik</p></hgroup>
        <form method="post">
            <?=csrf_field()?>
            <label>Username <input type="text" name="username" required autocomplete="username"></label>
            <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">Login</button>
        </form>
    </article>
    <?php return ob_get_clean();
}

function handle_logout(): void { do_logout(); }

function handle_dashboard(): string {
    $u = current_user();
    if ($u['role'] === 'mahasiswa') {
        $mhs = db()->prepare("SELECT nim, nama, prodi_id, angkatan FROM mahasiswa WHERE id = ?");
        $mhs->execute([$u['linked_id']]); $m = $mhs->fetch();
        $prodi = '';
        if ($m && $m['prodi_id']) {
            $p = db()->prepare("SELECT nama FROM prodi WHERE id = ?");
            $p->execute([$m['prodi_id']]);
            $prodi = $p->fetchColumn() ?: '';
        }
        ob_start(); ?>
        <hgroup><h1>Dashboard</h1><p>Selamat datang, <?=e($u['name'])?></p></hgroup>
        <div class="grid">
            <article><strong>NIM:</strong> <?=e($m['nim']??'')?></article>
            <article><strong>Prodi:</strong> <?=e($prodi)?></article>
            <article><strong>Angkatan:</strong> <?=e($m['angkatan']??'')?></article>
        </div>
        <section><h2>Jadwal Kuliah</h2><?=jadwal_table_mhs($u['linked_id'])?></section>
        <?php return ob_get_clean();
    }
    if ($u['role'] === 'dosen') {
        ob_start(); ?>
        <hgroup><h1>Dashboard</h1><p>Selamat datang, <?=e($u['name'])?></p></hgroup>
        <section><h2>Kelas Saya</h2>
        <table><thead><tr><th>MK</th><th>Kelas</th><th>Jadwal</th><th>MHS</th><th>Aksi</th></tr></thead><tbody>
        <?php
        $rows = db()->prepare("
            SELECT k.id, mk.nama AS mk, k.nama_kelas, mk.sks,
                (SELECT COUNT(*) FROM krs WHERE kelas_id=k.id) AS mhs_count
            FROM kelas k JOIN mata_kuliah mk ON mk.id=k.mk_id
            WHERE k.dosen_id=? ORDER BY mk.nama
        "); $rows->execute([$u['linked_id']]);
        $has = false;
        foreach ($rows as $r) { $has = true;
            $j = db()->prepare("SELECT hari, jam_mulai, jam_selesai, ruang FROM jadwal WHERE kelas_id=?");
            $j->execute([$r['id']]); $jads = '';
            foreach ($j as $jd) $jads .= e($jd['hari']).' '.e(substr($jd['jam_mulai'],0,5)).'-'.e(substr($jd['jam_selesai'],0,5)).' '.e($jd['ruang']).'<br>';
            echo "<tr><td>" . e($r['mk']) . "</td><td>" . e($r['nama_kelas']) . "</td><td>$jads</td><td>{$r['mhs_count']}</td><td><a href=\"?page=nilai&kelas_id={$r['id']}\">Nilai</a> | <a href=\"?page=presensi&kelas_id={$r['id']}\">Presensi</a></td></tr>";
        } if (!$has) echo '<tr><td colspan="5">Belum ada kelas.</td></tr>'; ?>
        </tbody></table></section>
        <?php return ob_get_clean();
    }
    $stats = [
        'Prodi' => db()->query("SELECT COUNT(*) FROM prodi")->fetchColumn(),
        'Mahasiswa' => db()->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn(),
        'Dosen' => db()->query("SELECT COUNT(*) FROM dosen")->fetchColumn(),
        'Mata Kuliah' => db()->query("SELECT COUNT(*) FROM mata_kuliah")->fetchColumn(),
    ];
    ob_start(); ?>
    <hgroup><h1>Dashboard</h1><p>Selamat datang, Admin</p></hgroup>
    <div class="grid"><?php foreach ($stats as $l => $c): ?>
        <article><header><?=$l?></header><h2><?=$c?></h2></article>
    <?php endforeach; ?></div>
    <?php return ob_get_clean();
}

function handle_prodi(): string {
    require_role('admin');
    if (method('POST')) { verify_csrf();
        $st = db()->prepare("INSERT OR REPLACE INTO prodi (id, kode, nama, jenjang) VALUES (?, ?, ?, ?)");
        $st->execute([$_POST['id'] ?: null, $_POST['kode']??'', $_POST['nama']??'', $_POST['jenjang']??'']);
        flash_set('success', 'Prodi tersimpan.'); redirect('?page=prodi');
    }
    $action = $_GET['action'] ?? 'list';
    if ($action === 'delete') {
        if (!isset($_GET['csrf']) || $_GET['csrf'] !== ($_SESSION['csrf'] ?? '')) {
            die('CSRF token tidak valid.');
        }
        db()->prepare("DELETE FROM prodi WHERE id=?")->execute([$_GET['id']]);
        flash_set('success', 'Prodi dihapus.'); redirect('?page=prodi');
    }
    if ($action === 'create' || $action === 'edit') {
        $d = ['id'=>'','kode'=>'','nama'=>'','jenjang'=>'S1'];
        if ($action === 'edit') { $st = db()->prepare("SELECT * FROM prodi WHERE id=?"); $st->execute([$_GET['id']]); $d = $st->fetch() ?: $d; }
        ob_start(); ?>
        <h1><?=$action==='create'?'Tambah':'Edit'?> Prodi</h1>
        <form method="post">
            <?=csrf_field()?><input type="hidden" name="id" value="<?=$d['id']?>">
            <?=input('kode','Kode Prodi',$d['kode'],'text',true)?>
            <?=input('nama','Nama Prodi',$d['nama'],'text',true)?>
            <label>Jenjang <select name="jenjang"><?php foreach (['D3','D4','S1','S2'] as $j): $s=$j==$d['jenjang']?' selected':''; echo "<option$s>$j</option>"; endforeach; ?></select></label>
            <button type="submit">Simpan</button>
            <a href="?page=prodi" role="button" class="secondary">Batal</a>
        </form>
        <?php return ob_get_clean();
    }
    $rows = db()->query("SELECT * FROM prodi ORDER BY kode");
    ob_start(); ?>
    <h1>Program Studi</h1>
    <a href="?page=prodi&action=create" role="button" style="float:right">+ Tambah</a>
    <table><thead><tr><th>Kode</th><th>Nama</th><th>Jenjang</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?=e($r['kode'])?></td><td><?=e($r['nama'])?></td><td><?=e($r['jenjang'])?></td>
    <td><a href="?page=prodi&action=edit&id=<?=$r['id']?>">Edit</a> | <form method="get" style="display:inline"><input type="hidden" name="page" value="prodi"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><button type="submit" onclick="return confirm('Hapus?')" style="background:none;border:none;color:inherit;text-decoration:underline;padding:0">Hapus</button></form></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php return ob_get_clean();
}

function handle_mahasiswa(): string {
    require_role('admin');
    if (method('POST')) { verify_csrf();
        $st = db()->prepare("INSERT OR REPLACE INTO mahasiswa (id, nim, nama, prodi_id, angkatan, alamat, no_telp, email) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$_POST['id']?:null, $_POST['nim']??'', $_POST['nama']??'', $_POST['prodi_id']??0, $_POST['angkatan']??'', $_POST['alamat']??'', $_POST['no_telp']??'', $_POST['email']??'']);
        flash_set('success', 'Mahasiswa tersimpan.'); redirect('?page=mahasiswa');
    }
    $action = $_GET['action'] ?? 'list';
    if ($action === 'delete') {
        if (!isset($_GET['csrf']) || $_GET['csrf'] !== ($_SESSION['csrf'] ?? '')) {
            die('CSRF token tidak valid.');
        }
        db()->prepare("DELETE FROM mahasiswa WHERE id=?")->execute([$_GET['id']]);
        flash_set('success', 'Mahasiswa dihapus.'); redirect('?page=mahasiswa');
    }
    if ($action === 'create' || $action === 'edit') {
        $d = ['id'=>'','nim'=>'','nama'=>'','prodi_id'=>'','angkatan'=>'','alamat'=>'','no_telp'=>'','email'=>''];
        if ($action === 'edit') { $st = db()->prepare("SELECT * FROM mahasiswa WHERE id=?"); $st->execute([$_GET['id']]); $d = $st->fetch() ?: $d; }
        ob_start(); ?>
        <h1><?=$action==='create'?'Tambah':'Edit'?> Mahasiswa</h1>
        <form method="post">
            <?=csrf_field()?><input type="hidden" name="id" value="<?=$d['id']?>">
            <?=input('nim','NIM',$d['nim'],'text',true)?>
            <?=input('nama','Nama',$d['nama'],'text',true)?>
            <label>Prodi <select name="prodi_id" required><?=select_opts('prodi','nama',$d['prodi_id'])?></select></label>
            <?=input('angkatan','Angkatan',$d['angkatan'],'text',true)?>
            <?=textarea('alamat','Alamat',$d['alamat'])?>
            <?=input('no_telp','No. Telp',$d['no_telp'])?>
            <?=input('email','Email',$d['email'],'email')?>
            <button type="submit">Simpan</button>
            <a href="?page=mahasiswa" role="button" class="secondary">Batal</a>
        </form>
        <?php return ob_get_clean();
    }
    $rows = db()->query("SELECT m.*, p.nama AS prodi_nama FROM mahasiswa m LEFT JOIN prodi p ON p.id=m.prodi_id ORDER BY m.nim");
    ob_start(); ?>
    <h1>Mahasiswa</h1>
    <a href="?page=mahasiswa&action=create" role="button" style="float:right">+ Tambah</a>
    <table><thead><tr><th>NIM</th><th>Nama</th><th>Prodi</th><th>Angkatan</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?=e($r['nim'])?></td><td><?=e($r['nama'])?></td><td><?=e($r['prodi_nama']??'')?></td><td><?=e($r['angkatan'])?></td>
    <td><a href="?page=mahasiswa&action=edit&id=<?=$r['id']?>">Edit</a> | <form method="get" style="display:inline"><input type="hidden" name="page" value="mahasiswa"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><button type="submit" onclick="return confirm('Hapus?')" style="background:none;border:none;color:inherit;text-decoration:underline;padding:0">Hapus</button></form></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php return ob_get_clean();
}

function handle_dosen(): string {
    require_role('admin');
    if (method('POST')) { verify_csrf();
        $st = db()->prepare("INSERT OR REPLACE INTO dosen (id, nidn, nama, prodi_id, alamat, no_telp, email) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$_POST['id']?:null, $_POST['nidn']??'', $_POST['nama']??'', $_POST['prodi_id']??0, $_POST['alamat']??'', $_POST['no_telp']??'', $_POST['email']??'']);
        flash_set('success', 'Dosen tersimpan.'); redirect('?page=dosen');
    }
    $action = $_GET['action'] ?? 'list';
    if ($action === 'delete') {
        if (!isset($_GET['csrf']) || $_GET['csrf'] !== ($_SESSION['csrf'] ?? '')) {
            die('CSRF token tidak valid.');
        }
        db()->prepare("DELETE FROM dosen WHERE id=?")->execute([$_GET['id']]);
        flash_set('success', 'Dosen dihapus.'); redirect('?page=dosen');
    }
    if ($action === 'create' || $action === 'edit') {
        $d = ['id'=>'','nidn'=>'','nama'=>'','prodi_id'=>'','alamat'=>'','no_telp'=>'','email'=>''];
        if ($action === 'edit') { $st = db()->prepare("SELECT * FROM dosen WHERE id=?"); $st->execute([$_GET['id']]); $d = $st->fetch() ?: $d; }
        ob_start(); ?>
        <h1><?=$action==='create'?'Tambah':'Edit'?> Dosen</h1>
        <form method="post">
            <?=csrf_field()?><input type="hidden" name="id" value="<?=$d['id']?>">
            <?=input('nidn','NIDN',$d['nidn'],'text',true)?>
            <?=input('nama','Nama',$d['nama'],'text',true)?>
            <label>Prodi <select name="prodi_id" required><?=select_opts('prodi','nama',$d['prodi_id'])?></select></label>
            <?=textarea('alamat','Alamat',$d['alamat'])?>
            <?=input('no_telp','No. Telp',$d['no_telp'])?>
            <?=input('email','Email',$d['email'],'email')?>
            <button type="submit">Simpan</button>
            <a href="?page=dosen" role="button" class="secondary">Batal</a>
        </form>
        <?php return ob_get_clean();
    }
    $rows = db()->query("SELECT d.*, p.nama AS prodi_nama FROM dosen d LEFT JOIN prodi p ON p.id=d.prodi_id ORDER BY d.nidn");
    ob_start(); ?>
    <h1>Dosen</h1>
    <a href="?page=dosen&action=create" role="button" style="float:right">+ Tambah</a>
    <table><thead><tr><th>NIDN</th><th>Nama</th><th>Prodi</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?=e($r['nidn'])?></td><td><?=e($r['nama'])?></td><td><?=e($r['prodi_nama']??'')?></td>
    <td><a href="?page=dosen&action=edit&id=<?=$r['id']?>">Edit</a> | <form method="get" style="display:inline"><input type="hidden" name="page" value="dosen"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><button type="submit" onclick="return confirm('Hapus?')" style="background:none;border:none;color:inherit;text-decoration:underline;padding:0">Hapus</button></form></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php return ob_get_clean();
}

function handle_mata_kuliah(): string {
    require_role('admin');
    if (method('POST')) { verify_csrf();
        $st = db()->prepare("INSERT OR REPLACE INTO mata_kuliah (id, kode, nama, sks, prodi_id, semester) VALUES (?,?,?,?,?,?)");
        $st->execute([$_POST['id']?:null, $_POST['kode']??'', $_POST['nama']??'', $_POST['sks']??3, $_POST['prodi_id']??0, $_POST['semester']??1]);
        flash_set('success', 'Mata kuliah tersimpan.'); redirect('?page=mata_kuliah');
    }
    $action = $_GET['action'] ?? 'list';
    if ($action === 'delete') {
        if (!isset($_GET['csrf']) || $_GET['csrf'] !== ($_SESSION['csrf'] ?? '')) {
            die('CSRF token tidak valid.');
        }
        db()->prepare("DELETE FROM mata_kuliah WHERE id=?")->execute([$_GET['id']]);
        flash_set('success', 'MK dihapus.'); redirect('?page=mata_kuliah');
    }
    if ($action === 'create' || $action === 'edit') {
        $d = ['id'=>'','kode'=>'','nama'=>'','sks'=>'3','prodi_id'=>'','semester'=>'1'];
        if ($action === 'edit') { $st = db()->prepare("SELECT * FROM mata_kuliah WHERE id=?"); $st->execute([$_GET['id']]); $d = $st->fetch() ?: $d; }
        ob_start(); ?>
        <h1><?=$action==='create'?'Tambah':'Edit'?> Mata Kuliah</h1>
        <form method="post">
            <?=csrf_field()?><input type="hidden" name="id" value="<?=$d['id']?>">
            <?=input('kode','Kode MK',$d['kode'],'text',true)?>
            <?=input('nama','Nama MK',$d['nama'],'text',true)?>
            <?=input('sks','SKS',$d['sks'],'number',true)?>
            <label>Prodi <select name="prodi_id" required><?=select_opts('prodi','nama',$d['prodi_id'])?></select></label>
            <?=input('semester','Semester',$d['semester'],'number',true)?>
            <button type="submit">Simpan</button>
            <a href="?page=mata_kuliah" role="button" class="secondary">Batal</a>
        </form>
        <?php return ob_get_clean();
    }
    $rows = db()->query("SELECT mk.*, p.nama AS prodi_nama FROM mata_kuliah mk LEFT JOIN prodi p ON p.id=mk.prodi_id ORDER BY mk.kode");
    ob_start(); ?>
    <h1>Mata Kuliah</h1>
    <a href="?page=mata_kuliah&action=create" role="button" style="float:right">+ Tambah</a>
    <table><thead><tr><th>Kode</th><th>Nama</th><th>SKS</th><th>Prodi</th><th>Smstr</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?=e($r['kode'])?></td><td><?=e($r['nama'])?></td><td><?=$r['sks']?></td><td><?=e($r['prodi_nama']??'')?></td><td><?=$r['semester']?></td>
    <td><a href="?page=mata_kuliah&action=edit&id=<?=$r['id']?>">Edit</a> | <form method="get" style="display:inline"><input type="hidden" name="page" value="mata_kuliah"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><button type="submit" onclick="return confirm('Hapus?')" style="background:none;border:none;color:inherit;text-decoration:underline;padding:0">Hapus</button></form></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php return ob_get_clean();
}

function handle_tahun_akademik(): string {
    require_role('admin');
    if (method('POST')) { verify_csrf();
        if (isset($_POST['activate'])) {
            db_transaction(function($db) {
                $db->exec("UPDATE tahun_akademik SET is_active=0");
                $db->prepare("UPDATE tahun_akademik SET is_active=1 WHERE id=?")->execute([$_POST['id']]);
            });
            flash_set('success', 'Tahun akademik aktif diubah.'); redirect('?page=tahun_akademik');
        }
        $st = db()->prepare("INSERT OR REPLACE INTO tahun_akademik (id, tahun, semester, is_active) VALUES (?,?,?,?)");
        $st->execute([$_POST['id']?:null, $_POST['tahun']??'', $_POST['semester']??'Ganjil', isset($_POST['is_active'])?1:0]);
        flash_set('success', 'Tahun akademik tersimpan.'); redirect('?page=tahun_akademik');
    }
    $action = $_GET['action'] ?? 'list';
    if ($action === 'delete') {
        if (!isset($_GET['csrf']) || $_GET['csrf'] !== ($_SESSION['csrf'] ?? '')) {
            die('CSRF token tidak valid.');
        }
        db()->prepare("DELETE FROM tahun_akademik WHERE id=?")->execute([$_GET['id']]);
        flash_set('success', 'T.A. dihapus.'); redirect('?page=tahun_akademik');
    }
    if ($action === 'create' || $action === 'edit') {
        $d = ['id'=>'','tahun'=>'','semester'=>'Ganjil','is_active'=>0];
        if ($action === 'edit') { $st = db()->prepare("SELECT * FROM tahun_akademik WHERE id=?"); $st->execute([$_GET['id']]); $d = $st->fetch() ?: $d; }
        ob_start(); ?>
        <h1><?=$action==='create'?'Tambah':'Edit'?> Tahun Akademik</h1>
        <form method="post">
            <?=csrf_field()?><input type="hidden" name="id" value="<?=$d['id']?>">
            <?=input('tahun','Tahun (contoh: 2024/2025)',$d['tahun'],'text',true)?>
            <label>Semester <select name="semester"><?php foreach (['Ganjil','Genap'] as $s): $sel=$s==$d['semester']?' selected':''; echo "<option$sel>$s</option>"; endforeach; ?></select></label>
            <label><input type="checkbox" name="is_active" value="1" <?=$d['is_active']?'checked':''?>> Aktifkan</label>
            <button type="submit">Simpan</button>
            <a href="?page=tahun_akademik" role="button" class="secondary">Batal</a>
        </form>
        <?php return ob_get_clean();
    }
    $rows = db()->query("SELECT * FROM tahun_akademik ORDER BY tahun DESC, semester DESC");
    ob_start(); ?>
    <h1>Tahun Akademik</h1>
    <a href="?page=tahun_akademik&action=create" role="button" style="float:right">+ Tambah</a>
    <table><thead><tr><th>Tahun</th><th>Semester</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?=e($r['tahun'])?></td><td><?=e($r['semester'])?></td>
    <td><?=$r['is_active']?'<mark>Aktif</mark>':'Tidak'?></td>
    <td><a href="?page=tahun_akademik&action=edit&id=<?=$r['id']?>">Edit</a>
        <?php if (!$r['is_active']): ?> | <form method="get" style="display:inline"><input type="hidden" name="page" value="tahun_akademik"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><button type="submit" onclick="return confirm('Hapus?')" style="background:none;border:none;color:inherit;text-decoration:underline;padding:0">Hapus</button></form><?php endif; ?>
        <?php if (!$r['is_active']): ?> | <form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><button type="submit" name="activate" value="1" class="outline" style="display:inline;width:auto;padding:0 0.5rem">Aktifkan</button></form><?php endif; ?>
    </td></tr>
    <?php endforeach; ?></tbody></table>
    <?php return ob_get_clean();
}

function handle_kelas(): string {
    require_role('admin');
    if (method('POST') && isset($_POST['save_kelas'])) { verify_csrf();
        log_info('Saving kelas', ['kelas_id' => $_POST['id'] ?? 'new', 'trace_id' => get_trace_id()]);
        db_transaction(function($db) {
            $st = $db->prepare("INSERT OR REPLACE INTO kelas (id, mk_id, dosen_id, tahun_akademik_id, nama_kelas, kuota) VALUES (?,?,?,?,?,?)");
            $st->execute([$_POST['id']?:null, $_POST['mk_id']??0, $_POST['dosen_id']??0, $_POST['tahun_akademik_id']??0, $_POST['nama_kelas']??'', $_POST['kuota']??40]);
            $kelas_id = $_POST['id'] ?: $db->lastInsertId();
            if (!empty($_POST['id'])) {
                $db->prepare("DELETE FROM jadwal WHERE kelas_id=?")->execute([$kelas_id]);
            }
            $hari = $_POST['hari'] ?? [];
            foreach ($hari as $i => $h) {
                if (empty($h) || empty($_POST['jam_mulai'][$i])) continue;
                $st = $db->prepare("INSERT INTO jadwal (kelas_id, hari, jam_mulai, jam_selesai, ruang) VALUES (?,?,?,?,?)");
                $st->execute([$kelas_id, $h, $_POST['jam_mulai'][$i]??'', $_POST['jam_selesai'][$i]??'', $_POST['ruang'][$i]??'']);
            }
        });
        flash_set('success', 'Kelas tersimpan.'); redirect('?page=kelas');
    }

    $action = $_GET['action'] ?? 'list';
    if ($action === 'delete') {
        if (!isset($_GET['csrf']) || $_GET['csrf'] !== ($_SESSION['csrf'] ?? '')) {
            die('CSRF token tidak valid.');
        }
        log_info('Deleting kelas', ['kelas_id' => $_GET['id'], 'trace_id' => get_trace_id()]);
        db()->prepare("DELETE FROM kelas WHERE id=?")->execute([$_GET['id']]);
        flash_set('success', 'Kelas dihapus.'); redirect('?page=kelas');
    }
}

function handle_krs(): string {
    $u = current_user();
    if ($u['role'] === 'mahasiswa') return handle_krs_mhs($u);
    require_role('admin'); return handle_krs_admin();
}

function handle_krs_mhs(array $u): string {
    $mhs_id = $u['linked_id'];

    if (method('POST')) { verify_csrf();
        log_info('KRS enrollment attempt', ['mahasiswa_id' => $mhs_id, 'class_id' => $_POST['kelas_id'] ?? null, 'trace_id' => get_trace_id()]);
        $ta_aktif = db()->query("SELECT id, tahun, semester FROM tahun_akademik WHERE is_active=1 LIMIT 1")->fetch();
        if (!$ta_aktif) {
            flash_set('error', 'Tidak ada tahun akademik aktif.');
            redirect('?page=krs');
        }
        $ta_id = $ta_aktif['id'];
        
        $result = db_transaction(function($db) use ($mhs_id, $ta_id) {
            $chk = $db->prepare("SELECT id, tahun_akademik_id, kuota FROM kelas WHERE id=?");
            $chk->execute([$_POST['kelas_id']]);
            $row = $chk->fetch();
            
            if (!$row || $row['tahun_akademik_id'] != $ta_id) {
                log_warning('KRS enrollment failed - invalid class', ['class_id' => $_POST['kelas_id'] ?? null, 'trace_id' => get_trace_id()]);
                return ['error' => 'Kelas tidak valid atau tidak dalam tahun akademik aktif.'];
            }
            
            $terisi = $db->prepare("SELECT COUNT(*) as cnt FROM krs WHERE kelas_id=?")->execute([$_POST['kelas_id']])->fetch();
            if ($terisi['cnt'] >= $row['kuota']) {
                log_warning('KRS enrollment failed - class full', ['class_id' => $_POST['kelas_id'] ?? null, 'trace_id' => get_trace_id()]);
                return ['error' => 'Kelas sudah penuh.'];
            }
            
            $existing = $db->prepare("SELECT id FROM krs WHERE mahasiswa_id=? AND kelas_id=?")->execute([$mhs_id, $_POST['kelas_id']])->fetch();
            if ($existing) {
                log_warning('KRS enrollment failed - already enrolled', ['mahasiswa_id' => $mhs_id, 'class_id' => $_POST['kelas_id'] ?? null, 'trace_id' => get_trace_id()]);
                return ['error' => 'Anda sudah terdaftar di kelas ini.'];
            }
            
            $st = $db->prepare("INSERT INTO krs (mahasiswa_id, kelas_id, tahun_akademik_id, status) VALUES (?,?,?,'disetujui')");
            $st->execute([$mhs_id, $_POST['kelas_id'], $ta_id]);
            log_info('KRS enrollment successful', ['mahasiswa_id' => $mhs_id, 'class_id' => $_POST['kelas_id'] ?? null, 'trace_id' => get_trace_id()]);
            return ['success' => true];
        });
        
        if (isset($result['error'])) {
            flash_set('error', $result['error']);
        } else {
            // Notify mahasiswa of successful KRS enrollment
            $user_st = db()->prepare("SELECT u.id FROM users u WHERE u.role = 'mahasiswa' AND u.linked_id = ?");
            $user_st->execute([$mhs_id]);
            $uid = $user_st->fetchColumn();
            if ($uid) {
                $mk_info = db()->prepare("SELECT mk.nama FROM kelas k JOIN mata_kuliah mk ON mk.id = k.mk_id WHERE k.id = ?");
                $mk_info->execute([$_POST['kelas_id']]);
                $mk_nama = $mk_info->fetchColumn() ?: 'Kelas';
                notify((int)$uid, 'krs', "KRS Anda telah disetujui untuk $mk_nama");
            }
            flash_set('success', 'KRS berhasil didaftarkan.');
        }
        redirect('?page=krs');
    }
}

function handle_krs_admin(): string {
    $ta_id = $_GET['ta_id'] ?? db()->query("SELECT id FROM tahun_akademik WHERE is_active=1 LIMIT 1")->fetchColumn();
    $rows = db()->prepare("
        SELECT krs.*, m.nim, m.nama AS mhs_nama, mk.nama AS mk_nama, mk.kode AS mk_kode, k.nama_kelas
        FROM krs
        JOIN mahasiswa m ON m.id=krs.mahasiswa_id
        JOIN kelas k ON k.id=krs.kelas_id
        JOIN mata_kuliah mk ON mk.id=k.mk_id
        WHERE krs.tahun_akademik_id=?
        ORDER BY m.nim
    "); $rows->execute([$ta_id]);

    ob_start(); ?>
    <h1>Data KRS</h1>
    <label>Tahun Akademik <select onchange="window.location='?page=krs&ta_id='+this.value">
        <?php $tas = db()->query("SELECT id, tahun || ' ' || semester AS label FROM tahun_akademik ORDER BY tahun DESC");
        foreach ($tas as $ta) { $s = $ta['id']==$ta_id?' selected':''; echo "<option value=\"{$ta['id']}\"$s>" . e($ta['label']) . "</option>"; } ?>
    </select></label>
    <table><thead><tr><th>NIM</th><th>Mahasiswa</th><th>MK</th><th>Kelas</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><?=e($r['nim'])?></td><td><?=e($r['mhs_nama'])?></td><td><?=e($r['mk_nama'])?></td><td><?=e($r['nama_kelas'])?></td><td><?=e($r['status'])?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php return ob_get_clean();
}

function handle_nilai(): string {
    require_role('admin', 'dosen');
    $u = current_user();
    $kelas_id = $_GET['kelas_id'] ?? $_POST['kelas_id'] ?? null;
    if (!$kelas_id) {
        if ($u['role'] === 'dosen') {
            return '<h1>Input Nilai</h1><p>Pilih kelas dari <a href="?page=dashboard">Dashboard</a>.</p>';
        }
        ob_start(); ?>
        <h1>Input Nilai</h1>
        <?php $rows = db()->query("
            SELECT k.id, mk.nama AS mk_nama, k.nama_kelas, d.nama AS dosen_nama,
                   ta.tahun, ta.semester
            FROM kelas k JOIN mata_kuliah mk ON mk.id=k.mk_id
            LEFT JOIN dosen d ON d.id=k.dosen_id
            LEFT JOIN tahun_akademik ta ON ta.id=k.tahun_akademik_id
            ORDER BY ta.tahun DESC, mk.nama
        "); ?>
        <table><thead><tr><th>MK</th><th>Kelas</th><th>Dosen</th><th>T.A.</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
        <tr><td><?=e($r['mk_nama'])?></td><td><?=e($r['nama_kelas'])?></td><td><?=e($r['dosen_nama']??'-')?></td><td><?=e($r['tahun'])?> <?=e($r['semester'])?></td>
        <td><a href="?page=nilai&kelas_id=<?=$r['id']?>">Input Nilai</a></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php return ob_get_clean();
    }

    if ($u['role'] === 'dosen') {
        $chk = db()->prepare("SELECT id FROM kelas WHERE id=? AND dosen_id=?");
        $chk->execute([$kelas_id, $u['linked_id']]); if (!$chk->fetch()) {
            log_warning('Access denied to input nilai', ['kelas_id' => $kelas_id, 'user_id' => $u['id'] ?? null, 'trace_id' => get_trace_id()]);
            die('Akses ditolak.');
        }
    }

    if (method('POST')) { verify_csrf();
        log_info('Saving nilai', ['kelas_id' => $kelas_id, 'count' => count($_POST['nilai'] ?? []), 'trace_id' => get_trace_id()]);
        foreach (($_POST['nilai'] ?? []) as $krs_id => $na) {
            if ($na === '') continue;
            $na = (float)$na;
            if ($na < 0 || $na > 100) continue;
            if ($u['role'] === 'dosen') {
                $chk = db()->prepare("SELECT kelas_id FROM krs WHERE id=?");
                $chk->execute([$krs_id]); $row = $chk->fetch();
                if (!$row || $row['kelas_id'] != $kelas_id) continue;
            }
            $st = db()->prepare("INSERT OR REPLACE INTO nilai (krs_id, nilai_angka) VALUES (?, ?)");
            $st->execute([$krs_id, $na]);
        }
        // Notify students in this class that grades were published
        $mk_info = db()->prepare("SELECT mk.nama FROM kelas k JOIN mata_kuliah mk ON mk.id = k.mk_id WHERE k.id = ?");
        $mk_info->execute([$kelas_id]);
        $mk_nama = $mk_info->fetchColumn() ?: 'Mata Kuliah';
        $enrolled = db()->prepare("SELECT m.id AS mhs_id, u.id AS user_id FROM krs JOIN mahasiswa m ON m.id = krs.mahasiswa_id JOIN users u ON u.linked_id = m.id AND u.role = 'mahasiswa' WHERE krs.kelas_id = ?");
        $enrolled->execute([$kelas_id]);
        foreach ($enrolled as $row) {
            notify($row['user_id'], 'nilai', "Nilai telah dipublikasikan untuk $mk_nama");
        }
        flash_set('success', 'Nilai tersimpan.'); redirect('?page=nilai&kelas_id=' . $kelas_id);
    }
}

function handle_presensi(): string {
    require_role('admin', 'dosen');
    $u = current_user();
    $kelas_id = $_GET['kelas_id'] ?? $_POST['kelas_id'] ?? null;
    $tanggal = $_GET['tanggal'] ?? $_POST['tanggal'] ?? date('Y-m-d');

    if (!$kelas_id) {
        if ($u['role'] === 'dosen') {
            return '<h1>Presensi</h1><p>Pilih kelas dari <a href="?page=dashboard">Dashboard</a>.</p>';
        }
        ob_start(); ?>
        <h1>Presensi</h1>
        <?php $rows = db()->query("
            SELECT k.id, mk.nama AS mk_nama, k.nama_kelas, d.nama AS dosen_nama,
                   ta.tahun, ta.semester
            FROM kelas k JOIN mata_kuliah mk ON mk.id=k.mk_id
            LEFT JOIN dosen d ON d.id=k.dosen_id
            LEFT JOIN tahun_akademik ta ON ta.id=k.tahun_akademik_id
            ORDER BY ta.tahun DESC, mk.nama
        "); ?>
        <table><thead><tr><th>MK</th><th>Kelas</th><th>Dosen</th><th>T.A.</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
        <tr><td><?=e($r['mk_nama'])?></td><td><?=e($r['nama_kelas'])?></td><td><?=e($r['dosen_nama']??'-')?></td><td><?=e($r['tahun'])?> <?=e($r['semester'])?></td>
        <td><a href="?page=presensi&kelas_id=<?=$r['id']?>">Presensi</a></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php return ob_get_clean();
    }

    if ($u['role'] === 'dosen') {
        $chk = db()->prepare("SELECT id FROM kelas WHERE id=? AND dosen_id=?");
        $chk->execute([$kelas_id, $u['linked_id']]); if (!$chk->fetch()) {
            log_warning('Access denied to presensi', ['kelas_id' => $kelas_id, 'user_id' => $u['id'] ?? null, 'trace_id' => get_trace_id()]);
            die('Akses ditolak.');
        }
    }

    if (method('POST')) { verify_csrf();
        log_info('Saving presensi', ['kelas_id' => $kelas_id, 'tanggal' => $tanggal, 'count' => count($_POST['status'] ?? []), 'trace_id' => get_trace_id()]);
        $tanggal = $_POST['tanggal'];
        $st = db()->prepare("INSERT OR REPLACE INTO presensi (kelas_id, mahasiswa_id, tanggal, status) VALUES (?,?,?,?)");
        foreach (($_POST['status'] ?? []) as $mhs_id => $status) {
            if (!in_array($status, ['hadir','sakit','izin','alpha'])) continue;
            if ($u['role'] === 'dosen') {
                $chk = db()->prepare("SELECT id FROM krs WHERE kelas_id=? AND mahasiswa_id=?");
                $chk->execute([$kelas_id, $mhs_id]); if (!$chk->fetch()) continue;
            }
            $st->execute([$kelas_id, $mhs_id, $tanggal, $status]);
            // Notify student if marked alpha
            if ($status === 'alpha') {
                $mk_info = db()->prepare("SELECT mk.nama FROM kelas k JOIN mata_kuliah mk ON mk.id = k.mk_id WHERE k.id = ?");
                $mk_info->execute([$kelas_id]);
                $mk_nama = $mk_info->fetchColumn() ?: 'Mata Kuliah';
                $user_st = db()->prepare("SELECT u.id FROM users u WHERE u.role = 'mahasiswa' AND u.linked_id = ?");
                $user_st->execute([$mhs_id]);
                $uid = $user_st->fetchColumn();
                if ($uid) {
                    notify((int)$uid, 'presensi', "Anda tercatat alpha pada $mk_nama tanggal $tanggal");
                }
            }
        }
        flash_set('success', "Presensi $tanggal tersimpan.");
        redirect("?page=presensi&kelas_id=$kelas_id&tanggal=$tanggal");
    }
}

function handle_khs(): string {
    $u = current_user();
    $mhs_id = $_GET['mhs_id'] ?? $u['linked_id'];
    if ($u['role'] !== 'admin' && $u['role'] !== 'mahasiswa') die('Akses ditolak.');
    if ($u['role'] === 'mahasiswa') $mhs_id = $u['linked_id'];

    $mhs = db()->prepare("SELECT m.*, p.nama AS prodi_nama FROM mahasiswa m LEFT JOIN prodi p ON p.id=m.prodi_id WHERE m.id=?");
    $mhs->execute([$mhs_id]); $mhs = $mhs->fetch() or die('Mahasiswa tidak ditemukan.');

    $ta_id = $_GET['ta_id'] ?? db()->query("SELECT id FROM tahun_akademik WHERE is_active=1 LIMIT 1")->fetchColumn();

    $rows = [];
    if ($ta_id) {
        $rows = db()->prepare("
            SELECT mk.kode, mk.nama AS mk_nama, mk.sks, k.nama_kelas,
                   n.nilai_angka,
                   CASE WHEN n.nilai_angka >= 80 THEN 'A' WHEN n.nilai_angka >= 70 THEN 'B'
                        WHEN n.nilai_angka >= 60 THEN 'C' WHEN n.nilai_angka >= 50 THEN 'D'
                        WHEN n.nilai_angka IS NOT NULL THEN 'E' ELSE '-' END AS nilai_huruf
            FROM krs JOIN kelas k ON k.id=krs.kelas_id
            JOIN mata_kuliah mk ON mk.id=k.mk_id
            LEFT JOIN nilai n ON n.krs_id=krs.id
            WHERE krs.mahasiswa_id=? AND krs.tahun_akademik_id=?
            ORDER BY mk.nama
        "); $rows->execute([$mhs_id, $ta_id]); $rows = $rows->fetchAll();
    }

    $total_sks = 0; $total_bobot = 0; $total_mk = 0;
    $bobot = ['A'=>4,'B'=>3,'C'=>2,'D'=>1,'E'=>0];
    foreach ($rows as $r) {
        if ($r['nilai_huruf'] !== '-' && isset($bobot[$r['nilai_huruf']])) {
            $total_sks += $r['sks']; $total_bobot += $bobot[$r['nilai_huruf']] * $r['sks']; $total_mk++;
        }
    }
    $ipk = $total_sks > 0 ? round($total_bobot / $total_sks, 2) : 0;

    ob_start(); ?>
    <h1>Kartu Hasil Studi (KHS)</h1>
    <div class="grid">
        <article><strong>NIM:</strong> <?=e($mhs['nim'])?></article>
        <article><strong>Nama:</strong> <?=e($mhs['nama'])?></article>
        <article><strong>Prodi:</strong> <?=e($mhs['prodi_nama']??'')?></article>
    </div>
    <label>Tahun Akademik <select onchange="window.location='?page=khs&<?=$u['role']==='admin'?'mhs_id='.$mhs_id.'&':''?>ta_id='+this.value">
        <?php $tas = db()->query("SELECT id, tahun || ' ' || semester AS label FROM tahun_akademik ORDER BY tahun DESC, semester DESC");
        foreach ($tas as $ta) { $s = $ta['id']==$ta_id?' selected':''; echo "<option value=\"{$ta['id']}\"$s>" . e($ta['label']) . "</option>"; } ?>
    </select></label>
    <table><thead><tr><th>Kode</th><th>Mata Kuliah</th><th>SKS</th><th>Kelas</th><th>Nilai</th><th>Bobot</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $b = $bobot[$r['nilai_huruf']] ?? '-'; ?>
    <tr><td><?=e($r['kode'])?></td><td><?=e($r['mk_nama'])?></td><td><?=$r['sks']?></td><td><?=e($r['nama_kelas'])?></td><td><strong><?=e($r['nilai_huruf'])?></strong></td><td><?=$b?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <article><strong>Total MK: <?=$total_mk?></strong> | <strong>Total SKS: <?=$total_sks?></strong> | <strong>IPK: <?=$ipk?></strong></article>
    <?php return ob_get_clean();
}

function jadwal_table_mhs(int $mhs_id): string {
    $ta = db()->query("SELECT id FROM tahun_akademik WHERE is_active=1 LIMIT 1")->fetch();
    if (!$ta) return '<p>Belum ada tahun akademik aktif.</p>';
    $rows = db()->prepare("
        SELECT j.hari, j.jam_mulai, j.jam_selesai, j.ruang,
               mk.nama AS mk_nama, mk.kode AS mk_kode, mk.sks, k.nama_kelas
        FROM krs JOIN kelas k ON k.id=krs.kelas_id
        JOIN mata_kuliah mk ON mk.id=k.mk_id
        JOIN jadwal j ON j.kelas_id=k.id
        WHERE krs.mahasiswa_id=? AND krs.tahun_akademik_id=?
        ORDER BY CASE j.hari WHEN 'Senin' THEN 1 WHEN 'Selasa' THEN 2 WHEN 'Rabu' THEN 3
            WHEN 'Kamis' THEN 4 WHEN 'Jumat' THEN 5 WHEN 'Sabtu' THEN 6 ELSE 7 END, j.jam_mulai
    "); $rows->execute([$mhs_id, $ta['id']]);
    ob_start(); ?>
    <table><thead><tr><th>Hari</th><th>Jam</th><th>MK</th><th>SKS</th><th>Kelas</th><th>Ruang</th></tr></thead><tbody>
    <?php $has=false; foreach ($rows as $r): $has=true; ?>
    <tr><td><?=e($r['hari'])?></td><td><?=e(substr($r['jam_mulai'],0,5))?>-<?=e(substr($r['jam_selesai'],0,5))?></td>
    <td><?=e($r['mk_nama'])?></td><td><?=$r['sks']?></td><td><?=e($r['nama_kelas'])?></td><td><?=e($r['ruang'])?></td></tr>
    <?php endforeach; if(!$has) echo '<tr><td colspan="6">Belum ada jadwal.</td></tr>'; ?>
    </tbody></table>
     <?php return ob_get_clean();
}

function handle_landing_images(): string {
    require_role('admin');
    
    $u = current_user();
    $upload_dir = __DIR__ . '/uploads/landing';
    
    // Ensure upload directory exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $allowed_slots = ['hero', 'banner', 'logo'];
    $slot = $_POST['slot'] ?? null;
    $message = null;
    $message_type = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf(); // Dies if CSRF is invalid
        
        if (!$slot || !in_array($slot, $allowed_slots)) {
            $message = 'Invalid image slot selected';
            $message_type = 'error';
            log_warning('Invalid slot on landing_images upload', ['slot' => $slot, 'user_id' => $u['id'], 'trace_id' => get_trace_id()]);
        } elseif (!isset($_FILES['image'])) {
            $message = 'No file provided';
            $message_type = 'error';
        } else {
            $file = $_FILES['image'];
            $errors = validate_image_upload($file);
            
            if (!empty($errors)) {
                $message = implode(', ', $errors);
                $message_type = 'error';
                log_warning('Image upload validation failed', [
                    'slot' => $slot,
                    'filename' => $file['name'],
                    'errors' => $errors,
                    'user_id' => $u['id'],
                    'trace_id' => get_trace_id()
                ]);
            } else {
                try {
                    // Determine file extension from validated upload
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    
                    // Build target filename (always use slot name + extension)
                    $target_filename = $slot . '.' . $ext;
                    $target_path = $upload_dir . '/' . $target_filename;
                    
                    // Delete old file if exists
                    $old_files = glob($upload_dir . '/' . $slot . '.*');
                    foreach ($old_files as $old_file) {
                        if (is_file($old_file)) {
                            unlink($old_file);
                        }
                    }
                    
                    // Move uploaded file
                    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                        throw new Exception('Failed to move uploaded file');
                    }
                    
                    // Update database record
                    db_transaction(function ($db) use ($slot, $target_filename, $file, $u) {
                        $stmt = $db->prepare("
                            INSERT INTO landing_images (slot_name, original_filename, file_size, mime_type, uploaded_by)
                            VALUES (?, ?, ?, ?, ?)
                            ON CONFLICT(slot_name) DO UPDATE SET
                                original_filename = excluded.original_filename,
                                file_size = excluded.file_size,
                                mime_type = excluded.mime_type,
                                uploaded_at = CURRENT_TIMESTAMP,
                                uploaded_by = excluded.uploaded_by
                        ");
                        $stmt->execute([
                            $slot,
                            $file['name'],
                            $file['size'],
                            $file['type'],
                            $u['id']
                        ]);
                    });
                    
                    $message = "Image '{$slot}' uploaded successfully";
                    $message_type = 'success';
                    log_info('Landing image uploaded successfully', [
                        'slot' => $slot,
                        'filename' => $target_filename,
                        'size' => $file['size'],
                        'user_id' => $u['id'],
                        'trace_id' => get_trace_id()
                    ]);
                } catch (Exception $e) {
                    $message = 'Upload failed: ' . $e->getMessage();
                    $message_type = 'error';
                    log_error('Landing image upload exception', [
                        'slot' => $slot,
                        'error' => $e->getMessage(),
                        'user_id' => $u['id'],
                        'trace_id' => get_trace_id()
                    ]);
                }
            }
        }
    }
    
    // Get current landing images info
    $landing_images = [];
    $stmt = db()->prepare("SELECT slot_name, original_filename, file_size, uploaded_at FROM landing_images ORDER BY uploaded_at DESC");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $img) {
        $landing_images[$img['slot_name']] = $img;
    }
    
    ob_start();
    ?>
    <h2>Landing Page Image Upload</h2>
    
    <?php if ($message): ?>
        <div style="padding: 1rem; margin: 1rem 0; border-left: 4px solid <?=$message_type === 'success' ? '#4CAF50' : '#f44336'?>; background: <?=$message_type === 'success' ? '#e8f5e9' : '#ffebee'?>;">
            <?=e($message)?>
        </div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data" style="max-width: 500px;">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        
        <div style="margin-bottom: 1rem;">
            <label>Image Slot</label>
            <select name="slot" required>
                <option value="">Select slot...</option>
                <?php foreach ($allowed_slots as $s): ?>
                    <option value="<?=e($s)?>" <?=$slot === $s ? 'selected' : ''?>>
                        <?=e(ucfirst($s))?>
                        <?php if (isset($landing_images[$s])): ?>
                            (current: <?=e($landing_images[$s]['original_filename'])?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="margin-bottom: 1rem;">
            <label>Image File</label>
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
            <small>Max 2MB. Allowed: JPG, PNG, GIF, WebP</small>
        </div>
        
        <button type="submit">Upload Image</button>
    </form>
    
    <hr>
    <h3>Current Landing Images</h3>
    
    <?php if (empty($landing_images)): ?>
        <p><em>No images uploaded yet.</em></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Slot</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Uploaded</th>
                    <th>URL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($landing_images as $slot => $img): ?>
                    <tr>
                        <td><?=e(ucfirst($slot))?></td>
                        <td><?=e($img['original_filename'])?></td>
                        <td><?=number_format($img['file_size'] / 1024, 2)?> KB</td>
                        <td><?=e($img['uploaded_at'])?></td>
                        <td><code>/uploads/landing/<?=e($slot)?>.<?=e(pathinfo($img['original_filename'], PATHINFO_EXTENSION))?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <?php
    return ob_get_clean();
}

function handle_settings(): string {
    require_role('admin');
    
    if (method('POST')) {
        if (isset($_POST['save'])) {
            handle_settings_save();
            return '';
        }
        if (isset($_POST['upload'])) {
            handle_settings_upload();
            return '';
        }
    }
    
    $s = get_all_settings();
    $icons = ['krs', 'nilai', 'jadwal', 'calendar', 'book', 'graduation', 'clipboard', 'users', 'chart', 'clock', 'building', 'certificate'];
    
    ob_start(); ?>
    <h1>Pengaturan Site</h1>
    <form method="post" enctype="multipart/form-data">
        <?=csrf_field()?>
        <h2>Informasi Sekolah</h2>
        <label>Nama Sekolah <input type="text" name="school_name" value="<?=e($s['school_name'])?>" required></label>
        <label>Warna Aksen <input type="color" name="accent_color" value="<?=e($s['accent_color'])?>"></label>
        <h2>Hero Section</h2>
        <label>Judul Hero <input type="text" name="hero_title" value="<?=e($s['hero_title'])?>" required></label>
        <label>Subtitle Hero <input type="text" name="hero_subtitle" value="<?=e($s['hero_subtitle'])?>" required></label>
        <h2>Card 1</h2>
        <label>Judul Card 1 <input type="text" name="card_1_title" value="<?=e($s['card_1_title'])?>" required></label>
        <label>Deskripsi Card 1 <textarea name="card_1_desc" required><?=e($s['card_1_desc'])?></textarea></label>
        <label>Icon Card 1 <select name="card_1_icon"><?php foreach ($icons as $icon): $sel=$icon==$s['card_1_icon']?' selected':''; echo "<option value=\"$icon\"$sel>" . ucwords(str_replace('_', ' ', $icon)) . "</option>"; endforeach; ?></select></label>
        <h2>Card 2</h2>
        <label>Judul Card 2 <input type="text" name="card_2_title" value="<?=e($s['card_2_title'])?>" required></label>
        <label>Deskripsi Card 2 <textarea name="card_2_desc" required><?=e($s['card_2_desc'])?></textarea></label>
        <label>Icon Card 2 <select name="card_2_icon"><?php foreach ($icons as $icon): $sel=$icon==$s['card_2_icon']?' selected':''; echo "<option value=\"$icon\"$sel>" . ucwords(str_replace('_', ' ', $icon)) . "</option>"; endforeach; ?></select></label>
        <h2>Card 3</h2>
        <label>Judul Card 3 <input type="text" name="card_3_title" value="<?=e($s['card_3_title'])?>" required></label>
        <label>Deskripsi Card 3 <textarea name="card_3_desc" required><?=e($s['card_3_desc'])?></textarea></label>
        <label>Icon Card 3 <select name="card_3_icon"><?php foreach ($icons as $icon): $sel=$icon==$s['card_3_icon']?' selected':''; echo "<option value=\"$icon\"$sel>" . ucwords(str_replace('_', ' ', $icon)) . "</option>"; endforeach; ?></select></label>
        <h2>Footer</h2>
        <label>Alamat Footer <textarea name="footer_address" required><?=e($s['footer_address'])?></textarea></label>
        <label>Email Footer <input type="email" name="footer_email" value="<?=e($s['footer_email'])?>" required></label>
        <label>Telepon Footer <input type="text" name="footer_phone" value="<?=e($s['footer_phone'])?>" required></label>
        <label>Hak Cipta <input type="text" name="footer_copyright" value="<?=e($s['footer_copyright'])?>" required></label>
        <h2>Gambar</h2>
        <label>Logo (PNG, JPEG, SVG) <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml"></label>
        <label>Hero Image (PNG, JPEG) <input type="file" name="hero_image" accept="image/png,image/jpeg"></label>
        <button type="submit" name="save">Simpan Pengaturan</button>
        <button type="submit" name="upload">Unggah Gambar</button>
    </form>
    <?php return ob_get_clean();
}

function render_landing_page(): string {
    $s = get_all_settings();
    
    $logo_path = 'assets/defaults/logo.png';
    if (file_exists(__DIR__ . '/uploads/logo.png')) {
        $logo_path = 'uploads/logo.png';
    } elseif (file_exists(__DIR__ . '/uploads/logo.jpg')) {
        $logo_path = 'uploads/logo.jpg';
    } elseif (file_exists(__DIR__ . '/uploads/logo.jpeg')) {
        $logo_path = 'uploads/logo.jpeg';
    } elseif (file_exists(__DIR__ . '/uploads/logo.svg')) {
        $logo_path = 'uploads/logo.svg';
    }
    
    $hero_path = 'assets/defaults/hero.svg';
    if (file_exists(__DIR__ . '/uploads/hero.png')) {
        $hero_path = 'uploads/hero.png';
    } elseif (file_exists(__DIR__ . '/uploads/hero.jpg')) {
        $hero_path = 'uploads/hero.jpg';
    }
    
    $accent_color = $s['accent_color'] ?? '#2c7a4b';
    
    ob_start(); ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=e($s['school_name'] ?? 'SIAKAD')?></title>
    <style>
        :root {
            --accent-color: <?=e($accent_color)?>;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        header {
            background: #fff;
            border-bottom: 1px solid #eee;
            padding: 1rem 2rem;
        }
        header .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        header .logo img {
            height: 2.5rem;
            width: auto;
        }
        .hero {
            background: linear-gradient(135deg, #f6f7f9 0%, #eef0f2 100%);
            padding: 4rem 2rem;
            text-align: center;
        }
        .hero h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #222;
        }
        .hero p {
            font-size: 1.25rem;
            color: #666;
            max-width: 600px;
            margin: 0 auto 2rem;
        }
        .hero img {
            max-width: 100%;
            height: auto;
            max-height: 300px;
            object-fit: contain;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            padding: 3rem 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .card-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
        }
        .card h3 {
            margin-bottom: 0.75rem;
            color: var(--accent-color);
        }
        .card p {
            color: #666;
        }
        footer {
            background: #222;
            color: #fff;
            padding: 2rem 2rem 1rem;
        }
        footer .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }
        footer h4 {
            margin-bottom: 1rem;
            color: var(--accent-color);
        }
        footer p, footer a {
            color: #bbb;
            font-size: 0.9rem;
        }
        footer a { text-decoration: none; }
        footer a:hover { color: var(--accent-color); }
        footer .copyright {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #444;
            color: #888;
            font-size: 0.85rem;
        }
        @media (max-width: 768px) {
            .hero h1 { font-size: 1.75rem; }
            .cards { padding: 1rem; }
        }
    </style>
    <link rel="stylesheet" href="landing.css">
</head>
<body>
    <header>
        <div class="logo">
            <img src="<?=e($logo_path)?>" alt="Logo">
            <?=e($s['school_name'] ?? 'SIAKAD')?>
        </div>
    </header>
    
    <section class="hero">
        <img src="<?=e($hero_path)?>" alt="Hero Image">
        <h1><?=e($s['hero_title'] ?? 'Selamat Datang di Portal Akademik')?></h1>
        <p><?=e($s['hero_subtitle'] ?? 'Portal akademik untuk mahasiswa dan dosen')?></p>
    </section>
    
    <section class="cards">
        <article class="card">
            <img src="assets/icons/<?=e($s['card_1_icon'] ?? 'krs')?>.svg" class="card-icon" alt="Icon">
            <h3><?=e($s['card_1_title'] ?? 'KRS Online')?></h3>
            <p><?=e($s['card_1_desc'] ?? 'Pengisian Kartu Rencana Studi secara online')?></p>
        </article>
        <article class="card">
            <img src="assets/icons/<?=e($s['card_2_icon'] ?? 'nilai')?>.svg" class="card-icon" alt="Icon">
            <h3><?=e($s['card_2_title'] ?? 'Nilai & Transkrip')?></h3>
            <p><?=e($s['card_2_desc'] ?? 'Lihat nilai dan transkrip akademik')?></p>
        </article>
        <article class="card">
            <img src="assets/icons/<?=e($s['card_3_icon'] ?? 'jadwal')?>.svg" class="card-icon" alt="Icon">
            <h3><?=e($s['card_3_title'] ?? 'Jadwal Kuliah')?></h3>
            <p><?=e($s['card_3_desc'] ?? 'Akses jadwal perkuliahan mingguan')?></p>
        </article>
    </section>
    
    <footer>
        <div class="footer-content">
            <div>
                <h4>Contact</h4>
                <p><?=e($s['footer_email'] ?? 'info@example.ac.id')?></p>
                <p><?=e($s['footer_phone'] ?? '(000) 000-0000')?></p>
            </div>
            <div>
                <h4>Address</h4>
                <p><?=e($s['footer_address'] ?? 'Jl. Pendidikan No. 123, Kota, Indonesia')?></p>
            </div>
        </div>
        <div class="copyright">
            <?=e(str_replace('{school_name}', $s['school_name'] ?? 'SIAKAD', $s['footer_copyright'] ?? '© 2026 SIAKAD'))?>
        </div>
    </footer>
</body>
</html>
<?php
    return ob_get_clean();
}

migrate();
verify_csrf();

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['trace_id'])) {
    $_SESSION['trace_id'] = bin2hex(random_bytes(8));
}
log_info('Request started', ['page' => $_GET['page'] ?? 'landing', 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'trace_id' => get_trace_id()]);

$page = $_GET['page'] ?? null;

if ($page === 'ping') {
    header('Content-Type: text/plain');
    echo 'pong';
    exit;
}

if ($page === 'health') {
    header('Content-Type: application/json');
    try {
        db()->query("SELECT 1");
        $status = 'ok';
    } catch (Exception $e) {
        $status = 'error';
    }
    echo json_encode(['status' => $status, 'database' => $status, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')]);
    exit;
}
if ($page === 'sse') {
    handle_sse();
}
if ($page === 'notif_count') {
    handle_notif_count();
}

$user = current_user();

$handlers = [
    'dashboard' => ['fn'=>'handle_dashboard', 'title'=>'Dashboard'],
    'dosen' => ['fn'=>'handle_dosen', 'title'=>'Dosen'],
    'kelas' => ['fn'=>'handle_kelas', 'title'=>'Kelas'],
    'khs' => ['fn'=>'handle_khs', 'title'=>'KHS'],
    'krs' => ['fn'=>'handle_krs', 'title'=>'KRS'],
    'landing_images' => ['fn'=>'handle_landing_images', 'title'=>'Landing Images'],
    'login' => ['fn'=>'handle_login', 'title'=>'Login'],
    'logout' => ['fn'=>'handle_logout', 'title'=>'Logout'],
    'mahasiswa' => ['fn'=>'handle_mahasiswa', 'title'=>'Mahasiswa'],
    'mata_kuliah' => ['fn'=>'handle_mata_kuliah', 'title'=>'Mata Kuliah'],
    'nilai' => ['fn'=>'handle_nilai', 'title'=>'Nilai'],
    'presensi' => ['fn'=>'handle_presensi', 'title'=>'Presensi'],
    'prodi' => ['fn'=>'handle_prodi', 'title'=>'Program Studi'],
    'tahun_akademik' => ['fn'=>'handle_tahun_akademik', 'title'=>'Tahun Akademik'],
    'broadcast' => ['fn'=>'handle_broadcast', 'title'=>'Broadcast'],
];

if (!$user && ($page === null || $page === '' || $page === 'login')) {
    if ($page === 'login') {
        $h = $handlers['login'];
        layout($h['title'], $h['fn']());
        log_info('Request completed', ['page' => 'login', 'status' => 'success', 'trace_id' => get_trace_id()]);
        exit;
    }
    
    header('Content-Type: text/html; charset=utf-8');
    echo render_landing_page();
    log_info('Request completed', ['page' => 'landing', 'status' => 'success', 'trace_id' => get_trace_id()]);
    exit;
}

if (!$user && $page !== 'login') {
    redirect('?page=login');
}

if ($user && ($page === null || $page === '')) {
    redirect('?page=dashboard');
}

if (!isset($handlers[$page])) {
    $page = 'dashboard';
}

$h = $handlers[$page];
$content = $h['fn']();
if ($page !== 'logout') {
    layout($h['title'], $content);
}
log_info('Request completed', ['page' => $page, 'status' => 'success', 'trace_id' => get_trace_id()]);
