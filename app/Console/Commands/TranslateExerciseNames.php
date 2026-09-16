<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TranslateExerciseNames extends Command
{
    protected $signature = 'exercises:translate
        {--source=exercise_dataset.csv : CSV file under database/data to read}
        {--output=exercises_translated.csv : CSV file under database/data to write}
        {--batch=50 : Names sent per DeepL request}';

    protected $description = 'Translate cardio activity names to French via DeepL, writing a static CSV for ExerciseSeeder to read';

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

        $cache = $this->loadCache($outputPath);
        $names = $this->readUniqueNames($sourcePath);

        $toTranslate = array_values(array_filter($names, fn ($n) => !isset($cache[strtolower($n)])));
        $totalChars = array_sum(array_map('mb_strlen', $toTranslate));
        $this->info(sprintf(
            '%d names total, %d already cached, %d new to translate (~%s characters)',
            count($names),
            count($names) - count($toTranslate),
            count($toTranslate),
            number_format($totalChars)
        ));

        $batchSize = max(1, (int) $this->option('batch'));
        $batches = array_chunk($toTranslate, $batchSize);
        $progress = $this->output->createProgressBar(count($batches));
        $progress->start();

        foreach ($batches as $batch) {
            $translations = $this->translateBatch($apiKey, $batch);
            foreach ($batch as $i => $name) {
                $cache[strtolower($name)] = $translations[$i] ?? '';
            }
            $progress->advance();
        }
        $progress->finish();
        $this->newLine();

        $handle = fopen($outputPath, 'w');
        fputcsv($handle, ['name', 'name_fr']);
        foreach ($names as $name) {
            fputcsv($handle, [$name, $cache[strtolower($name)] ?? '']);
        }
        fclose($handle);

        $this->info("Wrote {$outputPath} (" . count($names) . ' rows)');

        return self::SUCCESS;
    }

    protected function loadCache(string $outputPath): array
    {
        if (!is_file($outputPath)) {
            return [];
        }

        $cache = [];
        $handle = fopen($outputPath, 'r');
        fgetcsv($handle);
        while (($fields = fgetcsv($handle)) !== false) {
            if (($fields[1] ?? '') !== '') {
                $cache[strtolower($fields[0])] = $fields[1];
            }
        }
        fclose($handle);

        return $cache;
    }

    /**
     * @return string[] unique, order-preserved activity names (the full first
     * CSV column, e.g. "Cycling, mountain bike, bmx" — not truncated).
     */
    protected function readUniqueNames(string $path): array
    {
        $handle = fopen($path, 'r');
        fgetcsv($handle); // header

        $seen = [];
        $names = [];
        while (($fields = fgetcsv($handle)) !== false) {
            $name = trim($fields[0] ?? '');
            $key = strtolower($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }
        fclose($handle);

        return $names;
    }

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

        return array_map(fn ($t) => $t['text'] ?? '', $response->json('translations', []));
    }
}
