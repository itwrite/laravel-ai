<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUp,
    BrainCircuit,
    Check,
    ChevronDown,
    Copy,
    FileText,
    History,
    ImagePlus,
    RotateCcw,
    Sparkles,
    Square,
    Trash2,
    X,
} from '@lucide/vue';
import { toast } from 'vue-sonner';
import { streamChat } from '@/lib/streamChat';
import { renderMarkdown } from '@/lib/markdown';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { destroy as destroyConversationAction } from '@/actions/App/Http/Controllers/Ai/ChatConversationController';
import articleAssistant from '@/routes/article-assistant';
import { chat } from '@/routes';
import type {
    ChatAgentOption,
    ChatConversationDetail,
    ChatConversationSummary,
    ChatMessage,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'AI Chat', href: chat() }],
    },
});

const props = defineProps<{
    aiConfigured: boolean;
    agents: ChatAgentOption[];
    conversations: ChatConversationSummary[];
    activeConversation: ChatConversationDetail | null;
}>();

const selectedAgentKey = ref<string>(
    props.activeConversation?.agent ?? props.agents[0]?.key ?? 'general',
);

const activeAgent = computed(
    () =>
        props.agents.find((agent) => agent.key === selectedAgentKey.value) ??
        props.agents[0],
);

const suggestions = computed(() => activeAgent.value?.suggestions ?? []);

const input = ref('');
const messages = ref<ChatDisplayMessage[]>([]);
const isStreaming = ref(false);
const abortController = ref<AbortController | null>(null);
const scrollContainer = useTemplateRef<HTMLDivElement>('scrollContainer');
const inputElement = useTemplateRef<HTMLTextAreaElement>('inputElement');
const fileInput = useTemplateRef<HTMLInputElement>('fileInput');

/** Stored conversation the local transcript belongs to, if any. */
const conversationId = ref<string | null>(props.activeConversation?.id ?? null);

/** History drawer visibility (stored conversations, logged-in users). */
const historyOpen = ref(false);

/**
 * Transcript entry carrying a locally-assigned id so v-for keys stay stable
 * across sends (ChatMessage itself has none).
 */
interface ChatDisplayMessage extends ChatMessage {
    id: number;
    /**
     * Chain-of-thought streamed before the answer (reasoning-capable models
     * only). Display-only: it never becomes part of the message content.
     */
    reasoning?: string;
    /** Whether the reasoning phase finished (live preview collapsed). */
    reasoningDone?: boolean;
    /** Whether the full reasoning transcript is expanded for reading. */
    reasoningExpanded?: boolean;
    /** performance.now() stamps used to show the thinking duration. */
    reasoningStartedAt?: number;
    reasoningEndedAt?: number;
}

interface PendingImage {
    id: number;
    dataUrl: string;
}

/** Mirrors the server-side ChatRequest limits for inline images. */
const MAX_IMAGES = 3;
const MAX_IMAGE_DATA_URL_LENGTH = 2_000_000;
const ACCEPTED_IMAGE_TYPES = [
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
];

const pendingImages = ref<PendingImage[]>([]);
let pendingImageId = 0;

let messageSeq = 0;

/** Whether the transcript is pinned to the bottom, for smart auto-scrolling. */
const isAtBottom = ref(true);
const NEAR_BOTTOM_THRESHOLD = 48;

/** Characters of the reasoning tail shown while the model is thinking. */
const REASONING_TAIL_LENGTH = 160;

/** Id of the assistant message whose full text was copied just now. */
const copiedMessageId = ref<number | null>(null);

const canSend = computed(
    () =>
        (input.value.trim() !== '' || pendingImages.value.length > 0) &&
        !isStreaming.value,
);

async function submit(): Promise<void> {
    await send();
}

async function ask(text: string): Promise<void> {
    await send(text);
}

