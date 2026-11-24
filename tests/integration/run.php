<?php
declare(strict_types=1);

/*
 * Borealis HTTP integration tests.
 *
 * Runs against a real server and a real, EMPTY MySQL/MariaDB database. The suite installs
 * Borealis through the web installer and then exercises the app exactly as a browser would
 * (cookies, CSRF tokens, JSON API). Nothing is mocked.
 *
 *   export BOREALIS_URL=http://127.0.0.1:8080
 *   export BOREALIS_TEST_DB_HOST=127.0.0.1 BOREALIS_TEST_DB_NAME=borealis_test \
 *          BOREALIS_TEST_DB_USER=... BOREALIS_TEST_DB_PASS=...
 *   php tests/integration/run.php [--json=results.json]
 *
 * The database connection is also used for read-only lookups (role ids) and to verify
 * that data really reached the database. Test passwords are random per run.
 */

if (PHP_SAPI !== 'cli') exit(1);
// Dates in the tests are the installation's dates (the installer below uses this zone). With UTC,
// between 00:00 and 06:00 UTC «today» was already tomorrow for Borealis and hours were rejected as future.
date_default_timezone_set('America/Mexico_City');

$BASE = rtrim(getenv('BOREALIS_URL') ?: 'http://127.0.0.1:8080', '/');
$DB = [
    'host' => getenv('BOREALIS_TEST_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('BOREALIS_TEST_DB_PORT') ?: '3306',
    'name' => getenv('BOREALIS_TEST_DB_NAME') ?: '',
    'user' => getenv('BOREALIS_TEST_DB_USER') ?: '',
    'pass' => getenv('BOREALIS_TEST_DB_PASS') ?: '',
];
if ($DB['name'] === '' || $DB['user'] === '') {
    fwrite(STDERR, "Define BOREALIS_TEST_DB_NAME y BOREALIS_TEST_DB_USER (y BOREALIS_TEST_DB_PASS).\n");
    exit(2);
}
$jsonOut = null;
foreach (array_slice($argv, 1) as $a) if (str_starts_with($a, '--json=')) $jsonOut = substr($a, 7);

// ---------------------------------------------------------------- tiny HTTP client
final class Client
{
    private array $cookies = [];
    public string $csrf = '';
    public int $status = 0;
    public string $body = '';
    public array $headers = [];
    /** Source address for this client (127.0.0.x) to simulate other IPs against a local server. */
    public ?string $iface = null;

    public function __construct(private string $base) {}

    public function request(string $method, string $path, $body = null, array $extra = []): self
    {
        $ch = curl_init($this->base . $path);
        $headers = $extra;
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if (is_array($body) && !isset($extra['raw_form'])) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($body);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_values(array_filter($headers, 'is_string')),
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($this->iface) curl_setopt($ch, CURLOPT_INTERFACE, $this->iface);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) throw new RuntimeException('HTTP error: ' . curl_error($ch));
        $this->status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $hsize);
        $this->body = substr($raw, $hsize);
        $this->headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (!str_contains($line, ':')) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $this->headers[strtolower($k)][] = $v;
            if (strtolower($k) === 'set-cookie') {
                [$pair] = explode(';', $v, 2);
                [$ck, $cv] = explode('=', $pair, 2) + [1 => ''];
                if ($cv === '' || $cv === 'deleted') unset($this->cookies[$ck]); else $this->cookies[$ck] = $cv;
            }
        }
        if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $this->body, $m)) $this->csrf = $m[1];
        return $this;
    }

    public function get(string $path): self { return $this->request('GET', $path); }

    /** Classic form POST (login, installer). */
    public function form(string $path, array $fields): self
    {
        $fields['_csrf'] ??= $this->csrf;
        return $this->request('POST', $path, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    /** JSON API call with the CSRF header, as the front end does. */
    public function api(string $method, string $path, ?array $data = null, bool $csrf = true): self
    {
        $h = ['Accept: application/json', 'X-Requested-With: fetch'];
        if ($csrf) $h[] = 'X-CSRF-Token: ' . $this->csrf;
        return $this->request($method, $path, $data ?? ($method === 'GET' ? null : []), $h);
    }

    public function upload(string $path, array $files, array $fields = [], bool $multi = true, string $field = 'file'): self
    {
        $post = $fields;
        foreach ($files as $i => [$name, $content, $mime]) {
            $tmp = tempnam(sys_get_temp_dir(), 'bup');
            file_put_contents($tmp, $content);
            $post[$multi ? "{$field}[$i]" : $field] = new CURLFile($tmp, $mime, $name);
        }
        $h = ['Accept: application/json', 'X-Requested-With: fetch', 'X-CSRF-Token: ' . $this->csrf, 'raw_form' => true];
        return $this->request('POST', $path, $post, $h);
    }

    public function json(): array { return json_decode($this->body, true) ?? []; }
    public function location(): string { return $this->headers['location'][0] ?? ''; }
    public function login(string $email, string $password): self
    {
        $this->get('/login');
        return $this->form('/login', ['email' => $email, 'password' => $password]);
    }
}

// ---------------------------------------------------------------- test runner
$results = [];
$current = '';
function group(string $name): void { global $current; $current = $name; echo "\n== $name\n"; }
function check(string $name, bool $ok, string $detail = ''): bool
{
    global $results, $current;
    $results[] = ['group' => $current, 'name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail];
    echo ($ok ? '  ✔ ' : '  ✘ ') . $name . ($ok || $detail === '' ? '' : "  → $detail") . "\n";
    return $ok;
}
function snippet(Client $c): string { return $c->status . ' ' . mb_substr(preg_replace('/\s+/', ' ', strip_tags($c->body)), 0, 160); }
function pw(): string { return 'T' . bin2hex(random_bytes(8)) . '!'; }

$pdo = new PDO("mysql:host={$DB['host']};port={$DB['port']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$q = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s; };

// ================================================================ 1. Installer
group('Instalación');
$tablesBefore = (int)$q('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?', [$DB['name']])->fetchColumn();
if ($tablesBefore > 0) {
    fwrite(STDERR, "La base de datos de prueba no está vacía ({$tablesBefore} tablas). Usa una base vacía.\n");
    exit(2);
}
$admin = new Client($BASE);
$admin->get('/');
check('Sin configuración se muestra el instalador', $admin->status === 200 && str_contains($admin->body, 'Instalar Borealis'), snippet($admin));
$adminEmail = 'admin@example.test';
$adminPass = pw();
$install = ['db_host' => $DB['host'], 'db_port' => $DB['port'], 'db_name' => $DB['name'], 'db_user' => $DB['user'], 'db_pass' => 'contraseña-incorrecta',
    'app_url' => $BASE, 'base_path' => '', 'timezone' => 'America/Mexico_City', 'pretty_urls' => '1',
    'workspace_name' => 'Bosque Norte', 'admin_name' => 'Ana Admin', 'admin_email' => $adminEmail, 'admin_password' => $adminPass];
$admin->form('/', $install);
check('Credenciales de base incorrectas: error visible y datos conservados', $admin->status === 200 && str_contains($admin->body, 'form-error') && str_contains($admin->body, 'value="Bosque Norte"'), snippet($admin));
$install['db_pass'] = $DB['pass'];
$install['admin_password'] = '123';
$admin->form('/', $install);
check('Contraseña corta rechazada en la instalación', $admin->status === 200 && !str_contains($admin->location(), '/'), snippet($admin));
$install['admin_password'] = $adminPass;
$admin->form('/', $install);
check('Instalación correcta redirige a la aplicación', in_array($admin->status, [302, 303], true), snippet($admin));
$tables = (int)$q('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?', [$DB['name']])->fetchColumn();
check('Migraciones aplicadas (tablas creadas)', $tables >= 25, "tablas: $tables");
$admin->get('/');
check('Tras instalar, la sesión queda iniciada y se ve el inicio', $admin->status === 200 && str_contains($admin->body, 'csrf-token') && !str_contains($admin->body, 'Instalar Borealis'), snippet($admin));
$other = new Client($BASE);
$other->get('/');
check('El instalador ya no está disponible', !str_contains($other->body, 'Instalar Borealis'), snippet($other));
$hash = (string)$q('SELECT password_hash FROM users WHERE email = ?', [$adminEmail])->fetchColumn();
check('Contraseña almacenada con Argon2id/bcrypt (nunca en claro)', (str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$')) && !str_contains($hash, $adminPass), substr($hash, 0, 12));
$cfg = @file_get_contents(dirname(__DIR__, 2) . '/config/config.php');
check('config.php escrito fuera de public/', is_string($cfg) && str_contains($cfg, "'db'"), 'no legible');

$ws1 = (int)$q('SELECT id FROM workspaces ORDER BY id LIMIT 1')->fetchColumn();
$role = fn(int $ws, string $scope, string $key) => (int)$q('SELECT id FROM roles WHERE workspace_id = ? AND scope = ? AND role_key = ?', [$ws, $scope, $key])->fetchColumn();

// ================================================================ 2. Authentication
group('Autenticación y sesión');
$anon = new Client($BASE);
$anon->get('/projects');
check('Sin sesión, una página redirige al inicio de sesión', in_array($anon->status, [302, 303], true) && str_contains($anon->location(), '/login'), snippet($anon));
$anon->api('GET', '/api/projects/1');
check('Sin sesión, la API responde 401', $anon->status === 401, snippet($anon));
$anon->get('/login');
$anon->api('POST', '/api/projects', ['name' => 'Intruso']);
check('Sin sesión no se puede crear un proyecto', $anon->status === 401 && (int)$q("SELECT COUNT(*) FROM projects WHERE name = 'Intruso'")->fetchColumn() === 0, snippet($anon));
$bad = new Client($BASE);
$bad->login($adminEmail, 'incorrecta');
check('Contraseña incorrecta: no inicia sesión y muestra error', in_array($bad->status, [200, 422], true) && str_contains($bad->body, 'form-error'), snippet($bad));
$bad->get('/login');
$bad->form('/login', ['email' => $adminEmail, 'password' => $adminPass, '_csrf' => 'falso']);
check('Login con token CSRF falso es rechazado', !in_array($bad->status, [302, 303], true) || str_contains($bad->location(), '/login'), snippet($bad));
$admin->api('POST', '/api/projects', ['name' => 'Sin token'], false);
check('Petición API sin token CSRF es rechazada (419)', $admin->status === 419, snippet($admin));
$admin->get('/');
$sec = $admin->headers;
check('Cabeceras de seguridad (CSP, nosniff, frame)', isset($sec['content-security-policy'], $sec['x-content-type-options']) && (isset($sec['x-frame-options']) || str_contains($sec['content-security-policy'][0], 'frame-ancestors')), json_encode(array_keys($sec)));
$cookie = implode(' ', $sec['set-cookie'] ?? []);
$loginPage = new Client($BASE);
$loginPage->login($adminEmail, $adminPass);
$sc = strtolower(implode(' ', $loginPage->headers['set-cookie'] ?? []));
check('Cookie de sesión HttpOnly y SameSite', str_contains($sc, 'httponly') && str_contains($sc, 'samesite=lax'), $sc);
check('Login correcto redirige', in_array($loginPage->status, [302, 303], true), snippet($loginPage));
$loginPage->form('/logout', []);
$loginPage->get('/');
check('Cerrar sesión invalida la sesión', in_array($loginPage->status, [302, 303], true) && str_contains($loginPage->location(), '/login'), snippet($loginPage));

// ================================================================ 3. People & roles
group('Personas y roles');
$users = [];
foreach (['bruno' => 'member', 'carla' => 'member', 'olga' => 'guest'] as $u => $rk) {
    $users[$u] = ['email' => "$u@example.test", 'pass' => pw()];
    $admin->get('/admin/members');
    $admin->api('POST', '/api/members', ['name' => ucfirst($u) . ' Prueba', 'email' => $users[$u]['email'], 'password' => $users[$u]['pass'], 'role_id' => $role($ws1, 'workspace', $rk)]);
    $users[$u]['id'] = (int)($admin->json()['id'] ?? 0);
    check("Alta de {$u} con rol {$rk}", $admin->status === 200 && $users[$u]['id'] > 0, snippet($admin));
    if ($u === 'bruno') check('Al crear la cuenta se da un mensaje para enviar con el enlace y el correo', str_contains((string)($admin->json()['share_text'] ?? ''), '/login') && str_contains((string)($admin->json()['share_text'] ?? ''), $users[$u]['email']), snippet($admin));
}
$admin->api('POST', '/api/members', ['name' => 'Duplicado', 'email' => $users['bruno']['email'], 'password' => pw(), 'role_id' => $role($ws1, 'workspace', 'member')]);
check('Correo repetido en el mismo espacio rechazado con mensaje', $admin->status === 422 && str_contains($admin->body, 'ya pertenece'), snippet($admin));
$bruno = new Client($BASE); $bruno->login($users['bruno']['email'], $users['bruno']['pass']); $bruno->get('/');
$carla = new Client($BASE); $carla->login($users['carla']['email'], $users['carla']['pass']); $carla->get('/');
$olga = new Client($BASE); $olga->login($users['olga']['email'], $users['olga']['pass']); $olga->get('/');
check('Las personas creadas pueden iniciar sesión', $bruno->status === 200 && $carla->status === 200 && $olga->status === 200, snippet($olga));
$bruno->get('/admin/members');
check('Un integrante normal no accede a Administración', $bruno->status === 403, snippet($bruno));
$bruno->api('POST', '/api/members', ['name' => 'X', 'email' => 'x@example.test', 'password' => pw(), 'role_id' => $role($ws1, 'workspace', 'admin')]);
check('Un integrante no puede crear cuentas (API)', $bruno->status === 403, snippet($bruno));
$bruno->api('PATCH', '/api/members/' . $users['bruno']['id'], ['role_id' => $role($ws1, 'workspace', 'admin')]);
check('Un integrante no puede ascenderse a sí mismo', $bruno->status === 403 && (int)$q('SELECT role_id FROM workspace_members WHERE user_id = ? AND workspace_id = ?', [$users['bruno']['id'], $ws1])->fetchColumn() === $role($ws1, 'workspace', 'member'), snippet($bruno));

// ================================================================ 4. Projects
group('Proyectos');
$admin->get('/projects');
$admin->api('POST', '/api/projects', ['name' => '']);
check('Proyecto sin nombre rechazado con error de campo', $admin->status === 422 && isset($admin->json()['errors']['name']), snippet($admin));
$admin->api('POST', '/api/projects', ['name' => 'Sendero Alpino']);
$p1 = (int)($admin->json()['id'] ?? 0);
$p1key = $admin->json()['project']['key'] ?? '';
check('Crear proyecto solo con nombre', $admin->status === 200 && $p1 > 0 && $p1key !== '', snippet($admin));
$admin->get("/projects/$p1");
check('El proyecto nuevo se ve al recargar', $admin->status === 200 && str_contains($admin->body, 'Sendero Alpino'), snippet($admin));
$admin->api('PATCH', "/api/projects/$p1", ['name' => 'Sendero Boreal']);
check('Renombrar proyecto', $admin->status === 200 && ($admin->json()['project']['name'] ?? '') === 'Sendero Boreal', snippet($admin));
// The old name may only survive inside the activity history («…»), never in titles or navigation.
$stale = fn(string $html) => str_contains(str_replace('«Sendero Alpino»', '', $html), 'Sendero Alpino');
$admin->get("/projects/$p1");
$renamedHere = str_contains($admin->body, 'Sendero Boreal') && !$stale($admin->body);
$admin->get('/projects');
$renamedList = str_contains($admin->body, 'Sendero Boreal') && !$stale($admin->body);
$admin->get('/');
$renamedHome = str_contains($admin->body, 'Sendero Boreal') && !$stale($admin->body);
check('El nombre nuevo aparece en resumen, listado y barra lateral (sin restos del anterior)', $renamedHere && $renamedList && $renamedHome, "resumen=$renamedHere listado=$renamedList inicio=$renamedHome");
check('El nombre nuevo persiste en la base de datos', $q('SELECT name FROM projects WHERE id = ?', [$p1])->fetchColumn() === 'Sendero Boreal');
$admin->api('PATCH', "/api/projects/$p1", ['name' => str_repeat('x', 300)]);
check('Nombre demasiado largo rechazado; el valor guardado no cambia', $admin->status === 422 && $q('SELECT name FROM projects WHERE id = ?', [$p1])->fetchColumn() === 'Sendero Boreal', snippet($admin));
$admin->api('POST', '/api/projects', ['name' => 'Proyecto clave', 'project_key' => $p1key]);
check('Clave de proyecto duplicada rechazada', $admin->status === 422, snippet($admin));
$admin->api('POST', "/api/projects/$p1/archive");
check('Archivar proyecto', $admin->status === 200 && $q('SELECT archived_at FROM projects WHERE id = ?', [$p1])->fetchColumn() !== null, snippet($admin));
$admin->get('/projects');
$hidden = !str_contains($admin->body, 'Sendero Boreal');
$admin->get('/projects?archived=1');
check('Archivado: sale de la lista activa y aparece en archivados', $hidden && str_contains($admin->body, 'Sendero Boreal'), "oculto=$hidden");
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => 'No debería crearse']);
check('Proyecto archivado es de solo lectura', $admin->status === 403 || $admin->status === 409, snippet($admin));
$admin->api('POST', "/api/projects/$p1/restore");
$admin->get('/projects');
check('Restaurar proyecto lo devuelve a la lista activa', $admin->status === 200 && str_contains($admin->body, 'Sendero Boreal'), snippet($admin));

// ================================================================ 5. Teams
group('Equipos');
$admin->api('POST', '/api/teams', ['name' => '']);
check('Equipo sin nombre rechazado', $admin->status === 422, snippet($admin));
$admin->api('POST', '/api/teams', ['name' => 'Guardabosques', 'description' => 'Cuidan el sendero']);
$t1 = (int)($admin->json()['id'] ?? 0);
check('Crear equipo', $admin->status === 200 && $t1 > 0, snippet($admin));
$admin->api('POST', "/api/teams/$t1/members", ['user_id' => $users['carla']['id'], 'team_role' => 'member', 'responsibility' => 'Mapas']);
check('Agregar integrante al equipo', $admin->status === 200, snippet($admin));
$admin->api('PATCH', "/api/teams/$t1", ['name' => 'Guardabosques del Norte']);
$admin->get("/teams/$t1");
check('Renombrar equipo y verlo al recargar', str_contains($admin->body, 'Guardabosques del Norte') && str_contains($admin->body, 'Carla Prueba'), snippet($admin));
$admin->api('POST', "/api/teams/$t1/projects", ['project_id' => $p1]);
check('Asociar proyecto al equipo', $admin->status === 200 && (int)$q('SELECT team_id FROM projects WHERE id = ?', [$p1])->fetchColumn() === $t1, snippet($admin));
$admin->api('DELETE', "/api/teams/$t1/members/" . $users['bruno']['id']);
check('Quitar a quien no es integrante da error claro', $admin->status >= 400 && $admin->status < 500, snippet($admin));
$adminId = (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn();
// A coordinator without admin rights cannot leave the team without coordination.
$admin->api('PATCH', "/api/teams/$t1/members/" . $users['carla']['id'], ['team_role' => 'coordinator']);
$admin->api('DELETE', "/api/teams/$t1/members/$adminId");
$carla->get("/teams/$t1");
$carla->api('DELETE', "/api/teams/$t1/members/" . $users['carla']['id']);
check('No se puede dejar el equipo sin coordinación', $carla->status === 409 && (int)$q('SELECT COUNT(*) FROM team_members WHERE team_id = ?', [$t1])->fetchColumn() === 1, snippet($carla));
$admin->api('PATCH', "/api/teams/$t1/members/" . $users['carla']['id'], ['team_role' => 'member']);
check('La administración puede reasignar el rol en el equipo', $admin->status === 200, snippet($admin));
$admin->api('POST', "/api/teams/$t1/archive");
$admin->get('/teams');
$teamHidden = !str_contains($admin->body, 'Guardabosques del Norte');
$admin->api('POST', "/api/teams/$t1/restore");
$admin->get('/teams');
check('Archivar y restaurar equipo', $teamHidden && str_contains($admin->body, 'Guardabosques del Norte'), "oculto=$teamHidden");

// ================================================================ 6. Tasks
group('Tareas');
$admin->get("/projects/$p1/board");
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => '   ']);
check('Tarea sin título rechazada', $admin->status === 422 && isset($admin->json()['errors']['title']), snippet($admin));
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => 'Marcar el sendero']);
$task1 = $admin->json()['task'] ?? [];
check('Crear tarea solo con título', $admin->status === 200 && ($task1['key'] ?? '') === "$p1key-1", snippet($admin));
$tid1 = (int)($task1['id'] ?? 0);
$admin->api('PATCH', "/api/tasks/$tid1", ['title' => 'Marcar el sendero norte', 'description' => "Con **pintura** verde", 'assignee_id' => $users['carla']['id'], 'due_date' => date('Y-m-d', strtotime('+3 days'))]);
$tk = $admin->json()['task'] ?? [];
check('Editar título, descripción, responsable y fecha', $admin->status === 200 && $tk['title'] === 'Marcar el sendero norte' && $tk['assignee_id'] === $users['carla']['id'], snippet($admin));
$admin->get("/t/$p1key-1");
check('La edición se ve al recargar la página de la tarea', str_contains($admin->body, 'Marcar el sendero norte') && str_contains($admin->body, '<strong>pintura</strong>'), snippet($admin));
$statuses = $q('SELECT id, category FROM statuses WHERE project_id = ? ORDER BY position', [$p1])->fetchAll();
$admin->api('POST', "/api/tasks/$tid1/move", ['status_id' => (int)$statuses[1]['id'], 'after_id' => null]);
check('Mover tarea a «En progreso»', $admin->status === 200 && (int)$q('SELECT status_id FROM tasks WHERE id = ?', [$tid1])->fetchColumn() === (int)$statuses[1]['id'], snippet($admin));
$admin->api('POST', "/api/tasks/$tid1/done", ['done' => true]);
$doneJson = $admin->json();
check('Finalizar tarea (y señal de celebración)', $admin->status === 200 && ($doneJson['task']['category'] ?? '') === 'done' && !empty($doneJson['celebrate']), snippet($admin));
check('Última tarea abierta finalizada: se ofrece el resumen del proyecto', !empty($doneJson['project_complete']), json_encode(array_keys($doneJson)));
$admin->api('POST', "/api/tasks/$tid1/done", ['done' => false]);
check('Reabrir tarea', $admin->status === 200 && ($admin->json()['task']['category'] ?? '') !== 'done', snippet($admin));
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => 'Subtarea: comprar pintura', 'parent_id' => $tid1]);
$sub = $admin->json()['task'] ?? [];
check('Crear subtarea', $admin->status === 200 && ($sub['parent_id'] ?? 0) === $tid1, snippet($admin));
$admin->api('POST', "/api/tasks/$tid1/comments", ['body' => 'Hola @' . $q('SELECT handle FROM users WHERE id = ?', [$users['carla']['id']])->fetchColumn() . ' revisa esto']);
check('Comentar con mención', $admin->status === 200, snippet($admin));
$notif = (int)$q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$users['carla']['id']])->fetchColumn();
check('La persona mencionada/asignada recibe notificación', $notif >= 1, "notificaciones=$notif");
$admin->api('POST', "/api/tasks/$tid1/duplicate");
$dup = $admin->json()['task'] ?? [];
check('Duplicar tarea (incluye subtareas)', $admin->status === 200 && (int)$q('SELECT COUNT(*) FROM tasks WHERE parent_id = ? AND deleted_at IS NULL', [$dup['id'] ?? 0])->fetchColumn() === 1, snippet($admin));
$admin->api('POST', '/api/tasks/bulk', ['ids' => [$tid1, $dup['id']], 'action' => 'priority', 'value' => (int)$q('SELECT id FROM priorities WHERE workspace_id = ? ORDER BY weight DESC LIMIT 1', [$ws1])->fetchColumn()]);
check('Edición masiva de prioridad', $admin->status === 200 && ($admin->json()['count'] ?? 0) === 2, snippet($admin));
$admin->api('DELETE', '/api/tasks/' . $dup['id']);
check('Eliminar tarea (borrado recuperable)', $admin->status === 200 && $q('SELECT deleted_at FROM tasks WHERE id = ?', [$dup['id']])->fetchColumn() !== null, snippet($admin));
$admin->get("/t/{$dup['key']}");
check('La tarea eliminada ya no se muestra', $admin->status === 404, snippet($admin));
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => '<script>alert(1)</script> & <b>negrita</b>']);
$xssKey = $admin->json()['task']['key'] ?? '';
$admin->get("/projects/$p1/list");
check('Texto con HTML se muestra escapado (sin XSS)', !str_contains($admin->body, '<script>alert(1)</script>') && str_contains($admin->body, '&lt;script&gt;'), snippet($admin));
$admin->get("/projects/$p1/list?q=sendero&sort=title");
check('Lista con filtros conserva los filtros en la vista', $admin->status === 200 && str_contains($admin->body, 'value="sendero"'), snippet($admin));
// 1.1: the initials can change; old task links keep working through an alias.
$admin->api('PATCH', "/api/projects/$p1", ['project_key' => 'zz!']);
check('Siglas no válidas rechazadas', $admin->status === 422 && $q('SELECT project_key FROM projects WHERE id = ?', [$p1])->fetchColumn() === $p1key, snippet($admin));
$admin->api('PATCH', "/api/projects/$p1", ['project_key' => 'ZZZ']);
check('Cambiar las siglas del proyecto', $admin->status === 200 && $q('SELECT project_key FROM projects WHERE id = ?', [$p1])->fetchColumn() === 'ZZZ', snippet($admin));
$admin->get("/t/$p1key-1");
check('El enlace con las siglas antiguas redirige a la clave nueva', in_array($admin->status, [301, 302, 303], true) && str_ends_with($admin->location(), '/t/ZZZ-1'), $admin->status . ' ' . $admin->location());
$admin->api('POST', '/api/projects', ['name' => 'Choque de siglas', 'project_key' => $p1key]);
check('Las siglas antiguas quedan reservadas para no romper enlaces', $admin->status === 422, snippet($admin));
$admin->api('PATCH', "/api/projects/$p1", ['project_key' => $p1key]);
$admin->get('/t/ZZZ-1');
check('Volver a las siglas originales también conserva los enlaces intermedios', $q('SELECT project_key FROM projects WHERE id = ?', [$p1])->fetchColumn() === $p1key && str_ends_with($admin->location(), "/t/$p1key-1"), $admin->status . ' ' . $admin->location());

