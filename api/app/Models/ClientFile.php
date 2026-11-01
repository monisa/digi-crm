<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Brand logo / style guide uploaded by a client. Stored privately, downloaded through the API. */
class ClientFile extends Model
{
    protected $guarded = ['id'];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
