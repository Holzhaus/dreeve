<?php

namespace App\Tests\Console\Backup;

use App\Application\AppName;
use App\Application\AppVersion;
use App\Console\Backup\CreateBackupConsoleCommand;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Backup\AvailableBackups;
use App\Infrastructure\Archive\Archiver;
use App\Infrastructure\Archive\CouldNotProcessArchive;
use App\Infrastructure\Archive\TarZstArchiver;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Console\ConsoleCommandTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class CreateBackupConsoleCommandTest extends ConsoleCommandTestCase
{
    private Connection&MockObject $connection;
    private Archiver $archiver;
    private Filesystem $filesystem;
    private string $projectDirectory;

    public function testExecute(): void
    {
        $this->filesystem->dumpFile($this->projectDirectory.'/storage/database/dreeve.db', 'live database');
        $this->filesystem->dumpFile($this->projectDirectory.'/storage/database/dreeve.db-wal', 'live wal');
        $this->filesystem->dumpFile($this->projectDirectory.'/storage/files/activities/activity-1.json', 'activity');
        $this->filesystem->dumpFile($this->projectDirectory.'/storage/files/logs/daemon.log', 'log');
        $this->filesystem->dumpFile($this->projectDirectory.'/storage/gear-maintenance/config.yaml', 'gear');
        $this->filesystem->dumpFile($this->projectDirectory.'/backups/dreeve-2026-11-06-030000.tar.zst', 'oldest');
        $this->filesystem->dumpFile($this->projectDirectory.'/backups/dreeve-2026-11-06-030000.json', '{"appVersion": "v5.8.1", "numberOfActivities": 1, "firstActivityStartDate": "2015-03-01", "lastActivityStartDate": "2015-03-01"}');
        $this->filesystem->dumpFile($this->projectDirectory.'/backups/dreeve-2026-11-07-030000.tar.zst', 'older');

        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withStartDateTime(SerializableDateTime::fromString('2015-03-01 10:00:00'))
                ->build(),
            rawData: [],
        ));
        $activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('2'))
                ->withStartDateTime(SerializableDateTime::fromString('2026-11-07 18:00:00'))
                ->build(),
            rawData: [],
        ));

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('VACUUM INTO ?', [$this->projectDirectory.'/backups/dreeve-2026-11-08-030000/database/dreeve.db'])
            ->willReturnCallback(function (string $sql, array $params): int {
                file_put_contents($params[0], 'database snapshot');

                return 0;
            });

        $command = $this->getCommandInApplication(CreateBackupConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
            '--'.CreateBackupConsoleCommand::KEEP_OPTION => 2,
        ]);

        $commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString(AppName::LABEL.' '.AppVersion::getSemanticVersion(), $commandTester->getDisplay());
        $this->assertStringContainsString('The backups directory is not mounted as a volume', $commandTester->getDisplay());
        $this->assertStringContainsString('=> Creating a snapshot of the database', $commandTester->getDisplay());
        $this->assertStringContainsString('=> Archiving storage, this can take a while', $commandTester->getDisplay());
        $this->assertStringContainsString('=> Deleted old backup "backups/dreeve-2026-11-06-030000.tar.zst"', $commandTester->getDisplay());
        $this->assertMatchesRegularExpression('/=> Created backup "backups\/dreeve-2026-11-08-030000.tar.zst" \(\d+\.\d MB\)/', $commandTester->getDisplay());
        $this->assertEquals(
            ['dreeve-2026-11-07-030000.tar.zst', 'dreeve-2026-11-08-030000.json', 'dreeve-2026-11-08-030000.tar.zst'],
            array_values(array_diff(scandir($this->projectDirectory.'/backups') ?: [], ['.', '..'])),
        );
        $this->assertJsonStringEqualsJsonString(
            sprintf('{"appVersion": "%s", "numberOfActivities": 2, "firstActivityStartDate": "2015-03-01", "lastActivityStartDate": "2026-11-07"}', AppVersion::getSemanticVersion()),
            (string) file_get_contents($this->projectDirectory.'/backups/dreeve-2026-11-08-030000.json'),
        );

        $extractedTarPath = $this->projectDirectory.'/extracted.tar';
        file_put_contents($extractedTarPath, zstd_uncompress((string) file_get_contents($this->projectDirectory.'/backups/dreeve-2026-11-08-030000.tar.zst')));
        $archivedFiles = [];
        foreach (new \RecursiveIteratorIterator(new \PharData($extractedTarPath)) as $archivedFile) {
            /* @var \PharFileInfo $archivedFile */
            $archivedFiles[substr($archivedFile->getPathname(), strlen('phar://'.$extractedTarPath) + 1)] = $archivedFile->getContent();
        }
        ksort($archivedFiles);

        $this->assertEquals(
            [
                'database/dreeve.db' => 'database snapshot',
                'files/activities/activity-1.json' => 'activity',
                'gear-maintenance/config.yaml' => 'gear',
            ],
            $archivedFiles,
        );
    }

    public function testExecuteWhenArchivingFails(): void
    {
        $this->filesystem->dumpFile($this->projectDirectory.'/storage/files/activities/activity-1.json', 'activity');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params): int {
                file_put_contents($params[0], 'database snapshot');

                return 0;
            });

        $partialBackupPath = $this->projectDirectory.'/backups/dreeve-2026-11-08-030000.tar.zst.partial';
        $archiver = $this->createMock(Archiver::class);
        $archiver
            ->expects($this->once())
            ->method('create')
            ->with(
                $partialBackupPath,
                [
                    $this->projectDirectory.'/backups/dreeve-2026-11-08-030000/database',
                    $this->projectDirectory.'/storage/files',
                ],
                [$this->projectDirectory.'/storage/files/logs'],
            )
            ->willReturnCallback(function () use ($partialBackupPath): never {
                file_put_contents($partialBackupPath, 'partial backup');

                throw new CouldNotProcessArchive('Could not add "files": No space left on device');
            });
        $this->archiver = $archiver;

        $command = $this->getCommandInApplication(CreateBackupConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertEquals(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('The backups directory is not mounted as a volume', $commandTester->getDisplay());
        $this->assertStringContainsString('Could not create backup:', $commandTester->getDisplay());
        $this->assertStringContainsString('Could not add "files": No space left on device', $commandTester->getDisplay());
        $this->assertEquals([], array_values(array_diff(scandir($this->projectDirectory.'/backups') ?: [], ['.', '..'])));
    }

    public function testExecuteWithAnInvalidKeepOption(): void
    {
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        $command = $this->getCommandInApplication(CreateBackupConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
            '--'.CreateBackupConsoleCommand::KEEP_OPTION => 0,
        ]);

        $this->assertEquals(Command::INVALID, $commandTester->getStatusCode());
        $this->assertStringContainsString('The --keep option must be a positive number', $commandTester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->projectDirectory.'/backups');
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);
        $this->archiver = new TarZstArchiver();
        $this->filesystem = new Filesystem();
        $this->projectDirectory = sys_get_temp_dir().'/'.uniqid('backup-', true);
        $this->filesystem->mkdir($this->projectDirectory.'/storage');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDirectory);

        parent::tearDown();
    }

    #[\Override]
    protected function getConsoleCommand(): Command
    {
        return new CreateBackupConsoleCommand(
            connection: $this->connection,
            kernelProjectDir: KernelProjectDir::fromString($this->projectDirectory),
            clock: PausedClock::fromString('2026-11-08 03:00:00'),
            archiver: $this->archiver,
            availableBackups: new AvailableBackups(
                defaultStorage: new Flysystem(new LocalFilesystemAdapter($this->projectDirectory)),
                kernelProjectDir: KernelProjectDir::fromString($this->projectDirectory),
                queryBus: $this->getContainer()->get(QueryBus::class),
            ),
        );
    }
}
