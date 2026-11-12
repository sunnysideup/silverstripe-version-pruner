<?php

namespace Sunnysideup\VersionPruner\Tasks;

use Override;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class PruneAllVersionedRecordsBasic extends BuildTask
{
    /**
     * Number of RecordIDs to process per batch.
     * @var int
     */
    private static int $batch_size = 1000;

    /**
     * Maximum number of batches to run per table per execution.
     * Set to 0 for unlimited.
     * @var int
     */
    private static int $max_batches = 100;

    /**
     * Minimum number of most-recent versions to keep per record,
     * regardless of age. These are NEVER deleted.
     * @var int
     */
    private static int $keep_versions = 10;

    /**
     * @var string
     */
    private static string $delete_older_than_strtotime_phrase = '-12 months';

    /**
     * Base classes to skip entirely, e.g. if you do not want to prune File
     * or elemental block history. List by fully-qualified class name, e.g.
     *   - SilverStripe\Assets\File
     *   - DNADesign\Elemental\Models\BaseElement
     * @var array
     */
    private static array $exclude_base_classes = [];

    protected string $title = 'Basic Prune of Older Records for ALL Versioned Classes (Batched)';

    protected static string $description = 'Keeps the most recent versions of every record of every versioned class and deletes the older ones (batched). CLI only. Never deletes the current draft or live version. See the options for batch_size, max_batches and keep_versions; config: delete_older_than_strtotime_phrase, exclude_base_classes. Requires MySQL 8.0+ / MariaDB 10.2+ (ROW_NUMBER).';

    protected static string $commandName = 'prune-all-versioned-records-sitetree-basic';

    protected PolyOutput $polyOutput;

    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption('batch_size', null, InputOption::VALUE_REQUIRED, 'Number of RecordIDs to process per batch (default from config)'),
            new InputOption('max_batches', null, InputOption::VALUE_REQUIRED, 'Maximum number of batches per table per run, 0 = unlimited (default from config)'),
            new InputOption('keep_versions', null, InputOption::VALUE_REQUIRED, 'Minimum number of most-recent versions to keep per record, minimum 1 (default from config)'),
        ];
    }

    /**
     * Prune old versions for every versioned class, in batches.
     *
     * For each versioned hierarchy (base data class with the Versioned
     * extension):
     * 1. In the base _Versions table, delete version rows that are ALL of:
     *      - not within the most-recent {keep_versions} for that record
     *      - older than the cutoff date
     *      - not the current draft version (base table .Version)
     *      - not the current live version (base table _Live .Version), when a
     *        _Live table exists (staged versioning)
     * 2. For each subclass table, remove rows whose RecordID+Version no longer
     *    exists in the base _Versions table (orphan cleanup).
     *
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->polyOutput = $output;

        // this task deletes version records: command line only.
        if (! Director::is_cli()) {
            $output->writeln('This task can only be run on the command line.');

            return Command::FAILURE;
        }

        $batchSize = (int) $this->config()->get('batch_size');
        $maxBatches = (int) $this->config()->get('max_batches');
        $keepVersions = (int) $this->config()->get('keep_versions');

        if ($input->getOption('batch_size')) {
            $batchSize = max(1, (int) $input->getOption('batch_size'));
        }

        if ($input->getOption('max_batches') !== null && $input->getOption('max_batches') !== '') {
            $maxBatches = max(0, (int) $input->getOption('max_batches'));
        }

        if ($input->getOption('keep_versions') !== null && $input->getOption('keep_versions') !== '') {
            $keepVersions = (int) $input->getOption('keep_versions');
        }

        // Safety floor: never allow the "keep" window to drop below 1, or a
        // record whose whole history predates the cutoff could be wiped out.
        if ($keepVersions < 1) {
            $keepVersions = 1;
        }

        $beforeDate = date('Y-m-d H:i:s', strtotime($this->config()->get('delete_older_than_strtotime_phrase')));

        $this->say("Settings: batch_size={$batchSize}, max_batches={$maxBatches}, keep_versions={$keepVersions}", 'created');
        $this->say("Keeping last {$keepVersions} versions per record; of the rest, deleting versions older than {$beforeDate}", 'created');

        $hierarchies = $this->getVersionedHierarchies();

        if (empty($hierarchies)) {
            $this->say('No versioned hierarchies found. Nothing to do.');

            return Command::SUCCESS;
        }

        $this->say('Found ' . count($hierarchies) . ' versioned hierarch' . (count($hierarchies) === 1 ? 'y' : 'ies') . ' to process.', 'created');

        $grandTotalDeleted = 0;

        foreach ($hierarchies as $info) {
            $baseClass = $info['baseClass'];
            $versionsTable = $info['versionsTable'];
            $draftTable = $info['draftTable'];
            $liveTable = $info['liveTable'];
            $subclassVersionTables = $info['subclassVersionTables'];

            $stagingNote = $liveTable ? 'staged (draft + live protected)' : 'unstaged (draft protected)';
            $this->say("=== {$baseClass} [{$versionsTable}] — {$stagingNote} ===", 'created');

            $before = DB::query("SELECT COUNT(*) FROM \"{$versionsTable}\"")->value();
            $oldest = DB::query("SELECT MIN(\"LastEdited\") FROM \"{$versionsTable}\"")->value();
            $newest = DB::query("SELECT MAX(\"LastEdited\") FROM \"{$versionsTable}\"")->value();
            $this->say("  BEFORE: {$before} versions, oldest: {$oldest}, newest: {$newest}");

            $deletedForHierarchy = 0;

            // Step 1: Delete eligible versions from the base _Versions table
            $deletedForHierarchy += $this->deleteVersionsInBatches(
                $versionsTable,
                $draftTable,
                $liveTable,
                $beforeDate,
                $keepVersions,
                $batchSize,
                $maxBatches
            );

            // Step 2: Clean up orphaned records in subclass tables
            foreach ($subclassVersionTables as $subclassVersionTable) {
                $deletedForHierarchy += $this->deleteOrphanedSubclassVersions(
                    $subclassVersionTable,
                    $versionsTable,
                    $batchSize,
                    $maxBatches
                );
            }

            $after = DB::query("SELECT COUNT(*) FROM \"{$versionsTable}\"")->value();
            $this->say("  AFTER: {$after} versions. Deleted this hierarchy: {$deletedForHierarchy}", 'changed');

            $grandTotalDeleted += $deletedForHierarchy;
        }

        $this->say("Total deleted this run (all hierarchies): {$grandTotalDeleted}", 'created');

        if ($grandTotalDeleted > 0) {
            $this->say('Run again to continue pruning.');
        }

        return Command::SUCCESS;
    }

    /**
     * Write a line to the task output.
     */
    private function say(string $message, string $type = ''): void
    {
        $this->polyOutput->writeln($message);
    }

    /**
     * Build the list of versioned hierarchies to process.
     *
     * Reduces every Versioned-extended class to its base data class, so an
     * entire hierarchy (SiteTree/Page/RedirectorPage/...) collapses to one
     * entry keyed on the base table (SiteTree_Versions). Only hierarchies whose
     * tables actually exist in the database are returned.
     *
     * @return array<string, array>
     */
    protected function getVersionedHierarchies(): array
    {
        $schema = DataObject::getSchema();
        $tables = DB::table_list(); // keyed by lowercase table name
        $excluded = (array) $this->config()->get('exclude_base_classes');
        $excludedLower = array_map('strtolower', $excluded);

        // Collect distinct versioned base classes.
        $baseClasses = [];
        foreach (ClassInfo::subclassesFor(DataObject::class) as $class) {
            if ($class === DataObject::class) {
                continue;
            }
            if (!$class::has_extension(Versioned::class)) {
                continue;
            }
            $baseClass = $schema->baseDataClass($class);
            $baseClasses[$baseClass] = $baseClass;
        }

        $result = [];
        foreach ($baseClasses as $baseClass) {
            if (in_array(strtolower($baseClass), $excludedLower, true)) {
                $this->say("  Skipping excluded base class {$baseClass}.");
                continue;
            }
            if (!$schema->classHasTable($baseClass)) {
                continue;
            }

            $baseTable = $schema->tableName($baseClass);
            $versionsTable = $baseTable . '_Versions';

            // The base _Versions table must exist.
            if (!isset($tables[strtolower($versionsTable)])) {
                continue;
            }

            // The draft/stage table is the base table itself; it must exist.
            $draftTable = $baseTable;
            if (!isset($tables[strtolower($draftTable)])) {
                continue;
            }

            // The live table only exists for staged versioning.
            $liveTable = $baseTable . '_Live';
            if (!isset($tables[strtolower($liveTable)])) {
                $liveTable = null;
            }

            // Subclass _Versions tables (table-split children of the base).
            $subclassVersionTables = [];
            foreach (ClassInfo::subclassesFor($baseClass) as $subClass) {
                if ($subClass === $baseClass) {
                    continue;
                }
                if (!$schema->classHasTable($subClass)) {
                    continue;
                }
                $subTable = $schema->tableName($subClass) . '_Versions';
                if (isset($tables[strtolower($subTable)])) {
                    $subclassVersionTables[$subTable] = $subTable;
                }
            }

            $result[$baseClass] = [
                'baseClass' => $baseClass,
                'baseTable' => $baseTable,
                'versionsTable' => $versionsTable,
                'draftTable' => $draftTable,
                'liveTable' => $liveTable,
                'subclassVersionTables' => array_values($subclassVersionTables),
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * Delete eligible rows from a base _Versions table, by RecordID batches.
     *
     * A version row is deleted only when it is ALL of:
     *   - ranked beyond the most-recent {$keepVersions} for its record
     *   - older than {$beforeDate}
     *   - NOT the current draft version (referenced by {$draftTable}.Version)
     *   - NOT the current live version (referenced by {$liveTable}.Version),
     *     when a live table is supplied
     *
     * NOTE: deleting by the versions table's own ID here is safe/correct
     * because those IDs are read straight out of the same table in the SELECT.
     * The cross-hierarchy link (to subclass tables) is still RecordID+Version,
     * handled by the orphan-cleanup step.
     *
     * Requires window functions: MySQL 8.0+ or MariaDB 10.2+.
     */
    protected function deleteVersionsInBatches(
        string $versionsTable,
        string $draftTable,
        ?string $liveTable,
        string $beforeDate,
        int $keepVersions,
        int $batchSize,
        int $maxBatches
    ): int {
        $totalDeleted = 0;
        $batchCount = 0;
        $lastRecordId = 0;

        // Optional live-version guard (only for staged versioning).
        $liveClause = '';
        if ($liveTable !== null) {
            $liveClause = "
                  AND NOT EXISTS (
                      SELECT 1 FROM \"{$liveTable}\" sl
                      WHERE sl.\"ID\" = v.\"RecordID\"
                        AND sl.\"Version\" = v.\"Version\"
                  )";
        }

        $this->say("  Processing {$versionsTable} (keep last {$keepVersions}, delete older than {$beforeDate})...");

        while (true) {
            if ($maxBatches > 0 && $batchCount >= $maxBatches) {
                $this->say("    Reached max batches ({$maxBatches}). Stopping.", 'changed');
                break;
            }

            // Next batch of distinct RecordIDs (keyset pagination on RecordID).
            $recordIdsSql = "
                SELECT DISTINCT \"RecordID\"
                FROM \"{$versionsTable}\"
                WHERE \"RecordID\" > {$lastRecordId}
                ORDER BY \"RecordID\" ASC
                LIMIT {$batchSize}
            ";
            $recordIds = [];
            foreach (DB::query($recordIdsSql) as $row) {
                $recordIds[] = (int) $row['RecordID'];
            }

            if (empty($recordIds)) {
                $this->say("    No more RecordIDs to process in {$versionsTable}.");
                break;
            }

            $lastRecordId = max($recordIds);
            $recordIdList = implode(',', $recordIds); // ints only - safe

            // Identify deletable version-row IDs for this batch of records.
            // ROW_NUMBER() ranks the full history per record (rn = 1 is newest),
            // so gaps in Version numbers don't matter.
            $selectSql = "
                SELECT v.\"ID\"
                FROM (
                    SELECT
                        \"ID\",
                        \"RecordID\",
                        \"Version\",
                        \"LastEdited\",
                        ROW_NUMBER() OVER (
                            PARTITION BY \"RecordID\"
                            ORDER BY \"Version\" DESC
                        ) AS rn
                    FROM \"{$versionsTable}\"
                    WHERE \"RecordID\" IN ({$recordIdList})
                ) v
                WHERE v.rn > {$keepVersions}
                  AND v.\"LastEdited\" < '{$beforeDate}'
                  AND NOT EXISTS (
                      SELECT 1 FROM \"{$draftTable}\" d
                      WHERE d.\"ID\" = v.\"RecordID\"
                        AND d.\"Version\" = v.\"Version\"
                  ){$liveClause}
            ";

            $idsToDelete = [];
            foreach (DB::query($selectSql) as $row) {
                $idsToDelete[] = (int) $row['ID'];
            }

            if (empty($idsToDelete)) {
                $batchCount++;
                continue;
            }

            // Delete in sub-chunks to keep the IN() clause a sane size.
            $deleted = 0;
            foreach (array_chunk($idsToDelete, 500) as $chunk) {
                $idList = implode(',', $chunk); // ints only - safe
                DB::query("DELETE FROM \"{$versionsTable}\" WHERE \"ID\" IN ({$idList})");
                $deleted += DB::affected_rows();
            }

            $totalDeleted += $deleted;
            $batchCount++;

            $this->say("    Batch {$batchCount}: Deleted {$deleted} versions for RecordIDs {$recordIds[0]}-{$lastRecordId} (total: {$totalDeleted})");

            // Free per-batch arrays before the next iteration to keep peak memory low.
            unset($idsToDelete, $recordIds);

            if (function_exists('flush')) {
                flush();
            }
        }

        return $totalDeleted;
    }

    /**
     * Delete orphaned records from a subclass version table.
     *
     * Strategy: Process by RecordID batches.
     * For each batch of RecordIDs in the subclass table:
     * 1. Get all RecordID+Version combos from subclass table for those RecordIDs
     * 2. Check which ones still exist in the base _Versions table
     * 3. Delete the ones that don't exist
     */
    protected function deleteOrphanedSubclassVersions(string $tableName, string $baseVersionsTable, int $batchSize, int $maxBatches): int
    {
        // Check if table exists
        $tables = DB::table_list();
        $tableNameLower = strtolower($tableName);
        if (!isset($tables[$tableNameLower])) {
            $this->say("  Table {$tableName} does not exist, skipping.");
            return 0;
        }

        $totalDeleted = 0;
        $batchCount = 0;
        $lastRecordId = 0;

        $this->say("  Processing {$tableName} (removing orphans)...");

        while (true) {
            if ($maxBatches > 0 && $batchCount >= $maxBatches) {
                $this->say("    Reached max batches ({$maxBatches}). Stopping.", 'changed');
                break;
            }

            // Get next batch of distinct RecordIDs from subclass table
            $recordIdsSql = "
                SELECT DISTINCT \"RecordID\"
                FROM \"{$tableName}\"
                WHERE \"RecordID\" > {$lastRecordId}
                ORDER BY \"RecordID\" ASC
                LIMIT {$batchSize}
            ";

            $rows = DB::query($recordIdsSql);
            $recordIds = [];
            foreach ($rows as $row) {
                $recordIds[] = (int) $row['RecordID'];
            }

            if (empty($recordIds)) {
                $this->say("    No more RecordIDs to process in {$tableName}.");
                break;
            }

            $lastRecordId = max($recordIds);
            $recordIdList = implode(',', $recordIds);

            // Get all RecordID+Version combos from subclass table for these RecordIDs
            $subclassCombosSql = "
                SELECT \"RecordID\", \"Version\"
                FROM \"{$tableName}\"
                WHERE \"RecordID\" IN ({$recordIdList})
            ";
            $subclassCombos = [];
            foreach (DB::query($subclassCombosSql) as $row) {
                $key = $row['RecordID'] . '-' . $row['Version'];
                $subclassCombos[$key] = [
                    'RecordID' => (int) $row['RecordID'],
                    'Version' => (int) $row['Version'],
                ];
            }

            if (empty($subclassCombos)) {
                $batchCount++;
                continue;
            }

            // Get all RecordID+Version combos that EXIST in the base _Versions table
            $baseCombosSql = "
                SELECT \"RecordID\", \"Version\"
                FROM \"{$baseVersionsTable}\"
                WHERE \"RecordID\" IN ({$recordIdList})
            ";
            $validCombos = [];
            foreach (DB::query($baseCombosSql) as $row) {
                $key = $row['RecordID'] . '-' . $row['Version'];
                $validCombos[$key] = true;
            }

            // Find orphans: combos in subclass but NOT in the base _Versions table
            $orphans = [];
            foreach ($subclassCombos as $key => $combo) {
                if (!isset($validCombos[$key])) {
                    $orphans[] = $combo;
                }
            }

            if (empty($orphans)) {
                $batchCount++;
                continue;
            }

            // Delete orphans in smaller sub-batches to avoid huge IN clauses
            $deleteSubBatchSize = 500;
            $orphanChunks = array_chunk($orphans, $deleteSubBatchSize);

            foreach ($orphanChunks as $chunk) {
                $conditions = [];
                foreach ($chunk as $orphan) {
                    $conditions[] = "(\"RecordID\" = {$orphan['RecordID']} AND \"Version\" = {$orphan['Version']})";
                }
                $whereClause = implode(' OR ', $conditions);

                $deleteSql = "DELETE FROM \"{$tableName}\" WHERE {$whereClause}";
                DB::query($deleteSql);
                $affected = DB::affected_rows();
                $totalDeleted += $affected;
            }

            $batchCount++;
            $this->say("    Batch {$batchCount}: Deleted " . count($orphans) . " orphans for RecordIDs {$recordIds[0]}-{$lastRecordId} (total: {$totalDeleted})");

            // Free per-batch arrays before the next iteration to keep peak memory low.
            unset($orphans, $subclassCombos, $validCombos, $recordIds);

            if (function_exists('flush')) {
                flush();
            }
        }

        return $totalDeleted;
    }
}
