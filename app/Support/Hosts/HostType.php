<?php

namespace App\Support\Hosts;

enum HostType: string
{
    case Marketing = 'marketing';
    case Www = 'www';
    case Auth = 'auth';
    case App = 'app';
    case Admin = 'admin';
    case Workspace = 'workspace';
    case Reserved = 'reserved';
    case Unknown = 'unknown';
}
