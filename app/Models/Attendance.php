<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendance';
    public $timestamps = false;
    protected $primaryKey = 'idAttendace';
    
    protected $fillable = [
        'date_time',
        'att_type',
        'idRoute',
        'user_who_register'
    ];

    public function route() {
        return $this->belongsTo(Route::class, 'idRoute');
    }
}
