<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Archive;

use App\Infrastructure\Archive\CouldNotProcessArchive;
use App\Infrastructure\Archive\TarZstArchiver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class TarZstArchiverTest extends TestCase
{
    private Filesystem $filesystem;
    private string $directory;

    public function testCreateAndExtract(): void
    {
        $this->filesystem->dumpFile($this->directory.'/source/database/dreeve.db', 'database');
        $this->filesystem->dumpFile($this->directory.'/source/files/activities/.hidden', 'hidden');
        $this->filesystem->dumpFile($this->directory.'/source/files/activities/database/nested.json', 'nested');
        $this->filesystem->dumpFile($this->directory.'/source/files/logs/daemon.log', 'log');
        $this->filesystem->dumpFile($this->directory.'/source/files/'.str_repeat('a-long-file-name-', 10).'.json', 'long name');
        for ($i = 0; $i < 50; ++$i) {
            $this->filesystem->dumpFile($this->directory.'/source/gear/file-'.$i.'.yaml', 'gear '.$i);
        }

        $archiver = new TarZstArchiver();
        $archiver->create(
            archivePath: $this->directory.'/archive.tar.zst',
            paths: [$this->directory.'/source/database', $this->directory.'/source/files', $this->directory.'/source/gear'],
            excludedPaths: [$this->directory.'/source/files/logs'],
        );

        $this->assertEquals(['database', 'files', 'gear'], $archiver->listRootEntries($this->directory.'/archive.tar.zst'));

        $archiver->extract($this->directory.'/archive.tar.zst', $this->directory.'/target', ['files', 'gear']);

        $expectedFiles = [
            'files/'.str_repeat('a-long-file-name-', 10).'.json',
            'files/activities/.hidden',
            'files/activities/database/nested.json',
        ];
        for ($i = 0; $i < 50; ++$i) {
            $expectedFiles[] = 'gear/file-'.$i.'.yaml';
        }
        sort($expectedFiles);
        $this->assertEquals($expectedFiles, $this->findFiles($this->directory.'/target'));
        $this->assertStringEqualsFile($this->directory.'/target/files/activities/database/nested.json', 'nested');
    }

    public function testArchivesCanBeReadByTar(): void
    {
        $this->filesystem->dumpFile($this->directory.'/source/files/activity.json', 'activity');

        new TarZstArchiver()->create($this->directory.'/archive.tar.zst', [$this->directory.'/source/files']);

        $process = new Process(['tar', '--zstd', '-xOf', $this->directory.'/archive.tar.zst', 'files/activity.json']);
        $process->mustRun();
        $this->assertEquals('activity', $process->getOutput());
    }

    public function testArchivesCreatedByTarCanBeExtracted(): void
    {
        $this->filesystem->dumpFile($this->directory.'/source/database/dreeve.db', 'database');
        for ($i = 0; $i < 20; ++$i) {
            $this->filesystem->dumpFile($this->directory.'/source/files/activity-'.$i.'.json', 'activity '.$i);
        }
        new Process([
            'tar', '--zstd', '-cf', $this->directory.'/archive.tar.zst', '-C', $this->directory.'/source', 'database', 'files',
        ])->mustRun();

        $archiver = new TarZstArchiver();
        $this->assertEquals(['database', 'files'], $archiver->listRootEntries($this->directory.'/archive.tar.zst'));

        $archiver->extract($this->directory.'/archive.tar.zst', $this->directory.'/target', ['database/dreeve.db']);
        $this->assertEquals(['database/dreeve.db'], $this->findFiles($this->directory.'/target'));

        $archiver->extract($this->directory.'/archive.tar.zst', $this->directory.'/target', ['files']);
        $this->assertCount(21, $this->findFiles($this->directory.'/target'));
    }

    public function testListRootEntriesOfAnInvalidArchive(): void
    {
        $this->filesystem->dumpFile($this->directory.'/archive.tar.zst', 'not an archive');

        $this->expectException(CouldNotProcessArchive::class);
        $this->expectExceptionMessage('is not a valid archive');

        new TarZstArchiver()->listRootEntries($this->directory.'/archive.tar.zst');
    }

    public function testCreateInADirectoryThatDoesNotExist(): void
    {
        $this->filesystem->dumpFile($this->directory.'/source/files/activity.json', 'activity');

        $this->expectException(CouldNotProcessArchive::class);
        $this->expectExceptionMessage('Could not write');

        new TarZstArchiver()->create($this->directory.'/missing/archive.tar.zst', [$this->directory.'/source/files']);
    }

    /**
     * @return list<string>
     */
    private function findFiles(string $directory): array
    {
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen($directory) + 1);
        }
        sort($files);

        return $files;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->directory = sys_get_temp_dir().'/'.uniqid('archive-', true);
        $this->filesystem->mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove($this->directory);
    }
}
