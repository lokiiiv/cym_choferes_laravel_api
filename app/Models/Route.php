<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    use HasFactory;

    protected $table = 'route';
    public $timestamps = false;
    protected $primaryKey = 'idRoute';

    protected $fillable = [
        'folio',
        'date_created',
        'att_for',
        'completed',
        'FK_idUser'
    ];

    public function attendances() {
        return $this->hasMany(Attendance::class, 'idRoute');
    }
}
