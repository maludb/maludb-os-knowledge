<?php
declare(strict_types=1);

/**
 * Markdown → HTML, a small safe subset (ask.md divergence 6): an answer, a pinned answer, the expert's reply. Everything is escaped
 * FIRST, then only these are recognised: paragraphs and line breaks, **strong**, *em*, `code`, fenced code (```), > quotes, - and 1.
 * lists, # to ### headings (rendered one level down as h4–h6), links only http(s):// (rel="noopener nofollow", target="_blank"), and
 * citation markers [n] → <a class="cite-ref" href="#cite-{qid}-{n}">[n]</a> when a question id is given. No raw HTML passes.
 */

function kn_markdown_html(string $md, ?int $questionId = null): string
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $out = [];
    $para = [];
    $list = null;            // ['ul'|'ol', items[]]
    $quote = [];
    $flushPara = static function () use (&$para, &$out, $questionId): void {
        if ($para !== []) {
            $out[] = '<p>' . implode('<br>', array_map(static fn ($l) => kn_markdown_inline($l, $questionId), $para)) . '</p>';
            $para = [];
        }
    };
    $flushList = static function () use (&$list, &$out, $questionId): void {
        if ($list !== null) {
            $out[] = '<' . $list[0] . '>' . implode('', array_map(static fn ($i) => '<li>' . kn_markdown_inline($i, $questionId) . '</li>', $list[1])) . '</' . $list[0] . '>';
            $list = null;
        }
    };
    $flushQuote = static function () use (&$quote, &$out, $questionId): void {
        if ($quote !== []) {
            $out[] = '<blockquote class="kn-md-quote">' . implode('<br>', array_map(static fn ($l) => kn_markdown_inline($l, $questionId), $quote)) . '</blockquote>';
            $quote = [];
        }
    };
    $flushAll = static function () use ($flushPara, $flushList, $flushQuote): void { $flushPara(); $flushList(); $flushQuote(); };
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        if (preg_match('/^\s*```/', $line)) {                       // fenced code: verbatim until the closing fence
            $flushAll();
            $code = [];
            for ($i++; $i < $n && !preg_match('/^\s*```/', $lines[$i]); $i++) {
                $code[] = $lines[$i];
            }
            $out[] = '<pre class="kn-md-code"><code>' . e(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (trim($line) === '') {
            $flushAll();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
            $flushAll();
            $h = strlen($m[1]) + 3;
            $out[] = "<h$h>" . kn_markdown_inline(trim($m[2], " #"), $questionId) . "</h$h>";
            continue;
        }
        if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
            $flushPara(); $flushList();
            $quote[] = $m[1];
            continue;
        }
        if (preg_match('/^\s*([-*]|\d+[.)])\s+(.+)$/', $line, $m)) {
            $flushPara(); $flushQuote();
            $kind = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($list !== null && $list[0] !== $kind) {
                $flushList();
            }
            $list ??= [$kind, []];
            $list[1][] = $m[2];
            continue;
        }
        $flushList(); $flushQuote();
        $para[] = $line;
    }
    $flushAll();
    return implode("\n", $out);
}

/** One line's inline marks, on the ESCAPED text: code spans first (their content is not marked further), links, strong, em, [n]. */
function kn_markdown_inline(string $text, ?int $questionId): string
{
    $codes = [];
    $text = (string) preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
        $codes[] = '<code>' . e($m[1]) . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $text);
    $links = [];
    $text = (string) preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/i', static function ($m) use (&$links) {
        $links[] = '<a href="' . e($m[2]) . '" rel="noopener nofollow" target="_blank">' . e($m[1]) . '</a>';
        return "\x02" . (count($links) - 1) . "\x02";
    }, $text);
    $html = e($text);
    $html = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $html);
    $html = (string) preg_replace('/(?<![*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?![*\w])/', '<em>$1</em>', $html);
    if ($questionId !== null) {
        $html = (string) preg_replace_callback('/\[(\d{1,2})\]/', static fn ($m) => '<a class="cite-ref" href="#cite-' . $questionId . '-' . (int) $m[1] . '">[' . (int) $m[1] . ']</a>', $html);
    }
    $html = (string) preg_replace_callback("/\x02(\d+)\x02/", static fn ($m) => $links[(int) $m[1]], $html);
    return (string) preg_replace_callback("/\x01(\d+)\x01/", static fn ($m) => $codes[(int) $m[1]], $html);
}