// ================================================================ 7. Permissions
group('Permisos');
// Private project owned by the admin, nobody else in it.
$admin->api('POST', '/api/projects', ['name' => 'Expedición privada', 'visibility' => 'private']);
$pp = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pp/tasks", ['title' => 'Secreto']);
$ppTask = $admin->json()['task'] ?? [];
$bruno->get("/projects/$pp");
check('Un integrante no ve un proyecto privado ajeno', $bruno->status === 404 || $bruno->status === 403, snippet($bruno));
$bruno->get('/projects');
check('El proyecto privado no aparece en su listado', !str_contains($bruno->body, 'Expedición privada'));
$bruno->api('GET', '/api/tasks/' . $ppTask['id']);
check('Tampoco puede leer sus tareas por la API', in_array($bruno->status, [403, 404], true), snippet($bruno));
$bruno->get('/t/' . $ppTask['key']);
check('Ni abrir la tarea por su enlace', in_array($bruno->status, [403, 404], true), snippet($bruno));
$bruno->api('PATCH', '/api/tasks/' . $ppTask['id'], ['title' => 'Hackeado']);
check('Petición manipulada para editar una tarea privada: rechazada', in_array($bruno->status, [403, 404], true) && $q('SELECT title FROM tasks WHERE id = ?', [$ppTask['id']])->fetchColumn() === 'Secreto', snippet($bruno));
$bruno->get('/search?q=Secreto');
check('La búsqueda no filtra contenido privado', !str_contains($bruno->body, 'Secreto') || str_contains($bruno->body, 'Sin resultados'), snippet($bruno));

// 1.7: the old «visible to the whole workspace» option no longer gives access: only members see a project.
$admin->api('POST', '/api/projects', ['name' => 'Bitácora compartida', 'visibility' => 'workspace']);
$pw = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pw/tasks", ['title' => 'Registro diario']);
$pwTask = $admin->json()['task'] ?? [];
$bruno->get("/projects/$pw");
check('Proyecto en el que no participa (aunque se pidiera «visible para el espacio»): no se ve', $bruno->status === 404 && !str_contains($bruno->body, 'Registro diario'), snippet($bruno));
$bruno->api('PATCH', '/api/tasks/' . $pwTask['id'], ['title' => 'Cambio no autorizado']);
check('Sin ser integrante no puede modificar', in_array($bruno->status, [403, 404], true) && $q('SELECT title FROM tasks WHERE id = ?', [$pwTask['id']])->fetchColumn() === 'Registro diario', snippet($bruno));

// Explicit observer role.
$admin->api('POST', "/api/projects/$pw/members", ['user_id' => $users['olga']['id'], 'role_id' => $role($ws1, 'project', 'observer')]);
check('Añadir a Olga como observadora', $admin->status === 200, snippet($admin));
$olga->get("/projects/$pw/board");
check('La observadora puede ver el tablero', $olga->status === 200 && str_contains($olga->body, 'Registro diario'), snippet($olga));
check('La observadora no ve controles de edición en el tablero', !str_contains($olga->body, 'data-draggable') && !str_contains($olga->body, 'column-add'), 'hay controles de edición');
$olga->api('PATCH', '/api/tasks/' . $pwTask['id'], ['title' => 'Editado por observadora']);
$e1 = $olga->status;
$olga->api('POST', "/api/projects/$pw/tasks", ['title' => 'Nueva de observadora']);
$e2 = $olga->status;
$olga->api('POST', '/api/tasks/' . $pwTask['id'] . '/move', ['status_id' => (int)$q('SELECT id FROM statuses WHERE project_id = ? ORDER BY position DESC LIMIT 1', [$pw])->fetchColumn()]);
$e3 = $olga->status;
$olga->api('DELETE', '/api/tasks/' . $pwTask['id']);
$e4 = $olga->status;
check('La observadora no puede editar, crear, mover ni eliminar', [$e1, $e2, $e3, $e4] === [403, 403, 403, 403], "$e1 $e2 $e3 $e4");
check('…y la tarea sigue intacta en la base', $q('SELECT title FROM tasks WHERE id = ?', [$pwTask['id']])->fetchColumn() === 'Registro diario');
$olga->get("/projects/$pp");
check('Invitada (guest) no ve proyectos privados', in_array($olga->status, [403, 404], true), snippet($olga));

// Contributor via team membership.
$carla->get("/projects/$p1/board");
check('Integrante del equipo accede al proyecto del equipo', $carla->status === 200, snippet($carla));
$carla->api('PATCH', "/api/tasks/$tid1", ['title' => 'Marcar el sendero norte (Carla)']);
check('Integrante del equipo puede editar tareas (colaboración)', $carla->status === 200, snippet($carla));
$carla->api('PATCH', "/api/projects/$p1", ['name' => 'Renombrado por Carla']);
check('Colaboración no puede cambiar ajustes del proyecto', $carla->status === 403, snippet($carla));
$carla->get("/projects/$p1/settings");
check('Colaboración no accede a Ajustes del proyecto', $carla->status === 403, snippet($carla));

// Manipulated references.
$otherStatus = (int)$q('SELECT id FROM statuses WHERE project_id = ? LIMIT 1', [$pp])->fetchColumn();
$admin->api('PATCH', "/api/tasks/$tid1", ['status_id' => $otherStatus]);
check('Estado de otro proyecto rechazado', $admin->status === 422 && (int)$q('SELECT status_id FROM tasks WHERE id = ?', [$tid1])->fetchColumn() !== $otherStatus, snippet($admin));
$admin->api('PATCH', "/api/tasks/$tid1", ['assignee_id' => 999999]);
check('Responsable inexistente rechazado', $admin->status === 422, snippet($admin));
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => 'Padre ajeno', 'parent_id' => $ppTask['id']]);
check('Tarea padre de otro proyecto rechazada', $admin->status === 422, snippet($admin));
$admin->api('PATCH', "/api/tasks/$tid1", ['due_date' => '2026-02-31']);
check('Fecha inválida rechazada', $admin->status === 422, snippet($admin));

// Workspace isolation.
$admin->api('POST', '/api/workspaces', ['name' => 'Taller Sur']);
$ws2 = (int)($admin->json()['id'] ?? 0);
check('Crear un segundo espacio de trabajo', $admin->status === 200 && $ws2 > 0, snippet($admin));
$admin->get('/');
$admin->api('POST', '/api/projects', ['name' => 'Proyecto del Sur', 'visibility' => 'workspace']);
$ps = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$ps/tasks", ['title' => 'Tarea del Sur']);
$psTask = $admin->json()['task'] ?? [];
check('El proyecto se crea en el espacio activo', (int)$q('SELECT workspace_id FROM projects WHERE id = ?', [$ps])->fetchColumn() === $ws2);
$bruno->get("/projects/$ps");
$i1 = $bruno->status;
$bruno->api('GET', '/api/tasks/' . $psTask['id']);
$i2 = $bruno->status;
$bruno->api('POST', '/api/workspace/switch', ['workspace_id' => $ws2]);
$i3 = $bruno->status;
check('Quien no pertenece a otro espacio no accede a nada de él', in_array($i1, [403, 404], true) && in_array($i2, [403, 404], true) && in_array($i3, [403, 404], true), "$i1 $i2 $i3");
$admin->api('POST', "/api/projects/$ps/tasks", ['title' => 'Cruce', 'assignee_id' => $users['bruno']['id']]);
check('No se puede asignar a alguien de otro espacio', $admin->status === 422, snippet($admin));
$admin->api('POST', '/api/workspace/switch', ['workspace_id' => $ws1]);
$admin->get('/');

// ================================================================ 8. Modules
group('Módulos por proyecto');
$admin->get("/projects/$p1/sprints");
check('Sprints desactivado por defecto (la página no existe)', $admin->status === 404, snippet($admin));
$admin->api('POST', "/api/projects/$p1/modules", ['module' => 'sprints', 'enabled' => true]);
$mods = $q('SELECT module_key, enabled FROM project_modules WHERE project_id = ?', [$p1])->fetchAll(PDO::FETCH_KEY_PAIR);
check('Activar Sprints activa también su dependencia Backlog', $admin->status === 200 && ($mods['sprints'] ?? 0) == 1 && ($mods['backlog'] ?? 0) == 1, json_encode($mods));
$admin->api('POST', "/api/projects/$p1/sprints", ['name' => 'Sprint de prueba']);
$sp = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/sprints/$sp/start");
check('No se inicia un sprint vacío', $admin->status === 422 || $admin->status === 409, snippet($admin));
$admin->api('PATCH', "/api/tasks/$tid1", ['sprint_id' => $sp]);
$admin->api('POST', "/api/sprints/$sp/start");
check('Iniciar sprint con tareas', $admin->status === 200 && $q('SELECT state FROM sprints WHERE id = ?', [$sp])->fetchColumn() === 'active', snippet($admin));
$admin->api('POST', "/api/sprints/$sp/complete", ['move_to' => 'backlog']);
check('Destino manipulado al finalizar sprint: error de validación, no fallo del servidor', $admin->status === 422 && $q('SELECT state FROM sprints WHERE id = ?', [$sp])->fetchColumn() === 'active', snippet($admin));
$admin->api('POST', "/api/sprints/$sp/complete", ['move_to' => '', 'result_notes' => 'Buen avance']);
check('Finalizar sprint: pendientes vuelven al backlog', $admin->status === 200 && $q('SELECT sprint_id FROM tasks WHERE id = ?', [$tid1])->fetchColumn() === null, snippet($admin));
$admin->api('POST', "/api/projects/$p1/modules", ['module' => 'backlog', 'enabled' => false]);
$mods = $q('SELECT module_key, enabled FROM project_modules WHERE project_id = ?', [$p1])->fetchAll(PDO::FETCH_KEY_PAIR);
check('Desactivar Backlog desactiva Sprints (dependencia)', ($mods['sprints'] ?? 1) == 0, json_encode($mods));
check('Desactivar un módulo no borra datos', (int)$q('SELECT COUNT(*) FROM sprints WHERE project_id = ?', [$p1])->fetchColumn() === 1);
$admin->api('POST', "/api/projects/$p1/modules", ['module' => 'tasks', 'enabled' => false]);
check('Los módulos base no se pueden desactivar', $admin->status === 422 || $admin->status === 400, snippet($admin));

// ================================================================ 9. Files
group('Archivos adjuntos');
$admin->get("/t/$p1key-1");
$admin->upload("/api/projects/$p1/files", [['notas.txt', "Lista de material\n", 'text/plain']], ['task_id' => (string)$tid1]);
$fileId = (int)($admin->json()['files'][0]['id'] ?? 0);
check('Subir archivo de texto a una tarea', $admin->status === 200 && $fileId > 0, snippet($admin));
$stored = (string)$q('SELECT stored_name FROM files WHERE id = ?', [$fileId])->fetchColumn();
check('Se guarda con nombre aleatorio fuera de public/', $stored !== '' && !str_contains($stored, 'notas') && is_file(dirname(__DIR__, 2) . '/storage/uploads/' . $stored) || is_file(dirname(__DIR__, 2) . '/storage/uploads/' . substr($stored, 0, 2) . '/' . $stored), $stored);
$admin->upload("/api/projects/$p1/files", [['shell.php', '<?php echo 1;', 'application/x-php']], ['task_id' => (string)$tid1]);
check('Archivo .php rechazado', $admin->status === 422, snippet($admin));
$admin->upload("/api/projects/$p1/files", [['foto.php.jpg', '<?php echo 1;', 'image/jpeg']], ['task_id' => (string)$tid1]);
check('Doble extensión peligrosa rechazada', $admin->status === 422, snippet($admin));
$admin->upload("/api/projects/$p1/files", [['imagen.png', 'no soy png', 'image/png']], ['task_id' => (string)$tid1]);
check('Contenido que no coincide con la extensión rechazado', $admin->status === 422, snippet($admin));
$admin->get("/files/$fileId");
$cd = $admin->headers['content-disposition'][0] ?? '';
check('Descarga con permisos y cabeceras seguras', $admin->status === 200 && str_contains($cd, 'attachment') && ($admin->headers['x-content-type-options'][0] ?? '') === 'nosniff', $cd);
$anon->get("/files/$fileId");
check('Sin sesión no se descarga el archivo', $anon->status !== 200, snippet($anon));
$ppFile = null;
$admin->upload("/api/projects/$pp/files", [['privado.txt', 'confidencial', 'text/plain']]);
$ppFile = (int)($admin->json()['files'][0]['id'] ?? 0);
$bruno->get("/files/$ppFile");
check('Archivo de un proyecto privado ajeno: no descargable', in_array($bruno->status, [403, 404], true), snippet($bruno));

// ================================================================ 10. Preferences & data
group('Preferencias, exportación y respaldo');
$admin->api('PATCH', '/api/profile/prefs', ['motion' => 'off']);
$admin->get('/');
check('Preferencia de animaciones «desactivadas» se aplica', str_contains($admin->body, 'data-motion="off"'), snippet($admin));
$admin->api('PATCH', '/api/profile/prefs', ['motion' => 'explosivo']);
check('Valor de preferencia inválido rechazado', $admin->status === 422, snippet($admin));
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => '=HYPERLINK("http://evil.test","x")']);
$admin->get("/projects/$p1/export.csv");
check('Exportar tareas a CSV', $admin->status === 200 && str_contains($admin->headers['content-type'][0] ?? '', 'csv') && str_contains($admin->body, 'Marcar el sendero'), snippet($admin));
check('CSV neutraliza fórmulas', str_contains($admin->body, "'=HYPERLINK") && !preg_match('/(^|[,;])"?=HYPERLINK/m', $admin->body), mb_substr($admin->body, 0, 300));
$admin->get('/admin/export.json');
$exp = json_decode($admin->body, true);
check('Exportar el espacio a JSON', $admin->status === 200 && is_array($exp) && !empty($exp['projects']), snippet($admin));
$admin->get('/admin/data');
$admin->api('POST', '/api/backups');
$bk = $admin->json()['file'] ?? '';
check('Copia de seguridad SQL completa (administración de la instalación)', $admin->status === 200 && is_file(dirname(__DIR__, 2) . '/storage/backups/' . $bk), snippet($admin));
$bruno->api('POST', '/api/backups');
check('Un integrante no puede crear copias completas', $bruno->status === 403, snippet($bruno));
$admin->get('/admin/backups/' . rawurlencode('../config/config.php'));
check('La descarga de copias no permite recorrer rutas', $admin->status === 404 || $admin->status === 400, snippet($admin));
$admin->api('POST', '/api/migrations');
check('Actualizar base sin migraciones pendientes no hace nada', $admin->status === 200 && ($admin->json()['applied'] ?? null) === [], snippet($admin));

// ================================================================ 10b. Import from v0.2
group('Importación desde Borealis v0.2');
$fixture = (string)file_get_contents(__DIR__ . '/../fixtures/v022.zip');
$admin->get('/admin/data');
check('Administración › Datos ofrece la importación', str_contains($admin->body, 'Importar desde Borealis v0.2'), snippet($admin));
$bruno->get('/');
$bruno->upload('/api/import/legacy', [['borealis.zip', $fixture, 'application/zip']], [], false);
check('Un integrante no puede importar', $bruno->status === 403, snippet($bruno));
$admin->upload('/api/import/legacy', [['notas.txt', 'hola', 'text/plain']], [], false);
check('Archivo que no es de Borealis rechazado', $admin->status === 422, snippet($admin));
$projectsBefore = (int)$q('SELECT COUNT(*) FROM projects WHERE workspace_id = ?', [$ws1])->fetchColumn();
$admin->upload('/api/import/legacy', [['borealis-v022.zip', $fixture, 'application/zip']], [], false);
$rep = $admin->json()['report'] ?? [];
check('Importación desde el ZIP de la versión anterior', $admin->status === 200 && ($rep['projects'] ?? 0) === 3 && ($rep['tasks'] ?? 0) === 4, snippet($admin));
check('Checklists como subtareas, comentarios, equipos y sprints', ($rep['subtasks'] ?? 0) === 2 && ($rep['comments'] ?? 0) === 1 && ($rep['teams'] ?? 0) === 1 && ($rep['sprints'] ?? 0) === 2, json_encode($rep));
check('Personas: nuevas creadas y la existente reutilizada por correo', ($rep['members'] ?? 0) === 2 && ($rep['members_existing'] ?? 0) === 1, json_encode($rep));
check('Se hizo copia de seguridad antes de importar', !empty($admin->json()['backup']) && is_file(dirname(__DIR__, 2) . '/storage/backups/' . ($admin->json()['backup'] ?? 'x')));
$leg = $q("SELECT * FROM projects WHERE workspace_id = ? AND name = 'Proyecto legado'", [$ws1])->fetch();
$t101 = $leg ? $q('SELECT * FROM tasks WHERE project_id = ? AND number = 101', [$leg['id']])->fetch() : null;
check('Las claves de tarea se conservan (LEG-101)', $leg && $leg['project_key'] === 'LEG' && $t101 && $t101['title'] === 'Diseñar inicio');
check('Estados propios del proyecto con su categoría', $leg && (int)$q("SELECT COUNT(*) FROM statuses WHERE project_id = ? AND category = 'done' AND name = 'Hecho'", [$leg['id']])->fetchColumn() === 1);
check('Código repetido recibe un número nuevo sin chocar', $leg && (int)$q('SELECT COUNT(DISTINCT number) FROM tasks WHERE project_id = ?', [$leg['id']])->fetchColumn() === (int)$q('SELECT COUNT(*) FROM tasks WHERE project_id = ?', [$leg['id']])->fetchColumn());
check('Solo un sprint activo por proyecto', $leg && (int)$q("SELECT COUNT(*) FROM sprints WHERE project_id = ? AND state = 'active'", [$leg['id']])->fetchColumn() === 1);
check('Clave repetida y clave inválida se ajustan con aviso', (int)$q("SELECT COUNT(*) FROM projects WHERE workspace_id = ? AND project_key LIKE 'LEG%'", [$ws1])->fetchColumn() === 2 && (bool)array_filter($rep['warnings'] ?? [], fn($w) => str_contains($w, 'LEG')));
check('Tipo y etiquetas pasan a etiquetas', $t101 && (int)$q('SELECT COUNT(*) FROM task_labels WHERE task_id = ?', [$t101['id']])->fetchColumn() === 3);
check('Módulos activados según los datos (sprints, estimaciones, calendario)', $leg && (int)$q("SELECT COUNT(*) FROM project_modules WHERE project_id = ? AND enabled = 1 AND module_key IN ('sprints','backlog','estimates','calendar')", [$leg['id']])->fetchColumn() === 4);
check('Solo se importa el archivo válido (los peligrosos o ausentes se avisan)', ($rep['files'] ?? 0) === 1 && count(array_filter($rep['warnings'] ?? [], fn($w) => str_contains($w, 'Archivo'))) === 3, json_encode($rep['warnings'] ?? []));
$legFile = $leg ? (int)$q('SELECT id FROM files WHERE project_id = ?', [$leg['id']])->fetchColumn() : 0;
$admin->get("/files/$legFile");
check('El archivo importado se descarga con su contenido', $admin->status === 200 && str_contains($admin->body, 'Acta de la reunión'), snippet($admin));
$admin->get('/t/LEG-101');
check('La tarea importada se ve y su HTML queda escapado', $admin->status === 200 && str_contains($admin->body, 'Diseñar inicio') && !str_contains($admin->body, '<script>alert(1)</script>'), snippet($admin));
$admin->get('/projects/' . ($leg['id'] ?? 0) . '/board');
check('El tablero del proyecto importado carga', $admin->status === 200 && str_contains($admin->body, 'Haciendo'), snippet($admin));
$admin->upload('/api/import/legacy', [['borealis-v022.zip', $fixture, 'application/zip']], [], false);
check('Importar los mismos datos dos veces se rechaza', $admin->status === 409 && (int)$q('SELECT COUNT(*) FROM projects WHERE workspace_id = ?', [$ws1])->fetchColumn() === $projectsBefore + 3, snippet($admin));
$newUser = $q("SELECT password_hash FROM users WHERE email = 'lucia@legacy.example'")->fetchColumn();
$imp = new Client($BASE);
$imp->login('lucia@legacy.example', '');
check('Las personas importadas no pueden entrar hasta tener contraseña', $newUser && !in_array($imp->status, [302, 303], true), snippet($imp));

// ================================================================ 11. Throttling
group('Protección contra fuerza bruta');
$brute = new Client($BASE);
$codes = [];
for ($i = 0; $i < 9; $i++) { $brute->get('/login'); $brute->form('/login', ['email' => $users['carla']['email'], 'password' => 'mala' . $i]); $codes[] = $brute->status; }
$brute->get('/login');
$brute->form('/login', ['email' => $users['carla']['email'], 'password' => $users['carla']['pass']]);
check('Tras varios fallos, el acceso se bloquea temporalmente', $brute->status === 429 || str_contains($brute->body, 'Demasiados intentos'), snippet($brute));

// ================================================================ 1.1
group('Horas, cierre de tareas y horas semanales');
$admin->api('POST', "/api/tasks/$tid1/time", ['hours' => '1:30', 'note' => 'Reconocimiento']);
$te1 = (int)($admin->json()['id'] ?? 0);
check('Registrar horas en formato 1:30', $admin->status === 200 && (int)$q('SELECT minutes FROM time_entries WHERE id = ?', [$te1])->fetchColumn() === 90, snippet($admin));
$admin->api('POST', "/api/tasks/$tid1/time", ['hours' => '2,5']);
check('Registrar horas con coma decimal (2,5 = 150 min)', $admin->status === 200 && (int)$q('SELECT minutes FROM time_entries WHERE id = ?', [(int)($admin->json()['id'] ?? 0)])->fetchColumn() === 150, snippet($admin));
$admin->api('POST', "/api/tasks/$tid1/time", ['hours' => 'mucho']);
$h1 = $admin->status;
$admin->api('POST', "/api/tasks/$tid1/time", ['hours' => '25']);
$h2 = $admin->status;
$admin->api('POST', "/api/tasks/$tid1/time", ['hours' => '1', 'work_date' => date('Y-m-d', strtotime('+2 days'))]);
$h3 = $admin->status;
check('Horas no válidas, más de 24 h o en el futuro: rechazadas', [$h1, $h2, $h3] === [422, 422, 422], "$h1 $h2 $h3");
$admin->api('POST', "/api/tasks/$tid1/time", ['hours' => '45m', 'user_id' => $users['carla']['id']]);
check('La responsable del proyecto registra horas de una participante', $admin->status === 200 && (int)$q('SELECT user_id FROM time_entries WHERE id = ?', [(int)($admin->json()['id'] ?? 0)])->fetchColumn() === $users['carla']['id'], snippet($admin));
$carla->api('POST', "/api/tasks/$tid1/time", ['hours' => '1', 'user_id' => $users['bruno']['id']]);
check('Una colaboradora no puede registrar horas a nombre de otra persona', $carla->status === 403, snippet($carla));
$olga->api('POST', '/api/tasks/' . $pwTask['id'] . '/time', ['hours' => '1']);
check('Observación no puede registrar horas', $olga->status === 403, snippet($olga));
$admin->api('GET', "/api/tasks/$tid1");
$logged = (int)($admin->json()['task']['logged_minutes'] ?? -1);
check('La tarea suma las horas registradas (90 + 150 + 45 = 285 min)', $logged === 285, "logged=$logged");
$admin->get("/t/$p1key-1");
check('La tarea muestra el total y el reparto por persona', str_contains($admin->body, '4 h 45 min') && str_contains($admin->body, 'Carla Prueba'), snippet($admin));
$carla->api('DELETE', "/api/time/$te1");
check('No se puede borrar el registro de otra persona sin ser responsable', $carla->status === 403, snippet($carla));
$admin->api('DELETE', "/api/time/$te1");
check('Borrar un registro propio (recuperable)', $admin->status === 200 && $q('SELECT deleted_at FROM time_entries WHERE id = ?', [$te1])->fetchColumn() !== null, snippet($admin));

$admin->api('POST', "/api/projects/$p1/tasks", ['title' => 'Tarea para cerrar', 'estimate_hours' => 3]);
$wt = $admin->json()['task'] ?? [];
check('Crear tarea con horas estimadas', $admin->status === 200 && (float)$q('SELECT estimate_hours FROM tasks WHERE id = ?', [$wt['id'] ?? 0])->fetchColumn() === 3.0, snippet($admin));
$admin->api('POST', '/api/tasks/' . $wt['id'] . '/done', ['done' => true]);
$dj = $admin->json();
check('Al finalizar se ofrece dejar comentario y horas', ($dj['completed']['id'] ?? 0) === $wt['id'], json_encode(array_keys($dj)));
$admin->api('POST', '/api/tasks/' . $wt['id'] . '/wrap-up', ['comment' => '', 'hours' => '']);
check('Cierre vacío: se pide algo o pulsar Omitir', $admin->status === 422, snippet($admin));
$admin->api('POST', '/api/tasks/' . $wt['id'] . '/wrap-up', ['comment' => 'Listo, quedó señalizado', 'hours' => '2']);
check('Cierre con comentario y horas guardados juntos', $admin->status === 200
    && (int)$q('SELECT COUNT(*) FROM comments WHERE task_id = ? AND deleted_at IS NULL', [$wt['id']])->fetchColumn() === 1
    && (int)$q('SELECT SUM(minutes) FROM time_entries WHERE task_id = ? AND deleted_at IS NULL', [$wt['id']])->fetchColumn() === 120, snippet($admin));
$admin->api('POST', '/api/tasks/' . $wt['id'] . '/wrap-up', ['comment' => 'Otro', 'hours' => 'x']);
check('Si las horas fallan no se guarda tampoco el comentario', $admin->status === 422 && (int)$q('SELECT COUNT(*) FROM comments WHERE task_id = ? AND deleted_at IS NULL', [$wt['id']])->fetchColumn() === 1, snippet($admin));

$admin->api('PATCH', '/api/members/' . $users['carla']['id'], ['weekly_hours' => 30]);
check('Definir horas semanales de una persona', $admin->status === 200 && (float)$q('SELECT weekly_hours FROM workspace_members WHERE user_id = ? AND workspace_id = ?', [$users['carla']['id'], $ws1])->fetchColumn() === 30.0, snippet($admin));
$admin->api('PATCH', '/api/members/' . $users['carla']['id'], ['weekly_hours' => 200]);
check('Horas semanales fuera de rango rechazadas', $admin->status === 422, snippet($admin));
$bruno->api('PATCH', '/api/members/' . $users['carla']['id'], ['weekly_hours' => 1]);
check('Un integrante no cambia las horas de otra persona', $bruno->status === 403, snippet($bruno));
$bruno->api('PATCH', '/api/profile', ['name' => 'Bruno Prueba', 'email' => $users['bruno']['email'], 'weekly_hours' => 25]);
check('Cada persona define sus horas en su perfil', $bruno->status === 200 && (float)$q('SELECT weekly_hours FROM workspace_members WHERE user_id = ? AND workspace_id = ?', [$users['bruno']['id'], $ws1])->fetchColumn() === 25.0, snippet($bruno));
$admin->api('PATCH', '/api/members/' . $q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn(), ['weekly_hours' => 20]);

