<script setup lang="ts">
import { computed, ref, type Component } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Check,
    Code2,
    Copy,
    Download,
    FileText,
    Monitor,
    Smartphone,
    Sparkles,
    Tablet,
} from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { generateArticle } from '@/lib/generateArticle';
import articleAssistant from '@/routes/article-assistant';
import type { ArticleAssistantForm, GeneratedArticle } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '文章助手', href: articleAssistant.show() }],
    },
});

const props = defineProps<{
    aiConfigured: boolean;
}>();

/** Mirrors the tone keys accepted by ArticleGenerateRequest. */
const toneOptions = [
    { value: 'auto', label: '自动（按题材选择）' },
    { value: 'professional', label: '专业严谨' },
    { value: 'friendly', label: '亲切易懂' },
    { value: 'formal', label: '正式书面' },
    { value: 'passionate', label: '热情有感染力' },
    { value: 'playful', label: '活泼有趣' },
    { value: 'academic', label: '学术规范' },
];

const lengthOptions = [
    { value: 'short', label: '短篇（约 500 字）' },
    { value: 'medium', label: '中篇（约 1000 字）' },
    { value: 'long', label: '长篇（约 2000 字）' },
];

const form = ref<ArticleAssistantForm>({
    topic: '',
    content: '',
    audience: '',
    tone: 'auto',
    length: 'medium',
    extras: '',
});

const article = ref<GeneratedArticle | null>(null);
const isGenerating = ref(false);
const showSource = ref(false);
const copied = ref(false);

type PreviewWidth = 'desktop' | 'tablet' | 'mobile';

const previewWidth = ref<PreviewWidth>('desktop');

const previewOptions: Array<{
    value: PreviewWidth;
    label: string;
    icon: Component;
}> = [
    { value: 'desktop', label: '桌面宽度', icon: Monitor },
    { value: 'tablet', label: '平板宽度', icon: Tablet },
    { value: 'mobile', label: '手机宽度', icon: Smartphone },
];

const previewWidths: Record<PreviewWidth, string> = {
    desktop: '100%',
    tablet: '768px',
    mobile: '390px',
};

/** Mirrors the server-side "min:10" rule on the content field. */
const canSubmit = computed(
    () => !isGenerating.value && form.value.content.trim().length >= 10,
);

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }

    isGenerating.value = true;

    try {
        article.value = await generateArticle(form.value);
        showSource.value = false;
        previewWidth.value = 'desktop';
        toast.success('文章已生成，可预览或下载。');
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : '生成失败，请稍后重试。',
        );
    } finally {
        isGenerating.value = false;
    }
}

