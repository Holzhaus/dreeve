<?php

declare(strict_types=1);

namespace App\Infrastructure\Archive;

interface Archiver
{
    /**
     * Archives every path under its own name, directories with everything in them.
     *
     * @param list<string> $paths
     * @param list<string> $excludedPaths
     *
     * @throws CouldNotProcessArchive
     */
    public function create(string $archivePath, array $paths, array $excludedPaths = []): void;

    /**
     * @return list<string> The names of the files and directories at the root of the archive
     *
     * @throws CouldNotProcessArchive
     */
    public function listRootEntries(string $archivePath): array;

    /**
     * Extracts the given entries into the target directory, directories with everything in them.
     *
     * @param list<string> $entries
     *
     * @throws CouldNotProcessArchive
     */
    public function extract(string $archivePath, string $targetDirectory, array $entries): void;
}
