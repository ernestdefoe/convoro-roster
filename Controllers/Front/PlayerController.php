<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * One player.
 *
 * 🚨 This page has to work for a man with four seasons of numbers and for a
 * true freshman who has none, and the second case is the one worth designing
 * for: it is the page somebody opens the week he signs. The recruiting record
 * carries it — class, stars, national rank, high school, hometown — so the page
 * is never a name and white space.
 */
final class PlayerController extends Controller
{
    public function player(Request $request): Response
    {
        $settings = $this->app->make('almanac.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('almanac.not_found'));
        }

        $players = $this->app->make('almanac.players');
        $player = $players->find((string) $request->routeParam('slug'));

        if ($player === null) {
            return Response::notFound(__('almanac.player_not_found'));
        }

        $cfbdId = (int) $player['cfbd_id'];
        $career = $players->careerStats($cfbdId);
        $appearances = $players->appearances($cfbdId);

        /*
         * Which season's game log to show. Defaults to the most recent one he
         * actually has stats for rather than to the current season — a player
         * who has left shows his last year rather than an empty table with this
         * year's heading on it.
         */
        $seasons = array_map(static fn (array $r): int => (int) $r['season'], $career);
        $requested = (int) $request->query('season', 0);
        $logSeason = in_array($requested, $seasons, true)
            ? $requested
            : (int) ($seasons[0] ?? $settings->season());

        return $this->render('almanac::front/player', [
            'user' => $this->user($request),
            'player' => $player,
            'headshot' => $players->headshot($cfbdId),
            'height' => $players->height(isset($player['height']) ? (int) $player['height'] : null),
            'classYear' => $players->classYear(
                isset($player['class_year']) ? (int) $player['class_year'] : null,
            ),
            'career' => $career,
            'appearances' => $appearances,
            'statSeasons' => $seasons,
            'logSeason' => $logSeason,
            'gameLog' => $settings->gameLogs() ? $players->gameLog($cfbdId, $logSeason) : [],
            'recruit' => $players->recruit((int) ($player['recruit_id'] ?? 0)),
            'transfers' => $players->transfers($cfbdId, (string) $player['name']),
            'syncedAt' => $settings->get('almanac_sync_at'),
            'seo' => $this->seo($request)
                ->title((string) $player['name'])
                ->description(__('almanac.player_seo', [
                    'name' => (string) $player['name'],
                    'school' => (string) ($player['school'] ?? ''),
                ])),
        ]);
    }
}
