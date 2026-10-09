<?php

declare(strict_types=1);

/*
 * OPcache preload script for the parser core (tokenizer, parser, generator, expressions and the
 * built-in dialects), so requests skip loading and linking about 1,100 classes.
 *
 *     opcache.preload=/path/to/app/vendor/kwasii1/laravel-sql-parser/preload.php
 *     opcache.preload_user=www-data
 *
 * The package works the same without it.
 */

$source = __DIR__.'/src/';

// The core has no dependencies, so a PSR-4 loader for the package is enough. Composer's autoloader
// would also load every package's helper files into the preloaded state.
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'LaravelSqlParser\\LaravelSqlParser\\';

    if (str_starts_with($class, $prefix)) {
        $file = $source.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

// The Laravel layer is left out: it needs the framework, which isn't loaded yet
$skip = ['LaravelSqlParser.php', 'LaravelSqlParserServiceProvider.php', 'Facades', 'Console'];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($source));

    if ($file->getExtension() !== 'php' || in_array(strtok($relative, '/\\'), $skip, true)) {
        continue;
    }

    // Through the autoloader, so parents, interfaces and traits load first
    $class = 'LaravelSqlParser\\LaravelSqlParser\\'.str_replace(['/', '\\'], '\\', substr($relative, 0, -4));
    class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
}
