<?php

namespace App\Services\MealScan;

use Anthropic\Client;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;

/**
 * FoodVision backed by Claude. The answer is constrained to a JSON schema, so
 * it always parses; the numbers are still clamped by the caller.
 */
class ClaudeFoodVision implements FoodVision
{
    private const PROMPT = <<<'TXT'
You look at one camera frame from a nutrition app and list the foods in it.

For each distinct food or drink item you can see:
- name: a short generic English name, the way a nutrition database would name it, with how it is prepared when you can tell (e.g. "Chicken breast, grilled", "White rice, cooked", "Banana, raw").
- search_term: 1 to 3 lowercase English words to look the food up, main food first (e.g. "chicken breast", "rice white", "banana").
- local_name: the same short name in the language you are asked for.
- box: where the item is, as fractions of the image width and height from the top-left corner (x, y, width, height, each 0 to 1).
- grams: your best estimate of the edible portion visible, using the plate, cutlery, hands or packaging for scale.
- confidence: 0 to 1, how sure you are of what the food is.
- per_100g: typical calories (kcal), protein, carbs and fats (grams) per 100 g of this food.

List each item once, even if there are several pieces (estimate the grams of all of them together). Ignore plates, cutlery, packaging text and people. Return at most 8 items. If there is no food in the frame, return an empty list.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'foods' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'search_term' => ['type' => 'string'],
                        'local_name' => ['type' => 'string'],
                        'box' => [
                            'type' => 'object',
                            'properties' => [
                                'x' => ['type' => 'number'],
                                'y' => ['type' => 'number'],
                                'width' => ['type' => 'number'],
                                'height' => ['type' => 'number'],
                            ],
                            'required' => ['x', 'y', 'width', 'height'],
                            'additionalProperties' => false,
                        ],
                        'grams' => ['type' => 'number'],
                        'confidence' => ['type' => 'number'],
                        'per_100g' => [
                            'type' => 'object',
                            'properties' => [
                                'calories' => ['type' => 'number'],
                                'protein' => ['type' => 'number'],
                                'carbs' => ['type' => 'number'],
                                'fats' => ['type' => 'number'],
                            ],
                            'required' => ['calories', 'protein', 'carbs', 'fats'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'required' => ['name', 'search_term', 'local_name', 'box', 'grams', 'confidence', 'per_100g'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['foods'],
        'additionalProperties' => false,
    ];

    private const LANGUAGES = ['en' => 'English', 'fr' => 'French'];

    public function __construct(private Client $client, private string $model) {}

    public function detect(string $image, string $mediaType, string $language): array
    {
        $languageName = self::LANGUAGES[$language] ?? 'English';

        $message = $this->client->messages->create(
            model: $this->model,
            maxTokens: 4096,
            system: self::PROMPT,
            messages: [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'mediaType' => $mediaType,
                            'data' => base64_encode($image),
                        ],
                    ],
                    ['type' => 'text', 'text' => "List the foods in this frame. Write local_name in {$languageName}."],
                ],
            ]],
            // Low effort: a frame arrives every second or two, so a quick
            // answer matters more than a long think.
            outputConfig: OutputConfig::with(
                effort: 'low',
                format: JSONOutputFormat::with(schema: self::SCHEMA),
            ),
        );

        // A refusal or a cut-off answer has no usable JSON; the next frame
        // gets another try.
        if ($message->stopReason !== 'end_turn') {
            return [];
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);

                return is_array($data['foods'] ?? null) ? array_values($data['foods']) : [];
            }
        }

        return [];
    }
}
