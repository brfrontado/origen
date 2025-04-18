<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class FlujoConexion extends Eloquent {
    public $timestamps = false;
    protected $table = 'flujosConexiones';
    protected $primaryKey = 'id';
}
