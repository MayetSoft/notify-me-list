<?php
/**
 * RSS 2.0 / RSS 0.9x / RSS 1.0 (RDF) / Atom 1.0 parser built on DOMDocument.
 * The format is detected from the root element. Parsing is tolerant
 * (libxml recover mode) but never loads external entities or DTDs.
 */
final class FeedParser
{
    const SUMMARY_LENGTH = 280;

    /**
     * Returns ['format' => rss|rdf|atom, 'title' => string, 'items' => [
     *   ['key', 'title', 'link', 'summary', 'published_at' (int|null)], ...]]
     * Throws RuntimeException if the document is not a feed.
     */
    public static function parse(string $xml, string $baseUrl = ''): array
    {
        $xml = self::prepare($xml);
        $doc = self::load($xml);
        $root = $doc->documentElement;
        if (!$root) {
            throw new RuntimeException(t('feed.err_not_feed'));
        }
        $rootName = strtolower($root->localName);
        if ($rootName === 'rss') {
            $channel = self::child($root, ['channel']);
            if (!$channel) {
                throw new RuntimeException(t('feed.err_not_feed'));
            }
            return ['format' => 'rss', 'title' => self::text(self::child($channel, ['title'])), 'items' => self::rssItems(self::children($channel, 'item'), $baseUrl)];
        }
        if ($rootName === 'rdf') {
            $channel = self::child($root, ['channel']);
            return ['format' => 'rdf', 'title' => $channel ? self::text(self::child($channel, ['title'])) : '', 'items' => self::rssItems(self::children($root, 'item'), $baseUrl)];
        }
        if ($rootName === 'feed') {
            return ['format' => 'atom', 'title' => self::text(self::child($root, ['title'])), 'items' => self::atomEntries(self::children($root, 'entry'), $baseUrl)];
        }
        throw new RuntimeException(t('feed.err_not_feed'));
    }

