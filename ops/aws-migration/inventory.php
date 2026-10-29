<?php
// Read-only filesystem inventory. Run from the application root; redirect to a 0600 file.
$files = [];
foreach (['private', 'studio-private', 'public'] as $scope) {
    $root = getcwd().'/storage/app/'.$scope.'/';
    if (!is_dir($root)) throw new RuntimeException('Missing storage root');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) throw new RuntimeException('Review symbolic link before inventory');
        if (!$file->isFile()) continue;
        $path = substr($file->getPathname(), strlen($root));
        $files[] = ['scope' => $scope, 'path' => $path, 'size' => $file->getSize(), 'mtime' => $file->getMTime()];
    }
}
echo json_encode(['captured_at'=>gmdate('c'), 'files'=>$files], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
