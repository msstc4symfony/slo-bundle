<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use InvalidArgumentException;

/**
 * Files of one or more generators plus the directories they write to. The generator owns only the files matching
 * OWNED_FILES directly in those directories: a stale owned file is deleted, anything else is never touched.
 *
 * @internal
 */
final readonly class GeneratedFileSet
{
    /**
     * Managed directory => fnmatch() pattern of the file names the generator owns there.
     */
    public const OWNED_FILES = [
        'prometheus' => 'slo-*.rules.yml',
        'grafana/dashboards' => 'slo-*.json',
        'grafana/provisioning/dashboards' => 'slo.yaml',
        'sloth' => 'slo-*.yml',
        'alertmanager' => 'slo-alertmanager*.yml',
    ];

    /**
     * @var list<non-empty-string> sorted
     */
    public array $managedDirectories;

    /**
     * @var list<GeneratedFile> sorted by path
     */
    public array $files;

    /**
     * @param list<non-empty-string> $managedDirectories keys of OWNED_FILES
     * @param list<GeneratedFile> $files each an owned file of one of the managed directories
     */
    public function __construct(array $managedDirectories, array $files)
    {
        foreach ($managedDirectories as $directory) {
            if (!isset(self::OWNED_FILES[$directory])) {
                throw new InvalidArgumentException(\sprintf('"%s" is not a generated directory, expected one of %s.', $directory, implode(', ', array_keys(self::OWNED_FILES))));
            }
        }
        $managedDirectories = array_values(array_unique($managedDirectories));
        sort($managedDirectories, SORT_STRING);

        $byPath = [];
        foreach ($files as $file) {
            if (isset($byPath[$file->relativePath])) {
                throw new InvalidArgumentException(\sprintf('"%s" is generated twice.', $file->relativePath));
            }
            if (!$this->ownedBy($file->relativePath, $managedDirectories)) {
                throw new InvalidArgumentException(\sprintf('"%s" is not an owned file of the managed directories %s.', $file->relativePath, implode(', ', $managedDirectories)));
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
     * True for a file name the generator owns directly in one of the managed directories.
     */
    public function owns(string $path): bool
    {
        return $this->ownedBy($path, $this->managedDirectories);
    }

    /**
     * @param list<non-empty-string> $directories
     */
    private function ownedBy(string $path, array $directories): bool
    {
        $slash = strrpos($path, '/');
        if ($slash === false) {
            return false;
        }
        $directory = substr($path, 0, $slash);

        return \in_array($directory, $directories, true)
            && isset(self::OWNED_FILES[$directory])
            && fnmatch(self::OWNED_FILES[$directory], substr($path, $slash + 1));
    }
}
