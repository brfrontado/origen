<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class FirmaElectronica extends Eloquent {
    public $timestamps = false;
    protected $table = 'firmaElectronica';
    protected $primaryKey = 'id';

    public function detalle() {
        return $this->belongsTo(CotizacionDetalle::class, 'cotizacionDetalleId', 'id');
    }
}
