<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CotizacionDetalleCatalogo extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesDetalleCatalogo';
    protected $primaryKey = 'id';

    public function cotizacion() {
        return $this->belongsTo(Cotizacion::class, 'cotizacionId', 'id');
    }
}
