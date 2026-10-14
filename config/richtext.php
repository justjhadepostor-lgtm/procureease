<?php
/**
 * Whitelist sanitiser for the rich text people write in Market Scoping.
 *
 * The editor stores real HTML, and the admin views what the department wrote,
 * so anything unsafe has to be stripped before it is ever rendered back out.
 * Only formatting tags survive; scripts, event handlers and javascript: URLs do not.
 */

/** Tags people are allowed to use, with the attributes each may carry. */
function rt_allowed_tags(): array {
    return [
        'p' => ['style'], 'div' => ['style'], 'span' => ['style'], 'br' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [],
        'sub' => [], 'sup' => [], 'mark' => ['style'],
        'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'],
        'ul' => ['style'], 'ol' => ['style'], 'li' => ['style'],
        'blockquote' => ['style'], 'hr' => [], 'pre' => ['style'], 'code' => ['style'],
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'style'],
        'table' => ['style', 'border', 'cellpadding', 'cellspacing'],
        'thead' => ['style'], 'tbody' => ['style'], 'tfoot' => ['style'],
        'tr' => ['style'], 'td' => ['style', 'colspan', 'rowspan'], 'th' => ['style', 'colspan', 'rowspan'],
        'font' => ['face', 'size', 'color'],
    ];
}

/** CSS properties that are safe to keep in a style attribute. */
function rt_allowed_css(): array {
    return [
        'color', 'background-color', 'background', 'font-size', 'font-family', 'font-weight',
        'font-style', 'text-decoration', 'text-align', 'text-indent', 'line-height',
        'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
        'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
        'border', 'border-collapse', 'border-color', 'border-width', 'border-style',
        'width', 'height', 'max-width', 'display', 'float', 'vertical-align', 'list-style-type',
    ];
}

/** Strip anything dangerous out of a style attribute. */
function rt_clean_style(string $style): string {
    $allowed = rt_allowed_css();
    $out = [];
    foreach (explode(';', $style) as $decl) {
        if (strpos($decl, ':') === false) continue;
        [$prop, $val] = explode(':', $decl, 2);
        $prop = strtolower(trim($prop));
        $val  = trim($val);
        if ($prop === '' || $val === '') continue;
        if (!in_array($prop, $allowed, true)) continue;
        // No expressions, no url() fetches, no escapes
        if (preg_match('/expression|javascript:|url\s*\(|@import|\\\\/i', $val)) continue;
        $out[] = "$prop: $val";
    }
    return implode('; ', $out);
}

/** True when an image or link target is safe to keep. */
function rt_safe_url(string $url, bool $allowData = false): bool {
    $u = trim($url);
    if ($u === '') return false;
    if (preg_match('/^\s*javascript:/i', $u)) return false;
    if (preg_match('/^\s*vbscript:/i', $u))   return false;
    if (stripos($u, 'data:') === 0) {
        // Only inline images, and only real image types
        return $allowData && (bool)preg_match('#^data:image/(png|jpe?g|gif|webp|bmp);base64,#i', $u);
    }
    return (bool)preg_match('#^(https?://|/|\./|[\w\-./]+$|mailto:|\#)#i', $u);
}

/**
 * Sanitise a block of rich text down to the whitelist above.
 * Returns HTML that is safe to echo directly.
 */
function rt_sanitize(string $html): string {
    if (trim($html) === '') return '';

    // Kill whole dangerous elements, contents and all, before parsing
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta|base|svg)\b.*?<\s*/\s*\1\s*>#is', '', $html);
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta|base|svg)\b[^>]*/?>#is', '', $html);

    if (!class_exists('DOMDocument')) {
        // Without DOM support, fall back to plain text so nothing unsafe survives
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    $allowed = rt_allowed_tags();

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="rt-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $root = $doc->getElementById('rt-root');
    if (!$root) return '';

    // Walk the tree depth-first, unwrapping or cleaning as we go
    $walk = function (DOMNode $node) use (&$walk, $allowed, $doc) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);

                if (!isset($allowed[$tag])) {
                    // Not on the list: keep the text inside, drop the tag itself
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->nodeName);
                    $val  = $attr->nodeValue;

                    // Event handlers never survive
                    if (strpos($name, 'on') === 0 || !in_array($name, $allowed[$tag], true)) {
                        $child->removeAttribute($attr->nodeName);
                        continue;
                    }
                    if ($name === 'style') {
                        $clean = rt_clean_style($val);
                        if ($clean === '') $child->removeAttribute('style');
                        else               $child->setAttribute('style', $clean);
                        continue;
                    }
                    if ($name === 'src') {
                        if (!rt_safe_url($val, true)) $child->removeAttribute('src');
                        continue;
                    }
                    if ($name === 'href') {
                        if (!rt_safe_url($val)) $child->removeAttribute('href');
                        continue;
                    }
                }

                // Links always open safely
                if ($tag === 'a' && $child->hasAttribute('href')) {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                }

                $walk($child);

            } elseif (!in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                // Comments, processing instructions and the like
                $node->removeChild($child);
            }
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}
