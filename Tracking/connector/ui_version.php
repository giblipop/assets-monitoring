<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    $projectRoot = realpath(dirname(__DIR__, 2));
    if ($projectRoot === false) {
        throw new RuntimeException('The application root is unavailable.');
    }

    $sourceDirectories = [
        $projectRoot . DIRECTORY_SEPARATOR . 'Tracking',
        $projectRoot . DIRECTORY_SEPARATOR . 'page',
    ];
    $excludedDirectories = [
        '.activity-archive',
        '.recycle-bin',
        'backups',
        'node_modules',
        'users',
        'vendor',
    ];
    $sourceExtensions = [
        'css', 'html', 'htm', 'ico', 'jpeg', 'jpg', 'js', 'json',
        'php', 'png', 'sql', 'svg', 'webp',
    ];
    $fingerprintEntries = [];

    foreach ($sourceDirectories as $sourceDirectory) {
        if (!is_dir($sourceDirectory)) {
            throw new RuntimeException('An application source directory is unavailable.');
        }

        $directory = new RecursiveDirectoryIterator(
            $sourceDirectory,
            FilesystemIterator::SKIP_DOTS
        );
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $entry) use ($excludedDirectories): bool {
                return !$entry->isDir()
                    || !in_array($entry->getFilename(), $excludedDirectories, true);
            }
        );
        $files = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $sourceExtensions, true)) {
                continue;
            }

            $path = $file->getPathname();
            $modifiedAt = $file->getMTime();
            $size = $file->getSize();
            $contentHash = hash_file('sha256', $path);
            if ($modifiedAt < 1 || $size < 0 || $contentHash === false) {
                throw new RuntimeException('An application source file could not be checked.');
            }

            $relativePath = substr($path, strlen($projectRoot) + 1);
            $fingerprintEntries[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath)
                . "\0" . $size
                . "\0" . $modifiedAt
                . "\0" . $contentHash;
        }
    }

    if ($fingerprintEntries === []) {
        throw new RuntimeException('No application source files were found.');
    }

    sort($fingerprintEntries, SORT_STRING);
    $version = substr(hash('sha256', implode("\n", $fingerprintEntries)), 0, 24);
    echo json_encode(
        ['version' => $version],
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
} catch (Throwable $error) {
    error_log('UI version check failed: ' . $error->getMessage());
    http_response_code(500);
    echo '{"error":"The application version could not be checked."}';
}
