<?php
namespace Funseco\Tmx\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Core\SharedKernel\Values\TmxTrack;
use Funseco\Tmx\Text;
use Funseco\Tmx\TrackRequest;

class TmxRecsCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'tmxrecs';
    protected string $description = 'Displays TMX records {Track_ID/TMX_ID} {sec}';
    protected string $usage = '[Track_ID|TMX_ID] [section]';

    public function handle(Player $player, array $args): void
    {
        TrackRequest::run($player, $args, function (TmxTrack $track) use ($player) {
            if (!$track->records) {
                Text::tell($player, Text::get('no_records', Text::stripColors($track->name)));
                return;
            }
            $rows = [];
            foreach ($track->records as $r) {
                $rows[] = [
                    str_pad((string) $r->rank, 2, '0', STR_PAD_LEFT).'.',
                    '{#black}'.$r->name,
                    $track->isStunts() ? (string) $r->score : Text::time($r->time),
                ];
            }
            Window::show($player, 'TMX Top-10 Records: {#black}'.$track->name, $rows, [0.9, 0.1, 0.5, 0.3], ['BgRaceScore2', 'Podium', 0]);
        });
    }
}
