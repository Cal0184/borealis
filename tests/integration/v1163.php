<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web

/*
 * Borealis 1.16.3 — deleting a person from Administración › Usuarios, and «Correo al soporte».
 * Included by run.php after v116.php (same globals).
 */

$root = dirname(__DIR__, 2);
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();

group('1.16.3 · Eliminar usuarios');
$mk = function (string $name, string $email, string $pass) use ($admin, $role, $ws1): int {
    $admin->api('POST', '/api/members', ['name' => $name, 'email' => $email, 'password' => $pass, 'role_id' => $role($ws1, 'workspace', 'member')]);
    return (int)($admin->json()['id'] ?? 0);
};
$elisaPass = pw();
$elisa = $mk('Elisa Saliente', 'elisa@example.test', $elisaPass);
$hugo = $mk('Hugo Heredero', 'hugo@example.test', pw());
check('Se crean dos personas para la prueba', $elisa > 0 && $hugo > 0);
foreach ([$elisa, $hugo] as $u) $admin->api('POST', "/api/projects/$pS/members", ['user_id' => $u, 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Abierta de Elisa', 'assignee_id' => $elisa]);
$tOpen = (int)$q("SELECT id FROM tasks WHERE title = 'Abierta de Elisa' ORDER BY id DESC LIMIT 1")->fetchColumn();
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Terminada de Elisa', 'assignee_id' => $elisa]);
$tDone = (int)$q("SELECT id FROM tasks WHERE title = 'Terminada de Elisa' ORDER BY id DESC LIMIT 1")->fetchColumn();
$q('UPDATE tasks SET completed_at = UTC_TIMESTAMP() WHERE id = ?', [$tDone]);
$q('UPDATE projects SET lead_user_id = ? WHERE id = ?', [$elisa, $pS]);
$admin->api('POST', "/api/tasks/$tOpen/comments", ['body' => 'Comentario de prueba antes de eliminar']);
$q('UPDATE comments SET user_id = ? WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$elisa, $tOpen]);
$elisaC = new Client($BASE); $elisaC->login('elisa@example.test', $elisaPass); $elisaC->get('/');
check('Elisa entra antes de eliminarla', $elisaC->status === 200 && str_contains($elisaC->body, 'Elisa'), snippet($elisaC));

$admin->get('/admin/members');
check('Administración › Usuarios tiene «Eliminar usuario» con confirmación, a quién pasar el trabajo y el número de tareas abiertas',
    str_contains($admin->body, 'data-dialog="dlg-del-' . $elisa . '"') && str_contains($admin->body, 'data-api="/api/members/' . $elisa . '/delete"') && str_contains($admin->body, 'name="reassign_to"') && preg_match('~id="dlg-del-' . $elisa . '".*?<strong>1</strong> tarea abierta~s', $admin->body), snippet($admin));

$bruno->api('POST', "/api/members/$elisa/delete", ['confirm' => 1]);
check('Sin permiso de administrar usuarios no se puede eliminar (403)', $bruno->status === 403, snippet($bruno));
$admin->api('POST', "/api/members/$elisa/delete", []);
check('Sin marcar la confirmación no se elimina (422)', $admin->status === 422 && $cnt('SELECT COUNT(*) FROM workspace_members WHERE user_id = ?', [$elisa]) === 1, snippet($admin));
$adminId = (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn();
$admin->api('POST', "/api/members/$adminId/delete", ['confirm' => 1]);
check('Nadie puede eliminarse a sí mismo', $admin->status === 409, snippet($admin));

$backups = count(glob($root . '/storage/backups/*antes-de-eliminar-usuario*') ?: []);
$admin->api('POST', "/api/members/$elisa/delete", ['confirm' => 1, 'reassign_to' => $hugo, 'reason' => 'Dejó el equipo']);
$res = $admin->json();
check('Se elimina y responde con a quién pasó su trabajo y la copia de seguridad', $admin->status === 200 && ($res['reassigned_to'] ?? '') === 'Hugo Heredero' && !empty($res['account_closed']) && str_contains((string)($res['backup'] ?? ''), 'antes-de-eliminar-usuario'), snippet($admin));
check('Antes se guarda una copia de seguridad completa', count(glob($root . '/storage/backups/*antes-de-eliminar-usuario*') ?: []) === $backups + 1);
check('Ya no pertenece al espacio ni a sus proyectos', !$cnt('SELECT COUNT(*) FROM workspace_members WHERE user_id = ?', [$elisa]) && !$cnt('SELECT COUNT(*) FROM project_members WHERE user_id = ?', [$elisa]));
check('Su tarea abierta pasa a la persona elegida', (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$tOpen])->fetchColumn() === $hugo && !$cnt('SELECT COUNT(*) FROM task_assignees WHERE task_id = ? AND user_id = ?', [$tOpen, $elisa]));
check('…y también el proyecto que lideraba', (int)$q('SELECT lead_user_id FROM projects WHERE id = ?', [$pS])->fetchColumn() === $hugo);
check('La tarea terminada conserva quién la hizo, y su comentario sigue ahí', (int)$q('SELECT assignee_id FROM tasks WHERE id = ?', [$tDone])->fetchColumn() === $elisa && $cnt('SELECT COUNT(*) FROM comments WHERE user_id = ?', [$elisa]) >= 1);
$u = $q('SELECT name, email, is_active FROM users WHERE id = ?', [$elisa])->fetch(PDO::FETCH_ASSOC);
check('Su cuenta (sin otros espacios) se cierra: «Usuario eliminado», correo liberado', $u['name'] === 'Usuario eliminado' && (int)$u['is_active'] === 0 && str_ends_with($u['email'], '@borealis.invalid'), json_encode($u));
$elisaC->get('/');
check('Su sesión abierta deja de funcionar', $elisaC->status !== 200 || !str_contains($elisaC->body, 'Elisa Saliente'), snippet($elisaC));
$try = new Client($BASE); $try->login('elisa@example.test', $elisaPass);
$try->get('/');
check('…y ya no puede iniciar sesión', !str_contains($try->body, 'Elisa'), snippet($try));
$log = $q("SELECT * FROM activity WHERE action = 'deleted' AND entity_type = 'user' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$elisa])->fetch(PDO::FETCH_ASSOC) ?: [];
check('Queda en el registro maestro con quién, motivo, antes y después', str_contains((string)($log['summary'] ?? ''), 'Elisa Saliente') && ($log['reason'] ?? '') === 'Dejó el equipo' && ($log['category'] ?? '') === 'deletion' && str_contains((string)$log['after_data'], 'Hugo Heredero'), json_encode($log));
$mk('Elisa Nueva', 'elisa@example.test', pw());
check('El mismo correo puede volver a usarse para una cuenta nueva', $admin->status === 200, snippet($admin));

group('1.16.3 · Correo al soporte');
$admin->get('/');
check('Sin correo configurado no aparece el botón (la administración ve «Configurar»)', !str_contains($admin->body, 'data-support') && str_contains($admin->body, 'Configurar correo al soporte'));
$bruno->api('PATCH', '/api/support/settings', ['email' => 'soporte@example.test']);
check('Solo quien administra el espacio lo configura', $bruno->status === 403, snippet($bruno));
$admin->api('PATCH', '/api/support/settings', ['email' => 'no-es-correo']);
check('Valida el correo', $admin->status === 422, snippet($admin));
$admin->api('PATCH', '/api/support/settings', ['email' => 'Soporte@Example.test', 'subject' => 'Ayuda Borealis']);
check('Se guarda desde Administración › Ajustes', $admin->status === 200 && ($admin->json()['email'] ?? '') === 'soporte@example.test', snippet($admin));
$admin->get('/admin');
check('Ajustes muestra el formulario y «Probar el botón»', str_contains($admin->body, 'id="soporte"') && str_contains($admin->body, 'Probar el botón'));
$bruno->get('/my-work');
preg_match('~href="(mailto:[^"]+)"[^>]*data-support~', $bruno->body, $mm);
$mail = html_entity_decode($mm[1] ?? '');
parse_str((string)parse_url($mail, PHP_URL_QUERY), $mq);
check('Todos ven «Correo al soporte»: abre su correo con la dirección, el asunto y el espacio', str_starts_with($mail, 'mailto:soporte%40example.test?') && str_starts_with((string)($mq['subject'] ?? ''), 'Ayuda Borealis · '), $mail);
check('…con los datos que el soporte necesita: quién, página, versión', str_contains((string)($mq['body'] ?? ''), 'Bruno Prueba <bruno@example.test>') && str_contains((string)$mq['body'], '/my-work') && str_contains((string)$mq['body'], 'Borealis 1.16.6') && str_contains((string)$mq['body'], '¿Qué pasó?'), (string)($mq['body'] ?? ''));
check('Está en el menú de la cuenta y en la barra lateral', substr_count($bruno->body, 'data-support') >= 2);
$anonS = new Client($BASE); $anonS->get('/login');
check('También en la página de inicio de sesión (para quien no puede entrar)', str_contains($anonS->body, 'data-support') && str_contains(html_entity_decode($anonS->body), 'mailto:soporte%40example.test'), snippet($anonS));
$admin->api('PATCH', '/api/support/settings', ['email' => '']);
$bruno->get('/my-work');
check('Al vaciarlo se oculta el botón', $admin->status === 200 && !str_contains($bruno->body, 'data-support'), snippet($admin));
