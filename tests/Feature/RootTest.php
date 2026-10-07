<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootTest extends TestCase
{
    public function test_the_root_describes_the_service(): void
    {
        $this->get('/')->assertOk()->assertJson(['service' => 'payment-webhooks']);
    }

    public function test_the_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
