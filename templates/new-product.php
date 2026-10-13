<?php

/*
 * Creates a new product from templates/product-app.
 *
 *   php templates/new-product.php <code> "<Display name>" <dev port>
 *   php templates/new-product.php pharmacy "Pharmacy" 8400
 *
 * Writes <code>-app/ at the repository root with every "blueprint" (code,
 * path, permissions, cookie, database) renamed, then prints the remaining
 * setup steps. Nothing outside the new folder is changed.
 */

$root = dirname(__DIR__);
$template = __DIR__.'/product-app';

[$script, $code, $name, $port] = array_pad($argv, 4, null);

if ($code === null || $name === null || $port === null) {
    fwrite(STDERR, "usage: php templates/new-product.php <code> \"<Display name>\" <dev port>\n");
    exit(1);
}

if (preg_match('/^[a-z][a-z0-9]*$/', $code) !== 1 || $code === 'blueprint') {
    fwrite(STDERR, "The code must be lowercase letters and digits, starting with a letter (it becomes the URL path, catalog code and permission prefix).\n");
    exit(1);
}

if (preg_match('/^\d{4,5}$/', $port) !== 1) {
    fwrite(STDERR, "The dev port must be a number, e.g. 8400.\n");
    exit(1);
}

$target = $root.'/'.$code.'-app';

if (file_exists($target)) {
    fwrite(STDERR, "{$code}-app already exists.\n");
    exit(1);
}

// Never copied: installed or generated per checkout, or secrets.
$skip = [
    'vendor', 'node_modules', '.env', 'public/hot', 'public/blueprint',
    // Pins the packages at ../../packages: the product locks its own on install.
    'composer.lock',
    'resources/js/actions', 'resources/js/routes', 'resources/js/wayfinder',
    'bootstrap/cache/packages.php', 'bootstrap/cache/services.php', 'bootstrap/cache/config.php',
];

// The display name appears only here; "Blueprint" elsewhere is Laravel's
// schema builder class and must stay.
$displayName = [
    '.env.example' => ['APP_NAME="Blueprint"', 'APP_NAME="'.$name.'"'],
    'composer.json' => ['"Blueprint: a Miles', '"'.$name.': a Miles'],
    'platform/catalog.json' => ['"name": "Blueprint"', '"name": "'.$name.'"'],
    'resources/js/app.tsx' => ["|| 'Blueprint'", "|| '".str_replace("'", "\\'", $name)."'"],
];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($template, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$copied = 0;

foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($template) + 1));

    foreach ($skip as $excluded) {
        if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
            continue 2;
        }
    }

    // Logs, caches, sessions and compiled views stay behind (their .gitignore files come along).
    if (preg_match('#^storage/(logs|framework/(cache|sessions|views|testing))/.+#', $relative) === 1 && basename($relative) !== '.gitignore') {
        continue;
    }

    $destination = $target.'/'.$relative;

    if ($file->isDir()) {
        is_dir($destination) || mkdir($destination, 0775, true);

        continue;
    }

    is_dir(dirname($destination)) || mkdir(dirname($destination), 0775, true);
    $contents = (string) file_get_contents($file->getPathname());

    if (! preg_match('/\.(png|ico|svg|jpg|woff2?)$/', $relative)) {
        if (isset($displayName[$relative])) {
            $contents = str_replace($displayName[$relative][0], $displayName[$relative][1], $contents);
        }

        $contents = str_replace('blueprint', $code, $contents);
        $contents = str_replace('8499', $port, $contents);

        // One level shallower than the template.
        if ($relative === 'composer.json') {
            $contents = str_replace('"../../packages/', '"../packages/', $contents);
        }
    }

    file_put_contents($destination, $contents);
    $copied++;
}

$host = 'milessoftwares.local';
echo <<<TXT
Created {$code}-app ({$copied} files).

Next:
  1. Database:        createdb {$code}            (owner: your local DB user)
  2. Install:         cd {$code}-app && composer setup
  3. Register:        cd ../central-app && php artisan platform:local-product {$code}-app --url=http://127.0.0.1:{$port} --entitle=<workspace-slug>
  4. Web server:      add to ops/local-dev/apache-dev.conf (before the platform's ProxyPass "/"), copy it to D:/srv/apache/miles-dev.conf, restart Apache:
                        RedirectMatch 301 "^/{$code}$" "/{$code}/"
                        ProxyPassMatch   "^/{$code}$" "!"
                        ProxyPass        "/{$code}/" "http://127.0.0.1:{$port}/{$code}/"
                        ProxyPassReverse "/{$code}/" "http://127.0.0.1:{$port}/{$code}/"
     Production:      copy ops/nginx/products/pharmacy.conf to ops/nginx/products/{$code}.conf and rename the paths.
  5. Run:             cd {$code}-app && composer dev
  6. Open:            http://<workspace-slug>.{$host}/{$code}

TXT;
