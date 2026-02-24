<?php

namespace App\Enums;

enum BeforeActionType: string
{
    case Click = 'click';
    case Hover = 'hover';
    case Wait = 'wait';
    case Type = 'type';
    case Select = 'select';
    case Scroll = 'scroll';
    case Screenshot = 'screenshot';
}
