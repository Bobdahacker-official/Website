<?php
declare(strict_types=1);

// Build with PHP CLI and the Zip extension. The hosted app does not need it.
$root = dirname(__DIR__);
$archive = new ZipArchive();
if ($archive->open(__DIR__ . '/KioWiki-InfinityFree.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create the InfinityFree archive.');
}
foreach (['index.php', 'config.example.php', '.htaccess'] as $path) {
    $archive->addFile($root . '/' . $path, $path);
}
foreach (['app', 'assets'] as $directory) {
    foreach (new FilesystemIterator($root . '/' . $directory) as $file) {
        if ($file->isFile()) {
            $archive->addFile($file->getPathname(), $directory . '/' . $file->getFilename());
        }
    }
}
$archive->addFromString('config.php', "<?php\nreturn ['setup_on_home' => true];\n");
if (!$archive->close()) {
    throw new RuntimeException('Could not save the InfinityFree archive.');
}
