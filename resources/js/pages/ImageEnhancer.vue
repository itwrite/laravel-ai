<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Download, ImagePlus, Maximize2, Sparkles, X } from '@lucide/vue';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { enhanceImage, readImageFile } from '@/lib/enhanceImage';
import imageEnhancer from '@/routes/image-enhancer';
import type { EnhancedImage, ImageEnhancerForm } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '图片超清助手', href: imageEnhancer.show() }],
    },
});

const props = defineProps<{
    aiConfigured: boolean;
}>();

/** Mirrors the goal keys accepted by ImageEnhanceRequest. */
const goalOptions = [
    { value: 'auto', label: '自动（按图片选择）' },
    { value: 'general', label: '通用超清' },
    { value: 'portrait', label: '人像照片' },
    { value: 'product', label: '产品图' },
    { value: 'anime', label: '插画 / 动漫' },
    { value: 'restore', label: '老照片修复' },
    { value: 'remove-handwriting', label: '去手写笔迹' },
];

/** Mirrors the detail keys accepted by ImageEnhanceRequest. */
const detailOptions = [
    { value: 'high', label: '超清 4K（较慢，默认）' },
    { value: 'medium', label: '高清 2K（更快）' },
];

const form = ref<ImageEnhancerForm>({
    goal: 'auto',
    detail: 'high',
    extras: '',
});

const result = ref<EnhancedImage | null>(null);
const isGenerating = ref(false);

const ACCEPTED_IMAGE_TYPES = [
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
];

const sourceImage = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

/** The uploaded source image is mandatory. */
const canSubmit = computed(
    () => !isGenerating.value && sourceImage.value !== null,
);

async function submit(): Promise<void> {
    if (!canSubmit.value || sourceImage.value === null) {
        return;
    }

    isGenerating.value = true;

    try {
        result.value = await enhanceImage(form.value, sourceImage.value);
        toast.success('超清图片已生成，可下载或更换原图重新处理。');
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : '处理失败，请稍后重试。',
        );
    } finally {
        isGenerating.value = false;
    }
}

function chooseImage(): void {
    fileInput.value?.click();
}

async function onImageSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    input.value = '';

    if (file === undefined) {
        return;
    }

    if (!ACCEPTED_IMAGE_TYPES.includes(file.type)) {
        toast.error(`不支持的图片格式：${file.name || file.type}`);

        return;
    }

    try {
        sourceImage.value = await readImageFile(file);
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : '读取图片失败，请重试。',
        );
    }
}

function removeImage(): void {
    sourceImage.value = null;
}

function downloadEnhanced(): void {
    const current = result.value;

    if (current === null) {
        return;
    }

    const [meta, base64] = current.image.split(',', 2);
    const mime = meta.slice('data:'.length);
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index++) {
        bytes[index] = binary.charCodeAt(index);
    }

    const blob = new Blob([bytes], { type: mime });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = `enhanced-image.${extensionFromMime(mime)}`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

/**
 * Derive the download file extension from the MIME type the provider
 * reported, falling back to png.
 */
function extensionFromMime(mime: string): string {
    const extensions: Record<string, string> = {
        'image/png': 'png',
        'image/jpeg': 'jpg',
        'image/webp': 'webp',
    };

    return extensions[mime] ?? 'png';
}
</script>

