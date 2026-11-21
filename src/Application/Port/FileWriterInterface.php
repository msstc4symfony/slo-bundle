<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;

/**
 * Output directory access of slo:generate; paths are relative to $root and slash-separated.
 */
interface FileWriterInterface
{
    /**
     * @return string|null null when the file does not exist
     */
    public function read(string $root, string $relativePath): ?string;

    public function write(string $root, GeneratedFile $file): void;

    /**
     * @return list<string> relative paths (including $directory) of every regular file below $directory, sorted
     */
    public function list(string $root, string $directory): array;

    public function delete(string $root, string $relativePath): void;
}
