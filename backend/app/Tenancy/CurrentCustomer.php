<?php

namespace App\Tenancy;

use App\Models\Customer;
use App\Models\PortalSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Клиент, открывший портал по одноразовой ссылке: заполняется middleware AuthenticatePortal. */
class CurrentCustomer
{
    private ?Customer $customer = null;

    private ?PortalSession $session = null;

    public function set(Customer $customer, PortalSession $session): void
    {
        $this->customer = $customer;
        $this->session = $session;
    }

    public function customer(): Customer
    {
        return $this->customer ?? throw new HttpException(401, 'Portal session required.');
    }

    public function session(): PortalSession
    {
        return $this->session ?? throw new HttpException(401, 'Portal session required.');
    }
}
