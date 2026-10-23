export interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
    /** Inline image attachments shown with the message (data URLs). */
    images?: string[];
}

/** A stored conversation entry shown in the history drawer. */
export interface ChatConversationSummary {
    id: string;
    title: string;
    /** Chat agent key recorded for the conversation, when resolvable. */
    agent: string | null;
    updatedAt: string | null;
}

/** A stored conversation loaded for review or continuation. */
export interface ChatConversationDetail {
    id: string;
    title: string;
    agent: string | null;
    messages: ChatMessage[];
}

export interface ChatAgentOption {
    key: string;
    name: string;
    description: string;
    suggestions: string[];
}

/** Form payload for the article assistant generator. */
export interface ArticleAssistantForm {
    topic: string;
    content: string;
    audience: string;
    /** Style key from ArticleGenerateRequest ('auto' lets the AI decide). */
    tone: string;
    /** Length key: 'short' | 'medium' | 'long'. */
    length: string;
    extras: string;
}

/** Generated article returned by the article assistant endpoint. */
export interface GeneratedArticle {
    html: string;
    /** Document title, used as the download filename suggestion. */
    title: string | null;
}

/** Form payload for the tattoo line art assistant. */
export interface TattooAssistantForm {
    subject: string;
    /** Style key from TattooGenerateRequest ('auto' lets the AI decide). */
    style: string;
    /** Placement key from TattooGenerateRequest ('any' leaves it open). */
    placement: string;
    /** Canvas ratio key: 'square' | 'portrait' | 'landscape'. */
    ratio: string;
    /** Resolution key: 'low' (1K) | 'medium' (2K) | 'high' (4K). */
    detail: string;
    extras: string;
}

/** Generated line art returned by the tattoo assistant endpoint. */
export interface GeneratedTattoo {
    /** Base64 data URL of the generated image, ready for <img src>. */
    image: string;
    /** MIME type of the generated image (e.g. image/png). */
    mime: string;
}
