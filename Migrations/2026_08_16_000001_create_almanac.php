<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;
use Convoro\Engine\Database\Schema\Blueprint;

/**
 * Almanac's tables.
 *
 * The shape follows one rule: **a page render is a SELECT and never a fetch.**
 * CollegeFootballData's free tier allows a thousand calls a CALENDAR MONTH and
 * Picks is already spending from the same allowance, so anything that reached
 * for the provider while somebody was looking at a page would exhaust the month
 * in an afternoon and take the pick'em down with it. Everything below is a
 * mirror, filled by a capped job, and every screen reads rows.
 *
 * 🚨 **Player ids are SIGNED.** CFBD returns an ESPN athlete id where it has
 * one — `"4685413"` — and a synthesised NEGATIVE id where it does not, such as
 * `"-1007404"` on Clemson's 2013 roster. Stored unsigned, every negative id
 * clamps to 0, hundreds of players collide on one row, and the only symptom is
 * a roster that is mysteriously short with one player who has everybody's
 * stats. They also arrive as JSON strings, so they are cast, not trusted.
 *
 * 🚨 **`cfbd_id > 0` is also what says a headshot exists.** Positive ids are
 * ESPN's and resolve at `a.espncdn.com/i/headshots/...`; negative ones are
 * CFBD's own invention and resolve to nothing. That single fact is why the
 * column is signed rather than a string.
 *
 * Stats are stored LONG — one row per player per category per stat type —
 * because that is the shape CFBD serves and pivoting on write would mean a
 * migration every time they add a column. Pages pivot on read, over a few dozen
 * rows for one player.
 */
