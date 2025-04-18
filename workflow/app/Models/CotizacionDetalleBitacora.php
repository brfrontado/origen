<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CotizacionDetalleBitacora extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesDetalleBitacora';
    protected $primaryKey = 'id';

    public function cotizacion() {
        return $this->belongsTo(Cotizacion::class, 'cotizacionId', 'id');
    }

    public function usuario(){
        return $this->belongsTo(User::class, 'usuarioId', 'id');
    }
}
