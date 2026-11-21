<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\SyncFiles;

use Msstc4Symfony\SloBundle\Application\Port\FileWriterInterface;

final readonly class Action
{
    public function __construct(private FileWriterInterface $writer)
    {
    }

    public function __invoke(Input $input): Output
    {
        $stale = [];
        $missing = [];
        $generated = [];
        foreach ($input->files->files as $file) {
            $generated[$file->relativePath] = true;
            $current = $this->writer->read($input->root, $file->relativePath);
            if ($current === $file->contents) {
                continue;
            }
            if ($current === null) {
                $missing[] = $file->relativePath;
            } else {
                $stale[] = $file->relativePath;
            }
            if (!$input->checkOnly) {
                $this->writer->write($input->root, $file);
            }
        }

        $extra = [];
        foreach ($input->files->managedDirectories as $directory) {
            foreach ($this->writer->list($input->root, $directory) as $path) {
                if (!isset($generated[$path])) {
                    $extra[] = $path;
                }
            }
        }
        if (!$input->checkOnly) {
            foreach ($extra as $path) {
                $this->writer->delete($input->root, $path);
            }
        }

        return new Output($stale, $missing, $extra);
    }
}
