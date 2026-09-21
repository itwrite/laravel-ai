<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Ai\Models\Conversation;

class ChatConversationController extends Controller
{
    /**
     * Delete one of the user's stored conversations.
     */
    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Unreachable behind the route's auth middleware.
            return redirect()->route('chat');
        }

        $conversation = Conversation::query()
            ->where('id', $conversation)
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->firstOrFail();

        $conversation->messages()->delete();
        $conversation->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('对话已删除'),
        ]);

        return redirect()->route('chat');
    }
}
