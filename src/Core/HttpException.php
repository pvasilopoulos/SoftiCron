<?php

declare(strict_types=1);

namespace App\Core;

class HttpException extends \RuntimeException
{
    public function __construct(public int $status, string $message = '', public ?string $redirect = null)
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $msg = 'Η σελίδα δεν βρέθηκε.'): self
    {
        return new self(404, $msg);
    }

    public static function forbidden(string $msg = 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'): self
    {
        return new self(403, $msg);
    }

    public static function redirect(string $to): self
    {
        return new self(302, '', $to);
    }
}
