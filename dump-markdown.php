<?php

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v0.6 (October 13th, 2020)
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

	function _format_value($s, $l, $c) {
		return (strlen($s) > $l) ? substr($s, 0, $l) : str_pad($s, $l, $c);
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
		foreach ($array as $k => &$v) $v = '-';
		return $array;
	}

	function _echo_markdown_rows($rows, $column_width) {
		echo implode(" | ", $this->_map($this->_map_header($rows[0]), $column_width, " ")) . "\r\n";
		echo implode("-|-", $this->_map($this->_map_mtable($rows[0]), $column_width, "-")) . "\r\n";
		foreach ($rows as $sample_row) {
			echo implode(" | ", $this->_map($sample_row, $column_width, " ")) . "\r\n";
		}
	}

	function _bool($value) {
        return $value == 1 ? 'Yes' : 'No';
    }

	function dumpFormat() {
		return array($this->type => $this->format);
	}

	function dumpDatabase($db) {
		if ($_POST["format"] == $this->type) {
			echo '# ' . $db . "\r\n\r\n";
			return true;
		}
	}

	/* export table structure */
	function dumpTable($table, $style, $is_view = false) {
		if ($_POST["format"] == $this->type) {
			echo '## ' . addcslashes($table, "\r\n\"\\") . "\r\n\r\n";

			if ($style) {
				$status = table_status1($table);

				$field_rows = array();
				$field_width = (['Column name' => 11, 'Type' => 4, 'Comment' => 7, 'Primary' => 4, 'Null' => 4, 'AI' => 2]);

				foreach (fields($table) as $field) {
					$new_row = [
						'Column name' => $field['field'],
						'Type' => $field['full_type'],
						'Comment' => $field['comment'],
						'Primary' => $this->_bool($field['primary']),
						'Null' => $this->_bool($field['null']),
						'AI' => $this->_bool($field['auto_increment'])
					];
					array_push($field_rows, $new_row);
					foreach ($new_row as $key => $val) {
						$field_width[$key] = max($field_width[$key], strlen($new_row[$key]));
					}
				}
	            $this->_echo_markdown_rows($field_rows, $field_width);
	            echo "\r\n";
	        }

			return true;
		}
	}

	/* export table data */
	function dumpData($table, $style, $query) {
		if ($_POST["format"] == $this->type) {

			$connection = connection();
			$result = $connection->query($query, 1);
			if ($result) {
				$rn = 0;
				$sample_rows = array();
				$column_width = array();

				while ($row = $result->fetch_assoc()) {
					switch(true) {
						case $rn==0:
							foreach ($row as $key => $val) {
								$column_width[$key] = strlen($key);
							}
						case $rn<100:
							$sample_rows[$rn]=$row;
							foreach ($row as $key => $val) {
								$column_width[$key] = max($column_width[$key], strlen($row[$key]));
							}
							break;
						case $rn==100:
							$this->_echo_markdown_rows($sample_rows, $column_width);
						default:
							echo implode(" | ", $this->_map($row, $column_width, " ")) . "\r\n";
					}
					$rn++;
				}
				if ($rn<100) {
					$this->_echo_markdown_rows($sample_rows, $column_width);
				}
				echo "\r\n";
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
