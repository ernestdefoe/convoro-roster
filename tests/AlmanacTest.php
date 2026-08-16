<?php

declare(strict_types=1);

/*
 * Almanac.
 *
 * 🚨 One rule carries this extension: **a page render is a SELECT and never a
 * fetch.** CollegeFootballData allows a thousand calls a CALENDAR MONTH on the
 * free tier and Picks draws on the same allowance, so anything that reached for
 * the provider while somebody was looking at a page would end the month in an
 * afternoon and take the pick'em down with it.
 *
 * 🚨 **Nothing here touches the network, and nothing here writes a row.** The
 * suite runs against the site's own database — on fbsfb that is production —
 * so every test below is either a source-and-markup invariant or is driven
 * through a double. `Http` and `Settings` are both deliberately non-final so
 * those doubles can exist.
 *
 * Most of what is asserted here is a bug that actually shipped. Each one is
 * named after the symptom somebody saw.
 */

use Convoro\Engine\Convoro;
use Convoro\Extensions\Almanac\Services\Athletics;
use Convoro\Extensions\Almanac\Services\Budget;
use Convoro\Extensions\Almanac\Services\Cfbd;
use Convoro\Extensions\Almanac\Services\Http;
use Convoro\Extensions\Almanac\Services\Photos;
use Convoro\Extensions\Almanac\Services\Players;
use Convoro\Extensions\Almanac\Services\Settings;
use Convoro\Extensions\Almanac\Services\Store;
use Convoro\Extensions\Almanac\Services\Sync;

$app = Convoro::getInstance();
$db = $app->make('db');
$root = dirname(__DIR__);

/**
 * An Http that answers from a script instead of a socket.
 *
 * 🚨 The reason `Http` is not final. Every failure this extension has to
 * survive — a provider that says nothing, a rejected key, a budget header that
 * stops arriving — is a status code and a header, and a test that cannot
 * produce one only ever exercises the happy path.
 */
if (!class_exists('AlmanacScriptedHttp')) {
    class AlmanacScriptedHttp extends Http
    {
        /**
         * @param list<array{0: int, 1: array<mixed>, 2: array<string, string>}> $answers
         */
        public function __construct(public array $answers = [], public array $asked = [])
        {
        }

        public function usable(): bool
        {
            return true;
        }

        public function getJson(string $url, array $query = [], array $headers = []): array
        {
            $this->asked[] = $url . ($query === [] ? '' : '?' . http_build_query($query));

            return array_shift($this->answers) ?? [200, [], []];
        }

        /**
         * The athletics sites, answered from a map of URL fragment => body.
         *
         * Keyed on a fragment rather than the whole URL because the readers
         * build query strings themselves, and a test that pinned the exact
         * string would be asserting the encoding rather than the behaviour.
         *
         * @var array<string, array{0: int, 1: string}>
         */
        public array $pages = [];

        public function getPage(string $url): array
        {
            $this->asked[] = $url;

            foreach ($this->pages as $fragment => $answer) {
                if (str_contains($url, $fragment)) {
                    return $answer;
                }
            }

            return [404, ''];
        }
    }
}

/**
 * A Photos that records instead of fetching.
 *
 * 🚨 The reason `Photos` is not final: the suite runs against the site's own
 * database, so driving the real one through `Sync` would read live rosters off
 * the internet and stamp live rows.
 */
if (!class_exists('AlmanacSpyPhotos')) {
    class AlmanacSpyPhotos extends \Convoro\Extensions\Almanac\Services\Photos
    {
        public int $runs = 0;

        public int $seeds = 0;

        public function __construct()
        {
        }

        public function run(?int $now = null): array
        {
            $this->runs++;

            return ['status' => 'ran', 'schools' => 0, 'photos' => 0];
        }

        public function seedCatalogue(): int
        {
            $this->seeds++;

            return 0;
        }
    }
}

/**
 * Settings held in memory.
 *
 * 🚨 So the budget guard can be driven without writing a single row into the
 * settings table of a live forum.
 */
