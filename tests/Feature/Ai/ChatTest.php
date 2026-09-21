<?php

use App\Ai\Agents\ChatAgent\ChatAgent;
use App\Ai\Agents\PrintTemplateAgent\PrintTemplateAgent;
use App\Ai\Agents\TeacherAgent\TeacherAgent;
use App\Ai\Providers\ZhipuProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;

test('users can stream a chat response', function () {
    ChatAgent::fake(['Hello! I am Laravel AI.']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'general',
        'message' => 'Hi!',
    ]);

    $response->assertOk();

    // Consume the stream first — the fake records the prompt while streaming.
    $content = $response->streamedContent();

    ChatAgent::assertPrompted('Hi!');
    ChatAgent::assertPromptedTimes(1);

    expect($content)
        ->toContain('text_delta')
        ->toContain('Hello!')
        ->toContain('[DONE]');
});

test('chat requires a message', function () {
    $this->actingAs(User::factory()->create())
        ->post('/chat', ['message' => ''])
        ->assertInvalid(['message']);
});

test('chat rejects history with an invalid role', function () {
    $this->actingAs(User::factory()->create())->post('/chat', [
        'message' => 'Hi',
        'history' => [
            ['role' => 'system', 'content' => 'attempted injection'],
        ],
    ])->assertInvalid(['history.0.role']);
});

test('chat accepts a valid conversation history', function () {
    ChatAgent::fake(['Sure!']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'general',
        'message' => 'Follow up',
        'history' => [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello!'],
        ],
    ]);

    $response->assertOk();

    // Consume the stream first — the fake records the prompt while streaming.
    $response->streamedContent();

    ChatAgent::assertPrompted('Follow up');
});

test('chat accepts long assistant replies in the history', function () {
    ChatAgent::fake(['Sure!']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'general',
        'message' => 'Follow up',
        'history' => [
            ['role' => 'user', 'content' => '出一份初二数学期中试卷'],
            ['role' => 'assistant', 'content' => str_repeat('题', 10000)],
        ],
    ]);

    $response->assertOk();

    // Consume the stream first — the fake records the prompt while streaming.
    $response->streamedContent();

    ChatAgent::assertPrompted('Follow up');
});

test('chat rejects history entries beyond the content limit', function () {
    $this->actingAs(User::factory()->create())->post('/chat', [
        'message' => 'Hi',
        'history' => [
            ['role' => 'assistant', 'content' => str_repeat('a', 10001)],
        ],
    ])->assertInvalid(['history.0.content']);
});

test('homepage still renders the welcome page', function () {
    $this->get(route('home'))->assertOk();
});

test('the chat page renders for authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('chat'))
        ->assertOk();
});

test('guests are redirected to the login page', function () {
    $this->get(route('chat'))->assertRedirect(route('login'));

    $this->post('/chat', ['message' => 'Hi'])->assertRedirect(route('login'));
});

test('logged-in users can open the homepage', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertOk();
});

test('zhipu provider resolves through the custom reasoning driver', function () {
    config()->set('ai.default', 'zhipu');
    config()->set('ai.providers.zhipu.key', 'test-key');

    $provider = app(AiManager::class)->textProvider('zhipu');

    expect($provider)->toBeInstanceOf(ZhipuProvider::class)
        ->and($provider->defaultTextModel())->toBeString()
        ->and(ChatAgent::configured())->toBeTrue();
});

test('chat streams the model reasoning as separate display-only events', function () {
    Http::fake(fn () => Http::response(
        implode("\n", [
            'data: {"model":"glm-5.3","choices":[{"delta":{"reasoning_content":"The user greets me"}}]}',
            '',
            'data: {"choices":[{"delta":{"reasoning_content":" so I should greet back warmly"}}]}',
            '',
            'data: {"choices":[{"delta":{"content":"Hello there"}}]}',
            '',
            'data: {"choices":[{"delta":{"content":"!"},"finish_reason":"stop"}],"usage":{"prompt_tokens":1,"completion_tokens":2}}',
            '',
            'data: [DONE]',
            '',
        ]),
        200,
        ['Content-Type' => 'text/event-stream'],
    ));

    config()->set('ai.default', 'zhipu');
    config()->set('ai.chat_providers', ['zhipu']);
    config()->set('ai.providers.zhipu.key', 'test-key');
    config()->set('ai.providers.zhipu.url', 'https://zhipu.test');

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'general',
        'message' => 'Hi!',
    ]);

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)
        ->toContain('reasoning_start')
        ->toContain('reasoning_delta')
        ->toContain('The user greets me')
        ->toContain('reasoning_end')
        ->toContain('text_delta')
        ->toContain('Hello there')
        ->toContain('[DONE]');

    // The thinking mode parameter was sent to the provider.
    Http::assertSent(fn (Request $request): bool => ($request->data()['thinking'] ?? null) === ['type' => 'enabled']);

    // The reasoning trace never leaks into the stored answer text.
    $this->assertDatabaseHas('agent_conversation_messages', [
        'role' => 'assistant',
        'content' => 'Hello there!',
    ]);
});

