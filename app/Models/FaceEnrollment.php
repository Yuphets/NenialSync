<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FaceEnrollment extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['id', 'employee_id', 'device_id', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'descriptors' => 'array',
            'enrolled_at' => 'datetime',
            'is_active' => 'boolean',
        ];
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
