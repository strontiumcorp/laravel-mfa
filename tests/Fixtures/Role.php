<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
}
