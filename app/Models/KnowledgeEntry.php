<?php

namespace App\Models;

use Database\Factories\KnowledgeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A searchable knowledge-base entry consulted by the knowledge agent.
 *
 * @property int $id
 * @property string $title
 * @property string $content
 * @property string|null $keywords
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'content', 'keywords'])]
class KnowledgeEntry extends Model
{
    /** @use HasFactory<KnowledgeEntryFactory> */
    use HasFactory;
}
