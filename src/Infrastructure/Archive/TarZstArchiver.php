<?php

declare(strict_types=1);

namespace App\Infrastructure\Archive;

/**
 * Creates and reads zstd compressed tar archives in PHP, so the image needs neither tar nor zstd.
 * Archive_Tar takes care of the tar format, the stream wrapper of the zstd extension of the
 * compression. Archives are streamed, nothing is ever decompressed to disk or memory as a whole.
 */
final readonly class TarZstArchiver implements Archiver
{
    // Archive_Tar copies files in blocks of this size, which must be a multiple of 512 bytes.
    private const int BUFFER_LENGTH = 1024 * 1024;

    public function create(string $archivePath, array $paths, array $excludedPaths = []): void
    {
        $tar = $this->open($archivePath);
        if ([] !== $excludedPaths) {
            // Archive_Tar matches this against every path with a slash in front of it.
            $tar->setIgnoreRegexp(sprintf(
                '#^/(%s)(/|$)#',
                implode('|', array_map(static fn (string $path): string => preg_quote($path, '#'), $excludedPaths)),
            ));
        }

        // createModify() can only strip a single directory from the stored names, but every path
        // is stored relative to its own parent directory. This is what createModify() does, with
        // one _addList() call per path.
        if (!$tar->_openWrite()) {
            throw $this->error($tar, sprintf('Could not write "%s"', $archivePath));
        }
        foreach ($paths as $path) {
            if (!$tar->_addList([$path], '', dirname($path))) {
                $tar->_close();
                throw $this->error($tar, sprintf('Could not add "%s"', $path));
            }
        }
        if (!$tar->_writeFooter() || !$tar->_close()) {
            throw $this->error($tar, sprintf('Could not write "%s"', $archivePath));
        }
    }

    public function listRootEntries(string $archivePath): array
    {
        $tar = $this->open($archivePath);
        $entries = $tar->listContent();
        if (!is_array($entries) || [] === $entries) {
            throw $this->error($tar, sprintf('"%s" is not a valid archive', $archivePath));
        }

        $rootEntries = [];
        foreach ($entries as $entry) {
            $rootEntries[] = explode('/', (string) preg_replace('#^\./#', '', (string) $entry['filename']), 2)[0];
        }

        return array_values(array_unique(array_filter($rootEntries)));
    }

    public function extract(string $archivePath, string $targetDirectory, array $entries): void
    {
        $tar = $this->open($archivePath);
        $entryList = [];
        foreach ($entries as $entry) {
            // Archive_Tar matches entries ending in a slash as a directory, all others as a file.
            $entryList[] = $entry;
            $entryList[] = rtrim($entry, '/').'/';
        }

        // Archive_Tar stops extracting as soon as it extracted as many entries as it was given,
        // which assumes that every given entry is a single file. Pad the list with entries that
        // never match, as names in a tar archive cannot contain NUL bytes, so it reads to the end.
        $archiveEntries = $tar->listContent();
        if (!is_array($archiveEntries)) {
            throw $this->error($tar, sprintf('"%s" is not a valid archive', $archivePath));
        }
        for ($i = count($entryList); $i <= count($archiveEntries); ++$i) {
            $entryList[] = "\0".$i;
        }

        if (!$tar->extractList($entryList, $targetDirectory)) {
            throw $this->error($tar, sprintf('Could not extract "%s"', $archivePath));
        }
    }

    private function open(string $archivePath): \Archive_Tar
    {
        // Without an explicit compression, Archive_Tar reads and writes the path as a plain file.
        return new \Archive_Tar('compress.zstd://'.$archivePath, null, self::BUFFER_LENGTH);
    }

    private function error(\Archive_Tar $tar, string $message): CouldNotProcessArchive
    {
        $error = $tar->error_object;

        return new CouldNotProcessArchive($error instanceof \PEAR_Error ? $message.': '.$error->getMessage() : $message);
    }
}
