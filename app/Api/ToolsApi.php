<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\Integrations;
use Borealis\Services\Mailer;
use Borealis\Services\Notifications;
use Borealis\Services\Push;
use Borealis\Services\Sprints;

/** 1.8: notification preferences, mobile notifications, project tools and integrations. */
final class ToolsApi
{
    // ---- notifications -------------------------------------------------------------

    public function savePrefs(Request $r): Response
    {
        return Response::ok(['notify' => Notifications::savePrefs($r->input())], 'Preferencias de notificaciones guardadas');
    }

    /** 1.9: Administración › Correo › «Enviar una prueba» (only the installation's administration). */
    public function mailTest(Request $r): Response
    {
        if (!\Borealis\Security\AccountPolicy::isInstanceAdmin()) throw HttpException::forbidden('Solo la administración de la instalación puede probar el correo.');
        if (!Mailer::configured()) throw HttpException::conflict('El correo no está configurado: añade la sección «mail» en config/config.php (README › Correo).');
        $to = (string)Auth::user()['email'];
        try {
            Mailer::send($to, 'Borealis · correo de prueba', "Hola,\n\nEste es un correo de prueba de Borealis. Si lo recibes, los avisos por correo funcionan.\n\n" . absolute_url('/'));
        } catch (\RuntimeException $e) {
            throw HttpException::conflict('No se pudo enviar: ' . $e->getMessage());
        }
        return Response::ok([], "Correo de prueba enviado a $to. Revisa la bandeja de entrada (y la de spam).");
    }

    public function pushKey(Request $r): Response
    {
        if (!Push::available()) throw HttpException::conflict('Este servidor no puede enviar notificaciones móviles (falta OpenSSL con curvas EC o cURL).');
        return Response::ok(['key' => Push::publicKey()], '');
    }

    public function subscribe(Request $r): Response
    {
        Push::subscribe($r->input());
        return Response::ok(['devices' => count(Push::devices(Auth::id()))], 'Notificaciones activadas en este dispositivo');
    }

    public function unsubscribe(Request $r): Response
    {
        $n = Push::unsubscribe($r->input('endpoint') ? (string)$r->input('endpoint') : null);
        return Response::ok(['removed' => $n], $n ? 'Notificaciones desactivadas en este dispositivo' : 'Este dispositivo no tenía notificaciones activadas');
    }

    /** «Enviar una prueba»: one message to each device of the person, right now. */
    public function test(Request $r): Response
    {
        $subs = Database::get()->all('SELECT * FROM push_subscriptions WHERE user_id = ?', [Auth::id()]);
        if (!$subs) throw HttpException::invalid(['push' => 'Primero activa las notificaciones en este dispositivo.']);
        $ok = 0;
        $payload = json_encode(['title' => 'Borealis', 'body' => 'Las notificaciones funcionan en este dispositivo.', 'url' => url('/profile') . '#notificaciones', 'tag' => 'borealis-test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subs as $s) if (Push::deliver($s, $payload, 'normal')) $ok++;
        if (!$ok) {
            $err = (string)Database::get()->value('SELECT last_error FROM push_subscriptions WHERE user_id = ? AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1', [Auth::id()]);
            throw HttpException::invalid(['push' => $err ?: 'El servicio de notificaciones del navegador no aceptó el mensaje.']);
        }
        return Response::ok(['sent' => $ok], $ok === 1 ? 'Prueba enviada' : "Prueba enviada a $ok dispositivos");
    }

    /** For the service worker when a message arrives without content: the newest unread notices. */
    public function pending(Request $r): Response
    {
        $rows = Database::get()->all('SELECT id, type, message, url FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY id DESC LIMIT 3', [Auth::id()]);
        return Response::ok(['items' => array_map(fn($n) => ['id' => (int)$n['id'], 'title' => Notifications::TYPES[$n['type']] ?? 'Borealis', 'body' => $n['message'], 'url' => $n['url'] ?: url('/notifications')], $rows)], '');
    }

    // ---- project tools ---------------------------------------------------------------

    public function addLink(Request $r): Response
    {
        $id = Integrations::addLink($r->intParam('id'), $r->input());
        return Response::ok(['id' => $id], 'Enlace añadido');
    }

    public function removeLink(Request $r): Response
    {
        Integrations::removeLink($r->intParam('id'));
        return Response::ok([], 'Enlace quitado');
    }

    public function saveDiscord(Request $r): Response
    {
        Integrations::saveDiscord($r->intParam('id'), $r->input());
        return Response::ok([], 'Discord guardado');
    }

    public function testDiscord(Request $r): Response
    {
        Integrations::testDiscord($r->intParam('id'));
        return Response::ok([], 'Mensaje de prueba publicado en Discord');
    }

    public function saveGithub(Request $r): Response
    {
        Integrations::saveGithub($r->intParam('id'), $r->input());
        $g = Integrations::github($r->intParam('id'));
        return Response::ok(['github' => $g], $g && $g['error'] ? 'Repositorio guardado, pero: ' . $g['error'] : 'Repositorio vinculado');
    }

    public function refreshGithub(Request $r): Response
    {
        $p = \Borealis\Services\Projects::find($r->intParam('id'));
        $g = Integrations::github((int)$p['id'], true) ?? throw HttpException::notFound('El proyecto no tiene un repositorio vinculado.');
        return Response::ok(['github' => $g], $g['error'] ?: 'Actualizado');
    }

    public function removeIntegration(Request $r): Response
    {
        $prov = (string)$r->param('provider');
        if (!in_array($prov, ['discord', 'github'], true)) throw HttpException::notFound();
        Integrations::remove($r->intParam('id'), $prov);
        return Response::ok([], 'Desconectado. Borealis sigue funcionando igual.');
    }
}