async function send(text?: string): Promise<void> {
    const message = (text ?? input.value).trim();

    if (
        (message === '' && pendingImages.value.length === 0) ||
        isStreaming.value
    ) {
        return;
    }

    input.value = '';

    // Stored conversations keep their context server-side; ephemeral chats
    // send the local transcript as the history instead.
    const history = conversationId.value === null ? [...messages.value] : [];
    const images = pendingImages.value.map((image) => image.dataUrl);

    pendingImages.value = [];

    // Sending always re-pins the transcript to the newest message.
    isAtBottom.value = true;

    messages.value = [
        ...history,
        {
            id: messageSeq++,
            role: 'user',
            content: message,
            images: images.length > 0 ? images : undefined,
        },
        { id: messageSeq++, role: 'assistant', content: '' },
    ];

    const controller = new AbortController();

    abortController.value = controller;
    isStreaming.value = true;

    // The answer is revealed in a single render when the stream finishes;
    // deltas are collected here silently so markdown (code fences, formulas)
    // never paints half-generated. Stopped or failed streams still reveal
    // whatever partial answer arrived.
    let answerBuffer = '';

    try {
        await streamChat({
            agent: selectedAgentKey.value,
            message,
            images: images.length > 0 ? images : undefined,
            conversation: conversationId.value ?? undefined,
            history,
            signal: controller.signal,
            onDelta: (delta: string) => {
                answerBuffer += delta;
            },
            onReasoning: (delta: string) => {
                const lastMessage =
                    messages.value[messages.value.length - 1] ?? null;

                if (!lastMessage) {
                    return;
                }

                if (lastMessage.reasoning === undefined) {
                    lastMessage.reasoningStartedAt = performance.now();
                }

                lastMessage.reasoning = (lastMessage.reasoning ?? '') + delta;
            },
            onReasoningEnd: () => {
                finalizeReasoning();
            },
            onConversation: (id: string) => {
                conversationId.value = id;

                void router.reload({ only: ['conversations'] });
            },
        });
    } catch (error) {
        if (!isAbortError(error)) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The AI request failed.',
            );
        }
    } finally {
        isStreaming.value = false;
        abortController.value = null;
        finalizeReasoning();
        commitAnswer(answerBuffer);
        dropEmptyAssistantMessage();
        focusInput();
    }
}

function stopStreaming(): void {
    abortController.value?.abort();
}

/**
 * Attach image files picked via the file dialog or pasted into the textarea.
 * Files are validated against the server contract and inlined as data URLs.
 */
function attachImages(files: FileList | File[] | null | undefined): void {
    if (!files) {
        return;
    }

    for (const file of Array.from(files)) {
        if (pendingImages.value.length >= MAX_IMAGES) {
            toast.error(`最多只能附带 ${MAX_IMAGES} 张图片。`);

            return;
        }

        if (!ACCEPTED_IMAGE_TYPES.includes(file.type)) {
            toast.error(`不支持的图片格式：${file.name || file.type}`);

            continue;
        }

        const reader = new FileReader();

        reader.onload = () => {
            const dataUrl = reader.result;

            if (typeof dataUrl !== 'string') {
                return;
            }

            if (dataUrl.length > MAX_IMAGE_DATA_URL_LENGTH) {
                toast.error(`图片过大（限约 1.4MB）：${file.name}`);

                return;
            }

            pendingImages.value = [
                ...pendingImages.value,
                { id: pendingImageId++, dataUrl },
            ];
        };

        reader.readAsDataURL(file);
    }
}

function onImagesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;

    attachImages(input.files);
    input.value = '';
}

function onPaste(event: ClipboardEvent): void {
    attachImages(event.clipboardData?.files);
}

function removePendingImage(id: number): void {
    pendingImages.value = pendingImages.value.filter(
        (image) => image.id !== id,
    );
}

/**
 * Copy-button clicks inside rendered Markdown are handled here through event
 * delegation, since the message HTML arrives via v-html.
 */
function onMessagesClick(event: MouseEvent): void {
    const trigger = (event.target as HTMLElement | null)?.closest<HTMLElement>(
        '.chat-code-copy',
    );

    if (!trigger) {
        return;
    }

    const code =
        trigger.closest('.chat-code-block')?.querySelector('pre')?.innerText ??
        '';

    void copyCode(trigger, code);
}

async function copyCode(trigger: HTMLElement, code: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(code);
    } catch {
        toast.error('复制失败，请手动选择代码复制。');

        return;
    }

    trigger.textContent = '已复制';

    window.setTimeout(() => {
        trigger.textContent = '复制';
    }, 1500);
}

