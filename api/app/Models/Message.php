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
            'deleted' => $gone,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
