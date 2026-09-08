<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac;

use Convoro\Engine\Module\Module;
use Convoro\Extensions\Almanac\Services\Athletics;
use Convoro\Extensions\Almanac\Services\Budget;
use Convoro\Extensions\Almanac\Services\Cfbd;
use Convoro\Extensions\Almanac\Services\EspnSync;
use Convoro\Extensions\Almanac\Services\Http;
use Convoro\Extensions\Almanac\Services\Photos;
use Convoro\Extensions\Almanac\Services\Players;
use Convoro\Extensions\Almanac\Services\Recruits;
use Convoro\Extensions\Almanac\Services\Settings;
use Convoro\Extensions\Almanac\Services\Sources\EspnRoster;
use Convoro\Extensions\Almanac\Services\Store;
use Convoro\Extensions\Almanac\Services\Sync;
use Convoro\Extensions\Almanac\Services\Teams;

/**
 * Roster — every FBS team, roster and player, and where the next ones are
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
 * 🚨 **Picks is not a dependency, but it is used when it is there.** Roster
 * borrows the CFBD key Picks already holds so an operator does not paste the
 * same credential twice, and it reads `picks_teams.forum_id` to point each
 * school at its forum. Both are wrapped: on a site without Picks, Roster asks
 * for its own key and school pages simply show no discussion panel.
 */
/*
 * 🚨 The class, the namespace and every `almanac_*` name stay as they are, and
 * that is deliberate rather than a half-finished rename.
 *
 * What changed is the NAME people see: "Almanac" was a college-football word
 * for a college-football extension, and this now holds the NFL, the NBA, MLB
 * and the NHL as well. The manifest key is what an installed extension is keyed
 * by on every live site, and the table prefix is what forty thousand rows sit
 * behind — changing either would orphan an install's data to make a label read
 * better. The label is what was wrong; the identifiers were never visible.
 */
final class Almanac extends Module
{
    /** The one queue handler: a single capped tick of the sync. */
    public const SYNC = 'almanac.sync';
    public const ESPN = 'almanac.espn';

    public function register(): void
    {
        $db = $this->app->make('db');

        $this->adminNav()->area('content')
            ->item('almanac', '/admin/almanac', 'almanac.nav')
            ->badge(fn (): int => $this->app->make('almanac.settings')->problems());

        /*
         * Where this extension's pages are, so a widget can be scoped to them.
         * Without it the section is unknown, and any widget carrying a section
         * condition reads as "hidden from you" on every Roster page.
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

        /*
         * The schools' own rosters, read for the photograph on them. A
         * different provider from CollegeFootballData with no shared
         * allowance — see Services/Athletics.php.
         */
        $this->app->singleton('almanac.athletics', fn (): Athletics => new Athletics(
            $this->app->make('almanac.http'),
        ));

        $this->app->singleton('almanac.photos', fn (): Photos => new Photos(
            $db,
            $this->app->make('almanac.athletics'),
            $this->app->make('almanac.settings'),
        ));

        $this->app->singleton('almanac.espn_roster', fn (): EspnRoster => new EspnRoster(
            $this->app->make('almanac.http'),
        ));

        /*
         * 🚨 A second sync beside the college one rather than a rewrite of it.
         * That one walks a resumable plan across a thousand-call monthly
         * budget, which is the fact that shapes the whole college side; ESPN
         * charges nothing and needs no key, so the same machinery would serve a
         * constraint that is not there.
         */
        $this->app->singleton('almanac.espn_sync', fn (): EspnSync => new EspnSync(
            $this->app->make('db'),
            $this->app->make('almanac.espn_roster'),
            $this->app->make('almanac.settings'),
        ));

        $this->app->singleton('almanac.sync', fn (): Sync => new Sync(
            $this->app->make('almanac.cfbd'),
            $this->app->make('almanac.store'),
            $this->app->make('almanac.settings'),
            $this->app->make('almanac.budget'),
            $this->app->make('almanac.photos'),
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

        /*
         * 🚨 HOURLY, where the college sync is daily, and that is not a
         * contradiction. The college sync is daily because it spends from a
         * thousand-call monthly allowance; this one is free, and it fetches at
         * most a dozen rosters a run — so a site that has just added the NBA
         * has all thirty within three hours instead of thirty days.
         *
         * It returns immediately on a site following no ESPN league, which is
         * every site that exists today.
         */
        $this->schedule()->hourly(self::ESPN);
    }

    public function boot(): void
    {
        $queue = $this->app->make('queue');

        $queue->handle(self::SYNC, fn (): array => $this->app->make('almanac.sync')->run());
        $queue->handle(self::ESPN, fn (): array => $this->app->make('almanac.espn_sync')->run());
    }
}
