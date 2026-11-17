import { generate } from '@/actions/App/Http/Controllers/Ai/ImageEnhancerController';
import { responseErrorMessage, xsrfToken } from '@/lib/streamChat';
import type { EnhancedImage, ImageEnhancerForm } from '@/types';

/** Mirrors the server-side "max:4000000" rule on the image field. */
const MAX_IMAGE_DATA_URL_LENGTH = 4_000_000;

/** Longest canvas edge used when downscaling oversized uploads. */
const MAX_IMAGE_EDGE = 2048;

/**
 * Read an image file into a data URL, downscaling oversized uploads through
 * a canvas so the base64 payload stays within the server-side limit. PNG,
 * webp and GIF uploads keep their transparency (PNG re-encode); photos fall
 * back to JPEG for a much smaller payload.
 */
export function readImageFile(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onerror = () =>
            reject(new Error(`读取图片失败：${file.name || '未命名文件'}`));
        reader.onload = () => {
            const dataUrl = reader.result;

            if (typeof dataUrl !== 'string') {
                reject(new Error(`读取图片失败：${file.name || '未命名文件'}`));

                return;
            }

            if (dataUrl.length <= MAX_IMAGE_DATA_URL_LENGTH) {
                resolve(dataUrl);

                return;
            }

            downscale(dataUrl).then(resolve).catch(reject);
        };

        reader.readAsDataURL(file);
    });
}

/**
 * Re-encode an oversized data URL on a canvas capped at
 * {@link MAX_IMAGE_EDGE} pixels per side, preferring a lossless format for
 * sources with transparency and falling back to JPEG otherwise.
 */
async function downscale(dataUrl: string): Promise<string> {
    const image = new Image();

    await new Promise<void>((resolve, reject) => {
        image.onload = () => resolve();
        image.onerror = () =>
            reject(new Error('图片解码失败，请换一张图片试试。'));
        image.src = dataUrl;
    });

    const scale = Math.min(
        1,
        MAX_IMAGE_EDGE / Math.max(image.width, image.height),
    );
    const canvas = document.createElement('canvas');

    canvas.width = Math.max(1, Math.round(image.width * scale));
    canvas.height = Math.max(1, Math.round(image.height * scale));

    const context = canvas.getContext('2d');

    if (context === null) {
        throw new Error('当前浏览器不支持图片压缩，请上传更小的图片。');
    }

    context.drawImage(image, 0, 0, canvas.width, canvas.height);

    const keepsTransparency = /^data:image\/(png|webp|gif);/.test(dataUrl);

    if (keepsTransparency) {
        const lossless = canvas.toDataURL('image/png');

        if (lossless.length <= MAX_IMAGE_DATA_URL_LENGTH) {
            return lossless;
        }
    }

    const compressed = canvas.toDataURL('image/jpeg', 0.92);

    if (compressed.length > MAX_IMAGE_DATA_URL_LENGTH) {
        throw new Error('图片过大，压缩后仍超出限制，请上传更小的图片。');
    }

    return compressed;
}

/**
 * Ask the server to re-render the uploaded image as an ultra-high-resolution
 * version of itself (non-streaming JSON response). The image data URL is the
 * mandatory enhancement source.
 */
export async function enhanceImage(
    form: ImageEnhancerForm,
    image: string,
): Promise<EnhancedImage> {
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
            image,
            goal: form.goal === 'auto' ? null : form.goal,
            detail: form.detail === '' ? null : form.detail,
            extras: form.extras.trim(),
        }),
    });

    if (!response.ok) {
        throw new Error(await responseErrorMessage(response));
    }

    return (await response.json()) as EnhancedImage;
}
