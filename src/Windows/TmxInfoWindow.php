<?php
namespace Funseco\Tmx\Windows;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Core\SharedKernel\Values\TmxTrack;

/** The /tmxinfo popup: Window has no image variant, so this renders its own (same style, same manialink id). */
class TmxInfoWindow
{
    public static function show(Player $player, TmxTrack $t): void
    {
        ManiaLink::displayLogin($player, self::xml($t), 0, false);
    }

    public static function xml(TmxTrack $t): string
    {
        $style = config('funseco.window_style');
        $h = $style['header']['textsize'];
        $b = $style['body']['textsize'];
        $w = 1.15;
        $e = fn ($s) => htmlspecialchars(clean_utf8((string) $s));
        $date = fn (?string $iso) => TmxTrack::date($iso);

        $rows = [
            ['TMX ID', (string) $t->id, 'Type/Style', '{#black}'.$e($t->type).'$g / {#black}'.$e($t->style)],
            ['Section', $t->section, 'Env/Mood', '{#black}'.$e($t->environment).'$g / {#black}'.$e($t->mood)],
            ['UID', '$n'.$t->uid, 'Routes', $t->routes],
            ['Author', $t->author, 'Difficulty', $t->difficulty],
            ['Uploaded', $date($t->uploadedAt), 'Length', $t->length],
            ['Updated', $date($t->updatedAt), 'Awards', (string) $t->awards],
            ['LB Rating', $t->lbRating, 'Replay', $t->replayUrl ? '$l['.$t->replayUrl.']Download$l' : '<none>'],
        ];
        $n = count($rows);
        $image = $t->imageUrl.(str_contains($t->imageUrl, '?') ? '&.jpg' : '?.jpg');
        $cols = [0.2, 0.45, 0.2, 0.3];
        $xs = [-0.025, -0.225, -0.675, -0.875];

        $xml = '<manialink id="'.Window::id().'"><frame pos="'.($w / 2).' 0.47 0">';
        $xml .= '<quad size="'.$w.' '.(0.42 + 2 * $h + $n * $b).'" style="'.$style['window']['style'].'" substyle="'.$style['window']['substyle'].'"/>';
        $xml .= '<quad pos="-'.($w / 2).' -0.01 -0.1" size="'.($w - 0.02).' '.$h.'" halign="center" style="'.$style['header']['style'].'" substyle="'.$style['header']['substyle'].'"/>';
        $xml .= '<quad pos="-0.055 -0.045 -0.2" size="'.($h - 0.01).' '.($h - 0.01).'" halign="center" valign="center" style="Icons64x64_1" substyle="Maximize"/>';
        $xml .= '<label pos="-0.10 -0.025 -0.2" size="'.($w - 0.12).' '.$h.'" halign="left" style="'.$style['header']['textstyle'].'" text="'.$e('TMX Info for: {#black}'.$t->name).'"/>';
        $xml .= '<quad pos="-'.($w / 2).' -'.(0.02 + $h).' -0.2" size="0.4 0.3" halign="center" image="'.$e($image).'"/>';
        $xml .= '<quad pos="-'.($w / 2).' -'.(0.33 + $h).' -0.1" size="'.($w - 0.02).' '.(0.02 + $h + $n * $b).'" halign="center" style="'.$style['body']['style'].'" substyle="'.$style['body']['substyle'].'"/>';
        $xml .= '<format style="'.$style['body']['textstyle'].'"/>';
        foreach ($rows as $r => $row) {
            $y = 0.305 + $h + ($r + 1) * $b;
            foreach ($row as $c => $text) {
                $value = $c % 2 === 1;
                if ($value && !str_starts_with($text, '{#black}')) {
                    $text = '{#black}'.($c === 1 || $c === 3 ? $e($text) : $text);
                }
                if (!$value) {
                    $text = $e($text);
                }
                $xml .= '<label pos="'.$xs[$c].' -'.$y.' -0.2" size="'.($cols[$c]).' 0.06" halign="left" style="'.$style['body']['textstyle'].'" text="'.$text.'"/>';
            }
        }
        $ly = 0.36 + $h + $n * $b;
        $xml .= '<format style="'.$style['header']['textstyle'].'"/>';
        $xml .= '<label pos="-0.2875 -'.$ly.' -0.2" size="0.575 0.07" halign="center" style="'.$style['header']['textstyle'].'" text="'.$e('$l['.$t->pageUrl.']Visit TMX Page').'"/>';
        $xml .= '<label pos="-0.8625 -'.$ly.' -0.2" size="0.575 0.07" halign="center" style="'.$style['header']['textstyle'].'" text="'.$e('$l['.$t->downloadUrl.']Download Track').'"/>';
        $xml .= '<quad pos="-'.($w / 2).' -'.(0.35 + 2 * $h + $n * $b).' -0.2" size="0.06 0.06" halign="center" style="Icons64x64_1" substyle="Close" action="'.ManiaLink::getAction('window_close').'"/>';
        $xml .= '</frame></manialink>';

        return format_colors(str_replace('{#black}', $style['window']['blackcolor'], $xml));
    }
}
