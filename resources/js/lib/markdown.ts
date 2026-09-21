import DOMPurify from 'dompurify';
import hljs from 'highlight.js';
import { marked } from 'marked';
import { markedHighlight } from 'marked-highlight';
import markedKatex from 'marked-katex-extension';

marked.use(
    markedKatex({
        throwOnError: false,
        // Also recognize \(...\) and \[...\] LaTeX delimiters used by GLM.
        nonStandard: true,
    }),
    markedHighlight({
        emptyLangClass: 'hljs',
        langPrefix: 'hljs language-',
        highlight(code: string, lang: string): string {
            let language = lang !== '' && hljs.getLanguage(lang) ? lang : '';

            // Unlabeled payloads that look like JSON get JSON highlighting.
            if (language === '' && isProbablyJson(code)) {
                language = 'json';
            }

            if (language === 'json') {
                code = prettyJson(code);
            }

            return hljs.highlight(code, {
                language: language === '' ? 'plaintext' : language,
            }).value;
        },
    }),
    {
        breaks: true, // Chat messages rely on single newlines becoming <br>.
        gfm: true, // Tables, strikethrough, task lists.
    },
);

/**
 * Whether the snippet looks like JSON (object or array literal start).
 */
function isProbablyJson(code: string): boolean {
    const trimmed = code.trimStart();

    return trimmed.startsWith('{') || trimmed.startsWith('[');
}

/**
 * Reformat fully-minified JSON with 2-space indentation so single-line
 * payloads (e.g. template agent output) stay readable instead of scrolling
 * sideways forever. Multi-line JSON keeps the model's own formatting, and
 * anything that fails to parse is left untouched.
 */
function prettyJson(code: string): string {
    if (code.includes('\n')) {
        return code;
    }

    try {
        return JSON.stringify(JSON.parse(code), null, 2);
    } catch {
        return code;
    }
}

/** Friendly display names for fenced code block languages. */
const LANGUAGE_LABELS: Record<string, string> = {
    json: 'JSON',
    js: 'JavaScript',
    javascript: 'JavaScript',
    ts: 'TypeScript',
    typescript: 'TypeScript',
    php: 'PHP',
    html: 'HTML',
    xml: 'XML',
    css: 'CSS',
    bash: 'Bash',
    sh: 'Shell',
    shell: 'Shell',
    python: 'Python',
    py: 'Python',
    sql: 'SQL',
    vue: 'Vue',
    yaml: 'YAML',
    yml: 'YAML',
    markdown: 'Markdown',
    md: 'Markdown',
};

function languageLabel(lang: string): string {
    return LANGUAGE_LABELS[lang] ?? lang.toUpperCase();
}

/**
 * Wrap sanitized code blocks in a card with a language label and a copy
 * affordance in its header.
 *
 * Runs after DOMPurify so only trusted markup generated here is appended.
 * The trigger is a span (DOMPurify forbids real buttons inside chat HTML)
 * and is wired up through event delegation in the chat page.
 */
function withCodeBlocks(html: string): string {
    return html.replace(
        /<pre><code class="hljs(?: language-([\w+#.-]+))?">([\s\S]*?)<\/code><\/pre>/g,
        (_match, language: string | undefined, code: string): string => {
            const lang =
                typeof language === 'string' && language !== ''
                    ? language
                    : // The highlighted HTML wraps tokens in spans, so strip
                      // tags before sniffing for a JSON literal start.
                      isProbablyJson(code.replace(/<[^>]+>/g, ''))
                      ? 'json'
                      : '';

            const label =
                lang === ''
                    ? ''
                    : `<span class="chat-code-lang">${languageLabel(lang)}</span>`;

            return (
                `<div class="chat-code-block"${
                    lang === '' ? '' : ` data-lang="${lang}"`
                }>` +
                `<div class="chat-code-header">${label}` +
                '<span class="chat-code-copy" role="button" tabindex="0">复制</span>' +
                '</div>' +
                `<pre><code class="hljs${
                    lang === '' ? '' : ` language-${lang}`
                }">${code}</code></pre>` +
                '</div>'
            );
        },
    );
}

