<?php

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v1.1-dev (March 4th, 2026)
 *
 * @link https://github.com/fthiella/adminer-plugin-dump-markdown
 * @author Federico Thiella, https://fthiella.github.io/ 
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 *
 */

class AdminerDumpMarkdown {
    private $type = 'markdown';
    private $format = 'Markdown';

    private $markdown_chr;

    private $rowSampleLimit;
    private $nullValue;

    private $specialChars;
    private $disableUTF8;

    private $mbStrAvailable;
    
    private $tableAlign;
    private $tablePipes;
    private $columnAlign;
    private $typeAlign;
    private $fields = [];

    function __construct($config = []) {
        $this->rowSampleLimit = $config['rowSampleLimit'] ?? 100;
        $this->nullValue = $config['nullValue'] ?? "N/D";

        $this->specialChars = $config['specialChars'] ?? '\\*_[](){}+-#\!|'; 
        $this->markdown_chr = $config['markdown_chr'] ?? ['space' => ' ', 'table' => '|', 'header' => '-'];
        $this->disableUTF8 = $config['disableUTF8'] ?? false;

        $this->tableAlign = $config['tableAlign'] ?? false;
        $this->tablePipes = $config['tablePipes'] ?? false;
        $this->columnAlign = $config['columnAlign'] ?? [];

        $this->typeAlign = $config['typeAlign'] ?? [
            'number'  => 'right',
            'bool'    => 'center',
            'default' => 'left'
        ];

        if (extension_loaded('mbstring')) {
            $this->mbStrAvailable = true;
        } else {
            $this->mbStrAvailable = false;
        }

        if (!$this->mbStrAvailable && !$this->disableUTF8) {
            echo "> WARNING: The PHP 'mbstring' extension is NOT enabled. Consider enabling 'mbstring' for better UTF-8 support.\n\n";
        }
    }

    function _getStringLength($value) {
        if ($this->disableUTF8 || !$this->mbStrAvailable) {
            return strlen($value);
        }
        return mb_strlen($value, 'UTF-8');
    }

    function _getStrPos($special, $chr) {
        if ($this->disableUTF8 || !$this->mbStrAvailable) {
            return strpos($special, $chr);
        }
        return mb_strpos($special, $chr);
    }

    function _getSubString($value, $start, $length) {
        if ($this->disableUTF8 || !$this->mbStrAvailable) {
            return substr($value, $start, $length);
        }
        return mb_substr($value, $start, $length, 'UTF-8');
    }

    function _escape_markdown($value) {
        $escaped_value = "";

        $value = strval($value);
        if ($this->disableUTF8) {
            $value = utf8_decode($value);
        }

        for ($i = 0; $i < $this->_getStringLength($value); $i++) {
            $char = $this->_getSubString($value, $i, 1);
            if ($this->_getStrPos($this->specialChars, $char) !== false) {
                $escaped_value .= '\\' . $char;
            } else {
                $escaped_value .= $char;
            }
        }

        return $escaped_value;
    }

    function _process_value($value) {
        if($value === null) {
            return $this->nullValue;
        }
        $value = str_replace(["\r\n", "\r", "\n"], " ", strval($value));
        return $this->_escape_markdown($value);
    }

    function _format_value($s, $l, $c, $mode = 'left') {
        $len = $this->_getStringLength($s);
        if ($len > $l) return $this->_getSubString($s, 0, $l);
        
        if ($mode === 'right') return str_repeat($c, $l - $len) . $s;
        if ($mode === 'center') {
            $left = floor(($l - $len) / 2);
            return str_repeat($c, $left) . $s . str_repeat($c, $l - $len - $left);
        }
        return $s . str_repeat($c, $l - $len);
    }

    function _get_align($col_name, $value) {
        // Se l'allineamento delle tabelle è disattivato, forza tutto a sinistra
        if (!$this->tableAlign) return 'left'; 
        
        if (isset($this->columnAlign[$col_name])) return $this->columnAlign[$col_name];
        
        if (isset($this->fields[$col_name])) {
            $type = strtolower($this->fields[$col_name]['type']);
            if (preg_match('/int|float|double|decimal|numeric|real|bit/', $type)) return $this->typeAlign['number'];
            if ($type === 'boolean' || ($type === 'tinyint' && strpos($this->fields[$col_name]['full_type'], '(1)') !== false)) return $this->typeAlign['bool'];
        }
        
        if ($value === 'Yes' || $value === 'No') return $this->typeAlign['bool'];
        return $this->typeAlign['default']; // 'left'
    }

    function _markdown_row($row, $column_width, $aligns, $separator, $filler) {
        $padded = [];
        foreach ($row as $k => $v) {
            $mode = $aligns[$k] ?? 'left';
            $padded[$k] = $this->_format_value($v, $column_width[$k], $filler, $mode);
        }
        $out = implode($separator, $padded);
        $t = $this->markdown_chr['table'];
        return ($this->tablePipes) ? $t . $filler . $out . $filler . $t : $out;
    }

    function _map_header($row) {
        $header = [];
        foreach ($row as $k => $v) {
            $header[$k] = $this->_process_value($k);
        }
        return $header;
    }