return new class extends Migration {
    public function up(): void
    {
        /*
         * The 130-odd FBS programmes. Small, read on every page, and the thing
         * every other table points at.
         */
        $this->schema->create('almanac_teams', function (Blueprint $bp) {
            $bp->id();
            $bp->int('cfbd_id', true);
            $bp->int('espn_id', true)->nullable();
            $bp->string('school', 190);
            $bp->string('slug', 190);
            $bp->string('mascot', 120)->nullable();
            $bp->string('abbreviation', 16)->nullable();
            $bp->string('conference', 100)->default('');
            $bp->string('division', 100)->nullable();
            $bp->string('classification', 20)->default('fbs');
            $bp->string('color', 16)->nullable();
            $bp->string('alt_color', 16)->nullable();
            $bp->string('logo', 255)->nullable();
            $bp->string('logo_dark', 255)->nullable();
            $bp->string('venue', 190)->nullable();
            $bp->string('venue_city', 120)->nullable();
            $bp->string('venue_state', 16)->nullable();

            /*
             * The team's forum, when this site has one. Resolved from Picks'
             * `picks_teams.forum_id` during sync and left at 0 otherwise —
             * Almanac does not require Picks, and a school page on a site
             * without it simply shows no discussion panel.
             */
            $bp->bigInt('forum_id', true)->default(0);

            $bp->timestamps();
            $bp->unique('cfbd_id');
            $bp->unique('slug');
            $bp->index('conference');
        });

        /*
         * One row per human, not per season. `almanac_player_seasons` carries
         * the year-by-year detail; this is who he is now.
         */
        $this->schema->create('almanac_players', function (Blueprint $bp) {
            $bp->id();
            $bp->bigInt('cfbd_id');           // 🚨 signed — see the note above
            $bp->string('first_name', 100)->nullable();
            $bp->string('last_name', 100)->nullable();
            $bp->string('name', 190);
            $bp->string('slug', 210);
            $bp->string('position', 8)->nullable();
            $bp->smallInt('jersey')->nullable();
            $bp->smallInt('height')->nullable();     // inches
            $bp->smallInt('weight')->nullable();     // pounds
            $bp->string('home_city', 120)->nullable();
            $bp->string('home_state', 16)->nullable();
            $bp->string('home_country', 60)->nullable();
            $bp->bigInt('team_id', true)->default(0);
            $bp->tinyInt('class_year')->nullable();  // CFBD 1-5
            $bp->smallInt('first_season')->nullable();
            $bp->smallInt('last_season')->nullable();

            /*
             * The join that makes a freshman's page worth opening. CFBD's
             * roster rows carry `recruitIds`, so a player who has never taken
             * a snap still has a class, a star rating and a high school.
             */
            $bp->bigInt('recruit_id', true)->default(0);

            $bp->timestamps();
            $bp->unique('cfbd_id');
            $bp->unique('slug');
            $bp->index('team_id');
            $bp->index('name');
        });

        /* Which roster he was on, in which year, at what listed size. */
        $this->schema->create('almanac_player_seasons', function (Blueprint $bp) {
            $bp->id();
            $bp->bigInt('player_id', true);
            $bp->bigInt('team_id', true);
            $bp->smallInt('season');
            $bp->string('position', 8)->nullable();
            $bp->smallInt('jersey')->nullable();
            $bp->smallInt('height')->nullable();
            $bp->smallInt('weight')->nullable();
            $bp->tinyInt('class_year')->nullable();
            $bp->timestamps();
            $bp->unique(['player_id', 'season', 'team_id'], 'almanac_pseason_unique');
            $bp->index(['team_id', 'season'], 'almanac_pseason_team');
        });

        /* Season totals, long format, exactly as CFBD serves them. */
        $this->schema->create('almanac_player_stats', function (Blueprint $bp) {
            $bp->id();
            $bp->bigInt('player_id', true);
            $bp->bigInt('team_id', true)->default(0);
            $bp->smallInt('season');
            $bp->string('category', 24);
            $bp->string('stat_type', 24);
            $bp->string('stat', 24)->nullable();
            $bp->timestamps();
            $bp->unique(['player_id', 'season', 'category', 'stat_type'], 'almanac_pstat_unique');
            $bp->index(['team_id', 'season', 'category'], 'almanac_pstat_team');
        });

        /*
         * Per-game lines. The biggest table by far, and the reason the sync is
         * capped: one season is roughly sixteen calls and a few hundred
         * thousand rows.
         */
        $this->schema->create('almanac_game_stats', function (Blueprint $bp) {
            $bp->id();
            $bp->bigInt('player_id', true);
            $bp->bigInt('game_id', true);
            $bp->smallInt('season');
            $bp->tinyInt('week')->nullable();
            $bp->string('season_type', 12)->default('regular');
            $bp->bigInt('team_id', true)->default(0);
            $bp->bigInt('opponent_id', true)->default(0);
            $bp->string('home_away', 8)->nullable();
            $bp->string('category', 24);
            $bp->string('stat_type', 24);
            $bp->string('stat', 24)->nullable();
            $bp->timestamps();
            $bp->unique(['player_id', 'game_id', 'category', 'stat_type'], 'almanac_gstat_unique');
            $bp->index(['player_id', 'season'], 'almanac_gstat_player');
        });

        /*
         * The recruiting board. Kept separate from `almanac_players` on
         * purpose: most recruits never become a row in that table, and the ones
         * who do are linked by `athlete_id`, not merged.
         */
        $this->schema->create('almanac_recruits', function (Blueprint $bp) {
            $bp->id();
            $bp->bigInt('cfbd_id');
            $bp->bigInt('athlete_id')->nullable();   // joins almanac_players.cfbd_id
            $bp->smallInt('year');
            $bp->int('ranking')->nullable();
            $bp->string('name', 190);
            $bp->string('slug', 210);
            $bp->string('position', 8)->nullable();
            $bp->smallInt('height')->nullable();
            $bp->smallInt('weight')->nullable();
            $bp->tinyInt('stars')->nullable();
            $bp->decimal('rating', 6, 4)->nullable();
            $bp->string('high_school', 190)->nullable();
            $bp->string('city', 120)->nullable();
            $bp->string('state', 16)->nullable();
            $bp->string('country', 60)->nullable();
            $bp->bigInt('committed_team_id', true)->default(0);

            /*
             * The provider's own spelling of the school is kept beside the
             * resolved id. A commitment to a team Almanac does not carry —
             * an FCS school, or a programme that has since moved division —
             * still has to render as text rather than vanish.
             */
            $bp->string('committed_to', 190)->nullable();

            $bp->string('recruit_type', 20)->default('HighSchool');
            $bp->timestamps();
            $bp->unique('cfbd_id');
            $bp->index(['year', 'ranking'], 'almanac_recruit_board');
            $bp->index('committed_team_id');
            $bp->index('athlete_id');
        });

        /* Class rankings — one row per team per cycle. */
        $this->schema->create('almanac_team_recruiting', function (Blueprint $bp) {
            $bp->id();
            $bp->bigInt('team_id', true);
            $bp->smallInt('year');
            $bp->int('rank')->nullable();
            $bp->decimal('points', 10, 2)->nullable();
            $bp->timestamps();
            $bp->unique(['team_id', 'year'], 'almanac_trecruit_unique');
        });

        /* The portal. */
        $this->schema->create('almanac_transfers', function (Blueprint $bp) {
            $bp->id();
            $bp->smallInt('season');
            $bp->string('name', 190);
            $bp->string('first_name', 100)->nullable();
            $bp->string('last_name', 100)->nullable();
            $bp->string('position', 8)->nullable();
            $bp->bigInt('player_id', true)->default(0);
            $bp->bigInt('origin_team_id', true)->default(0);
            $bp->bigInt('destination_team_id', true)->default(0);
            $bp->string('origin', 190)->nullable();
            $bp->string('destination', 190)->nullable();
            $bp->datetime('transfer_date')->nullable();
            $bp->decimal('rating', 6, 4)->nullable();
            $bp->tinyInt('stars')->nullable();
            $bp->string('eligibility', 40)->nullable();
            $bp->timestamps();

            /*
             * 🚨 The portal has no stable per-entry id, so the natural key is
             * the person and the cycle. Without this a re-sync inserts the
             * whole board again every run.
             */
            $bp->unique(['season', 'name', 'origin'], 'almanac_transfer_unique');
            $bp->index('season');
            $bp->index('destination_team_id');
        });

        /* Team results and ratings, one row per programme per season. */
        $this->schema->create('almanac_team_seasons', function (Blueprint $bp) {
            $bp->id();
            $bp->bigInt('team_id', true);
            $bp->smallInt('season');
            $bp->smallInt('wins')->default(0);
            $bp->smallInt('losses')->default(0);
            $bp->smallInt('ties')->default(0);
            $bp->smallInt('conf_wins')->default(0);
            $bp->smallInt('conf_losses')->default(0);
            $bp->decimal('talent', 10, 2)->nullable();
            $bp->decimal('sp_rating', 8, 2)->nullable();
            $bp->int('sp_rank')->nullable();
            $bp->int('ap_rank')->nullable();
            $bp->int('coaches_rank')->nullable();
            $bp->timestamps();
            $bp->unique(['team_id', 'season'], 'almanac_tseason_unique');
        });
    }

    public function down(): void
    {
        /* Children first: nothing here is worth a foreign key, but dropping in
         * insert order would leave orphans behind on a failed run. */
        foreach ([
            'almanac_team_seasons',
            'almanac_transfers',
            'almanac_team_recruiting',
            'almanac_recruits',
            'almanac_game_stats',
            'almanac_player_stats',
            'almanac_player_seasons',
            'almanac_players',
            'almanac_teams',
        ] as $table) {
            $this->schema->drop($table);
        }
    }
};
