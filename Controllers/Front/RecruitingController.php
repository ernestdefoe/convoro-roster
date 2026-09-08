<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * The recruiting board and the transfer portal.
 *
 * 🚨 Filters are applied in SQL and the page is paged, so a class of four
 * thousand is never sent to a browser to be filtered there.
 */
final class RecruitingController extends Controller
{
    public function board(Request $request): Response
    {
        $settings = $this->app->make('almanac.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('almanac.not_found'));
        }

        $recruits = $this->app->make('almanac.recruits');
        $years = $recruits->years();

        /* Clamped to a class Roster holds, so a typed year cannot render empty. */
        $requested = (int) $request->query('year', 0);
        $year = in_array($requested, $years, true)
            ? $requested
            : (int) ($years[0] ?? $settings->season() + 1);

        $filters = [
            'position' => (string) $request->query('position', ''),
            'state' => (string) $request->query('state', ''),
            'stars' => (int) $request->query('stars', 0),
            'status' => (string) $request->query('status', ''),
            'q' => (string) $request->query('q', ''),
        ];

        $board = $recruits->board($year, $filters, (int) $request->query('page', 1));

        return $this->render('almanac::front/recruiting', [
            'user' => $this->user($request),
            'year' => $year,
            'years' => $years,
            'filters' => $filters,
            'board' => $board,
            'positions' => $recruits->positions($year),
            'states' => $recruits->states($year),
            'rankings' => $recruits->classRankings($year),
            'syncedAt' => $settings->get('almanac_sync_at'),
            'seo' => $this->seo($request)
                ->title(__('almanac.recruiting.title', ['year' => (string) $year]))
                ->description(__('almanac.recruiting.intro')),
        ]);
    }

    public function portal(Request $request): Response
    {
        $settings = $this->app->make('almanac.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('almanac.not_found'));
        }

        $recruits = $this->app->make('almanac.recruits');
        $seasons = $recruits->portalSeasons();

        $requested = (int) $request->query('season', 0);
        $season = in_array($requested, $seasons, true)
            ? $requested
            : (int) ($seasons[0] ?? $settings->season() + 1);

        $filters = [
            'position' => (string) $request->query('position', ''),
            'status' => (string) $request->query('status', ''),
            'q' => (string) $request->query('q', ''),
        ];

        return $this->render('almanac::front/transfers', [
            'user' => $this->user($request),
            'season' => $season,
            'seasons' => $seasons,
            'filters' => $filters,
            'board' => $recruits->portal($season, $filters, (int) $request->query('page', 1)),
            'syncedAt' => $settings->get('almanac_sync_at'),
            'seo' => $this->seo($request)
                ->title(__('almanac.portal.title', ['season' => (string) $season]))
                ->description(__('almanac.portal.intro')),
        ]);
    }
}
