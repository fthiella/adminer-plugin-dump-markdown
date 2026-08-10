<?php

declare(strict_types=1);

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v1.3 (August 10th, 2026)
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
    /** @var string */
    private $type = 'markdown';
    /** @var string */
    private $format = 'Markdown';

    /** @var array<string,string> Characters used to build Markdown tables. */
    private $markdownChr;

    /** @var int */
    private $rowSampleLimit;
    /** @var string */
    private $nullValue;

    /** @var string */
    private $specialChars;
    /** @var bool */
    private $disableUTF8;
    /** @var bool */
    private $mbStrAvailable;

    /** @var bool */
    private $tableAlign;
    /** @var bool */
    private $tablePipes;

    /** @var array<string,string> Per-column alignment overrides, keyed by column name. */
    private $columnAlign;

    /** @var array<string,string> Default alignment per inferred data type. */
    private $typeAlign;

    /** @var array<string,array<string,mixed>> Field metadata for the table currently being dumped. */
    private $fields = [];

    /** @var array<string,string> Maps a de-duplicated output column name (e.g. "id_2") back to its real field name, for alignment/type lookups. */
    private $columnSourceMap = [];

    /** @var string Appended to values that get truncated to fit a column's width. Empty string means truncate silently (default, preserves pre-1.2.3 behavior). Must be shorter than the column width to take effect. */
    private $truncationMarker;

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
     *     @type string $truncationMarker Appended to values truncated to fit a column's width (e.g. "~", "..."). Default "" (silent truncation, no marker). Ignored for a given cell if it doesn't fit within that column's width.
     * }
     */
    public function __construct(array $config = [])
    {
        $this->rowSampleLimit = $config['rowSampleLimit'] ?? 100;
        $this->nullValue = $config['nullValue'] ?? 'N/D';

        $this->specialChars = $config['specialChars'] ?? '\\*_[](){}+-#\!|';
        $this->markdownChr = $config['markdown_chr'] ?? ['space' => ' ', 'table' => '|', 'header' => '-'];
        $this->disableUTF8 = $config['disableUTF8'] ?? false;
        $this->truncationMarker = (string) ($config['truncationMarker'] ?? '');

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

    /**
     * @param string|int|float|bool $value
     */
    private function escapeMarkdown($value): string
    {
        $value = strval($value);
        if ($this->disableUTF8) {
            $value = $this->toLegacyEncoding($value);
        }

        $escaped = preg_quote($this->specialChars, '/');
        // Drop the 'u' modifier when using legacy (non-UTF-8) encoding
        $modifier = $this->disableUTF8 ? '' : 'u';

        $result = preg_replace('/([' . $escaped . '])/' . $modifier, '\\\$1', $value);

        // preg_replace() returns null on malformed input (e.g. invalid UTF-8
        // bytes with the 'u' modifier). Falling back to the unescaped
        // original would let raw Markdown syntax characters through and
        // corrupt the table, so fall back to a byte-safe manual escape
        // instead, which works regardless of encoding since specialChars
        // is always ASCII.
        if ($result !== null) {
            return $result;
        }
        $chars = array_unique(str_split($this->specialChars));
        $backslashPos = array_search('\\', $chars, true);
        if ($backslashPos !== false && $backslashPos !== 0) {
            unset($chars[$backslashPos]);
            array_unshift($chars, '\\');
        }
        foreach ($chars as $char) {
            $value = str_replace($char, '\\' . $char, $value);
        }
        return $value;
    }

    /**
     * @param mixed $value
     */
    private function processValue($value): string
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
            $marker = $this->disableUTF8 ? $this->toLegacyEncoding($this->truncationMarker) : $this->truncationMarker;
            $markerLen = $this->getStringLength($marker);
            if ($markerLen > 0 && $markerLen < $l) {
                return $this->getSubString($s, 0, $l - $markerLen) . $marker;
            }
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

        // Resolve de-duplicated names (e.g. "id_2" from a join producing two
        // "id" columns) back to the original field name.
        $sourceField = $this->columnSourceMap[$colName] ?? $colName;

        if (isset($this->fields[$sourceField])) {
            $type = strtolower($this->fields[$sourceField]['type']);
            // Boolean check must run before the numeric regex: 'tinyint'
            // contains the substring 'int', so a tinyint(1) boolean column
            // would otherwise always match the numeric branch first and
            // never be centered as a boolean.
            if ($type === 'boolean' || ($type === 'tinyint' && strpos($this->fields[$sourceField]['full_type'], '(1)') !== false)) {
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
            return "> No data found in table.\n";
        }

        $t = $this->markdownChr['table'];
        $h = $this->markdownChr['header'];
        $s = $this->markdownChr['space'];

        $content = $this->markdownRow($this->mapHeader($rows[0]), $columnWidth, $aligns, $s . $t . $s, $s) . "\n";

        $columnKeys = array_keys($columnWidth);
        $lastIndex = count($columnKeys) - 1;

        $sepParts = [];
        foreach ($columnKeys as $i => $k) {
            $extra = 0;
            if ($i > 0) {
                $extra++; // absorbs the space before this column's join pipe
            }
            if ($i < $lastIndex) {
                $extra++; // absorbs the space after this column's join pipe
            }
            if ($this->tablePipes && $i === 0) {
                $extra++; // absorbs the leading "| " wrap space
            }
            if ($this->tablePipes && $i === $lastIndex) {
                $extra++; // absorbs the trailing " |" wrap space
            }

            $w = $columnWidth[$k] + $extra;
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

        $sepLine = implode($t, $sepParts);

        $content .= $this->tablePipes
            ? $t . $sepLine . $t . "\n"
            : $sepLine . "\n";

        foreach ($rows as $row) {
            $content .= $this->markdownRow($row, $columnWidth, $aligns, $s . $t . $s, $s) . "\n";
        }
        return $content;
    }

    /**
     * @param mixed $value
     */
    private function bool($value): string
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
        if (($_POST['format'] ?? null) !== $this->type) {
            return null;
        }

        echo '# ' . $this->escapeMarkdown($db) . "\n\n";

        $tables = Adminer\tables_list();

        if (!empty($_POST['tables']) || !empty($_POST['data'])) {
            $selected = array_flip((array) ($_POST['tables'] ?? [])) + array_flip((array) ($_POST['data'] ?? []));
            $tables = array_intersect_key($tables, $selected);
        }

        if (!empty($tables)) {
            echo "## Table of contents\n\n";
            foreach (array_keys($tables) as $tableName) {
                echo '- [' . $this->escapeMarkdown($tableName) . '](#' . $this->slugify($tableName) . ")\n";
            }
            echo "\n";
        }

        return true;
    }

    private function slugify(string $text): string
    {
        $slug = strtolower($text);
        $slug = (string) preg_replace('/[^\w\- ]+/u', '', $slug);
        $slug = str_replace(' ', '-', $slug);
        return $slug;
    }

    public function dumpTable(string $table, string $style, $is_view = 0): ?bool
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

            // Indexes and foreign keys don't apply to views.
            if (!$is_view) {
                $this->dumpIndexes($table);
                $this->dumpForeignKeys($table);
            }
        }

        return true;
    }

    private function dumpIndexes(string $table): void
    {
        $indexes = Adminer\indexes($table);
        if (empty($indexes)) {
            return;
        }

        $rows = [];
        $width = ['Type' => 4, 'Columns' => 7];

        foreach ($indexes as $index) {
            $columns = [];
            foreach ($index['columns'] as $i => $col) {
                $part = $col;
                if (!empty($index['lengths'][$i])) {
                    $part .= '(' . $index['lengths'][$i] . ')';
                }
                if (!empty($index['descs'][$i])) {
                    $part .= ' DESC';
                }
                $columns[] = $part;
            }

            $newRow = [
                'Type' => $this->processValue($index['type']),
                'Columns' => $this->processValue(implode(', ', $columns)),
            ];
            $rows[] = $newRow;
            foreach ($newRow as $k => $v) {
                $width[$k] = max($width[$k], $this->getStringLength($v));
            }
        }

        echo "### indexes\n\n";
        echo $this->markdownTable($rows, $width, ['Type' => 'left', 'Columns' => 'left']);
        echo "\n";
    }

    private function dumpForeignKeys(string $table): void
    {
        $foreignKeys = Adminer\foreign_keys($table);
        if (empty($foreignKeys)) {
            return;
        }

        $rows = [];
        $width = ['Columns' => 7, 'References' => 10, 'On Delete' => 9, 'On Update' => 9];

        foreach ($foreignKeys as $fk) {
            $targetColumns = [];
            foreach ($fk['target'] as $i => $col) {
                // A null target element conventionally means "same column
                // name as the source side" in Adminer's ForeignKey shape.
                $targetColumns[] = $col !== null ? $col : ($fk['source'][$i] ?? '');
            }
            $references = $fk['table'] . '(' . implode(', ', $targetColumns) . ')';

            $newRow = [
                'Columns' => $this->processValue(implode(', ', $fk['source'])),
                'References' => $this->processValue($references),
                'On Delete' => $this->processValue($fk['on_delete'] ?? ''),
                'On Update' => $this->processValue($fk['on_update'] ?? ''),
            ];
            $rows[] = $newRow;
            foreach ($newRow as $k => $v) {
                $width[$k] = max($width[$k], $this->getStringLength($v));
            }
        }

        echo "### foreign keys\n\n";
        echo $this->markdownTable($rows, $width, ['Columns' => 'left', 'References' => 'left', 'On Delete' => 'left', 'On Update' => 'left']);
        echo "\n";
    }

    public function dumpData(
        string $table,
        string $style,
        string $query,
        array $select = [],
        array $where = [],
        array $group = [],
        array $order = []
    ): ?bool
    {
        if (($_POST['format'] ?? null) !== $this->type) {
            return null;
        }

        echo "### Table Data\n\n";

        if (empty($this->fields) && $table !== '') {
            foreach (Adminer\fields($table) as $f) {
                $this->fields[$f['field']] = $f;
            }
        }

        $connection = Adminer\connection();

        // Adminer <= 5.x supplies the complete query. Adminer 6.0+ passes an
        // empty query and asks the driver to build/execute it from these parts.
        if ($query !== '') {
            $result = $connection->query($query, 1);
        } else {
            $result = Adminer\driver()->select(
                $table,
                $select ?: ['*'],
                $where,
                $group,
                $order,
                0
            );
        }

        if (!$result) {
            echo '> ERROR: query failed' . ($connection->error ? ': ' . $this->escapeMarkdown($connection->error) : '.') . "\n\n";
            return false;
        }

        $rn = 0;
        $sampleRows = [];
        $columnWidth = [];
        $aligns = [];

        // fetch_assoc() silently collapses columns that share a name (e.g.
        // "id" appearing on both sides of a join) - only the last one
        // survives. To keep both, read the field list from the result
        // metadata (which still has duplicates) and pull values positionally
        // with fetch_row() instead, de-duplicating names ourselves.
        //
        // Different result objects expose this metadata differently:
        // - mysqli_result has fetch_fields() (all columns at once, safe to
        //   use directly).
        // - Adminer 6's own Result wrappers (adminer/drivers/*.inc.php)
        //   only implement the singular fetch_field(): \stdClass, and on
        //   at least the pgsql driver it has a non-nullable return type and
        //   ALWAYS returns an object - including past the last column,
        //   where pg_field_name()/pg_field_type() just fail silently
        //   (emitting warnings) rather than making it return false.
        //   Looping "while ($field = $result->fetch_field())" therefore
        //   never terminates on that driver. Instead, fetch the first row
        //   via fetch_row() to get a reliable column count from count(),
        //   and call fetch_field() exactly that many times.
        $this->columnSourceMap = [];
        $fieldNames = null;
        $firstRow = null;
        if (method_exists($result, 'fetch_fields')) {
            $fieldNames = array_map(static function ($field) {
                return $field->name;
            }, $result->fetch_fields());
        } elseif (method_exists($result, 'fetch_field') && method_exists($result, 'fetch_row')) {
            $firstRow = $result->fetch_row();
            if ($firstRow !== null && $firstRow !== false) {
                $fieldNames = [];
                for ($i = 0, $count = count($firstRow); $i < $count; $i++) {
                    $fieldNames[] = $result->fetch_field()->name;
                }
            }
        }

        $uniqueNames = null;
        if ($fieldNames !== null && method_exists($result, 'fetch_row')) {
            $uniqueNames = [];
            $counts = [];
            foreach ($fieldNames as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $unique = ($counts[$name] > 1) ? $name . '_' . $counts[$name] : $name;
                $uniqueNames[] = $unique;
                $this->columnSourceMap[$unique] = $name;
            }
        }

        // If we already consumed the first row above (to count its
        // columns), feed it back through the loop below before pulling any
        // further rows from the result.
        $pendingRow = $firstRow;

        while (($rawRow = ($pendingRow !== null ? $pendingRow : ($uniqueNames !== null ? $result->fetch_row() : $result->fetch_assoc()))) !== null && $rawRow !== false) {
            $pendingRow = null;
            $row = [];
            if ($uniqueNames !== null) {
                foreach ($rawRow as $i => $v) {
                    $row[$uniqueNames[$i]] = $this->processValue($v);
                }
            } else {
                // Fallback for result objects that don't expose
                // fetch_fields()/fetch_row() (best effort - duplicate
                // column names will still collapse in this path).
                foreach ($rawRow as $k => $v) {
                    $row[$k] = $this->processValue($v);
                }
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

        if ($rn <= $this->rowSampleLimit) {
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
     * Thin wrapper around header().
     */
    protected function sendHeader(string $header): void
    {
        header($header);
    }
}
