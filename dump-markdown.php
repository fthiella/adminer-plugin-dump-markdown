<?php

/*
 * AdminerDumpMarkdown - dump to MARKDOWN format v0.5 (July 12 2018)
 *
 * @link https://github.com/fthiella/adminer-plugin-dump-markdown
 * @author Federico Thiella, https://fthiella.github.io/ 
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 *
 */

class AdminerDumpMarkdown {
	/** @access protected */

	function dumpFormat() {
		return array('markdown' => 'Markdown');
	}

	function dumpTable($table, $style, $is_view = false) {
		if ($_POST["format"] == "markdown") {
			echo '## ' . addcslashes($table, "\r\n\"\\") . "\n\n";
			return true;
		}
	}

	function format_value($s, $l, $c) {
		return (strlen($s) > $l) ? substr($s, 0, $l) : str_pad($s, $l, $c);
	}

	function map($array, $width, $c) {
		foreach ($array as $k => &$v) $v = $this->format_value($v, $width[$k], $c);
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

	function echo_sampled_rows($rows, $column_width) {
		echo implode(" | ", $this->map($this->map_header($rows[0]), $column_width, " ")) . "\r\n";
		echo implode("-|-", $this->map($this->map_mtable($rows[0]), $column_width, "-")) . "\r\n";
		foreach ($rows as $sample_row) {
			echo implode(" | ", $this->map($sample_row, $column_width, " ")) . "\r\n";
		}
	}

	function dumpData($table, $style, $query) {
		if ($_POST["format"] == "markdown") {
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
							$this->echo_sampled_rows($sample_rows, $column_width);
						default:
							echo implode(" | ", $this->map($row, $column_width, " ")) . "\r\n";
					}
					$rn++;
				}
				if ($rn<100) {
					$this->echo_sampled_rows($sample_rows, $column_width);
				}
				echo "\r\n";
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
