<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class CotizacionOCR extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesOCR';

    protected $fillable = [

    ];
    protected $primaryKey = 'id';
}
