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
