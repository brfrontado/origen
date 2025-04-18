<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CotizacionLoteOrden extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesLoteOrden';
    protected $primaryKey = 'id';


}
