<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cod = 'cod';
    case Whatsapp = 'whatsapp';
    case Messenger = 'messenger';
}
