<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Controllers\Admin;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Extensions\Almanac\Almanac;

/**
 * Almanac's admin screen.
 *
 * 🚨 **Rendering this page makes no outbound call**, and "Run a sync now"
 * QUEUES one rather than performing it in the request. A backfill is tens of
 * calls and megabytes of JSON; run inline it would be killed by
 * `max_execution_time` half way through, which is the exact failure Picks was
 * ported away from.
 */
final class AlmanacController extends Controller
{
    private const NOTICE = 'almanac_admin_notice';

    public function index(Request $request): Response
    {
        $settings = $this->app->make('almanac.settings');
        $sync = $this->app->make('almanac.sync');
        $cursor = $settings->cursor();

        /*
         * How much is left to do, worked out from the same plan the job walks
         * so the number on screen cannot drift from the number it acts on.
         */
        $mode = (string) ($cursor['mode'] ?? 'backfill');
        $plan = $sync->plan($mode);

        return $this->render('almanac::admin/index', [
            'user' => $this->user($request),
            'settings' => $settings,
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'status' => $settings->get('almanac_sync_status'),
            'syncedAt' => $settings->get('almanac_sync_at'),
            'okAt' => $settings->lastComplete(),
            'budget' => $settings->get('almanac_budget_remaining'),
            'mode' => $mode,
            'remaining' => max(0, count($plan) - (int) ($cursor['i'] ?? 0)),
            'borrowed' => trim($settings->get('almanac_cfbd_key')) === '' && $settings->configured(),
            'counts' => $this->counts(),
            'seo' => $this->seo($request)->title(__('almanac.admin_title')),
        ]);
    }

    public function saveOptions(Request $request): Response
    {
        $this->app->make('almanac.settings')->save([
            'almanac_enabled' => $request->input('almanac_enabled') === '1' ? '1' : '0',
            'almanac_cfbd_key' => (string) $request->input('almanac_cfbd_key', ''),
            'almanac_season' => (string) $request->input('almanac_season', ''),
            'almanac_seasons_back' => (string) $request->input('almanac_seasons_back', '6'),
            'almanac_recruit_classes' => (string) $request->input('almanac_recruit_classes', '8'),
            'almanac_game_logs' => $request->input('almanac_game_logs') === '1' ? '1' : '0',
            'almanac_budget_reserve' => (string) $request->input('almanac_budget_reserve', '50'),
            'almanac_run_cap' => (string) $request->input('almanac_run_cap', '40'),
            'almanac_refresh_days' => (string) $request->input('almanac_refresh_days', '7'),
        ]);

        return $this->ok($request, __('almanac.saved'));
    }

    /**
     * Queue a run.
     *
     * 🚨 Queued, never performed here. See the note on the class.
     */
    public function syncNow(Request $request): Response
    {
        $this->app->make('queue')->push(Almanac::SYNC);

        return $this->ok($request, __('almanac.admin.sync_queued'));
    }

    /**
     * Walk the whole plan again from the beginning.
     *
     * Clearing the cursor is enough — every write is an upsert, so a repeat
     * costs calls and changes nothing else. Nothing is deleted, because a
     * provider having a bad day must not be able to empty the mirror.
     */
    public function restart(Request $request): Response
    {
        $settings = $this->app->make('almanac.settings');
        $settings->saveCursor([]);
        $settings->put('almanac_sync_ok_at', '');

        $this->app->make('queue')->push(Almanac::SYNC);

        return $this->ok($request, __('almanac.admin.sync_queued'));
    }

    /**
     * What is actually stored, so the screen can prove the sync did something.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $db = $this->app->make('db');
        $out = [];

        foreach ([
            'teams' => 'almanac_teams',
            'players' => 'almanac_players',
            'recruits' => 'almanac_recruits',
            'stats' => 'almanac_player_stats',
        ] as $label => $table) {
            try {
                $out[$label] = (int) ($db->selectOne(
                    'SELECT COUNT(*) AS `c` FROM `' . $db->prefixed($table) . '`'
                )['c'] ?? 0);
            } catch (\Throwable) {
                $out[$label] = 0;
            }
        }

        return $out;
    }

    private function ok(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect('/admin/almanac');
    }
}
