<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac;

use Convoro\Engine\Module\Module;
use Convoro\Extensions\Almanac\Services\Budget;
use Convoro\Extensions\Almanac\Services\Cfbd;
use Convoro\Extensions\Almanac\Services\Http;
use Convoro\Extensions\Almanac\Services\Players;
use Convoro\Extensions\Almanac\Services\Recruits;
use Convoro\Extensions\Almanac\Services\Settings;
use Convoro\Extensions\Almanac\Services\Store;
use Convoro\Extensions\Almanac\Services\Sync;
use Convoro\Extensions\Almanac\Services\Teams;

/**
 * Almanac — every FBS team, roster and player, and where the next ones are
 * coming from.
 *
 * Browse by conference, open a school for its roster and its season, open a
 * player for his career and the high-school class he came out of. Plus a
 * recruiting board and a transfer-portal tracker.
 *
 * 🚨 **Nothing on a page makes an outbound call, ever.** CollegeFootballData's
 * free tier allows a thousand calls a CALENDAR MONTH, so a page that fetched
 * would exhaust the month in an afternoon and take Picks — which draws on the
 * same allowance — down with it. A capped, resumable, budget-aware job on the
 * schedule writes rows; every screen reads them and says how old they are.
 *
 * 🚨 **Picks is not a dependency, but it is used when it is there.** Almanac
 * borrows the CFBD key Picks already holds so an operator does not paste the
 * same credential twice, and it reads `picks_teams.forum_id` to point each
 * school at its forum. Both are wrapped: on a site without Picks, Almanac asks
 * for its own key and school pages simply show no discussion panel.
 */
final class Almanac extends Module
{
    /** The one queue handler: a single capped tick of the sync. */
    public const SYNC = 'almanac.sync';

    public function register(): void
    {
        $db = $this->app->make('db');

        $this->adminNav()->area('content')
            ->item('almanac', '/admin/almanac', 'almanac.nav')
            ->badge(fn (): int => $this->app->make('almanac.settings')->problems());

        /*
         * Where this extension's pages are, so a widget can be scoped to them.
         * Without it the section is unknown, and any widget carrying a section
         * condition reads as "hidden from you" on every Almanac page.
         */
        $this->app->make('widget_sections')->register('almanac', [
            'label' => 'almanac.nav',
            'paths' => ['/almanac'],
            'module' => 'almanac',
        ]);

        $this->app->singleton('almanac.settings', fn (): Settings => new Settings($db));
        $this->app->singleton('almanac.store', fn (): Store => new Store($db));
        $this->app->singleton('almanac.teams', fn (): Teams => new Teams($db));
        $this->app->singleton('almanac.players', fn (): Players => new Players($db));
        $this->app->singleton('almanac.recruits', fn (): Recruits => new Recruits($db));

        /* One HTTP class, hard timeouts baked in. See Services/Http.php. */
        $this->app->singleton('almanac.http', fn (): Http => new Http());

        $this->app->singleton('almanac.budget', fn (): Budget => new Budget(
            $this->app->make('almanac.settings'),
        ));

        $this->app->singleton('almanac.cfbd', fn (): Cfbd => new Cfbd(
            $this->app->make('almanac.http'),
            $this->app->make('almanac.settings'),
            $this->app->make('almanac.budget'),
        ));

        $this->app->singleton('almanac.sync', fn (): Sync => new Sync(
            $this->app->make('almanac.cfbd'),
            $this->app->make('almanac.store'),
            $this->app->make('almanac.settings'),
            $this->app->make('almanac.budget'),
        ));

        /*
         * 🚨 Registered in register(), not boot() — the cron boots everything
         * and then reads the schedule, so a module registering its schedule
         * during boot relies on an ordering it does not control and fails
         * silently. Picks documents the same seam.
         *
         * Daily, and almost every tick returns immediately: a finished mirror
         * does nothing until it is due, which is weekly by default. The daily
         * tick exists so the FIRST backfill makes steady progress instead of
         * advancing one capped chunk a week.
         */
        $this->schedule()->daily(self::SYNC);
    }

    public function boot(): void
    {
        $this->app->make('queue')->handle(
            self::SYNC,
            fn (): array => $this->app->make('almanac.sync')->run(),
        );
    }
}