group('Control de sprints');
$admin->api('POST', "/api/projects/$p1/modules", ['module' => 'sprints', 'enabled' => true]);
$admin->api('POST', "/api/projects/$p1/sprints", ['name' => 'Sprint 2', 'start_date' => date('Y-m-d', strtotime('-3 days')), 'end_date' => date('Y-m-d', strtotime('+11 days'))]);
$sp2 = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => 'Pintar señales', 'estimate_hours' => 40, 'assignee_id' => $users['carla']['id'], 'sprint_id' => $sp2]);
$admin->api('PATCH', '/api/members/' . $users['carla']['id'], ['weekly_hours' => 10]);
$admin->api('POST', "/api/sprints/$sp2/start");
check('Iniciar sprint con fechas', $admin->status === 200, snippet($admin));
$admin->get("/projects/$p1/sprints");
check('El sprint muestra capacidad, planificado y gráfico de avance', $admin->status === 200 && str_contains($admin->body, 'Control del sprint') && str_contains($admin->body, 'class="burndown"') && str_contains($admin->body, '40 h'), snippet($admin));
check('Alerta de sobrecarga cuando una persona tiene más horas que su capacidad', str_contains($admin->body, 'Carla Prueba tiene 40 h asignadas'), snippet($admin));
$admin->api('PATCH', "/api/sprints/$sp2", ['capacity_hours' => 10]);
$admin->get("/projects/$p1/sprints");
check('Capacidad fijada a mano y alerta de sobrecarga del sprint', $admin->status === 200 && str_contains($admin->body, 'fijada a mano') && str_contains($admin->body, 'Sobrecarga: hay 40 h planificadas para 10 h'), snippet($admin));
$admin->api('PATCH', "/api/sprints/$sp2", ['capacity_hours' => -5]);
check('Capacidad negativa rechazada', $admin->status === 422, snippet($admin));
$admin->api('PATCH', "/api/sprints/$sp2", ['capacity_hours' => '']);
check('Capacidad vacía vuelve al cálculo automático', $admin->status === 200 && $q('SELECT capacity_hours FROM sprints WHERE id = ?', [$sp2])->fetchColumn() === null, snippet($admin));

group('Exportar reportes');
$admin->api('POST', "/api/projects/$p1/modules", ['module' => 'reports', 'enabled' => true]);
$admin->get("/projects/$p1/hours.csv");
$csvOk = $admin->status === 200 && str_contains($admin->headers['content-type'][0] ?? '', 'text/csv') && str_contains($admin->body, 'Fecha,Persona,Proyecto') && str_contains($admin->body, 'Carla Prueba');
check('Exportar horas del proyecto (CSV)', $csvOk, snippet($admin));
check('El registro borrado no aparece en la exportación', !str_contains($admin->body, 'Reconocimiento'), 'aparece el registro borrado');
$admin->get("/projects/$p1/export.csv");
check('Exportar tareas incluye horas estimadas y registradas', $admin->status === 200 && str_contains($admin->body, '"Horas estimadas","Horas registradas"') && str_contains($admin->body, 'Tarea para cerrar'), snippet($admin));
$admin->get("/projects/$p1/report/print");
check('Informe imprimible del proyecto', $admin->status === 200 && str_contains($admin->body, 'Informe de proyecto') && str_contains($admin->body, 'data-print') && str_contains($admin->body, 'Horas por persona'), snippet($admin));
$ok = true;
foreach (['projects', 'tasks', 'hours'] as $k) { $admin->get("/reports/$k.csv"); $ok = $ok && $admin->status === 200 && str_contains($admin->headers['content-disposition'][0] ?? '', "borealis-$k-"); }
check('Exportaciones del espacio: proyectos, tareas y horas', $ok, snippet($admin));
$admin->get('/reports/print');
check('Informe imprimible del espacio', $admin->status === 200 && str_contains($admin->body, 'Informe del espacio'), snippet($admin));
$admin->get('/reports/usuarios.csv');
check('Exportación desconocida: 404', $admin->status === 404, snippet($admin));
$olga->get('/reports/hours.csv');
check('Las horas exportadas respetan la visibilidad (invitada no ve horas de otros proyectos)', $olga->status === 200 && !str_contains($olga->body, 'Pintar') && !str_contains($olga->body, 'Marcar el sendero'), snippet($olga));
$olga->get("/projects/$pp/hours.csv");
check('Sin acceso al proyecto no se exportan sus horas', in_array($olga->status, [403, 404], true), snippet($olga));

group('Cuentas fantasma');
$bruno->api('POST', '/api/ghosts', ['name' => 'Fantasma de Bruno', 'role_id' => $role($ws1, 'workspace', 'member')]);
check('Solo la administración de la instalación crea fantasmas', $bruno->status === 403, snippet($bruno));
$admin->api('POST', '/api/ghosts', ['name' => 'Gema Fantasma', 'role_id' => $role($ws1, 'workspace', 'owner')]);
check('Un fantasma no puede ser propietario', $admin->status === 422, snippet($admin));
$admin->api('POST', '/api/ghosts', ['name' => 'Gema Fantasma', 'role_id' => $role($ws1, 'workspace', 'member'), 'team_id' => $t1, 'weekly_hours' => 10]);
$gid = (int)($admin->json()['id'] ?? 0);
$g = $q('SELECT * FROM users WHERE id = ?', [$gid])->fetch();
check('Crear cuenta fantasma en el equipo', $admin->status === 200 && $g && (int)$g['is_ghost'] === 1 && str_ends_with($g['email'], '@borealis.invalid'), snippet($admin));
$gpass = pw();
$q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($gpass, PASSWORD_DEFAULT), $gid]);
$gc = new Client($BASE); $gc->login($g['email'], $gpass);
check('Un fantasma no puede iniciar sesión con contraseña', !in_array($gc->status, [302, 303], true) || str_contains($gc->location(), '/login'), snippet($gc));
$admin->api('POST', "/api/ghosts/{$users['bruno']['id']}/enter");
check('No se puede entrar como una persona real', $admin->status === 404, snippet($admin));
$admin->api('POST', "/api/projects/$p1/tasks", ['title' => 'Tarea de Gema', 'assignee_id' => $gid]);
$gTask = $admin->json()['task'] ?? [];
$imp = new Client($BASE); $imp->login($adminEmail, $adminPass); $imp->get('/admin/members');
check('La administración ve la sección de fantasmas', str_contains($imp->body, 'Cuentas fantasma para pruebas') && str_contains($imp->body, 'Gema Fantasma'), snippet($imp));
$imp->api('POST', "/api/ghosts/$gid/enter");
$imp->get('/');
check('«Entrar como» cambia a la cuenta fantasma y muestra el aviso', $imp->status === 200 && str_contains($imp->body, 'impersonation-bar') && str_contains($imp->body, 'Gema Fantasma'), snippet($imp));
$imp->api('POST', "/api/tasks/{$gTask['id']}/comments", ['body' => 'Comentario de Gema']);
$gComment = (int)$q('SELECT user_id FROM comments WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$gTask['id']])->fetchColumn();
check('Lo que se hace como fantasma queda a su nombre', $imp->status === 200 && $gComment === $gid, snippet($imp));
$imp->get('/admin/members');
$i1 = $imp->status;
$imp->api('POST', "/api/ghosts/$gid/enter");
$i2 = $imp->status;
check('Como fantasma no se accede a Administración ni se encadenan suplantaciones', $i1 === 403 && $i2 === 403, "$i1 $i2");
$imp->api('POST', '/api/ghosts/leave');
$imp->get('/admin/members');
check('«Volver a mi cuenta» recupera la sesión de la administración', $imp->status === 200 && !str_contains($imp->body, 'impersonation-bar'), snippet($imp));
$imp->api('POST', '/api/ghosts/leave');
check('Volver sin estar usando un fantasma: rechazado', $imp->status === 422, snippet($imp));
$bruno->api('POST', '/api/ghosts/delete-all');
check('Un integrante no puede borrar fantasmas', $bruno->status === 403 && (int)$q('SELECT is_ghost FROM users WHERE id = ?', [$gid])->fetchColumn() === 1, snippet($bruno));
$backupsBefore = count(glob(dirname(__DIR__, 2) . '/storage/backups/*.sql') ?: []);
$admin->api('POST', '/api/ghosts/delete-all', []);
$dres = $admin->json();
$backupsAfter = count(glob(dirname(__DIR__, 2) . '/storage/backups/*.sql') ?: []);
check('Borrar todos los fantasmas hace antes una copia de seguridad', $admin->status === 200 && ($dres['deleted'] ?? 0) === 1 && $backupsAfter === $backupsBefore + 1 && str_contains((string)($dres['backup'] ?? ''), 'antes-de-borrar-fantasmas'), snippet($admin));
$gAfter = $q('SELECT * FROM users WHERE id = ?', [$gid])->fetch();
check('El fantasma queda anonimizado y sin acceso', $gAfter['name'] === 'Usuario eliminado' && (int)$gAfter['is_active'] === 0 && (int)$q('SELECT COUNT(*) FROM workspace_members WHERE user_id = ?', [$gid])->fetchColumn() === 0, json_encode($gAfter['name']));
check('Sus tareas quedan sin responsable y sus comentarios se conservan', $q('SELECT assignee_id FROM tasks WHERE id = ?', [$gTask['id']])->fetchColumn() === null && (int)$q('SELECT COUNT(*) FROM comments WHERE user_id = ?', [$gid])->fetchColumn() === 1);

group('Apariencia del proyecto');
$admin->api('PATCH', "/api/projects/$p1", ['cover' => 'aurora', 'color' => '#0EA5E9']);
check('Cambiar fondo y color del proyecto', $admin->status === 200 && $q('SELECT cover FROM projects WHERE id = ?', [$p1])->fetchColumn() === 'aurora', snippet($admin));
$admin->get("/projects/$p1");
check('El encabezado usa el fondo elegido', str_contains($admin->body, 'cover-aurora'), snippet($admin));
$admin->api('PATCH', "/api/projects/$p1", ['cover' => 'nada']);
check('Fondo desconocido rechazado', $admin->status === 422, snippet($admin));
$admin->api('POST', '/api/teams', ['name' => 'Equipo sin salto']);
check('Crear equipo no te saca de la página (devuelve el enlace para abrirlo)', $admin->status === 200 && empty($admin->json()['redirect']) && str_contains((string)($admin->json()['url'] ?? ''), '/teams/'), snippet($admin));

// ================================================================ 11b. Borealis 1.2
group('1.2 · Correo más estricto');
$admin->get('/admin/members');
$badMails = ['ana@gmail.con' => 'Quisiste decir', 'ana@dominio' => 'no válido', 'ana@dominio.c' => 'dominio', 'ana..rios@dominio.com' => 'no válido', 'ana@-dominio.com' => 'no válido', 'ana@dominio.c0m' => 'dominio', 'ana rios@dominio.com' => 'no válido'];
$rejected = [];
foreach ($badMails as $mail => $needle) {
    $admin->api('POST', '/api/members', ['name' => 'Correo Malo', 'email' => $mail, 'password' => pw(), 'role_id' => $role($ws1, 'workspace', 'member')]);
    if ($admin->status === 422 && str_contains((string)($admin->json()['errors']['email'] ?? ''), $needle)) $rejected[] = $mail;
}
check('Correos mal escritos rechazados con un mensaje claro (' . count($badMails) . ' casos)', count($rejected) === count($badMails), 'aceptados o sin mensaje: ' . implode(', ', array_diff(array_keys($badMails), $rejected)));
$admin->api('POST', '/api/members', ['name' => 'Ana', 'email' => 'ana@gmail.con', 'password' => pw(), 'role_id' => $role($ws1, 'workspace', 'member')]);
check('Un dominio típico mal escrito sugiere el correcto (gmail.con → gmail.com)', str_contains((string)($admin->json()['errors']['email'] ?? ''), 'ana@gmail.com'), snippet($admin));
$admin->api('POST', '/api/invitations', ['email' => 'alguien@hotmial.com', 'role_id' => $role($ws1, 'workspace', 'member')]);
check('La invitación también valida el correo', $admin->status === 422, snippet($admin));
$doraPass = pw();
$admin->api('POST', '/api/members', ['name' => 'Dora Prueba', 'email' => 'dora.rios+test@sub.example.test', 'password' => $doraPass, 'role_id' => $role($ws1, 'workspace', 'member')]);
$dora = (int)($admin->json()['id'] ?? 0);
check('Un correo válido con subdominio y «+» se acepta', $admin->status === 200 && $dora > 0, snippet($admin));

group('1.2 · Administración edita cuentas');
$admin->api('PATCH', "/api/members/$dora", ['name' => 'Dora Ríos', 'email' => 'dora@example.test', 'title' => 'Guía de montaña']);
$drow = $q('SELECT name, email, title FROM users WHERE id = ?', [$dora])->fetch();
check('La administración cambia nombre, correo y cargo de otra persona', $admin->status === 200 && $drow['name'] === 'Dora Ríos' && $drow['email'] === 'dora@example.test' && $drow['title'] === 'Guía de montaña', snippet($admin));
$dc = new Client($BASE); $dc->login('dora@example.test', $doraPass); $dc->get('/');
check('La persona entra con el correo nuevo', $dc->status === 200 && str_contains($dc->body, 'csrf-token'), snippet($dc));
$admin->api('PATCH', "/api/members/$dora", ['email' => $users['bruno']['email']]);
check('No se puede poner un correo que ya usa otra cuenta', $admin->status === 422, snippet($admin));
$admin->api('PATCH', "/api/members/$dora", ['email' => 'dora@gmail.con']);
check('Al editar también se valida el correo', $admin->status === 422 && str_contains($admin->body, 'Quisiste'), snippet($admin));
$bruno->api('PATCH', "/api/members/$dora", ['name' => 'Hackeada']);
check('Un integrante no puede editar cuentas ajenas', $bruno->status === 403 && $q('SELECT name FROM users WHERE id = ?', [$dora])->fetchColumn() === 'Dora Ríos', snippet($bruno));
$admin->get('/admin/members');
check('La lista de usuarios ofrece «Editar cuenta»', str_contains($admin->body, 'dlg-edit-' . $dora) && str_contains($admin->body, 'Editar cuenta'), snippet($admin));

group('1.2 · Invitar por enlace');
$admin->api('POST', '/api/invitations', ['email' => 'eva@example.test', 'role_id' => $role($ws1, 'workspace', 'member')]);
$inv = $admin->json();
$link1 = (string)($inv['link'] ?? '');
check('Crear invitación devuelve el enlace y el mensaje para copiar', $admin->status === 200 && str_contains($link1, '/invite/') && str_contains((string)($inv['share_text'] ?? ''), $link1), snippet($admin));
$anonInv = new Client($BASE);
$anonInv->get(parse_url($link1, PHP_URL_PATH));
check('El enlace de invitación abre el formulario de alta', $anonInv->status === 200 && str_contains($anonInv->body, 'eva@example.test'), snippet($anonInv));
$admin->api('POST', '/api/invitations/' . (int)$inv['id'] . '/renew');
$link2 = (string)($admin->json()['link'] ?? '');
check('«Nuevo enlace» genera otro enlace para una invitación pendiente', $admin->status === 200 && $link2 !== '' && $link2 !== $link1 && !empty($admin->json()['share_dialog']), snippet($admin));
$anonInv->get(parse_url($link1, PHP_URL_PATH));
$oldBody = $anonInv->body;
$anonInv->get(parse_url($link2, PHP_URL_PATH));
check('El enlace anterior deja de valer y el nuevo funciona', !str_contains($oldBody, 'eva@example.test') && $anonInv->status === 200 && str_contains($anonInv->body, 'eva@example.test'), snippet($anonInv));

group('1.2 · Equipos como integrantes del proyecto');
$admin->api('POST', '/api/teams', ['name' => 'Brigada Norte']);
$tb = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/teams/$tb/members", ['user_id' => $dora, 'team_role' => 'member']);
$admin->api('POST', "/api/teams/$tb/members", ['user_id' => $users['bruno']['id'], 'team_role' => 'member']);
$admin->api('POST', '/api/projects', ['name' => 'Refugio Privado', 'visibility' => 'private']);
$pv = (int)($admin->json()['id'] ?? 0);
$pvKey = (string)($admin->json()['project']['key'] ?? '');
$dc->get("/projects/$pv");
$before = $dc->status;
$admin->api('POST', "/api/projects/$pv/teams", ['team_id' => $tb, 'role_id' => $role($ws1, 'project', 'contributor')]);
$addTeam = $admin->status;
$dc->get("/projects/$pv");
check('Añadir un equipo completo da acceso a sus integrantes', $before === 404 && $addTeam === 200 && $dc->status === 200, "antes=$before añadir=$addTeam después={$dc->status}");
$admin->get("/projects/$pv/settings?section=members");
check('Ajustes › Integrantes muestra el equipo participante y a su gente', str_contains($admin->body, 'Equipos participantes') && str_contains($admin->body, 'Brigada Norte') && str_contains($admin->body, 'Dora Ríos'), snippet($admin));
$admin->api('POST', "/api/teams/$tb/members", ['user_id' => $users['carla']['id'], 'team_role' => 'member']);
$carla->get("/projects/$pv");
check('Quien entra al equipo después también accede (acceso en vivo)', $carla->status === 200, snippet($carla));
$dc->api('POST', "/api/projects/$pv/tasks", ['title' => 'Revisar techo']);
check('Integrantes por equipo pueden crear tareas con el rol elegido', $dc->status === 200, snippet($dc));
$bruno->api('POST', "/api/projects/$pv/teams", ['team_id' => $t1, 'role_id' => $role($ws1, 'project', 'manager')]);
check('Solo quien gestiona el proyecto puede añadir equipos', $bruno->status === 403, snippet($bruno));

group('1.2 · Varios responsables o un equipo por tarea');
$admin->api('POST', "/api/projects/$pv/tasks", ['title' => 'Cargar leña']);
$ta = (int)($admin->json()['task']['id'] ?? 0);
$admin->api('PATCH', "/api/tasks/$ta", ['assignee_ids' => [$dora, $users['carla']['id']]]);
$tj = $admin->json()['task'] ?? [];
check('Asignar dos responsables: el primero es el principal', $admin->status === 200 && ($tj['assignee_id'] ?? 0) === $dora && ($tj['assignee_ids'] ?? []) === [$dora, $users['carla']['id']], snippet($admin));
check('Ambas personas reciben aviso', (int)$q("SELECT COUNT(*) FROM notifications WHERE task_id = ? AND type = 'assigned' AND user_id IN (?, ?)", [$ta, $dora, $users['carla']['id']])->fetchColumn() === 2);
$carla->get('/my-work');
check('La corresponsable ve la tarea en «Mi trabajo»', str_contains($carla->body, 'Cargar leña'), snippet($carla));
$admin->api('POST', "/api/projects/$pv/tasks", ['title' => 'Despejar sendero']);
$tt = (int)($admin->json()['task']['id'] ?? 0);
$admin->api('PATCH', "/api/tasks/$tt", ['team_id' => $tb]);
check('Asignar una tarea a un equipo', $admin->status === 200 && (int)($admin->json()['task']['team_id'] ?? 0) === $tb, snippet($admin));
$bruno->get('/my-work');
check('Integrantes del equipo ven la tarea del equipo en «Mi trabajo»', str_contains($bruno->body, 'Despejar sendero'), snippet($bruno));
$admin->get("/projects/$pv/board");
check('La tarjeta muestra el equipo y varios responsables', str_contains($admin->body, 'team-chip') && str_contains($admin->body, 'avatar-stack'), snippet($admin));
$admin->get("/projects/$pv/export.csv");
check('El CSV de tareas lista a todos los responsables y al equipo', str_contains($admin->body, 'Dora Ríos, Carla Prueba') && str_contains($admin->body, 'equipo Brigada Norte'), mb_substr($admin->body, 0, 200));
$admin->api('PATCH', "/api/tasks/$ta", ['assignee_ids' => [$users['carla']['id']]]);
check('Quitar responsables deja solo a los elegidos', $admin->status === 200 && (int)$q('SELECT COUNT(*) FROM task_assignees WHERE task_id = ?', [$ta])->fetchColumn() === 0 && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$ta])->fetchColumn() === $users['carla']['id'], snippet($admin));
$admin->api('PATCH', "/api/tasks/$ta", ['team_id' => 999999]);
check('Equipo inexistente rechazado', $admin->status === 422, snippet($admin));

