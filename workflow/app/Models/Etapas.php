<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Etapas extends Eloquent {
    public $timestamps = false;
    protected $table = 'etapas';
    protected $primaryKey = 'id';

    public function expedientes()
    {
        return $this->belongsToMany('App\Models\Expedientes', 'expedientes_etapas', 'etapaId', 'expedienteId')
            ->withPivot('dateUpdated');
    }
}
