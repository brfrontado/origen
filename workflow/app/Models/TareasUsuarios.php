<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class TareasUsuarios extends Eloquent {
    public $timestamps = false;
    protected $table = 'tareas_usuarios';
    protected $primaryKey = 'id';
}