if (!class_exists('AlmanacFakeSettings')) {
    class AlmanacFakeSettings extends Settings
    {
        /** @param array<string, string> $values */
        public function __construct(public array $values = [])
        {
        }

        public function get(string $key): string
        {
            return $this->values[$key] ?? '';
        }

        public function put(string $key, string $value): void
        {
            $this->values[$key] = $value;
        }

        public function cfbdKey(): string
        {
            return $this->values['key'] ?? 'test-key';
        }

        public function configured(): bool
        {
            return $this->cfbdKey() !== '';
        }

        public function budgetReserve(): int
        {
            return (int) ($this->values['reserve'] ?? 50);
        }

        public function runCap(): int
        {
            return (int) ($this->values['cap'] ?? 40);
        }

        public function seasonsBack(): int
        {
            return (int) ($this->values['back'] ?? 6);
        }

        public function recruitClasses(): int
        {
            return (int) ($this->values['classes'] ?? 8);
        }

        public function gameLogs(): bool
        {
            return ($this->values['logs'] ?? '1') === '1';
        }

        public function season(?int $now = null): int
        {
            return (int) ($this->values['season'] ?? 2026);
        }
    }
}

$codeOf = static function (string $path): string {
    if (str_ends_with($path, '.cvr')) {
        // A template's comments are `{# … #}` and the compiler drops them.
        return (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));
    }

    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
};

/** @return list<string> */
$sourceFiles = static function (string $root, string $extension): array {
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== $extension) {
            continue;
        }

        if (str_contains($file->getPathname(), '/tests/')) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    return $files;
};

/** A Cfbd wired to a scripted provider. */
$cfbdWith = static function (array $answers): Cfbd {
    $settings = new AlmanacFakeSettings();

    return new Cfbd(new AlmanacScriptedHttp($answers), $settings, new Budget($settings));
};

