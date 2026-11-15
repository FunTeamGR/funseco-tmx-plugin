<?php
namespace Funseco\Tmx\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use React\Http\Message\Response;
use Funseco\Tmx\Tests\TestCase;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Facades\Server;
use Funseco\Core\Servers\TmForever\Query;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Enums\MessageType;
use Funseco\Tmx\Commands\AdminAddCommand;
use function React\Promise\reject;
use function React\Promise\resolve;

class AdminAddCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tracksDir;
    private Player $admin;
    private $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracksDir = sys_get_temp_dir() . '/tmx-test-' . uniqid();
        mkdir($this->tracksDir);
        ServerInfo::setTracksDirectory($this->tracksDir);

        $this->admin = Player::factory()->make(['login' => 'admin']);
        $this->http = Mockery::mock(AsyncHttpClient::class);
        $this->app->instance(AsyncHttpClient::class, $this->http);
        Chat::spy();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tracksDir));
        parent::tearDown();
    }

    public function test_downloaded_track_is_saved_and_inserted()
    {
        $gbx = "GBX\0\0<header type=\"challenge\"><ident uid=\"abc\" name=\"\$f00Speed\" author=\"bob\"/>"
            . "<desc envir=\"Stadium\" mood=\"Day\" type=\"Race\" nblaps=\"0\" price=\"0\"/>"
            . "<times bronze=\"1\" silver=\"1\" gold=\"1\" authortime=\"1\"/></header>\0\0";
        $this->http->shouldReceive('get')->once()->with('https://tmnf.exchange/trackgbx/123')
            ->andReturn(resolve(new Response(200, [], $gbx)));

        $query = Mockery::mock(Query::class);
        $query->shouldReceive('ChatSendServerMessage');   // jukebox announces the queued track
        $query->shouldReceive('InsertChallenge')->once()
            ->withArgs(function ($filename, $callback) {
                $callback([true]);
                return $filename === 'Challenges/TMX/Speed.gbx';
            });
        Server::shouldReceive('query')->andReturn($query);

        app(AdminAddCommand::class)->handle($this->admin, ['123']);

        $this->assertSame($gbx, file_get_contents($this->tracksDir . '/Challenges/TMX/Speed.gbx'));
        Chat::shouldHaveReceived('sendMessage')->once();
    }

    public function test_added_track_is_saved_to_the_configured_tracklist()
    {
        config(['server.matchsettings' => 'tracklist_asmo.txt', 'funseco.writetracklist_random' => false]);
        $gbx = "<header type=\"challenge\"><ident uid=\"u\" name=\"Speed\" author=\"bob\"/><desc envir=\"Stadium\"/><times/></header>";
        $this->http->shouldReceive('get')->andReturn(resolve(new Response(200, [], $gbx)));

        $query = Mockery::mock(Query::class);
        $query->shouldReceive('ChatSendServerMessage');   // jukebox announces the queued track
        $query->shouldReceive('InsertChallenge')->once()->withArgs(function ($file, $callback) {
            $callback([true]);
            return true;
        });
        $query->shouldReceive('SaveMatchSettings')->once()->withArgs(fn ($file) => $file === 'MatchSettings/tracklist_asmo.txt');
        Server::shouldReceive('query')->andReturn($query);

        app(AdminAddCommand::class)->handle($this->admin, ['123']);
    }

    public function test_no_tracklist_save_when_not_configured()
    {
        config(['server.matchsettings' => null]);
        $gbx = "<header type=\"challenge\"><ident uid=\"u\" name=\"Speed\" author=\"bob\"/><desc envir=\"Stadium\"/><times/></header>";
        $this->http->shouldReceive('get')->andReturn(resolve(new Response(200, [], $gbx)));

        $query = Mockery::mock(Query::class);
        $query->shouldReceive('ChatSendServerMessage');   // jukebox announces the queued track
        $query->shouldReceive('InsertChallenge')->once()->withArgs(function ($file, $callback) {
            $callback([true]);
            return true;
        });
        $query->shouldNotReceive('SaveMatchSettings');
        Server::shouldReceive('query')->andReturn($query);

        app(AdminAddCommand::class)->handle($this->admin, ['123']);
    }

    public function test_many_ids_are_added_in_order_and_only_a_non_numeric_argument_picks_the_exchange()
    {
        config(['server.matchsettings' => null]);
        $gbx = fn (string $uid) => "<header type=\"challenge\"><ident uid=\"$uid\" name=\"M$uid\" author=\"bob\"/><desc envir=\"Stadium\"/><times/></header>";
        $this->http->shouldReceive('get')->once()->ordered()->with('https://tmnf.exchange/trackgbx/11')->andReturn(resolve(new Response(200, [], $gbx('a'))));
        $this->http->shouldReceive('get')->once()->ordered()->with('https://tmnf.exchange/trackgbx/22')->andReturn(resolve(new Response(200, [], $gbx('b'))));

        $query = Mockery::mock(Query::class);
        $query->shouldReceive('ChatSendServerMessage');
        $query->shouldReceive('InsertChallenge')->twice()->withArgs(function ($file, $callback) {
            $callback([true]);
            return true;
        });
        Server::shouldReceive('query')->andReturn($query);

        app(AdminAddCommand::class)->handle($this->admin, ['11', '22', 'tmnf']);

        Chat::shouldHaveReceived('sendMessage')->twice();
    }

    public function test_html_response_reports_track_not_found()
    {
        $this->http->shouldReceive('get')->andReturn(resolve(new Response(200, [], '<html>nope</html>')));

        app(AdminAddCommand::class)->handle($this->admin, ['999']);

        Chat::shouldHaveReceived('sendMessageToLogin')->with('admin', 'Track 999 not found on tmnf.', MessageType::Error);
    }

    public function test_http_failure_is_reported_to_admin()
    {
        $this->http->shouldReceive('get')->andReturn(reject(new \RuntimeException('Connection timed out')));

        app(AdminAddCommand::class)->handle($this->admin, ['123']);

        Chat::shouldHaveReceived('sendMessageToLogin')->with('admin', 'Connection timed out', MessageType::Error);
    }

    public function test_unknown_exchange_is_reported_to_admin()
    {
        app(AdminAddCommand::class)->handle($this->admin, ['123', 'nope']);

        Chat::shouldHaveReceived('sendMessageToLogin')->with('admin', 'Unknown TMX exchange: nope', MessageType::Error);
    }
}
