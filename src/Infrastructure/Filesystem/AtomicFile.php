<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Filesystem;

use RuntimeException;

/**
 * Writes through a temporary file and a rename, so a reader (or an include) never sees a partial file. The temporary
 * name is random and created exclusively, so a file or link planted next to the target is never written through.
 *
 * @internal
 */
final class AtomicFile
{
    /**
     * @throws RuntimeException when the directory or the file cannot be written
     */
    public static function write(string $path, string $contents): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Cannot create the directory of "%s".', $path));
        }

        $temporary = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException(\sprintf('Cannot write "%s".', $path));
        }
        $written = @fwrite($handle, $contents);
        fclose($handle);
        if ($written !== \strlen($contents)) {
            @unlink($temporary);

            throw new RuntimeException(\sprintf('Cannot write "%s".', $path));
        }
        @chmod($temporary, 0o666 & ~umask());
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(\sprintf('Cannot write "%s".', $path));
        }
    }
}
