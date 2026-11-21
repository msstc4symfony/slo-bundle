<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use InvalidArgumentException;

/**
 * Files of one or more generators plus the directories they own: anything else found there is stale output.
 */
final readonly class GeneratedFileSet
{
    /**
     * @var list<non-empty-string> sorted
     */
    public array $managedDirectories;

    /**
     * @var list<GeneratedFile> sorted by path
     */
    public array $files;

    /**
     * @param list<non-empty-string> $managedDirectories relative to the output directory
     * @param list<GeneratedFile> $files each inside one of the managed directories
     */
    public function __construct(array $managedDirectories, array $files)
    {
        foreach ($managedDirectories as $directory) {
            if (!GeneratedFile::isRelativePath($directory)) {
                throw new InvalidArgumentException(\sprintf('Managed directory "%s" must be relative, slash-separated and without "." or ".." segments.', $directory));
            }
        }
        $managedDirectories = array_values(array_unique($managedDirectories));
        sort($managedDirectories, SORT_STRING);

        $byPath = [];
        foreach ($files as $file) {
            if (isset($byPath[$file->relativePath])) {
                throw new InvalidArgumentException(\sprintf('"%s" is generated twice.', $file->relativePath));
            }
            if (!$this->isInside($file->relativePath, $managedDirectories)) {
                throw new InvalidArgumentException(\sprintf('"%s" is outside the managed directories %s.', $file->relativePath, implode(', ', $managedDirectories)));
            }
            $byPath[$file->relativePath] = $file;
        }
        ksort($byPath, SORT_STRING);

        $this->managedDirectories = $managedDirectories;
        $this->files = array_values($byPath);
    }

    public function merge(self $other): self
    {
        return new self([...$this->managedDirectories, ...$other->managedDirectories], [...$this->files, ...$other->files]);
    }

    /**
     * @param list<non-empty-string> $directories
     */
    private function isInside(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }
}
