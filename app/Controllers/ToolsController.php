<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Google;
use Borealis\Services\Integrations;
use Borealis\Services\Modules;
use Borealis\Services\Projects;
use Borealis\Services\Sprints;

/** 1.8: «Sprints» across projects and «Todo a mano» (project tools and integrations). */
final class ToolsController
{
    public function sprints(Request $r): Response
    {
        $all = Sprints::overview();
        // projects where the person can plan sprints (the module is on and they have the permission)
        $plannable = array_values(array_filter(Projects::list(), fn($p) => !$p['archived_at'] && Modules::isEnabled((int)$p['id'], 'sprints') && Policy::canProject($p, 'sprint.manage')));
        return View::page('sprints/index', ['title' => 'Sprints', 'nav' => 'sprints', 'sprints' => $all, 'plannable' => $plannable]);
    }

    public function integrations(Request $r): Response
    {
        $hub = Integrations::hub();
        $only = (int)$r->q('project');
        if ($only) $hub = array_values(array_filter($hub, fn($h) => (int)$h['project']['id'] === $only));
        $github = [];
        foreach ($hub as $h) if (isset($h['integrations']['github'])) $github[(int)$h['project']['id']] = Integrations::github((int)$h['project']['id']);
        return View::page('integrations/index', ['title' => 'Todo a mano', 'nav' => 'integrations', 'hub' => $hub, 'github' => $github, 'google' => Google::account(), 'googleReady' => Google::configured(), 'only' => $only]);
    }
}
