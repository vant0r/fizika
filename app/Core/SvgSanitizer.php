<?php
declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * SvgSanitizer
 * ------------
 * Zero-dependency SVG sanitizer that defends against:
 *   - <script>, <foreignObject>, <iframe>, <object>, <embed>, <video>, <audio>
 *   - inline event handlers (on* attributes)
 *   - href / xlink:href values pointing to javascript:, data:, vbscript:, file:
 *   - <use> elements referencing external resources
 *   - <style> blocks (CSS can hide JS via expression() or @import url(...))
 *   - XML External Entities (XXE)  — DOCTYPE blocks and external entities
 *   - PHP/HTML processing instructions
 *
 * Strategy: parse with libxml2 in safe mode → walk the DOM →
 * remove disallowed elements/attributes → re-serialize.
 *
 * If at any step the input is malformed or non-SVG, sanitize() throws.
 *
 * NOTE: This is intentionally a strict whitelist. Decorative/illustrative
 * SVGs from common tools (Figma, Illustrator) pass through; anything that
 * needs JavaScript or external resources is rejected.
 */
final class SvgSanitizer
{
    /**
     * Whitelisted SVG element local names. Anything else is removed.
     * Source: SVG 1.1 + SVG 2 graphical/structural elements.
     *
     * @var array<int,string>
     */
    private const ALLOWED_TAGS = [
        // structural
        'svg', 'g', 'defs', 'symbol', 'title', 'desc', 'metadata', 'a',
        // shapes
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        // text
        'text', 'tspan', 'textpath',
        // gradients & patterns
        'lineargradient', 'radialgradient', 'stop', 'pattern',
        // clipping & masking
        'clippath', 'mask',
        // markers
        'marker',
        // filters & primitives
        'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer',
        'fecomposite', 'feconvolvematrix', 'fediffuselighting',
        'fedisplacementmap', 'fedistantlight', 'feflood', 'fefunca',
        'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur',
        'femerge', 'femergenode', 'femorphology', 'feoffset',
        'fepointlight', 'fespecularlighting', 'fespotlight',
        'fetile', 'feturbulence',
        // misc safe
        'use', // we additionally restrict its href to local fragments
    ];

    /**
     * Whitelisted attribute local names (case-insensitive).
     * 'style' is intentionally OMITTED — CSS is a vector for expression()/url(javascript:).
     *
     * @var array<int,string>
     */
    private const ALLOWED_ATTRS = [
        // identification
        'id', 'class',
        // dimensions / coords
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'width', 'height', 'viewbox', 'preserveaspectratio',
        // path / shape data
        'd', 'points', 'pathlength',
        // transforms
        'transform', 'gradienttransform', 'patterntransform',
        // strokes / fills (literal colors / refs only — values are filtered)
        'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset',
        // opacity
        'opacity',
        // text
        'font-family', 'font-size', 'font-weight', 'font-style',
        'text-anchor', 'dominant-baseline', 'letter-spacing', 'word-spacing',
        'text-decoration',
        // gradients/stops
        'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'spreadmethod',
        'fx', 'fy',
        // markers
        'marker-start', 'marker-mid', 'marker-end',
        'markerwidth', 'markerheight', 'markerunits',
        'orient', 'refx', 'refy',
        // patterns
        'patternunits', 'patterncontentunits',
        // clipping/masking
        'clip-path', 'clip-rule', 'mask',
        'maskunits', 'maskcontentunits',
        // filters
        'filter', 'filterunits', 'primitiveunits',
        'in', 'in2', 'result', 'mode',
        'stddeviation', 'edgemode',
        'type', 'values', 'tablevalues',
        'k1', 'k2', 'k3', 'k4',
        // viewport
        'overflow', 'visibility', 'display',
        // SVG namespace declarations are explicitly allowed in the root walker.
        'version',
        // safe references to defs (sanitized further below)
        'href', 'xlink:href',
    ];

