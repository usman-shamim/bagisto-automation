<?php

namespace Webkul\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Automation\Contracts\AutomationAuditLog as AutomationAuditLogContract;

class AutomationAuditLog extends Model implements AutomationAuditLogContract
{
    protected $table = 'automation_audit_log';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'token_id',
        'admin_id',
        'endpoint',
        'method',
        'payload_hash',
        'ip',
        'status_code',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'status_code' => 'integer',
        'created_at' => 'datetime',
    ];

    public function token()
    {
        return $this->belongsTo(AdminTokenProxy::modelClass(), 'token_id');
    }
}
