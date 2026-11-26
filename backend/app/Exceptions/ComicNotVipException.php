<?php

namespace App\Exceptions;

use Exception;

class ComicNotVipException extends Exception
{
    public function __construct(string $message = 'Truyện này không phải là truyện VIP và không yêu cầu mở khóa bằng Coin.')
    {
        parent::__construct($message, 422);
    }
}
