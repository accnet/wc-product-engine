<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

use RuntimeException;

/**
 * Streams feed payloads to a file as Google RSS 2.0 or TSV (10 §12), and checks a written file
 * before it may replace the published one (D09).
 *
 * Memory stays flat: XMLWriter flushes every 500 items and TSV rows are written one by one.
 */
final class FeedWriter
{
    public const GOOGLE_NS = 'http://base.google.com/ns/1.0';

    /** RSS elements written without the g: prefix. */
    private const PLAIN_XML = ['title', 'link', 'description'];

    private string $format;
    /** @var string[] */
    private array $columns;
    private ?\XMLWriter $xml = null;
    /** @var resource|null */
    private $fh = null;
    private int $count = 0;
    private string $path = '';

    /**
     * @param string[] $columns TSV columns (ignored for XML)
     */
    public function __construct(string $format, array $columns)
    {
        $this->format = $format === 'xml' ? 'xml' : 'tsv';
        $this->columns = $columns;
    }

    public function open(string $path, array $channel): void
    {
        $this->path = $path;
        $this->count = 0;
        if ($this->format === 'xml') {
            $xml = new \XMLWriter();
            if (!$xml->openUri($path)) {
                throw new RuntimeException('Cannot open feed file for writing.');
            }
            $xml->setIndent(false);
            $xml->startDocument('1.0', 'UTF-8');
            $xml->startElement('rss');
            $xml->writeAttribute('version', '2.0');
            $xml->writeAttribute('xmlns:g', self::GOOGLE_NS);
            $xml->startElement('channel');
            $xml->writeElement('title', self::clean((string)($channel['title'] ?? '')));
            $xml->writeElement('link', self::clean((string)($channel['link'] ?? '')));
            $xml->writeElement('description', self::clean((string)($channel['description'] ?? '')));
            $this->xml = $xml;
            return;
        }

        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open feed file for writing.');
        }
        $this->fh = $fh;
        fwrite($fh, implode("\t", $this->columns) . "\n");
    }

    public function write(array $payload): void
    {
        $this->count++;
        if ($this->xml !== null) {
            $x = $this->xml;
            $x->startElement('item');
            foreach ($payload as $attr => $value) {
                if ($attr === 'shipping') {
                    foreach ((array)$value as $ship) {
                        $x->startElement('g:shipping');
                        foreach (['country', 'region', 'service', 'price'] as $k) {
                            if (($ship[$k] ?? '') !== '') {
                                $x->writeElement('g:' . $k, self::clean((string)$ship[$k]));
                            }
                        }
                        $x->endElement();
                    }
                    continue;
                }
                $name = in_array($attr, self::PLAIN_XML, true) ? $attr : 'g:' . $attr;
                foreach (is_array($value) ? $value : [$value] as $v) {
                    $x->writeElement($name, self::clean((string)$v));
                }
            }
            $x->endElement();
            if ($this->count % 500 === 0) {
                $x->flush();
            }
            return;
        }

        $cells = [];
        foreach ($this->columns as $col) {
            $value = $payload[$col] ?? '';
            if ($col === 'shipping') {
                $value = implode(',', array_map(static fn(array $s): string => implode(':', [
                    $s['country'] ?? '', $s['region'] ?? '', $s['service'] ?? '', $s['price'] ?? '',
                ]), (array)$value));
            } elseif (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            $cells[] = self::tsvCell((string)$value);
        }
        fwrite($this->fh, implode("\t", $cells) . "\n");
    }

    /** @return array{items:int,bytes:int} */
    public function close(): array
    {
        if ($this->xml !== null) {
            $this->xml->endElement(); // channel
            $this->xml->endElement(); // rss
            $this->xml->endDocument();
            $this->xml->flush();
            $this->xml = null;
        }
        if ($this->fh !== null) {
            fclose($this->fh);
            $this->fh = null;
        }
        clearstatcache(true, $this->path);
        return ['items' => $this->count, 'bytes' => (int)@filesize($this->path)];
    }

    /**
     * Check a written file: well-formed XML (streamed) or TSV rows with the header's column count,
     * and the number of items equals what was written.
     *
     * @return string|null null when the file is valid, else the reason
     */
    public static function verify(string $path, string $format, int $expectedItems): ?string
    {
        if (!is_file($path)) {
            return 'file_missing';
        }
        if ($format === 'xml') {
            $reader = new \XMLReader();
            if (!$reader->open($path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
                return 'xml_unreadable';
            }
            $items = 0;
            $prev = libxml_use_internal_errors(true);
            try {
                while (@$reader->read()) {
                    if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'item') {
                        $items++;
                    }
                }
                $errors = libxml_get_errors();
                libxml_clear_errors();
            } finally {
                libxml_use_internal_errors($prev);
                $reader->close();
            }
            if (!empty($errors)) {
                return 'xml_malformed: ' . trim((string)$errors[0]->message);
            }
            return $items === $expectedItems ? null : "item_count_mismatch: {$items} != {$expectedItems}";
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 'tsv_unreadable';
        }
        $header = fgets($fh);
        $cols = $header === false ? 0 : count(explode("\t", rtrim($header, "\n")));
        $rows = 0;
        $bad = null;
        while (($line = fgets($fh)) !== false) {
            $rows++;
            if (count(explode("\t", rtrim($line, "\n"))) !== $cols) {
                $bad = "tsv_column_mismatch at row {$rows}";
                break;
            }
        }
        fclose($fh);
        if ($cols === 0) {
            return 'tsv_header_missing';
        }
        if ($bad !== null) {
            return $bad;
        }
        return $rows === $expectedItems ? null : "item_count_mismatch: {$rows} != {$expectedItems}";
    }

    /** Remove characters XML 1.0 cannot carry. */
    public static function clean(string $value): string
    {
        $value = wp_check_invalid_utf8($value, true);
        return (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    }

    public static function tsvCell(string $value): string
    {
        return trim((string)preg_replace('/[\t\r\n]+/', ' ', self::clean($value)));
    }
}
