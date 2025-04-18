<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class TareasCanales extends Eloquent {
    public $timestamps = false;
    protected $table = 'tareas_canales';
    protected $primaryKey = 'id';
}
