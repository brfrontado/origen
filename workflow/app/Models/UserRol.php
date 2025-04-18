<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class UserRol extends Authenticatable {
    public $timestamps = false;
    protected $table = 'user_rol';
    protected $primaryKey = 'id';

    protected $fillable = [
        'userId',
    ];


    public function user() {
        return $this->belongsTo(User::class, 'rolId', 'id');
    }

    public function rol() {
        return $this->belongsTo(Rol::class, 'rolId', 'id');
    }

    /*public function detalle() {
        return $this->hasMany(ReglaDetalle::class, 'ruleId', 'id');
    }

    public function modificadorCampo() {
        return $this->belongsTo(CatalogoCampos::class, 'fieldCatalogIdModifierField', 'id');
    }*/
}
