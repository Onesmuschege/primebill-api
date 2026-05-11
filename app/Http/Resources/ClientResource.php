<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'id_number' => $this->id_number,
            'full_name' => $this->full_name,
            'address' => $this->address,
            'city' => $this->city,
            'account_type' => $this->account_type,
            'status' => $this->status,
            'total_invoiced' => $this->total_invoiced,
            'total_paid' => $this->total_paid,
            'balance' => $this->balance,
            'accounts' => ClientAccountResource::collection($this->whenLoaded('accounts')),
            'invoices_count' => $this->invoices_count ?? $this->invoices()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
