<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web

/*
 * Borealis 1.10 — integration checks: links of a task to its Google Drive folder and GitHub repository.
 * Included by run.php after v19.php (same globals: $admin, $q, $role, $ws1, $mkUser, check(), group(), snippet()).
 */

group('1.10 · Enlaces de Drive y GitHub en las tareas');
[$lia, $liaC] = $mkUser('Lía Lectura', 'lia.lectura@example.test', 'member');
$admin->api('POST', '/api/projects', ['name' => 'Proyecto Enlaces', 'project_key' => 'ENL', 'description' => 'Pruebas 1.10']);
$p110 = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$p110/members", ['user_id' => $lia, 'role_id' => $role($ws1, 'project', 'observer')]);
$linkOf = fn(int $id) => $q('SELECT drive_url, github_url FROM tasks WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC) ?: [];

$admin->api('POST', "/api/projects/$p110/tasks", ['title' => 'Tarea con enlaces',
    'drive_url' => '  https://drive.google.com/drive/folders/1AbC-dEf_GhI?usp=sharing  ', 'github_url' => 'github.com/Gep/borealis']);
$tl = $admin->json()['task'] ?? [];
$tlId = (int)($tl['id'] ?? 0);
$row = $linkOf($tlId);
check('Una tarea nueva guarda la carpeta de Drive y el repositorio de GitHub', $admin->status === 200
    && ($row['drive_url'] ?? '') === 'https://drive.google.com/drive/folders/1AbC-dEf_GhI?usp=sharing'
    && ($row['github_url'] ?? '') === 'https://github.com/Gep/borealis', snippet($admin) . ' ' . json_encode($row));

$admin->api('PATCH', "/api/tasks/$tlId", ['github_url' => 'git@github.com:Gep/borealis-api.git']);
check('Se acepta el enlace SSH de GitHub y se guarda como https', $admin->status === 200 && ($linkOf($tlId)['github_url'] ?? '') === 'https://github.com/Gep/borealis-api', snippet($admin));
$admin->api('PATCH', "/api/tasks/$tlId", ['github_url' => 'http://www.github.com/Gep/borealis/tree/main/app/']);
check('Un enlace a una rama o carpeta del repositorio se conserva (en https)', $admin->status === 200 && ($linkOf($tlId)['github_url'] ?? '') === 'https://github.com/Gep/borealis/tree/main/app', snippet($admin));

foreach ([
    ['drive_url', 'https://dropbox.com/carpeta', 'Drive de otro servicio'],
    ['drive_url', 'javascript:alert(1)', 'Drive con javascript:'],
    ['drive_url', 'https://drive.google.com.evil.test/drive/folders/x', 'Drive con dominio falso'],
    ['github_url', 'https://github.com/solo-organizacion', 'GitHub sin repositorio'],
    ['github_url', 'https://gitlab.com/Gep/borealis', 'GitHub de otro servicio'],
    ['github_url', 'https://user:pass@github.com/Gep/borealis', 'GitHub con usuario y contraseña'],
    ['github_url', 'https://github.com/Gep/"><script>', 'GitHub con HTML'],
] as [$field, $bad, $what]) {
    $admin->api('PATCH', "/api/tasks/$tlId", [$field => $bad]);
    check("Se rechaza con el motivo: $what", $admin->status === 422 && isset($admin->json()['errors'][$field]), snippet($admin));
}
check('Un enlace rechazado no cambia lo guardado', ($linkOf($tlId)['github_url'] ?? '') === 'https://github.com/Gep/borealis/tree/main/app');

$admin->api('PATCH', "/api/tasks/$tlId", ['github_url' => 'https://github.com/Gep/borealis']);
$act = (string)$q("SELECT GROUP_CONCAT(summary SEPARATOR ' | ') FROM activity WHERE task_id = ?", [$tlId])->fetchColumn();
check('El historial dice que cambió el repositorio de GitHub', str_contains($act, 'repositorio de GitHub'), $act);

$admin->get('/t/' . ($tl['key'] ?? 'ENL-1'));
check('La ficha de la tarea muestra la sección «Enlaces de trabajo» con los dos campos', $admin->status === 200 && str_contains($admin->body, 'Enlaces de trabajo')
    && str_contains($admin->body, 'name="drive_url"') && str_contains($admin->body, 'name="github_url"'), snippet($admin));
check('Cada enlace tiene su botón «Copiar» con el enlace guardado', str_contains($admin->body, 'data-copy="https://drive.google.com/drive/folders/1AbC-dEf_GhI?usp=sharing"')
    && str_contains($admin->body, 'data-copy="https://github.com/Gep/borealis"') && substr_count($admin->body, 'data-copy-msg=') === 2, snippet($admin));
check('Y un botón para abrirlo en otra pestaña (sin dar acceso a la ventana de Borealis)', str_contains($admin->body, 'href="https://github.com/Gep/borealis" target="_blank" rel="noopener noreferrer"'));

$liaC->get('/t/' . ($tl['key'] ?? 'ENL-1'));
check('Quien solo puede ver la tarea ve y copia los enlaces, pero no puede cambiarlos', $liaC->status === 200 && str_contains($liaC->body, 'data-copy="https://github.com/Gep/borealis"') && !str_contains($liaC->body, 'name="github_url"'), snippet($liaC));
$liaC->api('PATCH', "/api/tasks/$tlId", ['github_url' => 'https://github.com/otro/repo']);
check('…y la API tampoco le deja cambiarlos', $liaC->status === 403 && ($linkOf($tlId)['github_url'] ?? '') === 'https://github.com/Gep/borealis', snippet($liaC));

$admin->api('GET', "/api/tasks/$tlId");
check('La API de la tarea incluye los dos enlaces', ($admin->json()['task']['drive_url'] ?? '') !== '' && ($admin->json()['task']['github_url'] ?? '') === 'https://github.com/Gep/borealis', snippet($admin));

$admin->api('POST', "/api/tasks/$tlId/duplicate");
$dupL = (int)($admin->json()['task']['id'] ?? 0);
check('Duplicar una tarea copia sus enlaces', $dupL && ($linkOf($dupL)['github_url'] ?? '') === 'https://github.com/Gep/borealis' && str_starts_with((string)($linkOf($dupL)['drive_url'] ?? ''), 'https://drive.google.com/'), snippet($admin));

$admin->api('PATCH', "/api/tasks/$tlId", ['drive_url' => '', 'github_url' => null]);
$row = $linkOf($tlId);
check('Vaciar el campo quita el enlace', $admin->status === 200 && $row['drive_url'] === null && $row['github_url'] === null, snippet($admin));
$admin->get('/t/' . ($tl['key'] ?? 'ENL-1'));
check('Sin enlaces, la sección sigue lista para pegar uno', str_contains($admin->body, 'placeholder="https://drive.google.com/drive/folders/…"') && str_contains($admin->body, 'placeholder="https://github.com/organización/repositorio"'));

$admin->get("/projects/$p110/export.json");
$exp110 = json_decode($admin->body, true) ?: [];
$withLinks = array_values(array_filter($exp110['tasks'] ?? [], fn($x) => ($x['github_url'] ?? null) === 'https://github.com/Gep/borealis'));
check('Exportar el proyecto incluye los enlaces de las tareas', $admin->status === 200 && count($withLinks) === 1, snippet($admin));
$exp110['tasks'][] = ['id' => 999999, 'parent_id' => null, 'title' => 'Importada con enlace raro', 'github_url' => 'javascript:alert(1)', 'drive_url' => 'drive.google.com/drive/folders/zz'] + ($exp110['tasks'][0] ?? []);
$admin->upload('/api/projects/import', [['proyecto.json', json_encode($exp110), 'application/json']], [], false);
$np110 = (int)($admin->json()['id'] ?? 0);
$imp = $q("SELECT title, drive_url, github_url FROM tasks WHERE project_id = ? AND deleted_at IS NULL ORDER BY id", [$np110])->fetchAll(PDO::FETCH_ASSOC);
$byTitle = array_column($imp, null, 'title');
check('Importar un proyecto conserva los enlaces válidos y descarta los que no lo son', $np110 > 0
    && ($byTitle['Copia de Tarea con enlaces']['github_url'] ?? '') === 'https://github.com/Gep/borealis'
    && array_key_exists('github_url', $byTitle['Importada con enlace raro'] ?? []) && $byTitle['Importada con enlace raro']['github_url'] === null
    && ($byTitle['Importada con enlace raro']['drive_url'] ?? '') === 'https://drive.google.com/drive/folders/zz', snippet($admin) . ' ' . json_encode($imp));
