<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Auth;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\Calendars;
use Borealis\Services\Events;
use Borealis\Services\Google;
use Borealis\Services\GoogleCalendar;
use Borealis\Services\GoogleError;
use Borealis\Services\GoogleExport;
use Borealis\Services\GoogleTasks;
use Borealis\Services\PersonalTasks;

/** 1.6: calendars, events and meetings, personal tasks and the Google connection. */
final class CalendarApi
{
    // ---- events --------------------------------------------------------------------

    public function showEvent(Request $r): Response
    {
        $e = Events::find($r->intParam('id'));
        $allDay = (bool)$e['all_day'];
        $endDate = $allDay ? date('Y-m-d', strtotime(substr($e['ends_at'], 0, 10) . ' -1 day')) : substr($e['local_end'], 0, 10);
        return Response::ok(['event' => [
            'id' => (int)$e['id'], 'kind' => $e['kind'], 'title' => $e['title'], 'description' => (string)$e['description'], 'location' => (string)$e['location'], 'video_url' => (string)$e['video_url'],
            'all_day' => $allDay, 'start_date' => $allDay ? substr($e['starts_at'], 0, 10) : substr($e['local_start'], 0, 10), 'start_time' => $allDay ? '' : substr($e['local_start'], 11, 5),
            'end_date' => $endDate, 'end_time' => $allDay ? '' : substr($e['local_end'], 11, 5), 'recurrence' => $e['recurrence'], 'recurrence_until' => (string)$e['recurrence_until'],
            'reminder_minutes' => $e['reminder_minutes'] === null ? '' : (string)$e['reminder_minutes'], 'project_id' => $e['project_id'] ? (int)$e['project_id'] : '', 'team_id' => $e['team_id'] ? (int)$e['team_id'] : '',
            'calendar_id' => (int)$e['calendar_id'], 'calendar_name' => $e['calendar_name'], 'color' => $e['color'], 'source' => $e['source'], 'status' => $e['status'], 'can_edit' => (bool)$e['can_edit'],
            'google_recurring' => (bool)$e['google_recurring'], 'when' => Events::when($e), 'participant_ids' => array_map(fn($p) => (int)$p['id'], $e['participants']), 'participants' => array_column($e['participants'], 'name'),
        ]], '');
    }

    public function createEvent(Request $r): Response
    {
        $id = Events::create($r->input());
        return Response::ok(['id' => $id], ($r->input('kind') === 'meeting' ? 'Reunión creada' : 'Evento creado'));
    }

    public function updateEvent(Request $r): Response
    {
        Events::update($r->intParam('id'), $r->input());
        return Response::ok([], 'Cambios guardados');
    }

    /** Drag & drop to another day: keeps the time and the length. */
    public function moveEvent(Request $r): Response
    {
        $e = Events::find($r->intParam('id'));
        $to = (string)$r->input('date');
        if (!\Borealis\Core\Validator::isDate($to)) throw HttpException::invalid(['date' => 'Fecha no válida.']);
        if ($e['recurrence'] !== 'none') throw HttpException::conflict('Es un evento que se repite: ábrelo para cambiar su fecha.');
        $from = $e['all_day'] ? substr($e['starts_at'], 0, 10) : substr($e['local_start'], 0, 10);
        $days = (int)round((strtotime($to) - strtotime($from)) / 86400);
        if ($days === 0) return Response::ok([], '');
        $shift = fn(string $d) => date('Y-m-d', strtotime($d . " $days days"));
        $endDate = $e['all_day'] ? date('Y-m-d', strtotime(substr($e['ends_at'], 0, 10) . ' -1 day')) : substr($e['local_end'], 0, 10);
        Events::update((int)$e['id'], ['title' => $e['title'], 'kind' => $e['kind'], 'description' => $e['description'], 'location' => $e['location'], 'video_url' => $e['video_url'],
            'all_day' => $e['all_day'] ? '1' : '0', 'start_date' => $shift($e['all_day'] ? $from : substr($e['local_start'], 0, 10)), 'start_time' => $e['all_day'] ? '' : substr($e['local_start'], 11, 5),
            'end_date' => $shift($endDate), 'end_time' => $e['all_day'] ? '' : substr($e['local_end'], 11, 5), 'recurrence' => 'none', 'reminder_minutes' => $e['reminder_minutes'],
            'project_id' => $e['project_id'], 'team_id' => $e['team_id']]);
        return Response::ok([], 'Fecha actualizada');
    }

