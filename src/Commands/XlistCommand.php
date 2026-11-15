<?php
namespace Funseco\Tmx\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Tmx\Exchange\TmxApi;
use Funseco\Tmx\Text;
use Funseco\Tmx\XlistAction;

/** /xlist: search TMX, show the hits in a paged window (kept apart from the player's /list). */
class XlistCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'xlist';
    protected string $description = 'Lists tracks on TMX (see: /xlist help)';

    private const ENV_ALIASES = ['speed' => 'desert', 'alpine' => 'snow'];

    public function handle(Player $player, array $args): void
    {
        $args = array_values(array_filter($args, fn ($a) => $a !== ''));
        if (count($args) === 1 && strtolower($args[0]) === 'help') {
            $this->help($player);
            return;
        }

        $section = strtoupper(config('tmx.default_section', 'TMNF'));
        $name = [];
        $author = '';
        $env = null;
        $recent = strtolower($args[0] ?? '') === 'recent';

        if ($recent) {
            if (isset($args[1]) && str_starts_with(strtolower($args[1]), 'tm')) {
                $section = strtoupper($args[1]);
            }
        } else {
            foreach ($args as $i => $word) {
                $lower = strtolower($word);
                if (str_starts_with($lower, 'auth:')) {
                    $author = rawurldecode(substr($word, 5));
                } elseif (str_starts_with($lower, 'env:')) {
                    $env = strtolower(rawurldecode(substr($word, 4)));
                    $env = self::ENV_ALIASES[$env] ?? $env;
                } elseif ($i === count($args) - 1 && str_starts_with($lower, 'tm')) {
                    $section = strtoupper($word);
                } else {
                    $name[] = $word;
                }
            }
        }
        if (in_array($section, ['TMN', 'TMNF'], true)) {
            $env = null;   // those sections have a single environment
        }

        app(TmxApi::class)
            ->search($section, implode(' ', $name), $author, $env, (int) config('tmx.xlist.max_results', 500), $recent)
            ->then(function (array $tracks) use ($player, $section, $recent) {
                if (!$tracks) {
                    Text::tell($player, Text::get('xlist_none'));
                    return;
                }
                if ($recent) {
                    $tracks = array_slice($tracks, 0, (int) config('tmx.xlist.recent_count', 10));
                }
                $this->show($player, $section, $tracks);
            });
    }

    private function show(Player $player, string $section, array $tracks): void
    {
        $clickable = (bool) config('tmx.xlist.clickable', true);
        $max = (int) config('tmx.xlist.clickable_rows', 500);
        $admin = $clickable && $player->allowed('add');

        state()->forever(XlistAction::key($player->login), [
            'section' => $section,
            'rows' => array_map(fn ($t) => ['id' => $t['id'], 'author' => $t['author']], $tracks),
        ]);

        $rows = [];
        foreach ($tracks as $i => $t) {
            $n = $i + 1;
            $click = $clickable && $n <= $max;
            $row = [
                str_pad((string) $n, 3, '0', STR_PAD_LEFT).'.',
                $click ? ['{#black}'.$t['id'], "tmx_xlist_{$n}_id"] : '{#black}'.$t['id'],
                $click ? ['{#black}'.$t['name'], "tmx_xlist_{$n}_add"] : '{#black}'.$t['name'],
            ];
            if ($admin) {
                $row[] = $click ? ['Add', "tmx_xlist_{$n}_adm"] : 'Add';
            }
            $row[] = $click && $t['author'] !== '' ? [$t['author'], "tmx_xlist_{$n}_auth"] : $t['author'];
            $row[] = $t['env'];
            $rows[] = $row;
        }

        $columns = $admin ? ['Id', 'TMX', 'Name (click to /add)', '$nAdmin', 'Author', 'Env']
            : ['Id', 'TMX', $clickable ? 'Name (click to /add)' : 'Name', 'Author', 'Env'];
        $widths = $admin ? [1.55, 0.12, 0.16, 0.6, 0.1, 0.4, 0.17] : [1.45, 0.12, 0.16, 0.6, 0.4, 0.17];

        Window::show($player, 'Tracks On TMX Section {#black}'.$section.'$g:', $rows, $widths, ['Icons128x128_1', 'LoadTrack', 0.02], columns: $columns);
    }

    private function help(Player $player): void
    {
        $rows = [];
        foreach ([
            ['help', 'Displays this help information'],
            ['recent', 'Lists the 10 most recent tracks'],
            ['xxx', 'Lists tracks matching (partial) name'],
            ['auth:yyy', 'Lists tracks matching (partial) author'],
            ['env:zzz', 'Where zzz is an environment from: stadium,'],
            [null, 'bay,coast,island,snow/alpine,desert/speed,rally'],
            ['xxx auth:yyy env:zzz', 'Combines the name, author and/or env searches'],
            ['tmx', 'Selects a TMX section from:'],
            [null, 'TMO,TMS,TMN,TMNF,TMU'],
        ] as [$arg, $text]) {
            $rows[] = ['...', $arg ? '{#black}'.$arg : '', $text];
        }
        $rows[] = [];
        $rows[] = ['Pick a TMX Id number from the list, and use {#black}/add # {sec}'];

        Window::show($player, '{#black}/xlist$g will show tracks on TMX:', $rows, [1.2, 0.05, 0.35, 0.8], ['Icons64x64_1', 'TrackInfo', -0.01]);
    }
}
