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

        /*
         * Pointing a school at its athletics site by hand. The shipped
         * catalogue covers all but a handful, and this is how the handful — and
         * anything the catalogue got wrong — gets fixed without waiting for a
         * release.
         */
        $router->post('/site', [AlmanacController::class, 'saveSite'], 'admin.almanac.site');
        $router->post('/photos', [AlmanacController::class, 'photosNow'], 'admin.almanac.photos');
    });
