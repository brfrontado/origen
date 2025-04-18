<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Requisitos extends Eloquent {
    public $timestamps = false;
    protected $table = 'requisitos';
    protected $primaryKey = 'id';

    public function expedienteDetail(){
        return $this->hasMany('App\Models\ExpedientesDetail','requisitoId');
    }
    public function requisitosAsignacion(){
        return $this->hasMany('App\Models\RequisitosAsignacion','requisitoId');
    }

}
