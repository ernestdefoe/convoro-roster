<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

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

        return $this->render('almanac::front/index', [
            'user' => $this->user($request),
            'conferences' => $teams->byConference(),
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
         * at an old roster; it is clamped to what Almanac actually holds so a
         * hand-typed year renders the nearest real thing rather than an empty
         * page.
         */
        $held = $teams->seasons($teamId);
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
            'record' => $teams->season($teamId, $season),
            'roster' => $teams->roster($teamId, $season),
            'recruits' => $teams->recruitingClass($teamId, $season + 1),
            'classRank' => $teams->classRank($teamId, $season + 1),
            'transfers' => $teams->transfers($teamId, $season + 1),
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
