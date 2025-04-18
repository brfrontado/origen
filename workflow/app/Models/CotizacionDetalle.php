<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CotizacionDetalle extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesDetalle';
    protected $primaryKey = 'id';

    public function cotizacion() {
        return $this->belongsTo(Cotizacion::class, 'cotizacionId', 'id');
    }
}
