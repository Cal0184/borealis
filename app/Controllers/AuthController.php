<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Auth;
use Borealis\Core\Config;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\Session;
use Borealis\Core\View;
use Borealis\Services\Users;
use Borealis\Services\Workspaces;

final class AuthController
{
    private function safeNext(?string $next): string
    {
        $next = (string)$next;
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\') || str_starts_with($next, '/api/')) return url('/');
        $path = parse_url($next, PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str((string)parse_url($next, PHP_URL_QUERY), $query);
        return url($path, $query);
    }

    public function loginForm(Request $r): Response
    {
        if (Auth::user()) return Response::redirect(url('/'));
        return View::page('auth/login', ['title' => 'Iniciar sesión', 'layout' => 'auth', 'email' => '', 'errors' => [], 'next' => (string)$r->q('next', ''), 'notice' => Session::flash('notice')]);
    }

    public function login(Request $r): Response
    {
        $email = trim((string)($r->post['email'] ?? ''));
        try {
            $u = Users::attempt($email, (string)($r->post['password'] ?? ''), $r->ip());
        } catch (HttpException $e) {
            return View::page('auth/login', ['title' => 'Iniciar sesión', 'layout' => 'auth', 'email' => $email, 'errors' => ['_' => $e->getMessage()], 'next' => (string)($r->post['next'] ?? ''), 'notice' => null], $e->status === 429 ? 429 : 422);
        }
        Auth::login($u);
        return Response::redirect($this->safeNext($r->post['next'] ?? null));
    }

    public function logout(Request $r): Response
    {
        Auth::logout();
        Session::start();
        Session::flash('notice', 'Cerraste sesión.');
        return Response::redirect(url('/login'));
    }

    public function registerForm(Request $r): Response
    {
        if (!Config::get('app.allow_registration')) throw HttpException::notFound('El registro abierto está desactivado. Pide una invitación a tu equipo.');
        return View::page('auth/register', ['title' => 'Crear cuenta', 'layout' => 'auth', 'values' => [], 'errors' => []]);
    }

    public function register(Request $r): Response
    {
        if (!Config::get('app.allow_registration')) throw HttpException::notFound();
        $values = ['name' => trim((string)($r->post['name'] ?? '')), 'email' => trim((string)($r->post['email'] ?? '')), 'workspace' => trim((string)($r->post['workspace'] ?? ''))];
        try {
            if ($values['workspace'] === '') throw HttpException::invalid(['workspace' => 'Ponle nombre a tu espacio de trabajo.']);
            $uid = Database::get()->transaction(function () use ($values, $r) {
                $uid = Users::create($values['name'], $values['email'], (string)($r->post['password'] ?? ''));
                Workspaces::create(mb_substr($values['workspace'], 0, 120), $uid);
                return $uid;
            });
        } catch (HttpException $e) {
            return View::page('auth/register', ['title' => 'Crear cuenta', 'layout' => 'auth', 'values' => $values, 'errors' => $e->errors + ['_' => $e->getMessage()]], 422);
        }
        Auth::login(Database::get()->one('SELECT * FROM users WHERE id = ?', [$uid]));
        return Response::redirect(url('/'));
    }

    public function inviteForm(Request $r): Response
    {
        $inv = Workspaces::findInvitation($r->param('token'));
        if (!$inv) throw HttpException::notFound('La invitación no existe, ya se usó o caducó.');
        $existing = Database::get()->one('SELECT id FROM users WHERE email = ?', [$inv['email']]);
        return View::page('auth/invite', ['title' => 'Unirte a ' . $inv['workspace_name'], 'layout' => 'auth', 'inv' => $inv, 'token' => $r->param('token'), 'hasAccount' => (bool)$existing, 'values' => [], 'errors' => []]);
    }

    public function acceptInvite(Request $r): Response
    {
        $token = $r->param('token');
        $inv = Workspaces::findInvitation($token);
        if (!$inv) throw HttpException::notFound('La invitación no existe, ya se usó o caducó.');
        $existing = Database::get()->one('SELECT * FROM users WHERE email = ?', [$inv['email']]);
        $values = ['name' => trim((string)($r->post['name'] ?? ''))];
        try {
            if ($existing) {
                // One person, one account: verify the password of the existing account.
                $u = Users::attempt($inv['email'], (string)($r->post['password'] ?? ''), $r->ip());
                Workspaces::acceptInvitation($inv, (int)$u['id']);
            } else {
                if ((string)($r->post['password'] ?? '') !== (string)($r->post['password_confirm'] ?? '')) {
                    throw HttpException::invalid(['password_confirm' => 'Las contraseñas no coinciden.']);
                }
                $u = Database::get()->transaction(function () use ($values, $inv, $r) {
                    $uid = Users::create($values['name'], $inv['email'], (string)($r->post['password'] ?? ''));
                    Workspaces::acceptInvitation($inv, $uid);
                    return Database::get()->one('SELECT * FROM users WHERE id = ?', [$uid]);
                });
            }
        } catch (HttpException $e) {
            return View::page('auth/invite', ['title' => 'Unirte a ' . $inv['workspace_name'], 'layout' => 'auth', 'inv' => $inv, 'token' => $token, 'hasAccount' => (bool)$existing, 'values' => $values, 'errors' => $e->errors + ['_' => $e->getMessage()]], 422);
        }
        Auth::login($u);
        Session::set('wsid', (int)$inv['workspace_id']);
        return Response::redirect(url('/'));
    }
}
