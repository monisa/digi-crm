<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPage extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'subscribed' => 'boolean', 'last_polled_at' => 'datetime'];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'page_id' => $this->page_id,
            'name' => $this->name,
            'subscribed' => $this->subscribed,
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
