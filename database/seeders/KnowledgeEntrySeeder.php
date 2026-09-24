<?php

namespace Database\Seeders;

use App\Models\KnowledgeEntry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class KnowledgeEntrySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the knowledge base with sample entries the retrieval agent can
     * find. Run manually: php artisan db:seed --class=KnowledgeEntrySeeder
     */
    public function run(): void
    {
        $entries = [
            [
                'title' => 'Laravel AI 聊天功能说明',
                'content' => '本项目基于 laravel/ai SDK 实现流式聊天：POST /chat 返回 SSE 事件流，思考型模型（glm-4.5 及以上）会先输出 reasoning 思考过程，最终回答一次性渲染。智谱 provider 使用自定义 zhipu driver。',
                'keywords' => 'laravel,ai,聊天,流式,SSE',
            ],
            [
                'title' => '项目常用命令',
                'content' => 'composer run dev 同时启动 server、queue 与 vite；php artisan test 运行 Pest 测试；vendor/bin/pint --dirty 格式化改动的 PHP 文件；npm run types:check 做前端类型检查。',
                'keywords' => '命令,测试,格式化',
            ],
            [
                'title' => '知识检索助手使用说明',
                'content' => '知识检索助手会先检索知识库（knowledge_entries 表），未命中时再检索当前用户的历史聊天记录，并基于检索结果回答问题；检索不到时会如实说明。',
                'keywords' => 'agent,检索,知识库',
            ],
        ];

        foreach ($entries as $entry) {
            KnowledgeEntry::query()->firstOrCreate(
                ['title' => $entry['title']],
                $entry,
            );
        }
    }
}
