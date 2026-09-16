<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TranslateGymExerciseNames extends Command
{
    protected $signature = 'gym-exercises:translate
        {--source=megaGymDataset.csv : CSV file under database/data to read}
        {--output=gym_exercises_translated.csv : CSV file under database/data to write}
        {--batch=50 : Titles sent per DeepL request}';

    protected $description = 'Translate gym exercise titles to French via DeepL, writing a static CSV for GymExerciseSeeder to read';

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
        $titles = $this->readUniqueTitles($sourcePath);

        $toTranslate = array_values(array_filter($titles, fn ($t) => !isset($cache[strtolower($t)])));
        $totalChars = array_sum(array_map('mb_strlen', $toTranslate));
        $this->info(sprintf(
            '%d titles total, %d already cached, %d new to translate (~%s characters)',
            count($titles),
            count($titles) - count($toTranslate),
            count($toTranslate),
            number_format($totalChars)
        ));

        $batchSize = max(1, (int) $this->option('batch'));
        $batches = array_chunk($toTranslate, $batchSize);
        $progress = $this->output->createProgressBar(count($batches));
        $progress->start();

        foreach ($batches as $batch) {
            $translations = $this->translateBatch($apiKey, $batch);
            foreach ($batch as $i => $title) {
                $cache[strtolower($title)] = $translations[$i] ?? '';
            }
            $progress->advance();
        }
        $progress->finish();
        $this->newLine();

        $handle = fopen($outputPath, 'w');
        fputcsv($handle, ['title', 'title_fr']);
        foreach ($titles as $title) {
            fputcsv($handle, [$title, $cache[strtolower($title)] ?? '']);
        }
        fclose($handle);

        $this->info("Wrote {$outputPath} (" . count($titles) . ' rows)');

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
     * @return string[] unique, order-preserved exercise titles
     */
    protected function readUniqueTitles(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $titleIndex = array_search('Title', $header);

        $seen = [];
        $titles = [];
        while (($fields = fgetcsv($handle)) !== false) {
            $title = trim($fields[$titleIndex] ?? '');
            $key = strtolower($title);
            if ($title === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $titles[] = $title;
        }
        fclose($handle);

        return $titles;
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
