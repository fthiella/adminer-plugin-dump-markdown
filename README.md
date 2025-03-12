# adminer-plugin-dump-markdown

This plugin enhances Adminer by adding a "Markdown" export format, allowing you
to dump database structure and data into Markdown-formatted text files (`.md`).

## Installation

1. [Download](https://www.adminer.org/#download) and install Adminer tool.
2. [Download](https://raw.github.com/vrana/adminer/master/plugins/plugin.php) and install plugin.php
3. [Download](https://github.com/fthiella/adminer-plugin-dump-markdown/blob/master/dump-markdown.php) and install dump-markdown.php
4. create an index.php like the following:

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
        // specify enabled plugins here
        new AdminerDumpMarkdown([
              // optional parameters
              'rowSampleLimit' => 50,
              'nullValue' => '(null)',
              'disableUTF8' => False,
              'specialChars' => '\\*_[](){}+-#.!|',
              'markdown_chr' => ['space'  => ' ', 'table'  => '|', 'header' => '-']
            ]),
    );

    /* It is possible to combine customization and plugins:
    class AdminerCustomization extends AdminerPlugin {
    }
    return new AdminerCustomization($plugins);
    */

    return new AdminerPlugin($plugins);
}

// include original Adminer or Adminer Editor
include "./adminer-4.3.1-en.php";
?>
```

File structure has to be like the following one:
```
- plugins
    - plugin.php
    - dump-markdown.php
    - ...
- adminer.php
- index.php
```

## Configuration Options

The `adminer-plugin-dump-markdown` plugin can be configured using optional parameters passed
to the `AdminerDumpMarkdown` class constructor in your `index.php` file:

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

### specialChars (string, optional, default: "\\\*\_\[\]\(\)\{\}\+\-\#\.\!\|")

Defines the set of special Markdown characters that will be escaped with a backslash (\\) in the output.

### markdown_chr (array, optional, default: ['space' => ' ', 'table' => '|', 'header' => '-']):

Allows you to customize the characters used for Markdown table formatting:

- `'space'`: padding within table cells (default: space ).
- `'table'`: table column separators (default: vertical bar \|).
- `'header'`: table header separator line (default: hyphen -).

### disableUTF8 (boolean, optional, default: False):

- `disableUTF8: False` (Default - Recommended): the plugin handles UTF-8 encoded data correctly, if the mbstring PHP extension is available
- `disableUTF8: True`  When set to true, the plugin performs a lossy conversion of UTF-8 text data to ISO-8859-1 encoding

## Notes

If the PHP mbstring extension is not enabled on your server,
the plugin will automatically fall back to byte-based string operations for core functionality.
It is recommended to enable the mbstring extension in your PHP configuration for better UTF-8 support.