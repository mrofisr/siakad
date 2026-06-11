<?php
// ============================================================
// SIAKAD v1 - Sistem Informasi Akademik
// Single-file PHP + SQLite
// Built-in functions only. No external libs.
// ============================================================

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
    ");

    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $st = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $st->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
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

function flash_get(): array {
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function flash_set(string $key, string $msg): void { $_SESSION['flash'][$key] = $msg; }

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
            $a = ($_GET['page'] ?? '') === $p ? ' aria-current="page"' : '';
            $nav_l .= "<li><a href=\"?page=$p\"$a>$l</a></li>\n";
        }
        $nav_l .= "<li><a href=\"?page=logout\">Logout (" . e($u['name']) . ")</a></li>";
        $nav = "<nav><ul><li><strong>SIAKAD</strong></li></ul><ul>$nav_l</ul></nav>";
    }
    $flash = '';
    foreach (flash_get() as $type => $msg) {
        $flash .= "<p>" . e($msg) . "</p>\n";
    }
    echo "<!DOCTYPE html><html data-theme=\"light\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>" . e($title) . " - SIAKAD</title><link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css\"></head><body><header class=\"container\">$nav</header><main class=\"container\">$flash$content</main></body></html>";
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

migrate();
verify_csrf();

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['trace_id'])) {
    $_SESSION['trace_id'] = bin2hex(random_bytes(8));
}
log_info('Request started', ['page' => $_GET['page'] ?? 'dashboard', 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'trace_id' => get_trace_id()]);

$page = $_GET['page'] ?? 'dashboard';
$user = current_user();
if (!$user && $page !== 'login') redirect('?page=login');

$handlers = [
    'login' => ['fn'=>'handle_login', 'title'=>'Login'],
    'logout' => ['fn'=>'handle_logout', 'title'=>'Logout'],
    'dashboard' => ['fn'=>'handle_dashboard', 'title'=>'Dashboard'],
    'prodi' => ['fn'=>'handle_prodi', 'title'=>'Program Studi'],
    'mahasiswa' => ['fn'=>'handle_mahasiswa', 'title'=>'Mahasiswa'],
    'dosen' => ['fn'=>'handle_dosen', 'title'=>'Dosen'],
    'mata_kuliah' => ['fn'=>'handle_mata_kuliah', 'title'=>'Mata Kuliah'],
    'tahun_akademik' => ['fn'=>'handle_tahun_akademik', 'title'=>'Tahun Akademik'],
    'kelas' => ['fn'=>'handle_kelas', 'title'=>'Kelas'],
    'krs' => ['fn'=>'handle_krs', 'title'=>'KRS'],
    'nilai' => ['fn'=>'handle_nilai', 'title'=>'Nilai'],
    'presensi' => ['fn'=>'handle_presensi', 'title'=>'Presensi'],
    'khs' => ['fn'=>'handle_khs', 'title'=>'KHS'],
];

if (!isset($handlers[$page])) $page = 'dashboard';
$h = $handlers[$page];
$content = $h['fn']();
if ($page !== 'logout') layout($h['title'], $content);
log_info('Request completed', ['page' => $page, 'status' => 'success', 'trace_id' => get_trace_id()]);
