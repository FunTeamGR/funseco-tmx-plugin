<?php
namespace Funseco\Tmx\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use React\Http\Message\Response;
use Funseco\Core\Testing\FakesServer;
use Funseco\Tmx\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\TrackExchangeInfoLoaded;
use Funseco\Core\SharedKernel\TrackExchange;
use Funseco\Core\Services\Defaults\NullTrackExchange;
use Funseco\Tmx\Exchange\TmxExchange;
use function React\Promise\reject;
use function React\Promise\resolve;

class TmxLookupTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    /** @var string[] */
    private array $urls = [];
    private bool $down = false;
    private Player $alice;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();
        config(['adminops.masteradmins' => ['boss']]);
        $this->alice = Player::factory()->create(['login' => 'alice', 'nickname' => 'Alice']);
        CurrentPlayers::add($this->alice);
        $this->map = Map::factory()->create(['uid' => 'BeySZdnfuSh4nHY5xztiXLmlrXe', 'name' => '$f00A01-Race', 'environment' => 'Stadium']);

        $http = Mockery::mock(AsyncHttpClient::class);
        $http->shouldReceive('get')->andReturnUsing(function (string $url) {
            $this->urls[] = $url;
            if ($this->down) {
                return reject(new \RuntimeException('down'));
            }

            if (str_contains($url, 'apiget.aspx')) {
                return resolve(new Response(200, [], $this->legacy($url)));
            }

            return resolve(new Response(200, [], json_encode($this->api($url))));
        });
        $this->app->instance(AsyncHttpClient::class, $http);
        $this->app->forgetInstance(TmxExchange::class);
        $this->app->forgetInstance(TrackExchange::class);
        $this->calls = [];
    }

    private function legacy(string $url): string
    {
        if (str_contains($url, 'apitrackrecords')) {
            return "501\t7\tOldTimer\t45120\t4/11/2008 9:27:57 PM\t4/10/2008 1:00:00 AM\t1\t0\t\t\n502\t8\tSecond\t46000\t\t\t1\t0\t\t\n";
        }
        if (str_contains($url, 'id=404')) {
            return '';
        }

        return "777\tSunny Ride\t5\tOldAuthor\t4/11/2008 9:27:57 PM\t4/12/2008 1:00:00 AM\t1\tRace\tBay\tDay\tFullSpeed\tSingle\t0:45\tNormal\t3\tTMS\tnice<br/>one\t12\t0\t1\t321\n";
    }

    private function api(string $url): array
    {
        if (str_contains($url, '/api/replays')) {
            return ['More' => false, 'Results' => [
                ['ReplayId' => 99, 'User' => ['Name' => 'Axell'], 'ReplayTime' => 23770, 'ReplayScore' => 25, 'ReplayAt' => '2025-09-16T18:43:03', 'TrackAt' => '2008-04-11T21:27:57'],
                ['ReplayId' => 98, 'User' => ['Name' => 'Bob'], 'ReplayTime' => 24100, 'ReplayScore' => 20],
            ]];
        }
        if (str_contains($url, '/api/tracks?') && str_contains($url, 'fields=TrackId,TrackName,UId')) {
            if (str_contains($url, 'name=') || str_contains($url, 'order1=0') || str_contains($url, 'author=')) {
                return ['More' => false, 'Results' => [
                    ['TrackId' => 2233, 'TrackName' => 'A01-Race', 'Authors' => [['User' => ['Name' => 'Nadeo']]], 'Environment' => 7],
                    ['TrackId' => 2234, 'TrackName' => 'Other Track', 'Authors' => [['User' => ['Name' => 'Some One']]], 'Environment' => 7],
                ]];
            }
            if (str_contains($url, 'uid=nope') || str_contains($url, 'id=404')) {
                return ['More' => false, 'Results' => []];
            }

            return ['More' => false, 'Results' => [[
                'TrackId' => 2233, 'TrackName' => 'A01-Race', 'UId' => 'BeySZdnfuSh4nHY5xztiXLmlrXe',
                'Authors' => [['User' => ['UserId' => 1001, 'Name' => 'Nadeo']]], 'UploadedAt' => '2008-04-12T00:00:00',
                'UpdatedAt' => '2008-04-11T21:27:57', 'PrimaryType' => 0, 'Environment' => 7, 'Mood' => 2, 'Style' => 0, 'Routes' => 0,
                'Difficulty' => 0, 'Length' => 1, 'Awards' => 357, 'AuthorTime' => 24540, 'TrackValue' => 0,
                'AuthorComments' => 'x', 'WRReplay' => ['ReplayId' => 12847157],
            ]]];
        }

        return [];
    }

    public function test_null_default_until_the_plugin_binds_its_own()
    {
        $this->assertInstanceOf(TmxExchange::class, app(TrackExchange::class));
        $null = new NullTrackExchange;
        $this->assertNull($null->current());
        $resolved = 'unset';
        $null->forUid('x')->then(function ($t) use (&$resolved) {
            $resolved = $t;
        });
        $this->assertNull($resolved);
    }

    public function test_map_start_looks_the_track_up_and_announces_the_world_record()
    {
        Event::fake([TrackExchangeInfoLoaded::class]);

        app(TmxExchange::class)->mapStarted($this->map);

        $track = app(TrackExchange::class)->current();
        $this->assertSame(2233, $track->id);
        $this->assertSame('Nadeo', $track->author);
        $this->assertSame('Sunset', $track->mood);
        $this->assertSame('Beginner', $track->difficulty);
        $this->assertSame('Race', $track->type);
        $this->assertSame('Stadium', $track->environment);
        $this->assertSame('24s', $track->length);
        $this->assertSame('Classic!', $track->lbRating);
        $this->assertSame('TMNF', $track->section);
        $this->assertSame('https://tmnf.exchange/trackshow/2233', $track->pageUrl);
        $this->assertSame('https://tmnf.exchange/recordgbx/12847157', $track->replayUrl);
        $this->assertCount(2, $track->records);
        $this->assertStringContainsString('uid=BeySZdnfuSh4nHY5xztiXLmlrXe', $this->urls[0]);
        $this->assertStringContainsString('Authors%5B%5D', $this->urls[0]);
        $this->assertContains(format_colors('{#server}>> {#record}TMX World Record: {#highlite}0:23.77{#record} by {#highlite}Axell'), $this->messages('ChatSendServerMessage'));
        Event::assertDispatched(TrackExchangeInfoLoaded::class, fn ($e) => $e->track?->id === 2233);
    }

    public function test_unknown_track_or_tmx_down_stays_silent()
    {
        Event::fake([TrackExchangeInfoLoaded::class]);
        $this->down = true;
        app(TmxExchange::class)->mapStarted($this->map);

        $this->assertNull(app(TrackExchange::class)->current());
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
        Event::assertDispatched(TrackExchangeInfoLoaded::class, fn ($e) => $e->track === null);
    }

    public function test_show_tmxrec_zero_turns_the_message_off()
    {
        config(['tmx.show_tmxrec' => 0]);
        app(TmxExchange::class)->mapStarted($this->map);
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
    }

    public function test_lookups_are_cached_per_map()
    {
        $exchange = app(TmxExchange::class);
        $exchange->mapStarted($this->map);
        $n = count($this->urls);
        $exchange->forUid($this->map->uid);   // same section as the map lookup
        $this->assertSame($n, count($this->urls));
    }

    public function test_tmxinfo_current_track_shows_the_window_with_image_and_links()
    {
        app(TmxExchange::class)->mapStarted($this->map);
        CurrentMap::set($this->map);
        $this->urls = [];

        Chat::resolve('/tmxinfo', 'alice');

        $xml = $this->window();
        $this->assertSame([], $this->urls, 'served from the cache');
        $this->assertStringContainsString('TMX Info for:', $xml);
        $this->assertStringContainsString('A01-Race', $xml);
        $this->assertStringContainsString('image="http://tmnforever.tm-exchange.com/get.aspx?action=trackscreen&amp;id=2233&amp;.jpg"', $xml);
        $this->assertStringContainsString('Visit TMX Page', $xml);
        $this->assertStringContainsString('https://tmnf.exchange/trackgbx/2233', $xml);
        $this->assertStringContainsString('2008-04-11', $xml);
        $this->assertStringContainsString('Classic!', $xml);
    }

    public function test_tmxinfo_by_tmx_id_and_section_errors()
    {
        Chat::resolve('/tmxinfo 2233', 'alice');
        $this->assertStringContainsString('id=2233', $this->urls[0]);
        $this->assertStringContainsString('TMX Info for:', $this->window());

        $this->calls = [];
        Chat::resolve('/tmxinfo 2233 foo', 'alice');
        Chat::resolve('/tmxinfo abc', 'alice');
        Chat::resolve('/tmxinfo abc foo', 'alice');   // arg 1 is judged first; no section complaint
        Chat::resolve('/tmxinfo 404', 'alice');
        $this->assertSame([
            format_colors('{#server}> {#error}No such section on TMX!'),
            format_colors('{#server}> {#highlite}abc{#error} is not a valid Track/TMX ID!'),
            format_colors('{#server}> {#highlite}abc{#error} is not a valid Track/TMX ID!'),
            format_colors('{#server}> {#highlite}404{#error} is not a known TMX track, or wrong TMX section, or TMX is down!'),
        ], $this->messages());
    }

    public function test_tmxrecs_lists_records_and_reports_the_bad_id_in_the_message()
    {
        Chat::resolve('/tmxrecs 2233', 'alice');
        $xml = $this->window();
        $this->assertStringContainsString('TMX Top-10 Records:', $xml);
        $this->assertStringContainsString('01.', $xml);
        $this->assertStringContainsString('Axell', $xml);
        $this->assertStringContainsString('0:23.77', $xml);

        $this->calls = [];
        Chat::resolve('/tmxrecs 0', 'alice');
        $this->assertSame([format_colors('{#server}> {#highlite}0{#error} is not a valid Track/TMX ID!')], $this->messages());
    }

    public function test_tmxrecs_without_records()
    {
        $http = Mockery::mock(AsyncHttpClient::class);
        $http->shouldReceive('get')->andReturnUsing(fn ($url) => resolve(new Response(200, [], json_encode(
            str_contains($url, '/api/replays') ? ['Results' => []] : $this->api('https://tmnf.exchange/api/tracks?id=2233&fields=TrackId,TrackName,UId')
        ))));
        $this->app->instance(AsyncHttpClient::class, $http);
        $this->app->forgetInstance(TmxExchange::class);
        $this->app->forgetInstance(TrackExchange::class);

        Chat::resolve('/tmxrecs 2233', 'alice');
        $this->assertSame([format_colors('{#server}> {#error}No TMX records found for {#highlite}$i A01-Race')], $this->messages());
    }

    public function test_small_number_is_a_list_index_from_the_players_last_list()
    {
        Map::factory()->create(['uid' => 'nope', 'name' => 'Other']);
        $this->responses['GetChallengeList'] = [['UId' => 'nope', 'Name' => 'Other', 'FileName' => 'o.Gbx', 'Author' => 'x', 'Environnement' => 'Stadium']];
        Chat::resolve('/list', 'alice');
        $this->urls = [];

        Chat::resolve('/tmxinfo 1', 'alice');

        $this->assertStringContainsString('uid=nope', $this->urls[0]);
        $this->assertSame([format_colors('{#server}> {#highlite}Other{#error} is not a known TMX track, or wrong TMX section, or TMX is down!')], $this->messages());
    }

    public function test_xlist_shows_results_and_clicks_run_the_commands()
    {
        config(['adminops.masteradmins' => ['alice']]);
        config(['jukebox.player_add' => false]);
        Chat::resolve('/xlist a01', 'alice');

        $xml = $this->window();
        $this->assertStringContainsString('Tracks On TMX Section', $xml);
        $this->assertStringContainsString('A01-Race', $xml);
        $this->assertStringContainsString('Nadeo', $xml);
        $this->assertStringContainsString('Stadium', $xml);
        $this->assertStringContainsString('Admin', $xml);
        $this->assertStringContainsString('name=a01', $this->urls[0]);

        $this->calls = [];
        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('tmx_xlist_1_id'));
        $this->assertStringContainsString('TMX Info for:', $this->window());

        $this->calls = [];
        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('tmx_xlist_1_add'));   // /add is off
        $this->assertContains(format_colors('{#server}> {#highlite}/add{#emotic} is not currently enabled on this server.'), $this->messages());

        $this->urls = [];
        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('tmx_xlist_2_auth'));
        $this->assertStringContainsString('author=Some%20One', $this->urls[0]);
    }

    public function test_xlist_results_do_not_replace_the_players_list()
    {
        Chat::resolve('/xlist a01', 'alice');
        $this->assertFalse(state()->has('jukebox:list:alice'));
    }

    public function test_xlist_none_help_and_recent()
    {
        $this->down = true;
        Chat::resolve('/xlist nothing', 'alice');
        $this->assertSame([format_colors('{#server}> {#error}No tracks found, or TMX is down!')], $this->messages());

        $this->down = false;
        $this->calls = [];
        Chat::resolve('/xlist help', 'alice');
        $this->assertStringContainsString('will show tracks on TMX', $this->window());

        $this->urls = [];
        Chat::resolve('/xlist recent', 'alice');
        $this->assertStringContainsString('order1=0', $this->urls[0]);
        $this->assertStringContainsString('Tracks On TMX Section', $this->window());
    }

    public function test_tmxinfo_and_tmxrecs_look_up_tms_and_tmn_on_the_legacy_host()
    {
        Chat::resolve('/tmxinfo 777 tms', 'alice');

        $this->assertSame('https://sunrise.tm-exchange.com/apiget.aspx?action=apitrackinfo&id=777', $this->urls[0]);
        $this->assertSame('https://sunrise.tm-exchange.com/apiget.aspx?action=apitrackrecords&id=777', $this->urls[1]);
        $xml = $this->window();
        $this->assertStringContainsString('Sunny Ride', $xml);
        $this->assertStringContainsString('OldAuthor', $xml);
        $this->assertStringContainsString('2008-04-11', $xml);
        $this->assertStringContainsString('https://sunrise.tm-exchange.com/trackshow/777', $xml);
        $this->assertStringContainsString('action=recordgbx&amp;id=321', $xml);

        $this->calls = [];
        Chat::resolve('/tmxrecs 777 tmn', 'alice');
        $this->assertStringContainsString('nations.tm-exchange.com/apiget.aspx?action=apitrackinfo&id=777', $this->urls[2]);
        $xml = $this->window();
        $this->assertStringContainsString('OldTimer', $xml);
        $this->assertStringContainsString('0:45.12', $xml);
    }

    public function test_legacy_host_miss_or_outage_reports_unknown_track()
    {
        Chat::resolve('/tmxinfo 404 tms', 'alice');
        $this->down = true;
        Chat::resolve('/tmxinfo 777 tmn', 'alice');
        $msg = format_colors('{#server}> {#highlite}404{#error} is not a known TMX track, or wrong TMX section, or TMX is down!');
        $this->assertSame($msg, $this->messages()[0]);
        $this->assertCount(2, $this->messages());
    }

    public function test_tmo_has_no_exchange_and_is_reported_unknown()
    {
        Chat::resolve('/tmxinfo 777 tmo', 'alice');
        $this->assertSame([], $this->urls);
        $this->assertStringContainsString('not a known TMX track', $this->messages()[0]);
    }
}
