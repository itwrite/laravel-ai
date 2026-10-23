<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Download, ImagePlus, PenTool, Sparkles, X } from '@lucide/vue';
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
import { generateTattoo } from '@/lib/generateTattoo';
import tattooAssistant from '@/routes/tattoo-assistant';
import type { GeneratedTattoo, TattooAssistantForm } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '纹身线稿助手', href: tattooAssistant.show() }],
    },
});

const props = defineProps<{
    aiConfigured: boolean;
}>();

/** Mirrors the style keys accepted by TattooGenerateRequest. */
const styleOptions = [
    { value: 'auto', label: '自动（按创意选择）' },
    { value: 'fine-line', label: '极简单线' },
    { value: 'traditional', label: '美式传统' },
    { value: 'neo-traditional', label: '新传统' },
    { value: 'japanese', label: '日式传统' },
    { value: 'geometric', label: '几何' },
    { value: 'black-grey', label: '写实黑灰' },
    { value: 'tribal', label: '部落图腾' },
    { value: 'sketch', label: '手绘素描' },
];

/** Mirrors the placement keys accepted by TattooGenerateRequest. */
const placementOptions = [
    { value: 'any', label: '不指定' },
    { value: 'forearm', label: '前臂' },
    { value: 'upper-arm', label: '上臂' },
    { value: 'shoulder', label: '肩部' },
    { value: 'back', label: '背部' },
    { value: 'chest', label: '胸口' },
    { value: 'leg', label: '腿部' },
    { value: 'wrist', label: '手腕' },
    { value: 'ankle', label: '脚踝' },
    { value: 'neck', label: '颈部' },
    { value: 'rib', label: '肋侧' },
];

/** Mirrors the ratio keys accepted by TattooGenerateRequest. */
const ratioOptions = [
    { value: 'portrait', label: '竖版 2:3' },
    { value: 'square', label: '方形 1:1' },
    { value: 'landscape', label: '横版 3:2' },
];

/** Mirrors the detail keys accepted by TattooGenerateRequest. */
const detailOptions = [
    { value: 'low', label: '标准 1K（最快）' },
    { value: 'medium', label: '高清 2K' },
    { value: 'high', label: '超清 4K（较慢）' },
];

const form = ref<TattooAssistantForm>({
    subject: '',
    style: 'auto',
    placement: 'any',
    ratio: 'portrait',
    detail: 'medium',
    extras: '',
});

const result = ref<GeneratedTattoo | null>(null);
const isGenerating = ref(false);

const ACCEPTED_IMAGE_TYPES = [
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
];

/** Mirrors the server-side "max:2000000" rule on the reference field. */
const MAX_REFERENCE_DATA_URL_LENGTH = 2_000_000;

const reference = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

/** Mirrors the server-side "min:2" rule on the subject field. */
const canSubmit = computed(
    () => !isGenerating.value && form.value.subject.trim().length >= 2,
);

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }

    isGenerating.value = true;

    try {
        result.value = await generateTattoo(form.value, reference.value);
        toast.success('线稿已生成，可下载或调整后重画。');
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : '生成失败，请稍后重试。',
        );
    } finally {
        isGenerating.value = false;
    }
}

function chooseReference(): void {
    fileInput.value?.click();
}

function onReferenceSelected(event: Event): void {
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

    const reader = new FileReader();

    reader.onload = () => {
        const dataUrl = reader.result;

        if (typeof dataUrl !== 'string') {
            return;
        }

        if (dataUrl.length > MAX_REFERENCE_DATA_URL_LENGTH) {
            toast.error(`参考图过大（限约 1.4MB）：${file.name}`);

            return;
        }

        reference.value = dataUrl;
    };

    reader.readAsDataURL(file);
}

function removeReference(): void {
    reference.value = null;
}

