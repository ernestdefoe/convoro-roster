<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services\Leagues;

/**
 * The competitions this extension can hold rosters for.
 *
 * 🚨 A registry, so an application can add one without editing a file it does
 * not own. Adding a league ESPN already covers is a line, because ESPN answers
 * every one of them in the same two shapes.
 *
 * 🚨 The KEYS deliberately match Picks' registry — `cfb`, `nfl`, `nba`, `mlb`,
 * `nhl` — and the two lists are still separate on purpose. This extension does
 * not require Picks and says so in its README, and the fact each list holds is
 * genuinely different: Picks knows which leagues have FIXTURES, this knows
 * which have ROSTERS. A site running both agrees because the keys agree, not
 * because one reaches into the other.
 *
 * 🚨 An unknown key falls back to college football rather than throwing. A row
 * naming a league that has since been removed is somebody's install, not a
 * programming error.
 */
final class Leagues
{
    public const DEFAULT = 'cfb';

    /** @var array<string, League> */
    private array $leagues = [];

    public function __construct()
    {
        /*
         * 🚨 College football is the one league NOT served by ESPN here.
         * CollegeFootballData carries the recruiting class, the transfer portal
         * and season-by-season statistics for forty thousand players — none of
         * which ESPN's roster endpoint answers — and it is what every existing
         * install already holds.
         */
        $this->register(new League('cfb', 'College football', 'cfbd', '', true));

        $this->register(new League('nfl', 'NFL', 'espn', 'football/nfl'));
        $this->register(new League('nba', 'NBA', 'espn', 'basketball/nba'));
        $this->register(new League('mlb', 'MLB', 'espn', 'baseball/mlb'));
        $this->register(new League('nhl', 'NHL', 'espn', 'hockey/nhl'));
        $this->register(new League('mls', 'MLS', 'espn', 'soccer/usa.1'));
        $this->register(new League('epl', 'Premier League', 'espn', 'soccer/eng.1'));
        $this->register(new League('wnba', 'WNBA', 'espn', 'basketball/wnba'));
    }

    public function register(League $league): void
    {
        $this->leagues[$league->key] = $league;
    }

    public function get(?string $key): League
    {
        return $this->leagues[(string) $key] ?? $this->leagues[self::DEFAULT];
    }

    public function has(?string $key): bool
    {
        return isset($this->leagues[(string) $key]);
    }

    /** @return array<string, League> */
    public function all(): array
    {
        return $this->leagues;
    }

    /** @return array<string, string> key => name, for a dropdown */
    public function choices(): array
    {
        $out = [];

        foreach ($this->leagues as $key => $league) {
            $out[$key] = $league->name;
        }

        return $out;
    }
}
