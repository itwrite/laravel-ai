<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('users can generate an image via pollinations', function () {
    Storage::fake('public');
    Http::fake([
        'image.pollinations.ai/*' => Http::response(
            'fake-jpeg-bytes',
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->post('/pollinations-image', [
            'prompt' => 'a koi fish tattoo line art',
            'width' => 768,
            'height' => 1024,
        ]);

    $response->assertOk();

    $url = $response->json('url');

    expect($url)->toStartWith('/storage/ai-images/')
        ->toContain('.jpg');

    Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));

    Http::assertSent(function (Request $request) {
        $url = $request->url();

        return str_starts_with($url, 'https://image.pollinations.ai/prompt/a%20koi%20fish%20tattoo%20line%20art')
            && str_contains($url, 'width=768')
            && str_contains($url, 'height=1024')
            && str_contains($url, 'model=flux')
            && str_contains($url, 'nologo=true');
    });
});

test('image dimensions default to 1024x1024', function () {
    Storage::fake('public');
    Http::fake([
        'image.pollinations.ai/*' => Http::response(
            'fake-png-bytes',
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $this->actingAs(User::factory()->create())
        ->post('/pollinations-image', ['prompt' => '一朵盛开的莲花']);

    Http::assertSent(
        fn (Request $request): bool => str_contains($request->url(), 'width=1024')
            && str_contains($request->url(), 'height=1024'),
    );
});

test('guests are redirected to the login page', function () {
    $this->post('/pollinations-image', ['prompt' => '一条锦鲤'])
        ->assertRedirect(route('login'));
});

test('generation requires a prompt of a minimum length', function () {
    $this->actingAs(User::factory()->create())
        ->post('/pollinations-image', ['prompt' => '短'])
        ->assertInvalid(['prompt']);
});

test('generation rejects out-of-range dimensions', function () {
    $this->actingAs(User::factory()->create())
        ->post('/pollinations-image', [
            'prompt' => '一条锦鲤',
            'width' => 4096,
        ])
        ->assertInvalid(['width']);
});

test('provider failures return a json error', function () {
    Http::fake([
        'image.pollinations.ai/*' => Http::response(
            ['error' => 'Internal Server Error'],
            500,
        ),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->post('/pollinations-image', ['prompt' => '一条锦鲤']);

    $response->assertStatus(502)
        ->assertJsonMissing(['url' => true]);

    expect($response->json('message'))->toContain('HTTP 500');
});

test('non-image responses return a json error', function () {
    Http::fake([
        'image.pollinations.ai/*' => Http::response(
            ['error' => 'rate limited'],
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->post('/pollinations-image', ['prompt' => '一条锦鲤']);

    $response->assertStatus(502);

    expect($response->json('message'))->toContain('non-image');
});
