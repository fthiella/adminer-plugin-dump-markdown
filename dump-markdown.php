<?php

/** Dump to MARKDOWN format
* @link https://github.com/fthiella/adminer-plugin-dump-markdown
* @author Federico Thiella, https://fthiella.github.io/ 
* @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
* @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
*/
class AdminerDumpMarkdown {
	/** @access protected */
	var $database = false;
	
	function dumpFormat() {
		return array('markdown' => 'Markdown');
	}

	function dumpTable($table, $style, $is_view = false) {
		if ($_POST["format"] == "markdown") {
			return true;
		}
	}

	function _database() {
		echo "\r\n";
	}

	function dumpData($table, $style, $query) {
		if ($_POST["format"] == "markdown") {
			if ($this->database) {
				echo "\r\n";
			} else {
				$this->database = true;
				register_shutdown_function(array($this, '_database'));
			}
			$connection = connection();
			$result = $connection->query($query, 1);
			if ($result) {
				echo '## ' . addcslashes($table, "\r\n\"\\") . "\r\n";
				$first = true;
				while ($row = $result->fetch_assoc()) {
					if ($first) {
						echo implode(" | ", array_keys($row)) . "\r\n";
						echo implode(" | ", array_fill(0, count(array_keys($row)), '--')). "\r\n";
						$first = false;
					}
					echo implode(" | ", array_values($row)) . "\r\n";
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
