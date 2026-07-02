<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deduction_plan' => 'array', 'is_active' => 'boolean', 'weekly_salary' => 'decimal:2', 'incentive' => 'decimal:2', 'overtime_hourly_rate' => 'decimal:2', 'overtime_hours' => 'decimal:2'];
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
