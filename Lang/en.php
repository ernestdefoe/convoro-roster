<?php

declare(strict_types=1);

/*
 * Every human string Almanac puts on a screen.
 *
 * 🚨 `{name}` for a placeholder, and exactly TWO forms separated by `|` for a
 * plural — which is reached through `__n()`, never `@lang`, because `@lang`
 * does not pluralise and would print the pipe onto the page.
 *
 * 🚨 Nothing here is assembled out of fragments in a template. A sentence a
 * translator cannot see whole is a sentence they cannot translate, and word
 * order is not a constant across languages.
 */

return [
    'name' => 'Almanac',
    'nav' => 'Almanac',
    'admin_title' => 'Almanac',

    'save' => 'Save',
    'saved' => 'Saved.',

    'title' => 'Almanac',
    'intro' => 'Every FBS team, roster and player. Pick a school to see who is on it.',
    'not_found' => 'There is nothing here.',
    'team_not_found' => 'No such school.',
    'player_not_found' => 'No such player.',
    'empty' => 'Nothing has been synced yet. Add a CollegeFootballData API key in the admin and the first scheduled run will fill this in.',

    /* ------------------------------------------------------------ shared */

    'season' => 'Season',
    'team' => 'Team',
    'player' => 'Player',
    'position' => 'Position',
    'class' => 'Class',
    'height' => 'Height',
    'weight' => 'Weight',
    'lbs' => 'lbs',
    'hometown' => 'Hometown',
    'jersey' => 'No.',
    'stars' => 'Stars',
    'rating' => 'Rating',
    'high_school' => 'High school',
    'national_rank' => 'Nat. rank',
    'state' => 'State',
    'status' => 'Status',
    'any' => 'Any',
    'search' => 'Search',
    'filter' => 'Filter',
    'go' => 'Go',
    'previous' => 'Previous',
    'next' => 'Next',
    'from' => 'From',
    'to' => 'To',
    'at' => 'at',
    'undecided' => 'Undecided',
    'points' => 'points',

    'team_count' => '{count} team|{count} teams',
    'post_count' => '{count} post|{count} posts',
    'showing' => 'Showing {shown} of {total}.',
    'page_of' => 'Page {page} of {pages}',

    /*
     * 🚨 The line that keeps every page honest. Everything on these screens is
     * what a scheduled job last managed to fetch, not what is true this second,
     * and a page that does not say so is a page people will quote as live.
     */
    'freshness' => 'Figures come from CollegeFootballData and are refreshed on a schedule, so they may lag a live scoreboard.',

    /* -------------------------------------------------------- team page */

    'record' => 'Record',
    'conference_record' => 'Conference',
    'sp_rating' => 'SP+',
    'class_rank' => 'Class rank',
    'roster' => '{season} roster',
    'no_roster' => 'No roster has been synced for this season.',
    'incoming_class' => '{year} recruiting class',
    'ranked_nationally' => 'ranked #{rank} nationally',
    'portal_in' => 'Arriving through the portal',
    'forum_recent' => 'Recent in the {school} forum',
    'team_seo' => 'The {season} {school} roster, results and recruiting class.',

    'group.offense' => 'Offense',
    'group.defense' => 'Defense',
    'group.specialists' => 'Specialists',
    'group.other' => 'Other',

    /* ------------------------------------------------------ player page */

    'recruiting_profile' => 'Coming out of high school',
    'class_of' => 'Class of',
    'career' => 'Career',
    'no_stats' => 'No college statistics yet.',
    'game_log' => '{season} game by game',
    'week' => 'Week',
    'opponent' => 'Opponent',
    'stat_line' => 'Stat line',
    'transfers' => 'Transfers',
    'player_seo' => '{name} of {school} — stats, career and recruiting profile.',

    'class.freshman' => 'Freshman',
    'class.sophomore' => 'Sophomore',
    'class.junior' => 'Junior',
    'class.senior' => 'Senior',
    'class.super_senior' => 'Super senior',

    /* ------------------------------------------------------- recruiting */

    'recruiting.nav' => 'Recruiting',
    'recruiting.title' => '{year} recruiting class',
    'recruiting.intro' => 'The national board — every recruit, who they are committed to, and where they came from.',
    'committed' => 'Committed',
    'uncommitted' => 'Uncommitted',
    'committed_to' => 'Committed to',
    'search_placeholder' => 'Name, high school or town',
    'no_recruits' => 'No recruits match that.',
    'class_rankings' => '{year} class rankings',

    /* ----------------------------------------------------------- portal */

    'portal.nav' => 'Transfer portal',
    'portal.title' => '{season} transfer portal',
    'portal.intro' => 'Who has entered, where they came from and where they landed.',
    'no_transfers' => 'No portal entries match that.',
    'eligibility' => 'Eligibility',

    /* ------------------------------------------------------------ admin */

    'admin.intro' => 'Almanac mirrors CollegeFootballData on a schedule. Nothing is fetched while somebody is looking at a page.',
    'admin.enabled' => 'Switch Almanac on',
    'admin.enabled_note' => 'While this is off, every Almanac page answers as though it does not exist.',
    'admin.api_key' => 'CollegeFootballData API key',
    'admin.api_key_note' => 'Leave blank to keep the stored key. If Picks already holds one, Almanac borrows it and you can leave this empty.',
    'admin.api_key_borrowed' => 'Currently borrowing the key Picks holds.',
    'admin.season' => 'Season',
    'admin.season_note' => 'Leave blank to work it out from the date. January and February count as the previous season.',
    'admin.seasons_back' => 'Seasons of history',
    'admin.seasons_back_note' => 'How far back to carry rosters and stats. Six covers a fifth-year senior’s whole career.',
    'admin.recruit_classes' => 'Recruiting classes',
    'admin.recruit_classes_note' => 'How many classes to carry. Eight covers everybody on a current roster, which is what gives a true freshman a page worth opening.',
    'admin.game_logs' => 'Fetch game by game',
    'admin.game_logs_note' => 'The most expensive dataset — one call per week of the season, and there is no whole-season shortcut.',
    'admin.budget_reserve' => 'Calls to hold back',
    'admin.budget_reserve_note' => 'The sync stops rather than spend below this, so Almanac can never take the whole monthly allowance.',
    'admin.run_cap' => 'Calls per run',
    'admin.run_cap_note' => 'A ceiling on a single scheduled run, so one tick cannot spend the month.',
    'admin.refresh_days' => 'Days between refreshes',
    'admin.refresh_days_note' => 'Once the first backfill is done, the daily tick does nothing until this much time has passed.',

    'admin.state' => 'What the last run did',
    'admin.budget' => 'Calls left this month',
    'admin.budget_unknown' => 'Not known until the next call.',
    'admin.progress' => 'Backfill progress',
    'admin.progress_working' => '{remaining} steps still to run.',
    'admin.progress_complete' => 'Up to date. The next refresh is due in {days} days.',
    'admin.never_run' => 'Has not run yet.',
    'admin.sync_now' => 'Run a sync now',
    'admin.restart' => 'Start the backfill again',
    'admin.restart_note' => 'Walks the whole plan from the beginning. Nothing is deleted — every write is an upsert — so this costs calls and changes nothing else.',
    'admin.sync_queued' => 'A sync has been queued. It will run on the next tick of the worker.',
    'admin.counts' => 'What is stored',
    'admin.count_teams' => 'Teams',
    'admin.count_players' => 'Players',
    'admin.count_recruits' => 'Recruits',
    'admin.count_stats' => 'Season stat lines',

    /*
     * 🚨 One line per status the sync can report, because "it did not work" is
     * not something anybody can act on. Each of these says what to do next.
     */
    'status.disabled' => 'Switched off.',
    'status.not_configured' => 'No API key, so nothing can be fetched.',
    'status.idle' => 'Up to date; waiting for the next refresh.',
    'status.working' => 'Working through the backfill.',
    'status.complete' => 'Finished.',
    'status.invalid_key' => 'CollegeFootballData rejected the key.',
    'status.unreachable' => 'CollegeFootballData did not answer. Nothing was changed.',
    'status.rate_limited' => 'CollegeFootballData asked us to slow down.',
    'status.budget_reserve' => 'Stopped to stay above the reserve. It will carry on next month, or lower the reserve to continue now.',
    'status.run_cap' => 'Reached this run’s ceiling. It will carry on at the next tick.',
];
