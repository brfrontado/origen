<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CotizacionesOcrTokens extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesOcrTokens';
    protected $primaryKey = 'id';
}