function downloadTattoo(): void {
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
    anchor.download = `tattoo-line-art.${extensionFromMime(mime)}`;
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
    <Head title="纹身线稿助手" />

    <div class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight">纹身线稿助手</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                描述你的纹身创意（或上传参考图），Gemini
                将生成可直接用作纹身手稿的黑白线稿，支持预览与下载。
            </p>
        </div>

        <Alert v-if="!props.aiConfigured" variant="destructive" class="mb-6">
            <AlertTitle>No image provider configured</AlertTitle>
            <AlertDescription>
                Add a <code>GEMINI_API_KEY</code> (or
                <code>XAI_API_KEY</code> as failover) to your
                <code>.env</code> file to enable line art generation. See
                <code>config/ai.php</code>.
            </AlertDescription>
        </Alert>

        <div
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]"
        >
            <Card class="lg:sticky lg:top-6">
                <CardHeader>
                    <CardTitle>设计要求</CardTitle>
                    <CardDescription>创意越具体，线稿越贴合。</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submit">
                        <div class="space-y-2">
                            <Label for="subject">
                                纹身创意
                                <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="subject"
                                v-model="form.subject"
                                rows="4"
                                maxlength="500"
                                required
                                placeholder="例：一条衔着樱花的锦鲤，搭配波浪与云纹，整体纵向延展"
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 w-full resize-y rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                            ></textarea>
                            <p class="text-muted-foreground text-right text-xs">
                                {{ form.subject.length }} / 500
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label>线条风格</Label>
                            <Select v-model="form.style">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="选择风格" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in styleOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label>纹身部位</Label>
                            <Select v-model="form.placement">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="选择部位" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in placementOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label>画幅比例</Label>
                                <Select v-model="form.ratio">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="选择比例" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in ratioOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="space-y-2">
                                <Label>清晰度</Label>
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
                        </div>

                        <div class="space-y-2">
                            <Label>参考图（选填）</Label>
                            <input
                                ref="fileInput"
                                type="file"
                                :accept="ACCEPTED_IMAGE_TYPES.join(',')"
                                class="hidden"
                                @change="onReferenceSelected"
                            />
                            <div v-if="reference === null">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="gap-1.5"
                                    @click="chooseReference"
                                >
                                    <ImagePlus class="size-4" />
                                    上传参考图
                                </Button>
                                <p
                                    class="text-muted-foreground mt-1.5 text-xs leading-relaxed"
                                >
                                    手绘草图、参考图案或照片，AI
                                    将保留其构图与关键元素转绘为线稿
                                    （png/jpg/gif/webp，约 1.4MB 内）。
                                </p>
                            </div>
                            <div
                                v-else
                                class="border-border/70 bg-muted/30 relative inline-flex overflow-hidden rounded-lg border"
                            >
                                <img
                                    :src="reference"
                                    alt="参考图预览"
                                    class="h-24 w-24 object-cover"
                                />
                                <button
                                    type="button"
                                    class="bg-background/80 hover:bg-background absolute top-1 right-1 rounded-full p-1 shadow-sm transition-colors"
                                    title="移除参考图"
                                    @click="removeReference"
                                >
                                    <X class="size-3.5" />
                                </button>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="extras">补充要求</Label>
                            <textarea
                                id="extras"
                                v-model="form.extras"
                                rows="3"
                                maxlength="1000"
                                placeholder="例：线条加粗一些，留白多一些，不要渐变（选填）"
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
                                isGenerating ? '正在绘制，请稍候…' : '生成线稿'
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
                                @click="downloadTattoo"
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
                            <PenTool class="size-7" />
                        </div>
                        <p
                            class="text-muted-foreground max-w-sm text-sm leading-relaxed"
                        >
                            填写左侧创意并点击「生成线稿」，生成后可在此预览、下载图片，或调整选项重新生成。
                        </p>
                    </div>

                    <div
                        v-else-if="isGenerating"
                        class="flex min-h-80 flex-col items-center justify-center gap-3"
                    >
                        <Spinner class="text-muted-foreground size-8" />
                        <p class="text-muted-foreground text-sm">
                            AI 正在绘制线稿，通常需要 10–60 秒，请勿关闭页面…
                        </p>
                    </div>

                    <template v-else>
                        <div
                            class="bg-muted/30 flex justify-center rounded-lg border p-2 sm:p-4"
                        >
                            <img
                                :src="result?.image"
                                alt="生成的纹身线稿"
                                class="max-h-[40rem] w-auto max-w-full rounded-md border bg-white shadow-inner"
                            />
                        </div>
                        <p class="text-muted-foreground mt-3 text-xs">
                            不满意？调整左侧选项或补充要求后再次点击「生成线稿」即可重画；同一创意多次生成会有差异。
                        </p>
                    </template>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