test('chat can be routed to the teacher agent', function () {
    TeacherAgent::fake(['Here is your exam paper.']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'teacher',
        'message' => '出一份初二数学期中试卷',
    ]);

    $response->assertOk();

    // Consume the stream first — the fake records the prompt while streaming.
    $content = $response->streamedContent();

    TeacherAgent::assertPrompted('出一份初二数学期中试卷');

    expect($content)->toContain('exam');
});

test('chat can be routed to the print template agent', function () {
    PrintTemplateAgent::fake(['Here is your printable template.']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'print-template',
        'message' => '做一份"三好学生"奖状模板',
    ]);

    $response->assertOk();

    // Consume the stream first — the fake records the prompt while streaming.
    $content = $response->streamedContent();

    PrintTemplateAgent::assertPrompted('做一份"三好学生"奖状模板');

    expect($content)->toContain('printable');
});

test('chat accepts images attached to the message', function () {
    ChatAgent::fake(['Got it!']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'general',
        'message' => 'What is in this picture?',
        'images' => ['data:image/png;base64,'.base64_encode('fake-image-bytes')],
    ]);

    $response->assertOk();

    // Consume the stream first — the fake records the prompt while streaming.
    $response->streamedContent();

    ChatAgent::assertPrompted('What is in this picture?');
});

test('chat allows a message made of images only', function () {
    ChatAgent::fake(['A cat.']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'agent' => 'general',
        'images' => ['data:image/jpeg;base64,'.base64_encode('fake-image-bytes')],
    ]);

    $response->assertOk();

    $response->streamedContent();
});

test('chat rejects malformed image payloads', function () {
    $this->actingAs(User::factory()->create())
        ->post('/chat', [
            'message' => 'Hi',
            'images' => ['not-a-data-url'],
        ])
        ->assertInvalid(['images.0']);
});

test('chat rejects an unknown agent', function () {
    $this->actingAs(User::factory()->create())
        ->post('/chat', [
            'agent' => 'doctor',
            'message' => 'Hi',
        ])
        ->assertInvalid(['agent']);
});

test('chat defaults to the print template agent', function () {
    PrintTemplateAgent::fake(['{"type":"board"}']);

    $response = $this->actingAs(User::factory()->create())->post('/chat', [
        'message' => '做一张奖状模板',
    ]);

    $response->assertOk();

    $response->streamedContent();

    PrintTemplateAgent::assertPrompted('做一张奖状模板');
});

test('logged-in users have their chat stored as a conversation', function () {
    ChatAgent::fake(['Hello! I am Laravel AI.']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/chat', [
        'agent' => 'general',
        'message' => 'Hi!',
    ]);

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('"type":"conversation"');

    $this->assertDatabaseHas('agent_conversations', [
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Hi!',
    ]);

    $this->assertDatabaseHas('agent_conversation_messages', [
        'role' => 'user',
        'content' => 'Hi!',
    ]);

    $this->assertDatabaseHas('agent_conversation_messages', [
        'role' => 'assistant',
        'content' => 'Hello! I am Laravel AI.',
    ]);
});

