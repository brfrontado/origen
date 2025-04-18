<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class CanalesUsuarios extends Eloquent {
    public $timestamps = false;
    protected $table = 'users_channels';
    protected $primaryKey = 'id';
}
