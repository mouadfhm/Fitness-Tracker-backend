<?php

namespace Tests\Unit;

use Anthropic\Client;
use App\Services\MealScan\ClaudeFoodVision;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The request ClaudeFoodVision sends and how it reads the answer, with the
 * HTTP call answered in-process so no key or network is needed.
 */
class ClaudeFoodVisionTest extends TestCase
{
    private ?array $sent = null;

    private function vision(array $responseBody): ClaudeFoodVision
    {
        $client = new Client(apiKey: 'test-key', requestOptions: [
            'maxRetries' => 0,
            'middleware' => [function (RequestInterface $request) use ($responseBody) {
                $this->sent = json_decode((string) $request->getBody(), true);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($responseBody));
            }],
        ]);

        return new ClaudeFoodVision($client, 'claude-haiku-5-5');
    }

    private function message(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1300, 'output_tokens' => 120],
        ];
    }

    public function test_it_sends_the_frame_as_an_image_with_a_json_schema(): void
    {
        $this->vision($this->message('{"foods":[]}'))->detect('jpeg-bytes', 'image/jpeg', 'fr');

        $this->assertSame('claude-haiku-5-5', $this->sent['model']);
        $image = $this->sent['messages'][0]['content'][0];
        $this->assertSame('image', $image['type']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode('jpeg-bytes')], $image['source']);
        $this->assertStringContainsString('French', $this->sent['messages'][0]['content'][1]['text']);
        $this->assertSame('json_schema', $this->sent['output_config']['format']['type']);
        $this->assertSame('low', $this->sent['output_config']['effort']);
    }

    public function test_it_returns_the_foods_from_the_answer(): void
    {
        $food = [
            'name' => 'Banana, raw', 'search_term' => 'banana', 'local_name' => 'Banane',
            'box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
            'grams' => 120, 'confidence' => 0.9,
            'per_100g' => ['calories' => 89, 'protein' => 1.1, 'carbs' => 22.8, 'fats' => 0.3],
        ];

        $foods = $this->vision($this->message(json_encode(['foods' => [$food]])))->detect('x', 'image/jpeg', 'en');

        $this->assertSame([$food], $foods);
    }

    public function test_a_refusal_or_cut_off_answer_gives_no_foods(): void
    {
        $this->assertSame([], $this->vision($this->message('', 'refusal'))->detect('x', 'image/jpeg', 'en'));
        $this->assertSame([], $this->vision($this->message('{"foods":[{"na', 'max_tokens'))->detect('x', 'image/jpeg', 'en'));
    }
}