/**
 * Matches whole fenced code blocks (``` or ~~~) so the raw-JSON sniffer
 * below never touches text that is already fenced — including a fence the
 * model left open while the response is still streaming.
 */
const FENCED_BLOCK =
    /(^|\n)[ \t]*(```|~~~)[^\n]*\n[\s\S]*?(?:\n[ \t]*\2[ \t]*(?=\n|$)|$)/g;

/**
 * Extract the exact JSON value starting at `start` via a string-aware
 * bracket balance scan; returns null unless the slice parses as JSON.
 */
function jsonSliceAt(text: string, start: number): string | null {
    const open = text[start];
    const close = open === '{' ? '}' : ']';
    let depth = 0;
    let inString = false;
    let escaped = false;

    for (let i = start; i < text.length; i++) {
        const char = text[i];

        if (inString) {
            if (escaped) {
                escaped = false;
            } else if (char === '\\') {
                escaped = true;
            } else if (char === '"') {
                inString = false;
            }

            continue;
        }

        if (char === '"') {
            inString = true;
        } else if (char === open) {
            depth++;
        } else if (char === close) {
            depth--;

            if (depth === 0) {
                const candidate = text.slice(start, i + 1);

                try {
                    JSON.parse(candidate);
                } catch {
                    return null;
                }

                return candidate;
            }
        }
    }

    return null;
}

/** Skip trivially small blobs that are more likely prose than JSON. */
const MIN_FENCED_JSON_LENGTH = 8;
const MAX_FENCED_JSON_BLOBS = 10;

function fenceJsonInSegment(segment: string): string {
    let result = '';
    let cursor = 0;
    let fenced = 0;

    for (let i = 0; i < segment.length && fenced < MAX_FENCED_JSON_BLOBS; i++) {
        const char = segment[i];

        if (char !== '{' && char !== '[') {
            continue;
        }

        const candidate = jsonSliceAt(segment, i);

        if (candidate === null || candidate.length < MIN_FENCED_JSON_LENGTH) {
            continue;
        }

        result += segment.slice(cursor, i);
        result += `\n\`\`\`json\n${candidate}\n\`\`\`\n`;
        cursor = i + candidate.length;
        i = cursor - 1;
        fenced++;
    }

    return result + segment.slice(cursor);
}

/**
 * Fence raw, unfenced JSON in a message before Markdown parsing.
 *
 * The print template agent is instructed to answer with pure JSON (no code
 * fences) so the template editor can parse the reply directly, which
 * Markdown would otherwise render as a wall of plain text. Blobs that parse
 * cleanly become real code blocks, so they get the code card, highlighting,
 * JSON prettifying and copy button. Fenced regions are left untouched.
 */
function fenceUnfencedJson(text: string): string {
    let result = '';
    let cursor = 0;

    FENCED_BLOCK.lastIndex = 0;

    for (
        let match = FENCED_BLOCK.exec(text);
        match !== null;
        match = FENCED_BLOCK.exec(text)
    ) {
        const fenceStart = match.index + match[1].length;

        result += fenceJsonInSegment(text.slice(cursor, fenceStart));
        result += match[0];
        cursor = FENCED_BLOCK.lastIndex;
    }

    return result + fenceJsonInSegment(text.slice(cursor));
}

/**
 * Render a chat message as sanitized Markdown HTML.
 *
 * Assistant output is untrusted content, so the generated HTML always passes
 * through DOMPurify before it reaches the page. Inline `style` attributes are
 * allowed (KaTeX needs them for formula layout) — DOMPurify sanitizes the CSS
 * declarations themselves.
 */
export function renderMarkdown(text: string): string {
    const html = marked.parse(fenceUnfencedJson(text), { async: false });

    return withCodeBlocks(
        DOMPurify.sanitize(html, {
            FORBID_TAGS: ['style', 'form', 'input', 'button', 'iframe'],
        }),
    );
}