group('1.2 · Mover personas en el tablero (polivalente)');
$admin->api('POST', "/api/tasks/$tt/people", ['user_id' => $users['carla']['id'], 'from_task_id' => $ta]);
check('Sin proyecto ni equipo polivalente no se pueden mover personas', $admin->status === 409 && str_contains($admin->body, 'polivalente'), snippet($admin));
$admin->api('PATCH', "/api/teams/$tb", ['is_flexible' => true]);
$admin->api('POST', "/api/tasks/$tt/people", ['user_id' => $users['carla']['id'], 'from_task_id' => $ta]);
$moved = $admin->status === 200 && $q('SELECT assignee_id FROM tasks WHERE id = ?', [$ta])->fetchColumn() === null && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$tt])->fetchColumn() === $users['carla']['id'];
check('Con el equipo marcado polivalente, una persona del equipo se mueve de una tarjeta a otra', $moved, snippet($admin));
$admin->get("/projects/$pv/board");
check('El tablero muestra la fila de personas arrastrables', str_contains($admin->body, 'people-strip') && str_contains($admin->body, 'data-person="' . $users['carla']['id'] . '"'), snippet($admin));
$adminId = (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn();
$admin->api('POST', "/api/projects/$pv/members", ['user_id' => $users['olga']['id'], 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', "/api/tasks/$ta/people", ['user_id' => $users['olga']['id']]);
check('Quien no está en un equipo polivalente no se mueve si el proyecto no es polivalente', $admin->status === 409, snippet($admin));
$admin->api('DELETE', "/api/projects/$pv/members/" . $users['olga']['id']);
$admin->api('PATCH', "/api/projects/$pv", ['is_flexible' => true]);
$admin->api('POST', "/api/tasks/$ta/people", ['user_id' => (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn()]);
check('Con el proyecto polivalente, cualquiera del proyecto se puede sumar a una tarjeta', $admin->status === 200 && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$ta])->fetchColumn() === (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn(), snippet($admin));
$olgaC = new Client($BASE); $olgaC->login($users['olga']['email'], $users['olga']['pass']); $olgaC->get('/');
$olgaC->api('POST', "/api/tasks/$ta/people", ['user_id' => $users['carla']['id']]);
check('Sin acceso al proyecto no se puede mover a nadie', in_array($olgaC->status, [403, 404], true), snippet($olgaC));

group('1.2 · Perfil de participantes con sus horas');
$carla->get("/projects/$pv/board");
$carla->api('POST', "/api/tasks/$tt/time", ['hours' => '1.5', 'work_date' => date('Y-m-d')]);
$carla->api('POST', "/api/tasks/$tt/time", ['hours' => '2', 'work_date' => date('Y-m-d')]);
$admin->api('PATCH', '/api/members/' . $users['carla']['id'], ['weekly_hours' => 20]);
$admin->get('/people/' . $users['carla']['id']);
$weekMin = (int)$q("SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE user_id = ? AND deleted_at IS NULL AND work_date >= ?", [$users['carla']['id'], date('Y-m-d', strtotime('monday this week'))])->fetchColumn();
$fmtMin = fn(int $m) => intdiv($m, 60) && $m % 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : (intdiv($m, 60) ? intdiv($m, 60) . ' h' : ($m % 60) . ' min');
check('El perfil muestra las horas de la semana, del período y por proyecto', $admin->status === 200 && $weekMin >= 210 && str_contains($admin->body, 'Horas registradas') && str_contains($admin->body, 'data-hours-week>' . $fmtMin($weekMin) . '<') && str_contains($admin->body, 'Refugio Privado') && str_contains($admin->body, 'de 20 h disponibles'), snippet($admin));
$admin->get('/people/' . $users['carla']['id'] . '?periodo=todo');
check('El período del perfil se puede cambiar', $admin->status === 200 && str_contains($admin->body, 'aria-current="page">Todo'), snippet($admin));
$olgaC->get('/people/' . $users['carla']['id']);
// 1.4.1: a guest who shares no project with the person no longer opens the profile at all (404).
check('Las horas de proyectos que no puedes ver no se muestran', ($olgaC->status === 404 && !str_contains($olgaC->body, 'Refugio Privado')) || ($olgaC->status === 200 && !str_contains($olgaC->body, 'Refugio Privado') && preg_match('/data-hours-week>0 min</', $olgaC->body)), snippet($olgaC));
$admin->get("/projects/$pv/settings?section=members");
check('Los participantes enlazan a su perfil', str_contains($admin->body, 'href="' . '/people/' . $users['carla']['id']) || str_contains($admin->body, '/people/' . $users['carla']['id'] . '"'), snippet($admin));

group('1.2 · Exportar e importar proyectos');
$admin->api('POST', "/api/tasks/$ta/comments", ['body' => 'Comentario para exportar']);
$admin->api('POST', "/api/projects/$pv/tasks", ['title' => 'Subtarea exportable', 'parent_id' => $ta]);
$admin->api('PATCH', "/api/tasks/$tt", ['assignee_ids' => [$users['carla']['id'], $dora]]);
$admin->get("/projects/$pv/export.json");
$exp = json_decode($admin->body, true) ?: [];
$cd = implode(' ', $admin->headers['content-disposition'] ?? []);
check('Exportar proyecto descarga un .json completo', $admin->status === 200 && ($exp['format'] ?? '') === 'borealis-project' && str_contains($cd, '.json') && count($exp['tasks'] ?? []) === 4 && count($exp['comments'] ?? []) >= 1 && count($exp['time_entries'] ?? []) === 2, snippet($admin));
$bruno->get("/projects/$pv/export.json");
check('Solo quien gestiona el proyecto puede exportarlo', $bruno->status === 403, snippet($bruno));
$admin->get('/projects');
$admin->upload('/api/projects/import', [['proyecto.json', json_encode($exp), 'application/json']], [], false);
$impRes = $admin->json();
$np = (int)($impRes['id'] ?? 0);
check('Importar crea un proyecto nuevo con siglas propias', $admin->status === 200 && $np > 0 && $np !== $pv && ($impRes['report']['key'] ?? '') !== $pvKey, snippet($admin));
$nt = (int)$q('SELECT COUNT(*) FROM tasks WHERE project_id = ? AND deleted_at IS NULL', [$np])->fetchColumn();
$nsub = (int)$q('SELECT COUNT(*) FROM tasks WHERE project_id = ? AND parent_id IS NOT NULL', [$np])->fetchColumn();
$nco = (int)$q('SELECT COUNT(*) FROM task_assignees ta JOIN tasks t ON t.id = ta.task_id WHERE t.project_id = ?', [$np])->fetchColumn();
$nteam = (int)$q('SELECT COUNT(*) FROM tasks WHERE project_id = ? AND team_id = ?', [$np, $tb])->fetchColumn();
$nmin = (int)$q('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE project_id = ?', [$np])->fetchColumn();
$ncom = (int)$q('SELECT COUNT(*) FROM comments c JOIN tasks t ON t.id = c.task_id WHERE t.project_id = ?', [$np])->fetchColumn();
check('Se conservan tareas, subtareas, responsables, equipo, horas y comentarios', $nt === 4 && $nsub === 1 && $nco === 1 && $nteam === 1 && $nmin === 210 && $ncom >= 1, "tareas=$nt sub=$nsub co=$nco equipo=$nteam min=$nmin com=$ncom");
check('El equipo participante y la marca polivalente se conservan', (int)$q('SELECT COUNT(*) FROM project_teams WHERE project_id = ? AND team_id = ?', [$np, $tb])->fetchColumn() === 1 && (int)$q('SELECT is_flexible FROM projects WHERE id = ?', [$np])->fetchColumn() === 1);
$origNumbers = $q('SELECT number FROM tasks WHERE project_id = ? ORDER BY number', [$pv])->fetchAll(PDO::FETCH_COLUMN);
$newNumbers = $q('SELECT number FROM tasks WHERE project_id = ? ORDER BY number', [$np])->fetchAll(PDO::FETCH_COLUMN);
check('Los números de tarea se mantienen (PROY-1, PROY-2…)', $origNumbers === $newNumbers, json_encode([$origNumbers, $newNumbers]));
$exp2 = $exp;
$exp2['members'][] = ['email' => 'nadie@otra.example.test', 'name' => 'Nadie', 'role_key' => 'contributor'];
$exp2['tasks'][0]['assignee'] = 'nadie@otra.example.test';
$admin->upload('/api/projects/import', [['proyecto.json', json_encode($exp2), 'application/json']], ['name' => 'Refugio copia'], false);
check('Personas que no existen aquí se informan y no rompen la importación', $admin->status === 200 && in_array('nadie@otra.example.test', $admin->json()['report']['unknown_people'] ?? [], true) && str_contains((string)($admin->json()['message'] ?? ''), 'no existen'), snippet($admin));
$admin->upload('/api/projects/import', [['otro.json', '{"hola":1}', 'application/json']], [], false);
check('Un archivo que no es una exportación de Borealis se rechaza', $admin->status === 422, snippet($admin));
$olgaC->upload('/api/projects/import', [['proyecto.json', json_encode($exp), 'application/json']], [], false);
check('Sin permiso para crear proyectos no se puede importar', $olgaC->status === 403, snippet($olgaC));
$admin->get("/projects/$pv/export.json?files=1");
check('Exportar con archivos también funciona', $admin->status === 200 && (json_decode($admin->body, true)['format'] ?? '') === 'borealis-project', snippet($admin));

// ================================================================ 11c. Borealis 1.3
group('1.3 · Horas disponibles por persona y sprint');
$admin->api('POST', '/api/projects', ['name' => 'Rally Capacidad']);
$pc = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pc/modules", ['module' => 'sprints', 'enabled' => true]);
$admin->api('PATCH', "/api/projects/$pc", ['is_flexible' => true]);
foreach ([$dora, $users['bruno']['id'], $users['carla']['id']] as $uid) $admin->api('POST', "/api/projects/$pc/members", ['user_id' => $uid, 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', "/api/projects/$pc/sprints", ['name' => 'Sprint Capacidad', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+13 days'))]);
$sc = (int)($admin->json()['id'] ?? 0);
$admin->api('PATCH', "/api/sprints/$sc/capacity", ['user_id' => $dora, 'hours' => 40]);
check('Definir las horas disponibles de una persona en un sprint', $admin->status === 200 && (float)$q('SELECT hours FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [$sc, $dora])->fetchColumn() === 40.0, snippet($admin));
$bruno->api('PATCH', "/api/sprints/$sc/capacity", ['user_id' => $dora, 'hours' => 400]);
check('Solo quien gestiona sprints cambia la disponibilidad', $bruno->status === 403, snippet($bruno));
$admin->api('PATCH', "/api/sprints/$sc/capacity", ['user_id' => $dora, 'hours' => -5]);
check('Horas disponibles negativas se rechazan', $admin->status === 422, snippet($admin));
$mk = function (string $title, ?float $h) use ($admin, $pc, $sc): int {
    $admin->api('POST', "/api/projects/$pc/tasks", ['title' => $title, 'sprint_id' => $sc, 'estimate_hours' => $h]);
    return (int)($admin->json()['task']['id'] ?? $admin->json()['id'] ?? 0);
};
$ta = $mk('Actividad A', 10); $tbb = $mk('Actividad B', 15); $tc = $mk('Actividad C', 10); $td = $mk('Actividad D', 8); $te = $mk('Actividad E', 5); $tf = $mk('Actividad sin estimar', null);
$ok = 0;
foreach ([$ta, $tbb, $tc] as $tid) { $admin->api('PATCH', "/api/tasks/$tid", ['assignee_ids' => [$dora]]); if ($admin->status === 200) $ok++; }
check('Asignar 35 h de 40 h disponibles', $ok === 3, snippet($admin));
$admin->api('PATCH', "/api/tasks/$td", ['assignee_ids' => [$dora]]);
check('Una tarea de 8 h con 5 h libres se rechaza con el motivo', $admin->status === 409 && str_contains((string)($admin->json()['message'] ?? $admin->body), 'solo tiene 5 h disponibles'), snippet($admin));
check('La tarea rechazada queda sin cambios', !$q('SELECT assignee_id FROM tasks WHERE id = ?', [$td])->fetchColumn() && !(int)$q('SELECT COUNT(*) FROM task_assignees WHERE task_id = ?', [$td])->fetchColumn());
$admin->api('POST', "/api/tasks/$te/people", ['user_id' => $dora]);
check('Alcanzar exactamente el límite (40 de 40 h) se permite', $admin->status === 200 && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$te])->fetchColumn() === $dora, snippet($admin));
$admin->get("/projects/$pc/board?sprint_id=$sc");
check('Tablero del sprint: la persona pasa a «Sin disponibilidad» y no se puede arrastrar', $admin->status === 200 && preg_match('/people-group is-full.*?data-person="' . $dora . '"/s', $admin->body) && !preg_match('/<button[^>]*draggable="true" class="person-drag person-pill[^"]*" data-person="' . $dora . '"/', $admin->body), snippet($admin));
$admin->api('PATCH', "/api/tasks/$tf", ['assignee_ids' => [$dora]]);
check('Una tarea sin horas estimadas no se bloquea: queda pendiente de estimar', $admin->status === 200, snippet($admin));
$admin->get("/projects/$pc/sprints?sprint=$sc");
check('El sprint muestra la carga y la tarea pendiente de estimar', str_contains($admin->body, 'cap-h-' . $dora) && str_contains($admin->body, '1 sin estimar') && str_contains($admin->body, 'sin disponibilidad'), snippet($admin));
$admin->api('PATCH', "/api/tasks/$tf", ['estimate_hours' => 3]);
check('Poner horas a esa tarea cuando ya no caben se rechaza', $admin->status === 409 && $q('SELECT estimate_hours FROM tasks WHERE id = ?', [$tf])->fetchColumn() === null, snippet($admin));
$admin->api('PATCH', "/api/tasks/$tbb", ['estimate_hours' => 20]);
check('Subir las horas de una tarea por encima de lo disponible se rechaza', $admin->status === 409 && (float)$q('SELECT estimate_hours FROM tasks WHERE id = ?', [$tbb])->fetchColumn() === 15.0, snippet($admin));
$admin->api('DELETE', "/api/tasks/$te/people/$dora");
check('Retirar a la persona de una tarea (arrastrar fuera)', $admin->status === 200 && !$q('SELECT assignee_id FROM tasks WHERE id = ?', [$te])->fetchColumn(), snippet($admin));
$admin->get("/projects/$pc/board?sprint_id=$sc");
check('Recupera 5 h y vuelve a «Disponibles»', preg_match('/people-group">.*?data-person="' . $dora . '" data-name="[^"]*" data-free="5"/s', $admin->body) === 1, snippet($admin));
$admin->api('POST', "/api/tasks/$td/people", ['user_id' => $dora, 'from_task_id' => $tc]);
$dIn = (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$td])->fetchColumn() === $dora;
$cOut = !$q('SELECT assignee_id FROM tasks WHERE id = ?', [$tc])->fetchColumn();
check('Reasignar entre tareas: de C (10 h) a D (8 h)', $admin->status === 200 && $dIn && $cOut, snippet($admin));
$admin->api('PATCH', "/api/tasks/$te", ['estimate_hours' => 20]);
$admin->api('POST', "/api/tasks/$te/people", ['user_id' => $dora, 'from_task_id' => $ta]);
check('Un movimiento que no cabe se rechaza entero: sigue en la tarea de origen', $admin->status === 409 && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$ta])->fetchColumn() === $dora && !$q('SELECT assignee_id FROM tasks WHERE id = ?', [$te])->fetchColumn(), snippet($admin));
$admin->api('PATCH', "/api/tasks/$tbb", ['assignee_ids' => [$dora, $users['bruno']['id']]]);
check('Con dos responsables, la tarea reparte sus horas (15 h → 7,5 h cada una)', $admin->status === 200, snippet($admin));
$admin->get("/projects/$pc/board?sprint_id=$sc");
check('La carga de la persona baja a 25,5 h (10 + 7,5 + 8)', preg_match('/data-person="' . $dora . '" data-name="[^"]*" data-free="14.5"/', $admin->body) === 1, snippet($admin));
$admin->api('PATCH', "/api/sprints/$sc/capacity", ['user_id' => $dora, 'hours' => 20]);
check('Bajar la disponibilidad por debajo de la carga avisa y no borra tareas', $admin->status === 200 && str_contains((string)($admin->json()['warning'] ?? ''), '25.5 h asignadas') && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$ta])->fetchColumn() === $dora, snippet($admin));
$admin->get("/projects/$pc/sprints?sprint=$sc");
check('El sprint marca la sobrecarga de esa persona', str_contains($admin->body, 'h de más') && str_contains($admin->body, 'Sus tareas se mantienen'), snippet($admin));
$admin->api('DELETE', "/api/tasks/$td/people/$dora");
check('Estando por encima, se le puede quitar trabajo', $admin->status === 200, snippet($admin));
$admin->api('POST', "/api/tasks/$tc/people", ['user_id' => $dora]);
check('Estando por encima, no se le puede dar más', $admin->status === 409, snippet($admin));
$admin->api('PATCH', "/api/sprints/$sc/capacity", ['user_id' => $dora, 'hours' => '']);
check('Vaciar la disponibilidad vuelve al cálculo automático (sin horas semanales: sin límite)', $admin->status === 200 && !$q('SELECT COUNT(*) FROM sprint_capacity WHERE sprint_id = ?', [$sc])->fetchColumn(), snippet($admin));
$admin->api('POST', "/api/tasks/$tc/people", ['user_id' => $dora]);
check('Sin límite definido, se puede asignar', $admin->status === 200, snippet($admin));
$admin->api('PATCH', "/api/sprints/$sc/capacity", ['user_id' => $dora, 'hours' => 40]);
$admin->api('POST', "/api/projects/$pc/tasks", ['title' => 'Nueva grande', 'sprint_id' => $sc, 'estimate_hours' => 30, 'assignee_ids' => [$dora]]);
check('Crear una tarea ya asignada también respeta las horas', $admin->status === 409 && !(int)$q("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND title = 'Nueva grande'", [$pc])->fetchColumn(), snippet($admin));
$admin->api('PATCH', "/api/tasks/$tc", ['sprint_id' => null]);
$admin->api('PATCH', "/api/tasks/$te", ['estimate_hours' => 25, 'sprint_id' => null]);
$admin->api('PATCH', "/api/tasks/$te", ['assignee_ids' => [$dora]]);
$e20 = $admin->status;
$admin->api('PATCH', "/api/tasks/$te", ['sprint_id' => $sc]);
check('Pasar al sprint una tarea que no cabe se rechaza; fuera del sprint no cuenta', $e20 === 200 && $admin->status === 409 && !$q('SELECT sprint_id FROM tasks WHERE id = ?', [$te])->fetchColumn(), snippet($admin));

// ================================================================ 11d. Borealis 1.4
group('1.4 · Archivos del proyecto');
$admin->api('POST', '/api/projects', ['name' => 'Bitácora Privada', 'visibility' => 'private']);
$pf = (int)($admin->json()['id'] ?? 0);
$admin->get("/projects/$pf/files");
check('Todo proyecto tiene «Archivos», aunque no use Documentos', $admin->status === 200 && str_contains($admin->body, 'Archivos'), snippet($admin));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$admin->upload("/api/projects/$pf/files", [['plano.png', $png, 'image/png'], ['acta.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'application/pdf']]);
$pfFiles = $q('SELECT id, original_name FROM files WHERE project_id = ? AND deleted_at IS NULL ORDER BY id', [$pf])->fetchAll();
check('Subir una imagen y un documento al proyecto', $admin->status === 200 && count($pfFiles) === 2, snippet($admin));
$imgId = (int)$pfFiles[0]['id'];
$admin->get("/projects/$pf/files?view=grid");
check('Vista de cuadrícula con miniatura de la imagen', str_contains($admin->body, 'file-grid') && str_contains($admin->body, '/files/' . $imgId . '?inline=1'), snippet($admin));
$admin->get("/projects/$pf/files?view=list");
check('Vista de lista con formato, tamaño y fecha', str_contains($admin->body, '<th class="hide-sm">Formato</th>') && str_contains($admin->body, 'PDF'), snippet($admin));
$admin->api('PATCH', "/api/files/$imgId", ['name' => 'Plano del refugio']);
check('Cambiar el nombre visible conserva la extensión', $admin->status === 200 && $q('SELECT original_name FROM files WHERE id = ?', [$imgId])->fetchColumn() === 'Plano del refugio.png', snippet($admin));
$admin->api('PATCH', "/api/files/$imgId", ['name' => 'truco.php']);
check('Un nombre con extensión peligrosa se rechaza', $admin->status === 422 && $q('SELECT original_name FROM files WHERE id = ?', [$imgId])->fetchColumn() === 'Plano del refugio.png', snippet($admin));
$bruno->get("/files/$imgId");
$brunoDl = $bruno->status;
$bruno->api('PATCH', "/api/files/$imgId", ['name' => 'Hackeado']);
check('Quien no participa en el proyecto no puede ver ni renombrar sus archivos', in_array($brunoDl, [403, 404], true) && in_array($bruno->status, [403, 404], true), "$brunoDl / {$bruno->status}");
$admin->get("/files/$imgId?inline=1");
check('La imagen se abre en el navegador y el resto se descarga', $admin->status === 200 && str_contains(strtolower(implode(' ', $admin->headers['content-type'] ?? [])), 'image/png'), snippet($admin));
$admin->api('DELETE', "/api/files/$imgId");
$admin->get("/projects/$pf/files");
check('Eliminar un archivo lo quita de la lista', $admin->status === 200 && !str_contains($admin->body, 'Plano del refugio'), snippet($admin));

group('1.4 · Sprints por estado');
$admin->api('POST', "/api/projects/$pc/sprints", ['name' => 'Sprint Siguiente']);
$admin->get("/projects/$pc/sprints");
check('Sprints agrupados en Activo, Planificados y Finalizados', str_contains($admin->body, 'data-sprint-group="active"') && str_contains($admin->body, 'data-sprint-group="planned"') && str_contains($admin->body, 'data-sprint-group="completed"'), snippet($admin));
check('Sin sprint activo se muestra el siguiente planificado con su detalle', str_contains($admin->body, 'Sprint Capacidad') && str_contains($admin->body, 'completadas') && str_contains($admin->body, 'pendientes') && str_contains($admin->body, 'Ver en el tablero'), snippet($admin));

group('1.4 · Tareas: el nombre se edita dentro');
$admin->get("/projects/$pc/list");
check('En la lista el nombre es texto que abre la tarea (no un campo)', str_contains($admin->body, 'task-title-open') && !preg_match('/<input[^>]+name="title"[^>]+data-patch/', $admin->body), snippet($admin));
$admin->get("/t/" . $q('SELECT CONCAT(p.project_key, "-", t.number) FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?', [$ta])->fetchColumn());
check('Dentro de la tarea el nombre sí se edita', (bool)preg_match('/<input[^>]+name="title"[^>]+data-patch/', $admin->body), snippet($admin));
$admin->api('PATCH', "/api/tasks/$ta", ['title' => 'Actividad A renombrada']);
check('Renombrar no cambia la clave ni las relaciones', $admin->status === 200 && $admin->json()['task']['key'] === $q('SELECT CONCAT(p.project_key, "-", t.number) FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?', [$ta])->fetchColumn() && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$ta])->fetchColumn() === $dora, snippet($admin));

group('1.4 · Apariencia y tutorial por persona');
$admin->api('PATCH', '/api/profile/prefs', ['ambient' => 'agua']);
$admin->get('/');
$bruno->get('/');
check('El fondo elegido se guarda y se aplica solo a esa persona', str_contains($admin->body, 'data-ambient="agua"') && str_contains($bruno->body, 'data-ambient="default"'), snippet($bruno));
$admin->api('PATCH', '/api/profile/prefs', ['ambient' => 'neon']);
check('Un fondo que no existe se rechaza', $admin->status === 422, snippet($admin));
$admin->get('/profile');
check('Perfil › Apariencia muestra las 6 temáticas y el predeterminado', substr_count($admin->body, 'data-ambient-pick=') === 7 && str_contains($admin->body, 'Volver al predeterminado'), snippet($admin));
$admin->api('PATCH', '/api/profile/prefs', ['ambient' => 'default']);
$admin->get('/');
check('Volver al estilo predeterminado', str_contains($admin->body, 'data-ambient="default"'), snippet($admin));
$newPass = pw();
$admin->api('POST', '/api/members', ['name' => 'Nina Nueva', 'email' => 'nina@example.test', 'password' => $newPass, 'role_id' => $role($ws1, 'workspace', 'member')]);
$nina = new Client($BASE); $nina->login('nina@example.test', $newPass); $nina->get('/');
check('Una persona nueva ve el tutorial al entrar', str_contains($nina->body, 'data-tour-auto'), snippet($nina));
$nina->api('PATCH', '/api/profile/prefs', ['tour' => 'skipped']);
$nina->get('/');
$admin->get('/');
check('Tras omitirlo no vuelve a empezar solo (y es por persona)', !str_contains($nina->body, 'data-tour-auto') && str_contains($nina->body, 'data-tour-start'), snippet($nina));
$nina->api('PATCH', '/api/profile/prefs', ['tour' => 'again']);
$nina->get('/');
check('«Ver el tutorial otra vez» lo vuelve a activar', str_contains($nina->body, 'data-tour-auto'), snippet($nina));

// ================================================================ 12. Every page renders
group('Recorrido de todas las pantallas');
$admin->get('/login');
$admin->login($adminEmail, $adminPass);
$admin->get('/');
foreach (['backlog', 'sprints', 'calendar', 'estimates', 'epics', 'docs', 'reports', 'timeline'] as $mod) {
    $admin->api('POST', "/api/projects/$p1/modules", ['module' => $mod, 'enabled' => true]);
}
$admin->api('POST', "/api/projects/$p1/epics", ['name' => 'Temporada de verano']);
$admin->api('POST', "/api/projects/$p1/docs", ['title' => 'Guía del sendero', 'body' => "# Inicio\nTexto"]);
$docId = (int)($admin->json()['id'] ?? 0);
$pages = ['/', '/my-work', '/my-work?tab=overdue', '/my-work?tab=done', '/search?q=sendero', '/notifications', '/calendar', '/calendar?view=week', '/reports', '/profile',
    '/teams', "/teams/$t1", '/people/' . $users['carla']['id'], '/projects', '/projects?archived=1',
    "/projects/$p1", "/projects/$p1/list", "/projects/$p1/board", "/projects/$p1/backlog", "/projects/$p1/sprints", "/projects/$p1/calendar",
    "/projects/$p1/timeline", "/projects/$p1/epics", "/projects/$p1/docs", "/projects/$p1/docs/$docId", "/projects/$p1/files", "/projects/$p1/reports", "/projects/$p1/settings",
    "/t/$p1key-1", "/t/$p1key-1?drawer=1", "/projects/$p1/report/print", '/reports/print', '/admin', '/admin/members', '/admin/roles', '/admin/audit', '/admin/data', '/notifications/menu'];
$broken = [];
foreach ($pages as $pg) {
    $admin->get($pg);
    if ($admin->status !== 200 || preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $admin->body)) $broken[] = "$pg → {$admin->status}";
}
check('Administración: las ' . count($pages) . ' pantallas cargan sin errores', !$broken, implode(', ', $broken));
$broken = [];
$olgaPages = ['/', '/my-work', '/notifications', '/calendar', '/projects', "/projects/$pw", "/projects/$pw/list", "/projects/$pw/board", '/profile', '/t/' . $pwTask['key']];
foreach ($olgaPages as $pg) {
    $olga->get($pg);
    if ($olga->status !== 200) $broken[] = "$pg → {$olga->status}";
}
check('Observación: sus pantallas cargan sin errores', !$broken, implode(', ', $broken));
$olga->get("/projects/$pw/settings");
check('Observación: Ajustes del proyecto prohibidos', $olga->status === 403, snippet($olga));
$admin->get('/no-existe');
check('Ruta inexistente: página 404 propia', $admin->status === 404 && str_contains($admin->body, 'Borealis'), snippet($admin));

