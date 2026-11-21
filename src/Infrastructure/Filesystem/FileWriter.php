<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Filesystem;

use FilesystemIterator;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Port\FileWriterInterface;
use Override;
use RuntimeException;
use SplFileInfo;

/**
 * @internal
 */
final readonly class FileWriter implements FileWriterInterface
{
    #[Override]
    public function read(string $root, string $relativePath): ?string
    {
        $path = $root . '/' . $relativePath;
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(\sprintf('Cannot read "%s".', $path));
        }

        return $contents;
    }

    #[Override]
    public function write(string $root, GeneratedFile $file): void
    {
        AtomicFile::write($root . '/' . $file->relativePath, $file->contents);
    }

    #[Override]
    public function list(string $root, string $directory): array
    {
        $base = $root . '/' . $directory;
        if (!is_dir($base)) {
            return [];
        }

        $paths = [];
        foreach (new FilesystemIterator($base, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $paths[] = $directory . '/' . $file->getFilename();
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    #[Override]
    public function delete(string $root, string $relativePath): void
    {
        $path = $root . '/' . $relativePath;
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException(\sprintf('Cannot delete "%s".', $path));
        }
    }
}
