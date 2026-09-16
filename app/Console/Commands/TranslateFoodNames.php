<?php

namespace App\Console\Commands;

use App\Support\FoodNameCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TranslateFoodNames extends Command
{
    protected $signature = 'foods:translate
        {--source=ABBREV.csv : CSV file under database/data to read}
        {--output=foods_translated.csv : CSV file under database/data to write}
        {--batch=50 : Names sent per DeepL request}
        {--limit=0 : Only process the first N rows (0 = all), useful for a dry run}';

    protected $description = 'Clean raw USDA food names and translate them to French via DeepL, writing a static CSV for the seeder to read';

    public function handle(): int
    {
        $apiKey = config('services.deepl.key');

        if (empty($apiKey)) {
            $this->error('DEEPL_API_KEY is not set in .env');

            return self::FAILURE;
        }

        $sourcePath = database_path('data/' . $this->option('source'));
        $outputPath = database_path('data/' . $this->option('output'));

        if (!is_file($sourcePath)) {
            $this->error("Source file not found: {$sourcePath}");

            return self::FAILURE;
        }

        // Reuse translations from a previous run (keyed by the cleaned English
        // name) so re-running this command — e.g. after adding nutrition
        // columns — doesn't re-spend DeepL quota on names we already have.
        $cache = $this->loadTranslationCache($outputPath);

        $rows = $this->readAbbrevCsv($sourcePath);
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $this->info(sprintf('Read %d rows, cleaning names...', count($rows)));

        $cleaned = [];
        $seen = [];
        foreach ($rows as $row) {
            $nameEn = FoodNameCleaner::clean($row['source_name']);

            if ($nameEn === '') {
                continue;
            }

            // Dedupe on the cleaned name so we don't pay to translate the
            // same string twice (ABBREV.csv has near-duplicate entries).
            $key = strtolower($nameEn);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $cleaned[] = [
                'ndb_no' => $row['ndb_no'],
                'source_name' => $row['source_name'],
                'name_en' => $nameEn,
                'calories' => $row['calories'],
                'protein' => $row['protein'],
                'carbs' => $row['carbs'],
                'fats' => $row['fats'],
            ];
        }

        $toTranslate = array_values(array_filter($cleaned, fn ($r) => !isset($cache[strtolower($r['name_en'])])));
        $totalChars = array_sum(array_map(fn ($r) => mb_strlen($r['name_en']), $toTranslate));
        $this->info(sprintf(
            '%d names total, %d already cached, %d new to translate (~%s characters, DeepL free tier = 500,000/month)',
            count($cleaned),
            count($cleaned) - count($toTranslate),
            count($toTranslate),
            number_format($totalChars)
        ));

        $batchSize = max(1, (int) $this->option('batch'));
        $batches = array_chunk($toTranslate, $batchSize);
        $progress = $this->output->createProgressBar(count($batches));
        $progress->start();

        foreach ($batches as $batch) {
            $texts = array_map(fn ($r) => $r['name_en'], $batch);
            $translations = $this->translateBatch($apiKey, $texts);

            foreach ($batch as $i => $row) {
                $cache[strtolower($row['name_en'])] = $translations[$i] ?? '';
            }

            $progress->advance();
        }
        $progress->finish();
        $this->newLine();

        $results = array_map(fn ($row) => $row + ['name_fr' => $cache[strtolower($row['name_en'])] ?? ''], $cleaned);

        $this->writeOutputCsv($outputPath, $results);
        $this->info("Wrote {$outputPath} (" . count($results) . ' rows)');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> cleaned English name (lowercase) => French translation
     */
    protected function loadTranslationCache(string $outputPath): array
    {
        if (!is_file($outputPath)) {
            return [];
        }

        $cache = [];
        $handle = fopen($outputPath, 'r');
        $header = fgetcsv($handle);
        $nameEnIndex = array_search('name_en', $header);
        $nameFrIndex = array_search('name_fr', $header);

        while (($fields = fgetcsv($handle)) !== false) {
            $nameEn = $fields[$nameEnIndex] ?? '';
            $nameFr = $fields[$nameFrIndex] ?? '';
            if ($nameEn !== '' && $nameFr !== '') {
                $cache[strtolower($nameEn)] = $nameFr;
            }
        }
        fclose($handle);

        return $cache;
    }

    /**
     * @return array<int, array{ndb_no: string, source_name: string, calories: string, protein: string, carbs: string, fats: string}>
     */
    protected function readAbbrevCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $ndbIndex = array_search('NDB_No', $header);
        $descIndex = array_search('Shrt_Desc', $header);
        $caloriesIndex = array_search('Energ_Kcal', $header);
        $proteinIndex = array_search('Protein_(g)', $header);
        $carbsIndex = array_search('Carbohydrt_(g)', $header);
        $fatsIndex = array_search('Lipid_Tot_(g)', $header);

        $rows = [];
        while (($fields = fgetcsv($handle)) !== false) {
            if (!isset($fields[$descIndex]) || $fields[$descIndex] === '') {
                continue;
            }

            $rows[] = [
                'ndb_no' => $fields[$ndbIndex] ?? '',
                'source_name' => $fields[$descIndex],
                'calories' => $fields[$caloriesIndex] ?? '',
                'protein' => $fields[$proteinIndex] ?? '',
                'carbs' => $fields[$carbsIndex] ?? '',
                'fats' => $fields[$fatsIndex] ?? '',
            ];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param string[] $texts
     * @return string[] translations, same order as $texts
     */
    protected function translateBatch(string $apiKey, array $texts): array
    {
        $endpoint = str_ends_with($apiKey, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $response = Http::asForm()
            ->withHeaders(['Authorization' => "DeepL-Auth-Key {$apiKey}"])
            ->post($endpoint, [
                'text' => $texts,
                'source_lang' => 'EN',
                'target_lang' => 'FR',
            ]);

        if ($response->failed()) {
            $this->error('DeepL request failed: ' . $response->status() . ' ' . $response->body());

            return array_fill(0, count($texts), '');
        }

        $translations = $response->json('translations', []);

        return array_map(fn ($t) => $t['text'] ?? '', $translations);
    }

    protected function writeOutputCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['ndb_no', 'source_name', 'name_en', 'name_fr', 'calories', 'protein', 'carbs', 'fats']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['ndb_no'],
                $row['source_name'],
                $row['name_en'],
                $row['name_fr'],
                $row['calories'],
                $row['protein'],
                $row['carbs'],
                $row['fats'],
            ]);
        }

        fclose($handle);
    }
}