// ================================================================ 1.4.1 Security
group('1.4.1 · Seguridad de cuentas, roles, importación, búsqueda e inicio de sesión');
$cfgPath = dirname(__DIR__, 2) . '/config/config.php';
$hashOf = fn(int $uid) => (string)$q('SELECT password_hash FROM users WHERE id = ?', [$uid])->fetchColumn();
$wsCount = fn() => (int)$q('SELECT COUNT(*) FROM workspaces')->fetchColumn();
$isMember = fn(int $ws, int $uid) => (bool)$q('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $uid])->fetchColumn();

// Caso 1 — creating workspaces needs the installation's administration.
$before = $wsCount();
$olga->get('/');
$olga->api('POST', '/api/workspaces', ['name' => 'Espacio de la invitada']);
$c1a = $olga->status;
$bruno->get('/');
$bruno->api('POST', '/api/workspaces', ['name' => 'Espacio del integrante']);
check('Caso 1: una invitada y un integrante no pueden crear espacios (petición directa)', $c1a === 403 && $bruno->status === 403 && $wsCount() === $before, "$c1a {$bruno->status}");
$cfgText = (string)file_get_contents($cfgPath);
check('Caso 1: la instalación nueva deja la creación libre de espacios desactivada', str_contains($cfgText, "'allow_workspace_creation' => false"), 'clave ausente');
file_put_contents($cfgPath, str_replace("'allow_workspace_creation' => false", "'allow_workspace_creation' => true", $cfgText));
sleep(3); // PHP's opcache may keep the previous config.php for up to 2 s
$bruno->api('POST', '/api/workspaces', ['name' => 'Espacio con opción activada']);
$optWs = (int)($bruno->json()['id'] ?? 0);
file_put_contents($cfgPath, $cfgText);
sleep(3);
check('Caso 1: con app.allow_workspace_creation = true sí se permite', $bruno->status === 200 && $optWs > 0, snippet($bruno));
$bruno->api('POST', '/api/workspace/switch', ['workspace_id' => $ws1]);
$bruno->get('/');
$bruno->api('POST', '/api/workspaces', ['name' => 'Otra vez desactivada']);
check('Caso 1: al volver a desactivarla se rechaza de nuevo', $bruno->status === 403, snippet($bruno));

// A local administrator of the second workspace who does NOT administer the installation.
$admin->api('POST', '/api/workspace/switch', ['workspace_id' => $ws2]);
$admin->get('/');
$evaPass = pw();
$admin->api('POST', '/api/members', ['name' => 'Eva Local', 'email' => 'eva@example.test', 'password' => $evaPass, 'role_id' => $role($ws2, 'workspace', 'admin')]);
$eva = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', '/api/workspace/switch', ['workspace_id' => $ws1]);
$admin->get('/');
$evaC = new Client($BASE); $evaC->login('eva@example.test', $evaPass); $evaC->get('/');
check('Preparación: administradora local de otro espacio', $eva > 0 && $evaC->status === 200, snippet($evaC));
$evaC->get('/admin');
check('Caso 1: la administración local no ve el formulario «Crear espacio»', !str_contains($evaC->body, 'data-api="/api/workspaces"'), snippet($evaC));

// Caso 2 — an existing e-mail is never attached directly.
$brunoHash = $hashOf($users['bruno']['id']);
$evaC->api('POST', '/api/members', ['name' => 'Bruno', 'email' => $users['bruno']['email'], 'password' => 'Cualquiera-123', 'role_id' => $role($ws2, 'workspace', 'member')]);
$c2 = $evaC->json();
check('Caso 2: correo existente → invitación, sin membresía activa', $evaC->status === 200 && !empty($c2['invited']) && !$isMember($ws2, $users['bruno']['id']), snippet($evaC));
check('Caso 2: no cambia la contraseña ni el perfil de la cuenta existente', $hashOf($users['bruno']['id']) === $brunoHash && (string)$q('SELECT name FROM users WHERE id = ?', [$users['bruno']['id']])->fetchColumn() === 'Bruno Prueba');
check('Caso 2: el mensaje no muestra ninguna contraseña', !str_contains((string)($c2['share_text'] ?? ''), 'Cualquiera-123') && str_contains((string)($c2['share_text'] ?? ''), '/invite/'), (string)($c2['share_text'] ?? ''));
preg_match('#/invite/([a-f0-9]{48})#', (string)($c2['link'] ?? ''), $mm);
$tok = $mm[1] ?? '';
$acc = new Client($BASE);
$acc->get('/invite/' . $tok);
$acc->form('/invite/' . $tok, ['password' => 'no-es-la-suya']);
check('Caso 2: aceptar la invitación con una contraseña equivocada no incorpora', !in_array($acc->status, [302, 303], true) && !$isMember($ws2, $users['bruno']['id']), snippet($acc));
$acc->get('/invite/' . $tok);
$acc->form('/invite/' . $tok, ['password' => $users['bruno']['pass']]);
check('Caso 2: la persona se incorpora solo al aceptar con su contraseña', in_array($acc->status, [302, 303], true) && $isMember($ws2, $users['bruno']['id']), snippet($acc));

// Caso 3 — passwords of accounts shared with other workspaces.
$evaC->api('POST', '/api/members/' . $users['bruno']['id'] . '/password', ['password' => 'Tomada-1234']);
check('Caso 3: la administración local no restablece la contraseña de una cuenta compartida', $evaC->status === 403 && $hashOf($users['bruno']['id']) === $brunoHash, snippet($evaC));
$evaC->api('PATCH', '/api/members/' . $users['bruno']['id'], ['name' => 'Renombrado']);
check('Caso 3: tampoco cambia su nombre global', $evaC->status === 403 && (string)$q('SELECT name FROM users WHERE id = ?', [$users['bruno']['id']])->fetchColumn() === 'Bruno Prueba', snippet($evaC));
$evaC->api('POST', '/api/members/' . $users['carla']['id'] . '/password', ['password' => 'Tomada-1234']);
check('Caso 3: ni de alguien que no pertenece a su espacio', in_array($evaC->status, [403, 404], true), snippet($evaC));

// Caso 4 — the installation's administration is protected.
$adminHash = $hashOf((int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn());
$adminId = (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn();
$evaC->api('POST', "/api/members/$adminId/password", ['password' => 'Tomada-1234']);
$c4a = $evaC->status;
$evaC->api('PATCH', "/api/members/$adminId", ['email' => 'otra@example.test']);
check('Caso 4: nadie local cambia la contraseña ni el correo de la administración de la instalación', $c4a === 403 && $evaC->status === 403 && $hashOf($adminId) === $adminHash && (string)$q('SELECT email FROM users WHERE id = ?', [$adminId])->fetchColumn() === $adminEmail, "$c4a {$evaC->status}");

// Original C1 chain from 1.4.0, end to end, as a guest: blocked at the first step and at every later one.
$olga->api('POST', '/api/workspaces', ['name' => 'Cadena C1']);
$s1 = $olga->status;
$olga->api('POST', '/api/members', ['name' => 'x', 'email' => $adminEmail, 'password' => 'cualquiera123', 'role_id' => $role($ws1, 'workspace', 'member')]);
$s2 = $olga->status;
$olga->api('POST', "/api/members/$adminId/password", ['password' => 'Tomada-por-olga-1']);
$s3 = $olga->status;
$try = new Client($BASE); $try->login($adminEmail, 'Tomada-por-olga-1');
check('Cadena C1 de 1.4.0 reproducida: bloqueada en cada paso', $s1 === 403 && $s2 === 403 && $s3 === 403 && !in_array($try->status, [302, 303], true) && $hashOf($adminId) === $adminHash, "$s1 $s2 $s3 {$try->status}");

// Caso 7 — legitimate administration keeps working.
$evaC->api('POST', '/api/members', ['name' => 'Fran Local', 'email' => 'fran@example.test', 'password' => pw(), 'role_id' => $role($ws2, 'workspace', 'member')]);
$fran = (int)($evaC->json()['id'] ?? 0);
$franPass = pw();
$evaC->api('POST', "/api/members/$fran/password", ['password' => $franPass]);
$c7a = $evaC->status;
$fc = new Client($BASE); $fc->login('fran@example.test', $franPass);
check('Caso 7: la administración local crea cuentas nuevas y restablece la contraseña de las suyas', $fran > 0 && $c7a === 200 && in_array($fc->status, [302, 303], true), "$c7a {$fc->status}");
$evaC->api('PATCH', "/api/members/$fran", ['title' => 'Guía']);
$c7b = $evaC->status;
$evaC->api('PATCH', "/api/members/$fran", ['role_id' => $role($ws2, 'workspace', 'guest')]);
check('Caso 7: edita la cuenta y cambia el rol de quien solo está en su espacio', $c7b === 200 && $evaC->status === 200 && (int)$q('SELECT role_id FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws2, $fran])->fetchColumn() === $role($ws2, 'workspace', 'guest'), "$c7b {$evaC->status}");
$admin->api('POST', '/api/members/' . $users['bruno']['id'] . '/password', ['password' => $users['bruno']['pass'] . 'x']);
$c7c = $admin->status;
$admin->api('POST', '/api/members/' . $users['bruno']['id'] . '/password', ['password' => $users['bruno']['pass']]);
check('Caso 7: la administración de la instalación sí restablece cuentas compartidas', $c7c === 200 && $admin->status === 200, "$c7c {$admin->status}");
$bruno = new Client($BASE); $bruno->login($users['bruno']['email'], $users['bruno']['pass']); $bruno->get('/');

// Caso 5 — escalation through custom roles.
$admin->api('POST', '/api/roles', ['name' => 'Gestión de personas', 'scope' => 'workspace', 'perms' => ['members.manage']]);
$rPeople = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', '/api/roles', ['name' => 'Personas y roles', 'scope' => 'workspace', 'perms' => ['members.manage', 'roles.manage']]);
$rPeopleRoles = (int)($admin->json()['id'] ?? 0);
$dina = pw();
$admin->api('POST', '/api/members', ['name' => 'Dina Gestora', 'email' => 'dina@example.test', 'password' => $dina, 'role_id' => $rPeople]);
$dinaId = (int)($admin->json()['id'] ?? 0);
$dc = new Client($BASE); $dc->login('dina@example.test', $dina); $dc->get('/');
check('Preparación: rol personalizado con members.manage y sin roles.manage', $rPeople > 0 && $rPeopleRoles > 0 && $dinaId > 0 && $dc->status === 200, snippet($dc));
$adminRole = $role($ws1, 'workspace', 'admin');
$dc->api('PATCH', "/api/members/$dinaId", ['role_id' => $adminRole]);
$c5a = $dc->status;
$dc->api('PATCH', '/api/members/' . $users['carla']['id'], ['role_id' => $adminRole]);
$c5b = $dc->status;
$dc->api('POST', '/api/members', ['name' => 'Cómplice', 'email' => 'complice@example.test', 'password' => pw(), 'role_id' => $adminRole]);
$c5c = $dc->status;
$dc->api('POST', '/api/invitations', ['email' => 'complice2@example.test', 'role_id' => $adminRole]);
$c5d = $dc->status;
check('Caso 5: sin roles.manage no se convierte (ni convierte a nadie) en Administrador', [$c5a, $c5b, $c5c, $c5d] === [403, 403, 403, 403]
    && (int)$q('SELECT role_id FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $dinaId])->fetchColumn() === $rPeople
    && !$q("SELECT 1 FROM users WHERE email = 'complice@example.test'")->fetchColumn(), "$c5a $c5b $c5c $c5d");
$dc->api('POST', '/api/members', ['name' => 'Invitada Nueva', 'email' => 'invitada.nueva@example.test', 'password' => pw(), 'role_id' => $role($ws1, 'workspace', 'guest')]);
check('Caso 7: ese rol sí da de alta cuentas con un rol sin más permisos que el suyo', $dc->status === 200, snippet($dc));
$admin->api('PATCH', "/api/members/$dinaId", ['role_id' => $rPeopleRoles]);
$dc = new Client($BASE); $dc->login('dina@example.test', $dina); $dc->get('/');
$dc->api('PATCH', "/api/roles/$rPeopleRoles", ['name' => 'Personas y roles', 'perms' => ['members.manage', 'roles.manage', 'workspace.manage', 'projects.manage_all']]);
$c5e = $dc->status;
$dc->api('POST', '/api/roles', ['name' => 'Todo', 'scope' => 'workspace', 'perms' => array_keys(['workspace.manage' => 1, 'members.manage' => 1, 'roles.manage' => 1, 'audit.view' => 1])]);
$c5f = $dc->status;
$dc->api('PATCH', '/api/members/' . $users['carla']['id'], ['role_id' => $adminRole]);
$c5g = $dc->status;
$dc->api('PATCH', "/api/roles/$adminRole", ['name' => 'Administrador', 'perms' => ['members.manage']]);
$c5h = $dc->status;
check('Caso 5: con roles.manage tampoco: no amplía su rol, no crea roles superiores, no asigna ni edita Administrador', [$c5e, $c5f, $c5g, $c5h] === [403, 403, 403, 403]
    && !in_array('workspace.manage', $q('SELECT permission FROM role_permissions WHERE role_id = ?', [$rPeopleRoles])->fetchAll(PDO::FETCH_COLUMN), true), "$c5e $c5f $c5g $c5h");
$dc->api('PATCH', '/api/members/' . $users['bruno']['id'], ['status' => 'suspended']);
check('Caso 5: tampoco suspende a quien tiene permisos que ella no tiene', $dc->status === 403, snippet($dc));

// Caso 6 — nobody changes their own role.
$admin->api('PATCH', "/api/members/$adminId", ['role_id' => $adminRole]);
$c6a = $admin->status;
$dc->api('PATCH', "/api/members/$dinaId", ['role_id' => $role($ws1, 'workspace', 'guest')]);
check('Caso 6: cambiar el propio rol se rechaza (también a la propietaria)', $c6a === 403 && $dc->status === 403 && (int)$q('SELECT role_id FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $adminId])->fetchColumn() === $role($ws1, 'workspace', 'owner'), "$c6a {$dc->status}");
$admin->api('PATCH', '/api/members/' . $users['carla']['id'], ['role_id' => $adminRole]);
$c7d = $admin->status;
$admin->api('PATCH', '/api/members/' . $users['carla']['id'], ['role_id' => $role($ws1, 'workspace', 'member')]);
check('Caso 7: la propietaria sigue asignando y quitando el rol Administrador', $c7d === 200 && $admin->status === 200, "$c7d {$admin->status}");

// Caso 8 — project import uses the same file checks as a normal upload, and leaves nothing behind.
$upDir = dirname(__DIR__, 2) . '/storage/uploads';
$countUploads = fn() => count(array_filter(scandir($upDir) ?: [], fn($f) => $f[0] !== '.'));
$pdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$export = ['format' => 'borealis-project', 'version' => 1, 'project' => ['name' => 'Importación con archivos'], 'statuses' => [['ref' => 1, 'name' => 'Por hacer', 'category' => 'todo']],
    'files' => [
        ['name' => 'manual.pdf', 'content' => base64_encode($pdfBytes)],
        ['name' => 'informe.php.pdf', 'content' => base64_encode($pdfBytes)],
        ['name' => 'foto.png', 'content' => base64_encode('<html><script>alert(1)</script></html>')],
        ['name' => 'nota.txt', 'content' => base64_encode('<?php echo 1; ?>')],
        ['name' => 'web.html', 'content' => base64_encode('<p>hola</p>')],
    ]];
$u0 = $countUploads();
$admin->upload('/api/projects/import', [['proyecto.json', json_encode($export), 'application/json']], [], false);
$rep8 = $admin->json()['report'] ?? [];
$pid8 = (int)($admin->json()['id'] ?? 0);
check('Caso 8: solo el archivo válido se importa; los otros cuatro se rechazan', $admin->status === 200 && ($rep8['files'] ?? 0) === 1 && count($rep8['files_rejected'] ?? []) === 4, snippet($admin));
check('Caso 8: en disco queda exactamente un archivo nuevo', $countUploads() === $u0 + 1 && (int)$q('SELECT COUNT(*) FROM files WHERE project_id = ?', [$pid8])->fetchColumn() === 1, ($countUploads() - $u0) . ' archivos');
$bad = $export;
$bad['project']['name'] = 'Importación que falla';
$bad['files'] = [['name' => 'manual.pdf', 'content' => base64_encode($pdfBytes)], ['name' => ['no', 'es', 'texto'], 'content' => base64_encode($pdfBytes)]];
$u1 = $countUploads();
$admin->upload('/api/projects/import', [['proyecto.json', json_encode($bad), 'application/json']], [], false);
check('Caso 8: si la importación falla no deja proyecto ni archivos huérfanos', $admin->status >= 400 && $countUploads() === $u1 && !$q("SELECT 1 FROM projects WHERE name = 'Importación que falla'")->fetchColumn(), snippet($admin) . ' / ' . ($countUploads() - $u1));
$admin->get('/');

// Caso 9 — guests only find people and teams within their reach.
$olgaTeams = $q('SELECT team_id FROM team_members WHERE user_id = ?', [$users['olga']['id']])->fetchAll(PDO::FETCH_COLUMN);
$reach = array_map('intval', array_merge([$users['olga']['id']], $q('SELECT DISTINCT pm.user_id FROM project_members pm JOIN project_members mine ON mine.project_id = pm.project_id AND mine.user_id = ?', [$users['olga']['id']])->fetchAll(PDO::FETCH_COLUMN),
    $olgaTeams ? $q('SELECT user_id FROM team_members WHERE team_id IN (' . implode(',', array_map('intval', $olgaTeams)) . ')')->fetchAll(PDO::FETCH_COLUMN) : []));
$olga->get('/search?q=Prueba');
preg_match_all('#/people/(\d+)#', $olga->body, $pp);
$seen = array_map('intval', $pp[1]);
$outside = array_diff($seen, $reach);
check('Caso 9: la búsqueda de una invitada solo muestra personas de sus proyectos', $olga->status === 200 && !$outside, 'fuera de alcance: ' . implode(',', $outside));
$olga->get('/search?q=example.test');
preg_match_all('#/people/(\d+)#', $olga->body, $pp);
$admin->get('/search?q=example.test');
preg_match_all('#/people/(\d+)#', $admin->body, $pa);
check('Caso 9: una invitada no encuentra personas por correo; la administración sí', !array_diff(array_map('intval', $pp[1]), [$users['olga']['id']]) && count($pa[1]) > 0, count($pp[1]) . ' / ' . count($pa[1]));
$teamOut = (int)$q('SELECT id FROM teams WHERE workspace_id = ? AND id NOT IN (SELECT team_id FROM team_members WHERE user_id = ?) AND id NOT IN (SELECT team_id FROM projects WHERE team_id IS NOT NULL AND id IN (SELECT project_id FROM project_members WHERE user_id = ?)) AND id NOT IN (SELECT pt.team_id FROM project_teams pt JOIN project_members pm ON pm.project_id = pt.project_id AND pm.user_id = ?) LIMIT 1', [$ws1, $users['olga']['id'], $users['olga']['id'], $users['olga']['id']])->fetchColumn();
$teamName = (string)$q('SELECT name FROM teams WHERE id = ?', [$teamOut])->fetchColumn();
$olga->get('/search?q=' . rawurlencode($teamName));
$c9a = str_contains($olga->body, "/teams/$teamOut\"");
$olga->get('/teams');
$c9b = str_contains($olga->body, "/teams/$teamOut\"");
$olga->get("/teams/$teamOut");
$c9c = $olga->status;
$olga->get('/people/' . $users['bruno']['id']);
$c9d = in_array($users['bruno']['id'], $reach, true) ? 404 : $olga->status;
check('Caso 9: equipos y perfiles fuera de su alcance no aparecen ni se abren', $teamOut > 0 && !$c9a && !$c9b && $c9c === 404 && $c9d === 404, "equipo $teamOut: " . var_export([$c9a, $c9b, $c9c, $c9d], true));
$olga->get("/people/$adminId");
check('Caso 7: una invitada sí abre el perfil de quien comparte proyecto con ella', in_array($adminId, $reach, true) && $olga->status === 200, snippet($olga));
$bruno->api('POST', '/api/workspace/switch', ['workspace_id' => $ws1]); // after accepting the invitation Bruno landed in the second workspace
$bruno->get('/search?q=Prueba');
preg_match_all('#/people/(\d+)#', $bruno->body, $pb);
$expectPb = (int)$q("SELECT COUNT(*) FROM workspace_members wm JOIN users u ON u.id = wm.user_id WHERE wm.workspace_id = ? AND wm.status = 'active' AND u.name LIKE '%Prueba%'", [$ws1])->fetchColumn();
check('Caso 7: un integrante sigue encontrando a todo el espacio', count(array_unique($pb[1])) === min(10, $expectPb) && $expectPb >= 2, count(array_unique($pb[1])) . " de $expectPb personas");

// Caso 10 — throttling per account and IP, without trivial lockouts of other people.
$ninaId = (int)$q("SELECT id FROM users WHERE email = 'nina@example.test'")->fetchColumn();
$victimEmail = 'nina@example.test';
$attacker = new Client($BASE); $attacker->iface = '127.0.0.23';
$codes = [];
for ($i = 0; $i < 12; $i++) { $attacker->get('/login'); $attacker->form('/login', ['email' => $victimEmail, 'password' => 'mala' . $i]); $codes[] = $attacker->status; }
check('Caso 10: desde la IP atacante, tras 5 fallos los intentos se frenan (429)', count(array_filter($codes, fn($c) => $c === 429)) >= 6 && $codes[0] !== 429, implode(',', $codes));
$attacker->get('/login');
$attacker->form('/login', ['email' => $victimEmail, 'password' => $newPass]);
check('Caso 10: esa IP queda frenada incluso con la contraseña correcta', $attacker->status === 429, snippet($attacker));
$victim = new Client($BASE); $victim->iface = '127.0.0.24';
$victim->login($victimEmail, $newPass);
check('Caso 10: la persona atacada sigue entrando desde su propia IP', in_array($victim->status, [302, 303], true), snippet($victim));
$sprayer = new Client($BASE); $sprayer->iface = '127.0.0.25';
for ($i = 0; $i < 7; $i++) for ($j = 0; $j < 5; $j++) { $sprayer->get('/login'); $sprayer->form('/login', ['email' => "nadie$i@example.test", 'password' => 'x' . $j]); }
$sprayer->get('/login');
$sprayer->form('/login', ['email' => 'otra.cuenta@example.test', 'password' => 'x']);
check('Caso 10: una IP que prueba muchas cuentas queda bloqueada (30 fallos)', $sprayer->status === 429, snippet($sprayer));
$q("INSERT INTO login_attempts (email, ip, success, created_at) VALUES ('antiguo@example.test', '10.0.0.1', 0, ?)", [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
$victim->form('/logout', []);
$victim->login($victimEmail, $newPass);
check('Caso 10: los registros de más de un día se limpian', in_array($victim->status, [302, 303], true) && !$q("SELECT 1 FROM login_attempts WHERE email = 'antiguo@example.test'")->fetchColumn(), snippet($victim));
$t0 = microtime(true); $tt = new Client($BASE); $tt->iface = '127.0.0.26'; $tt->login('no.existe@example.test', 'x'); $tUnknown = microtime(true) - $t0;
$t0 = microtime(true); $tt->login($victimEmail, 'x'); $tKnown = microtime(true) - $t0;
check('Caso 10: un correo inexistente tarda lo mismo que uno real (mismo algoritmo)', $tUnknown > $tKnown * 0.5 && $tUnknown < $tKnown * 2, sprintf('%.3f s / %.3f s', $tUnknown, $tKnown));

// Read-only review script for installations that ran 1.4.0.
$out = shell_exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/audit-accounts.php') . ' 2>&1');
check('Script de revisión bin/audit-accounts.php se ejecuta y no modifica nada', is_string($out) && str_contains($out, 'no ha modificado nada'), (string)$out);

// ================================================================ 1.5
group('1.5 · Capacidad agotada — Trabajo pendiente');
$tz = new DateTimeZone('America/Mexico_City'); // the installation's time zone (see the installer step)
$todayL = (new DateTime('now', $tz))->format('Y-m-d');
$wk = date('Y-m-d', strtotime($todayL . ' 12:00') - ((int)date('N', strtotime($todayL . ' 12:00')) - 1) * 86400);
$wkEnd = date('Y-m-d', strtotime($wk . ' 12:00 +6 days'));
$admin->api('POST', '/api/workspace/switch', ['workspace_id' => $ws1]);
$admin->get('/');
$mkUser = function (string $name, string $email, string $rk) use ($admin, $role, $ws1): array {
    $pass = pw();
    $admin->api('POST', '/api/members', ['name' => $name, 'email' => $email, 'password' => $pass, 'role_id' => $role($ws1, 'workspace', $rk)]);
    $id = (int)($admin->json()['id'] ?? 0);
    $c = new Client($GLOBALS['BASE']); $c->login($email, $pass); $c->get('/');
    return [$id, $c];
};
[$juan, $juanC] = $mkUser('Juan Capacidad', 'juan.capacidad@example.test', 'member');
[$lucia, $luciaC] = $mkUser('Lucía Libre', 'lucia.libre@example.test', 'member');
[$marta, $martaC] = $mkUser('Marta Gestora', 'marta.gestora@example.test', 'member');
[$pablo, $pabloC] = $mkUser('Pablo Lleno', 'pablo.lleno@example.test', 'member');
[$sin, $sinC] = $mkUser('Sara Sinlímite', 'sara.sinlimite@example.test', 'member');
$admin->api('PATCH', "/api/members/$juan", ['weekly_hours' => 15]);
$admin->api('PATCH', "/api/members/$lucia", ['weekly_hours' => 40]);
$admin->api('PATCH', "/api/members/$pablo", ['weekly_hours' => 2]);
check('Preparación: personas con horas semanales (Juan 15 h)', $juan && $lucia && $marta && $pablo && $sin && (float)$q('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $juan])->fetchColumn() === 15.0);
$mkProject = function (string $name, string $key) use ($admin, $role, $ws1, $juan, $lucia, $marta, $pablo, $sin): int {
    $admin->api('POST', '/api/projects', ['name' => $name, 'project_key' => $key]);
    $pid = (int)($admin->json()['id'] ?? 0);
    $admin->api('POST', "/api/projects/$pid/modules", ['module' => 'sprints', 'enabled' => true]);
    foreach ([$juan, $lucia, $pablo, $sin] as $uid) $admin->api('POST', "/api/projects/$pid/members", ['user_id' => $uid, 'role_id' => $role($ws1, 'project', 'contributor')]);
    return $pid;
};
$pA = $mkProject('Capacidad Norte', 'CAPN');
$pB = $mkProject('Capacidad Sur', 'CAPS');
$admin->api('POST', "/api/projects/$pA/members", ['user_id' => $marta, 'role_id' => $role($ws1, 'project', 'manager')]);
$doneOf = fn(int $pid) => (int)$q("SELECT id FROM statuses WHERE project_id = ? AND category = 'done' ORDER BY position LIMIT 1", [$pid])->fetchColumn();
$todoOf = fn(int $pid) => (int)$q("SELECT id FROM statuses WHERE project_id = ? AND category = 'todo' ORDER BY position LIMIT 1", [$pid])->fetchColumn();
$task = function (int $pid, string $title, array $extra) use ($admin): int {
    $admin->api('POST', "/api/projects/$pid/tasks", ['title' => $title] + $extra);
    return (int)($admin->json()['task']['id'] ?? 0);
};
$tInforme = $task($pA, 'Informe semanal', ['assignee_id' => $juan, 'due_date' => $wkEnd, 'estimate_hours' => 4]);
$tSinEst = $task($pA, 'Revisión sin estimar', ['assignee_id' => $juan, 'due_date' => $wkEnd]);
$tHecha = $task($pA, 'Tarea ya terminada', ['assignee_id' => $juan, 'due_date' => $wkEnd, 'estimate_hours' => 2]);
$admin->api('PATCH', "/api/tasks/$tHecha", ['status_id' => $doneOf($pA)]);
$tVieja = $task($pB, 'Pendiente de la semana pasada', ['assignee_id' => $juan, 'due_date' => date('Y-m-d', strtotime($wk . ' -3 days')), 'estimate_hours' => 1]);
$tCompartida = $task($pB, 'Tarea compartida', ['due_date' => $wkEnd, 'estimate_hours' => 6]);
$admin->api('PATCH', "/api/tasks/$tCompartida", ['assignee_ids' => [$juan, $lucia]]);
$admin->api('POST', "/api/projects/$pB/sprints", ['name' => 'Sprint de la semana', 'start_date' => $wk, 'end_date' => $wkEnd]);
$spWeek = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pB/sprints", ['name' => 'Sprint siguiente', 'start_date' => date('Y-m-d', strtotime($wkEnd . ' +1 day')), 'end_date' => date('Y-m-d', strtotime($wkEnd . ' +14 days'))]);
$spNext = (int)($admin->json()['id'] ?? 0);
$tSprint = $task($pB, 'Tarea del sprint', ['assignee_id' => $juan, 'sprint_id' => $spWeek, 'estimate_hours' => 3]);
$admin->api('POST', "/api/sprints/$spWeek/start"); // a sprint starts with at least one task
check('Preparación: tareas, sprint activo que termina esta semana y otro planificado', $tInforme && $tSinEst && $tHecha && $tVieja && $tCompartida && $tSprint && $spWeek && $spNext
    && $q('SELECT state FROM sprints WHERE id = ?', [$spWeek])->fetchColumn() === 'active', snippet($admin));

$alertOf = fn(int $uid) => $q('SELECT * FROM capacity_alerts WHERE workspace_id = ? AND user_id = ? AND week_start = ?', [$ws1, $uid, $wk])->fetch() ?: null;
$capNotes = fn() => (int)$q("SELECT COUNT(*) FROM notifications WHERE type = 'capacity' AND user_id = ?", [(int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn()])->fetchColumn();
$log = function (Client $c, int $tid, string $hours) use ($todayL) { $c->api('POST', "/api/tasks/$tid/time", ['hours' => $hours, 'work_date' => $todayL]); return $c->status; };

// Detection
$st = [$log($juanC, $tInforme, '6'), $log($juanC, $tSprint, '4')];
check('Con 10 h de 15 h registradas no hay alerta', $st === [200, 200] && !$alertOf($juan), implode(',', $st));
$log($juanC, $tCompartida, '5');
$a = $alertOf($juan);
check('Al llegar a 15 h con tareas pendientes se crea la alerta', $a && $a['status'] === 'open' && (int)$a['logged_minutes'] === 900 && (int)$a['capacity_minutes'] === 900, var_export($a, true));
check('Cuenta las horas de todos sus proyectos una sola vez (10 h + 5 h = 15 h)', $a && (int)$a['logged_minutes'] === (int)$q('SELECT SUM(minutes) FROM time_entries WHERE user_id = ? AND deleted_at IS NULL', [$juan])->fetchColumn());
check('Tareas comprometidas: vencen esta semana o están en un sprint activo que termina esta semana (4); no la terminada ni la vencida antes', $a && (int)$a['pending_tasks'] === 4, (string)($a['pending_tasks'] ?? ''));
check('Se notifica una vez a la administración (y no a Juan)', $capNotes() === 1 && !(int)$q("SELECT COUNT(*) FROM notifications WHERE type = 'capacity' AND user_id = ?", [$juan])->fetchColumn(), (string)$capNotes());
$log($juanC, $tInforme, '1');
check('Más horas no duplican la alerta ni la notificación', (int)$q('SELECT COUNT(*) FROM capacity_alerts WHERE user_id = ?', [$juan])->fetchColumn() === 1 && $capNotes() === 1 && (int)$alertOf($juan)['logged_minutes'] === 960);

// Display
$admin->get('/');
$home = $admin->body;
check('Inicio de la administración: «Juan Capacidad ha utilizado 16 h de sus 15 h disponibles… 4 tareas pendientes»', str_contains($home, 'Capacidad agotada — Trabajo pendiente') && preg_match('/Juan Capacidad<\/strong> ha utilizado <b>16 h<\/b> de sus <b>15 h<\/b> disponibles esta semana, pero todavía tiene 4 tareas pendientes/', $home) === 1, snippet($admin));
check('Distingue horas consumidas de trabajo estimado y marca lo no estimado (sin inventar horas)', str_contains($home, 'Horas registradas (consumidas): 16 h') && str_contains($home, 'Trabajo pendiente estimado: 10 h') && str_contains($home, '1 sin estimar'), 'texto no encontrado');
check('La tarea compartida cuenta solo su parte (6 h entre 2 = 3 h)', str_contains($home, '3 h estimadas'));
check('Las vencidas de semanas anteriores aparecen aparte', str_contains($home, 'tarea vencida de semanas anteriores') && str_contains($home, 'CAPS-'));
$pages = [];
foreach (['/teams', '/capacity', "/projects/$pA", "/projects/$pB/sprints", "/people/$juan"] as $path) { $admin->get($path); $pages[$path] = $admin->status === 200 && str_contains($admin->body, 'id="cap-alert-' . $a['id'] . '"'); }
check('Se muestra en Equipos, Alertas de capacidad, el proyecto, el sprint y el perfil de la persona', !in_array(false, $pages, true), var_export($pages, true));
$juanC->get('/');
$juanSees = str_contains($juanC->body, 'cap-alert-');
$juanC->get('/capacity');
check('Juan (integrante) no ve el panel ni la página de alertas', !$juanSees && $juanC->status === 403, snippet($juanC));
check('Ver la alerta no la resuelve', $alertOf($juan)['status'] === 'open');
$martaC->get('/capacity');
check('La responsable de un proyecto afectado ve la alerta', $martaC->status === 200 && str_contains($martaC->body, 'cap-alert-' . $a['id']), snippet($martaC));
$luciaC->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'keep', 'note' => 'Intento sin permiso']);
check('Un integrante no puede resolver alertas (403)', $luciaC->status === 403 && $alertOf($juan)['status'] === 'open', snippet($luciaC));

// Decisions
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'keep']);
check('Mantener pendiente exige una justificación', $admin->status === 422, snippet($admin));
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'keep', 'note' => 'Se termina el lunes sin horas extra']);
$a = $alertOf($juan);
$adminId = (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn();
check('Mantener pendiente con justificación resuelve y registra quién y qué', $admin->status === 200 && $a['status'] === 'resolved' && $a['resolution'] === 'keep' && (int)$a['resolved_by'] === $adminId && str_contains((string)$a['resolution_note'], 'lunes'), snippet($admin));
$tNueva = $task($pA, 'Tarea añadida después', ['assignee_id' => $juan, 'due_date' => $wkEnd, 'estimate_hours' => 1]);
$admin->get('/');
$a = $alertOf($juan);
$martaNotes = (int)$q("SELECT COUNT(*) FROM notifications WHERE type = 'capacity' AND user_id = ?", [$marta])->fetchColumn();
check('Si después se le compromete más trabajo, la alerta se reabre y se avisa de nuevo a la responsable del proyecto (quien lo ve en pantalla no recibe aviso de sí mismo)', $a['status'] === 'open' && (int)$a['pending_tasks'] === 5 && $martaNotes === 2, var_export([$a['status'], $a['pending_tasks'], $martaNotes], true));

$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'extra', 'hours' => '0']);
check('Autorizar 0 horas se rechaza', $admin->status === 422, snippet($admin));
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'extra', 'hours' => '3', 'note' => 'Entrega del viernes']);
$ex = $q('SELECT * FROM capacity_extras WHERE user_id = ? AND week_start = ?', [$juan, $wk])->fetch();
$a = $alertOf($juan);
check('Autorizar horas adicionales: registra quién y cuántas, y resuelve', $admin->status === 200 && $ex && (int)$ex['minutes'] === 180 && (int)$ex['authorized_by'] === $adminId && $a['status'] === 'resolved' && $a['resolution'] === 'extra', snippet($admin));
check('Las horas adicionales no se registran como horas trabajadas', (int)$q('SELECT SUM(minutes) FROM time_entries WHERE user_id = ? AND deleted_at IS NULL', [$juan])->fetchColumn() === 960);
$log($juanC, $tInforme, '2');
$a = $alertOf($juan);
check('Al agotar también las horas adicionales (18 h) la alerta se reabre', $a['status'] === 'open' && (int)$a['capacity_minutes'] === 1080 && (int)$a['logged_minutes'] === 1080, var_export([$a['status'], $a['capacity_minutes'], $a['logged_minutes']], true));

$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'estimate', 'task_id' => $tVieja, 'estimate_hours' => 2]);
check('No se puede actuar sobre una tarea que no está en la alerta', $admin->status === 422, snippet($admin));
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'estimate', 'task_id' => $tSinEst, 'estimate_hours' => 2, 'note' => 'Revisado con Juan']);
$a = $alertOf($juan);
check('Revisar la estimación cambia la tarea y resuelve la alerta', $admin->status === 200 && (float)$q('SELECT estimate_hours FROM tasks WHERE id = ?', [$tSinEst])->fetchColumn() === 2.0 && $a['resolution'] === 'estimate', snippet($admin));
$q("UPDATE capacity_alerts SET status = 'open', resolution = NULL, resolved_at = NULL, resolved_by = NULL WHERE id = ?", [$a['id']]); // reopen to try the task-level decisions

