<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'recognized_at' => 'datetime', 'metadata' => 'array', 'match_confidence' => 'decimal:2'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