function resetConversation(): void {
    abortController.value?.abort();
    messages.value = [];
    conversationId.value = null;
    focusInput();
}

/**
 * Switch to another agent: each persona gets a fresh conversation, so stop
 * any in-flight stream and clear the transcript.
 */
function switchAgent(key: string): void {
    if (key === selectedAgentKey.value) {
        return;
    }

    abortController.value?.abort();
    selectedAgentKey.value = key;
    messages.value = [];
    conversationId.value = null;
    focusInput();
}

/**
 * Open the standalone article assistant tool from the agent switcher. It is
 * not a chat agent, so stop any in-flight stream before navigating away.
 */
function openArticleAssistant(): void {
    abortController.value?.abort();
    router.visit(articleAssistant.show());
}

function isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

/**
 * Remove the assistant placeholder when nothing was generated (e.g. the
 * request failed or the user stopped the stream before the first token).
 * A message with only a reasoning trace is kept — it still tells the story.
 */
function dropEmptyAssistantMessage(): void {
    const lastMessage = messages.value[messages.value.length - 1] ?? null;

    if (
        lastMessage &&
        lastMessage.role === 'assistant' &&
        lastMessage.content.trim() === '' &&
        (lastMessage.reasoning ?? '').trim() === ''
    ) {
        messages.value = messages.value.slice(0, -1);
    }
}

/**
 * Close the live reasoning preview of the last assistant message: the answer
 * has started, the stream was aborted mid-thought, or the request failed.
 * The thinking trace stays readable through the collapsed summary toggle.
 */
function finalizeReasoning(): void {
    const lastMessage = messages.value[messages.value.length - 1] ?? null;

    if (!lastMessage || lastMessage.role !== 'assistant') {
        return;
    }

    if (lastMessage.reasoning !== undefined && !lastMessage.reasoningDone) {
        lastMessage.reasoningEndedAt =
            lastMessage.reasoningStartedAt ?? performance.now();
        lastMessage.reasoningDone = true;
        lastMessage.reasoningExpanded = false;
    }
}

/**
 * Reveal the buffered answer in a single render: stream deltas were collected
 * silently, so the markdown (code blocks, formulas, tables) only paints once,
 * complete — the thinking preview above keeps its own live streaming.
 */
function commitAnswer(buffer: string): void {
    const lastMessage = messages.value[messages.value.length - 1] ?? null;

    if (lastMessage && lastMessage.role === 'assistant') {
        lastMessage.content = buffer;
    }
}

/** Whether the message's reasoning is still streaming in right now. */
function isReasoningActive(
    message: ChatDisplayMessage,
    index: number,
): boolean {
    return (
        isStreaming.value &&
        index === messages.value.length - 1 &&
        message.reasoningDone !== true
    );
}

/** Live "tail window" of the reasoning: only its newest characters. */
function reasoningTail(message: ChatDisplayMessage): string {
    const text = message.reasoning ?? '';

    return text.length > REASONING_TAIL_LENGTH
        ? `…${text.slice(-REASONING_TAIL_LENGTH)}`
        : text;
}

/** Collapsed summary label, e.g. "已深度思考 · 3 秒 · 128 字". */
function reasoningSummaryLabel(message: ChatDisplayMessage): string {
    const chars = (message.reasoning ?? '').length;

    if (!message.reasoningDone) {
        return `正在思考… · ${chars} 字`;
    }

    const seconds =
        message.reasoningStartedAt !== undefined &&
        message.reasoningEndedAt !== undefined
            ? Math.max(
                  1,
                  Math.round(
                      (message.reasoningEndedAt - message.reasoningStartedAt) /
                          1000,
                  ),
              )
            : null;

    return seconds === null
        ? `已深度思考 · ${chars} 字`
        : `已深度思考 · ${seconds} 秒 · ${chars} 字`;
}

/** Expand/collapse the full reasoning transcript of a message. */
function toggleReasoning(message: ChatDisplayMessage): void {
    message.reasoningExpanded = !(message.reasoningExpanded ?? false);
}

function focusInput(): void {
    void nextTick(() => inputElement.value?.focus());
}

/**
 * Keep the transcript glued to the bottom while streaming, but stop following
 * as soon as the user scrolls up to read older messages.
 */