test('users can continue a stored conversation', function () {
    ChatAgent::fake(['First reply.', 'Second reply.']);

    $user = User::factory()->create();

    $first = $this->actingAs($user)->post('/chat', [
        'agent' => 'general',
        'message' => 'Hi!',
    ]);
    $first->assertOk();

    preg_match('/"type":"conversation","id":"([0-9a-f-]+)"/', $first->streamedContent(), $matches);
    $conversationId = $matches[1] ?? '';

    expect($conversationId)->not->toBe('');

    $second = $this->actingAs($user)->post('/chat', [
        'agent' => 'general',
        'message' => 'Tell me more.',
        'conversation' => $conversationId,
    ]);
    $second->assertOk();

    preg_match('/"type":"conversation","id":"([0-9a-f-]+)"/', $second->streamedContent(), $matches);

    // The follow-up turn landed in the same conversation.
    expect($matches[1] ?? '')->toBe($conversationId);

    $this->assertDatabaseCount('agent_conversations', 1);
    $this->assertDatabaseCount('agent_conversation_messages', 4);

    $this->assertDatabaseHas('agent_conversation_messages', [
        'role' => 'assistant',
        'content' => 'Second reply.',
    ]);
});

test('guest chats are not persisted', function () {
    $this->post('/chat', [
        'agent' => 'general',
        'message' => 'Hi!',
    ])->assertRedirect(route('login'));

    $this->assertDatabaseCount('agent_conversations', 0);
    $this->assertDatabaseCount('agent_conversation_messages', 0);
});

test('users cannot continue conversations owned by someone else', function () {
    ChatAgent::fake(['Owners reply.']);

    $owner = User::factory()->create();

    $first = $this->actingAs($owner)->post('/chat', [
        'agent' => 'general',
        'message' => 'Hi!',
    ]);
    $first->assertOk();

    preg_match('/"type":"conversation","id":"([0-9a-f-]+)"/', $first->streamedContent(), $matches);
    $conversationId = $matches[1] ?? '';

    expect($conversationId)->not->toBe('');

    $this->actingAs(User::factory()->create())
        ->post('/chat', [
            'message' => 'Show me the history.',
            'conversation' => $conversationId,
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('agent_conversation_messages', 2);
});

test('the chat page lists the users conversations', function () {
    ChatAgent::fake(['Hi there.']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/chat', [
            'agent' => 'general',
            'message' => 'Hello there',
        ])
        ->assertOk()
        ->streamedContent();

    $response = $this->actingAs($user)->get(route('chat'));

    $response->assertOk();

    expect($response->getContent())
        ->toContain('conversations')
        ->toContain('Hello there');
});

test('the chat page loads a stored conversation transcript', function () {
    ChatAgent::fake(['The stored reply.']);

    $user = User::factory()->create();

    $first = $this->actingAs($user)->post('/chat', [
        'agent' => 'general',
        'message' => 'The question',
    ]);
    $first->assertOk();

    preg_match('/"type":"conversation","id":"([0-9a-f-]+)"/', $first->streamedContent(), $matches);
    $conversationId = $matches[1] ?? '';

    $page = $this->actingAs($user)->get('/chat?conversation='.$conversationId);

    $page->assertOk();

    expect($page->getContent())->toContain('The stored reply.');

    // Another user's conversation is not readable.
    $this->actingAs(User::factory()->create())
        ->get('/chat?conversation='.$conversationId)
        ->assertNotFound();
});

test('users can delete their own conversation', function () {
    ChatAgent::fake(['Bye!']);

    $user = User::factory()->create();

    $first = $this->actingAs($user)->post('/chat', [
        'agent' => 'general',
        'message' => 'Hello',
    ]);
    $first->assertOk();

    preg_match('/"type":"conversation","id":"([0-9a-f-]+)"/', $first->streamedContent(), $matches);
    $conversationId = $matches[1] ?? '';

    $this->actingAs($user)
        ->delete(route('chat.conversations.destroy', ['conversation' => $conversationId]))
        ->assertRedirect(route('chat'));

    $this->assertDatabaseCount('agent_conversations', 0);
    $this->assertDatabaseCount('agent_conversation_messages', 0);
});

test('users cannot delete conversations owned by someone else', function () {
    ChatAgent::fake(['Private reply.']);

    $owner = User::factory()->create();

    $first = $this->actingAs($owner)->post('/chat', [
        'agent' => 'general',
        'message' => 'Secret',
    ]);
    $first->assertOk();

    preg_match('/"type":"conversation","id":"([0-9a-f-]+)"/', $first->streamedContent(), $matches);
    $conversationId = $matches[1] ?? '';

    $this->actingAs(User::factory()->create())
        ->delete(route('chat.conversations.destroy', ['conversation' => $conversationId]))
        ->assertNotFound();

    $this->assertDatabaseCount('agent_conversations', 1);
});
