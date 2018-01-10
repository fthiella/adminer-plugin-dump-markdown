<?php

/** Dump to MARKDOWN format
* @link https://github.com/fthiella/adminer-plugin-dump-markdown
* @author Federico Thiella, https://fthiella.github.io/ 
* @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
* @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
*/
class AdminerDumpMarkdown {
	/** @access protected */

	function dumpFormat() {
		return array('markdown' => 'Markdown');
	}

	function dumpData($table, $style, $query) {
		function format_value($s, $l, $c) {
			return (strlen($s) > $l) ? substr($s, 0, $l) : str_pad($s, $l, $c);
		}

		function map($array, $width, $c) {
			foreach ($array as $k => &$v) $v = format_value($v, $width[$k], $c);
			return $array;
		}

		function map_header($array) {
			foreach ($array as $k => &$v) $v = $k;
			return $array;
		}

		function map_mtable($array) {
			foreach ($array as $k => &$v) $v = '-';
			return $array;
		}

		if ($_POST["format"] == "markdown") {
			$connection = connection();
			$result = $connection->query($query, 1);
			if ($result) {
				$rn = 0;
				$sample_rows = array();
				$column_width = array();

				echo '## ' . addcslashes($table, "\r\n\"\\") . "\n\n";

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
							echo implode(" | ", map(map_header($row), $column_width, " ")) . "\n";
							echo implode("-|-", map(map_mtable($row), $column_width, "-")). "\n";
							foreach ($sample_rows as $sample_row) {
								echo implode(" | ", map($sample_row, $column_width, " ")) . "\n";
							}
						default:
							echo implode(" | ", map($row, $column_width, " ")) . "\n";
					}
					$rn++;
				}
                                if ($rn<100) {
					echo implode(" | ", map(map_header($sample_rows[0]), $column_width, " ")) . "\n";
					echo implode("-|-", map(map_mtable($sample_rows[0]), $column_width, "-")). "\n";
					foreach ($sample_rows as $sample_row) {
						echo implode(" | ", map($sample_row, $column_width, " ")) . "\n";
					}
				}
				echo "\n";
			}
			return true;
		}
	}

	function dumpHeaders($identifier, $multi_table = false) {
		if ($_POST["format"] == "markdown") {
			header("Content-Type: text/plain; charset=utf-8");
			return "markdown";
		}
	}

}
