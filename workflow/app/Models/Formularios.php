<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Formularios extends Eloquent {
    public $timestamps = false;
    protected $table = 'formularios';
    protected $primaryKey = 'id';
}
