<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class OrdenAsignacion extends Eloquent {
    public $timestamps = false;
    protected $table = 'ordenAsig';
    protected $primaryKey = 'id';
}
