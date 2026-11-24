<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'client_message_id',
        'type',
        'body',
    ];

    /**
     * The attributes that should be visible in serialization.
     * Strictly whitelists the public chat communication fields.
     *
     * @var list<string>
     */
    protected $visible = [
        'id',
        'conversation_id',
        'sender_id',
        'type',
        'body',
        'client_message_id',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'conversation_id' => 'integer',
            'sender_id' => 'integer',
        ];
    }

    /**
     * Conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * User who sent this message.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