    /** Cleans the raw payload before parsing. */
    private static function prepare(string $xml): string
    {
        // Strip UTF-8 BOM and leading garbage/whitespace before the first tag.
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        $start = strpos($xml, '<');
        if ($start === false) {
            throw new RuntimeException(t('feed.err_not_feed'));
        }
        $xml = substr($xml, $start);
        // Refuse inline entity declarations (billion laughs / XXE attempts).
        if (preg_match('/<!ENTITY/i', substr($xml, 0, 4096))) {
            throw new RuntimeException(t('feed.err_entities'));
        }
        // Remove characters that are illegal in XML 1.0 (a frequent cause of broken feeds).
        if (preg_match('//u', $xml)) {
            $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $xml);
            if ($clean !== null) {
                $xml = $clean;
            }
        }
        return $xml;
    }

    private static function load(string $xml): DOMDocument
    {
        $prevErrors = libxml_use_internal_errors(true);
        $prevLoader = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $prevLoader = libxml_disable_entity_loader(true);
        }
        $doc = new DOMDocument();
        $doc->recover = true;
        $doc->resolveExternals = false;
        $doc->substituteEntities = false;
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);
        if ($prevLoader !== null) {
            libxml_disable_entity_loader($prevLoader);
        }
        if (!$ok || !$doc->documentElement) {
            $first = $errors ? trim($errors[0]->message) . ' (line ' . $errors[0]->line . ')' : '';
            throw new RuntimeException(t('feed.err_xml', ['error' => $first]));
        }
        return $doc;
    }

    private static function rssItems(array $items, string $baseUrl): array
    {
        $out = [];
        foreach ($items as $item) {
            $title = self::plain(self::text(self::child($item, ['title'])));
            $link = self::text(self::child($item, ['link']));
            if ($link === '') {
                // Some feeds only give the permalink as a guid.
                $guidEl = self::child($item, ['guid']);
                if ($guidEl && strtolower($guidEl->getAttribute('isPermaLink')) !== 'false' && preg_match('~^https?://~i', self::text($guidEl))) {
                    $link = self::text($guidEl);
                }
            }
            $guid = self::text(self::child($item, ['guid']));
            if ($guid === '' && $item->hasAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about')) {
                $guid = $item->getAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about');
            }
            $summary = self::text(self::child($item, ['description']));
            if ($summary === '') {
                $summary = self::text(self::child($item, ['encoded'])); // content:encoded
            }
            $date = self::date(self::text(self::child($item, ['pubDate', 'date', 'published', 'updated'])));
            $out[] = self::item($guid, $title, $link, $summary, $date, $baseUrl);
        }
        return $out;
    }

    private static function atomEntries(array $entries, string $baseUrl): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $title = self::plain(self::text(self::child($entry, ['title'])));
            $link = '';
            foreach (self::children($entry, 'link') as $l) {
                $rel = strtolower($l->getAttribute('rel'));
                if ($rel === '' || $rel === 'alternate') {
                    $link = trim($l->getAttribute('href'));
                    break;
                }
                if ($link === '') {
                    $link = trim($l->getAttribute('href'));
                }
            }
            $summary = self::text(self::child($entry, ['summary']));
            if ($summary === '') {
                $summary = self::text(self::child($entry, ['content']));
            }
            $date = self::date(self::text(self::child($entry, ['published', 'updated', 'issued', 'modified'])));
            $id = self::text(self::child($entry, ['id']));
            $out[] = self::item($id, $title, $link, $summary, $date, $baseUrl);
        }
        return $out;
    }

    private static function item(string $id, string $title, string $link, string $summary, ?int $date, string $baseUrl): array
    {
        if ($link !== '' && $baseUrl !== '' && !preg_match('~^[a-z][a-z0-9+.-]*:~i', $link)) {
            $link = FeedFetcher::resolveRelative($baseUrl, $link);
        }
        $link = nm_safe_url($link);
        $summary = nm_excerpt($summary, self::SUMMARY_LENGTH);
        if ($title === '') {
            $title = $summary !== '' ? nm_excerpt($summary, 80) : ($link !== '' ? $link : t('feed.untitled'));
        }
        // Identity of the item: GUID/id, else link, else hash(title + date).
        $id = trim($id);
        if ($id !== '') {
            $key = 'id:' . $id;
        } elseif ($link !== '') {
            $key = 'link:' . $link;
        } else {
            $key = 'hash:' . sha1($title . '|' . (string) $date);
        }
        if (strlen($key) > 500) {
            $key = 'sha1:' . sha1($key);
        }
        return [
            'key' => $key,
            'title' => mb_substr($title, 0, 500),
            'link' => mb_substr($link, 0, 2000),
            'summary' => $summary,
            'published_at' => $date,
        ];
    }

    // --- DOM helpers (namespace-agnostic, by local name) ----------------------

    private static function child(DOMElement $parent, array $names): ?DOMElement
    {
        $lower = array_map('strtolower', $names);
        // Respect the preference order of $names.
        $found = [];
        foreach ($parent->childNodes as $n) {
            if ($n instanceof DOMElement) {
                $idx = array_search(strtolower($n->localName), $lower, true);
                if ($idx !== false && !isset($found[$idx])) {
                    $found[$idx] = $n;
                }
            }
        }
        if (!$found) {
            return null;
        }
        ksort($found);
        return reset($found);
    }

    /** @return DOMElement[] */
    private static function children(DOMElement $parent, string $name): array
    {
        $out = [];
        foreach ($parent->childNodes as $n) {
            if ($n instanceof DOMElement && strtolower($n->localName) === strtolower($name)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    private static function text(?DOMElement $el): string
    {
        return $el ? trim($el->textContent) : '';
    }

    /** Titles sometimes contain escaped HTML: turn them into plain text. */
    private static function plain(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function date(string $s): ?int
    {
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        if ($ts === false) {
            // RFC 822 dates with localized day/month names or odd time zones.
            $ts = strtotime(preg_replace('/^[^,]*,\s*/', '', $s));
        }
        return $ts === false || $ts <= 0 ? null : $ts;
    }
}