    public function cancelEvent(Request $r): Response
    {
        Events::cancel($r->intParam('id'));
        return Response::ok([], 'Cancelado. Se avisó a los participantes.');
    }

    public function restoreEvent(Request $r): Response
    {
        Events::restore($r->intParam('id'));
        return Response::ok([], 'Reactivado');
    }

    public function deleteEvent(Request $r): Response
    {
        Events::cancel($r->intParam('id'), true);
        return Response::ok([], 'Eliminado');
    }

    // ---- calendars -------------------------------------------------------------------

    public function createCalendar(Request $r): Response
    {
        return Response::ok(['id' => Calendars::create($r->input())], 'Calendario creado');
    }

    public function updateCalendar(Request $r): Response
    {
        Calendars::update($r->intParam('id'), $r->input());
        return Response::ok([], 'Calendario guardado');
    }

    public function deleteCalendar(Request $r): Response
    {
        $move = (int)$r->input('move_to', 0);
        $res = Calendars::delete($r->intParam('id'), $move ?: null);
        return Response::ok($res, $res['events'] ? ($res['moved'] ? "Calendario eliminado; sus {$res['events']} eventos se movieron" : "Calendario eliminado junto con sus {$res['events']} eventos") : 'Calendario eliminado');
    }

    public function showCalendar(Request $r): Response
    {
        Calendars::setShown($r->intParam('id'), in_array($r->input('shown'), [true, 1, '1', 'true', 'on'], true));
        return Response::ok([], '');
    }

    public function calendarMember(Request $r): Response
    {
        Calendars::setMember($r->intParam('id'), (int)$r->input('user_id'), (string)$r->input('access', 'read'));
        return Response::ok([], 'Acceso guardado');
    }

    public function layer(Request $r): Response
    {
        Calendars::setLayer((string)$r->input('layer'), in_array($r->input('shown'), [true, 1, '1', 'true', 'on'], true));
        return Response::ok([], '');
    }

    // ---- personal tasks ----------------------------------------------------------------

    public function createMyTask(Request $r): Response
    {
        $t = PersonalTasks::create($r->input());
        return Response::ok(['task' => ['id' => (int)$t['id']]], $t['parent_id'] ? 'Subtarea añadida' : 'Tarea añadida');
    }

    public function updateMyTask(Request $r): Response
    {
        $t = PersonalTasks::update($r->intParam('id'), $r->input());
        $msg = array_key_exists('done', $r->input()) ? ($t['completed_at'] ? 'Tarea completada' : 'Tarea reabierta') : 'Guardado';
        return Response::ok(['task' => ['id' => (int)$t['id'], 'done' => $t['completed_at'] !== null]], $msg);
    }

    public function deleteMyTask(Request $r): Response
    {
        PersonalTasks::delete($r->intParam('id'));
        return Response::ok([], 'Tarea eliminada');
    }

    public function createMyList(Request $r): Response
    {
        $id = PersonalTasks::createList($r->input());
        return Response::ok(['id' => $id, 'redirect' => url('/tasks', ['list' => $id])], 'Lista creada');
    }

    public function updateMyList(Request $r): Response
    {
        PersonalTasks::renameList($r->intParam('id'), $r->input());
        return Response::ok([], 'Lista guardada');
    }

    public function deleteMyList(Request $r): Response
    {
        PersonalTasks::deleteList($r->intParam('id'));
        return Response::ok(['redirect' => url('/tasks')], 'Lista eliminada');
    }

    public function reorderMyList(Request $r): Response
    {
        PersonalTasks::reorder($r->intParam('id'), (array)$r->input('ids', []), $r->input('parent_id') ? (int)$r->input('parent_id') : null);
        return Response::ok([], 'Orden guardado');
    }

    public function linkMyList(Request $r): Response
    {
        $n = GoogleTasks::link($r->intParam('id'));
        return Response::ok(['sync' => $n], 'Lista enviada a Google Tasks');
    }

    // ---- Google ---------------------------------------------------------------------------

    public function googleCalendars(Request $r): Response
    {
        return Response::ok(['calendars' => GoogleCalendar::available()], '');
    }

    public function googleChoose(Request $r): Response
    {
        $n = GoogleCalendar::choose((array)$r->input('calendars', []));
        return Response::ok(['sync' => $n], 'Calendarios de Google guardados' . self::summary($n));
    }

