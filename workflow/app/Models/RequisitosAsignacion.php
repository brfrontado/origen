<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class RequisitosAsignacion extends Eloquent {
    public $timestamps = false;
    protected $table = 'requisitos_asignacion';
    protected $primaryKey = 'id';

    public function categoriaRequisito(){
        return $this->belongsTo('App\Models\RequisitoCategorias','categoriaRequisitoId');
    }
    public function requisito(){
        return $this->belongsTo('App\Models\Requisitos','requisitoId');
    }
}
