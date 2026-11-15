<?php
namespace Funseco\Tmx\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Tmx\TrackRequest;
use Funseco\Tmx\Windows\TmxInfoWindow;

class TmxInfoCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'tmxinfo';
    protected string $description = 'Displays TMX info {Track_ID/TMX_ID} {sec}';
    protected string $usage = '[Track_ID|TMX_ID] [section]';

    public function handle(Player $player, array $args): void
    {
        TrackRequest::run($player, $args, fn ($track) => TmxInfoWindow::show($player, $track));
    }
}
