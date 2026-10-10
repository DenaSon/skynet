<?php

namespace App\Modules\Identity\Domain;

enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}