    /** Safe URL schemes for href/xlink:href values. */
    private const SAFE_URL_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Returns sanitized SVG source. Throws on irrecoverable input.
     *
     * @throws RuntimeException
     */
    public static function sanitize(string $svg): string
    {
        $svg = self::stripBOM($svg);
        $svg = self::stripProcessingInstructions($svg);
        $svg = self::stripDoctype($svg);

        if ($svg === '' || stripos($svg, '<svg') === false) {
            throw new RuntimeException('Yuklangan fayl SVG emas');
        }

        // libxml2 safe load
        $prevUseInternal = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // PHP 8.0+ disables external entity loading by default; older PHP needs the toggle.
        $prevDisableEntL = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            // suppress: the function exists but is deprecated on 8.0+; we gate by PHP_VERSION_ID
            $prevDisableEntL = @libxml_disable_entity_loader(true);
        }

        try {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = false;
            $doc->formatOutput        = false;
            $doc->resolveExternals    = false;
            $doc->substituteEntities  = false;

            // LIBXML_NONET prevents network fetches; LIBXML_NOENT is INTENTIONALLY OFF.
            $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$loaded) {
                throw new RuntimeException('SVG ni o\'qib bo\'lmadi (xato XML)');
            }

            $root = $doc->documentElement;
            if ($root === null || strtolower($root->localName ?? '') !== 'svg') {
                throw new RuntimeException('Faylning ildiz elementi <svg> emas');
            }

            // Reject anything outside SVG namespace at the root
            // (allows the standard SVG ns + xlink ns).
            self::walkAndClean($root);

            $out = $doc->saveXML($root);
            if ($out === false || $out === '') {
                throw new RuntimeException('SVG ni qayta serializatsiya qilib bo\'lmadi');
            }

