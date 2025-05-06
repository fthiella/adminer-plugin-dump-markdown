<?php

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v0.9 (March 12th, 2025)
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

    function __construct($config = []) {
        $this->rowSampleLimit = $config['rowSampleLimit'] ?? 100;
        $this->nullValue = $config['nullValue'] ?? "N/D";
        $this->specialChars = $config['specialChars'] ?? '\\*_[](){}+-#.!|';
        $this->markdown_chr = $config['markdown_chr'] ?? ['space'  => ' ', 'table'  => '|', 'header' => '-'];
        $this->disableUTF8 = $config['disableUTF8'] ?? False;

        if (extension_loaded('mbstring')) {
            $this->mbStrAvailable = true;
        } else {
            $this->mbStrAvailable = false;
            // also set disableUTF8 to true?
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
        return $this->_escape_markdown($value);
    }

    function _format_value($s, $l, $c) {
        return ($this->_getStringLength($s) > $l) ? $this->_getSubString($s, 0, $l) : $s.str_repeat($c, $l-$this->_getStringLength($s));
    }

    function _map($array, $width, $c) {
        foreach ($array as $k => &$v) $v = $this->_format_value($v, $width[$k], $c);
        return $array;
    }

    function _map_header($array) {
        foreach ($array as $k => &$v) $v = $this->_process_value($k);
        return $array;
    }

    function _map_mtable($array) {
        foreach ($array as $k => &$v) $v = $this->markdown_chr['header'];
        return $array;
    }

    function _markdown_row($row, $column_width, $separator, $filler) {
        return implode($separator, $this->_map($row, $column_width, $filler));
    }

    function _markdown_table($rows, $column_width) {
        $content  = $this->_markdown_row($this->_map_header($rows[0]), $column_width, $this->markdown_chr['space'] . $this->markdown_chr['table'] . $this->markdown_chr['space'], $this->markdown_chr['space']) . "\n";
        $content .= $this->_markdown_row($this->_map_mtable($rows[0]), $column_width, $this->markdown_chr['header'] . $this->markdown_chr['table'] . $this->markdown_chr['header'], $this->markdown_chr['header']) . "\n";
        foreach ($rows as $row) {
            $content .= $this->_markdown_row($row, $column_width, $this->markdown_chr['space'] . $this->markdown_chr['table'] . $this->markdown_chr['space'], $this->markdown_chr['space']) . "\n";
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
        if ($_POST["format"] == $this->format) {
            echo '# ' . $this->_escape_markdown($db) . "\n\n";
            return true;
        }
    }

    /* export table structure */
    function dumpTable($table, $style, $is_view = false) {
        if ($_POST["format"] == $this->type) {
            echo '## ' . $this->_escape_markdown($table) . "\n\n";

            if ($style) {
                echo "### table structure\n\n";

                $field_rows = array();
                $field_width = (['Column name' => 11, 'Type' => 4, 'Comment' => 7, 'Null' => 4, 'AI' => 2]);

                foreach (Adminer\fields($table) as $field) {
                    $new_row = [
                        'Column name' => $this->_process_value($field['field']),
                        'Type' => $field['full_type'],
                        'Comment' => $field['comment'],
                        'Null' => $this->_bool($field['null']),
                        'AI' => $this->_bool($field['auto_increment'])
                    ];
                    array_push($field_rows, $new_row);
                    foreach ($new_row as $key => $val) {
                        $field_width[$key] = max($field_width[$key], $this->_getStringLength($new_row[$key]));
                    }
                }
                echo $this->_markdown_table($field_rows, $field_width);
                echo "\n";
            }
            return true;
        }
    }

    /* export table data */
    function dumpData($table, $style, $query) {
        if ($_POST["format"] == $this->type) {
            echo "### Table Data\n\n";

            $connection = Adminer\connection();

            $result = $connection->query($query, 1);
            if ($result) {
                $rn = 0;
                $sample_rows = array();
                $column_width = array();

                while ($raw_row = $result->fetch_assoc()) {
                    // process row for output
                    $row = [];
                    foreach($raw_row as $key => $value) {
                        $row[$key] = $this->_process_value($value);
                    }
                    // end process row
                    switch(true) {
                        case $rn==0:
                            foreach ($row as $key => $val) {
                                $column_width[$key] = $this->_getStringLength($this->_process_value($key)); // escape here?
                            }
                        case $rn<$this->rowSampleLimit:
                            $sample_rows[$rn]=$row;
                            foreach ($row as $key => $val) {
                                $column_width[$key] = max($column_width[$key], $this->_getStringLength($row[$key]));
                            }
                            break;
                        case $rn==$this->rowSampleLimit:
                            echo $this->_markdown_table($sample_rows, $column_width);
                        default:
                            echo $this->_markdown_row($row, $column_width, $this->markdown_chr['space'] . $this->markdown_chr['table'] . $this->markdown_chr['space'], $this->markdown_chr['space']) . "\n";
                    }
                    $rn++;
                }
                if ($rn<=$this->rowSampleLimit) {
                    echo $this->_markdown_table($sample_rows, $column_width);
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
