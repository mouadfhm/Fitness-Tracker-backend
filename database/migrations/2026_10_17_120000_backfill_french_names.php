<?php

use App\Support\FoodNameCleaner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills in French names for foods, activities and gym exercises that were
 * seeded before translations existed. The seeders skip rows that are already
 * there, and the migrations that added name_fr only added the column, so on a
 * database seeded earlier every display_name stayed English.
 *
 * Old food rows are named after the raw USDA description ("BUTTER,WITH
 * SALT"), which is the translated CSV's source_name; they also get the
 * cleaned English name ("Butter (With Salt)") in name_en. Only empty fields
 * are filled, so translations already present or edited by hand stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillFoods();
        $this->backfillNames('exercises', 'exercises_translated.csv');
        $this->backfillNames('gym_exercises', 'gym_exercises_translated.csv');
    }

    public function down(): void
    {
        // Nothing to undo: only empty fields were filled.
    }

    private function backfillFoods(): void
    {
        $rows = $this->readCsv('foods_translated.csv');
        if ($rows === []) {
            return;
        }

        // Lowercase name => [source_name, name_fr]. Old rows may carry the
        // USDA description, the CSV's English name or the cleaned name.
        $byName = [];
        foreach ($rows as $row) {
            $source = $row['source_name'] ?? '';
            $nameFr = $row['name_fr'] ?? '';
            if ($source === '' || $nameFr === '') {
                continue;
            }
            $entry = [$source, $nameFr];
            foreach ([$source, $row['name_en'] ?? '', FoodNameCleaner::clean($source)] as $name) {
                if ($name !== '') {
                    $byName[mb_strtolower($name)] ??= $entry;
                }
            }
        }

        DB::table('foods')
            ->whereNull('added_by')
            ->whereNull('name_fr')
            ->select('id', 'name', 'name_en', 'source_name')
            ->orderBy('id')
            ->chunkById(500, function ($foods) use ($byName) {
                foreach ($foods as $food) {
                    $match = $byName[mb_strtolower($food->name)]
                        ?? ($food->name_en !== null ? $byName[mb_strtolower($food->name_en)] ?? null : null);
                    if ($match === null) {
                        continue;
                    }

                    [$source, $nameFr] = $match;
                    $changes = ['name_fr' => $nameFr];
                    if ($food->source_name === null) {
                        $changes['source_name'] = $source;
                    }
                    if ($food->name_en === null) {
                        $changes['name_en'] = FoodNameCleaner::clean($source) ?: $food->name;
                    }

                    DB::table('foods')->where('id', $food->id)->update($changes);
                }
            });
    }

    private function backfillNames(string $table, string $file): void
    {
        $translations = [];
        foreach ($this->readCsv($file, false) as $fields) {
            if (($fields[0] ?? '') !== '' && ($fields[1] ?? '') !== '') {
                $translations[mb_strtolower($fields[0])] ??= $fields[1];
            }
        }
        if ($translations === []) {
            return;
        }

        DB::table($table)
            ->whereNull('name_fr')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($table, $translations) {
                foreach ($items as $item) {
                    $nameFr = $translations[mb_strtolower(trim((string) $item->name))] ?? null;
                    if ($nameFr !== null) {
                        DB::table($table)->where('id', $item->id)->update(['name_fr' => $nameFr]);
                    }
                }
            });
    }

    /**
     * Rows of database/data/$file after its header, keyed by header name
     * when $assoc is true. Missing file: no rows.
     */
    private function readCsv(string $file, bool $assoc = true): array
    {
        $path = database_path("data/{$file}");
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle) ?: [];
        $rows = [];
        while (($fields = fgetcsv($handle)) !== false) {
            $rows[] = $assoc && count($fields) === count($header)
                ? array_combine($header, $fields)
                : $fields;
        }
        fclose($handle);

        return $rows;
    }
};
