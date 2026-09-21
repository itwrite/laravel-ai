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