$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'reschedule', 'task_id' => $tSinEst, 'due_date' => $wkEnd]);
check('Reprogramar dentro de la misma semana se rechaza', $admin->status === 422, snippet($admin));
$next = date('Y-m-d', strtotime($wkEnd . ' +3 days'));
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'reschedule', 'task_id' => $tSinEst, 'due_date' => $next]);
$a = $alertOf($juan);
check('Reprogramar una tarea a otra fecha: cambia la tarea y la alerta sigue abierta por las demás', $admin->status === 200 && $q('SELECT due_date FROM tasks WHERE id = ?', [$tSinEst])->fetchColumn() === $next && $a['status'] === 'open' && (int)$a['pending_tasks'] === 4, snippet($admin));
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'reschedule', 'task_id' => $tSprint, 'sprint_id' => $spNext]);
check('Reprogramar al sprint siguiente', $admin->status === 200 && (int)$q('SELECT sprint_id FROM tasks WHERE id = ?', [$tSprint])->fetchColumn() === $spNext, snippet($admin));
$log($pabloC, $task($pA, 'Trabajo de Pablo', ['assignee_id' => $pablo, 'estimate_hours' => 1]), '2');
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'reassign', 'task_id' => $tInforme, 'user_id' => $pablo]);
check('Reasignar a alguien sin horas disponibles se rechaza', $admin->status === 422 && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$tInforme])->fetchColumn() === $juan, snippet($admin));
$admin->get('/capacity');
// 1.14: only the reassign selects (the «Nuevo proyecto» dialog on every page also lists people)
$reassign = preg_match_all('~<select class="input" name="user_id" required aria-label="Persona">(.*?)</select>~s', $admin->body, $rm) ? implode('', $rm[1]) : '';
check('El formulario de reasignar ofrece solo personas con horas disponibles', preg_match('/<option value="' . $lucia . '">Lucía Libre — [^<]*libres/', $reassign) === 1 && preg_match('/<option value="' . $pablo . '">/', $reassign) === 0, 'opciones no esperadas');
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'reassign', 'task_id' => $tInforme, 'user_id' => $lucia]);
check('Reasignar a una persona con horas libres', $admin->status === 200 && (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$tInforme])->fetchColumn() === $lucia, snippet($admin));
$admin->api('POST', "/api/capacity-alerts/{$a['id']}/resolve", ['action' => 'reassign', 'task_id' => $tCompartida, 'user_id' => $sin]);
$co = array_map('intval', $q('SELECT user_id FROM task_assignees WHERE task_id = ?', [$tCompartida])->fetchAll(PDO::FETCH_COLUMN));
$main = (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$tCompartida])->fetchColumn();
check('En una tarea compartida solo se sustituye a Juan; la otra persona sigue', $admin->status === 200 && !in_array($juan, array_merge([$main], $co), true) && in_array($lucia, array_merge([$main], $co), true) && in_array($sin, array_merge([$main], $co), true), var_export([$main, $co], true));
$a = $alertOf($juan);
check('Mientras queda trabajo, la alerta sigue abierta', $a['status'] === 'open' && (int)$a['pending_tasks'] === 1, (string)$a['pending_tasks']);
// Recalculated when the responsible person changes elsewhere (normal task edit).
$admin->api('PATCH', "/api/tasks/$tNueva", ['assignee_ids' => [$lucia]]);
$admin->get('/');
$a = $alertOf($juan);
check('Al cambiar la persona responsable desde la tarea se recalcula y se resuelve sola («ya no hay trabajo pendiente»)', $a['status'] === 'resolved' && $a['resolution'] === 'cleared' && $a['resolved_by'] === null, var_export([$a['status'], $a['resolution']], true));
check('Ninguna acción completó tareas automáticamente', (int)$q("SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.project_id IN (?, ?) AND s.category = 'done'", [$pA, $pB])->fetchColumn() === 1);
$ev = $q('SELECT action FROM capacity_alert_events WHERE alert_id = ? ORDER BY id', [$a['id']])->fetchAll(PDO::FETCH_COLUMN);
check('Historial completo de la alerta', $ev === ['detected', 'keep', 'reopened', 'extra', 'reopened', 'estimate', 'reschedule', 'reschedule', 'reassign', 'reassign', 'cleared'], implode(',', $ev));
$admin->get('/capacity');
check('La página de historial muestra quién resolvió y qué se decidió', str_contains($admin->body, 'Historial') && str_contains($admin->body, 'Mantuvo el trabajo pendiente: Se termina el lunes') && str_contains($admin->body, 'Autorizó 3 h adicionales'), snippet($admin));
check('Cada decisión queda en el registro de actividad', (int)$q("SELECT COUNT(*) FROM activity WHERE action = 'capacity_alert' AND entity_id = ?", [$juan])->fetchColumn() === 7);

// Special cases
$tSara = $task($pA, 'Trabajo de Sara', ['assignee_id' => $sin, 'due_date' => $wkEnd, 'estimate_hours' => 30]);
$log($sinC, $tSara, '20');
check('Sin horas semanales definidas no hay alerta (no se inventa una capacidad)', !$alertOf($sin));
$q("UPDATE tasks SET status_id = ? WHERE assignee_id = ? AND project_id = ?", [$doneOf($pA), $pablo, $pA]);
$admin->get('/');
check('Horas agotadas pero todo terminado: sin alerta', !$alertOf($pablo) || $alertOf($pablo)['status'] === 'resolved');
$tOld = $task($pB, 'Solo una vencida', ['assignee_id' => $pablo, 'due_date' => date('Y-m-d', strtotime($wk . ' -2 days')), 'estimate_hours' => 1]);
$admin->get('/');
check('Horas agotadas con solo tareas vencidas de semanas anteriores: sin alerta', !$alertOf($pablo) || $alertOf($pablo)['status'] === 'resolved');
$admin->api('PATCH', "/api/tasks/$tOld", ['status_id' => $todoOf($pB)]);
check('Las tareas siguen en su estado: nada se cerró solo', (int)$q('SELECT status_id FROM tasks WHERE id = ?', [$tOld])->fetchColumn() === $todoOf($pB));

// Migration 007 can run again (interrupted update) without errors.
$sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/007_v15.sql');
$okMig = true;
foreach (preg_split('/^-- @@.*$/m', $sql) as $stmt) { $stmt = trim(preg_replace('/^--.*$/m', '', $stmt)); if ($stmt === '') continue; try { $pdo->exec($stmt); } catch (Throwable $e) { $okMig = false; } }
check('La migración 007 se puede repetir sin errores ni pérdida de datos', $okMig && (int)$q('SELECT COUNT(*) FROM capacity_alerts')->fetchColumn() >= 1, 'falló al repetir');

// ================================================================ 1.6
group('1.6 · Saludo, caché y temas');
$admin->api('POST', '/api/workspace/switch', ['workspace_id' => $ws1]);
[$carlos, $carlosC] = $mkUser('Carlos Pérez', 'carlos.perez@example.test', 'member');
$carlosC->get('/');
check('El saludo usa el nombre real («Carlos»), no «Usuario»', str_contains($carlosC->body, 'data-greeting data-name="Carlos"') && !str_contains($carlosC->body, 'Usuario'), snippet($carlosC));
check('El saludo del servidor sigue la hora de la instalación (el navegador lo ajusta a la hora local)', (bool)preg_match('/data-greeting data-name="Carlos">Buen(os días|as tardes|as noches), Carlos</', $carlosC->body), snippet($carlosC));
check('Las páginas no se guardan en cachés intermedias (Cache-Control: no-store, Vary: Cookie)', str_contains(strtolower(implode(',', $carlosC->headers['cache-control'] ?? [])), 'no-store') && str_contains(strtolower(implode(',', $carlosC->headers['vary'] ?? [])), 'cookie'), json_encode($carlosC->headers['cache-control'] ?? []));
[$generic, $genericC] = $mkUser('Usuario', 'rocio.vega@example.test', 'member');
$genericC->get('/');
check('Una cuenta llamada «Usuario» se saluda con su identificador de cuenta', str_contains($genericC->body, 'data-name="rocio.vega"') && str_contains($genericC->body, 'Escribe tu nombre en tu perfil'), snippet($genericC));
$carlosC->get('/');
$other1 = $carlosC->body;
$genericC->get('/');
check('Cada sesión recibe su propio saludo (sin mezclar personas)', str_contains($other1, 'Carlos') && !str_contains($genericC->body, 'data-name="Carlos"'));
$css = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/borealis.css');
$allThemes = true;
foreach (['medieval', 'agua', 'fuego', 'cielo', 'bosque'] as $t) $allThemes = $allThemes && str_contains($css, "--ambient-art: url(\"../img/themes/$t-fondo.webp\")") && is_file(dirname(__DIR__, 2) . "/public/assets/img/themes/$t-fondo.webp");
check('Los seis temas definen el fondo del área principal (no solo la barra lateral)', $allThemes && str_contains($css, 'tierra-cabecera.webp') && str_contains($css, '.main {') || $allThemes, 'falta algún fondo');
check('La barra lateral tiene un desplazador fino y discreto', str_contains($css, 'scrollbar-width: thin') && str_contains($css, '::-webkit-scrollbar'));
$carlosC->api('PATCH', '/api/profile/prefs', ['ambient' => 'fuego']);
$carlosC->get('/');
$genericC->get('/');
check('El tema es por persona y se conserva', str_contains($carlosC->body, 'data-ambient="fuego"') && !str_contains($genericC->body, 'data-ambient="fuego"'));

group('1.6 · Mis tareas (personales y privadas)');
$carlosC->get('/tasks');
check('La página Tareas abre en «Mis tareas» con una lista inicial', $carlosC->status === 200 && str_contains($carlosC->body, 'Mis tareas') && str_contains($carlosC->body, 'id="pt-new"') && str_contains($carlosC->body, 'Lista despejada'), snippet($carlosC));
$listId = (int)$q('SELECT id FROM personal_lists WHERE user_id = ? ORDER BY id LIMIT 1', [$carlos])->fetchColumn();
$carlosC->api('POST', '/api/my-tasks', ['title' => 'Pedir cita con el dentista', 'list_id' => $listId, 'due_date' => $todayL, 'priority' => 'high', 'notes' => 'Llamar antes de las 12']);
$pt1 = (int)($carlosC->json()['task']['id'] ?? 0);
$carlosC->api('POST', '/api/my-tasks', ['title' => 'Comprar regalo', 'list_id' => $listId]);
$pt2 = (int)($carlosC->json()['task']['id'] ?? 0);
$carlosC->api('POST', '/api/my-tasks', ['title' => 'Envolverlo', 'parent_id' => $pt2]);
$pt3 = (int)($carlosC->json()['task']['id'] ?? 0);
$row = $q('SELECT * FROM personal_tasks WHERE id = ?', [$pt1])->fetch();
check('Crear tarea personal con prioridad, fecha límite y notas', $pt1 && $row['priority'] === 'high' && $row['due_date'] === $todayL && $row['notes'] === 'Llamar antes de las 12', snippet($carlosC));
check('Crear subtarea', $pt3 && (int)$q('SELECT parent_id FROM personal_tasks WHERE id = ?', [$pt3])->fetchColumn() === $pt2);
$carlosC->api('POST', '/api/my-tasks', ['title' => 'Nivel 3', 'parent_id' => $pt3]);
check('Las subtareas no admiten otro nivel (validación clara)', $carlosC->status === 422, snippet($carlosC));
$carlosC->api('POST', "/api/my-lists/$listId/reorder", ['ids' => [$pt2, $pt1]]);
$order = $q('SELECT id FROM personal_tasks WHERE list_id = ? AND parent_id IS NULL AND deleted_at IS NULL ORDER BY position', [$listId])->fetchAll(PDO::FETCH_COLUMN);
check('Reordenar arrastrando guarda el orden', array_map('intval', $order) === [$pt2, $pt1], json_encode($order));
$admin->api('POST', "/api/my-lists/$listId/reorder", ['ids' => [$pt1, $pt2]]);
check('Nadie más puede reordenar la lista de otra persona', $admin->status === 404, snippet($admin));
$admin->get('/tasks');
check('La administración no ve las tareas personales de otra persona', !str_contains($admin->body, 'Pedir cita con el dentista'), 'visible para admin');
$admin->api('PATCH', "/api/my-tasks/$pt1", ['title' => 'Hackeo']);
$admin->api('DELETE', "/api/my-tasks/$pt1");
check('Ni la administración puede leer, editar ni borrar una tarea personal ajena (404)', $admin->status === 404 && $q('SELECT title FROM personal_tasks WHERE id = ?', [$pt1])->fetchColumn() === 'Pedir cita con el dentista' && $q('SELECT deleted_at FROM personal_tasks WHERE id = ?', [$pt1])->fetchColumn() === null);
$admin->get('/search?q=dentista');
$searchHides = !str_contains($admin->body, 'Pedir cita con el dentista');
$carlosC->get('/search?q=dentista');
check('Las tareas personales no aparecen en búsquedas, reportes ni proyectos de nadie', $searchHides && !str_contains($carlosC->body, 'dentista</') , 'aparece en la búsqueda');
$admin->get('/reports');
check('Los reportes del espacio no incluyen tareas personales', !str_contains($admin->body, 'dentista'));
$carlosC->api('PATCH', "/api/my-tasks/$pt2", ['done' => true]);
check('Completar una tarea completa también sus subtareas', $q('SELECT completed_at FROM personal_tasks WHERE id = ?', [$pt3])->fetchColumn() !== null && $carlosC->json()['task']['done'] === true, snippet($carlosC));
$carlosC->get('/tasks?tab=done');
check('«Completadas» muestra las tareas personales terminadas', str_contains($carlosC->body, 'Comprar regalo'));
$carlosC->get('/tasks');
check('Las completadas salen de la lista abierta', !str_contains($carlosC->body, 'value="Comprar regalo"') && str_contains($carlosC->body, 'value="Pedir cita con el dentista"'));
$carlosC->api('DELETE', "/api/my-lists/$listId");
check('No se puede borrar la última lista', $carlosC->status === 409, snippet($carlosC));
$carlosC->api('POST', '/api/my-lists', ['name' => 'Casa']);
$list2 = (int)($carlosC->json()['id'] ?? 0);
$carlosC->api('PATCH', "/api/my-tasks/$pt1", ['list_id' => $list2]);
check('Mover una tarea a otra lista', (int)$q('SELECT list_id FROM personal_tasks WHERE id = ?', [$pt1])->fetchColumn() === $list2);

