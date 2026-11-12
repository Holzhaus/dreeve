<?php

declare(strict_types=1);

namespace App\Console\Backup;

use App\Domain\Backup\AvailableBackups;
use App\Infrastructure\Archive\Archiver;
use App\Infrastructure\Archive\CouldNotProcessArchive;
use App\Infrastructure\Console\ProvideConsoleIntro;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: CreateBackupConsoleCommand::NAME, description: 'Create a local backup of the database and storage files')]
final class CreateBackupConsoleCommand extends Command
{
    use ProvideConsoleIntro;

    public const string NAME = 'app:backup:create';
    public const string KEEP_OPTION = 'keep';
    private const int DEFAULT_KEEP = 7;
    private const string BACKUP_DIRECTORY = 'backups';
    private const string DATABASE_DIRECTORY = 'database';

    public function __construct(
        private readonly Connection $connection,
        private readonly KernelProjectDir $kernelProjectDir,
        private readonly Clock $clock,
        private readonly Archiver $archiver,
        private readonly AvailableBackups $availableBackups,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            name: self::KEEP_OPTION,
            mode: InputOption::VALUE_REQUIRED,
            description: 'The number of backups to keep',
            default: self::DEFAULT_KEEP,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, $output);
        $this->outputConsoleIntro($output);

        $keep = filter_var($input->getOption(self::KEEP_OPTION), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $keep) {
            $output->error(sprintf('The --%s option must be a positive number', self::KEEP_OPTION));

            return Command::INVALID;
        }

        $storageDirectory = $this->kernelProjectDir.'/storage';
        $backupDirectory = $this->kernelProjectDir.'/'.self::BACKUP_DIRECTORY;

        if (!$this->availableBackups->isOnMountedVolume()) {
            $output->warning('The backups directory is not mounted as a volume. Backups will be lost when the container is recreated. See https://docs.dreeve.app/maintenance/backup-and-restore/#mount-the-backups-directory');
        }
        if (!is_dir($backupDirectory)) {
            mkdir($backupDirectory, 0755, true);
        }

        $backupName = 'dreeve-'.$this->clock->now()->format('Y-m-d-His');
        $stagingDirectory = $backupDirectory.'/'.$backupName;
        $databaseSnapshotPath = $stagingDirectory.'/'.self::DATABASE_DIRECTORY.'/dreeve.db';
        $backupPath = $stagingDirectory.'.tar.zst';
        $partialBackupPath = $backupPath.'.partial';
        mkdir(dirname($databaseSnapshotPath), 0755, true);

        try {
            $output->writeln('=> Creating a snapshot of the database');
            $this->connection->executeStatement('VACUUM INTO ?', [$databaseSnapshotPath]);

            $storageEntries = array_values(array_diff(scandir($storageDirectory) ?: [], ['.', '..', self::DATABASE_DIRECTORY]));
            $output->writeln('=> Archiving storage, this can take a while');
            try {
                $this->archiver->create(
                    archivePath: $partialBackupPath,
                    paths: [
                        $stagingDirectory.'/'.self::DATABASE_DIRECTORY,
                        ...array_map(static fn (string $entry): string => $storageDirectory.'/'.$entry, $storageEntries),
                    ],
                    excludedPaths: [$storageDirectory.'/files/logs'],
                );
            } catch (CouldNotProcessArchive $exception) {
                if (file_exists($partialBackupPath)) {
                    unlink($partialBackupPath);
                }
                $output->error(sprintf("Could not create backup:\n%s", $exception->getMessage()));

                return Command::FAILURE;
            }

            rename($partialBackupPath, $backupPath);
        } finally {
            if (file_exists($databaseSnapshotPath)) {
                unlink($databaseSnapshotPath);
            }
            rmdir(dirname($databaseSnapshotPath));
            rmdir($stagingDirectory);
        }

        $backups = $this->availableBackups->findAll();
        $createdBackup = $backups[count($backups) - 1];
        $this->availableBackups->writeManifest($createdBackup);
        foreach (array_slice($backups, 0, max(0, count($backups) - $keep)) as $oldBackup) {
            $this->availableBackups->delete($oldBackup);
            $output->writeln(sprintf('=> Deleted old backup "%s"', self::BACKUP_DIRECTORY.'/'.$oldBackup->getFilename()));
        }

        $output->writeln(sprintf(
            '=> Created backup "%s" (%.1f MB)',
            self::BACKUP_DIRECTORY.'/'.$createdBackup->getFilename(),
            $createdBackup->getSizeInMegaBytes(),
        ));

        return Command::SUCCESS;
    }
}
