<?php

namespace App\Services\Network;

use App\Models\NetworkEvent;

class NetworkEventService
{
    public function create(array $data): NetworkEvent
    {
        return NetworkEvent::create($data);
    }
}
