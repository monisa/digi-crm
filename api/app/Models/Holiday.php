<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'optional' => 'boolean'];
    }

    /** Store plain Y-m-d so the unique date rule works the same on every database. */
    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = \Carbon\Carbon::parse($value)->toDateString();
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'day' => $this->date->format('l'),
            'name' => $this->name,
            'optional' => $this->optional,
        ];
    }
}
