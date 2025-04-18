<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CotizacionCierre extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesCierre';
    protected $primaryKey = 'id';

}
