<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\Ghosts;

/** Practice accounts for trying things out (installation administrator only). */
final class GhostApi
{
    public function create(Request $r): Response
    {
        return Response::ok(['id' => Ghosts::create($r->input())], 'Cuenta fantasma creada');
    }

    public function enter(Request $r): Response
    {
        $g = Ghosts::enter($r->intParam('id'));
        return Response::ok(['redirect' => url('/')], 'Ahora usas la cuenta de ' . $g['name']);
    }

    public function leave(Request $r): Response
    {
        Ghosts::leave();
        return Response::ok(['redirect' => url('/admin/members')], 'Volviste a tu cuenta');
    }

    public function deleteAll(Request $r): Response
    {
        $res = Ghosts::deleteAll((bool)$r->input('keep_hours'));
        $n = $res['deleted'];
        $msg = $n ? ($n === 1 ? 'Se borró 1 cuenta fantasma' : "Se borraron $n cuentas fantasma") . ". Copia de seguridad: {$res['backup']}" : 'No había cuentas fantasma';
        return Response::ok($res, $msg);
    }
}