function onScroll(): void {
    const container = scrollContainer.value;

    if (!container) {
        return;
    }

    isAtBottom.value =
        container.scrollHeight - container.scrollTop - container.clientHeight <
        NEAR_BOTTOM_THRESHOLD;
}

function scrollToBottom(): void {
    isAtBottom.value = true;

    const container = scrollContainer.value;

    if (container) {
        container.scrollTop = container.scrollHeight;
    }
}

/** Copy the full text of an assistant message with brief visual feedback. */
async function copyMessage(message: ChatDisplayMessage): Promise<void> {
    try {
        await navigator.clipboard.writeText(message.content);
    } catch {
        toast.error('复制失败，请手动选择内容复制。');

        return;
    }

    copiedMessageId.value = message.id;

    window.setTimeout(() => {
        copiedMessageId.value = null;
    }, 1500);
}

watch(
    messages,
    () => {
        if (!isAtBottom.value) {
            return;
        }

        void nextTick(() => {
            const container = scrollContainer.value;

            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        });
    },
    { deep: true },
);

/** Rebuild the local transcript from a loaded stored conversation. */
function hydrateFromActiveConversation(
    conversation: ChatConversationDetail | null,
): void {
    conversationId.value = conversation?.id ?? null;
    selectedAgentKey.value =
        conversation?.agent ?? props.agents[0]?.key ?? 'general';

    messages.value = (conversation?.messages ?? []).map((message) => ({
        id: messageSeq++,
        role: message.role,
        content: message.content,
    }));
}

hydrateFromActiveConversation(props.activeConversation);

// Scrolled-in transcripts (direct URL or history drawer) start at the
// newest message.
void nextTick(() => {
    const container = scrollContainer.value;

    if (container) {
        container.scrollTop = container.scrollHeight;
    }
});

watch(
    () => props.activeConversation,
    (conversation) => {
        hydrateFromActiveConversation(conversation);
        isAtBottom.value = true;

        void nextTick(() => {
            const container = scrollContainer.value;

            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        });
    },
);

function agentNameOf(key: string | null): string {
    return props.agents.find((agent) => agent.key === key)?.name ?? '通用助手';
}

