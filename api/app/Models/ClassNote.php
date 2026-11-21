<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Class notes for one date; students whose attendance for that date is approved can download them. */
class ClassNote extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'title' => $this->title,
            'body' => $this->body,
            'file' => $this->file_path ? ['name' => $this->file_name, 'size' => $this->file_size, 'mime' => $this->file_mime] : null,
            'by' => $this->uploader?->name,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