    /** «Sincronizar ahora» (also called by the pages when the last sync is older than 10 minutes). */
    public function googleSync(Request $r): Response
    {
        if (!Google::account()) throw HttpException::conflict('No tienes una cuenta de Google conectada.');
        $c = GoogleCalendar::syncAll();
        $t = GoogleTasks::sync();
        $msg = 'Sincronizado con Google' . self::summary(['created' => $c['created'] + $t['created'], 'updated' => $c['updated'] + $t['updated'], 'removed' => $c['removed']]);
        if ($t['conflicts']) $msg .= '. ' . ($t['conflicts'] === 1 ? '1 tarea cambió en ambos lados: se conservó la versión de Google' : "{$t['conflicts']} tareas cambiaron en ambos lados: se conservó la versión de Google");
        return Response::ok(['calendar' => $c, 'tasks' => $t], $msg);
    }

    private static function summary(array $n): string
    {
        $parts = [];
        if (!empty($n['created'])) $parts[] = $n['created'] . ' nuevos';
        if (!empty($n['updated'])) $parts[] = $n['updated'] . ' actualizados';
        if (!empty($n['removed'])) $parts[] = $n['removed'] . ' quitados';
        return $parts ? ' (' . implode(', ', $parts) . ')' : '';
    }

    public function googleDisconnect(Request $r): Response
    {
        $removed = 0;
        if (in_array($r->input('remove_events'), [true, 1, '1', 'on', 'true'], true)) {
            try { $removed = GoogleExport::stop(true)['removed']; }
            catch (GoogleError $e) { throw new GoogleError('No se pudieron quitar los eventos de Google (' . $e->getMessage() . '). No se desconectó; inténtalo de nuevo o desconecta sin quitarlos.', $e->googleStatus); }
        } else {
            GoogleExport::stop(false);
        }
        Google::disconnect();
        return Response::ok(['removed' => $removed], $removed ? "Google desconectado. Se quitaron de tu calendario los $removed eventos que había creado Borealis; nada más se tocó." : 'Google desconectado. Tus datos en Google no se tocaron.');
    }

    // ---- 1.9: Borealis → Google Calendar ------------------------------------------------

    public function exportStatus(Request $r): Response
    {
        $st = GoogleExport::status();
        $cals = [];
        if (in_array($st['state'], ['off', 'ok', 'pending', 'error'], true)) {
            try { $cals = GoogleExport::writableCalendars(); } catch (GoogleError $e) { $st['calendars_error'] = $e->getMessage(); }
        }
        return Response::ok(['status' => $st, 'calendars' => $cals], '');
    }

    public function exportConfigure(Request $r): Response
    {
        $n = GoogleExport::configure($r->input());
        return Response::ok(['sync' => $n, 'status' => GoogleExport::status()], self::exportMsg($n, 'Listo: tu trabajo de Borealis se envía a Google Calendar'));
    }

    public function exportSync(Request $r): Response
    {
        if (!Google::account()) throw HttpException::conflict('No tienes una cuenta de Google conectada.');
        $n = GoogleExport::run(Auth::id(), true);
        return Response::ok(['sync' => $n, 'status' => GoogleExport::status()], self::exportMsg($n, 'Google Calendar al día'));
    }

    public function exportStop(Request $r): Response
    {
        $remove = in_array($r->input('remove'), [true, 1, '1', 'on', 'true'], true);
        $n = GoogleExport::stop($remove);
        return Response::ok(['removed' => $n['removed'], 'status' => GoogleExport::status()], $remove ? "Ya no se envía nada a Google Calendar. Se quitaron {$n['removed']} eventos creados por Borealis." : 'Ya no se envía nada a Google Calendar. Los eventos ya enviados siguen en tu calendario.');
    }

    private static function exportMsg(array $n, string $ok): string
    {
        $parts = [];
        if ($n['created']) $parts[] = $n['created'] . ' nuevos';
        if ($n['updated']) $parts[] = $n['updated'] . ' actualizados';
        if ($n['removed']) $parts[] = $n['removed'] . ' quitados';
        $msg = $ok . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
        return $n['pending'] ? $msg . '. Quedan ' . $n['pending'] . ' por enviar; seguirán en unos minutos.' : $msg . '.';
    }
}
