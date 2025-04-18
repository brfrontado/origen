<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class ConfiguracionOCR extends Eloquent {
    public $timestamps = false;
    protected $table = 'configuracionOcr';
    protected $primaryKey = 'id';
}
