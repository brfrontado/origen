<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Expedientes extends Eloquent {
    public $timestamps = false;
    protected $table = 'expedientes';
    protected $primaryKey = 'id';

    public function cliente(){
        return $this->belongsTo('App\Models\Clientes', 'clienteId');
    }

    public function etapas(){
        return $this->belongsToMany('App\Models\Etapas', 'expedientes_etapas', 'expedienteId', 'etapaId')
            ->withPivot('dateUpdated');
    }
    public function expedienteDetail(){
        return $this->hasMany('App\Models\ExpedientesDetail','expedienteId');
    }
    public function expedientes_etapas()
    {
        return $this->belongsToMany('App\Models\Etapas', 'expedientes_etapas', 'expedienteId', 'etapaId')->withPivot('dateUpdated');
    }
    public function tareasRespuestas()
    {
        return $this->hasMany('App\Models\ExpedientesTareasRespuestas', 'idExpediente');
    }
}
