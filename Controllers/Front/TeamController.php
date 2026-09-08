<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Extensions\Almanac\Services\Leagues\Leagues;

/**
 * The conference index and a school page.
 */
final class TeamController extends Controller
{
    /** Every FBS team, grouped by conference. */
    public function index(Request $request): Response
    {
        $settings = $this->app->make('almanac.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('almanac.not_found'));
        }

        $teams = $this->app->make('almanac.teams');

        /*
         * 🚨 The league comes from the query string and is clamped to what this
         * site actually HOLDS, not to what the registry knows about. A
         * hand-typed `?league=nhl` on a site that follows only college football
         * would otherwise render an empty page with no explanation.
         */
        $leagues = $teams->leagues();
        $league = (string) $request->query('league', '');
        $league = isset($leagues[$league]) ? $league : (string) array_key_first($leagues);

        return $this->render('almanac::front/index', [
            'user' => $this->user($request),
            'leagues' => $leagues,
            'league' => $league,
            'conferences' => $teams->byConference($league !== '' ? $league : Leagues::DEFAULT),
            'season' => $settings->season(),
            'syncedAt' => $settings->get('almanac_sync_at'),
            'seo' => $this->seo($request)
                ->title(__('almanac.title'))
                ->description(__('almanac.intro')),
        ]);
    }

    /** One school: its season, its roster, its class, its forum. */
    public function team(Request $request): Response
    {
        $settings = $this->app->make('almanac.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('almanac.not_found'));
        }

        $teams = $this->app->make('almanac.teams');
        $team = $teams->find((string) $request->routeParam('slug'));

        if ($team === null) {
            return Response::notFound(__('almanac.team_not_found'));
        }

        $teamId = (int) $team['id'];

        /*
         * The season being shown. A year in the query string lets somebody look
         * at an old roster; it is clamped to what Roster actually holds so a
         * hand-typed year renders the nearest real thing rather than an empty
         * page.
         */
        $collegiate = $teams->collegiate($teamId);

        /*
         * 🚨 A professional club has no season picker, because ESPN answers the
         * CURRENT roster and nothing historical. Offering a year selector that
         * shows the same twenty players whatever is chosen is worse than
         * offering none.
         */
        $held = $collegiate ? $teams->seasons($teamId) : [];
        $available = array_map(static fn (array $r): int => (int) $r['season'], $held);
        $requested = (int) $request->query('season', 0);
        $season = in_array($requested, $available, true) ? $requested : $settings->season();

        /*
         * 🚨 The forum panel is gated on the VIEWER, not just on the team
         * having a forum. Convoro has already shipped one bug where an endpoint
         * served restricted forums to anybody who asked, and a "recent threads"
         * panel on a public reference page is exactly the shape that repeats
         * it. `Teams::forumTopics()` filters hidden and deleted topics and
         * nothing else — the permission check has to happen here.
         */
        $forumId = (int) ($team['forum_id'] ?? 0);
        $topics = [];

        if ($forumId > 0) {
            $groupIds = array_map(
                'intval',
                (array) $this->app->make('template')->shared('viewerGroupIds', []),
            );

            if ($this->app->make('forum.visibility')->maySee($forumId, $groupIds, $this->isModerator($request))) {
                $topics = $teams->forumTopics($forumId);
            }
        }

        return $this->render('almanac::front/team', [
            'user' => $this->user($request),
            'team' => $team,
            'season' => $season,
            'seasons' => $held,
            'record' => $collegiate ? $teams->season($teamId, $season) : null,
            'roster' => $teams->roster($teamId, $season),
            /*
             * 🚨 Not fetched at all for a professional club, rather than
             * fetched and found empty. There is no signing class for the NFL in
             * the sense this extension means and no transfer portal, so these
             * are three queries whose answer is known — and the panels are
             * already wrapped in `@notempty`, so an empty array is exactly what
             * hides them.
             */
            'recruits' => $collegiate ? $teams->recruitingClass($teamId, $season + 1) : [],
            'classRank' => $collegiate ? $teams->classRank($teamId, $season + 1) : null,
            'transfers' => $collegiate ? $teams->transfers($teamId, $season + 1) : ['in' => [], 'out' => []],
            'collegiate' => $collegiate,
            'topics' => $topics,
            'players' => $this->app->make('almanac.players'),
            'syncedAt' => $settings->get('almanac_sync_at'),
            'seo' => $this->seo($request)
                ->title((string) $team['school'])
                ->description(__('almanac.team_seo', [
                    'school' => (string) $team['school'],
                    'season' => (string) $season,
                ])),
        ]);
    }
}