function downloadArticle(): void {
    if (article.value === null) {
        return;
    }

    const blob = new Blob([article.value.html], {
        type: 'text/html;charset=utf-8',
    });

    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = downloadFilename();
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

/**
 * Derive a filesystem-safe filename from the article title, replacing
 * characters that browsers reject in downloads.
 */
function downloadFilename(): string {
    const safe = (article.value?.title ?? '')
        .replace(/[\\/:*?"<>|]+/g, '_')
        .replace(/\s+/g, '_')
        .replace(/^_+|_+$/g, '');

    if (safe === '') {
        return 'article.html';
    }

    return `${safe.slice(0, 60)}.html`;
}

async function copySource(): Promise<void> {
    if (article.value === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(article.value.html);

        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        toast.error('复制失败，请改用「查看源码」手动复制。');
    }
}
</script>

<template>
    <Head title="文章助手" />

    <div class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight">文章助手</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                输入素材与要求，AI 将优化整理并生成可预览、可下载的响应式 HTML
                文章。
            </p>
        </div>

        <Alert v-if="!props.aiConfigured" variant="destructive" class="mb-6">
            <AlertTitle>No AI provider configured</AlertTitle>
            <AlertDescription>
                Add an API key (e.g. <code>OPENAI_API_KEY</code>) to your
                <code>.env</code> file, or point <code>ai.default</code> at a
                local Ollama instance. See <code>config/ai.php</code>.
            </AlertDescription>
        </Alert>

        <div
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]"
        >
            <Card class="lg:sticky lg:top-6">
                <CardHeader>
                    <CardTitle>文章信息</CardTitle>
                    <CardDescription
                        >素材越具体，生成效果越好。</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submit">
                        <div class="space-y-2">
                            <Label for="topic">文章主题</Label>
                            <Input
                                id="topic"
                                v-model="form.topic"
                                maxlength="120"
                                placeholder="例：公司 2026 秋季新品发布会回顾"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="content">
                                文章素材
                                <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="content"
                                v-model="form.content"
                                rows="10"
                                maxlength="8000"
                                required
                                placeholder="粘贴原始素材、要点或草稿，AI 会优化整理成文（至少 10 个字符）"
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 w-full resize-y rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                            ></textarea>
                            <p class="text-muted-foreground text-right text-xs">
                                {{ form.content.length }} / 8000
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="audience">目标读者</Label>
                                <Input
                                    id="audience"
                                    v-model="form.audience"
                                    maxlength="120"
                                    placeholder="例：潜在客户"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label>语言风格</Label>
                                <Select v-model="form.tone">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="选择风格" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in toneOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label>篇幅</Label>
                            <Select v-model="form.length">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="选择篇幅" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in lengthOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label for="extras">补充要求</Label>
                            <textarea
                                id="extras"
                                v-model="form.extras"
                                rows="3"
                                maxlength="1000"
                                placeholder="例：结尾加行动号召，突出限时优惠（选填）"
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 w-full resize-y rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                            ></textarea>
                        </div>

                        <Button
                            type="submit"
                            class="w-full gap-2"
                            :disabled="!canSubmit"
                        >
                            <Spinner v-if="isGenerating" class="size-4" />
                            <Sparkles v-else class="size-4" />
                            {{
                                isGenerating
                                    ? '正在生成，请稍候…'
                                    : '生成 HTML 文章'
                            }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <Card class="min-h-[30rem]">
                <CardHeader>
                    <div class="flex flex-wrap items-center gap-2">
                        <CardTitle>预览与导出</CardTitle>
                        <span
                            v-if="article !== null && article.title !== null"
                            class="text-muted-foreground truncate text-sm"
                        >
                            {{ article.title }}
                        </span>
                        <div
                            v-if="article !== null"
                            class="ml-auto flex flex-wrap items-center gap-1.5"
                        >
                            <div
                                class="border-border/70 bg-muted/50 flex items-center gap-0.5 rounded-lg border p-0.5"
                            >
                                <Button
                                    v-for="option in previewOptions"
                                    :key="option.value"
                                    variant="ghost"
                                    size="icon-sm"
                                    :class="
                                        previewWidth === option.value
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground'
                                    "
                                    :title="option.label"
                                    @click="previewWidth = option.value"
                                >
                                    <component
                                        :is="option.icon"
                                        class="size-4"
                                    />
                                </Button>
                            </div>

                            <Button
                                variant="ghost"
                                size="sm"
                                class="gap-1.5"
                                @click="showSource = !showSource"
                            >
                                <Code2 class="size-4" />
                                {{ showSource ? '隐藏源码' : '查看源码' }}
                            </Button>

                            <Button
                                variant="outline"
                                size="sm"
                                class="gap-1.5"
                                @click="copySource"
                            >
                                <Check v-if="copied" class="size-4" />
                                <Copy v-else class="size-4" />
                                {{ copied ? '已复制' : '复制源码' }}
                            </Button>

                            <Button
                                size="sm"
                                class="gap-1.5"
                                @click="downloadArticle"
                            >
                                <Download class="size-4" />
                                下载 HTML
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="article === null && !isGenerating"
                        class="flex min-h-80 flex-col items-center justify-center gap-3 text-center"
                    >
                        <div
                            class="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl"
                        >
                            <FileText class="size-7" />
                        </div>
                        <p
                            class="text-muted-foreground max-w-sm text-sm leading-relaxed"
                        >
                            填写左侧素材并点击「生成 HTML
                            文章」，生成后可在此预览效果、复制源码或下载 .html
                            文件。
                        </p>
                    </div>

                    <div
                        v-else-if="isGenerating"
                        class="flex min-h-80 flex-col items-center justify-center gap-3"
                    >
                        <Spinner class="text-muted-foreground size-8" />
                        <p class="text-muted-foreground text-sm">
                            AI 正在优化整理素材，通常需要 30–120
                            秒，请勿关闭页面…
                        </p>
                    </div>

                    <template v-else>
                        <div
                            v-if="showSource"
                            class="border-border/70 bg-muted/30 mb-4 max-h-72 overflow-auto rounded-lg border p-4"
                        >
                            <pre
                                class="text-muted-foreground text-xs leading-relaxed break-all whitespace-pre-wrap"
                                >{{ article?.html }}</pre>
                        </div>

                        <div
                            class="bg-muted/30 flex justify-center rounded-lg border p-2 sm:p-4"
                        >
                            <iframe
                                title="文章预览"
                                :srcdoc="article?.html ?? ''"
                                sandbox=""
                                class="h-[40rem] max-w-full rounded-md border bg-white shadow-inner transition-[width] duration-300"
                                :style="{ width: previewWidths[previewWidth] }"
                            ></iframe>
                        </div>
                    </template>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
