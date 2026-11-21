<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\SyncFiles;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Port\FileWriterInterface;
use Override;

final class InMemoryFileWriter implements FileWriterInterface
{
    /**
     * @param array<string, string> $files "<root>/<relative path>" => contents
     */
    public function __construct(public array $files = [])
    {
    }

    #[Override]
    public function read(string $root, string $relativePath): ?string
    {
        return $this->files[$root . '/' . $relativePath] ?? null;
    }

    #[Override]
    public function write(string $root, GeneratedFile $file): void
    {
        $this->files[$root . '/' . $file->relativePath] = $file->contents;
        ksort($this->files);
    }

    #[Override]
    public function list(string $root, string $directory): array
    {
        $prefix = $root . '/' . $directory . '/';
        $paths = [];
        foreach (array_keys($this->files) as $path) {
            $path = (string) $path;
            if (str_starts_with($path, $prefix)) {
                $paths[] = substr($path, \strlen($root) + 1);
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    #[Override]
    public function delete(string $root, string $relativePath): void
    {
        unset($this->files[$root . '/' . $relativePath]);
    }
}