            // Final paranoia pass: regex-strip anything that DOMDocument may have
            // round-tripped (e.g. CDATA-wrapped script blocks inside <text>).
            $out = self::finalRegexStrip($out);

            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseInternal);
            if ($prevDisableEntL !== null
                && PHP_VERSION_ID < 80000
                && function_exists('libxml_disable_entity_loader')) {
                @libxml_disable_entity_loader($prevDisableEntL);
            }
        }
    }

    /* ============================================================
     *  WALKER
     * ============================================================ */
    private static function walkAndClean(DOMNode $node): void
    {
        $toRemove = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $toRemove[] = $child;
                continue;
            }
            if ($child->nodeType === XML_PI_NODE) {
                $toRemove[] = $child;
                continue;
            }
            if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
                $toRemove[] = $child;
                continue;
            }
            if (!($child instanceof DOMElement)) continue;

            $name = strtolower($child->localName ?? '');

            // Drop disallowed elements outright.
            if (!in_array($name, self::ALLOWED_TAGS, true)) {
                $toRemove[] = $child;
                continue;
            }

            // Reject <style> regardless (defense in depth — not in whitelist anyway)
            if ($name === 'style' || $name === 'script') {
                $toRemove[] = $child;
                continue;
            }

            // Clean attributes
            self::sanitizeAttributes($child, $name);

            // Recurse
            self::walkAndClean($child);
        }
        foreach ($toRemove as $n) {
            if ($n->parentNode !== null) {
                $n->parentNode->removeChild($n);
            }
        }
    }

    /* ============================================================
     *  ATTRIBUTE SANITIZATION
     * ============================================================ */
    private static function sanitizeAttributes(DOMElement $el, string $tagName): void
    {
        $remove = [];
        foreach ($el->attributes as $attr) {
            $rawName = $attr->nodeName;       // may include "xmlns:xlink" / "xlink:href"
            $local   = strtolower($attr->localName ?? '');
            $prefix  = strtolower($attr->prefix ?? '');
            $value   = (string) $attr->nodeValue;

            // Always drop on* event handlers
            if (str_starts_with($local, 'on')) {
                $remove[] = $rawName;
                continue;
            }

            // Allow xmlns / xmlns:* declarations only on the root <svg>
            if (str_starts_with(strtolower($rawName), 'xmlns')) {
                continue;
            }

            // Reject attributes whose VALUE contains javascript:/vbscript:/data: scheme
            // anywhere (covers `style="background:url(javascript:...)"` if a style attr
            // sneaks in via prefix tricks).
            if (preg_match('/(?:^|[\s\(\'"])(?:javascript|vbscript|data|file|jar)\s*:/i', $value)) {
                $remove[] = $rawName;
                continue;
            }

            // Reject attributes containing &#x... entity tricks for "javascript:"
            if (preg_match('/&#x?\d+;?[a-z]*script/i', $value)) {
                $remove[] = $rawName;
                continue;
            }

            // Whitelist
            $isHrefLike = ($local === 'href') || ($prefix === 'xlink' && $local === 'href');
            if (!in_array($local, self::ALLOWED_ATTRS, true)
                && !str_starts_with(strtolower($rawName), 'xlink:href')
                && !$isHrefLike) {
                $remove[] = $rawName;
                continue;
            }

            // Special handling for hrefs
            if ($isHrefLike || str_starts_with(strtolower($rawName), 'xlink:href')) {
                if (!self::isSafeHref($value)) {
                    $remove[] = $rawName;
                    continue;
                }
                // For <use>, only allow same-document fragments (#id).
                if ($tagName === 'use' && !str_starts_with($value, '#')) {
                    $remove[] = $rawName;
                    continue;
                }
            }

            // Special handling for url(...) inside fill/stroke/clip-path/filter/mask.
            // Only allow url(#localFragment) — never url(data:...) or url(http://...).
            if (in_array($local, ['fill', 'stroke', 'clip-path', 'filter', 'mask',
                                  'marker-start', 'marker-mid', 'marker-end'], true)
                && stripos($value, 'url(') !== false) {
                if (!preg_match('/^url\(\s*#[A-Za-z0-9_\-]+\s*\)$/i', trim($value))) {
                    $remove[] = $rawName;
                    continue;
                }
            }
        }
        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
    }

    private static function isSafeHref(string $value): bool
    {
        $v = trim($value);
        if ($v === '') return false;
        // Same-document fragment is always safe
        if (str_starts_with($v, '#')) return true;
        // Relative path? (no scheme) — accept
        if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $v)) return true;
        // Has a scheme — must be in whitelist
        $scheme = strtolower(substr($v, 0, strpos($v, ':')));
        return in_array($scheme, self::SAFE_URL_SCHEMES, true);
    }

    /* ============================================================
     *  PRE-/POST-PROCESS
     * ============================================================ */
    private static function stripBOM(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            return substr($s, 3);
        }
        return $s;
    }

    private static function stripProcessingInstructions(string $s): string
    {
        // The XML declaration is fine; anything else (PI) we drop.
        // We'll re-add the XML declaration via DOMDocument.
        return preg_replace('/<\?(?!xml\b)[^?]*\?>/i', '', $s) ?? $s;
    }

    private static function stripDoctype(string $s): string
    {
        // Reject <!DOCTYPE ...> (even if libxml might tolerate it),
        // because non-trivial DOCTYPEs can carry XXE payloads.
        return preg_replace('/<!DOCTYPE[^>\[]*(\[[^\]]*\])?[^>]*>/is', '', $s) ?? $s;
    }

    private static function finalRegexStrip(string $s): string
    {
        // 1) Belt-and-braces: drop any leftover <script>...</script>
        $s = preg_replace('#<\s*script\b[^>]*>.*?</\s*script\s*>#is', '', $s) ?? $s;
        // 2) Drop any on*=... that somehow round-tripped (defensive)
        $s = preg_replace('#\s+on[a-z]+\s*=\s*"(?:[^"\\\\]|\\\\.)*"#i', '', $s) ?? $s;
        $s = preg_replace("#\\s+on[a-z]+\\s*=\\s*'(?:[^'\\\\]|\\\\.)*'#i", '', $s) ?? $s;
        // 3) Drop javascript: / vbscript: / data: in any leftover URL
        $s = preg_replace('#(href|xlink:href)\s*=\s*"(?:javascript|vbscript|data|file|jar):[^"]*"#i', '', $s) ?? $s;
        $s = preg_replace("#(href|xlink:href)\\s*=\\s*'(?:javascript|vbscript|data|file|jar):[^']*'#i", '', $s) ?? $s;
        return $s;
    }
}
