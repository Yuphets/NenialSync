<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['calculation' => 'array'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
