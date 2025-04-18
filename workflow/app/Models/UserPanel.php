<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserPanel extends Eloquent {
    public $timestamps = false;
    protected $table = 'userPanelsAccess';
    protected $primaryKey = 'id';

    public function user() {
        return $this->belongsTo(User::class, 'usuarioId', 'id');
    }
}
