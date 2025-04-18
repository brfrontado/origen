<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Marca extends Eloquent {
    public $timestamps = false;
    protected $table = 'marca';
    protected $primaryKey = 'id';
}
