<?php

declare(strict_types=1);

use Convoro\Extensions\Almanac\Controllers\Front\PlayerController;
use Convoro\Extensions\Almanac\Controllers\Front\RecruitingController;
use Convoro\Extensions\Almanac\Controllers\Front\TeamController;

/**
 * @var \Convoro\Engine\Routing\Router $router
 */

/*
 * No middleware on the reads. Whether Roster answers at all is a setting, so
 * the controller decides: an extension that is installed and switched off
 * should 404 like a page that does not exist, not 403 like one somebody is not
 * allowed to see.
 *
 * 🚨 Schools and players sit under their own path segments rather than directly
 * under `/almanac/{slug}`. A single flat namespace would mean `/almanac/
 * recruiting` was ambiguous the first time a school called Recruiting appeared,
 * and the symptom would be the site's own navigation returning "no such team".
 */
$router->get('/almanac', [TeamController::class, 'index'], 'almanac.index');
$router->get('/almanac/recruiting', [RecruitingController::class, 'board'], 'almanac.recruiting');
$router->get('/almanac/transfers', [RecruitingController::class, 'portal'], 'almanac.transfers');
$router->get('/almanac/team/{slug}', [TeamController::class, 'team'], 'almanac.team');
$router->get('/almanac/player/{slug}', [PlayerController::class, 'player'], 'almanac.player');
