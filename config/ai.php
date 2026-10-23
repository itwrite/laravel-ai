<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'gemini'),
    'default_for_images' => env('AI_DEFAULT_IMAGE_PROVIDER', 'gemini'),
    'default_for_audio' => env('AI_DEFAULT_AUDIO_PROVIDER', 'gemini'),
    'default_for_transcription' => env('AI_DEFAULT_TRANSCRIPTION_PROVIDER', 'gemini'),
    'default_for_embeddings' => env('AI_DEFAULT_EMBEDDINGS_PROVIDER', 'gemini'),
    'default_for_reranking' => env('AI_DEFAULT_RERANKING_PROVIDER', 'gemini'),

    /*
    |----------------------------------------------------------------------
    | Chat Provider Failover Chain
    |----------------------------------------------------------------------
    |
    | Providers tried in order when the homepage chat streams a response.
    | If one fails with a failoverable error (rate limit, connection
    | failure...), the SDK automatically retries with the next entry.
    | Each entry must exist under "providers" below.
    |
    */

    'chat_providers' => ['zhipu'],

    /*
    |----------------------------------------------------------------------
    | Image Provider Failover Chain
    |----------------------------------------------------------------------
    |
    | Providers tried in order when the tattoo assistant generates an image
    | (mirrors "chat_providers" above). Gemini's free-tier image quota is
    | tiny, so limits fail over to the free Pollinations channel first, and
    | only then to the paid xAI Imagine API (about $0.04/image, capped at
    | 2K). Pollinations cannot honor reference-image attachments, so the
    | tattoo assistant drops it from the chain when one is uploaded.
    | Reorder freely — every entry must exist under "providers" below.
    |
    */

    'image_providers' => ['gemini', 'pollinations', 'xai'],

    /*
    |----------------------------------------------------------------------
    | Conversations
    |----------------------------------------------------------------------
    |
    | Controls the SDK's automatic conversation persistence. Title generation
    | is disabled so starting a chat never triggers an extra model call; the
    | truncated first user message becomes the conversation title instead.
    |
    */

    'conversations' => [
        'generate_title' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
            'assume_role' => [
                'arn' => env('AWS_BEDROCK_ASSUME_ROLE_ARN'),
                'session_name' => env('AWS_BEDROCK_ASSUME_ROLE_SESSION_NAME'),
                'duration_seconds' => env('AWS_BEDROCK_ASSUME_ROLE_DURATION_SECONDS'),
                'external_id' => env('AWS_BEDROCK_ASSUME_ROLE_EXTERNAL_ID'),
            ],
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
            // Image model used by the tattoo line art assistant; switch with
            // GEMINI_IMAGE_MODEL without code changes.
            'models' => [
                'image' => [
                    'default' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image'),
                ],
            ],
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'pollinations' => [
            // Custom driver (App\Ai\Providers\PollinationsProvider, registered
            // in AppServiceProvider): free text-to-image API used as a free
            // rung of the "image_providers" failover chain. No key is needed
            // on the anonymous tier; set POLLINATIONS_TOKEN for higher limits.
            'driver' => 'pollinations',
            'url' => env('POLLINATIONS_URL', 'https://image.pollinations.ai'),
            'model' => env('POLLINATIONS_MODEL', 'flux'),
            'token' => env('POLLINATIONS_TOKEN'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            // Native SDK driver: Grok text plus image generation via the
            // Imagine API (aspect_ratio / resolution are handled natively;
            // outbound requests go through the global HTTP proxy).
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
            'url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
            'models' => [
                // Image model used when the image failover chain reaches
                // xAI (see "image_providers" above).
                'image' => [
                    'default' => env('XAI_IMAGE_MODEL', 'grok-imagine-image-2.0'),
                ],
            ],
        ],

        'zhipu' => [
            // Custom driver (App\Ai\Providers\ZhipuProvider, registered in
            // AppServiceProvider): openai-compatible plus reasoning streaming.
            'driver' => 'zhipu',
            'url' => env('ZHIPU_BASE_URL', 'https://open.bigmodel.cn/api/paas/v4'),
            'key' => env('ZHIPU_API_KEY'),
            // Ask thinking-capable GLM models (glm-4.5 and later) to stream
            // their chain-of-thought; set ZHIPU_THINKING=false to stream
            // answers only (e.g. for non-thinking models like glm-4-flash).
            'thinking' => env('ZHIPU_THINKING', true),
            'models' => [
                'text' => [
                    'default' => env('ZHIPU_MODEL', 'glm-5.3'),
                    // 'cheapest' => 'glm-4-flash',
                    // 'smartest' => 'glm-4.6',
                ],
            ],
        ],
    ],

];