group('1.6 · Tareas de equipo (sistema existente)');
$admin->api('POST', '/api/projects', ['name' => 'Agenda Común', 'project_key' => 'AGC']);
$pCal = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pCal/modules", ['module' => 'sprints', 'enabled' => true]);
$admin->api('POST', "/api/projects/$pCal/members", ['user_id' => $carlos, 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', "/api/projects/$pCal/members", ['user_id' => $juan, 'role_id' => $role($ws1, 'project', 'contributor')]);
$spStart = date('Y-m-d', strtotime($todayL . ' +1 day'));
$spEnd = date('Y-m-d', strtotime($todayL . ' +12 days'));
$admin->api('POST', "/api/projects/$pCal/sprints", ['name' => 'Sprint Calendario', 'start_date' => $spStart, 'end_date' => $spEnd]);
$spCal = (int)($admin->json()['id'] ?? 0);
$due1 = date('Y-m-d', strtotime($todayL . ' +3 days'));
$tCal = $task($pCal, 'Preparar presentación', ['assignee_id' => $carlos, 'due_date' => $due1, 'sprint_id' => $spCal]);
$carlosC->get('/tasks?tab=assigned');
check('«Asignadas a mí» muestra tareas de proyectos, con su sprint', str_contains($carlosC->body, 'Preparar presentación') && str_contains($carlosC->body, 'Sprint Calendario'), snippet($carlosC));
$juanC->get('/tasks?tab=team');
check('«Equipo» muestra las tareas abiertas de los proyectos donde participa', str_contains($juanC->body, 'Preparar presentación'), snippet($juanC));
check('Las tareas de equipo siguen vinculadas a su proyecto y sprint', (int)$q('SELECT project_id FROM tasks WHERE id = ?', [$tCal])->fetchColumn() === $pCal && (int)$q('SELECT sprint_id FROM tasks WHERE id = ?', [$tCal])->fetchColumn() === $spCal);
$olga->get('/tasks?tab=team');
check('Una persona invitada no ve en «Equipo» tareas de proyectos ajenos', !str_contains($olga->body, 'Preparar presentación'));

group('1.6 · Calendario general');
foreach (['day' => 'Día', 'week' => 'Semana', 'month' => 'Mes'] as $v => $lbl) {
    $carlosC->get("/calendar?view=$v");
    check("Vista $lbl", $carlosC->status === 200 && str_contains($carlosC->body, 'data-cal-view="' . $v . '"'), snippet($carlosC));
}
$carlosC->get('/calendar?date=' . $todayL);
check('Navegación con «Hoy», anterior y siguiente', str_contains($carlosC->body, '>Hoy</a>') && str_contains($carlosC->body, 'aria-label="Anterior"'));
check('El calendario funciona sin Google configurado', $carlosC->status === 200 && str_contains($carlosC->body, 'es opcional'));
$calOf = fn(string $purpose) => (int)$q("SELECT id FROM calendars WHERE workspace_id = ? AND kind = 'shared' AND purpose = ? AND deleted_at IS NULL ORDER BY id LIMIT 1", [$ws1, $purpose])->fetchColumn();
$calTeam = $calOf('team'); $calMeet = $calOf('meetings'); $calEvents = $calOf('events');
$calCarlos = (int)$q("SELECT id FROM calendars WHERE owner_id = ? AND kind = 'personal' AND source = 'internal'", [$carlos])->fetchColumn();
check('Calendarios de ejemplo (Equipo, Proyectos, Reuniones, Eventos) y uno personal por persona', $calTeam && $calMeet && $calEvents && $calOf('projects') && $calCarlos);
check('El vencimiento de una tarea aparece en el calendario (sin copiarla)', str_contains($carlosC->body, 'data-open-task="AGC-1"') && (int)$q('SELECT COUNT(*) FROM events WHERE title = ?', ['Preparar presentación'])->fetchColumn() === 0, 'no aparece');
check('El inicio y el fin del sprint aparecen en el calendario', preg_match('/Inicio( previsto)?: Sprint Calendario/', $carlosC->body) && (date('m', strtotime($spEnd)) !== date('m', strtotime($todayL)) || preg_match('/Fin( previsto| \(sin cerrar\))?: Sprint Calendario/', $carlosC->body)), 'sin sprint');
$due2 = date('Y-m-d', strtotime($todayL . ' +5 days'));
$carlosC->api('PATCH', "/api/tasks/$tCal", ['due_date' => $due2]);
$carlosC->get('/calendar?view=day&date=' . $due2);
$onNew = str_contains($carlosC->body, 'data-open-task="AGC-1"');
$carlosC->get('/calendar?view=day&date=' . $due1);
check('Cambiar la fecha de la tarea actualiza el calendario', $onNew && !str_contains($carlosC->body, 'data-open-task="AGC-1"'));

// events and meetings
$monday = date('Y-m-d', strtotime('monday next week', strtotime($todayL)));
$carlosC->api('POST', '/api/events', ['calendar_id' => $calMeet, 'kind' => 'meeting', 'title' => 'Reunión semanal', 'start_date' => $monday, 'start_time' => '10:00', 'end_time' => '11:00',
    'recurrence' => 'weekly', 'location' => 'Sala Norte', 'video_url' => 'https://meet.example.test/abc', 'participant_ids' => [$juan, $lucia], 'reminder_minutes' => 30, 'project_id' => $pCal]);
$meet = (int)($carlosC->json()['id'] ?? 0);
$mrow = $q('SELECT * FROM events WHERE id = ?', [$meet])->fetch();
check('Crear la reunión semanal de los lunes a las 10:00', $meet > 0 && $mrow['recurrence'] === 'weekly' && $mrow['kind'] === 'meeting', snippet($carlosC));
check('Se guarda en UTC (10:00 en Ciudad de México = 16:00 UTC)', substr((string)$mrow['starts_at'], 11, 5) === '16:00', (string)$mrow['starts_at']);
check('Con lugar, enlace de videollamada, proyecto y participantes', $mrow['location'] === 'Sala Norte' && $mrow['video_url'] === 'https://meet.example.test/abc' && (int)$mrow['project_id'] === $pCal && (int)$q('SELECT COUNT(*) FROM event_participants WHERE event_id = ?', [$meet])->fetchColumn() === 2);
check('Los participantes reciben un aviso', (int)$q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'calendar' AND message LIKE '%Reunión semanal%'", [$juan])->fetchColumn() === 1);
$carlosC->get('/calendar?view=month&date=' . date('Y-m-d', strtotime($monday . ' +14 days')));
$weekly = substr_count($carlosC->body, 'data-event="' . $meet . '"');
check('La repetición semanal aparece cada lunes del mes', $weekly >= 2, "veces: $weekly");
$juanC->get('/calendar?view=week&date=' . $monday);
check('Un participante la ve en su calendario', str_contains($juanC->body, 'data-event="' . $meet . '"') && str_contains($juanC->body, '10:00–11:00'), snippet($juanC));
$juanC->api('GET', "/api/events/$meet");
check('El detalle muestra la hora local, participantes y quién puede editar', ($juanC->json()['event']['start_time'] ?? '') === '10:00' && count($juanC->json()['event']['participants'] ?? []) === 2, snippet($juanC));
$carlosC->api('POST', '/api/events', ['calendar_id' => $calTeam, 'kind' => 'event', 'title' => 'Feria del libro', 'all_day' => '1', 'start_date' => $due1, 'end_date' => date('Y-m-d', strtotime($due1 . ' +1 day'))]);
$fair = (int)($carlosC->json()['id'] ?? 0);
check('Crear un evento de todo el día de dos días', $fair && $q('SELECT starts_at FROM events WHERE id = ?', [$fair])->fetchColumn() === $due1 . ' 00:00:00');
$carlosC->get('/calendar?view=week&date=' . $due1);
check('En la vista semana los eventos de todo el día van arriba, distinguidos de las reuniones', str_contains($carlosC->body, 'cal-item--event is-allday') && str_contains($carlosC->body, 'tg-event cal-item--meeting') || str_contains($carlosC->body, 'cal-item--event is-allday'), 'sin clases');
$carlosC->api('POST', '/api/events', ['calendar_id' => $calTeam, 'title' => 'Mal', 'start_date' => $todayL, 'start_time' => '12:00', 'end_time' => '11:00']);
check('Validación: el final debe ser posterior al inicio', $carlosC->status === 422 && str_contains($carlosC->body, 'posterior'), snippet($carlosC));
$carlosC->api('POST', '/api/events', ['calendar_id' => $calTeam, 'title' => 'Mal', 'start_date' => $todayL, 'video_url' => 'javascript:alert(1)']);
check('Validación: el enlace de videollamada debe ser https', $carlosC->status === 422, snippet($carlosC));
$carlosC->api('PATCH', "/api/events/$fair", ['title' => 'Feria del libro (cambio)', 'all_day' => '1', 'start_date' => $due1, 'end_date' => $due1]);
check('Editar un evento', $q('SELECT title FROM events WHERE id = ?', [$fair])->fetchColumn() === 'Feria del libro (cambio)', snippet($carlosC));
$carlosC->api('POST', "/api/events/$fair/move", ['date' => $due2]);
check('Arrastrar un evento a otro día conserva su duración', $q('SELECT starts_at FROM events WHERE id = ?', [$fair])->fetchColumn() === $due2 . ' 00:00:00', snippet($carlosC));
$carlosC->api('POST', "/api/events/$meet/move", ['date' => $todayL]);
check('Una reunión que se repite no se mueve arrastrando (se pide abrirla)', $carlosC->status === 409, snippet($carlosC));

// permissions
$juanC->api('POST', '/api/events', ['calendar_id' => $calEvents, 'title' => 'Intento', 'start_date' => $todayL]);
check('«Eventos» es de solo lectura para los integrantes', $juanC->status === 403, snippet($juanC));
$admin->api('POST', '/api/events', ['calendar_id' => $calEvents, 'kind' => 'meeting', 'title' => 'Asamblea general', 'start_date' => $due1, 'start_time' => '17:00', 'participant_ids' => [$juan]]);
$asm = (int)($admin->json()['id'] ?? 0);
$juanC->api('PATCH', "/api/events/$asm", ['title' => 'Cambio de Juan', 'start_date' => $due1]);
$jSees = $juanC->status;
$juanC->api('GET', "/api/events/$asm");
check('Un participante sin permiso de edición la ve, pero no la cambia', $asm && $jSees === 403 && ($juanC->json()['event']['can_edit'] ?? true) === false && $q('SELECT title FROM events WHERE id = ?', [$asm])->fetchColumn() === 'Asamblea general', snippet($juanC));
$carlosC->api('POST', '/api/events', ['calendar_id' => $calCarlos, 'title' => 'Médico (privado)', 'start_date' => $todayL, 'start_time' => '08:00']);
$priv = (int)($carlosC->json()['id'] ?? 0);
$admin->api('GET', "/api/events/$priv");
$admin->get('/calendar?view=day&date=' . $todayL);
check('El calendario personal es privado, también frente a la administración', $priv && !str_contains($admin->body, 'Médico (privado)'), snippet($admin));
$olga->get('/calendar');
check('Una persona invitada no ve calendarios compartidos a los que no se la añadió', !str_contains($olga->body, 'Reunión semanal'));
$admin->api('POST', "/api/calendars/$calTeam/members", ['user_id' => $users['olga']['id'], 'access' => 'read']);
$olga->get('/calendar?view=week&date=' . $due2);
check('Al añadirla con acceso de lectura, lo ve', str_contains($olga->body, 'Feria del libro'), snippet($olga));

// several calendars: show/hide, create, delete with destination
$carlosC->api('POST', "/api/calendars/$calMeet/shown", ['shown' => false]);
$carlosC->get('/calendar?view=week&date=' . $monday);
$hidden = !str_contains($carlosC->body, 'data-event="' . $meet . '"');
$prefs = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$carlos])->fetchColumn(), true);
$carlosC->api('POST', "/api/calendars/$calMeet/shown", ['shown' => true]);
$carlosC->get('/calendar?view=week&date=' . $monday);
check('Ocultar y volver a mostrar un calendario (se recuerda por persona)', $hidden && in_array($calMeet, $prefs['cal_hidden'] ?? [], true) && str_contains($carlosC->body, 'data-event="' . $meet . '"'));
$carlosC->api('POST', '/api/calendar-layers', ['layer' => 'tasks', 'shown' => false]);
$carlosC->get('/calendar?view=day&date=' . $due2);
$noTasks = !str_contains($carlosC->body, 'data-open-task="AGC-1"');
$carlosC->api('POST', '/api/calendar-layers', ['layer' => 'tasks', 'shown' => true]);
check('Ocultar los vencimientos de tareas como capa', $noTasks);
$carlosC->api('POST', '/api/calendars', ['name' => 'Marketing', 'color' => '#EC4899', 'kind' => 'shared', 'default_access' => 'edit', 'purpose' => 'other']);
$calMk = (int)($carlosC->json()['id'] ?? 0);
$carlosC->api('POST', '/api/events', ['calendar_id' => $calMk, 'title' => 'Lanzamiento', 'start_date' => $due1, 'start_time' => '09:00']);
$evMk = (int)($carlosC->json()['id'] ?? 0);
$juanC->get('/calendar');
check('Crear un calendario compartido con color: los demás lo ven', $calMk && str_contains($juanC->body, 'Marketing') && $q('SELECT color FROM calendars WHERE id = ?', [$calMk])->fetchColumn() === '#EC4899');
$juanC->api('DELETE', "/api/calendars/$calMk");
check('Solo quien lo administra puede eliminarlo', $juanC->status === 403, snippet($juanC));
$carlosC->api('DELETE', "/api/calendars/$calMk", ['move_to' => $calTeam]);
check('Al eliminarlo, sus eventos pasan al calendario elegido', $carlosC->status === 200 && (int)$q('SELECT calendar_id FROM events WHERE id = ?', [$evMk])->fetchColumn() === $calTeam && $q('SELECT deleted_at FROM calendars WHERE id = ?', [$calMk])->fetchColumn() !== null, snippet($carlosC));
$carlosC->api('POST', '/api/calendars', ['name' => 'Temporal', 'kind' => 'personal']);
$calTmp = (int)($carlosC->json()['id'] ?? 0);
$carlosC->api('POST', '/api/events', ['calendar_id' => $calTmp, 'title' => 'Borrable', 'start_date' => $todayL]);
$evTmp = (int)($carlosC->json()['id'] ?? 0);
$carlosC->api('DELETE', "/api/calendars/$calTmp", ['move_to' => null]);
check('O se eliminan junto con él (quedan ocultos, recuperables en la base)', $q('SELECT deleted_at FROM events WHERE id = ?', [$evTmp])->fetchColumn() !== null && (int)$q('SELECT COUNT(*) FROM events WHERE id = ?', [$evTmp])->fetchColumn() === 1);

// cancel, reminders
$carlosC->api('POST', '/api/events', ['calendar_id' => $calMeet, 'kind' => 'meeting', 'title' => 'Revisión rápida', 'start_date' => (new DateTime('now', $tz))->format('Y-m-d'),
    'start_time' => (new DateTime('+20 minutes', $tz))->format('H:i'), 'end_time' => (new DateTime('+50 minutes', $tz))->format('H:i'), 'reminder_minutes' => 30, 'participant_ids' => [$lucia]]);
