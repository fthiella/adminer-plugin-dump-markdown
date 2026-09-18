<?php

declare(strict_types=1);

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v1.5 (September 18th, 2026)
 *
 * @link https://github.com/fthiella/adminer-plugin-dump-markdown
 * @author Federico Thiella, https://fthiella.github.io/
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 */

if (class_exists('AdminerDumpMarkdown', false)) {
    return;
}

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
    /** @var bool */
    private $compact;

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

    /** @var bool Whether POST export-form options have already been applied this request. */
    private $optionsApplied = false;

    /** @var array<string,int> Counter for duplicate slugs, to ensure unique anchor IDs in the table of contents. */
    private $generatedSlugs = [];

    /** @var string|null */
    private $fieldsTable = null;

    /**
     * @param array<string,mixed> $config {
     *     @type int    $rowSampleLimit Number of rows buffered to compute column widths before streaming. Default 100. Ignored when $compact is true.
     *     @type string $nullValue      Placeholder text for NULL values. Default "N/D".
     *     @type string $specialChars   Characters escaped in Markdown output.
     *     @type array  $markdown_chr   Overrides for the space/table/header characters.
     *     @type bool   $disableUTF8    Disable multibyte-safe string handling.
     *     @type bool   $tableAlign     Enable column-alignment markers in the separator row.
     *     @type bool   $tablePipes     Wrap rows in leading/trailing pipes.
     *     @type bool   $compact        Skip cell padding; still emit left/center/right markers if $tableAlign is on.
     *     @type array  $columnAlign    Per-column alignment overrides.
     *     @type array  $typeAlign      Default alignment per data type ('number', 'bool', 'default').
     *     @type string $truncationMarker Appended to values truncated to fit a column's width. Ignored when $compact is true.
     * }
     */
    public function __construct(array $config = [])
    {
        $this->rowSampleLimit = max(1, (int) ($config['rowSampleLimit'] ?? 100));
        $this->nullValue = (string) ($config['nullValue'] ?? 'N/D');
        $this->specialChars = (string) ($config['specialChars'] ?? '\\*|');
        $this->disableUTF8 = (bool) ($config['disableUTF8'] ?? false);
        $this->truncationMarker = (string) ($config['truncationMarker'] ?? '');
        $this->tableAlign = (bool) ($config['tableAlign'] ?? false);
        $this->tablePipes = (bool) ($config['tablePipes'] ?? false);
        $this->compact = (bool) ($config['compact'] ?? false);

        $defaultChr = ['space' => ' ', 'table' => '|', 'header' => '-'];
        $passedChr = is_array($config['markdown_chr'] ?? null) ? $config['markdown_chr'] : [];
        $this->markdownChr = array_merge($defaultChr, array_intersect_key($passedChr, $defaultChr));

        $this->columnAlign = is_array($config['columnAlign'] ?? null) ? $config['columnAlign'] : [];

        $defaultTypeAlign = ['number' => 'right', 'bool' => 'center', 'default' => 'left'];
        $passedTypeAlign = is_array($config['typeAlign'] ?? null) ? $config['typeAlign'] : [];
        $this->typeAlign = array_merge($defaultTypeAlign, array_intersect_key($passedTypeAlign, $defaultTypeAlign));

        $this->mbStrAvailable = extension_loaded('mbstring');

        if (!$this->mbStrAvailable && !$this->disableUTF8) {
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
        if (!function_exists('iconv')) {
            return $value;
        }
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
        if ($this->specialChars === '') {
            return strval($value);
        }

        $escaped = preg_quote($this->specialChars, '/');
        $modifier = $this->disableUTF8 ? '' : 'u';

        $result = preg_replace('/([' . $escaped . '])/' . $modifier, '\\\$1', $value);

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
        if (!$this->tableAlign) {
            return 'left';
        }

        if (isset($this->columnAlign[$colName])) {
            return $this->columnAlign[$colName];
        }

        $sourceField = $this->columnSourceMap[$colName] ?? $colName;

        if (isset($this->fields[$sourceField])) {
            $type = strtolower($this->fields[$sourceField]['type']);
            if ($type === 'boolean' || ($type === 'tinyint' && strpos($this->fields[$sourceField]['full_type'], '(1)') !== false)) {
                return $this->typeAlign['bool'];
            }
            if (preg_match('/\b(int|integer|tinyint|smallint|mediumint|bigint|float|double|decimal|dec|numeric|real|bit)\b/i', $type)) {
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
        if ($this->compact) {
            $inner = implode($separator, array_map('strval', array_values($row)));
            $t = $this->markdownChr['table'];
            return $this->tablePipes ? $t . $filler . $inner . $filler . $t : $inner;
        }

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
     * @param array<int|string,mixed> $keys
     * @param array<string,string> $aligns
     */
    private function compactSeparator($keys, array $aligns): string
    {
        $h = $this->markdownChr['header'];
        $t = $this->markdownChr['table'];
        $s = $this->markdownChr['space'];
        $parts = [];
        foreach ($keys as $k) {
            $mode = $aligns[$k] ?? 'left';
            if (!$this->tableAlign) {
                $parts[] = str_repeat($h, 3);
            } elseif ($mode === 'center') {
                $parts[] = ':' . $h . ':';
            } elseif ($mode === 'right') {
                $parts[] = str_repeat($h, 2) . ':';
            } else {
                $parts[] = ':' . str_repeat($h, 2);
            }
        }
        $inner = implode($s . $t . $s, $parts);
        return $this->tablePipes ? $t . $s . $inner . $s . $t : $inner;
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

        if ($this->compact) {
            $t = $this->markdownChr['table'];
            $s = $this->markdownChr['space'];
            $sep = $s . $t . $s;
            $out = $this->markdownRow($this->mapHeader($rows[0]), $columnWidth, $aligns, $sep, $s) . "\n";
            $out .= $this->compactSeparator(array_keys($rows[0]), $aligns) . "\n";
            foreach ($rows as $row) {
                $out .= $this->markdownRow($row, $columnWidth, $aligns, $sep, $s) . "\n";
            }
            return $out;
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
                $extra++;
            }
            if ($i < $lastIndex) {
                $extra++;
            }
            if ($this->tablePipes && $i === 0) {
                $extra++;
            }
            if ($this->tablePipes && $i === $lastIndex) {
                $extra++;
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
        return ($value === 1 || $value === '1' || $value === true) ? 'Yes' : 'No';
    }

    private function loadFields(string $table): void
    {
        if ($table === '') {
            $this->fields = [];
            $this->fieldsTable = null;
            return;
        }

        if ($this->fieldsTable !== $table) {
            $this->fields = [];
            foreach (Adminer\fields($table) as $f) {
                $this->fields[$f['field']] = $f;
            }
            $this->fieldsTable = $table;
        }
    }

    /**
     * @return array<string,string>
     */
    public function dumpFormat(): array
    {
        return [$this->type => $this->format];
    }

    /**
     * Extra fields on Adminer's Export form. Shown only when Format = Markdown.
     */
    public function dumpPrint(): void
    {
        $opts = $this->uiOptionValues();

        echo "<table id='dump-markdown-options'>\n";
        echo "<tr><th>Markdown<td>";

        echo "<label>"
            . "<input type='hidden' name='markdown[tablePipes]' value='0'>"
            . "<input type='checkbox' name='markdown[tablePipes]' value='1'"
            . ($opts['tablePipes'] ? ' checked' : '')
            . "> Wrap tables with |</label><br>\n";

        echo "<label>"
            . "<input type='hidden' name='markdown[tableAlign]' value='0'>"
            . "<input type='checkbox' name='markdown[tableAlign]' value='1'"
            . ($opts['tableAlign'] ? ' checked' : '')
            . "> Align numbers / booleans</label><br>\n";

        echo "<label>"
            . "<input type='hidden' name='markdown[compact]' value='0'>"
            . "<input type='checkbox' name='markdown[compact]' value='1'"
            . ($opts['compact'] ? ' checked' : '')
            . "> Compact (no padding)</label><br>\n";

        echo "NULL <input name='markdown[nullValue]' value='"
            . $this->htmlEscape($opts['nullValue'])
            . "' size='8'>\n";

        echo " Sample rows <input type='number' name='markdown[rowSampleLimit]' min='1' max='100000' value='"
            . (int) $opts['rowSampleLimit']
            . "' style='width: 5em;'>\n";

        echo " Truncate with <input name='markdown[truncationMarker]' value='"
            . $this->htmlEscape($opts['truncationMarker'])
            . "' size='4' placeholder='…'>\n";

        echo "</table>\n";

        $js = '(function(){'
            . 'var box=document.getElementById("dump-markdown-options");'
            . 'if(!box)return;'
            . 'function selected(){'
            . 'var r=document.querySelectorAll("input[name=format]");'
            . 'for(var i=0;i<r.length;i++){if(r[i].checked)return r[i].value;}'
            . 'var s=document.querySelector("select[name=format]");'
            . 'return s?s.value:"";'
            . '}'
            . 'function sync(){box.style.display=selected()==="markdown"?"":"none";}'
            . 'var r=document.querySelectorAll("input[name=format]");'
            . 'for(var i=0;i<r.length;i++)r[i].addEventListener("change",sync);'
            . 'var s=document.querySelector("select[name=format]");'
            . 'if(s)s.addEventListener("change",sync);'
            . 'sync();'
            . '})();';

        if (function_exists('Adminer\\script')) {
            echo Adminer\script($js);
        } else {
            $nonce = function_exists('Adminer\\nonce') ? Adminer\nonce() : '';
            echo "<script$nonce>$js</script>\n";
        }
    }

    private function applyExportOptions(): void
    {
        if ($this->optionsApplied) {
            return;
        }
        $this->optionsApplied = true;

        $posted = $_POST['markdown'] ?? null;
        if (!is_array($posted)) {
            return;
        }

        if (array_key_exists('tablePipes', $posted)) {
            $this->tablePipes = $this->toBool($posted['tablePipes']);
        }
        if (array_key_exists('tableAlign', $posted)) {
            $this->tableAlign = $this->toBool($posted['tableAlign']);
        }
        if (array_key_exists('compact', $posted)) {
            $this->compact = $this->toBool($posted['compact']);
        }
        if (array_key_exists('nullValue', $posted)) {
            $this->nullValue = (string) $posted['nullValue'];
        }
        if (array_key_exists('rowSampleLimit', $posted)) {
            $n = (int) $posted['rowSampleLimit'];
            $this->rowSampleLimit = max(1, min(100000, $n));
        }
        if (array_key_exists('truncationMarker', $posted)) {
            $this->truncationMarker = (string) $posted['truncationMarker'];
        }

        $this->persistExportOptions();
    }

    /**
     * @return array{tablePipes:bool,tableAlign:bool,compact:bool,nullValue:string,rowSampleLimit:int,truncationMarker:string}
     */
    private function uiOptionValues(): array
    {
        return [
            'tablePipes' => $this->cookieBool('tablePipes', $this->tablePipes),
            'tableAlign' => $this->cookieBool('tableAlign', $this->tableAlign),
            'compact' => $this->cookieBool('compact', $this->compact),
            'nullValue' => $this->cookieString('nullValue', $this->nullValue),
            'rowSampleLimit' => $this->cookieInt('rowSampleLimit', $this->rowSampleLimit),
            'truncationMarker' => $this->cookieString('truncationMarker', $this->truncationMarker),
        ];
    }

    private function persistExportOptions(): void
    {
        if (!function_exists('Adminer\\save_settings')) {
            return;
        }
        Adminer\save_settings([
            'tablePipes' => $this->tablePipes ? '1' : '0',
            'tableAlign' => $this->tableAlign ? '1' : '0',
            'compact' => $this->compact ? '1' : '0',
            'nullValue' => $this->nullValue,
            'rowSampleLimit' => (string) $this->rowSampleLimit,
            'truncationMarker' => $this->truncationMarker,
        ], 'adminer_markdown');
    }

    private function cookieBool(string $key, bool $fallback): bool
    {
        $v = $this->cookieRaw($key);
        return $v === null ? $fallback : $this->toBool($v);
    }

    private function cookieString(string $key, string $fallback): string
    {
        $v = $this->cookieRaw($key);
        return $v === null ? $fallback : $v;
    }

    private function cookieInt(string $key, int $fallback): int
    {
        $v = $this->cookieRaw($key);
        if ($v === null || $v === '') {
            return $fallback;
        }
        return max(1, min(100000, (int) $v));
    }

    private function cookieRaw(string $key): ?string
    {
        if (!function_exists('Adminer\\get_setting')) {
            return null;
        }
        $v = Adminer\get_setting($key, 'adminer_markdown', null);
        return $v === null ? null : (string) $v;
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        return $value === '1' || $value === 'true' || $value === 'on' || $value === 'yes';
    }

    private function htmlEscape(string $value): string
    {
        if (function_exists('Adminer\\h')) {
            return Adminer\h($value);
        }
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function dumpDatabase(string $db): ?bool
    {
        if (($_POST['format'] ?? null) !== $this->type) {
            return null;
        }
        $this->applyExportOptions();
        $this->generatedSlugs = [];

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
        $lower = (!$this->disableUTF8 && $this->mbStrAvailable)
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);

        // Gestione fallback se preg_replace ritorna null su sequenze UTF-8 invalide
        $replaced = preg_replace('/[^\p{L}\p{N}\-_ ]+/u', '', $lower);
        $slug = (string) ($replaced !== null ? $replaced : preg_replace('/[^a-zA-Z0-9\-_ ]+/', '', $lower));
        $slug = str_replace(' ', '-', trim($slug));
        
        if ($slug === '') {
            $slug = 'table';
        }

        if (isset($this->generatedSlugs[$slug])) {
            $this->generatedSlugs[$slug]++;
            return $slug . '-' . $this->generatedSlugs[$slug];
        }

        $this->generatedSlugs[$slug] = 1;
        return $slug;
    }

    public function dumpTable(string $table, string $style, $is_view = 0): ?bool
    {
        if (($_POST['format'] ?? null) !== $this->type) {
            return null;
        }
        $this->applyExportOptions();

        echo '## ' . $this->escapeMarkdown($table) . "\n\n";

        $this->loadFields($table);

        if ($style) {
            echo "### table structure\n\n";
            $fieldRows = [];
            $fieldWidth = ['Column name' => 11, 'Type' => 4, 'Comment' => 7, 'Null' => 4, 'AI' => 2];

            foreach ($this->fields as $f) {
                $newRow = [
                    'Column name' => $this->processValue($f['field']),
                    'Type' => $this->processValue($f['full_type']),
                    'Comment' => ($f['comment'] ?? '') !== '' ? $this->processValue($f['comment']) : '',
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
        $this->applyExportOptions();

        echo "### Table Data\n\n";

        $this->loadFields($table);

        $connection = Adminer\connection();

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
            $counts = array_count_values($fieldNames);
            $assigned = array_fill_keys($fieldNames, true);
            $seen = [];

            foreach ($fieldNames as $name) {
                if ($counts[$name] === 1) {
                    $uniqueNames[] = $name;
                    $this->columnSourceMap[$name] = $name;
                    continue;
                }

                if (!isset($seen[$name])) {
                    $seen[$name] = 1;
                    $uniqueNames[] = $name;
                    $this->columnSourceMap[$name] = $name;
                } else {
                    $i = $seen[$name] + 1;
                    $candidate = $name . '_' . $i;
                    while (isset($assigned[$candidate])) {
                        $i++;
                        $candidate = $name . '_' . $i;
                    }
                    $assigned[$candidate] = true;
                    $seen[$name] = $i;
                    $uniqueNames[] = $candidate;
                    $this->columnSourceMap[$candidate] = $name;
                }
            }
        }

        $pendingRow = $firstRow;
        $sep = $this->markdownChr['space'] . $this->markdownChr['table'] . $this->markdownChr['space'];
        $fill = $this->markdownChr['space'];

        while (($rawRow = ($pendingRow !== null ? $pendingRow : ($uniqueNames !== null ? $result->fetch_row() : $result->fetch_assoc()))) !== null && $rawRow !== false) {
            $pendingRow = null;
            $row = [];
            if ($uniqueNames !== null) {
                foreach ($rawRow as $i => $v) {
                    $row[$uniqueNames[$i]] = $this->processValue($v);
                }
            } else {
                foreach ($rawRow as $k => $v) {
                    $row[$k] = $this->processValue($v);
                }
            }

            if ($rn === 0) {
                foreach ($row as $k => $v) {
                    $aligns[$k] = $this->getAlign($k, $v);
                    if (!$this->compact) {
                        $columnWidth[$k] = $this->getStringLength($this->processValue($k));
                    }
                }
            }

            if ($this->compact) {
                if ($rn === 0) {
                    echo $this->markdownRow($this->mapHeader($row), $columnWidth, $aligns, $sep, $fill) . "\n";
                    echo $this->compactSeparator(array_keys($row), $aligns) . "\n";
                }
                echo $this->markdownRow($row, $columnWidth, $aligns, $sep, $fill) . "\n";
                $rn++;
                continue;
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
                echo $this->markdownRow($row, $columnWidth, $aligns, $sep, $fill) . "\n";
            }

            $rn++;
        }

        if (!$this->compact && $rn <= $this->rowSampleLimit) {
            echo $this->markdownTable($sampleRows, $columnWidth, $aligns);
        }

        if ($this->compact && $rn === 0) {
            echo "> No data found in table.\n";
        }

        echo "\n";
        return true;
    }

    public function dumpHeaders(string $identifier, bool $multi_table = false): ?string
    {
        if (($_POST['format'] ?? null) === $this->type) {
            $this->applyExportOptions();
            $charset = $this->disableUTF8 ? 'iso-8859-1' : 'utf-8';
            $this->sendHeader("Content-Type: text/markdown; charset={$charset}");
            return 'md';
        }
        return null;
    }

    protected function sendHeader(string $header): void
    {
        header($header);
    }
}
