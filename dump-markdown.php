<?php

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v0.8 (March 11th, 2025)
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

	const markdown_chr = [
		'space' => ' ',
		'table' => '|',
		'header' => '-'
	];

	const rowSampleLimit = 100;
	const nullValue = "N/D";

    const specialChars = '\\*_[](){}+-#.!|';

	function _getAdminerConnection() {
		if (function_exists('Adminer\connection')) {
			return Adminer\connection();
        } elseif (function_exists('connection')) {
            return connection();
        }
    }

	function _getAdminerFields($table) {
		if (function_exists('Adminer\fields')) {
			return Adminer\fields($table);
        } elseif (function_exists('fields')) {
            return fields($table);
        }
    }

    function _escape_markdown($value) {
    	$special_chars = '\\*_[](){}+-#.!|';
    	$escaped_value = "";

    	// I am still undecided how to handle utf8 data. String lenght is not always calculated correctly
    	$value = strval($value);
    	// $value = utf8_decode($value);

        for ($i = 0; $i < strlen($value); $i++) {
            $char = $value[$i];
            if (strpos(self::specialChars, $char) !== false) {
            	$escaped_value .= '\\' . $char;
            } else {
                $escaped_value .= $char;
            }
        }
        return $escaped_value;
    }

    function _process_value($value) {
    	if($value === null) {
    		return self::nullValue;
    	}
    	return $this->_escape_markdown($value);
    }

	function _format_value($s, $l, $c) {
		return (strlen($s) > $l) ? substr($s, 0, $l) : $s.str_repeat($c, $l-strlen($s));
	}

	function _map($array, $width, $c) {
		foreach ($array as $k => &$v) $v = $this->_format_value($v, $width[$k], $c);
		return $array;
	}

	function _map_header($array) {
		foreach ($array as $k => &$v) $v = $k;
		return $array;
	}

	function _map_mtable($array) {
		foreach ($array as $k => &$v) $v = self::markdown_chr['header'];
		return $array;
	}

	function _markdown_row($row, $column_width, $separator, $filler) {
		return implode($separator, $this->_map($row, $column_width, $filler));
	}

	function _markdown_table($rows, $column_width) {
		$content  = $this->_markdown_row($this->_map_header($rows[0]), $column_width, self::markdown_chr['space'] . self::markdown_chr['table'] . self::markdown_chr['space'], self::markdown_chr['space']) . "\n";
		$content .= $this->_markdown_row($this->_map_mtable($rows[0]), $column_width, self::markdown_chr['header'] . self::markdown_chr['table'] . self::markdown_chr['header'], self::markdown_chr['header']) . "\n";
		foreach ($rows as $row) {
			$content .= $this->_markdown_row($row, $column_width, self::markdown_chr['space'] . self::markdown_chr['table'] . self::markdown_chr['space'], self::markdown_chr['space']) . "\n";
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
			echo '# ' . $db . "\n\n";
			return true;
		}
	}

//	https://github.com/ToX82/adminer-db-structure-plugin/blob/main/db-structure.php

	/* export table structure */
	function dumpTable($table, $style, $is_view = false) {
		if ($_POST["format"] == $this->type) {
			echo '## ' . addcslashes($table, "\n\"\\") . "\n\n";

			if ($style) {
				echo "### table structure\n\n";

				$field_rows = array();
				$field_width = (['Column name' => 11, 'Type' => 4, 'Comment' => 7, 'Null' => 4, 'AI' => 2]);

				foreach ($this->_getAdminerFields($table) as $field) {
					$new_row = [
						'Column name' => $field['field'],
						'Type' => $field['full_type'],
						'Comment' => $field['comment'],
						'Null' => $this->_bool($field['null']),
						'AI' => $this->_bool($field['auto_increment'])
					];
					array_push($field_rows, $new_row);
					foreach ($new_row as $key => $val) {
						$field_width[$key] = max($field_width[$key], strlen(utf8_decode($new_row[$key]))); // to be fixed
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

			echo "### table data\n\n";

			$connection = $this->_getAdminerConnection();

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
								$column_width[$key] = strlen($this->_process_value($key));
							}
						case $rn<self::rowSampleLimit:
							$sample_rows[$rn]=$row;
							foreach ($row as $key => $val) {
								$column_width[$key] = max($column_width[$key], strlen($row[$key]));
							}
							break;
						case $rn==self::rowSampleLimit:
							echo $this->_markdown_table($sample_rows, $column_width);
							break;
						default:
							echo $this->_markdown_row($row, $column_width, self::markdown_chr['space'] . self::markdown_chr['table'] . self::markdown_chr['space'], self::markdown_chr['space']) . "\n";
					}
					$rn++;
				}
				if ($rn<=self::rowSampleLimit) {
					echo $this->_markdown_table($sample_rows, $column_width);
				}
				echo "\n";
			}
			return true;
		}
	}

	function dumpHeaders($identifier, $multi_table = false) {
		if ($_POST["format"] == $this->type) {
			header("Content-Type: text/text; charset=utf-8");
			return "md";
		}
	}
}
?>