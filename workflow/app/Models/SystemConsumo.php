<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class SystemConsumo extends Eloquent {
    public $timestamps = false;
    protected $table = 'systemConsumo';
    protected $primaryKey = 'id';
}
