<?php
declare(strict_types=1);

/*
 * A local stand-in for Google's OAuth, Calendar v3 and Tasks v1 endpoints, ONLY for the test-suite.
 * It answers the small subset Borealis uses, with the same shapes, ids, etags, syncTokens and errors
 * (401, 404, 410, 412, invalid_grant). It proves Borealis' side of the integration; it does not prove
 * that Google itself accepts the requests — that needs a real OAuth client (README › Google).
 *
 *   FAKE_GOOGLE_STATE=/tmp/fg.json php -S 127.0.0.1:8099 tests/fake-google/server.php
 *
 * Test control: POST /_control {"action": "reset" | "state" | "add_event" | "edit_event" | "delete_event"
 *   | "add_task" | "edit_task" | "revoke" | "deny" | "fail"} (see the code below).
 */

$file = getenv('FAKE_GOOGLE_STATE') ?: sys_get_temp_dir() . '/fake-google.json';
$fp = fopen($file . '.lock', 'c');
flock($fp, LOCK_EX);
$S = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
if (!$S) $S = fresh();
register_shutdown_function(function () use (&$S, $file, $fp) { file_put_contents($file, json_encode($S)); flock($fp, LOCK_UN); });

function fresh(): array
{
    return ['seq' => 10, 'codes' => [], 'tokens' => [], 'refresh' => [], 'revoked' => false, 'deny' => false, 'fail' => 0, 'log' => [],
        'calendars' => [
            'primary@test' => ['id' => 'primary@test', 'summary' => 'Cal (Google)', 'backgroundColor' => '#4285f4', 'accessRole' => 'owner', 'primary' => true, 'events' => []],
            'festivos@test' => ['id' => 'festivos@test', 'summary' => 'Festivos', 'backgroundColor' => '#16a765', 'accessRole' => 'reader', 'events' => []],
        ],
        'lists' => ['L1' => ['id' => 'L1', 'title' => 'Compras', 'etag' => '"l1"', 'tasks' => []]]];
}
function out(int $status, array $data = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function body(): array
{
    $raw = (string)file_get_contents('php://input');
    if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'x-www-form-urlencoded')) { parse_str($raw, $f); return $f; }
    return json_decode($raw, true) ?: [];
}
function stamp(array &$S): string { $S['seq']++; return gmdate('Y-m-d\TH:i:s.000\Z', 1790000000 + $S['seq']); }
function auth(array &$S): void
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $tok = str_starts_with($h, 'Bearer ') ? substr($h, 7) : '';
    if ($tok === '' || !isset($S['tokens'][$tok]) || $S['revoked']) out(401, ['error' => ['code' => 401, 'message' => 'Invalid Credentials', 'status' => 'UNAUTHENTICATED']]);
    if ($S['fail'] > 0) { $S['fail']--; out(503, ['error' => ['code' => 503, 'message' => 'Backend Error']]); }
}

$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
parse_str((string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_QUERY), $Q);
$S['log'][] = "$m $path";
$S['log'] = array_slice($S['log'], -200);

