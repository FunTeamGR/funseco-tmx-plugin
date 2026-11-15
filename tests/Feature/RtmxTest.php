<?php
namespace Funseco\Tmx\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Funseco\Core\Testing\FakesServer;
use Funseco\Tmx\Tests\TestCase;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Events\TrackAddRequested;
use Funseco\Tmx\Commands\RtmxCommand;
use function React\Promise\reject;
use function React\Promise\resolve;

class RtmxTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private string $dir;
    private $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        $this->dir = sys_get_temp_dir().'/rtmx-'.uniqid();
        mkdir($this->dir);
        ServerInfo::setTracksDirectory($this->dir);
        config(['adminops.admins' => ['boss'], 'server.login' => 'srv', 'tmx.rtmx.bot_login' => '', 'server.matchsettings' => null]);
        $this->http = Mockery::mock(AsyncHttpClient::class);
        $this->app->instance(AsyncHttpClient::class, $this->http);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function gbx(): string
    {
        return '<header type="challenge"><ident uid="u1" name="Rand" author="bob"/><desc envir="Stadium"/><times/></header>';
    }

    public function test_stranger_is_refused()
    {
        $p = Player::factory()->make(['login' => 'nobody']);
        $this->http->shouldNotReceive('locate');

        app(RtmxCommand::class)->handle($p, []);

        $this->assertStringContainsString('You do not have permission to use this command.', $this->messages()[0]);
    }

    public function test_admin_adds_the_random_map_as_the_randomizer_and_errors_go_to_the_caller()
    {
        $admin = Player::factory()->make(['login' => 'boss']);
        $this->http->shouldReceive('locate')->once()->andReturn(resolve('https://tmnf.exchange/trackshow/4242'));
        $this->http->shouldReceive('get')->with('https://tmnf.exchange/trackgbx/4242')->andReturn(resolve(new Response(200, [], $this->gbx())));

        $added = null;
        \Illuminate\Support\Facades\Event::listen(\Funseco\Core\SharedKernel\Events\TrackAdded::class, function ($e) use (&$added) {
            $added = $e->requester;
        });

        app(RtmxCommand::class)->handle($admin, []);

        $this->assertStringContainsString('Fetching random TMX map...', $this->messages()[0]);
        $this->assertSame('srv', $added->login);
        $this->assertSame('$0f0TMX $fffRandomizer', $added->nickname);
        $this->assertFalse($added->exists);
    }

    public function test_whitelisted_login_may_use_it_and_failure_is_reported_to_the_caller()
    {
        config(['tmx.rtmx.extra_allowed' => ['alice']]);
        $p = Player::factory()->make(['login' => 'Alice']);
        $this->http->shouldReceive('locate')->andReturn(resolve('https://tmnf.exchange/trackshow/9'));
        $this->http->shouldReceive('get')->andReturn(reject(new \RuntimeException('Connection timed out')));

        app(RtmxCommand::class)->handle($p, []);

        $sent = $this->sent('ChatSendServerMessageToLogin');
        $this->assertSame('Alice', end($sent)[1]);
        $this->assertStringContainsString('Connection timed out', end($sent)[0]);
    }

    public function test_no_id_in_the_final_url_says_busy()
    {
        $this->http->shouldReceive('locate')->andReturn(resolve('https://tmnf.exchange/'));
        app(RtmxCommand::class)->handle(Player::factory()->make(['login' => 'boss']), []);
        $this->assertStringContainsString('TMX busy or ID not found. Try again.', $this->messages()[1]);
    }

    public function test_request_failure_says_busy()
    {
        $this->http->shouldReceive('locate')->andReturn(reject(new \RuntimeException('timeout')));
        app(RtmxCommand::class)->handle(Player::factory()->make(['login' => 'boss']), []);
        $this->assertStringContainsString('TMX busy', $this->messages()[1]);
    }

    public function test_track_add_requested_exposes_a_result_and_silent_hides_errors()
    {
        $this->http->shouldReceive('get')->andReturn(reject(new \RuntimeException('down')));
        $event = new TrackAddRequested(5, null, Player::factory()->make(['login' => 'bot']), true);
        event($event);

        $result = null;
        $event->result->then(function ($r) use (&$result) {
            $result = $r;
        });
        $this->assertFalse($result->ok);
        $this->assertSame('down', $result->error);
        $this->assertSame([], $this->sent('ChatSendServerMessageToLogin'));
    }

    public function test_locate_follows_redirects_to_the_final_url()
    {
        config(['funseco.http_allow_insecure' => true]);
        $server = new SocketServer('127.0.0.1:0');
        $port = parse_url($server->getAddress(), PHP_URL_PORT);
        $http = new HttpServer(function ($request) use ($port) {
            return $request->getUri()->getPath() === '/random'
                ? new Response(302, ['Location' => "/trackshow/777"])
                : new Response(200, [], 'ok');
        });
        $http->listen($server);

        $final = null;
        (new AsyncHttpClient(2.0))->locate("http://127.0.0.1:$port/random")->then(function ($u) use (&$final, $server) {
            $final = $u;
            $server->close();
            Loop::stop();
        });
        Loop::addTimer(3, fn () => Loop::stop());
        Loop::run();

        $this->assertSame("http://127.0.0.1:$port/trackshow/777", $final);
    }

    private function locateAgainst(string $location, bool $allowHttp): array
    {
        config(['funseco.http_allow_insecure' => $allowHttp]);
        $server = new SocketServer('127.0.0.1:0');
        $port = parse_url($server->getAddress(), PHP_URL_PORT);
        (new HttpServer(fn () => new Response(302, ['Location' => str_replace('{port}', (string) $port, $location)])))->listen($server);

        $out = ['url' => null, 'error' => null];
        (new AsyncHttpClient(2.0))->locate("http://127.0.0.1:$port/random")->then(
            function ($u) use (&$out, $server) {
                $out['url'] = $u;
                $server->close();
                Loop::stop();
            },
            function ($e) use (&$out, $server) {
                $out['error'] = $e->getMessage();
                $server->close();
                Loop::stop();
            },
        );
        Loop::addTimer(3, fn () => Loop::stop());
        Loop::run();

        return $out;
    }

    public function test_locate_refuses_redirects_to_another_host()
    {
        $out = $this->locateAgainst('http://evil.example/trackshow/1', true);
        $this->assertNull($out['url']);
        $this->assertStringContainsString('Refusing redirect', (string) $out['error']);
    }

    public function test_locate_refuses_http_redirects_unless_allowed()
    {
        $out = $this->locateAgainst('/trackshow/1', false);
        $this->assertNull($out['url']);
        $this->assertStringContainsString('Refusing redirect', (string) $out['error']);
    }
}
