<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['yearly_quota' => 'float', 'paid' => 'boolean', 'active' => 'boolean', 'carry_forward' => 'boolean', 'carry_max' => 'float', 'prorate_joiners' => 'boolean'];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'yearly_quota' => $this->yearly_quota,
            'paid' => $this->paid, 'active' => $this->active,
            'carry_forward' => $this->carry_forward, 'carry_max' => $this->carry_max, 'carry_from_year' => $this->carry_from_year,
            'prorate_joiners' => $this->prorate_joiners,
        ];
    }
}
