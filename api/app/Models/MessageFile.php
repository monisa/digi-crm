<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageFile extends Model
{
    protected $guarded = ['id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size,
            'image' => str_starts_with((string) $this->mime, 'image/'),
        ];
    }
}
