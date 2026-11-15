<?php
namespace Funseco\Tmx;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\Events\TrackAddRequested;
use Funseco\Core\SharedKernel\TrackExchange;
use Funseco\Tmx\Commands\AdminAddCommand;
use Funseco\Tmx\Commands\RtmxCommand;
use Funseco\Tmx\Commands\TmxInfoCommand;
use Funseco\Tmx\Commands\TmxRecsCommand;
use Funseco\Tmx\Commands\XlistCommand;
use Funseco\Tmx\Exchange\TmxExchange;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;

class TmxServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tmx.php', 'tmx');
        $this->publishPluginConfig(__DIR__.'/..');

        // xlist.clickable_rows sizes the action list registered in boot(): restart. The rest is read where used.
        $this->app->make(SettingsRegistry::class)->declare('tmx', 'tmx', [
            Setting::enum('default', 'Default exchange', ['tmnf', 'tmu', 'tms', 'tmn']),
            Setting::enum('default_section', 'Default section', ['TMO', 'TMS', 'TMN', 'TMU', 'TMNF']),
            Setting::int('show_tmxrec', 'Announce TMX world record (0 off, 1 on)', min: 0, max: 1),
            Setting::url('rtmx.url', 'Random map URL'),
            Setting::list('rtmx.extra_allowed', 'Logins allowed to use /rtmx'),
            Setting::string('rtmx.bot_login', 'Random map bot login'),
            Setting::string('rtmx.bot_nickname', 'Random map bot nickname'),
            Setting::bool('xlist.clickable', 'Clickable /xlist rows'),
            Setting::int('xlist.clickable_rows', 'Clickable /xlist rows count', min: 1, max: 2000, restart: true),
            Setting::int('xlist.max_results', '/xlist maximum results', min: 1, max: 5000),
            Setting::int('xlist.page_size', '/xlist TMX page size', min: 1, max: 500),
            Setting::int('xlist.recent_count', '/xlist recent tracks', min: 1, max: 500),
        ]);
        $this->app->singleton(TmxService::class);
        $this->app->singleton(TmxExchange::class);
        // replaces core's null default
        $this->app->singleton(TrackExchange::class, fn ($app) => $app->make(TmxExchange::class));
    }

    public function boot(): void
    {
        foreach ([AdminAddCommand::class, RtmxCommand::class, TmxInfoCommand::class, TmxRecsCommand::class, XlistCommand::class] as $command) {
            \Chat::register($command);
        }

        $max = (int) config('tmx.xlist.clickable_rows', 500);
        $actions = [];
        foreach (range(1, max(1, $max)) as $n) {
            foreach (['id', 'add', 'adm', 'auth'] as $kind) {
                $actions[] = "tmx_xlist_{$n}_$kind";
            }
        }
        app(ManiaLinkService::class)->addActions($actions, XlistAction::class);

        // TMX lookup of every new track (async; the result arrives as TrackExchangeInfoLoaded)
        Event::listen(StartupEvent::class, function () {
            if ($map = CurrentMap::get()) {
                app(TmxExchange::class)->mapStarted($map);
            }
        });
        Event::listen(MapStarted::class, fn (MapStarted $e) => app(TmxExchange::class)->mapStarted($e->map));
        Event::listen(PlayerDisconnected::class, fn (PlayerDisconnected $e) => state()->forget(XlistAction::key($e->player->login)));

        // A passed player /add vote (jukebox plugin) asks for a TMX track.
        Event::listen(TrackAddRequested::class, fn (TrackAddRequested $e) => $e->result = app(TrackAdder::class)->add($e->requester, $e->tmxId, $e->section, !$e->silent));
    }
}
