<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class PdfTemplate extends Eloquent {
    public $timestamps = false;
    protected $table = 'pdfTemplates';
    protected $primaryKey = 'id';
}
