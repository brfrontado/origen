<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class SistemaVariable extends Eloquent {
    public $timestamps = false;
    protected $table = 'sistemaVariables';
    protected $primaryKey = 'id';

    protected $fillable = [
        'slug',
        'contenido',
    ];

}
