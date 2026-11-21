<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class GeneratedFile
{
    /**
     * @param non-empty-string $relativePath slash-separated, relative to the output directory
     */
    public function __construct(
        public string $relativePath,
        public string $contents,
    ) {
        if (!self::isRelativePath($relativePath)) {
            throw new InvalidArgumentException(\sprintf('Generated file path "%s" must be relative, slash-separated and without "." or ".." segments.', $relativePath));
        }
    }

    public static function isRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }
}
