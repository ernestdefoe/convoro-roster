<?php

declare(strict_types=1);

use Convoro\Engine\Http\Middleware\RequireAdmin;
use Convoro\Extensions\Almanac\Controllers\Admin\AlmanacController;

/** @var \Convoro\Engine\Routing\Router $router */

$router->group()
    ->prefix('/admin/almanac')
    ->middleware(RequireAdmin::class)
    ->group(function ($router) {
        /*
         * 🚨 Never an action called `settings` — the base Controller has a
         * protected `settings(): array` and the clash is a fatal at class load.
         * The path may say settings; the method may not.
         */
        $router->get('/', [AlmanacController::class, 'index'], 'admin.almanac');
        $router->post('/save', [AlmanacController::class, 'saveOptions'], 'admin.almanac.save');
        $router->post('/sync', [AlmanacController::class, 'syncNow'], 'admin.almanac.sync');
        $router->post('/restart', [AlmanacController::class, 'restart'], 'admin.almanac.restart');
    });
