import { generate } from '@/actions/App/Http/Controllers/Ai/ArticleAssistantController';
import { responseErrorMessage, xsrfToken } from '@/lib/streamChat';
import type { ArticleAssistantForm, GeneratedArticle } from '@/types';

/**
 * Ask the server to optimize the submitted material into a standalone
 * responsive HTML article (non-streaming JSON response).
 */
export async function generateArticle(
    form: ArticleAssistantForm,
): Promise<GeneratedArticle> {
    const endpoint = generate();

    const response = await fetch(endpoint.url, {
        method: endpoint.method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            // A JSON Accept header keeps validation failures as 422 JSON
            // bodies instead of redirects.
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            topic: form.topic.trim(),
            content: form.content,
            audience: form.audience.trim(),
            tone: form.tone === 'auto' ? null : form.tone,
            length: form.length,
            extras: form.extras.trim(),
        }),
    });

    if (!response.ok) {
        throw new Error(await responseErrorMessage(response));
    }

    return (await response.json()) as GeneratedArticle;
}
