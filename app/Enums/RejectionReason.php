<?php

namespace App\Enums;

enum RejectionReason: string
{
    case OutOfStock = 'out_of_stock';
    case Unreachable = 'unreachable';
    case InvalidAddress = 'invalid_address';
    case FakeOrder = 'fake_order';
    case OutsideArea = 'outside_area';
    case Other = 'other';
}
