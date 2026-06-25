<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = ['holiday_date', 'title', 'reason', 'type', 'compensation_type', 'is_default_sunday', 'status', 'notes', 'created_by'];
    protected function casts(): array { return ['holiday_date' => 'date', 'is_default_sunday' => 'boolean']; }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function scopeActive(Builder $query): Builder { return $query->where('status', 'active'); }
    public function scopeCompensation(Builder $query): Builder { return $query->where('compensation_type', 'compensation'); }
    public function scopeNonCompensation(Builder $query): Builder { return $query->where('compensation_type', 'non_compensation'); }
    public function isCompensation(): bool { return $this->compensation_type === 'compensation'; }
}