    function _markdown_table($rows, $column_width, $aligns = []) {
        $t = $this->markdown_chr['table'];
        $h = $this->markdown_chr['header'];
        $s = $this->markdown_chr['space'];

        // 1. Header: uses column names instead of data
        $content = $this->_markdown_row($this->_map_header($rows[0]), $column_width, $aligns, $s . $t . $s, $s) . "\n";

        // 2. Separator Row: Perfect character-match with spaces
        $sep_parts = [];
        foreach ($column_width as $k => $w) {
            $mode = $aligns[$k] ?? 'left';
            
            // IF tableAlign is false, we only output dashes
            if (!$this->tableAlign) {
                $sep_parts[$k] = str_repeat($h, $w);
            } elseif ($mode === 'center') {
                $sep_parts[$k] = ':' . str_repeat($h, max(0, $w - 2)) . ':';
            } elseif ($mode === 'right') {
                $sep_parts[$k] = str_repeat($h, max(0, $w - 1)) . ':';
            } else {
                // LEFT: :---
                $sep_parts[$k] = ':' . str_repeat($h, max(0, $w - 1));
            }
        }
        
        // Join segments with " | " to match the data rows separator
        $sep_line = implode($s . $t . $s, $sep_parts);
        
        if ($this->tablePipes) {
            // Wrap with "| " and " |"
            $content .= $t . $s . $sep_line . $s . $t . "\n";
        } else {
            $content .= $sep_line . "\n";
        }

        // 3. Data Rows
        foreach ($rows as $row) {
            $content .= $this->_markdown_row($row, $column_width, $aligns, $s . $t . $s, $s) . "\n";
        }
        return $content;
    }

    function _bool($value) {
        return $value == 1 ? 'Yes' : 'No';
    }

    function dumpFormat() {
        return array($this->type => $this->format);
    }

    function dumpDatabase($db) {
        if ($_POST["format"] == $this->type) {
            echo '# ' . $this->_escape_markdown($db) . "\n\n";
            return true;
        }
    }

    function dumpTable($table, $style, $is_view = false) {
        if ($_POST["format"] == $this->type) {
            echo '## ' . $this->_escape_markdown($table) . "\n\n";
            $this->fields = [];
            foreach (Adminer\fields($table) as $f) $this->fields[$f['field']] = $f;
            if ($style) {
                echo "### table structure\n\n";
                $field_rows = []; $field_width = (['Column name' => 11, 'Type' => 4, 'Comment' => 7, 'Null' => 4, 'AI' => 2]);
                foreach ($this->fields as $f) {
                    $new_row = ['Column name' => $this->_process_value($f['field']), 'Type' => $f['full_type'], 'Comment' => $f['comment'], 'Null' => ($this->_bool($f['null'])), 'AI' => ($this->_bool($f['auto_increment']))];
                    $field_rows[] = $new_row;
                    foreach ($new_row as $k => $v) $field_width[$k] = max($field_width[$k], $this->_getStringLength($v));
                }
                $st_al = ['Column name' => 'left', 'Type' => 'left', 'Comment' => 'left', 'Null' => 'center', 'AI' => 'center'];
                echo $this->_markdown_table($field_rows, $field_width, $st_al);
                echo "\n";
            }
            return true;
        }
    }

    function dumpData($table, $style, $query) {
        if ($_POST["format"] == $this->type) {
            echo "### Table Data\n\n";
            if (empty($this->fields)) {
                foreach (Adminer\fields($table) as $f) $this->fields[$f['field']] = $f;
            }
            $connection = Adminer\connection();
            $result = $connection->query($query, 1);
            if ($result) {
                $rn = 0; $sample_rows = []; $column_width = []; $aligns = [];
                while ($raw_row = $result->fetch_assoc()) {
                    $row = [];
                    foreach($raw_row as $k => $v) $row[$k] = $this->_process_value($v);
                    if ($rn == 0) {
                        foreach ($row as $k => $v) {
                            $column_width[$k] = $this->_getStringLength($this->_process_value($k));
                            $aligns[$k] = $this->_get_align($k, $v);
                        }
                    }
                    if ($rn < $this->rowSampleLimit) {
                        $sample_rows[$rn] = $row;
                        foreach ($row as $k => $v) $column_width[$k] = max($column_width[$k], $this->_getStringLength($v));
                    }
                    if ($rn == $this->rowSampleLimit) {
                        echo $this->_markdown_table($sample_rows, $column_width, $aligns);
                    }
                    if ($rn >= $this->rowSampleLimit) {
                        echo $this->_markdown_row($row, $column_width, $aligns, $this->markdown_chr['space'] . $this->markdown_chr['table'] . $this->markdown_chr['space'], $this->markdown_chr['space']) . "\n";
                    }
                    $rn++;
                }
                if ($rn<=$this->rowSampleLimit) {
                    echo $this->_markdown_table($sample_rows, $column_width, $aligns);
                }
                echo "\n";
            }
            return true;
        }
    }

    function dumpHeaders($identifier, $multi_table = false) {
        if ($_POST["format"] == $this->type) {
            header("Content-Type: text/markdown; charset=utf-8");
            return "md";
        }
    }
}
