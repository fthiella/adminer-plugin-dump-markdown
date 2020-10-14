<?php

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v0.7 (October 14th, 2020)
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
		return (strlen(utf8_decode($s)) > $l) ? substr($s, 0, $l) : $s.str_repeat($c, $l-strlen(utf8_decode($s)));
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

	function _markdown_row($row, $column_width, $separator, $filler) {
		return implode($separator, $this->_map($row, $column_width, $filler));
	}

	function _markdown_table($rows, $column_width) {
		$content  = $this->_markdown_row($this->_map_header($rows[0]), $column_width, " | ", " ") . "\n";
		$content .= $this->_markdown_row($this->_map_mtable($rows[0]), $column_width, "-|-", "-") . "\n";
		foreach ($rows as $row) {
			$content .= $this->_markdown_row($row, $column_width, " | ", " ") . "\n";
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
			echo '# ' . $db . "\n\n";
			return true;
		}
	}

	/* export table structure */
	function dumpTable($table, $style, $is_view = false) {
		if ($_POST["format"] == $this->type) {
			echo '## ' . addcslashes($table, "\n\"\\") . "\n\n";

			if ($style) {
				echo "### table structure\n\n";

				$field_rows = array();
				$field_width = (['Column name' => 11, 'Type' => 4, 'Comment' => 7, 'Null' => 4, 'AI' => 2]);

				foreach (fields($table) as $field) {
					$new_row = [
						'Column name' => $field['field'],
						'Type' => $field['full_type'],
						'Comment' => $field['comment'],
						'Null' => $this->_bool($field['null']),
						'AI' => $this->_bool($field['auto_increment'])
					];
					array_push($field_rows, $new_row);
					foreach ($new_row as $key => $val) {
						$field_width[$key] = max($field_width[$key], strlen(utf8_decode($new_row[$key])));
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
								$column_width[$key] = strlen(utf8_decode($key));
							}
						case $rn<100:
							$sample_rows[$rn]=$row;
							foreach ($row as $key => $val) {
								$column_width[$key] = max($column_width[$key], strlen(utf8_decode($row[$key])));
							}
							break;
						case $rn==100:
							echo $this->_markdown_table($sample_rows, $column_width);
						default:
							echo $this->_markdown_row($row, $column_width, " | ", " ") . "\n";
					}
					$rn++;
				}
				if ($rn<100) {
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
