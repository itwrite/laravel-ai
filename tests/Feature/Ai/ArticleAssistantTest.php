<?php

use App\Ai\Agents\ArticleAgent\ArticleAgent;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function articleHtml(string $title = '测试文章'): string
{
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'
        ."<title>{$title}</title></head><body><article><h1>你好</h1></article></body></html>";
}

test('the article assistant page renders for authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('article-assistant.show'))
        ->assertOk();
});

test('guests are redirected to the login page', function () {
    $this->get(route('article-assistant.show'))->assertRedirect(route('login'));

    $this->post('/article-assistant', ['content' => '足够长的素材内容……'])
        ->assertRedirect(route('login'));
});

test('users can generate an html article', function () {
    ArticleAgent::fake([articleHtml()]);

    $response = $this->actingAs(User::factory()->create())->post('/article-assistant', [
        'topic' => 'Laravel 13 发布',
        'content' => 'Laravel 13 正式发布，带来了新式模型写法与官方 AI SDK。',
    ]);

    $response->assertOk()
        ->assertJsonPath('html', articleHtml())
        ->assertJsonPath('title', '测试文章');

    ArticleAgent::assertPromptedTimes(1);
});

test('the generation prompt carries the user inputs', function () {
    ArticleAgent::fake([articleHtml()]);

    $this->actingAs(User::factory()->create())->post('/article-assistant', [
        'content' => '新品：保温杯，316 不锈钢内胆。',
        'audience' => '户外爱好者',
        'tone' => 'passionate',
        'length' => 'long',
        'extras' => '结尾加购买引导',
    ]);

    ArticleAgent::assertPrompted(
        fn ($prompt) => str_contains($prompt->prompt, '保温杯')
            && str_contains($prompt->prompt, '户外爱好者')
            && str_contains($prompt->prompt, '热情有感染力')
            && str_contains($prompt->prompt, '长篇')
            && str_contains($prompt->prompt, '购买引导'),
    );
});

test('markdown code fences are stripped from the generated html', function () {
    ArticleAgent::fake(["```html\n".articleHtml('围栏文章')."\n```"]);

    $response = $this->actingAs(User::factory()->create())->post('/article-assistant', [
        'content' => '任意素材内容，足够超过十个字符。',
    ]);

    $response->assertOk();

    expect($response->json('html'))
        ->toStartWith('<!DOCTYPE html>')
        ->not->toContain('```');
});

test('article generation requires content of a minimum length', function () {
    $this->actingAs(User::factory()->create())
        ->post('/article-assistant', ['content' => '太短'])
        ->assertInvalid(['content']);
});

test('article generation rejects an unknown tone', function () {
    $this->actingAs(User::factory()->create())
        ->post('/article-assistant', [
            'content' => '足够长的素材内容……',
            'tone' => 'shouty',
        ])
        ->assertInvalid(['tone']);
});

test('provider failures return a json error', function () {
    config()->set('ai.default', 'zhipu');
    config()->set('ai.chat_providers', ['zhipu']);
    config()->set('ai.providers.zhipu.key', 'test-key');
    config()->set('ai.providers.zhipu.url', 'https://zhipu.test');

    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $response = $this->actingAs(User::factory()->create())->post('/article-assistant', [
        'content' => '足够长的素材内容……',
    ]);

    $response->assertStatus(502)->assertJsonMissing(['html' => true]);
});
