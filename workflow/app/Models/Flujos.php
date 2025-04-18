<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Flujos extends Eloquent {
    public $timestamps = false;
    protected $table = 'flujos';
    protected $primaryKey = 'id';
}