function formatRelativeTime(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const timestamp = Date.parse(iso);

    if (Number.isNaN(timestamp)) {
        return '';
    }

    const minutes = Math.floor((Date.now() - timestamp) / 60_000);

    if (minutes < 1) {
        return '刚刚';
    }

    if (minutes < 60) {
        return `${minutes} 分钟前`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours} 小时前`;
    }

    const days = Math.floor(hours / 24);

    if (days < 30) {
        return `${days} 天前`;
    }

    return new Date(timestamp).toLocaleDateString();
}

/** Open a stored conversation for review and continuation. */
function openConversation(id: string): void {
    historyOpen.value = false;

    void router.visit(chat({ query: { conversation: id } }).url, {
        only: ['activeConversation', 'conversations'],
        preserveScroll: true,
    });
}

function deleteConversation(id: string): void {
    void router.delete(destroyConversationAction({ conversation: id }).url, {
        only: ['conversations'],
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            // Deleting the open conversation resets the transcript.
            if (conversationId.value === id) {
                messages.value = [];
                conversationId.value = null;
            }
        },
    });
}
</script>

<template>
    <Head title="AI Chat" />

    <div class="flex h-full min-h-0 w-full flex-1 flex-col">
        <main
            class="mx-auto flex min-h-0 w-full max-w-7xl flex-1 flex-col px-4"
        >
            <!-- Chat controls; layout chrome (sidebar/header) comes from AppLayout. -->
            <div class="flex items-center gap-1.5 pt-3">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="gap-2 rounded-full px-2"
                        >
                            <Sparkles class="text-primary size-4" />
                            <span class="max-w-44 truncate font-medium">
                                {{ activeAgent?.name ?? '通用助手' }}
                            </span>
                            <ChevronDown
                                class="text-muted-foreground size-3.5"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-72">
                        <DropdownMenuLabel>选择专属 Agent</DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="catalogAgent in props.agents"
                            :key="catalogAgent.key"
                            class="flex flex-col items-start gap-0.5"
                            @click="switchAgent(catalogAgent.key)"
                        >
                            <span
                                class="flex items-center gap-1.5 text-sm font-medium"
                            >
                                {{ catalogAgent.name }}
                                <Check
                                    v-if="catalogAgent.key === selectedAgentKey"
                                    class="text-primary size-3.5"
                                />
                            </span>
                            <span class="text-muted-foreground text-xs">
                                {{ catalogAgent.description }}
                            </span>
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <!-- Standalone tool, not a chat agent: navigates away. -->
                        <DropdownMenuItem
                            class="flex flex-col items-start gap-0.5"
                            @click="openArticleAssistant()"
                        >
                            <span
                                class="flex items-center gap-1.5 text-sm font-medium"
                            >
                                <FileText class="text-primary size-3.5" />
                                文章助手
                            </span>
                            <span class="text-muted-foreground text-xs">
                                整理素材生成响应式 HTML 文章，支持预览与下载
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <div class="ml-auto flex items-center gap-1.5">
                    <Button
                        v-if="messages.length > 0"
                        variant="ghost"
                        size="sm"
                        class="gap-1.5"
                        title="开始新对话"
                        @click="resetConversation"
                    >
                        <RotateCcw class="size-3.5" />
                        <span class="hidden sm:inline">新对话</span>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        title="聊天历史"
                        @click="historyOpen = true"
                    >
                        <History class="size-4" />
                    </Button>
                </div>
            </div>
            <Alert
                v-if="!props.aiConfigured"
                variant="destructive"
                class="mt-4"
            >
                <AlertTitle>No AI provider configured</AlertTitle>
                <AlertDescription>
                    Add an API key (e.g. <code>OPENAI_API_KEY</code>) to your
                    <code>.env</code> file, or point <code>ai.default</code> at
                    a local Ollama instance. See <code>config/ai.php</code>.
                </AlertDescription>
            </Alert>

            <!-- Transcript -->
            <div
                ref="scrollContainer"
                class="min-h-0 flex-1 space-y-6 overflow-y-auto py-6"
                @click="onMessagesClick"
                @scroll="onScroll"
            >
                <!-- Empty state -->
                <div
                    v-if="messages.length === 0"
                    class="flex h-full min-h-72 flex-col items-center justify-center gap-8 py-8"
                >
                    <div class="flex flex-col items-center gap-3 text-center">
                        <div
                            class="bg-primary text-primary-foreground flex size-14 items-center justify-center rounded-2xl shadow-sm"
                        >
                            <Sparkles class="size-7" />
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold tracking-tight">
                                Laravel AI
                            </h1>
                            <p
                                class="text-muted-foreground mx-auto mt-1 max-w-md text-sm"
                            >
                                {{
                                    activeAgent?.description ??
                                    'Powered by the official Laravel AI SDK — answers stream straight from your server.'
                                }}
                            </p>
                        </div>
                    </div>

                    <div class="grid w-full max-w-3xl gap-2 sm:grid-cols-2">
                        <button
                            v-for="suggestion in suggestions"
                            :key="suggestion"
                            type="button"
                            class="group border-border/70 hover:border-ring/50 hover:bg-accent flex min-h-16 items-center justify-between gap-3 rounded-xl border p-4 text-left text-sm transition-colors"
                            @click="ask(suggestion)"
                        >
                            <span>{{ suggestion }}</span>
                            <ArrowUp
                                class="text-muted-foreground group-hover:text-primary size-3.5 shrink-0 -rotate-45 transition-colors"
                            />
                        </button>
                    </div>
                </div>

                <template
                    v-for="(message, index) in messages"
                    :key="message.id"
                >
                    <!-- User message -->
                    <div
                        v-if="message.role === 'user'"
                        class="flex flex-col items-end gap-2"
                    >
                        <div
                            v-if="(message.images?.length ?? 0) > 0"
                            class="flex flex-wrap justify-end gap-2"
                        >
                            <img
                                v-for="(src, imageIndex) in message.images"
                                :key="imageIndex"
                                :src="src"
                                :alt="`附件图片 ${imageIndex + 1}`"
                                class="max-h-40 rounded-xl border object-cover"
                            />
                        </div>
                        <p
                            v-if="message.content !== ''"
                            class="bg-primary text-primary-foreground max-w-[85%] rounded-2xl rounded-br-md px-4 py-2.5 text-sm whitespace-pre-wrap"
                        >
                            {{ message.content }}
                        </p>
                    </div>
                    <!-- Assistant message -->
                    <div v-else class="group flex gap-3">
                        <div
                            class="border-primary/20 bg-primary/10 text-primary flex size-8 shrink-0 items-center justify-center rounded-lg border"
                        >
                            <Sparkles class="size-4" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div
                                class="text-muted-foreground text-xs font-medium"
                            >
                                {{ activeAgent?.name ?? 'AI 助手' }}
                            </div>

                            <!-- Thinking process (reasoning-capable models):
                                 streamed live as a compact "tail window", then
                                 collapsed into a summary. Display-only — kept
                                 fully separate from the answer content below. -->
                            <div v-if="message.reasoning" class="mt-1.5">
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-foreground flex items-center gap-1.5 text-xs font-medium transition-colors"
                                    @click="toggleReasoning(message)"
                                >
                                    <BrainCircuit
                                        class="size-3.5"
                                        :class="
                                            isReasoningActive(message, index)
                                                ? 'text-primary animate-pulse'
                                                : ''
                                        "
                                    />
                                    <span>
                                        {{ reasoningSummaryLabel(message) }}
                                    </span>
                                    <ChevronDown
                                        class="size-3.5 transition-transform"
                                        :class="
                                            message.reasoningExpanded
                                                ? ''
                                                : '-rotate-90'
                                        "
                                    />
                                </button>

                                <!-- Full transcript, shown when expanded -->
                                <div
                                    v-if="message.reasoningExpanded"
                                    class="border-border/70 bg-muted/40 mt-1 max-h-48 overflow-y-auto rounded-lg border px-3 py-2"
                                >
                                    <p
                                        class="text-muted-foreground text-xs leading-relaxed break-words whitespace-pre-wrap"
                                    >
                                        {{ message.reasoning }}
                                    </p>
                                </div>

                                <!-- Live tail preview while the model thinks -->
                                <p
                                    v-else-if="
                                        isReasoningActive(message, index)
                                    "
                                    class="text-muted-foreground/80 mt-1 line-clamp-2 text-xs leading-relaxed break-words whitespace-pre-wrap"
                                >
                                    {{ reasoningTail(message) }}
                                </p>
                            </div>

                            <div class="mt-1.5">
                                <Spinner
                                    v-if="
                                        message.content === '' &&
                                        !isReasoningActive(message, index)
                                    "
                                    class="text-muted-foreground size-4"
                                />
                                <template v-else>
                                    <!-- eslint-disable-next-line vue/no-v-html -- sanitized in lib/markdown.ts -->
                                    <div
                                        class="chat-markdown"
                                        v-html="renderMarkdown(message.content)"
                                    ></div>
                                    <span
                                        v-if="
                                            isStreaming &&
                                            index === messages.length - 1
                                        "
                                        class="text-muted-foreground animate-pulse"
                                        >▍</span
                                    >
                                </template>
                            </div>

                            <!-- Hover actions -->
                            <div
                                v-if="
                                    message.content !== '' &&
                                    !(
                                        isStreaming &&
                                        index === messages.length - 1
                                    )
                                "
                                class="mt-1 flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-muted-foreground hover:text-foreground size-7"
                                    :title="
                                        copiedMessageId === message.id
                                            ? '已复制'
                                            : '复制全文'
                                    "
                                    @click="copyMessage(message)"
                                >
                                    <Check
                                        v-if="copiedMessageId === message.id"
                                        class="size-3.5"
                                    />
                                    <Copy v-else class="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Composer -->
            <div class="relative shrink-0 pt-1 pb-4">
                <button
                    v-if="!isAtBottom"
                    type="button"
                    title="滚动到最新消息"
                    class="border-border bg-background text-muted-foreground hover:text-foreground animate-in fade-in-0 zoom-in-95 absolute -top-11 left-1/2 z-10 flex size-8 -translate-x-1/2 items-center justify-center rounded-full border shadow-md"
                    @click="scrollToBottom"
                >
                    <ChevronDown class="size-4" />
                </button>

                <div
                    v-if="pendingImages.length > 0"
                    class="mb-2 flex flex-wrap items-center gap-2"
                >
                    <div
                        v-for="image in pendingImages"
                        :key="image.id"
                        class="relative"
                    >
                        <img
                            :src="image.dataUrl"
                            alt="待发送图片"
                            class="border-background h-16 w-16 rounded-xl border-2 object-cover"
                        />
                        <button
                            type="button"
                            class="bg-background text-muted-foreground hover:text-foreground absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full border shadow-sm"
                            title="移除图片"
                            @click="removePendingImage(image.id)"
                        >
                            <X class="size-3" />
                        </button>
                    </div>
                    <span class="text-muted-foreground text-xs">
                        {{ pendingImages.length }}/{{ MAX_IMAGES }}
                    </span>
                </div>

                <form
                    class="border-input bg-card focus-within:border-ring focus-within:ring-ring/50 flex items-end gap-1.5 rounded-2xl border p-2 shadow-xs transition-[border-color,box-shadow] focus-within:ring-[3px]"
                    @submit.prevent="submit"
                >
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/png,image/jpeg,image/gif,image/webp"
                        multiple
                        class="hidden"
                        @change="onImagesSelected"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground rounded-full"
                        title="添加图片"
                        :disabled="pendingImages.length >= MAX_IMAGES"
                        @click="fileInput?.click()"
                    >
                        <ImagePlus class="size-4" />
                    </Button>
                    <textarea
                        ref="inputElement"
                        v-model="input"
                        rows="1"
                        placeholder="给 AI 发送消息…"
                        class="placeholder:text-muted-foreground field-sizing-content max-h-60 min-h-8 flex-1 resize-none bg-transparent px-1.5 py-1.5 text-sm outline-none"
                        @keydown.enter.exact.prevent="submit"
                        @paste="onPaste"
                    ></textarea>
                    <Button
                        v-if="isStreaming"
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        class="rounded-full"
                        title="停止生成"
                        @click="stopStreaming"
                    >
                        <Square class="size-3.5" />
                    </Button>
                    <Button
                        v-else
                        type="submit"
                        size="icon-sm"
                        class="rounded-full"
                        :disabled="!canSend"
                        title="发送"
                    >
                        <ArrowUp class="size-4" />
                    </Button>
                </form>

                <div
                    class="text-muted-foreground mt-2 flex items-center justify-between px-1 text-xs"
                >
                    <span>Enter 发送 · Shift + Enter 换行</span>
                    <span>最多 {{ MAX_IMAGES }} 张图片</span>
                </div>
            </div>
        </main>

        <!-- Stored conversation history (logged-in users only). -->
        <Sheet v-model:open="historyOpen">
            <SheetContent side="left" class="w-80 gap-0 p-0">
                <SheetHeader class="border-border/80 border-b px-4 py-3">
                    <SheetTitle class="text-sm">聊天历史</SheetTitle>
                </SheetHeader>

                <div class="flex-1 overflow-y-auto p-2">
                    <p
                        v-if="props.conversations.length === 0"
                        class="text-muted-foreground px-2 py-8 text-center text-sm"
                    >
                        还没有历史对话
                    </p>

                    <div
                        v-for="conversation in props.conversations"
                        :key="conversation.id"
                        class="group relative"
                    >
                        <button
                            type="button"
                            class="hover:bg-accent w-full rounded-lg px-3 py-2.5 pr-9 text-left transition-colors"
                            :class="
                                conversation.id === conversationId
                                    ? 'bg-accent'
                                    : ''
                            "
                            @click="openConversation(conversation.id)"
                        >
                            <span class="block truncate text-sm font-medium">
                                {{ conversation.title }}
                            </span>
                            <span
                                class="text-muted-foreground mt-0.5 block truncate text-xs"
                            >
                                {{ agentNameOf(conversation.agent) }} ·
                                {{ formatRelativeTime(conversation.updatedAt) }}
                            </span>
                        </button>
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-destructive absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-md opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                            title="删除对话"
                            @click="deleteConversation(conversation.id)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
            </SheetContent>
        </Sheet>
    </div>
</template>
