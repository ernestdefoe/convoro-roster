<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;
use Convoro\Engine\Database\Schema\Blueprint;

/**
 * Where a school publishes, and the photo it publishes of each player.
 *
 * Almanac shipped with ESPN headshots, which are free — CFBD's player ids ARE
 * ESPN athlete ids — but thin. ESPN has no picture for a great many real
 * athletes, and a true freshman, the player this whole extension was built to
 * make worth looking up, almost never has one. Every school does have one: it
 * takes the photographs itself in June and puts them on its own roster page.
 *
 * 🚨 **None of this spends a CollegeFootballData call.** The athletics sites
 * are a different provider with no shared allowance, which is why photos can
 * be refreshed on their own cadence while the CFBD mirror sits idle for a week
 * at a time.
 *
 * 🚨 **The domain has to be stored, not derived.** Athletics sites are named
 * after nicknames — rolltide.com, vucommodores.com, ramblinwreck.com — and
 * nothing in CFBD carries one. They are also on four different platforms, and
 * the platform decides which endpoint answers, so it is stored beside the
 * domain rather than sniffed on every run.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema->alter('almanac_teams', function (Blueprint $bp): void {
            /* rolltide.com. Bare host: no scheme, no path, no trailing slash. */
            $bp->string('site_domain', 190)->default('');

            /* Which of the four readers answers here. See Services/Athletics. */
            $bp->string('site_platform', 12)->default('');

            /*
             * The site's OWN id for football, on the platforms that have one.
             * It is per-site and not a constant: Alabama's football is sport 3
             * and Clemson's is 20.
             */
            $bp->int('site_sport_id', true)->default(0);

            /*
             * 🚨 What put this row here, and the reason an operator's
             * correction survives an update. The shipped catalogue only ever
             * fills a row that is empty or that it wrote itself; a domain typed
             * into the admin screen is marked `manual` and is never overwritten
             * by a later release's guess.
             */
            $bp->string('site_source', 12)->default('');

            /* When the roster was last read, and what came back. */
            $bp->int('photos_at', true)->default(0);
            $bp->int('photos_found', true)->default(0);
            $bp->string('photos_error', 40)->default('');
        });

        $this->schema->alter('almanac_players', function (Blueprint $bp): void {
            /*
             * The school's own photograph of him, absolute. Nullable rather
             * than defaulted, because "we have never looked" and "we looked and
             * there is none" are different states and only the first should be
             * retried cheaply.
             */
            $bp->string('photo_url', 500)->nullable();

            /* 'school' today. Room for another source without a migration. */
            $bp->string('photo_source', 12)->default('');
        });
    }

    public function down(): void
    {
        foreach ([
            'almanac_teams' => ['site_domain', 'site_platform', 'site_sport_id', 'site_source', 'photos_at', 'photos_found', 'photos_error'],
            'almanac_players' => ['photo_url', 'photo_source'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->schema->hasColumn($table, $column)) {
                    $this->schema->dropColumn($table, $column);
                }
            }
        }
    }
};
