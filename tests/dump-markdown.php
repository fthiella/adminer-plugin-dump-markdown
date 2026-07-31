<?php

declare(strict_types=1);

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v1.2.0 (July 29th, 2026)
 *
 * @link https://github.com/fthiella/adminer-plugin-dump-markdown
 * @author Federico Thiella, https://fthiella.github.io/
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 */

/**
 * Adminer plugin that dumps database structure and data as Markdown tables.
 */
class AdminerDumpMarkdown
{
    private string $type = 'markdown';
    private string $format = 'Markdown';

    /** @var array<string,string> Characters used to build Markdown tables. */
    private array $markdownChr;

    private int $rowSampleLimit;
    private string $nullValue;

    private string $specialChars;
    private bool $disableUTF8;
    private bool $mbStrAvailable;

    private bool $tableAlign;
    private bool $tablePipes;

    /** @var array<string,string> Per-column alignment overrides, keyed by column name. */
    private array $columnAlign;

    /** @var array<string,string> Default alignment per inferred data type. */
    private array $typeAlign;

    /** @var array<string,array<string,mixed>> Field metadata for the table currently being dumped. */
    private array $fields = [];

    /**
     * @param array<string,mixed> $config {
     *     @type int    $rowSampleLimit Number of rows buffered to compute column widths before streaming. Default 100.
     *     @type string $nullValue      Placeholder text for NULL values. Default "N/D".
     *     @type string $specialChars   Characters escaped in Markdown output.
     *     @type array  $markdown_chr   Overrides for the space/table/header characters.
     *     @type bool   $disableUTF8    Disable multibyte-safe string handling.
     *     @type bool   $tableAlign     Enable column-alignment markers in the separator row.
     *     @type bool   $tablePipes     Wrap rows in leading/trailing pipes.
     *     @type array  $columnAlign    Per-column alignment overrides.
     *     @type array  $typeAlign      Default alignment per data type ('number', 'bool', 'default').
     * }
     */
    public function __construct(array $config = [])
    {
        $this->rowSampleLimit = $config['rowSampleLimit'] ?? 100;
        $this->nullValue = $config['nullValue'] ?? 'N/D';

        $this->specialChars = $config['specialChars'] ?? '\\*_[](){}+-#\!|';
        $this->markdownChr = $config['markdown_chr'] ?? ['space' => ' ', 'table' => '|', 'header' => '-'];
        $this->disableUTF8 = $config['disableUTF8'] ?? false;

        $this->tableAlign = $config['tableAlign'] ?? false;
        $this->tablePipes = $config['tablePipes'] ?? false;
        $this->columnAlign = $config['columnAlign'] ?? [];

        $this->typeAlign = $config['typeAlign'] ?? [
            'number' => 'right',
            'bool' => 'center',
            'default' => 'left',
        ];

        $this->mbStrAvailable = extension_loaded('mbstring');

        if (!$this->mbStrAvailable && !$this->disableUTF8) {
            // Use error_log() rather than echo: this constructor can run before
            // dumpHeaders() sends the Content-Type header, and any prior output
            // would trigger a "headers already sent" warning.
            error_log("AdminerDumpMarkdown: the PHP 'mbstring' extension is not enabled; falling back to byte-based string handling. Enable 'mbstring' for correct UTF-8 support.");
        }
    }

    private function getStringLength(string $value): int
    {
        if ($this->disableUTF8 || !$this->mbStrAvailable) {
            return strlen($value);
        }
        return mb_strlen($value, 'UTF-8');
    }

    private function getStrPos(string $haystack, string $needle): int|false
    {
        if ($this->disableUTF8 || !$this->mbStrAvailable) {
            return strpos($haystack, $needle);
        }
        return mb_strpos($haystack, $needle);
    }

    private function getSubString(string $value, int $start, int $length): string
    {
        if ($this->disableUTF8 || !$this->mbStrAvailable) {
            return substr($value, $start, $length);
        }
        return mb_substr($value, $start, $length, 'UTF-8');
    }

