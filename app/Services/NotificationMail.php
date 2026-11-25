<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;

/**
 * Notices by e-mail (1.9). Sent by bin/notify.php (cron), never while a page is loading.
 *
 *  - Only categories the person chose for e-mail, only if «Correo» is on in their profile, only
 *    if they have a valid address and the installation can send e-mail (Mailer::configured()).
 *  - A notice already read in Borealis is not e-mailed. It waits a couple of minutes first
 *    (mail.delay_minutes, default 2), so someone working in Borealis is not mailed what they just saw.
 *  - Quiet hours: nothing is mailed; it goes out when they end, in one message.
 *  - Several pending notices of one person go in ONE e-mail (grouping), each with its link.
 *  - Completed tasks, deleted tasks and reminders for a date that changed are dropped.
 *  - Each notice is mailed at most once (notifications.emailed_at, claimed before sending).
 */
final class NotificationMail
{
    private static bool $hooked = false;

    /**
     * 1.16.2 · e-mails go out right after the request that created the notice (an assignment, a
     * change), not only when the scheduled job runs; the job still sends what is left.
     */
    public static function later(int $userId): void
    {
        if (self::$hooked || PHP_SAPI === 'cli' || !Mailer::configured()) return;
        self::$hooked = true;
        register_shutdown_function(function () {
            \Borealis\Core\Background::detach();
            try { self::flush(); } catch (\Throwable $e) { error_log('[Borealis] correo: ' . $e->getMessage()); }
        });
    }

    public static function flush(?int $userId = null): array
    {
        $out = ['emails' => 0, 'notices' => 0, 'errors' => 0];
        if (!Mailer::configured()) return $out;
        $db = Database::get();
        $delay = max(0, (int)MailSettings::get('delay_minutes', 0)); // 1.16.2: 0 = at once (before: 2 minutes)
        $sql = 'SELECT n.* FROM notifications n JOIN users u ON u.id = n.user_id AND u.is_active = 1
            WHERE n.emailed_at IS NULL AND n.read_at IS NULL AND n.created_at >= ? AND n.created_at <= ?' . ($userId ? ' AND n.user_id = ?' : '') . ' ORDER BY n.user_id, n.id LIMIT 1000';
        $params = [gmdate('Y-m-d H:i:s', time() - 2 * 86400), gmdate('Y-m-d H:i:s', time() - $delay * 60)];
        if ($userId) $params[] = $userId;
        $byUser = [];
        foreach ($db->all($sql, $params) as $n) $byUser[(int)$n['user_id']][] = $n;
        foreach ($byUser as $uid => $list) {
            $to = UserTime::emailOf($uid);
            if (!$to || UserTime::isQuiet($uid)) continue;
            $send = [];
            foreach ($list as $n) {
                if ($db->run('UPDATE notifications SET emailed_at = ? WHERE id = ? AND emailed_at IS NULL', [now(), $n['id']])->rowCount() !== 1) continue;
                if (!Notifications::wantsEmail($uid, (string)$n['type']) || !Notifications::stillRelevant($n)) continue;
                $send[] = $n;
            }
            if (!$send) continue;
            [$subject, $text, $html] = self::compose($uid, $send);
            try {
                Mailer::send($to, $subject, $text, $html);
                $out['emails']++;
                $out['notices'] += count($send);
            } catch (\Throwable $e) {
                // let them go again next time (a server down must not lose the notices)
                $ids = array_map(fn($n) => (int)$n['id'], $send);
                $db->run('UPDATE notifications SET emailed_at = NULL WHERE id IN (' . Database::in($ids) . ')', $ids);
                $out['errors']++;
                error_log('[Borealis] correo: ' . $e->getMessage());
            }
        }
        return $out;
    }

    private static function origin(): string
    {
        $abs = absolute_url('/');
        $rel = url('/');
        return str_ends_with($abs, $rel) ? substr($abs, 0, -strlen($rel)) : rtrim($abs, '/');
    }

    /** [subject, text, html] for one or several notices. */
    public static function compose(int $uid, array $list): array
    {
        $origin = self::origin();
        $link = fn($n) => $n['url'] ? (preg_match('#^https?://#', (string)$n['url']) ? (string)$n['url'] : $origin . $n['url']) : absolute_url('/notifications');
        $prefs = absolute_url('/profile') . '#notificaciones';
        if (count($list) === 1) {
            $n = $list[0];
            $subject = 'Borealis · ' . (Notifications::TYPES[$n['type']] ?? 'Aviso') . ': ' . mb_substr($n['message'], 0, 120);
        } else {
            $subject = 'Borealis · ' . count($list) . ' avisos nuevos';
        }
        $text = "Hola,\n\n" . (count($list) === 1 ? "Tienes un aviso nuevo en Borealis:\n\n" : 'Tienes ' . count($list) . " avisos nuevos en Borealis:\n\n");
        $items = '';
        foreach ($list as $n) {
            $text .= '• ' . $n['message'] . "\n  " . $link($n) . "\n\n";
            $items .= '<li style="margin:0 0 12px"><strong>' . e(Notifications::TYPES[$n['type']] ?? 'Aviso') . '</strong><br>' . e($n['message'])
                . '<br><a href="' . e($link($n)) . '" style="color:#1f6f50">Abrir en Borealis</a></li>';
        }
        $text .= "Puedes elegir qué avisos te llegan por correo en tu perfil: $prefs\n";
        $html = '<!doctype html><html lang="es"><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#142820;background:#f4f7f5;padding:24px">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;border:1px solid #dfe7e2">'
            . '<p style="margin:0 0 16px;font-size:18px;font-weight:600">Borealis</p><ul style="padding-left:18px;margin:0 0 16px">' . $items . '</ul>'
            . '<p style="font-size:13px;color:#5b6b63;margin:16px 0 0">Elige qué avisos te llegan por correo en <a href="' . e($prefs) . '" style="color:#1f6f50">tu perfil</a>.</p></div></body></html>';
        return [$subject, $text, $html];
    }
}