return [
    /* ================================================== THE MARKUP === */

    'every CSS class these screens use actually exists' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 This is the test that was missing, and it cost a round of "this
         * looks sloppy": the admin screen was built on `form-field` and
         * `form-note`, neither of which Convoro defines, so none of it was
         * styled. A class name that does not exist is invisible in code review
         * and obvious on screen.
         *
         * Almanac's own `alm-*` rules count as defined, which is the other half
         * of the same check: a rule nobody defined is as broken as a class
         * nobody has.
         */
        $cssRoot = defined('CONVORO_ROOT') ? CONVORO_ROOT : dirname($root, 3) . '/fbsfb-convoro';
        $known = [];

        foreach (glob($cssRoot . '/public/assets/css/*.css') ?: [] as $sheet) {
            if (preg_match_all('/\.([a-zA-Z][\w-]*)/', (string) file_get_contents($sheet), $matches)) {
                foreach ($matches[1] as $class) {
                    $known[$class] = true;
                }
            }
        }

        assertTrue(count($known) > 100, 'the core stylesheets could not be read, so this test proves nothing');

        foreach (glob($root . '/Templates/**/styles.cvr') ?: [] as $own) {
            if (preg_match_all('/\.(alm-[\w-]*)/', (string) file_get_contents($own), $mine)) {
                foreach ($mine[1] as $class) {
                    $known[$class] = true;
                }
            }
        }

        foreach ($sourceFiles($root . '/Templates', 'cvr') as $path) {
            $code = $codeOf($path);

            if (!preg_match_all('/class="([^"]*)"/', $code, $attributes)) {
                continue;
            }

            foreach ($attributes[1] as $attribute) {
                $plain = preg_replace('/@\w+\s*\([^)]*\)|@\w+|\{\{.*?\}\}|\{!.*?!\}/s', ' ', $attribute) ?? '';

                foreach (preg_split('/\s+/', $plain) ?: [] as $class) {
                    if ($class === '' || preg_match('/^[a-zA-Z][\w-]*$/', $class) !== 1) {
                        continue;
                    }

                    assertTrue(
                        isset($known[$class]),
                        basename($path) . ' uses a CSS class that does not exist anywhere: .' . $class
                    );
                }
            }
        }
    },

    'every string a screen asks for is in the language file' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 A missing key renders as the key itself — `almanac.admin.restart`
         * printed on the page — which is the kind of thing that ships because
         * it only appears on the one screen nobody reopened.
         */
        $strings = require $root . '/Lang/en.php';

        foreach ($sourceFiles($root . '/Templates', 'cvr') as $path) {
            $code = $codeOf($path);

            if (!preg_match_all("/@(?:lang|choice)\(\s*'almanac\.([\w.]+)'/", $code, $keys)) {
                continue;
            }

            foreach ($keys[1] as $key) {
                assertTrue(
                    array_key_exists($key, $strings),
                    basename($path) . ' asks for a string that is not in Lang/en.php: almanac.' . $key
                );
            }
        }
    },

    'a jersey number of zero is still a jersey number' => static function () use ($root, $codeOf): void {
        /*
         * 🚨 Number 0 has been legal in college football since 2020 and 400
         * players on this site wear it — AK Dear at Alabama among them. `0` is
         * falsy in PHP, so `@notempty` renders an empty cell for a number the
         * player genuinely has.
         */
        foreach (['front/team.cvr', 'front/player.cvr'] as $template) {
            $code = $codeOf($root . '/Templates/' . $template);

            assertFalse(
                (bool) preg_match("/@notempty\(\s*\\\$(row|player)\['jersey'\]/", $code),
                $template . ' hides jersey 0 by testing it with @notempty'
            );
        }
    },

    'a headshot that 404s uncovers the initials instead of breaking' => static function () use ($root, $codeOf): void {
        /*
         * 🚨 A positive CFBD id means the player was matched to an ESPN
         * athlete, NOT that ESPN has a picture of him — most of Vanderbilt's
         * roster has none. The initials are therefore always rendered with the
         * photo stacked over them, and `onerror` removes the photo. Choosing
         * one or the other up front is what produced broken-image icons.
         */
        $code = $codeOf($root . '/Templates/front/player.cvr');

        assertTrue(str_contains($code, 'alm-initials'), 'the initials fallback is gone');
        assertTrue(str_contains($code, 'onerror'), 'nothing removes a headshot that failed to load');
        assertFalse(
            (bool) preg_match('/@empty\(\s*\$headshot\s*\)/', $code),
            'the initials are chosen instead of the photo again, so a 404 shows a broken image'
        );
    },

    /* ================================================ THE PROVIDER === */

    'a synthesised negative player id survives' => static function () use ($cfbdWith): void {
        /*
         * 🚨 CFBD returns an ESPN athlete id where it has one and a NEGATIVE id
         * where it does not. Dropped or made unsigned, hundreds of players
         * collide onto one row and a roster is mysteriously short.
         */
        $cfbd = $cfbdWith([[200, [[
            'id' => '-1007404', 'team' => 'Clemson', 'firstName' => 'Jerrodd',
            'lastName' => 'Williams', 'position' => 'S', 'year' => 3,
        ]], []]]);

        [$rows, $error] = $cfbd->roster(2013);

        assertSame('', $error);
        assertSame(1, count($rows), 'a negative id was dropped');
        assertSame(-1007404, $rows[0]['cfbd_id'], 'a negative id did not survive the transform');
    },

    'a class year outside one to five is not recorded as one' => static function () use ($cfbdWith): void {
        /*
         * 🚨 The bug that killed the very first run. CFBD's roster `year` is not
         * always the class: older rows carry the SEASON (`"year":2005`) and some
         * current ones do too. Straight into a `tinyint` that is an
         * out-of-range error which fails the whole chunk of 400 players.
         */
        $cfbd = $cfbdWith([[200, [
            ['id' => '1', 'team' => 'Clemson', 'firstName' => 'A', 'lastName' => 'B', 'year' => 2005],
            ['id' => '2', 'team' => 'Clemson', 'firstName' => 'C', 'lastName' => 'D', 'year' => 4],
            ['id' => '3', 'team' => 'Clemson', 'firstName' => 'E', 'lastName' => 'F', 'year' => 0],
        ], []]]);

        [$rows] = $cfbd->roster(2025);

        assertSame(null, $rows[0]['class_year'], 'a season was stored as a class year');
        assertSame(4, $rows[1]['class_year'], 'a real class year was thrown away');
        assertSame(null, $rows[2]['class_year'], 'zero was stored as a class year');
    },

    'a provider that does not answer is not mistaken for one with no data' => static function () use ($cfbdWith): void {
        /*
         * 🚨 The distinction the whole sync rests on. "Nobody answered" must
         * leave every row alone; "there is nothing for that year" is a fact to
         * record and move past.
         */
        $silent = $cfbdWith([[0, [], []]]);
        [$rows, $error] = $silent->teams(2026);
        assertSame('unreachable', $error);
        assertSame(0, count($rows));

        $rejected = $cfbdWith([[401, [], []]]);
        [, $keyError] = $rejected->teams(2026);
        assertSame('invalid_key', $keyError);

        $empty = $cfbdWith([[200, [], []]]);
        [$none, $noError] = $empty->teams(2026);
        assertSame('', $noError, 'an empty year was reported as a failure');
        assertSame(0, count($none));
    },

    'only FBS teams are kept, whatever the endpoint answers with' => static function () use ($cfbdWith): void {
        $cfbd = $cfbdWith([[200, [
            ['id' => 228, 'school' => 'Clemson', 'classification' => 'fbs', 'conference' => 'ACC'],
            ['id' => 999, 'school' => 'Someone Else', 'classification' => 'fcs', 'conference' => 'X'],
        ], []]]);

        [$rows] = $cfbd->teams(2026);

        assertSame(1, count($rows), 'a non-FBS programme was stored');
        assertSame('Clemson', $rows[0]['school']);
    },

    /* ================================================== THE BUDGET === */

    'the guard stops above the reserve rather than at zero' => static function (): void {
        /*
         * 🚨 The reserve exists so a runaway backfill cannot end the month.
         * Picks spends from the same thousand-a-month allowance.
         */
        $settings = new AlmanacFakeSettings(['reserve' => '50', 'cap' => '500']);
        $budget = new Budget($settings);

        $budget->record(['x-calllimit-remaining' => '51']);
        assertTrue($budget->may(), 'stopped while there was still headroom above the reserve');

        $budget->record(['x-calllimit-remaining' => '50']);
        assertFalse($budget->may(), 'spent into the reserve');
        assertSame('budget_reserve', $budget->reason());
    },

    'a call that failed still counted' => static function (): void {
        /*
         * 🚨 A 401 carries the header and still costs a call. Counting only
         * successes is how a run with a bad key burns the month believing it
         * has spent nothing.
         */
        $settings = new AlmanacFakeSettings(['reserve' => '0', 'cap' => '2']);
        $budget = new Budget($settings);

        $budget->record([]);
        $budget->record([]);

        assertSame(2, $budget->spent());
        assertFalse($budget->may(), 'the run cap ignored calls that failed');
        assertSame('run_cap', $budget->reason());
    },

    'an unknown budget is spendable, or nothing would ever learn it' => static function (): void {
        /*
         * 🚨 Before the first call of a run nothing has reported a figure. A
         * guard that refused to start without one would deadlock, and it would
         * look exactly like a broken API key.
         */
        $budget = new Budget(new AlmanacFakeSettings(['reserve' => '900', 'cap' => '40']));

        assertSame(null, $budget->remaining());
        assertTrue($budget->may(), 'the first call of a run was refused');
    },

    /* ==================================================== THE PLAN === */

    'the plan makes the site usable before it makes it complete' => static function () use ($db): void {
        /*
         * 🚨 Teams first, because everything else resolves a team NAME to an id.
         * Then this season's rosters, this year's class and this season's
         * stats — about twenty calls, after which every page renders. Ordering
         * by dataset instead would spend the same calls and leave the site with
         * every team's 2021 rushing totals and no current roster.
         */
        $settings = new AlmanacFakeSettings();
        $sync = new Sync(
            new Cfbd(new AlmanacScriptedHttp(), $settings, new Budget($settings)),
            new Store($db),
            $settings,
            new Budget($settings),
            new AlmanacSpyPhotos(),
        );

        $plan = $sync->plan('backfill');
        $kinds = array_column($plan, 'kind');

        assertSame('teams', $kinds[0], 'something is fetched before the teams it needs to resolve');
        assertSame('roster', $kinds[1], 'the current roster is not fetched first');

        $firstStats = array_search('stats', $kinds, true);
        $firstHistory = null;

        foreach ($plan as $index => $step) {
            if ($step['kind'] === 'roster' && ($step['current'] ?? false) === false) {
                $firstHistory = $index;
                break;
            }
        }

        assertTrue(
            $firstHistory === null || $firstStats < $firstHistory,
            'an older season is fetched before this season is finished'
        );
    },

    'seasons are walked newest first' => static function () use ($db): void {
        /*
         * 🚨 `Store` relies on this. Who a player is now — his team, his jersey
         * — is written from the first roster the plan reaches. Reverse the order
         * and every senior is filed under the school he was a freshman at.
         */
        $settings = new AlmanacFakeSettings(['season' => '2026', 'back' => '4']);
        $sync = new Sync(
            new Cfbd(new AlmanacScriptedHttp(), $settings, new Budget($settings)),
            new Store($db),
            $settings,
            new Budget($settings),
            new AlmanacSpyPhotos(),
        );

        $years = [];

        foreach ($sync->plan('backfill') as $step) {
            if ($step['kind'] === 'roster') {
                $years[] = (int) $step['year'];
            }
        }

        $descending = $years;
        rsort($descending);

        assertSame($descending, $years, 'rosters are not walked newest first');
        assertTrue(count($years) > 1, 'the history loop no longer produces older seasons');
    },

    'the portal asks for the cycle that actually has entries' => static function () use ($db): void {
        /*
         * 🚨 This shipped asking only for `season + 1`, a cycle that has not
         * opened. It stored nothing and rendered as a page headed "2027
         * transfer portal" showing nothing at all — a page that looks broken
         * while the sync reports success.
         */
        $settings = new AlmanacFakeSettings(['season' => '2026']);
        $sync = new Sync(
            new Cfbd(new AlmanacScriptedHttp(), $settings, new Budget($settings)),
            new Store($db),
            $settings,
            new Budget($settings),
            new AlmanacSpyPhotos(),
        );

        $years = [];

        foreach ($sync->plan('refresh') as $step) {
            if ($step['kind'] === 'portal') {
                $years[] = (int) $step['year'];
            }
        }

        assertTrue(in_array(2026, $years, true), 'the current portal cycle is never fetched');
    },

    'a refresh does not re-fetch every week of game logs' => static function () use ($db): void {
        /*
         * 🚨 Game logs are the most expensive dataset — a call per week, with no
         * whole-season form of the endpoint. Re-fetching all sixteen weekly
         * would spend more than the rest of the plan combined rewriting rows
         * that have not changed since September.
         */
        $settings = new AlmanacFakeSettings();
        $sync = new Sync(
            new Cfbd(new AlmanacScriptedHttp(), $settings, new Budget($settings)),
            new Store($db),
            $settings,
            new Budget($settings),
            new AlmanacSpyPhotos(),
        );

        $backfill = count(array_filter($sync->plan('backfill'), static fn (array $s): bool => $s['kind'] === 'game_stats'));
        $refresh = count(array_filter($sync->plan('refresh'), static fn (array $s): bool => $s['kind'] === 'game_stats'));

        assertTrue($backfill >= 16, 'a backfill no longer covers the season');
        assertTrue($refresh <= 2, 'a refresh re-fetches the whole season of game logs');
    },

    /* ================================================== THE PAGES === */

    'nothing a page renders can make an outbound call' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 The rule the whole extension rests on. A page that fetched would
         * exhaust a thousand-call month in an afternoon and take Picks with it.
         * `Http` is reachable only from `Cfbd`, and `Cfbd` only from `Sync`.
         */
        foreach ($sourceFiles($root . '/Controllers', 'php') as $path) {
            $code = $codeOf($path);

            assertFalse(
                str_contains($code, 'almanac.http') || str_contains($code, 'almanac.cfbd'),
                basename($path) . ' can reach the provider from a request'
            );

            /*
             * 🚨 The schools' sites cost no CFBD allowance, but they are still
             * a fetch, and a roster page is megabytes of HTML. Reading a dozen
             * of them inside somebody's click is the same mistake with a
             * different provider. The admin screen may ask `Photos` what it
             * already knows; it may not ask it to go and look.
             */
            assertFalse(
                str_contains($code, 'almanac.athletics'),
                basename($path) . ' can read an athletics site from a request'
            );
            assertFalse(
                (bool) preg_match("/almanac\.photos'\s*\)\s*->\s*(run|apply)\s*\(/", $code),
                basename($path) . ' reads rosters inside the request instead of queueing'
            );
            assertFalse(
                (bool) preg_match('/\bcurl_\w+\s*\(/', $code),
                basename($path) . ' calls curl directly'
            );
        }

        foreach ($sourceFiles($root . '/Templates', 'cvr') as $path) {
            assertFalse(
                (bool) preg_match('/\bcurl_\w+\s*\(|file_get_contents\s*\(\s*[\'"]http/', $codeOf($path)),
                basename($path) . ' fetches while rendering'
            );
        }
    },

    'a player with no ESPN athlete id is offered no photo' => static function () use ($db): void {
        $players = new Players($db);

        assertSame(null, $players->headshot(-1007404), 'a synthesised id was given a headshot URL that cannot resolve');
        assertTrue(str_contains((string) $players->headshot(4685413), '4685413'));
    },

    'heights read as feet and inches, and nothing reads as zero' => static function () use ($db): void {
        $players = new Players($db);

        assertSame('6-2', $players->height(74));
        assertSame(null, $players->height(0), 'a missing height rendered as a height');
        assertSame(null, $players->height(null));
    },

    /* =================================================== THE SEAMS === */

    'the core constraint still matches the seams used' => static function () use ($root, $codeOf): void {
        $manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);
        $code = $codeOf($root . '/Almanac.php');

        assertSame('^1.5.0', $manifest['convoro'] ?? '', 'the core constraint no longer matches the seams used');
        assertTrue(str_contains($code, '$this->schedule()'), 'the seam that sets the floor is gone');
        assertTrue(str_contains($code, 'widget_sections'), 'the widget section registry is no longer used');
    },

    /* ============================================== THE PHOTOGRAPHS === */

    'a name that matches two players gives neither of them a face' => static function () use ($db): void {
        /*
         * 🚨 The bug this whole matcher is shaped around. Brothers on one
         * roster are not rare, and first initial plus surname cannot tell them
         * apart — so where the jersey cannot settle it, nobody gets the photo.
         * A player page with no picture is honest. A player page with his
         * brother's face on it is wrong in a way nothing on the screen reveals,
         * and the only person who would ever notice is the one it is about.
         */
        $photos = new Photos($db, new Athletics(new AlmanacScriptedHttp()), new AlmanacFakeSettings());

        $squad = [
            ['id' => 1, 'first_name' => 'Marcus', 'last_name' => 'Smith', 'name' => 'Marcus Smith', 'jersey' => 7],
            ['id' => 2, 'first_name' => 'Malik', 'last_name' => 'Smith', 'name' => 'Malik Smith', 'jersey' => 22],
            ['id' => 3, 'first_name' => 'Jaden', 'last_name' => 'Okafor', 'name' => 'Jaden Okafor', 'jersey' => 4],
        ];

        $ambiguous = $photos->pair(
            [['first' => 'M', 'last' => 'Smith', 'jersey' => null, 'photo' => 'https://x/1.jpg']],
            $squad,
        );

        assertSame([], $ambiguous, 'a photo was hung on one of two players who share a name');

        $settled = $photos->pair(
            [['first' => 'Marcus', 'last' => 'Smith', 'jersey' => 7, 'photo' => 'https://x/1.jpg']],
            $squad,
        );

        assertSame([1 => 'https://x/1.jpg'], $settled, 'the jersey did not separate two players who share a name');

        /* The ordinary case still has to work, spelling differences and all. */
        $ordinary = $photos->pair(
            [['first' => 'J.', 'last' => "O'Kafor Jr.", 'jersey' => null, 'photo' => 'https://x/3.jpg']],
            $squad,
        );

        assertSame([3 => 'https://x/3.jpg'], $ordinary, 'a punctuated name stopped matching the same man');

        /*
         * 🚨 And a nickname printed inside the name still matches. Kentucky's
         * roster carries `Elijah "Bo" Barnes` where CFBD has `Elijah Barnes`,
         * and a key built from every word compares `bobarnes` to `barnes` and
         * matches nobody — silently, for a whole school.
         */
        $nickname = $photos->pair(
            [['first' => 'Elijah', 'last' => '"Bo" Barnes', 'jersey' => 0, 'photo' => 'https://x/4.jpg']],
            [['id' => 4, 'first_name' => 'Elijah', 'last_name' => 'Barnes', 'name' => 'Elijah Barnes', 'jersey' => 0]],
        );

        assertSame([4 => 'https://x/4.jpg'], $nickname, 'a nickname in the name stopped him matching himself');

        /*
         * 🚨 And the same rule facing the other way. Two of the readers pick up
         * coaches as well as players, because those sites build both cards from
         * the same markup — so a father coaching his son would otherwise put
         * his own face on the boy's page, decided by nothing but who the page
         * listed last.
         */
        $coachAndPlayer = $photos->pair(
            [
                ['first' => 'Jaden', 'last' => 'Okafor', 'jersey' => null, 'photo' => 'https://x/coach.jpg'],
                ['first' => 'Jaden', 'last' => 'Okafor', 'jersey' => null, 'photo' => 'https://x/player.jpg'],
            ],
            $squad,
        );

        assertSame([], $coachAndPlayer, 'one of two identical names on the school page was picked anyway');
    },

    'a roster page nobody can parse any more returns nothing, not nonsense' => static function (): void {
        /*
         * 🚨 Two of the four readers are HTML parsers, against markup nobody
         * owes Almanac, and August is when athletics sites get rebuilt. The
         * failure has to be an EMPTY answer — which leaves every stored photo
         * alone — rather than half-read names, which would hang faces on the
         * wrong men and look like data.
         */
        $http = new AlmanacScriptedHttp();
        $http->pages = ['/sports/football/roster' => [200, '<html><body><h1>Roster</h1><p>Coming soon</p></body></html>']];

        $athletics = new Athletics($http);

        [$classic, $classicError] = $athletics->roster('example.com', 'classic');
        [$wpx, $wpxError] = $athletics->roster('example.com', 'wpx');

        assertSame([], $classic, 'the classic reader invented players out of a page it did not understand');
        assertSame([], $wpx, 'the WordPress reader invented players out of a page it did not understand');
        assertTrue($classicError !== '' && $wpxError !== '', 'a page it cannot read was reported as a good read');
    },

    'a player with no photograph on his school page is not stored as one' => static function (): void {
        $http = new AlmanacScriptedHttp();
        $http->pages = [
            '/api/v2/Rosters' => [200, json_encode(['items' => [['players' => [
                ['firstName' => 'A', 'lastName' => 'Player', 'jerseyNumber' => '1',
                 'image' => ['absoluteUrl' => 'https://school.test/a.jpg']],
                ['firstName' => 'No', 'lastName' => 'Picture', 'jerseyNumber' => '2', 'image' => null],
                ['firstName' => 'Blank', 'lastName' => 'Picture', 'jerseyNumber' => '3',
                 'image' => ['absoluteUrl' => '']],
            ]]]])],
        ];

        [$players, $error] = (new Athletics($http))->roster('school.test', 'sidearm', 3);

        assertSame('', $error);
        assertSame(1, count($players), 'a player with no photograph was kept as though he had one');
        assertSame('https://school.test/a.jpg', $players[0]['photo']);
    },

    'the reader asks for every sport, not the first page of them' => static function (): void {
        /*
         * 🚨 The trap that made Clemson look like a school without football.
         * Every list on that platform pages at fifteen and departments run
         * twenty-odd sports, so the default page carries whichever ones sort
         * first — and the reader concluded the site had no football at all.
         */
        $http = new AlmanacScriptedHttp();
        $http->pages = [
            '/website-api/sports' => [200, json_encode(['data' => [
                ['id' => 20, 'name' => 'Football', 'slug' => 'football', 'default_roster_id' => 900],
            ]])],
            '/website-api/player-rosters' => [200, json_encode(['data' => [
                ['jersey_number' => 5, 'player' => ['first_name' => 'A', 'last_name' => 'Player'],
                 'photo' => ['url' => 'https://school.test/p.jpg']],
            ]])],
        ];

        [$players, $error] = (new Athletics($http))->roster('school.test', 'wmt');

        assertSame('', $error);
        assertSame(1, count($players));

        $sports = array_values(array_filter(
            $http->asked,
            static fn (string $url): bool => str_contains($url, '/website-api/sports'),
        ));

        assertTrue($sports !== [], 'the sports list was never asked for');
        assertTrue(str_contains($sports[0], 'per_page=200'), 'only the first page of sports was asked for');

        /*
         * 🚨 And the roster comes from the SPORT'S own pointer. Picking the
         * newest roster id instead lands on a signing class, which is also a
         * roster and is created later than the season's.
         */
        $rosters = array_values(array_filter(
            $http->asked,
            static fn (string $url): bool => str_contains($url, 'player-rosters'),
        ));

        assertTrue(str_contains($rosters[0], '900'), 'the roster the sport points at was not the one read');
    },

    'photographs keep going when the CFBD budget has stopped' => static function () use ($db): void {
        /*
         * 🚨 They are a different provider — each school's own site, free to
         * read — and almost every tick of this job returns down the idle path,
         * because that is what an up-to-date mirror does. A photo pass that sat
         * below the CFBD work would run during a backfill, then once a week,
         * then never again on a site that had finished syncing.
         */
        $settings = new AlmanacFakeSettings(['season' => '2026', 'almanac_enabled' => '1']);
        $settings->put('almanac_sync_cursor', json_encode(['mode' => 'refresh', 'i' => 0]));
        $settings->put('almanac_sync_ok_at', (string) time());

        $spy = new AlmanacSpyPhotos();
        $sync = new Sync(
            new Cfbd(new AlmanacScriptedHttp(), $settings, new Budget($settings)),
            new Store($db),
            $settings,
            new Budget($settings),
            $spy,
        );

        $result = $sync->run();

        assertSame('idle', $result['status'], 'this test no longer exercises the idle path');
        assertSame(1, $spy->runs, 'an idle tick skipped the photographs, so a finished site would never get any');
    },

    'the shipped catalogue is a catalogue' => static function () use ($root): void {
        /*
         * 130-odd hand-verified domains are data, and data rots quietly. This
         * is the guard that a bad edit — a duplicate school, a platform that no
         * reader answers to, a URL pasted where a host belongs — fails here
         * rather than as a school whose photographs silently stop.
         */
        $rows = json_decode((string) file_get_contents($root . '/Data/athletics-sites.json'), true);

        assertTrue(is_array($rows) && count($rows) > 120, 'the shipped catalogue is missing or has been emptied');

        $seen = [];

        foreach ($rows as $row) {
            $school = (string) ($row['school'] ?? '?');

            assertTrue(((int) ($row['cfbd_id'] ?? 0)) > 0, $school . ' has no provider id to match on');
            assertFalse(isset($seen[$row['cfbd_id']]), $school . ' appears in the catalogue twice');
            $seen[$row['cfbd_id']] = true;

            assertTrue(
                in_array($row['platform'] ?? '', Athletics::PLATFORMS, true),
                $school . ' is on a platform no reader answers to: ' . ($row['platform'] ?? '')
            );
            assertTrue(
                (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', (string) ($row['domain'] ?? '')),
                $school . ' has something that is not a bare host: ' . ($row['domain'] ?? '')
            );
        }
    },

    'an operator’s own domain is never overwritten by the shipped one' => static function () use ($root, $codeOf): void {
        /*
         * 🚨 The catalogue is a best effort at a hundred and thirty guessable
         * domains; the operator is the one who KNOWS. Anybody whose correction
         * was silently reverted by an update would, quite reasonably, never
         * trust the field again.
         */
        $code = $codeOf($root . '/Services/Photos.php');

        assertTrue(
            str_contains($code, "`site_source` = 'catalogue' OR `site_domain` = ''"),
            'the shipped catalogue can now overwrite a domain somebody typed in'
        );
        assertTrue(str_contains($code, "'manual'"), 'nothing marks a hand-set site as the operator’s own');
    },

    'the school’s photograph wins, and ESPN still fills the gap' => static function () use ($db): void {
        $players = new Players($db);

        assertSame(
            'https://school.test/p.jpg',
            $players->portrait(['cfbd_id' => 4685413, 'photo_url' => 'https://school.test/p.jpg']),
            'ESPN was preferred over the school’s own photograph'
        );
        assertTrue(
            str_contains((string) $players->portrait(['cfbd_id' => 4685413, 'photo_url' => '']), '4685413'),
            'a player with no school photograph lost his ESPN one too'
        );
        assertSame(
            null,
            $players->portrait(['cfbd_id' => -1007404, 'photo_url' => null]),
            'a synthesised id was given a photo URL that cannot resolve'
        );
    },

    'the forum panel is gated on the viewer, not just on a forum existing' => static function () use ($root, $codeOf): void {
        /*
         * 🚨 Convoro has already shipped one bug that served restricted forums
         * to anybody who asked. A "recent threads" panel on a public reference
         * page is exactly the shape that repeats it, so the permission check
         * lives in the controller and `Teams::forumTopics()` never decides.
         */
        $controller = $codeOf($root . '/Controllers/Front/TeamController.php');

        assertTrue(str_contains($controller, 'forum.visibility'), 'the forum panel no longer checks visibility');
        assertTrue(str_contains($controller, 'maySee'), 'nothing asks whether this viewer may see that forum');

        $service = $codeOf($root . '/Services/Teams.php');

        assertFalse(
            str_contains($service, 'maySee'),
            'the visibility check moved into the query, where the caller can forget it'
        );
    },
];
