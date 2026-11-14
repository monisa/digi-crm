<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MessageFile::class)->orderBy('id');
    }

    /** "📎 2 files" style text for previews and notifications. */
    public function attachmentLabel(): string
    {
        $n = ($this->relationLoaded('files') ? $this->files->count() : $this->files()->count()) + ($this->file_path ? 1 : 0);
        $first = $this->file_name ?: ($this->relationLoaded('files') ? $this->files->first()?->name : $this->files()->value('name'));

        return $n === 0 ? '' : ($n === 1 ? '📎 '.$first : "📎 $n files");
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function toApiArray(): array
    {
        $gone = $this->deleted_at !== null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'body' => $gone ? null : $this->body,
            'file' => ! $gone && $this->file_path ? [
                'name' => $this->file_name, 'mime' => $this->file_mime, 'size' => $this->file_size,
                'image' => str_starts_with((string) $this->file_mime, 'image/'),
            ] : null,
            // Attachments sent together with this message (several allowed).
            'files' => $gone ? [] : $this->files->map->toApiArray()->values()->all(),
            'deleted' => $gone,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