// ---- test control ---------------------------------------------------------------------
if ($path === '/_control') {
    $b = body();
    switch ($b['action'] ?? '') {
        case 'reset': $S = fresh(); out(200, ['ok' => true]);
        case 'state': out(200, $S);
        case 'revoke': $S['revoked'] = true; out(200, ['ok' => true]);
        case 'deny': $S['deny'] = (bool)($b['on'] ?? true); out(200, ['ok' => true]);
        case 'fail': $S['fail'] = (int)($b['times'] ?? 1); out(200, ['ok' => true]);
        case 'add_event':
            $id = $b['id'] ?? ('ev' . $S['seq']);
            $S['calendars'][$b['calendar'] ?? 'primary@test']['events'][$id] = ['id' => $id, 'status' => 'confirmed', 'summary' => $b['summary'] ?? 'Evento de Google', 'etag' => '"e' . $S['seq'] . '"', 'updated' => stamp($S), 'upd' => $S['seq'],
                'start' => $b['start'], 'end' => $b['end']] + (isset($b['recurringEventId']) ? ['recurringEventId' => $b['recurringEventId']] : []);
            out(200, ['id' => $id]);
        case 'edit_event':
            $e = &$S['calendars'][$b['calendar'] ?? 'primary@test']['events'][$b['id']];
            foreach (['summary', 'start', 'end'] as $k) if (isset($b[$k])) $e[$k] = $b[$k];
            $e['updated'] = stamp($S); $e['upd'] = $S['seq']; $e['etag'] = '"e' . $S['seq'] . '"';
            out(200, ['ok' => true]);
        case 'quota': $S['quota'] = (int)($b['times'] ?? 1); out(200, ['ok' => true]);
        case 'purge_event': $S['calendars'][$b['calendar'] ?? 'primary@test']['events'][$b['id']]['purged'] = true; out(200, ['ok' => true]);
        case 'add_calendar': $S['calendars'][$b['id']] = ['id' => $b['id'], 'summary' => $b['summary'] ?? $b['id'], 'backgroundColor' => '#9e69af', 'accessRole' => $b['accessRole'] ?? 'writer', 'events' => []]; out(200, ['ok' => true]);
        case 'delete_event':
            $e = &$S['calendars'][$b['calendar'] ?? 'primary@test']['events'][$b['id']];
            $e['status'] = 'cancelled'; $e['updated'] = stamp($S); $e['upd'] = $S['seq'];
            out(200, ['ok' => true]);
        case 'add_task':
            $id = $b['id'] ?? ('t' . $S['seq']);
            $S['lists'][$b['list'] ?? 'L1']['tasks'][$id] = ['id' => $id, 'title' => $b['title'], 'status' => 'needsAction', 'etag' => '"t' . $S['seq'] . '"', 'updated' => stamp($S), 'position' => sprintf('%020d', $S['seq'])] + (isset($b['due']) ? ['due' => $b['due']] : []) + (isset($b['parent']) ? ['parent' => $b['parent']] : []);
            out(200, ['id' => $id]);
        case 'edit_task':
            $t = &$S['lists'][$b['list'] ?? 'L1']['tasks'][$b['id']];
            foreach (['title', 'status', 'notes', 'due', 'deleted'] as $k) if (array_key_exists($k, $b)) $t[$k] = $b[$k];
            $t['updated'] = stamp($S); $t['etag'] = '"t' . $S['seq'] . '"';
            out(200, ['ok' => true]);
    }
    out(400, ['error' => 'unknown action']);
}

