<?php
namespace App\Models;

use App\Events\ExpedientesEtapasUpdated;
use Illuminate\Database\Eloquent\Model as Eloquent;


class ExpedientesTareasRespuestas extends Eloquent {
    public $timestamps = false;
    protected $table = 'expedientes_tareas_respuestas';
    protected $primaryKey = 'id';
}
