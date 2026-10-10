<?php
declare(strict_types=1);

/**
 * The drawing of a subject's neighbourhood (docs/build-specs/graph-explorer.md "The drawing"): a server-rendered inline SVG, no script
 * library. The subject sits at the centre of a 640 x 420 viewBox; at most 24 neighbours on one ring (two rings past 12), each a circle
 * with a label cut at 24 characters and a <title> with the full name; the edges are straight lines, labelled with the verb at their
 * midpoint when it is known. Subjects are --bs-primary, sections --bs-secondary, statements --bs-info; every node is an <a> to its
 * screen. The layout is deterministic — angles by sorted name — so the proof compares it. Hidden under 992 px (the lists carry it all).
 */
const GRAPH_SVG_MAX_NODES = 24;
const GRAPH_SVG_LABEL = 24;

function graph_svg_cut(string $s, int $max): string
{
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

/**
 * @param array{name: string, kind?: string} $center
 * @param list<array{kind: string, name: string, title?: string, url: string, verb?: ?string}> $neighbours  kind: subject | section | statement
 */
function graph_svg(array $center, array $neighbours): string
{
    $neighbours = array_slice($neighbours, 0, GRAPH_SVG_MAX_NODES);
    usort($neighbours, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['kind'], $a['url']] <=> [mb_strtolower($b['name']), $b['kind'], $b['url']]);
    $n = count($neighbours);
    $cx = 320.0;
    $cy = 205.0;
    $fill = ['subject' => 'var(--bs-primary)', 'section' => 'var(--bs-secondary)', 'statement' => 'var(--bs-info)'];
    $hx = static fn (string $url): string => ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '"';
    $lines = '';
    $nodes = '';
    foreach ($neighbours as $i => $nb) {
        $ringed = $n > 12;
        $inner = $ringed && $i % 2 === 0;
        [$rx, $ry] = !$ringed ? [235.0, 140.0] : ($inner ? [150.0, 85.0] : [265.0, 150.0]);
        $slots = !$ringed ? $n : ($inner ? (int) ceil($n / 2) : (int) floor($n / 2));
        $k = !$ringed ? $i : (int) floor($i / 2);
        $angle = -M_PI / 2 + 2 * M_PI * $k / max(1, $slots) + ($ringed && !$inner ? M_PI / max(1, $slots) : 0);
        $x = round($cx + $rx * cos($angle), 1);
        $y = round($cy + $ry * sin($angle), 1);
        $lines .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . $x . '" y2="' . $y . '" stroke="var(--bs-border-color, #cbd5e1)" stroke-width="1.5"/>';
        $verb = (string) ($nb['verb'] ?? '');
        if ($verb !== '') {
            $lines .= '<text x="' . round(($cx + $x) / 2, 1) . '" y="' . round(($cy + $y) / 2, 1) . '" font-size="9" text-anchor="middle" fill="var(--bs-secondary-color, #64748b)" class="graph-svg-verb">' . e(graph_svg_cut($verb, 16)) . '</text>';
        }
        $color = $fill[$nb['kind']] ?? $fill['subject'];
        $nodes .= '<a href="' . e($nb['url']) . '"' . $hx($nb['url']) . ' id="svg-node-' . ($i + 1) . '" class="graph-svg-node" data-kind="' . e($nb['kind']) . '">'
            . '<title>' . e($nb['title'] ?? $nb['name']) . '</title>'
            . '<circle cx="' . $x . '" cy="' . $y . '" r="15" fill="' . $color . '" stroke="#fff" stroke-width="2"/>'
            . '<text x="' . $x . '" y="' . round($y + 29, 1) . '" font-size="10" text-anchor="middle" fill="currentColor">' . e(graph_svg_cut($nb['name'], GRAPH_SVG_LABEL)) . '</text></a>';
    }
    $centreName = (string) $center['name'];
    $head = '<circle cx="' . $cx . '" cy="' . $cy . '" r="30" fill="var(--bs-primary)" stroke="#fff" stroke-width="3"/>'
        . '<text x="' . $cx . '" y="' . ($cy + 4) . '" font-size="11" font-weight="600" text-anchor="middle" fill="#fff">' . e(graph_svg_cut($centreName, 10)) . '</text>';
    return '<svg id="subject-svg" class="d-none d-lg-block w-100" viewBox="0 0 640 420" role="img" aria-label="' . e('Neighbourhood of ' . $centreName . ': ' . $n . ' neighbour' . ($n === 1 ? '' : 's')) . '" xmlns="http://www.w3.org/2000/svg" data-nodes="' . $n . '" style="max-height:460px">'
        . '<title>' . e('Neighbourhood of ' . $centreName) . '</title>' . $lines . $head . $nodes . '</svg>';
}
