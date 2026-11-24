<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\Validator;
use Borealis\Core\View;
use Borealis\Services\CapacityAlerts;
use Borealis\Services\QuickWork;
use Borealis\Services\Workspaces;

/** 1.14 · «Trabajos rápidos»: the week's quick work and a form that stays open. */
final class QuickController
{
    public function index(Request $r): Response
    {
        $w = (string)$r->q('week', '');
        $monday = CapacityAlerts::weekStart(Validator::isDate($w) ? $w : null);
        $mine = (string)$r->q('mine', '') === '1';
        $project = QuickWork::project();
        return View::page('quick/index', [
            'title' => QuickWork::NAME, 'nav' => 'quick', 'monday' => $monday, 'mine' => $mine,
            'rows' => QuickWork::week($monday, $mine ? (int)\Borealis\Core\Auth::id() : null), 'quickProject' => $project,
            'people' => QuickWork::canAssignOthers() ? QuickWork::people() : [], 'priorities' => Workspaces::priorities(),
        ]);
    }
}
