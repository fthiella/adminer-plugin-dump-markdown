# adminer-plugin-dump-markdown

This plugin enhances Adminer by adding a "Markdown" export format, allowing you
to dump database structure and data into Markdown-formatted text files (`.md`).

## Features

For each dumped database, the plugin outputs:

- A **table of contents** with anchor links to each table.
- For each table, the **structure** (columns, types, comments, nullability,
  auto-increment), followed by its **indexes** and **foreign keys**, each as
  its own Markdown table.
- The table **data**, sampled up to `rowSampleLimit` rows to compute column
  widths, then streamed for larger tables.
- If a query fails, an inline `> ERROR: query failed: ...` note instead of a
  silently empty section.

## Requirements

- PHP 7.2 or later.
- The `mbstring` extension is recommended (not required) for correct UTF-8 handling; without it the plugin falls back to byte-based string operations. See [Notes](#notes).

## Installation

### For Adminer 6.0.0 and newer

Adminer 6.0.0 introduced a new, simpler plugin system based on the `adminer-plugins/` folder.
 
1. [Download](https://www.adminer.org/#download) and install Adminer (e.g., `adminer-6.0.0-en.php`).

2. Download `dump-markdown.php` from this repository and place it in the `adminer-plugins/` directory (create it if it doesn't exist), next to your Adminer file.

3. **Optional - configure the plugin**:   
 Create an `adminer-plugins.php` file in the same folder as Adminer:

   ```php
   <?php // adminer-plugins.php

   return array(
       new AdminerDumpMarkdown([
           'rowSampleLimit' => 100,
           'nullValue'      => 'N/D',
           'disableUTF8'     => false,
           'markdown_chr' => ['space' => ' ', 'table' => '|', 'header' => '-'],
           'specialChars'   => '\*[](){+-#\!|',
           'tableAlign'     => true,
           'tablePipes'     => false,
           'typeAlign'      => [
               'number' => 'right',
               'bool'   => 'center',
               'default' => 'left'
           ]
       )]),
   );
   ```

   If you omit this file, the plugin will use its default settings.

4. Your `index.php` can be minimal:

   ```php
   <?php
   include "./adminer-6.0.0-en.php";
   ?>
   ```

Adminer 6.0.0 will automatically detect and configure the plugin from the `adminer-plugins/` folder and, if present,
apply the configuration from `adminer-plugins.php`.

---

### For Adminer 5.x and older

If you're using an older version of Adminer (5.x or below), follow the classic approach:

1. [Download](https://www.adminer.org/#download) and install Adminer.

2. [Download](https://raw.github.com/vrana/adminer/master/plugins/plugin.php) and place `plugin.php` in your `plugins/` folder.

3. Download `dump-markdown.php` from this repository and place it in the same `plugins/` folder.

4. Create an `index.php` like the following:

```php
<?php
function adminer_object() {
    // required to run any plugin
    include_once "./plugins/plugin.php";

    // autoloader
    foreach (glob("plugins/*.php") as $filename) {
        include_once "./$filename";
    }

    $plugins = array(
        new AdminerDumpMarkdown([
            'rowSampleLimit' => 100,
            'nullValue'      => 'N/D',
            'tablePipes'     => false,
            'tableAlign'     => false,
            'specialChars'   => '\*[](){+-#\!|',
            'columnAlign'    => ['id' => 'center'],
            'typeAlign'      => [
                'number'  => 'right',
                'bool'    => 'center',
                'default' => 'left'
            ]
        ]),
    );

    return new AdminerPlugin($plugins);
}

// include original Adminer or Adminer Editor
include "./adminer-5.5.1-en.php";
?>
```

## Configuration Options

The `adminer-plugin-dump-markdown` plugin can be configured using optional parameters passed
to the `AdminerDumpMarkdown` class constructor in your `index.php` file (Adminer 5.x)
or in `adminer-plugins.php` (Adminer 6.0.0):

```php
new AdminerDumpMarkdown([
    // Configuration options here
]);
```

The following configuration options are available:

### rowSampleLimit (integer, optional, default: 100):

Specifies the maximum number of rows to sample from each table when determining column widths for Markdown table formatting.

### nullValue (string, optional, default: "N/D"):

Defines the string to be used in the Markdown output to represent NULL database values.

### specialChars (string, optional, default: `\*_[](){}+-#\!|`)

Defines the set of special Markdown characters that will be escaped with a backslash (\) in the output.

Note: the default includes _ for maximum compatibility with older/non-standard Markdown parsers,
but can be omitted for better readability.

### markdown_chr (array, optional, default: ['space' => ' ', 'table' => '|', 'header' => '-']):

Allows you to customize the characters used for Markdown table formatting:

- `'space'`: padding within table cells (default: space ).
- `'table'`: table column separators (default: vertical bar \|).
- `'header'`: table header separator line (default: hyphen -).

### disableUTF8 (boolean, optional, default: False):

- `disableUTF8: False` (Default - Recommended): the plugin handles UTF-8 encoded data correctly, if the mbstring PHP extension is available
- `disableUTF8: True`  When set to true, the plugin performs a lossy conversion of UTF-8 text data to ISO-8859-1 encoding

### tablePipes (boolean, optional, default: false):

When true, wraps tables with leading and trailing pipes (|).

### tableAlign (bool, optional, default: false):

When true, includes alignment markers (:---) in the separator row. When false all
columns are aligned to left.

### truncationMarker (string, optional, default: "" - empty):

When a value is longer than its column's width (as computed from the
sampled rows), it gets cut to fit:

- By default this happens silently;
- Set this to a short string (e.g. `'~'` or `'...'`) to mark truncated
  values instead (e.g. `"exampl~"` instead of `"example"`).
  It's UTF-8 safe.

#### Alignment Control

- `typeAlign` (array): Defines default alignment based on data types.
    number: defaults to right.
    bool: defaults to center.
    default: defaults to left (for text, varchars, dates, etc.).
- `columnAlign` (array): Manual override for specific columns. Example: `'column_name' => 'center'`

## Testing

The plugin has a PHPUnit test suite covering Markdown escaping, column
alignment/padding, generated table output, and the public methods Adminer
calls. To run it:

```
phpunit
```

from the repository root (requires PHPUnit 10+, since the suite uses PHP 8
attributes for data providers; `phpunit.xml` and the `tests/` directory
are included in the repository).

## Notes

If the PHP mbstring extension is not enabled on your server,
the plugin will automatically fall back to byte-based string operations for core functionality.
It is recommended to enable the mbstring extension in your PHP configuration for better UTF-8 support.
