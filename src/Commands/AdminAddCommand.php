<?php
namespace Funseco\Tmx\Commands;

use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\Commands\Guarded;
use Funseco\Core\SharedKernel\Enums\Role;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\SharedKernel\Enums\MessageType;
use Funseco\Tmx\TrackAdder;
use function React\Promise\resolve;

class AdminAddCommand implements Command, Guarded, Described
{
    use DeclaresCommand;

    protected string $name = 'admin add';
    protected ?Role $role = Role::Operator;
    protected ?string $ability = 'add';
    protected string $description = 'Adds tracks directly from TMX (<ID>... {sec})';
    protected string $usage = '<tmxid>... [tmnf|tmu|tms|tmn]';

    public function handle(Player $player, array $args): void
    {
        // numeric arguments are track ids; a non-numeric one switches the exchange for all of them
        $ids      = [];
        $exchange = null;
        foreach ($args as $arg) {
            if (ctype_digit((string) $arg)) {
                (int) $arg > 0 && $ids[] = (int) $arg;
            } else {
                $exchange = strtolower((string) $arg);
            }
        }

        if (!$ids) {
            Chat::sendMessageToLogin($player->login, 'Usage: /admin add <tmxid>... [tmnf|tmu|tms|tmn]', MessageType::Error);
            return;
        }

        // one after another, so the maps are queued in the order given
        $chain = resolve(null);
        foreach (array_values(array_unique($ids)) as $id) {
            $chain = $chain->then(fn () => app(TrackAdder::class)->add($player, $id, $exchange));
        }
    }
}
