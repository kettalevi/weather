<?php
declare(strict_types=1);

/**
 * Streams the app's own source code as a .zip so people without git can grab it.
 * Requires the PHP "zip" extension (enabled by default on most hosts).
 */
if (!class_exists('ZipArchive')) {
    http_response_code(501);
    exit('The PHP zip extension is not enabled on this server. Please download from GitHub instead.');
}

$root   = __DIR__;
$prefix = 'php-weather/';
$skip   = ['.git', '.github', 'cache'];          // never ship VCS data or cached responses
$tmp    = tempnam(sys_get_temp_dir(), 'wx');

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create the archive.');
}

$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn(SplFileInfo $f) => !in_array($f->getFilename(), $skip, true)
    )
);
foreach ($it as $file) {
    if ($file->isFile()) {
        $zip->addFile($file->getPathname(), $prefix . substr($file->getPathname(), strlen($root) + 1));
    }
}
$zip->addFromString($prefix . 'cache/.gitkeep', '');   // empty, writable cache dir
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="php-weather.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
