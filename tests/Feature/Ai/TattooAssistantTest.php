<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Image;

test('the tattoo assistant page renders for authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('tattoo-assistant.show'))
        ->assertOk();
});

test('guests are redirected to the login page', function () {
    $this->get(route('tattoo-assistant.show'))->assertRedirect(route('login'));

    $this->post('/tattoo-assistant', ['subject' => '一条锦鲤'])
        ->assertRedirect(route('login'));
});

test('users can generate a tattoo line art', function () {
    Image::fake();

    $response = $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '一条衔着樱花的锦鲤，搭配波浪与云纹',
        'style' => 'japanese',
        'ratio' => 'portrait',
        'detail' => 'medium',
    ]);

    $response->assertOk()
        ->assertJsonPath('mime', 'image/png');

    expect($response->json('image'))->toStartWith('data:image/png;base64,');

    Image::assertGenerated(
        fn ($prompt) => $prompt->contains('锦鲤') && $prompt->isPortrait()
    );
});

test('the generation prompt carries the selected options', function () {
    Image::fake();

    $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '几何风格的狼头',
        'style' => 'geometric',
        'placement' => 'forearm',
        'extras' => '线条加粗一些',
    ]);

    Image::assertGenerated(
        fn ($prompt) => $prompt->contains('geometric tattoo style')
            && $prompt->contains('forearm')
            && $prompt->contains('线条加粗一些')
    );
});

test('a reference image is attached to the generation', function () {
    Image::fake();

    $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '按参考图重绘的图案',
        'reference' => 'data:image/png;base64,'.base64_encode('reference-image'),
    ]);

    Image::assertGenerated(
        fn ($prompt) => $prompt->attachments->count() === 1
            && $prompt->contains('visual reference')
    );
});

test('generation requires a subject of a minimum length', function () {
    $this->actingAs(User::factory()->create())
        ->post('/tattoo-assistant', ['subject' => '短'])
        ->assertInvalid(['subject']);
});

test('generation rejects an unknown style', function () {
    $this->actingAs(User::factory()->create())
        ->post('/tattoo-assistant', [
            'subject' => '一条锦鲤',
            'style' => 'watercolor',
        ])
        ->assertInvalid(['style']);
});

test('generation rejects a malformed reference image', function () {
    $this->actingAs(User::factory()->create())
        ->post('/tattoo-assistant', [
            'subject' => '一条锦鲤',
            'reference' => 'not-a-data-url',
        ])
        ->assertInvalid(['reference']);
});

test('provider failures return a json error', function () {
    config()->set('ai.providers.gemini.key', 'test-key');

    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $response = $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '一条锦鲤',
    ]);

    $response->assertStatus(502)->assertJsonMissing(['image' => true]);
});

test('generation falls over to xai when gemini and pollinations fail', function () {
    config()->set('ai.providers.gemini.key', 'gemini-key');
    config()->set('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/');
    config()->set('ai.providers.xai.key', 'xai-key');
    config()->set('ai.providers.xai.url', 'https://api.x.ai/v1');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'You exceeded your current quota'],
        ], 429),
        'image.pollinations.ai/*' => Http::response(['error' => 'overloaded'], 503),
        'api.x.ai/*' => Http::response([
            'data' => [['b64_json' => base64_encode('xai-line-art')]],
        ]),
    ]);

    $response = $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '一条锦鲤',
    ]);

    $response->assertOk()->assertJsonPath('mime', 'image/jpeg');

    expect($response->json('image'))->toStartWith('data:image/jpeg;base64,');
});

test('generation falls over to pollinations when gemini rate limits', function () {
    config()->set('ai.providers.gemini.key', 'gemini-key');
    config()->set('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'You exceeded your current quota'],
        ], 429),
        'image.pollinations.ai/*' => Http::response(
            'pollinations-line-art',
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $response = $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '一条锦鲤',
    ]);

    $response->assertOk()->assertJsonPath('mime', 'image/jpeg');

    expect($response->json('image'))->toBe('data:image/jpeg;base64,'.base64_encode('pollinations-line-art'));
});

test('reference images skip the pollinations channel', function () {
    config()->set('ai.providers.gemini.key', 'gemini-key');
    config()->set('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/');
    config()->set('ai.providers.xai.key', 'xai-key');
    config()->set('ai.providers.xai.url', 'https://api.x.ai/v1');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        'image.pollinations.ai/*' => Http::response(
            'pollinations-line-art',
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
        'api.x.ai/*' => Http::response([
            'data' => [['b64_json' => base64_encode('xai-line-art')]],
        ]),
    ]);

    $response = $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '按参考图重绘',
        'reference' => 'data:image/png;base64,'.base64_encode('reference'),
    ]);

    $response->assertOk()->assertJsonPath('mime', 'image/jpeg');

    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), 'image.pollinations.ai'),
    );
});

test('pollinations 500 rate-limit responses fail over to xai', function () {
    config()->set('ai.providers.gemini.key', 'gemini-key');
    config()->set('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/');
    config()->set('ai.providers.xai.key', 'xai-key');
    config()->set('ai.providers.xai.url', 'https://api.x.ai/v1');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'You exceeded your current quota'],
        ], 429),
        // Pollinations wraps its internal 429s (anonymous tier routing to
        // community models with per-user RPM limits) as HTTP 500.
        'image.pollinations.ai/*' => Http::response([
            'error' => 'Internal Server Error',
            'message' => 'Gen Sana request failed with 429: {"success":false,"error":{"message":"Per-user limit of 300 RPM exceeded for model lykon/dreamshaper-8-lcm","code":"community_model_rate_limit"}}',
        ], 500),
        'api.x.ai/*' => Http::response([
            'data' => [['b64_json' => base64_encode('xai-line-art')]],
        ]),
    ]);

    $response = $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '一条锦鲤',
    ]);

    $response->assertOk()->assertJsonPath('mime', 'image/jpeg');

    expect($response->json('image'))->toStartWith('data:image/jpeg;base64,');
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

    $response = $this->actingAs(User::factory()->create())->post('/tattoo-assistant', [
        'subject' => '一条锦鲤',
    ]);

    $response->assertStatus(502)
        ->assertJsonMissing(['image' => true]);

    expect($response->json('message'))->toContain('额度');
});
