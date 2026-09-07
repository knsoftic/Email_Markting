<?php

namespace App\Services\Contacts;

use League\Csv\Info;
use League\Csv\Reader;
use League\Csv\Statement;

/**
 * Opens an uploaded CSV safely and streams it.
 *
 * Real customer exports are messy: Excel writes UTF-16 or CP1252 with a BOM,
 * European locales use semicolons, and files arrive with CRLF line endings and
 * ragged rows. This class absorbs all of that so the import job only ever sees
 * clean UTF-8 associative rows, and never holds more than one row in memory.
 */
class CsvReader
{
    public const DELIMITERS = [',', ';', "\t", '|'];

    public function __construct(protected string $path) {}

    public static function open(string $path): self
    {
        return new self($path);
    }

    /**
     * Builds a configured league/csv Reader.
     *
     * The encoding filter is attached at the stream level, so conversion
     * happens as bytes are read rather than by loading the file into a string.
     */
    public function reader(bool $withHeader = true): Reader
    {
        $reader = Reader::createFromPath($this->path, 'r');
        $reader->setDelimiter($this->detectDelimiter());
        $reader->skipEmptyRecords();

        $encoding = $this->detectEncoding();

        if ($encoding !== 'UTF-8') {
            // iconv//TRANSLIT keeps a stray smart quote from aborting the read.
            $reader->addStreamFilter("convert.iconv.{$encoding}/UTF-8//TRANSLIT");
        }

        // Strips the BOM Excel writes, which would otherwise become part of
        // the first header name and break column mapping.
        $reader->skipInputBOM();

        if ($withHeader) {
            $reader->setHeaderOffset(0);
        }

        return $reader;
    }

    /**
     * Column headers, de-duplicated and never empty, because they become the
     * keys the mapping UI works with.
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        $raw = $this->reader()->getHeader();

        $headers = [];
        $seen = [];

        foreach ($raw as $index => $name) {
            $name = trim((string) $name);

            if ($name === '') {
                $name = 'column_'.($index + 1);
            }

            $base = $name;
            $n = 1;

            while (in_array($name, $seen, true)) {
                $name = $base.'_'.(++$n);
            }

            $seen[] = $name;
            $headers[] = $name;
        }

        return $headers;
    }

    /**
     * First N data rows, for the mapping preview.
     *
     * @return array<int, array<string, string|null>>
     */
    public function sample(int $limit = 5): array
    {
        $records = Statement::create()->limit($limit)->process($this->reader());

        return collect($records)->map(fn ($row) => $this->normaliseRow($row))->values()->all();
    }

    /**
     * Counts data rows without materialising them. Used once at upload time so
     * the progress bar has a denominator.
     */
    public function countRows(): int
    {
        // count() on the Reader includes the header row when one is set, so
        // this reads the record iterator instead.
        $reader = $this->reader();

        $count = 0;
        foreach ($reader->getRecords() as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * Streams data rows in chunks. Yields [offset, rows] so the caller can
     * report progress and resume from a known position.
     *
     * @return \Generator<int, array{0: int, 1: array<int, array<string, string|null>>}>
     */
    public function chunks(int $size = 500, int $startOffset = 0): \Generator
    {
        $reader = $this->reader();
        $buffer = [];
        $offset = 0;

        foreach ($reader->getRecords() as $row) {
            $offset++;

            // Resume support: skip rows a previous attempt already handled.
            if ($offset <= $startOffset) {
                continue;
            }

            $buffer[] = $this->normaliseRow($row);

            if (count($buffer) >= $size) {
                yield [$offset, $buffer];
                $buffer = [];
            }
        }

        if ($buffer) {
            yield [$offset, $buffer];
        }
    }

    /**
     * Picks the delimiter that yields the most consistent column count over
     * the first rows, rather than assuming a comma.
     */
    public function detectDelimiter(): string
    {
        try {
            $probe = Reader::createFromPath($this->path, 'r');
            $stats = Info::getDelimiterStats($probe, self::DELIMITERS, 10);

            arsort($stats);
            $best = array_key_first($stats);

            return ($best !== null && $stats[$best] > 0) ? $best : ',';
        } catch (\Throwable) {
            return ',';
        }
    }

    /**
     * Sniffs the encoding from the first 64 KB. UTF-16 is detected from its
     * BOM, because mb_detect_encoding is unreliable for it.
     */
    public function detectEncoding(): string
    {
        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return 'UTF-8';
        }

        $sample = (string) fread($handle, 65536);
        fclose($handle);

        if (str_starts_with($sample, "\xFF\xFE")) {
            return 'UTF-16LE';
        }

        if (str_starts_with($sample, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }

        if (mb_check_encoding($sample, 'UTF-8')) {
            return 'UTF-8';
        }

        $detected = mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

        return $detected ?: 'Windows-1252';
    }

    /**
     * Trims values, collapses empty strings to null, and drops the ragged
     * extra cells league/csv reports as numeric keys on a malformed row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string|null>
     */
    protected function normaliseRow(array $row): array
    {
        $clean = [];

        foreach ($row as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $value = is_scalar($value) ? trim((string) $value) : null;
            $clean[trim($key)] = ($value === '' ? null : $value);
        }

        return $clean;
    }
}
