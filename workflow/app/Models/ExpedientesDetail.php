<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class ExpedientesDetail extends Eloquent {
    public $timestamps = false;
    protected $table = 'expedientes_detail';
    protected $primaryKey = 'id';

    public function expediente(){
        return $this->belongsTo('App\Models\Expediente','expedienteId');
    }
    public function requisito(){
        return $this->belongsTo('App\Models\Requisitos','requisitoId');
    }
}
