<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CotizacionLoteOperacion extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesLoteOperacion';
    protected $primaryKey = 'id';

    public function detalle(){
        return $this->hasMany('App\Models\CotizacionLoteOperacionDetalle','cotizacionLoteId');
    }

    public function producto(){
        return $this->belongsTo('App\Models\Productos','expedienteId');
    }

}
