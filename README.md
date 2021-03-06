# adminer-plugin-dump-markdown
Adminer Plugin: dump to Markdown format

## How to use

1. [Download](http://www.adminer.org/#download) and install Adminer tool.
2. [Download](http://www.adminer.org/plugins/) and install plugin.php
3. [Download](fthiella/adminer-plugin-dump-markdown) and install dump-markdown.php
4. create an index.php like the following:

````
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
        new AdminerDumpMarkdown,
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
````

File structure has to be like the following one:
````
- plugins
    - plugin.php
    - dump-markdown.php
    - ...
- adminer.php
- index.php
````