<template>
    <Head title="图片超清助手" />

    <div class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight">图片超清助手</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                上传一张图片，AI
                将在保留构图与内容的前提下重绘为超高清版本，支持人像、产品图、老照片修复等场景，可预览与下载。
            </p>
        </div>

        <Alert v-if="!props.aiConfigured" variant="destructive" class="mb-6">
            <AlertTitle>No image provider configured</AlertTitle>
            <AlertDescription>
                Add a <code>GEMINI_API_KEY</code> (or
                <code>XAI_API_KEY</code> as failover) to your
                <code>.env</code> file to enable image enhancement. See
                <code>config/ai.php</code>.
            </AlertDescription>
        </Alert>

        <div
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]"
        >
            <Card class="lg:sticky lg:top-6">
                <CardHeader>
                    <CardTitle>原图与设置</CardTitle>
                    <CardDescription
                        >原图越清晰，超清效果越好。</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submit">
                        <div class="space-y-2">
                            <Label>
                                原图
                                <span class="text-destructive">*</span>
                            </Label>
                            <input
                                ref="fileInput"
                                type="file"
                                :accept="ACCEPTED_IMAGE_TYPES.join(',')"
                                class="hidden"
                                @change="onImageSelected"
                            />
                            <div v-if="sourceImage === null">
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="w-full gap-1.5 border-dashed py-8"
                                    @click="chooseImage"
                                >
                                    <ImagePlus class="size-5" />
                                    点击上传图片
                                </Button>
                                <p
                                    class="text-muted-foreground mt-1.5 text-xs leading-relaxed"
                                >
                                    png/jpg/gif/webp，过大的图片会自动压缩后上传。
                                </p>
                            </div>
                            <div v-else class="space-y-2">
                                <div
                                    class="border-border/70 bg-muted/30 relative inline-flex overflow-hidden rounded-lg border"
                                >
                                    <img
                                        :src="sourceImage"
                                        alt="原图预览"
                                        class="h-40 w-40 object-cover"
                                    />
                                    <button
                                        type="button"
                                        class="bg-background/80 hover:bg-background absolute top-1 right-1 rounded-full p-1 shadow-sm transition-colors"
                                        title="移除图片"
                                        @click="removeImage"
                                    >
                                        <X class="size-3.5" />
                                    </button>
                                </div>
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="gap-1.5"
                                        @click="chooseImage"
                                    >
                                        <ImagePlus class="size-4" />
                                        更换图片
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label>增强场景</Label>
                            <Select v-model="form.goal">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="选择场景" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in goalOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label>输出清晰度</Label>
                            <Select v-model="form.detail">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="选择清晰度" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in detailOptions"
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
                                placeholder="例：保留背景文字，颜色再鲜艳一些（选填）"
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
                                    ? '正在处理，请稍候…'
                                    : '生成超清图片'
                            }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <Card class="min-h-[30rem]">
                <CardHeader>
                    <div class="flex flex-wrap items-center gap-2">
                        <CardTitle>预览与导出</CardTitle>
                        <div
                            v-if="result !== null"
                            class="ml-auto flex flex-wrap items-center gap-1.5"
                        >
                            <Button
                                size="sm"
                                class="gap-1.5"
                                @click="downloadEnhanced"
                            >
                                <Download class="size-4" />
                                下载图片
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="result === null && !isGenerating"
                        class="flex min-h-80 flex-col items-center justify-center gap-3 text-center"
                    >
                        <div
                            class="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl"
                        >
                            <Maximize2 class="size-7" />
                        </div>
                        <p
                            class="text-muted-foreground max-w-sm text-sm leading-relaxed"
                        >
                            上传原图并点击「生成超清图片」，AI
                            将保留构图与内容、重绘为更高分辨率的版本，生成后可在此预览与下载。
                        </p>
                    </div>

                    <div
                        v-else-if="isGenerating"
                        class="flex min-h-80 flex-col items-center justify-center gap-3"
                    >
                        <Spinner class="text-muted-foreground size-8" />
                        <p class="text-muted-foreground text-sm">
                            AI 正在重绘超清版本，通常需要 10–90
                            秒，请勿关闭页面…
                        </p>
                    </div>

                    <template v-else>
                        <div
                            class="bg-muted/30 flex justify-center rounded-lg border p-2 sm:p-4"
                        >
                            <img
                                :src="result?.image"
                                alt="超清处理结果"
                                class="max-h-[40rem] w-auto max-w-full rounded-md border bg-white shadow-inner"
                            />
                        </div>
                        <p class="text-muted-foreground mt-3 text-xs">
                            不满意？更换原图、调整场景或补充要求后再次点击「生成超清图片」即可重新处理。
                        </p>
                    </template>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