    /**
     * Converts a UTF-8 string to a single-byte encoding for legacy environments.
     * Replaces the deprecated utf8_decode() (removed in PHP 9.0).
     */
    private function toLegacyEncoding(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value);
        return $converted !== false ? $converted : $value;
    }

    private function escapeMarkdown(string|int|float|bool $value): string
    {
        $escapedValue = '';

        $value = strval($value);
        if ($this->disableUTF8) {
            $value = $this->toLegacyEncoding($value);
        }

        $length = $this->getStringLength($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $this->getSubString($value, $i, 1);
            if ($this->getStrPos($this->specialChars, $char) !== false) {
                $escapedValue .= '\\' . $char;
            } else {
                $escapedValue .= $char;
            }
        }

        return $escapedValue;
    }

    private function processValue(mixed $value): string
    {
        if ($value === null) {
            return $this->nullValue;
        }
        $value = str_replace(["\r\n", "\r", "\n"], ' ', strval($value));
        return $this->escapeMarkdown($value);
    }

    private function formatValue(string $s, int $l, string $c, string $mode = 'left'): string
    {
        $len = $this->getStringLength($s);
        if ($len > $l) {
            return $this->getSubString($s, 0, $l);
        }

        if ($mode === 'right') {
            return str_repeat($c, $l - $len) . $s;
        }
        if ($mode === 'center') {
            $left = (int) floor(($l - $len) / 2);
            return str_repeat($c, $left) . $s . str_repeat($c, $l - $len - $left);
        }
        return $s . str_repeat($c, $l - $len);
    }

    /**
     * Determines the alignment for a given column, based on (in priority order)
     * explicit overrides, inferred SQL type, and heuristics on the sample value.
     */
    private function getAlign(string $colName, string $value): string
    {
        // If table alignment is disabled, everything is forced to the left.
        if (!$this->tableAlign) {
            return 'left';
        }

        if (isset($this->columnAlign[$colName])) {
            return $this->columnAlign[$colName];
        }

        if (isset($this->fields[$colName])) {
            $type = strtolower($this->fields[$colName]['type']);
            // Boolean check must run before the numeric regex: 'tinyint'
            // contains the substring 'int', so a tinyint(1) boolean column
            // would otherwise always match the numeric branch first and
            // never be centered as a boolean.
            if ($type === 'boolean' || ($type === 'tinyint' && str_contains($this->fields[$colName]['full_type'], '(1)'))) {
                return $this->typeAlign['bool'];
            }
            if (preg_match('/int|float|double|decimal|numeric|real|bit/', $type)) {
                return $this->typeAlign['number'];
            }
        }

        if ($value === 'Yes' || $value === 'No') {
            return $this->typeAlign['bool'];
        }
        return $this->typeAlign['default'];
    }

    /**
     * @param array<string,string> $row
     * @param array<string,int> $columnWidth
     * @param array<string,string> $aligns
     */
    private function markdownRow(array $row, array $columnWidth, array $aligns, string $separator, string $filler): string
    {
        $padded = [];
        foreach ($row as $k => $v) {
            $mode = $aligns[$k] ?? 'left';
            $padded[$k] = $this->formatValue((string) $v, $columnWidth[$k], $filler, $mode);
        }
        $out = implode($separator, $padded);
        $t = $this->markdownChr['table'];
        return $this->tablePipes ? $t . $filler . $out . $filler . $t : $out;
    }

    /**
     * @param array<string,string> $row
     * @return array<string,string>
     */
    private function mapHeader(array $row): array
    {
        $header = [];
        foreach ($row as $k => $v) {
            $header[$k] = $this->processValue($k);
        }
        return $header;
    }

    /**
     * @param array<int,array<string,string>> $rows
     * @param array<string,int> $columnWidth
     * @param array<string,string> $aligns
     */
    private function markdownTable(array $rows, array $columnWidth, array $aligns = []): string
    {
        if (empty($rows)) {
            return '';
        }

        $t = $this->markdownChr['table'];
        $h = $this->markdownChr['header'];
        $s = $this->markdownChr['space'];

        // 1. Header row: uses column names instead of data.
        $content = $this->markdownRow($this->mapHeader($rows[0]), $columnWidth, $aligns, $s . $t . $s, $s) . "\n";

        // 2. Separator row: character-aligned with the header/data rows.
        $sepParts = [];
        foreach ($columnWidth as $k => $w) {
            $mode = $aligns[$k] ?? 'left';

            if (!$this->tableAlign) {
                $sepParts[$k] = str_repeat($h, $w);
            } elseif ($mode === 'center') {
                $sepParts[$k] = ':' . str_repeat($h, max(0, $w - 2)) . ':';
            } elseif ($mode === 'right') {
                $sepParts[$k] = str_repeat($h, max(0, $w - 1)) . ':';
            } else {
                $sepParts[$k] = ':' . str_repeat($h, max(0, $w - 1));
            }
        }

        $sepLine = implode($s . $t . $s, $sepParts);

        $content .= $this->tablePipes
            ? $t . $s . $sepLine . $s . $t . "\n"
            : $sepLine . "\n";

        // 3. Data rows.
        foreach ($rows as $row) {
            $content .= $this->markdownRow($row, $columnWidth, $aligns, $s . $t . $s, $s) . "\n";
        }
        return $content;
    }

    private function bool(mixed $value): string
    {
        return $value == 1 ? 'Yes' : 'No';
    }

    /**
     * @return array<string,string>
     */
    public function dumpFormat(): array
    {
        return [$this->type => $this->format];
    }

    public function dumpDatabase(string $db): ?bool
    {
        if (($_POST['format'] ?? null) === $this->type) {
            echo '# ' . $this->escapeMarkdown($db) . "\n\n";
            return true;
        }
        return null;
    }

    public function dumpTable(string $table, bool|string $style, bool $is_view = false): ?bool
    {
        if (($_POST['format'] ?? null) !== $this->type) {
            return null;
        }

        echo '## ' . $this->escapeMarkdown($table) . "\n\n";

        $this->fields = [];
        foreach (Adminer\fields($table) as $f) {
            $this->fields[$f['field']] = $f;
        }

        if ($style) {
            echo "### table structure\n\n";
            $fieldRows = [];
            $fieldWidth = ['Column name' => 11, 'Type' => 4, 'Comment' => 7, 'Null' => 4, 'AI' => 2];

            foreach ($this->fields as $f) {
                $newRow = [
                    'Column name' => $this->processValue($f['field']),
                    'Type' => $f['full_type'],
                    'Comment' => $f['comment'],
                    'Null' => $this->bool($f['null']),
                    'AI' => $this->bool($f['auto_increment']),
                ];
                $fieldRows[] = $newRow;
                foreach ($newRow as $k => $v) {
                    $fieldWidth[$k] = max($fieldWidth[$k], $this->getStringLength((string) $v));
                }
            }

            $structureAlign = ['Column name' => 'left', 'Type' => 'left', 'Comment' => 'left', 'Null' => 'center', 'AI' => 'center'];
            echo $this->markdownTable($fieldRows, $fieldWidth, $structureAlign);
            echo "\n";
        }

        return true;
    }

    public function dumpData(string $table, bool|string $style, string $query): ?bool
    {
        if (($_POST['format'] ?? null) !== $this->type) {
            return null;
        }

        echo "### Table Data\n\n";

        if (empty($this->fields)) {
            foreach (Adminer\fields($table) as $f) {
                $this->fields[$f['field']] = $f;
            }
        }

        $connection = Adminer\connection();
        $result = $connection->query($query, 1);

        if (!$result) {
            echo '> ERROR: query failed' . ($connection->error ? ': ' . $this->escapeMarkdown($connection->error) : '.') . "\n\n";
            return false;
        }

        $rn = 0;
        $sampleRows = [];
        $columnWidth = [];
        $aligns = [];

        while ($rawRow = $result->fetch_assoc()) {
            $row = [];
            foreach ($rawRow as $k => $v) {
                $row[$k] = $this->processValue($v);
            }

            if ($rn === 0) {
                foreach ($row as $k => $v) {
                    $columnWidth[$k] = $this->getStringLength($this->processValue($k));
                    $aligns[$k] = $this->getAlign($k, $v);
                }
            }

            if ($rn < $this->rowSampleLimit) {
                $sampleRows[$rn] = $row;
                foreach ($row as $k => $v) {
                    $columnWidth[$k] = max($columnWidth[$k], $this->getStringLength($v));
                }
            }

            if ($rn === $this->rowSampleLimit && !empty($sampleRows)) {
                echo $this->markdownTable($sampleRows, $columnWidth, $aligns);
            }

            if ($rn >= $this->rowSampleLimit && !empty($sampleRows)) {
                echo $this->markdownRow(
                    $row,
                    $columnWidth,
                    $aligns,
                    $this->markdownChr['space'] . $this->markdownChr['table'] . $this->markdownChr['space'],
                    $this->markdownChr['space']
                ) . "\n";
            }

            $rn++;
        }

        if ($rn > 0 && $rn <= $this->rowSampleLimit) {
            echo $this->markdownTable($sampleRows, $columnWidth, $aligns);
        }

        echo "\n";
        return true;
    }

    public function dumpHeaders(string $identifier, bool $multi_table = false): ?string
    {
        if (($_POST['format'] ?? null) === $this->type) {
            $this->sendHeader('Content-Type: text/markdown; charset=utf-8');
            return 'md';
        }
        return null;
    }

    /**
     * Thin wrapper around header(). header() is a no-op under the CLI SAPI
     * (headers_sent() is always true there), so this seam lets tests verify
     * the call by overriding it in a subclass instead of relying on
     * headers_list().
     */
    protected function sendHeader(string $header): void
    {
        header($header);
    }
}
