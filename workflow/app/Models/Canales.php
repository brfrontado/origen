<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Canales extends Eloquent {
    public $timestamps = false;
    protected $table = 'canales';
    protected $primaryKey = 'id';
}
