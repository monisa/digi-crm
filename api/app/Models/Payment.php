<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, LogsActivity;

    public function activityLabel(): string
    {
        return '₹'.number_format((float) $this->amount, 2).' · '.(Client::whereKey($this->client_id)->value('company_name') ?? '');
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'float'];
    }
}