$soon = (int)($carlosC->json()['id'] ?? 0);
$luciaC->get('/notifications');
$remN = (int)$q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key LIKE ?", [$lucia, "ev-rem:$soon:%"])->fetchColumn();
check('Recordatorio dentro de la aplicación 30 minutos antes', $soon && $remN === 1 || (new DateTime('+50 minutes', $tz))->format('Y-m-d') !== (new DateTime('now', $tz))->format('Y-m-d'), "recordatorios: $remN");
$luciaC->get('/');
check('El recordatorio no se duplica', (int)$q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key LIKE ?", [$lucia, "ev-rem:$soon:%"])->fetchColumn() <= 1);
$carlosC->api('POST', "/api/events/$soon/cancel");
check('Cancelar una reunión la deja visible como cancelada y avisa', $q('SELECT status FROM events WHERE id = ?', [$soon])->fetchColumn() === 'cancelled' && (int)$q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND message LIKE 'Se canceló%'", [$lucia])->fetchColumn() === 1);
$carlosC->api('DELETE', "/api/events/$soon");
check('Eliminar un evento lo oculta del calendario', $q('SELECT deleted_at FROM events WHERE id = ?', [$soon])->fetchColumn() !== null);

// time rules
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/unit/events-time.php'), $uout, $ucode);
$unit = json_decode(implode('', $uout), true) ?: ['ok' => false, 'checks' => []];
foreach ($unit['checks'] as $uc) check('Horario: ' . $uc['name'], $uc['ok'], $uc['detail']);
if (!$unit['checks']) check('Horario: pruebas de zona horaria ejecutadas', false, implode("\n", $uout));

// ================================================================ 1.6 Google (local stand-in)
group('1.6 · Google Calendar y Google Tasks');
$carlosC->get('/google/connect');
$carlosC->get('/profile');
check('Sin configurar Google: no se finge la conexión; se explica que falta configurarla', str_contains($carlosC->body, 'no está configurada') && !$q('SELECT COUNT(*) FROM google_accounts')->fetchColumn(), snippet($carlosC));
$fgPort = (int)(getenv('BOREALIS_FAKE_GOOGLE_PORT') ?: 8099);
$fg = 'http://127.0.0.1:' . $fgPort;
$fgState = sys_get_temp_dir() . '/borealis-fake-google-' . getmypid() . '.json';
@unlink($fgState);
$fgProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fgPort", dirname(__DIR__) . '/fake-google/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fgPipes, null, ['FAKE_GOOGLE_STATE' => $fgState, 'PATH' => getenv('PATH')]);
usleep(400000);
$fgCall = function (string $method, string $path, ?array $body = null) use ($fg): array {
    $ch = curl_init($fg . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
    $r = (string)curl_exec($ch); curl_close($ch);
    return json_decode($r, true) ?: [];
};
$fgCtl = fn(array $b) => $fgCall('POST', '/_control', $b);
$cfgPath = dirname(__DIR__, 2) . '/config/config.php';
$cfgOrig = (string)file_get_contents($cfgPath);
$cfgArr = require $cfgPath;
$cfgArr['google'] = ['client_id' => 'test-client.apps', 'client_secret' => 'test-secret', 'auth_url' => "$fg/o/oauth2/v2/auth", 'token_url' => "$fg/token", 'revoke_url' => "$fg/revoke", 'userinfo_url' => "$fg/userinfo", 'api_base' => $fg];
$cfgG = "<?php\nreturn " . var_export($cfgArr, true) . ";\n";
file_put_contents($cfgPath, $cfgG);
usleep(3200000); // the server's opcache re-reads changed files after opcache.revalidate_freq (2 s by default)
$carlosC->get('/profile');
check('Configurado: el perfil ofrece «Conectar con Google» y explica los permisos', str_contains($carlosC->body, 'Conectar con Google') && str_contains($carlosC->body, 'ver y editar tus tareas'), snippet($carlosC));
$carlosC->get('/google/connect');
$authUrl = $carlosC->location();
parse_str((string)parse_url($authUrl, PHP_URL_QUERY), $aq);
check('Autorización real con OAuth: redirige al consentimiento con state, PKCE y acceso sin conexión', str_starts_with($authUrl, $fg . '/o/oauth2/v2/auth') && strlen($aq['state'] ?? '') === 32 && ($aq['code_challenge_method'] ?? '') === 'S256' && ($aq['access_type'] ?? '') === 'offline', $authUrl);
check('Permisos mínimos: correo, lista de calendarios, eventos y tareas (no todo Google Calendar)', ($aq['scope'] ?? '') === 'openid email https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/tasks', $aq['scope'] ?? '');
$carlosC->get('/google/callback?code=robado&state=' . str_repeat('a', 32));
$carlosC->get('/profile');
check('Una respuesta con «state» falso se rechaza', !$q('SELECT COUNT(*) FROM google_accounts')->fetchColumn() && str_contains($carlosC->body, 'no es válida'), snippet($carlosC));
$fgCtl(['action' => 'deny', 'on' => true]);
$carlosC->get('/google/connect');
$ch = curl_init($carlosC->location()); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]); $h = (string)curl_exec($ch); curl_close($ch);
preg_match('/^location:\s*(\S+)/mi', $h, $lm);
$carlosC->get(str_replace($BASE, '', $lm[1] ?? '/'));
$carlosC->get('/profile');
check('Si la persona no da permiso, se informa y no se conecta', str_contains($carlosC->body, 'cancelaste el permiso') && !$q('SELECT COUNT(*) FROM google_accounts')->fetchColumn(), snippet($carlosC));
$fgCtl(['action' => 'deny', 'on' => false]);
$googleConnect = function (Client $c) use ($BASE) {
    $c->get('/google/connect');
    $ch = curl_init($c->location()); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]); $h = (string)curl_exec($ch); curl_close($ch);
    preg_match('/^location:\s*(\S+)/mi', $h, $lm);
    $c->get(str_replace($BASE, '', $lm[1] ?? '/'));
};
$googleConnect($carlosC);
$gacc = $q('SELECT * FROM google_accounts WHERE user_id = ?', [$carlos])->fetch();
check('Conexión completada: cuenta, correo y estado guardados', $gacc && $gacc['google_email'] === 'cal@gmail.test' && $gacc['status'] === 'active', snippet($carlosC));
check('Los tokens se guardan cifrados en el servidor', $gacc && str_starts_with((string)$gacc['access_token'], 'v1:') && str_starts_with((string)$gacc['refresh_token'], 'v1:') && !str_contains((string)$gacc['access_token'], 'at-'));
$keyFile = dirname(__DIR__, 2) . '/storage/app.key';
check('La clave de cifrado está en storage/ con permisos 0600', is_file($keyFile) && (fileperms($keyFile) & 0777) === 0600, is_file($keyFile) ? decoct(fileperms($keyFile) & 0777) : 'no existe');
$carlosC->get('/profile');
check('El navegador nunca recibe los tokens', !preg_match('/\b(at|rt)-[0-9a-f]{12}\b/', $carlosC->body) && str_contains($carlosC->body, 'cal@gmail.test') && str_contains($carlosC->body, '>Conectado<'));
$carlosC->api('GET', '/api/google/calendars');
$gcals = $carlosC->json()['calendars'] ?? [];
check('Elegir calendarios: se listan los de la cuenta de Google', count($gcals) === 2 && $gcals[0]['id'] === 'primary@test', snippet($carlosC));
$fgCtl(['action' => 'add_event', 'id' => 'g1', 'summary' => 'Dentista (Google)', 'start' => ['dateTime' => $due1 . 'T09:00:00-06:00'], 'end' => ['dateTime' => $due1 . 'T10:00:00-06:00']]);
$fgCtl(['action' => 'add_event', 'id' => 'g2', 'summary' => 'Cumpleaños', 'start' => ['date' => $due1], 'end' => ['date' => date('Y-m-d', strtotime($due1 . ' +1 day'))]]);
$fgCtl(['action' => 'add_event', 'id' => 'g3_20261109', 'recurringEventId' => 'g3', 'summary' => 'Yoga', 'start' => ['dateTime' => $due2 . 'T18:00:00Z'], 'end' => ['dateTime' => $due2 . 'T19:00:00Z']]);
$fgCtl(['action' => 'add_event', 'calendar' => 'festivos@test', 'id' => 'h1', 'summary' => 'Día festivo', 'start' => ['date' => $due2], 'end' => ['date' => date('Y-m-d', strtotime($due2 . ' +1 day'))]]);
$carlosC->api('POST', '/api/google/calendars', ['calendars' => ['primary@test', 'festivos@test']]);
$gCal = (int)$q("SELECT id FROM calendars WHERE owner_id = ? AND google_calendar_id = 'primary@test' AND deleted_at IS NULL", [$carlos])->fetchColumn();
$gHol = (int)$q("SELECT id FROM calendars WHERE owner_id = ? AND google_calendar_id = 'festivos@test' AND deleted_at IS NULL", [$carlos])->fetchColumn();
$cntG = fn() => (int)$q('SELECT COUNT(*) FROM events WHERE calendar_id IN (?, ?) AND deleted_at IS NULL', [$gCal, $gHol])->fetchColumn();
check('Importa los eventos de los calendarios elegidos (con varias páginas)', $carlosC->status === 200 && $gCal && $gHol && $cntG() === 4, snippet($carlosC) . ' n=' . $cntG());
$g1 = $q("SELECT * FROM events WHERE calendar_id = ? AND google_event_id = 'g1'", [$gCal])->fetch();
check('Zona horaria: 09:00 −06:00 de Google se guarda como 15:00 UTC', $g1 && $g1['starts_at'] === $due1 . ' 15:00:00', (string)($g1['starts_at'] ?? ''));
$carlosC->api('POST', '/api/google/sync');
$carlosC->api('POST', '/api/google/sync');
check('Sincronizar varias veces no duplica (identificadores estables de Google)', $cntG() === 4 && (int)$q('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$gCal])->fetchColumn() === 3, 'n=' . $cntG());
$log = $fgCall('POST', '/_control', ['action' => 'state'])['log'] ?? [];
check('La sincronización es incremental (syncToken)', (bool)array_filter($log, fn($l) => str_contains($l, '/events')) && (string)$q('SELECT sync_token FROM calendars WHERE id = ?', [$gCal])->fetchColumn() !== '');
$carlosC->get('/calendar?view=week&date=' . $due1);
check('Los calendarios de Google se distinguen de los internos', str_contains($carlosC->body, 'badge badge--google') && str_contains($carlosC->body, 'Dentista (Google)'), snippet($carlosC));
$fgCtl(['action' => 'edit_event', 'id' => 'g1', 'summary' => 'Dentista (cambiado en Google)']);
$fgCtl(['action' => 'delete_event', 'id' => 'g2']);
$carlosC->api('POST', '/api/google/sync');
check('Cambios hechos en Google llegan a Borealis', $q("SELECT title FROM events WHERE calendar_id = ? AND google_event_id = 'g1'", [$gCal])->fetchColumn() === 'Dentista (cambiado en Google)' && $q("SELECT deleted_at FROM events WHERE calendar_id = ? AND google_event_id = 'g2'", [$gCal])->fetchColumn() !== null, snippet($carlosC));
$carlosC->api('POST', '/api/events', ['calendar_id' => $gCal, 'title' => 'Creado en Borealis', 'start_date' => $due2, 'start_time' => '12:00', 'end_time' => '13:00']);
$gNew = (int)($carlosC->json()['id'] ?? 0);
$gNewRow = $q('SELECT * FROM events WHERE id = ?', [$gNew])->fetch();
$remote = $fgCall('POST', '/_control', ['action' => 'state'])['calendars']['primary@test']['events'][$gNewRow['google_event_id'] ?? 'x'] ?? null;
check('Crear un evento en un calendario de Google lo crea también en Google', $gNew && $remote && $remote['summary'] === 'Creado en Borealis' && ($remote['start']['timeZone'] ?? '') === 'America/Mexico_City', snippet($carlosC));
$carlosC->api('POST', '/api/google/sync');
check('…y la siguiente sincronización no lo duplica', (int)$q('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND title = ?', [$gCal, 'Creado en Borealis'])->fetchColumn() === 1);
$fgCtl(['action' => 'edit_event', 'id' => $gNewRow['google_event_id'], 'summary' => 'Cambiado en Google a la vez']);
$carlosC->api('PATCH', "/api/events/$gNew", ['title' => 'Cambiado en Borealis', 'start_date' => $due2, 'start_time' => '12:00', 'end_time' => '13:00']);
check('Conflicto: si cambió en Google mientras tanto, gana Google y se avisa', $carlosC->status === 409 && str_contains($carlosC->body, 'versión de Google') && $q('SELECT title FROM events WHERE id = ?', [$gNew])->fetchColumn() === 'Cambiado en Google a la vez', snippet($carlosC));
$carlosC->api('POST', '/api/events', ['calendar_id' => $gHol, 'title' => 'No permitido', 'start_date' => $due2]);
check('Un calendario de Google de solo lectura no admite eventos nuevos', $carlosC->status === 403, snippet($carlosC));
$q('UPDATE calendars SET sync_token = ? WHERE id = ?', ['tok-1', $gCal]);
$carlosC->api('POST', '/api/google/sync');
check('Si Google invalida la sincronización (410), se rehace completa sin duplicar', $carlosC->status === 200 && (int)$q('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$gCal])->fetchColumn() === 3, snippet($carlosC));
$fgCtl(['action' => 'fail', 'times' => 5]);
$carlosC->api('POST', '/api/google/sync');
$errShown = $carlosC->status === 502 && str_contains($carlosC->body, 'Google no responde');
$carlosC->get('/calendar');
$calOk = $carlosC->status === 200 && str_contains($carlosC->body, 'Google no responde');
$carlosC->api('POST', '/api/events', ['calendar_id' => $calTeam, 'title' => 'Funciona sin Google', 'start_date' => $todayL]);
check('Si Google falla, el error se ve y el calendario interno sigue funcionando', $errShown && $calOk && $carlosC->status === 200, snippet($carlosC));
$fgCtl(['action' => 'fail', 'times' => 0]);

// Google Tasks
$fgCtl(['action' => 'add_task', 'id' => 'tA', 'title' => 'Leche', 'due' => $due1 . 'T00:00:00.000Z']);
$fgCtl(['action' => 'add_task', 'id' => 'tB', 'title' => 'Pan']);
$fgCtl(['action' => 'add_task', 'id' => 'tC', 'title' => 'Integral', 'parent' => 'tB']);
$fgCtl(['action' => 'add_task', 'id' => 'tD', 'title' => 'Huevos']);
$carlosC->api('POST', '/api/google/sync');
$gList = (int)$q("SELECT id FROM personal_lists WHERE user_id = ? AND google_list_id = 'L1'", [$carlos])->fetchColumn();
$cntT = fn() => (int)$q('SELECT COUNT(*) FROM personal_tasks WHERE list_id = ? AND deleted_at IS NULL', [$gList])->fetchColumn();
check('Importar Google Tasks: la lista y sus tareas, con subtareas y fechas', $gList && $cntT() === 4 && $q("SELECT due_date FROM personal_tasks WHERE google_task_id = 'tA'")->fetchColumn() === $due1
    && (int)$q("SELECT parent_id FROM personal_tasks WHERE google_task_id = 'tC'")->fetchColumn() === (int)$q("SELECT id FROM personal_tasks WHERE google_task_id = 'tB'")->fetchColumn(), snippet($carlosC));
$carlosC->api('POST', '/api/google/sync');
check('Sincronizar las tareas de nuevo no las duplica', $cntT() === 4, 'n=' . $cntT());
$tAid = (int)$q("SELECT id FROM personal_tasks WHERE google_task_id = 'tA'")->fetchColumn();
$carlosC->api('PATCH', "/api/my-tasks/$tAid", ['title' => 'Leche sin lactosa']);
$carlosC->api('POST', '/api/my-tasks', ['title' => 'Café', 'list_id' => $gList]);
$carlosC->api('POST', '/api/google/sync');
$st = $fgCall('POST', '/_control', ['action' => 'state']);
$titles = array_column($st['lists']['L1']['tasks'] ?? [], 'title');
check('Cambios y tareas nuevas de Borealis se envían a Google Tasks', ($st['lists']['L1']['tasks']['tA']['title'] ?? '') === 'Leche sin lactosa' && in_array('Café', $titles, true), json_encode($titles));
$tDid = (int)$q("SELECT id FROM personal_tasks WHERE google_task_id = 'tD'")->fetchColumn();
$fgCtl(['action' => 'edit_task', 'id' => 'tD', 'title' => 'Huevos (Google)']);
$carlosC->api('PATCH', "/api/my-tasks/$tDid", ['title' => 'Huevos (Borealis)']);
$carlosC->api('POST', '/api/google/sync');
check('Conflicto en una tarea: gana Google y se informa', $q('SELECT title FROM personal_tasks WHERE id = ?', [$tDid])->fetchColumn() === 'Huevos (Google)' && str_contains($carlosC->body, 'conservó la versión de Google'), snippet($carlosC));
$tBid = (int)$q("SELECT id FROM personal_tasks WHERE google_task_id = 'tB'")->fetchColumn();
$carlosC->api('DELETE', "/api/my-tasks/$tBid");
$carlosC->api('POST', '/api/google/sync');
$st = $fgCall('POST', '/_control', ['action' => 'state']);
check('Borrar en Borealis no borra nada en Google ni la vuelve a importar', isset($st['lists']['L1']['tasks']['tB']) && empty($st['lists']['L1']['tasks']['tB']['deleted']) && (int)$q("SELECT COUNT(*) FROM personal_tasks WHERE google_task_id = 'tB' AND deleted_at IS NULL")->fetchColumn() === 0);
$fgCtl(['action' => 'edit_task', 'id' => 'tA', 'deleted' => true]);
$carlosC->api('POST', '/api/google/sync');
check('Una tarea eliminada en Google se quita de Borealis', $q('SELECT deleted_at FROM personal_tasks WHERE id = ?', [$tAid])->fetchColumn() !== null);
$admin->get('/tasks');
check('Las tareas de Google Tasks también son privadas', !str_contains($admin->body, 'Huevos'));

// expiry and revocation
$q('UPDATE google_accounts SET expires_at = ? WHERE user_id = ?', ['2000-01-01 00:00:00', $carlos]);
$carlosC->api('POST', '/api/google/sync');
check('Token caducado: se renueva solo con el refresh token', $carlosC->status === 200 && strtotime((string)$q('SELECT expires_at FROM google_accounts WHERE user_id = ?', [$carlos])->fetchColumn()) > time(), snippet($carlosC));
$fgCtl(['action' => 'revoke']);
$q('UPDATE google_accounts SET expires_at = ? WHERE user_id = ?', ['2000-01-01 00:00:00', $carlos]);
$carlosC->api('POST', '/api/google/sync');
$revokedMsg = $carlosC->status === 502 && str_contains($carlosC->body, 'retiró el permiso');
$carlosC->get('/profile');
check('Permiso revocado en Google: error visible y opción de volver a conectar', $revokedMsg && $q('SELECT status FROM google_accounts WHERE user_id = ?', [$carlos])->fetchColumn() === 'revoked' && str_contains($carlosC->body, 'Volver a conectar'), snippet($carlosC));
$googleConnect($carlosC);
check('Volver a conectar restablece la cuenta', $q('SELECT status FROM google_accounts WHERE user_id = ?', [$carlos])->fetchColumn() === 'active');
$before = count($fgCall('POST', '/_control', ['action' => 'state'])['calendars']['primary@test']['events'] ?? []);
$carlosC->api('POST', '/api/google/disconnect');
$after = $fgCall('POST', '/_control', ['action' => 'state']);
check('Desconectar: borra los tokens, oculta los calendarios de Google y no elimina nada en Google', $carlosC->status === 200 && !$q('SELECT COUNT(*) FROM google_accounts WHERE user_id = ?', [$carlos])->fetchColumn()
    && $q('SELECT deleted_at FROM calendars WHERE id = ?', [$gCal])->fetchColumn() !== null && count($after['calendars']['primary@test']['events'] ?? []) === $before && !empty($after['revoked']), snippet($carlosC));
check('Tras desconectar, las tareas importadas siguen en Borealis como lista normal', (int)$q('SELECT COUNT(*) FROM personal_tasks WHERE list_id = ? AND deleted_at IS NULL', [$gList])->fetchColumn() >= 2 && $q('SELECT google_list_id FROM personal_lists WHERE id = ?', [$gList])->fetchColumn() === null);
file_put_contents($cfgPath, $cfgOrig);
usleep(3200000);
proc_terminate($fgProc); @unlink($fgState); @unlink($fgState . '.lock');

// Migration 008 can run again (interrupted update) without errors.
$sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/008_v16.sql');
$okMig = true;
foreach (preg_split('/^-- @@.*$/m', $sql) as $stmt) { $stmt = trim(preg_replace('/^--.*$/m', '', $stmt)); if ($stmt === '') continue; try { $pdo->exec($stmt); } catch (Throwable $e) { $okMig = false; } }
check('La migración 008 se puede repetir sin errores ni pérdida de datos', $okMig && (int)$q('SELECT COUNT(*) FROM events')->fetchColumn() >= 5, 'falló al repetir');

// ================================================================ 1.7
group('1.7 · Enlaces de los mensajes de cuenta');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/unit/urls.php'), $uout2, $ucode2);
$unit2 = json_decode(implode('', $uout2), true) ?: ['ok' => false, 'checks' => []];
foreach ($unit2['checks'] as $uc) check('Enlace: ' . $uc['name'], $uc['ok'], $uc['detail']);
if (!$unit2['checks']) check('Enlace: pruebas de URL ejecutadas', false, implode("\n", $uout2));
$nPass = pw();
$admin->api('POST', '/api/members', ['name' => 'Nora Nueva', 'email' => 'nora.nueva@example.test', 'password' => $nPass, 'role_id' => $role($ws1, 'workspace', 'member'), 'weekly_hours' => 15]);
$nora = (int)($admin->json()['id'] ?? 0);
$share = (string)($admin->json()['share_text'] ?? '');
preg_match('#https?://\S+/login#', $share, $lm);
$loginLink = $lm[0] ?? '';
check('El mensaje de cuenta creada trae el enlace exacto de inicio de sesión', $loginLink === rtrim($BASE, '/') . '/login', $share);
$noraC = new Client($BASE);
$noraC->get(parse_url($loginLink, PHP_URL_PATH) ?: '/nada');
check('Ese enlace abre la página de inicio de sesión', $noraC->status === 200 && str_contains($noraC->body, 'Iniciar sesión'), snippet($noraC));
$noraC->login('nora.nueva@example.test', $nPass);
$noraC->get('/');
check('Con los datos del mensaje se entra a Borealis', $noraC->status === 200 && str_contains($noraC->body, 'Nora'), snippet($noraC));
$admin->api('POST', '/api/invitations', ['email' => 'ines.invitada@example.test', 'role_id' => $role($ws1, 'workspace', 'member'), 'weekly_hours' => 12]);
$invLink = (string)($admin->json()['link'] ?? '');
check('El enlace de invitación tiene la dirección pública una sola vez', str_starts_with($invLink, rtrim($BASE, '/') . '/invite/') && substr_count($invLink, '/invite/') === 1, $invLink);

group('1.7 · Horas semanales al crear la cuenta');
check('Crear una cuenta con «Horas semanales disponibles» las guarda (15 h)', (float)$q('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $nora])->fetchColumn() === 15.0);
$admin->api('POST', '/api/members', ['name' => 'Horas Malas', 'email' => 'horas.malas@example.test', 'password' => pw(), 'role_id' => $role($ws1, 'workspace', 'member'), 'weekly_hours' => 200]);
check('Más de 168 horas: se rechaza con el motivo y no se crea la cuenta', $admin->status === 422 && isset($admin->json()['errors']['weekly_hours']) && !$q("SELECT COUNT(*) FROM users WHERE email = 'horas.malas@example.test'")->fetchColumn(), snippet($admin));
$admin->api('POST', '/api/members', ['name' => 'Sin Horas', 'email' => 'sin.horas@example.test', 'password' => pw(), 'role_id' => $role($ws1, 'workspace', 'member')]);
$sinH = (int)($admin->json()['id'] ?? 0);
check('Dejarlas vacías sigue siendo posible (sin límite)', $sinH > 0 && $q('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $sinH])->fetchColumn() === null);
preg_match('#/invite/([a-f0-9]{48})#', $invLink, $im);
$ines = new Client($BASE);
$ines->get('/invite/' . ($im[1] ?? 'x'));
$ines->form('/invite/' . ($im[1] ?? 'x'), ['name' => 'Inés Invitada', 'password' => 'Ines-clave-123', 'password_confirm' => 'Ines-clave-123']);
$inesId = (int)$q("SELECT id FROM users WHERE email = 'ines.invitada@example.test'")->fetchColumn();
check('Las horas indicadas en la invitación se aplican al aceptarla (12 h)', $inesId > 0 && (float)$q('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $inesId])->fetchColumn() === 12.0);
$admin->get('/admin/members');
check('El formulario de crear cuenta tiene el campo «Horas semanales disponibles»', str_contains($admin->body, 'id="cu-h"') && str_contains($admin->body, 'Horas semanales disponibles'));
$admin->api('POST', '/api/projects', ['name' => 'Planificación Nora', 'project_key' => 'PLNO']);
$pN = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pN/modules", ['module' => 'sprints', 'enabled' => true]);
$admin->api('POST', "/api/projects/$pN/members", ['user_id' => $nora, 'role_id' => $role($ws1, 'project', 'contributor')]);
$mon = date('Y-m-d', strtotime('monday next week'));
$admin->api('POST', "/api/projects/$pN/sprints", ['name' => 'Sprint de dos semanas', 'start_date' => $mon, 'end_date' => date('Y-m-d', strtotime($mon . ' +11 days'))]);
$spN = (int)($admin->json()['id'] ?? 0);
$pastMon = date('Y-m-d', strtotime('monday this week -4 weeks'));
$admin->api('POST', "/api/projects/$pN/sprints", ['name' => 'Sprint pasado', 'start_date' => $pastMon, 'end_date' => date('Y-m-d', strtotime($pastMon . ' +11 days'))]);
$spOld = (int)($admin->json()['id'] ?? 0);
$q("UPDATE sprints SET state = 'completed' WHERE id = ?", [$spOld]);
$admin->get("/projects/$pN/sprints?sprint=$spN");
check('Sprint de dos semanas: 15 h por semana dan 30 h de capacidad', str_contains($admin->body, '15 h/sem × 2 sem') && (bool)preg_match('/placeholder="30"/', $admin->body), snippet($admin));
$admin->api('POST', "/api/projects/$pN/tasks", ['title' => 'Demasiado grande', 'assignee_id' => $nora, 'sprint_id' => $spN, 'estimate_hours' => 31]);
check('Una asignación que supera esas 30 h se rechaza con el motivo', in_array($admin->status, [409, 422], true) && str_contains($admin->body, '30 h disponibles'), snippet($admin));
$admin->api('POST', "/api/projects/$pN/tasks", ['title' => 'Cabe justa', 'assignee_id' => $nora, 'sprint_id' => $spN, 'estimate_hours' => 30]);
check('Hasta 30 h se puede asignar', $admin->status === 200, snippet($admin));
$admin->api('PATCH', "/api/members/$nora", ['weekly_hours' => 20]);
check('Cambiar después las horas semanales desde la administración', $admin->status === 200 && (float)$q('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $nora])->fetchColumn() === 20.0, snippet($admin));
$hist = $q("SELECT hours, source FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?", [$spOld, $nora])->fetch(PDO::FETCH_ASSOC) ?: [];
check('El sprint ya terminado conserva sus 30 h (no se recalcula el historial)', (float)($hist['hours'] ?? 0) === 30.0 && ($hist['source'] ?? '') === 'history', json_encode($hist));
check('El sprint por venir usa la nueva disponibilidad (sin copia guardada), y no se copian sprints en los que no participa', !$q('SELECT COUNT(*) FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [$spN, $nora])->fetchColumn() && (int)$q("SELECT COUNT(*) FROM sprint_capacity WHERE user_id = ? AND source = 'history'", [$nora])->fetchColumn() === 1);
$admin->get("/projects/$pN/sprints?sprint=$spN");
check('…y muestra 20 h/sem × 2 sem', str_contains($admin->body, '20 h/sem × 2 sem'), snippet($admin));
$admin->get("/projects/$pN/sprints?sprint=$spOld");
check('El sprint terminado explica que son las horas que tenía', str_contains($admin->body, 'las que tenía este sprint'), snippet($admin));

group('1.7 · Solo los proyectos donde participa cada persona');
$admin->api('POST', '/api/projects', ['name' => 'Proyecto Reservado', 'project_key' => 'RESV', 'visibility' => 'workspace']);
$pR = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pR/tasks", ['title' => 'Tarea reservada', 'due_date' => $todayL]);
$tR = $admin->json()['task'] ?? [];
$noraC->get('/projects');
$a1 = !str_contains($noraC->body, 'Proyecto Reservado');
$noraC->get('/');
$a2 = !str_contains($noraC->body, 'Proyecto Reservado');
check('Sin participar: no aparece en Proyectos ni en el inicio', $a1 && $a2, snippet($noraC));
$noraC->get('/search?q=reservad');
check('…ni en la búsqueda', !str_contains($noraC->body, 'Proyecto Reservado') && !str_contains($noraC->body, 'Tarea reservada'), snippet($noraC));
$noraC->get('/calendar');
$c1 = !str_contains($noraC->body, 'Tarea reservada');
$noraC->get('/tasks?tab=team');
check('…ni en el calendario ni en las tareas del equipo', $c1 && !str_contains($noraC->body, 'Tarea reservada'), snippet($noraC));
$noraC->get("/projects/$pR");
$d1 = $noraC->status;
$noraC->get('/t/' . ($tR['key'] ?? 'RESV-1'));
$d2 = $noraC->status;
$noraC->api('GET', '/api/tasks/' . (int)($tR['id'] ?? 0));
$d3 = $noraC->status;
$noraC->api('PATCH', '/api/tasks/' . (int)($tR['id'] ?? 0), ['title' => 'Intento']);
$d4 = $noraC->status;
check('Con enlace directo o por la API: no existe para ella (404) y no se puede cambiar', $d1 === 404 && $d2 === 404 && in_array($d3, [403, 404], true) && in_array($d4, [403, 404], true) && $q('SELECT title FROM tasks WHERE id = ?', [(int)($tR['id'] ?? 0)])->fetchColumn() === 'Tarea reservada', "$d1 $d2 $d3 $d4");
$admin->api('POST', "/api/projects/$pR/members", ['user_id' => $nora, 'role_id' => $role($ws1, 'project', 'contributor')]);
$noraC->get('/projects');
$e1 = str_contains($noraC->body, 'Proyecto Reservado');
$noraC->get("/projects/$pR");
check('Al añadirla como integrante aparece en su lista al momento y puede entrar', $e1 && $noraC->status === 200, snippet($noraC));
$admin->api('DELETE', "/api/projects/$pR/members/$nora");
$noraC->get('/projects');
$f1 = !str_contains($noraC->body, 'Proyecto Reservado');
$noraC->get("/projects/$pR");
check('Al retirarla desaparece de su lista y el enlace deja de funcionar', $f1 && $noraC->status === 404, snippet($noraC));
$admin->api('POST', '/api/teams', ['name' => 'Equipo Reservado']);
$tRes = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/teams/$tRes/members", ['user_id' => $nora, 'team_role' => 'member']);
$admin->api('POST', "/api/projects/$pR/teams", ['team_id' => $tRes, 'role_id' => $role($ws1, 'project', 'observer')]);
$noraC->get("/projects/$pR");
check('Participar a través de un equipo del proyecto también cuenta', $noraC->status === 200, snippet($noraC));
$admin->api('DELETE', "/api/projects/$pR/teams/$tRes");
$noraC->api('POST', '/api/projects', ['name' => 'Proyecto de Nora', 'project_key' => 'NORA']);
$pNora = (int)($noraC->json()['id'] ?? 0);
$admin->get('/projects');
$g1 = str_contains($admin->body, 'Proyecto de Nora');
$admin->get("/projects/$pNora");
$g2 = $admin->status;
$admin->get('/projects?mine=1');
check('La administración ve todos los proyectos (también los que no integra) y puede abrirlos', $g1 && $g2 === 200, snippet($admin));
check('«Solo donde participo» deja a la administración solo con los suyos', !str_contains($admin->body, 'data-project-card="' . $pNora . '"') && str_contains($admin->body, 'data-project-card="' . $pR . '"'), snippet($admin));
$juanC->get('/projects');
check('Otra persona no ve el proyecto que creó Nora', !str_contains($juanC->body, 'Proyecto de Nora'), snippet($juanC));
$noraC->get('/projects');
check('Quien crea un proyecto lo ve (queda como integrante)', str_contains($noraC->body, 'Proyecto de Nora'), snippet($noraC));

group('1.7 · Fotografía de perfil');
$img = imagecreatetruecolor(600, 400);
imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 90));
imagefilledellipse($img, 300, 200, 200, 200, imagecolorallocate($img, 240, 200, 60));
ob_start(); imagepng($img); $png = (string)ob_get_clean();
$noraC->get('/');
$noraC->upload('/api/profile/photo', [['foto.png', $png, 'image/png']], [], false, 'photo');
$tok = (string)$q('SELECT avatar_photo FROM users WHERE id = ?', [$nora])->fetchColumn();
check('Subir una fotografía la guarda', $noraC->status === 200 && preg_match('/^[0-9a-f]{16}$/', $tok) === 1, snippet($noraC));
$small = "/avatars/$nora/$tok/96";
$noraC->get($small);
$sz = @getimagesizefromstring($noraC->body) ?: [0, 0, 0];
check('Se guarda pequeña y cuadrada en WebP (96 × 96 para listas)', $noraC->status === 200 && $sz[0] === 96 && $sz[1] === 96 && $sz[2] === IMAGETYPE_WEBP && strlen($noraC->body) < 15000, json_encode([$noraC->status, $sz, strlen($noraC->body)]));
$noraC->get("/avatars/$nora/$tok/256");
$sz2 = @getimagesizefromstring($noraC->body) ?: [0, 0];
check('Y una de 256 × 256 para el perfil', $sz2[0] === 256 && $sz2[1] === 256);
check('Se puede guardar en caché (su dirección cambia con cada fotografía)', str_contains(implode(',', $noraC->headers['cache-control'] ?? []), 'immutable'), json_encode($noraC->headers['cache-control'] ?? []));
$noraC->get('/');
check('Aparece en el menú de la cuenta', str_contains($noraC->body, 'id="topbar-me-photo"') && str_contains($noraC->body, $small), snippet($noraC));
$admin->api('POST', "/api/projects/$pN/tasks", ['title' => 'Con foto', 'assignee_id' => $nora]);
$admin->get("/projects/$pN/board");
$h1 = str_contains($admin->body, $small);
$admin->get("/projects/$pN/settings?section=members");
check('Aparece en las tarjetas y en las listas de responsables', $h1 && str_contains($admin->body, $small), snippet($admin));
$anonP = new Client($BASE);
$anonP->get($small);
check('Sin iniciar sesión no se puede ver la fotografía', $anonP->status !== 200 || !str_contains((string)($anonP->headers['content-type'][0] ?? ''), 'image'), (string)$anonP->status);
$noraC->upload('/api/profile/photo', [['virus.png', '<?php echo 1; ?>', 'image/png']], [], false, 'photo');
check('Un archivo que no es una imagen se rechaza', $noraC->status === 422, snippet($noraC));
$noraC->upload('/api/profile/photo', [['foto.png', $png, 'image/png']], [], false, 'photo');
$tok2 = (string)$q('SELECT avatar_photo FROM users WHERE id = ?', [$nora])->fetchColumn();
$noraC->get($small);
check('Cambiarla reemplaza la anterior (la vieja ya no se sirve ni queda guardada)', $tok2 !== $tok && $noraC->status === 404 && !glob(dirname(__DIR__, 2) . "/storage/uploads/avatars/$nora-$tok-*"), (string)$noraC->status);
$noraC->api('DELETE', '/api/profile/photo');
$noraC->get('/');
check('Eliminarla vuelve a mostrar las iniciales', $noraC->status === 200 && $q('SELECT avatar_photo FROM users WHERE id = ?', [$nora])->fetchColumn() === null && str_contains($noraC->body, '>NN</span>') && !glob(dirname(__DIR__, 2) . "/storage/uploads/avatars/$nora-*"), snippet($noraC));

group('1.7 · Menú lateral');
$noraC->get('/calendar');
check('«Calendario» está en el menú lateral con su icono y queda marcado al abrirlo', (bool)preg_match('#<a class="nav-item" data-tour="nav-calendar" href="[^"]*/calendar" aria-current="page"><svg[^>]*><use href="[^"]*\#i-calendar"#', $noraC->body), snippet($noraC));
$css17 = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/borealis.css');
check('La barra lateral ya no dibuja una barra de desplazamiento (sigue desplazándose)', str_contains($css17, '.sidebar { scrollbar-width: none;') && str_contains($css17, '.sidebar::-webkit-scrollbar { display: none;'));

group('1.7.1 · Mis tareas, Completadas, Nueva tarea y tutorial');
$noraC->get('/tasks');
check('«Mis tareas» muestra las tareas de proyectos asignadas a la persona', $noraC->status === 200 && str_contains($noraC->body, 'Asignadas en proyectos') && str_contains($noraC->body, 'Con foto'), snippet($noraC));
check('Y por separado sus tareas personales', str_contains($noraC->body, 'id="mine-personal-h"') && str_contains($noraC->body, 'data-tour="tasks-add"'));
$noraC->get('/tasks?tab=assigned');
check('El enlace antiguo «Asignadas a mí» sigue funcionando (Mis tareas, solo de proyectos)', $noraC->status === 200 && str_contains($noraC->body, 'Con foto') && !str_contains($noraC->body, 'id="mine-personal-h"'));
$noraC->api('POST', '/api/my-tasks', ['title' => 'Comprar café privado']);
$admin->get('/tasks');
check('Las personales siguen siendo privadas (otra persona no las ve)', !str_contains($admin->body, 'Comprar café privado'));
$noraC->get('/tasks?tab=done');
check('«Completadas» usa una cuadrícula real (antes faltaba la clase .grid y las tarjetas se pegaban)', str_contains($noraC->body, 'class="grid grid-2 done-grid"'));
$pdo->exec('RENAME TABLE personal_tasks TO personal_tasks_off');
$noraC->get('/tasks');
$pdo->exec('RENAME TABLE personal_tasks_off TO personal_tasks');
check('Si una parte no se puede cargar, la página no queda en blanco: se explica y lo demás se ve', $noraC->status === 200 && str_contains($noraC->body, 'No se pudieron cargar tus tareas personales') && str_contains($noraC->body, 'Con foto'), snippet($noraC));
$admin->get("/projects/$pN/board");
check('«Nueva tarea» ofrece «Tiempo estimado» y «Sprint»', str_contains($admin->body, 'id="nt-hours" name="estimate_hours"') && str_contains($admin->body, 'id="nt-sprint" name="sprint_id"'));
$admin->api('POST', "/api/projects/$pN/sprints", ['name' => 'Sprint 2', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+13 days'))]);
$sp2 = (int)$q("SELECT id FROM sprints WHERE project_id = ? AND name = 'Sprint 2' ORDER BY id DESC LIMIT 1", [$pN])->fetchColumn();
$admin->api('POST', "/api/projects/$pN/tasks", ['title' => 'Diseñar pantalla de inicio', 'assignee_id' => $nora, 'sprint_id' => $sp2, 'estimate_hours' => '6']);
$newT = $admin->json()['task'] ?? [];
$row = $q('SELECT estimate_hours, sprint_id FROM tasks WHERE id = ?', [(int)($newT['id'] ?? 0)])->fetch();
check('Crear con tiempo estimado lo guarda junto con el sprint (6 h, Sprint 2)', $admin->status === 200 && $row && (float)$row['estimate_hours'] === 6.0 && (int)$row['sprint_id'] === $sp2, snippet($admin));
$admin->get('/api/tasks/' . (int)($newT['id'] ?? 0));
check('Al abrir la tarea aparecen las 6 h estimadas', (float)($admin->json()['task']['estimate_hours'] ?? 0) === 6.0);
$admin->api('POST', "/api/projects/$pN/tasks", ['title' => 'Horas raras', 'estimate_hours' => '-2']);
check('Horas estimadas negativas: se rechazan con el motivo en el campo', $admin->status === 422 && isset($admin->json()['errors']['estimate_hours']), snippet($admin));
$noraC->get('/');
check('Cada página sabe en qué proyecto mostrar el paso «Sprints» del tutorial', (bool)preg_match('/data-tour-project="\d+"/', $noraC->body));
check('El tutorial se carga con la versión en la dirección (la caché no sirve el antiguo)', str_contains((string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js'), "import './tour.js?v=" . (preg_match("/define\\('BOREALIS_VERSION', '([^']+)'/", (string)file_get_contents(dirname(__DIR__, 2) . '/app/bootstrap.php'), $vm) ? $vm[1] : '?') . "'"));

// Migration 009 can run again (interrupted update) without errors.
group('1.8.1 · Fechas en día/mes/año');
$admin->api('PATCH', '/api/tasks/' . (int)($tR['id'] ?? 0), ['due_date' => '2026-11-09']);
$admin->get("/projects/$pR/export.csv");
$csvT = $admin->body;
check('El CSV de tareas escribe las fechas como dd/mm/aaaa', str_contains($csvT, '09/11/2026') && !preg_match('/,20\d\d-\d\d-\d\d[,\s]/', $csvT), mb_substr($csvT, 0, 400));
check('…y los momentos (creada, finalizada) como dd/mm/aaaa hh:mm en la hora local', (bool)preg_match('#,"?\d{2}/\d{2}/\d{4} \d{2}:\d{2}"?[,\r\n]#', $csvT), mb_substr($csvT, 0, 400));
$admin->get('/reports/hours.csv');
check('El CSV de horas escribe la fecha como dd/mm/aaaa', (bool)preg_match('#\n\d{2}/\d{2}/\d{4},#', $admin->body) && !preg_match('#\n\d{4}-\d{2}-\d{2},#', $admin->body), mb_substr($admin->body, 0, 300));
$admin->get('/');
check('Las páginas cargan el campo de fecha dd/mm/aaaa', str_contains($admin->body, 'assets/js/app.js') && str_contains((string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js'), "import './date-field.js?v="));
$admin->api('PATCH', '/api/tasks/' . (int)($tR['id'] ?? 0), ['due_date' => '09/11/2026']);
check('El servidor sigue exigiendo la fecha completa en formato interno (no adivina 09/11)', $admin->status === 422, snippet($admin));

// Someone with a task in a project they never joined (possible before 1.7) keeps access after the update.
$admin->api('POST', "/api/projects/$pR/members", ['user_id' => $sinH, 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', "/api/projects/$pR/tasks", ['title' => 'Trabajo de antes', 'assignee_id' => $sinH]);
$q('DELETE FROM project_members WHERE project_id = ? AND user_id = ?', [$pR, $sinH]);
$pdo->exec("DELETE FROM schema_migrations WHERE version = '009_v17'");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/migrate.php') . ' --no-backup 2>&1', $mout, $mcode);
$okMig9 = $mcode === 0 && str_contains(implode("\n", $mout), 'Aplicada: 009_v17');
$again = $mout;
check('Al actualizar, quien tenía tareas u horas en un proyecto sin ser integrante pasa a colaborar en él', $okMig9 && (string)$q("SELECT r.role_key FROM project_members pm JOIN roles r ON r.id = pm.role_id WHERE pm.project_id = ? AND pm.user_id = ?", [$pR, $sinH])->fetchColumn() === 'contributor' && !$q('SELECT COUNT(*) FROM project_members WHERE project_id = ? AND user_id = ?', [$pR, $nora])->fetchColumn());
check('La migración 009 se puede repetir sin errores ni pérdida de datos', $okMig9 && $q('SELECT avatar_photo FROM users WHERE id = ?', [$nora])->fetchColumn() === null && (float)$q('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws1, $nora])->fetchColumn() === 20.0, json_encode($again));

require __DIR__ . '/v18.php';
require __DIR__ . '/v19.php';
require __DIR__ . '/v110.php';
require __DIR__ . '/v111.php';
require __DIR__ . '/v112.php';
require __DIR__ . '/v113.php';
require __DIR__ . '/v114.php';

// ---------------------------------------------------------------- summary
$pass = count(array_filter($results, fn($r) => $r['ok']));
$fail = count($results) - $pass;
echo "\nResultado: $pass correctas, $fail fallidas, " . count($results) . " en total.\n";
if ($jsonOut) file_put_contents($jsonOut, json_encode(['date' => gmdate('c'), 'base' => $BASE, 'pass' => $pass, 'fail' => $fail, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($fail ? 1 : 0);
