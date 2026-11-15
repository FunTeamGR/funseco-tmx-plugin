<?php
namespace Funseco\Tmx\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\Enums\Role;
use Funseco\Tmx\Text;
use Funseco\Tmx\TrackAdder;

/**
 * /rtmx: add a random TMX map that matches the configured filters. Admins and the logins of
 * tmx.rtmx.extra_allowed may use it. Deliberately not `Guarded`: the guard cannot express "admin
 * or listed login", so handle() checks it (and answers with the XAseco refusal text).
 * The map is added as the server's own "TMX Randomizer" so it stays queued if the caller leaves.
 */
class RtmxCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'rtmx';
    protected string $description = 'Adds a random TMX map from your filters';

    public function handle(Player $player, array $args): void
    {
        if (!self::mayUse($player)) {
            Text::tell($player, Text::get('rtmx_denied'));
            return;
        }

        Text::tell($player, Text::get('rtmx_fetching'));

        $http = app(AsyncHttpClient::class);
        $http->locate((string) config('tmx.rtmx.url'), ['User-Agent' => 'XAseco-TmxRandom/3.0'])->then(
            function (string $finalUrl) use ($player) {
                if (!preg_match('#trackshow/(\d+)#', $finalUrl, $m)) {
                    Text::tell($player, Text::get('rtmx_busy'));
                    return;
                }
                // errors go to the caller, not to the synthetic requester
                app(TrackAdder::class)->add(self::bot(), (int) $m[1], null, $player->login);
            },
            fn (\Throwable $e) => Text::tell($player, Text::get('rtmx_busy')),
        );
    }

    public static function mayUse(Player $player): bool
    {
        if ($player->effectiveRole()->allows(Role::Admin)) {
            return true;
        }

        return in_array(strtolower($player->login), array_map('strtolower', (array) config('tmx.rtmx.extra_allowed', [])), true);
    }

    /** Unsaved Player: nothing downstream persists it. */
    private static function bot(): Player
    {
        return new Player([
            'login' => (string) (config('tmx.rtmx.bot_login') ?: config('server.login')),
            'nickname' => (string) config('tmx.rtmx.bot_nickname'),
        ]);
    }
}