// ---- OAuth ---------------------------------------------------------------------------------
if ($path === '/o/oauth2/v2/auth') {
    // consent screen: the test «person» accepts (or refuses, with deny)
    foreach (['client_id', 'redirect_uri', 'state', 'scope', 'code_challenge'] as $k) if (empty($Q[$k])) out(400, ['error' => "missing $k"]);
    $sep = str_contains($Q['redirect_uri'], '?') ? '&' : '?';
    if ($S['deny']) { header('Location: ' . $Q['redirect_uri'] . $sep . http_build_query(['error' => 'access_denied', 'state' => $Q['state']])); exit; }
    $code = 'code-' . bin2hex(random_bytes(6));
    $S['codes'][$code] = ['challenge' => $Q['code_challenge'], 'scope' => $Q['scope'], 'redirect' => $Q['redirect_uri']];
    header('Location: ' . $Q['redirect_uri'] . $sep . http_build_query(['code' => $code, 'state' => $Q['state'], 'scope' => $Q['scope']]));
    exit;
}
if ($path === '/token' && $m === 'POST') {
    $b = body();
    if (($b['client_id'] ?? '') === '' || ($b['client_secret'] ?? '') === '') out(401, ['error' => 'invalid_client']);
    if (($b['grant_type'] ?? '') === 'authorization_code') {
        $c = $S['codes'][$b['code'] ?? ''] ?? null;
        unset($S['codes'][$b['code'] ?? '']);
        if (!$c) out(400, ['error' => 'invalid_grant', 'error_description' => 'Bad code']);
        $calc = rtrim(strtr(base64_encode(hash('sha256', (string)($b['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
        if (!hash_equals($c['challenge'], $calc)) out(400, ['error' => 'invalid_grant', 'error_description' => 'PKCE mismatch']);
        if ($c['redirect'] !== ($b['redirect_uri'] ?? '')) out(400, ['error' => 'redirect_uri_mismatch']);
        $at = 'at-' . bin2hex(random_bytes(6)); $rt = 'rt-' . bin2hex(random_bytes(6));
        $S['tokens'][$at] = true; $S['refresh'][$rt] = true; $S['revoked'] = false;
        out(200, ['access_token' => $at, 'refresh_token' => $rt, 'expires_in' => 3599, 'scope' => $c['scope'], 'token_type' => 'Bearer']);
    }
    if (($b['grant_type'] ?? '') === 'refresh_token') {
        if ($S['revoked'] || !isset($S['refresh'][$b['refresh_token'] ?? ''])) out(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']);
        $at = 'at-' . bin2hex(random_bytes(6));
        $S['tokens'][$at] = true;
        out(200, ['access_token' => $at, 'expires_in' => 3599, 'token_type' => 'Bearer']);
    }
    out(400, ['error' => 'unsupported_grant_type']);
}
if ($path === '/revoke' && $m === 'POST') { $S['revoked'] = true; $S['tokens'] = []; $S['refresh'] = []; out(200, []); }
if ($path === '/userinfo') { auth($S); out(200, ['sub' => '1234', 'email' => 'cal@gmail.test', 'email_verified' => true]); }

// ---- Calendar v3 -----------------------------------------------------------------------------
if ($path === '/calendar/v3/users/me/calendarList') {
    auth($S);
    out(200, ['items' => array_values(array_map(fn($c) => array_diff_key($c, ['events' => 1]), $S['calendars']))]);
}
if (preg_match('#^/calendar/v3/calendars/([^/]+)/events(?:/([^/]+))?$#', $path, $mm)) {
    auth($S);
    $cid = rawurldecode($mm[1]);
    if (!isset($S['calendars'][$cid])) out(404, ['error' => ['code' => 404, 'message' => 'Not Found']]);
    $cal = &$S['calendars'][$cid];
    $eid = isset($mm[2]) ? rawurldecode($mm[2]) : null;
    $pub = fn($e) => array_diff_key($e, ['upd' => 1]);
    if ($eid === null && $m === 'GET') {
        $since = 0;
        if (!empty($Q['syncToken'])) {
            if (!preg_match('/^tok-(\d+)$/', $Q['syncToken'], $t) || (int)$t[1] < 5) out(410, ['error' => ['code' => 410, 'message' => 'Sync token is no longer valid', 'errors' => [['reason' => 'fullSyncRequired']]]]);
            $since = (int)$t[1];
        }
        $items = array_values(array_filter($cal['events'], fn($e) => $e['upd'] > $since && empty($e['purged']) && ($since > 0 || $e['status'] !== 'cancelled' || (!empty($Q['showDeleted']) && $Q['showDeleted'] !== 'false'))));
        // 1.9: privateExtendedProperty=key=value (repeatable, all must match), like Google
        foreach (explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if ($k !== 'privateExtendedProperty') continue;
            [$pk, $pv] = array_pad(explode('=', rawurldecode($v), 2), 2, '');
            $items = array_values(array_filter($items, fn($e) => (string)($e['extendedProperties']['private'][$pk] ?? "\0") === $pv));
        }
        // pages of 2 to exercise pageToken
        $off = (int)($Q['pageToken'] ?? 0);
        $page = array_slice($items, $off, 2);
        $res = ['items' => array_map($pub, $page)];
        if ($off + 2 < count($items)) $res['nextPageToken'] = (string)($off + 2); else $res['nextSyncToken'] = 'tok-' . $S['seq'];
        out(200, $res);
    }
    if (in_array($cal['accessRole'], ['reader', 'freeBusyReader'], true) && $m !== 'GET') out(403, ['error' => ['code' => 403, 'message' => 'Forbidden', 'errors' => [['reason' => 'requiredAccessLevel']]]]);
    if ($eid === null && $m === 'POST') {
        $b = body();
        // 1.9: a client-chosen id (base32hex, 5-1024) like Google; an existing one (even deleted) answers 409
        if (isset($b['id'])) {
            if (!preg_match('/^[a-v0-9]{5,1024}$/', (string)$b['id'])) out(400, ['error' => ['code' => 400, 'message' => 'Invalid resource id value.', 'errors' => [['reason' => 'invalid']]]]);
            if (isset($cal['events'][$b['id']])) out(409, ['error' => ['code' => 409, 'message' => 'The requested identifier already exists.', 'errors' => [['reason' => 'duplicate']]]]);
        }
        if ($S['quota'] ?? 0) { $S['quota']--; out(403, ['error' => ['code' => 403, 'message' => 'Rate Limit Exceeded', 'errors' => [['reason' => 'rateLimitExceeded']]]]); }
        $id = $b['id'] ?? ('b' . $S['seq'] . bin2hex(random_bytes(2)));
        $cal['events'][$id] = $b + ['id' => $id, 'status' => 'confirmed', 'etag' => '"e' . $S['seq'] . '"', 'updated' => stamp($S), 'upd' => $S['seq']];
        $cal['events'][$id]['etag'] = '"e' . $S['seq'] . '"';
        out(200, $pub($cal['events'][$id]));
    }
    // like Google, a deleted event can still be read and updated (PATCH with status confirmed brings it back)
    if (!isset($cal['events'][$eid]) || ($m === 'DELETE' && $cal['events'][$eid]['status'] === 'cancelled') || (!empty($cal['events'][$eid]['purged']))) out($m === 'DELETE' && isset($cal['events'][$eid]) ? 410 : 404, ['error' => ['code' => 404, 'message' => 'Not Found']]);
    $e = &$cal['events'][$eid];
    if ($m === 'GET') out(200, $pub($e));
    if ($m === 'PATCH') {
        $im = (string)($_SERVER['HTTP_IF_MATCH'] ?? '');
        if ($im !== '' && $im !== $e['etag']) out(412, ['error' => ['code' => 412, 'message' => 'Precondition Failed', 'errors' => [['reason' => 'conditionNotMet']]]]);
        foreach (body() as $k => $v) $e[$k] = $v;
        $e['updated'] = stamp($S); $e['upd'] = $S['seq']; $e['etag'] = '"e' . $S['seq'] . '"';
        out(200, $pub($e));
    }
    if ($m === 'DELETE') { $e['status'] = 'cancelled'; $e['updated'] = stamp($S); $e['upd'] = $S['seq']; http_response_code(204); exit; }
}

// ---- Tasks v1 -------------------------------------------------------------------------------------
if ($path === '/tasks/v1/users/@me/lists') {
    auth($S);
    if ($m === 'POST') { $b = body(); $id = 'L' . $S['seq']; $S['lists'][$id] = ['id' => $id, 'title' => $b['title'], 'etag' => '"l' . $S['seq'] . '"', 'tasks' => []]; stamp($S); out(200, array_diff_key($S['lists'][$id], ['tasks' => 1])); }
    out(200, ['items' => array_values(array_map(fn($l) => array_diff_key($l, ['tasks' => 1]), $S['lists']))]);
}
if (preg_match('#^/tasks/v1/lists/([^/]+)/tasks(?:/([^/]+))?$#', $path, $mm)) {
    auth($S);
    $lid = rawurldecode($mm[1]);
    if (!isset($S['lists'][$lid])) out(404, ['error' => ['code' => 404, 'message' => 'Not Found']]);
    $l = &$S['lists'][$lid];
    $tid = isset($mm[2]) ? rawurldecode($mm[2]) : null;
    if ($tid === null && $m === 'GET') {
        $items = array_values($l['tasks']);
        $off = (int)($Q['pageToken'] ?? 0);
        $res = ['items' => array_slice($items, $off, 3)];
        if ($off + 3 < count($items)) $res['nextPageToken'] = (string)($off + 3);
        out(200, $res);
    }
    if ($tid === null && $m === 'POST') {
        $b = body();
        $id = 'g' . $S['seq'] . bin2hex(random_bytes(2));
        $l['tasks'][$id] = array_filter($b, fn($v) => $v !== null) + ['id' => $id, 'etag' => '"t' . $S['seq'] . '"', 'updated' => stamp($S), 'position' => sprintf('%020d', $S['seq'])] + (!empty($Q['parent']) ? ['parent' => $Q['parent']] : []);
        out(200, $l['tasks'][$id]);
    }
    if (!isset($l['tasks'][$tid])) out(404, ['error' => ['code' => 404, 'message' => 'Not Found']]);
    $t = &$l['tasks'][$tid];
    if ($m === 'PATCH') { foreach (body() as $k => $v) { if ($v === null) unset($t[$k]); else $t[$k] = $v; } $t['updated'] = stamp($S); $t['etag'] = '"t' . $S['seq'] . '"'; out(200, $t); }
    if ($m === 'GET') out(200, $t);
}
out(404, ['error' => ['code' => 404, 'message' => "No route $m $path"]]);
