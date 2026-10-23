import { generate } from '@/actions/App/Http/Controllers/Ai/TattooAssistantController';
import { responseErrorMessage, xsrfToken } from '@/lib/streamChat';
import type { GeneratedTattoo, TattooAssistantForm } from '@/types';

/**
 * Ask the server to generate a stencil-ready tattoo line art with the
 * Gemini image model (non-streaming JSON response). An optional reference
 * image data URL lets the model base the design on an uploaded sketch.
 */
export async function generateTattoo(
    form: TattooAssistantForm,
    reference: string | null,
): Promise<GeneratedTattoo> {
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
            subject: form.subject.trim(),
            style: form.style === 'auto' ? null : form.style,
            placement: form.placement === 'any' ? null : form.placement,
            ratio: form.ratio === '' ? null : form.ratio,
            detail: form.detail === '' ? null : form.detail,
            extras: form.extras.trim(),
            reference,
        }),
    });

    if (!response.ok) {
        throw new Error(await responseErrorMessage(response));
    }

    return (await response.json()) as GeneratedTattoo;
}
