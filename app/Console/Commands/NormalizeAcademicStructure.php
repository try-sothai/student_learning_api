<?php

namespace App\Console\Commands;

use App\Services\AcademicStructureNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class NormalizeAcademicStructure extends Command
{
    protected $signature = 'slas:normalize-academic-structure
        {--apply : Apply the changes (default is a dry run that rolls back)}
        {--no-backup : Skip the mysqldump backup (never use on production data)}
        {--mysqldump= : Full path to mysqldump (defaults to "mysqldump" on PATH)}';

    protected $description = 'Move Majors that were stored as Faculties into the programs table, re-point relations and remove the misclassified rows (backup + single transaction).';

    public function handle(AcademicStructureNormalizer $normalizer): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->option('no-backup')) {
            $backup = $this->backup();
            if ($backup === null) {
                $this->error('Backup failed - aborting. Nothing was changed.');

                return self::FAILURE;
            }
            $this->info("Backup written: {$backup}");
        }

        $report = $normalizer->run(! $apply);

        $this->line($apply ? '== APPLIED ==' : '== DRY RUN (rolled back) ==');
        foreach (['faculties', 'majors', 'courses_linked', 'repointed', 'deactivated_majors', 'deleted_departments'] as $section) {
            $this->line(strtoupper($section) . ': ' . json_encode($report[$section], JSON_UNESCAPED_UNICODE));
        }
        $this->line('BEFORE: ' . json_encode($report['before']));
        $this->line('AFTER : ' . json_encode($report['after']));
        foreach ($report['warnings'] as $w) {
            $this->warn('WARNING: ' . $w);
        }

        $path = database_path('backups/academic_structure_report_' . now()->format('Ymd_His') . ($apply ? '_applied' : '_dryrun') . '.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Report saved: {$path}");

        return self::SUCCESS;
    }

    private function backup(): ?string
    {
        $conn = config('database.default');
        $cfg = config("database.connections.{$conn}");
        if (($cfg['driver'] ?? '') !== 'mysql') {
            $this->warn('Backup skipped: not a MySQL connection.');

            return 'skipped (non-mysql)';
        }

        $dir = database_path('backups');
        @mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . 'slas_pre_academic_structure_' . now()->format('Ymd_His') . '.sql';

        $process = new Process([
            $this->option('mysqldump') ?: 'mysqldump',
            '--host=' . $cfg['host'],
            '--port=' . $cfg['port'],
            '--user=' . $cfg['username'],
            '--single-transaction',
            '--routines',
            '--default-character-set=utf8mb4',
            '--result-file=' . $file,
            $cfg['database'],
        ], null, ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($file) || filesize($file) < 1000) {
            $this->error(trim($process->getErrorOutput()));

            return null;
        }

        return $file;
    }
}
