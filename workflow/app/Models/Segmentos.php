<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Segmentos extends Eloquent {
    public $timestamps = false;
    protected $table = 'canales_segmentos';
    protected $primaryKey = 'id';
}
