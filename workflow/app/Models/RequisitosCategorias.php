<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class RequisitosCategorias extends Eloquent {
    public $timestamps = false;
    protected $table = 'requisitos_categorias';
    protected $primaryKey = 'id';

    public function requisitos(){
        return $this->hasMany('App\Models\RequisitosAsignacion','categoriaRequisitoId');
    }

}
