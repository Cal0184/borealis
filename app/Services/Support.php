<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * 1.16.3 · «Correo al soporte»: a button (account menu, sidebar and sign-in page) that opens the
 * person's own mail program with a message to the support address already written: subject,
 * a short template and the details support needs (who, workspace, page, version, browser).
 * Borealis sends nothing itself, so it works even without the e-mail settings.
 *
 * The address is set in Administración › Ajustes › Soporte (saved in the workspace settings);
 * config.php 'support' => ['email' => …] is the fallback for the whole installation.
 */
final class Support
{
    /** ['email' => ?string, 'subject' => string] for the current workspace. */
    public static function settings(?array $ws = null): array
    {
        $ws ??= Policy::workspace();
        $saved = (array)(($ws['settings']['support'] ?? null) ?: []);
        $email = trim((string)($saved['email'] ?? '')) ?: trim((string)config('support.email', ''));
        return ['email' => $email !== '' ? $email : null, 'subject' => trim((string)($saved['subject'] ?? '')) ?: 'Soporte Borealis'];
    }

    public static function save(array $input): array
    {
        Policy::authorize('workspace.manage');
        $v = Validator::check($input, ['email' => 'nullable,email,max:190', 'subject' => 'nullable,string,max:120']);
        $db = Database::get();
        $settings = json_decode((string)$db->value('SELECT settings FROM workspaces WHERE id = ?', [Policy::wsId()]), true) ?: [];
        $old = (array)($settings['support'] ?? []);
        $new = ['email' => mb_strtolower(trim((string)($v['email'] ?? ''))), 'subject' => trim((string)($v['subject'] ?? ''))];
        $settings['support'] = $new;
        $db->update('workspaces', ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()], 'id = ?', [Policy::wsId()]);
        Activity::log('settings', 'workspace', Policy::wsId(), $new['email'] !== '' ? "Configuró el correo de soporte: {$new['email']}" : 'Quitó el correo de soporte', null, null, [], null,
            ['category' => 'settings', 'before' => $old, 'after' => $new]);
        Policy::reset();
        return self::settings(Policy::workspace());
    }

    /** Address shown on the sign-in page (no workspace yet): config.php, else the first workspace that has one. */
    public static function installationEmail(): ?string
    {
        $cfg = trim((string)config('support.email', ''));
        if ($cfg !== '') return $cfg;
        try {
            foreach (Database::get()->column('SELECT settings FROM workspaces ORDER BY id') as $json) {
                $e = trim((string)((json_decode((string)$json, true) ?: [])['support']['email'] ?? ''));
                if ($e !== '') return $e;
            }
        } catch (\Throwable) {
            // not installed yet
        }
        return null;
    }

    /** mailto: link with the subject and a body that already carries the context support needs. */
    public static function mailto(string $email, string $subject = 'Soporte Borealis', ?array $user = null, ?array $ws = null, ?string $page = null): string
    {
        $user ??= Auth::user();
        $lines = ['Hola, necesito ayuda con Borealis.', '', '¿Qué pasó?', '', '', '¿Qué esperabas que pasara?', '', '', '— Datos para el soporte —'];
        if ($user) $lines[] = 'Persona: ' . $user['name'] . ' <' . $user['email'] . '>';
        if ($ws) $lines[] = 'Espacio: ' . $ws['name'];
        if ($page) $lines[] = 'Página: ' . $page;
        $lines[] = 'Versión: Borealis ' . BOREALIS_VERSION;
        $lines[] = 'Fecha: ' . date('d/m/Y H:i');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua !== '') $lines[] = 'Navegador: ' . mb_substr($ua, 0, 200);
        $subj = $subject . ($ws ? ' · ' . $ws['name'] : '');
        return 'mailto:' . rawurlencode($email) . '?subject=' . rawurlencode($subj) . '&body=' . rawurlencode(implode("\r\n", $lines));
    }

    /** Link for the current page, or null when no address is set. */
    public static function link(): ?string
    {
        $ws = Policy::workspace();
        $s = self::settings($ws);
        if (!$s['email']) return null;
        $origin = preg_replace('~^(https?://[^/]+).*$~', '$1', absolute_url('/'));
        $page = $origin . mb_substr((string)($_SERVER['REQUEST_URI'] ?? '/'), 0, 300);
        return self::mailto($s['email'], $s['subject'], Auth::user(), $ws, $page);
    }
}
