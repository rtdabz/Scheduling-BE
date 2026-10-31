<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumImport extends Model
{
    protected $table = 'curriculum_imports';

    protected $fillable = [
        'curriculum_id', 'department_id', 'user_id', 'request_key', 'payload_hash',
        'source_format', 'source_filename', 'source_sha256', 'extractor_version', 'record', 'summary',
    ];

    protected $casts = [
        'curriculum_id' => 'integer',
        'department_id' => 'integer',
        'user_id' => 'integer',
        'record' => 'array',
        'summary' => 'array',
    ];

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }
}
