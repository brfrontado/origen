<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class CotizacionTranscribe extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizacionesTranscribe';
    protected $primaryKey = 'id';
}
