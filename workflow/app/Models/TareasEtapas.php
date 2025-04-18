<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class TareasEtapas extends Eloquent {
    public $timestamps = false;
    protected $table = 'tareas_etapas';
    protected $primaryKey = 'id';
}
