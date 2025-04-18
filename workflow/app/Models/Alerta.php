<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Alerta extends Eloquent {
    public $timestamps = false;
    protected $table = 'alertas';
    protected $primaryKey = 'id';
}
