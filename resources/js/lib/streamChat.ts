import { stream } from '@/actions/App/Http/Controllers/Ai/ChatController';
import type { ChatMessage } from '@/types';

interface StreamChatOptions {
    agent?: string;
    message: string;
    history: ChatMessage[];
    /** Inline image attachments as data URLs. */
    images?: string[];
    /** Stored conversation id to continue (logged-in users). */
    conversation?: string;
    signal?: AbortSignal;
    onDelta: (delta: string) => void;
    /** Fired for each chain-of-thought chunk (thinking models only). */
    onReasoning?: (delta: string) => void;
    /** Fired when the reasoning phase ends and the answer begins. */
    onReasoningEnd?: () => void;
    /** Fired with the stored conversation id on remembered turns. */
    onConversation?: (id: string) => void;
}

interface StreamEvent {
    type?: string;
    delta?: string;
    message?: string;
    /** Conversation id on "conversation" events. */
    id?: string;
    /** Present on "stream_end" events, e.g. "stop" or "length". */
    reason?: string;
}

/**
 * Server-side contract (ChatRequest validation): at most 20 history entries,
 * each capped in length so the payload always passes validation.
 */
const MAX_HISTORY_MESSAGES = 20;
const MAX_HISTORY_CONTENT_LENGTH = 10_000;

/**
 * Normalize the conversation history into a shape the server will accept:
 * drop empty messages (e.g. aborted placeholders), keep only the most recent
 * messages, and truncate entries that grew past the per-message limit.
 *
 * Without this, the second turn of a conversation re-sends the assistant's
 * previous reply — which can exceed the server's validation limits and fail
 * the whole request.
 */
function normalizeHistory(history: ChatMessage[]): ChatMessage[] {
    return history
        .filter((message) => message.content.trim() !== '')
        .slice(-MAX_HISTORY_MESSAGES)
        .map((message) => ({
            role: message.role,
            content: message.content.slice(0, MAX_HISTORY_CONTENT_LENGTH),
        }));
}

/**
 * Read the XSRF-TOKEN cookie so JSON requests to the AI endpoints pass CSRF
 * validation.
 *
 * Laravel accepts the (encrypted) cookie value via the X-XSRF-TOKEN header and
 * decrypts it server-side, so the raw cookie value can be forwarded as-is.
 */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

/**
 * Stream a chat completion from the server via server-sent events.
 *
 * Each SSE chunk looks like `data: {"type":"text_delta","delta":"..."}\n\n`,
 * and the stream is terminated by a `data: [DONE]` marker.
 */
export async function streamChat(options: StreamChatOptions): Promise<void> {
    const endpoint = stream();

    const response = await fetch(endpoint.url, {
        method: endpoint.method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            // Ask for JSON on failures: with a pure "text/event-stream" Accept
            // header, validation errors would redirect (302) to the homepage
            // instead of returning a 422 JSON body, failing silently.
            Accept: 'application/json, text/event-stream',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            agent: options.agent,
            message: options.message,
            images: options.images,
            conversation: options.conversation,
            history: normalizeHistory(options.history),
        }),
        signal: options.signal,
    });

    if (response.redirected) {
        throw new Error(
            'The AI request was redirected — please reload the page and try again.',
        );
    }

    if (!response.ok) {
        throw new Error(await responseErrorMessage(response));
    }

    if (!response.body) {
        throw new Error('Streaming is not supported by this browser.');
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let failure: Error | null = null;

    const consume = (chunk: string): void => {
        const error = consumeServerSentEvent(
            chunk,
            options.onDelta,
            options.onConversation,
            options.onReasoning,
            options.onReasoningEnd,
        );

        // Keep consuming later events — the conversation id event trails the
        // stream-end event, and bailing out early would swallow it. The first
        // failure is surfaced once the whole stream has been processed.
        if (error !== null && failure === null) {
            failure = error;
        }
    };

    while (true) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });

        const chunks = buffer.split('\n\n');

        buffer = chunks.pop() ?? '';

        for (const chunk of chunks) {
            consume(chunk);
        }
    }

    if (buffer.trim() !== '') {
        consume(buffer);
    }

    if (failure !== null) {
        throw failure;
    }
}

/**
 * Extract text and reasoning deltas from a single SSE chunk, ignoring
 * comments, the termination marker, and event types the chat UI does not
 * care about.
 *
 * Failure events (`stream_error`, length-capped `stream_end`) are RETURNED
 * instead of thrown — the conversation id event arrives after them in the
 * stream, and throwing mid-loop would swallow it. The caller rethrows the
 * first returned error after the whole stream has been consumed.
 */
function consumeServerSentEvent(
    chunk: string,
    onDelta: (delta: string) => void,
    onConversation?: (id: string) => void,
    onReasoning?: (delta: string) => void,
    onReasoningEnd?: () => void,
): Error | null {
    const payload = chunk
        .split('\n')
        .filter((line) => line.startsWith('data:'))
        .map((line) => line.slice('data:'.length).trimStart())
        .join('\n');

    if (payload === '' || payload === '[DONE]') {
        return null;
    }

    let event: StreamEvent;

    try {
        event = JSON.parse(payload) as StreamEvent;
    } catch {
        // Ignore malformed events (e.g. padding or comments) — the stream
        // may legitimately contain chunks we do not understand.

        return null;
    }

    if (event.type === 'text_delta' && typeof event.delta === 'string') {
        onDelta(event.delta);

        return null;
    }

    // Thinking models stream their chain-of-thought before the answer; it is
    // display-only and must never be mixed into the final message content.
    if (event.type === 'reasoning_delta' && typeof event.delta === 'string') {
        onReasoning?.(event.delta);

        return null;
    }

    if (event.type === 'reasoning_end') {
        onReasoningEnd?.();

        return null;
    }

    // Logged-in turns land in a stored conversation; the server announces its
    // id so the client can continue the thread and refresh the history list.
    if (event.type === 'conversation' && typeof event.id === 'string') {
        onConversation?.(event.id);

        return null;
    }

    if (event.type === 'stream_error' && typeof event.message === 'string') {
        return new Error(event.message);
    }

    // A "length" finish means the model hit its max_tokens ceiling and the
    // reply was cut short (e.g. zhipu's glm-4v-flash caps output at 1024
    // tokens). Surface it instead of silently ending mid-sentence.
    if (event.type === 'stream_end' && event.reason === 'length') {
        return new Error(
            '回复因达到模型单次输出上限（max_tokens）被截断——可要求 AI 精简内容，或在 .env 中换用输出上限更高的模型。',
        );
    }

    return null;
}

/**
 * Prefer the JSON "message" of error responses (validation, throttling),
 * falling back to the HTTP status for HTML error pages.
 */
export async function responseErrorMessage(
    response: Response,
): Promise<string> {
    try {
        const data = JSON.parse(await response.text()) as { message?: string };

        if (typeof data.message === 'string' && data.message !== '') {
            return data.message;
        }
    } catch {
        // Body was not JSON (e.g. an HTML error page).
    }

    return `The AI request failed with status ${response.status}.`;
}
