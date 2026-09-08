# Roster

Every team, roster and player, for [Convoro](https://convoro.co).

Browse by conference or division, open a club for its roster, open a player for
who he is. In college football that goes further: his career stats season by
season, and the high-school class he came out of, plus a national recruiting
board and a transfer-portal tracker.

Third-party extension by Ernest Defoe. Requires Convoro **^1.5.0**.

> This was called **Almanac**. The name was a college-football word for a
> college-football extension, and it now holds the NFL, the NBA, MLB, the NHL
> and league football too. The manifest key and the table names are unchanged —
> renaming those would orphan an installed site's data to make a label read
> better.

## Which leagues

| League | Source | What you get |
|---|---|---|
| College football | CollegeFootballData | Rosters, career stats, recruiting class, transfer portal |
| NFL, NBA, MLB, NHL, MLS, Premier League, WNBA | ESPN | The current roster, with position, number, size and hometown |

College football is always on. The rest are tick boxes in **Admin → Roster**,
and they cost nothing: ESPN needs no key and does not count against the
CollegeFootballData allowance.

🚨 **The professional leagues carry a current roster and nothing historical**,
because that is what ESPN's roster endpoint answers. There is no season picker
on an NBA club's page, and no recruiting or portal panel, because those are not
thin versions of something — they do not exist in professional sport. Showing
empty panels would read as a broken page rather than as a sport that does not
have the thing.

## What it does

- **Conference index** — all 130-odd FBS programmes grouped by conference, as
  crest tiles.
- **School page** — record, SP+, recruiting class rank, the full roster grouped
  into offence, defence and specialists, the incoming class, portal arrivals,
  and recent threads from that school's forum.
- **Player page** — headshot, bio, career stats season by season, game-by-game
  lines, portal history, and **the recruiting profile he came out of high school
  with**. That last one is the point: a true freshman has no college stats, and
  his page is still worth opening.
- **Recruiting board** — a whole class, filtered by position, state, stars,
  commitment status or free text, filtered and paged in SQL.
- **Portal tracker** — who entered, where from, where to.

## Where the data comes from

[CollegeFootballData](https://collegefootballdata.com). One API key, free.

🚨 **The free tier allows a thousand calls per calendar month**, and Picks draws
on the same allowance. That single fact shapes the whole extension:

- **Nothing is fetched while a page is rendering.** Everything on screen is a
  row a scheduled job wrote, and every page says so.
- **The sync is capped and resumable.** It walks an ordered plan, spends up to
  `almanac_run_cap` calls, writes down where it stopped, and carries on next
  tick.
- **A budget guard** reads `x-calllimit-remaining` off every response and stops
  above a reserve floor rather than at zero.
- **The plan is ordered so a half-done backfill is still a working site** —
  teams, this season's rosters, this year's class, this season's stats, and
  after roughly twenty calls every page renders.

A first backfill is around a hundred calls. After that it idles until due,
weekly by default.

### Why not ESPN

ESPN's `site.api.espn.com` roster and team endpoints answer **403 from a
datacenter IP** — Akamai blocks them — so they are not a usable source from a
server. Only the `site.web.api` athlete endpoints answer. Scraping 130 school
athletics sites instead would mean 130 parsers that break every August, when one
CFBD call returns every roster in the country.

ESPN is still used as the fallback for **headshots**. CFBD's player ids *are*
ESPN athlete ids, so `a.espncdn.com/i/headshots/...` resolves directly.

## Photographs, from the schools themselves

ESPN has no picture for a great many real athletes, and almost never for a true
freshman — the player this whole extension exists to make worth looking up. His
school photographed him in June.

So Roster also reads each school's own roster and stores the photograph it
finds. **This spends no CollegeFootballData calls** — the athletics sites are a
separate provider — so it keeps running on its own cadence while the CFBD mirror
sits idle, a dozen schools a day by default.

🚨 **A face is never guessed.** Where a name matches two players and the jersey
cannot separate them — brothers, and it is not rare — neither gets the photo. A
page with no picture is honest; a page with his brother's face on it is wrong in
a way nothing on screen reveals.

Roster ships a catalogue of **134 of the 138 FBS athletics sites**, each one
verified by checking that the roster it serves is the roster Roster already
holds for that school. That check matters: athletics-site domains are nicknames
(`rolltide.com`, `ramblinwreck.com`) that no provider carries, and the obvious
way to find them — the football article's external links — is full of citations
to the *opponent's* site. Four schools are unresolved (Arkansas, Georgia Tech,
Utah State, Wyoming); their players fall back to ESPN until somebody fills the
domain in.

**Admin → Roster** lists every school with the site it reads. A domain typed in
there is kept as yours and is never overwritten by an update.

### The four platforms

Athletics departments are mid-migration between four site builds, and the
platform decides which endpoint answers:

| Platform  | How it answers | Schools |
| --------- | -------------- | ------- |
| `sidearm` | `/api/v2/sports` → `/api/v2/Rosters?sportId=N` | 86 |
| `wmt`     | `/website-api/sports` → `/website-api/player-rosters` | 26 |
| `classic` | the older server-rendered SIDEARM page | 19 |
| `wpx`     | a WordPress build; players keyed off the photo's `title` | 3 |

🚨 The two HTML readers depend on markup nobody owes Roster, and August is when
athletics sites get rebuilt. Both return **nothing** rather than nonsense when
the shape changes, and a school that returns nothing keeps the photographs it
already had — so the failure mode is staleness the admin screen can show, not a
roster of wrong faces.

## Setup

1. Install and enable the extension.
2. **Admin → Roster**: switch it on. If Picks already holds a
   CollegeFootballData key, Roster borrows it and you can leave the field
   blank; otherwise paste one.
3. Press **Run a sync now**, or wait for the daily tick. The backfill takes a
   few runs.

Optional: `Seasons of history` (default 6, enough for a fifth-year senior's
whole career) and `Recruiting classes` (default 8, enough that everybody on a
current roster has a high school).

## Traps worth knowing

🚨 **Player ids are signed.** CFBD returns an ESPN athlete id where it has one
and a synthesised **negative** id where it does not (`-1007404`). Stored
unsigned, every negative id clamps to 0 and hundreds of players collide onto one
row. `cfbd_id > 0` is also what says a headshot exists.

🚨 **An empty year is normal, not a failure.** CFBD's early coverage is patchy:
2004 returns season stats and 2005 returns none for the same team and category.
The cursor advances past an empty step, or it would stall there forever.

🚨 **`/games/players` requires a week.** There is no whole-season form, so game
logs cost about sixteen calls a season and are a setting rather than always on.

🚨 **The extension directory must be named after its manifest key.** This repo
is `convoro-almanac`, so package from a copy named `almanac`:

```bash
php tools/convoro ext:package almanac && php tools/convoro ext:install almanac-1.0.0.zip
```

## Picks and forums are optional

Roster does not depend on Picks. It borrows Picks' API key if one is there, and
reads `picks_teams.forum_id` to point each school at its forum — both wrapped,
so on a site without Picks it asks for its own key and school pages simply show
no discussion panel.

🚨 The forum panel is gated on the **viewer** via `ForumVisibility::maySee()`,
in the controller. A "recent threads" panel on a public reference page is
exactly the shape that leaks a private forum.

## Licence

MIT.
