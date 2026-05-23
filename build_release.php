<?php

declare(strict_types=1);

$root = __DIR__;
$releaseRoot = $root . '/release';
$releaseDir = $releaseRoot . '/cabin_portal_obfuscated';
$zipPath = $releaseRoot . '/cabin_portal_obfuscated.zip';

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function mkdirp(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function copy_file(string $source, string $target): void {
    mkdirp(dirname($target));
    if (!copy($source, $target)) {
        throw new RuntimeException("Could not copy $source to $target");
    }
}

function obfuscate_php_file(string $source, string $target): void {
    $code = file_get_contents($source);
    if ($code === false) {
        throw new RuntimeException("Could not read $source");
    }
    $payload = base64_encode(gzencode($code, 9));
    $out = "<?php\n";
    $out .= "/* Obfuscated release build. Keep original source private. */\n";
    $out .= "\$__af_payload = '" . $payload . "';\n";
    $out .= "\$__af_code = gzdecode(base64_decode(\$__af_payload));\n";
    $out .= "if (\$__af_code === false) { http_response_code(500); exit('Application payload error.'); }\n";
    $out .= "eval('?>' . \$__af_code);\n";
    $out .= "unset(\$__af_payload, \$__af_code);\n";
    mkdirp(dirname($target));
    file_put_contents($target, $out);
}

function create_clean_database(string $sourceDb, string $targetDb): void {
    if (file_exists($targetDb)) {
        unlink($targetDb);
    }
    $source = new PDO('sqlite:' . $sourceDb);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $target = new PDO('sqlite:' . $targetDb);
    $target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema = $source->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' ORDER BY type DESC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($schema as $sql) {
        $target->exec($sql);
    }

    foreach (['settings', 'airlines'] as $table) {
        $rows = $source->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $placeholders = array_map(fn($column) => ':' . $column, $columns);
            $stmt = $target->prepare("INSERT INTO $table (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")");
            foreach ($row as $column => $value) {
                $stmt->bindValue(':' . $column, $value);
            }
            $stmt->execute();
        }
    }
}

rrmdir($releaseDir);
mkdirp($releaseDir);
mkdirp($releaseDir . '/uploads/cabin_items');
mkdirp($releaseDir . '/uploads/branding');

foreach (['index.php', 'api.php', 'staff.php', 'bootstrap.php'] as $file) {
    obfuscate_php_file($root . '/' . $file, $releaseDir . '/' . $file);
}

foreach (['.htaccess', 'RELEASE_NOTES.md', 'stations.json'] as $file) {
    if (file_exists($root . '/' . $file)) {
        copy_file($root . '/' . $file, $releaseDir . '/' . $file);
    }
}

copy_file($root . '/uploads/.htaccess', $releaseDir . '/uploads/.htaccess');

if (file_exists($root . '/import_excel.py')) {
    copy_file($root . '/import_excel.py', $releaseDir . '/import_excel.py');
}

create_clean_database($root . '/cabin_db.sqlite', $releaseDir . '/cabin_db.sqlite');

if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Could not create release zip.');
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($releaseDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    $path = $file->getPathname();
    $local = substr($path, strlen($releaseRoot) + 1);
    if ($file->isDir()) {
        $zip->addEmptyDir($local);
    } else {
        $zip->addFile($path, $local);
    }
}
$zip->close();

echo "Release directory: $releaseDir\n";
echo "Release zip: $zipPath\n";
