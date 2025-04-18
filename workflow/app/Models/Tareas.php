<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Tareas extends Eloquent {
    public $timestamps = false;
    protected $table = 'tareas';
    protected $primaryKey = 'id';
}
