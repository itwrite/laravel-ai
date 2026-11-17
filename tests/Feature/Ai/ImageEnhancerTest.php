<?php

use App\Ai\Agents\ImageEnhanceAgent\ImageEnhanceAgent;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Image;

test('the image enhancer page renders for authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('image-enhancer.show'))
        ->assertOk();
});

test('guests are redirected to the login page', function () {
    $this->get(route('image-enhancer.show'))->assertRedirect(route('login'));

    $this->post('/image-enhancer', [
        'image' => 'data:image/png;base64,'.base64_encode('image'),
    ])->assertRedirect(route('login'));
});

test('users can enhance an image to ultra hd', function () {
    Image::fake();

    $response = $this->actingAs(User::factory()->create())->post('/image-enhancer', [
        'image' => 'data:image/png;base64,'.base64_encode('source-image'),
        'detail' => 'high',
    ]);

    $response->assertOk()
        ->assertJsonPath('mime', 'image/png');

    expect($response->json('image'))->toStartWith('data:image/png;base64,');

    Image::assertGenerated(
        fn ($prompt) => $prompt->contains('Resolution tier')
            && $prompt->quality === 'high'
            && $prompt->attachments->count() === 1
            && $prompt->size === null
    );
});

test('the agent loads its fixed instructions from disk', function () {
    $path = dirname(__DIR__, 3).'/app/Ai/Agents/ImageEnhanceAgent/instructions.md';

    expect((new ImageEnhanceAgent)->instructions())
        ->toBe(file_get_contents($path));
});

test('the generation prompt embeds the agent instructions', function () {
    Image::fake();

    $this->actingAs(User::factory()->create())->post('/image-enhancer', [
        'image' => 'data:image/png;base64,'.base64_encode('image'),
    ]);

    // The instructions wording may evolve; assert that the first heading
    // line of whatever is on disk reaches the image model prompt.
    $marker = strtok((string) (new ImageEnhanceAgent)->instructions(), "\n");

    Image::assertGenerated(
        fn ($prompt) => $prompt->contains((string) $marker)
            && $prompt->contains('Resolution tier')
    );
});

test('the enhancement prompt carries the selected options', function () {
    Image::fake();

    $this->actingAs(User::factory()->create())->post('/image-enhancer', [
        'image' => 'data:image/jpeg;base64,'.base64_encode('source-image'),
        'goal' => 'restore',
        'detail' => 'medium',
        'extras' => '保留背景文字',
    ]);

    Image::assertGenerated(
        fn ($prompt) => $prompt->contains('Old photo restoration')
            && $prompt->contains('保留背景文字')
            && $prompt->contains('2K-class')
            && $prompt->quality === 'medium'
    );
});

test('the remove-handwriting goal drives the enhancement prompt', function () {
    Image::fake();

    $this->actingAs(User::factory()->create())->post('/image-enhancer', [
        'image' => 'data:image/png;base64,'.base64_encode('exam-paper'),
        'goal' => 'remove-handwriting',
    ]);

    Image::assertGenerated(
        fn ($prompt) => $prompt->contains('Processing mode: remove_handwriting (Mode B)')
            && $prompt->contains('erase every handwritten answer')
            && $prompt->contains('no smudge, shadow or ghost')
    );
});

test('enhancement requires a source image', function () {
    $this->actingAs(User::factory()->create())
        ->post('/image-enhancer', [])
        ->assertInvalid(['image']);
});

test('enhancement rejects a malformed source image', function () {
    $this->actingAs(User::factory()->create())
        ->post('/image-enhancer', ['image' => 'not-a-data-url'])
        ->assertInvalid(['image']);
});

test('enhancement rejects an unknown goal', function () {
    $this->actingAs(User::factory()->create())
        ->post('/image-enhancer', [
            'image' => 'data:image/png;base64,'.base64_encode('image'),
            'goal' => 'watercolor',
        ])
        ->assertInvalid(['goal']);
});

test('provider failures return a json error', function () {
    config()->set('ai.providers.gemini.key', 'test-key');

    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $response = $this->actingAs(User::factory()->create())->post('/image-enhancer', [
        'image' => 'data:image/png;base64,'.base64_encode('image'),
    ]);

    $response->assertStatus(502)->assertJsonMissing(['image' => true]);
});

test('the pollinations channel never joins the enhancement chain', function () {
    config()->set('ai.providers.gemini.key', 'gemini-key');
    config()->set('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/');
    config()->set('ai.providers.xai.key', 'xai-key');
    config()->set('ai.providers.xai.url', 'https://api.x.ai/v1');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        'api.x.ai/*' => Http::response([
            'data' => [['b64_json' => base64_encode('xai-enhanced')]],
        ]),
    ]);

    $response = $this->actingAs(User::factory()->create())->post('/image-enhancer', [
        'image' => 'data:image/png;base64,'.base64_encode('source'),
    ]);

    $response->assertOk()->assertJsonPath('mime', 'image/jpeg');

    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), 'image.pollinations.ai'),
    );
});

test('rate limited chains add a quota hint to the error message', function () {
    config()->set('ai.providers.gemini.key', 'gemini-key');
    config()->set('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/');
    config()->set('ai.providers.xai.key', 'xai-key');
    config()->set('ai.providers.xai.url', 'https://api.x.ai/v1');

    Http::fake([
        '*' => Http::response([
            'error' => ['message' => 'Quota exceeded'],
        ], 429),
    ]);

    $response = $this->actingAs(User::factory()->create())->post('/image-enhancer', [
        'image' => 'data:image/png;base64,'.base64_encode('image'),
    ]);

    $response->assertStatus(502)
        ->assertJsonMissing(['image' => true]);

    expect($response->json('message'))->toContain('额度');
});
