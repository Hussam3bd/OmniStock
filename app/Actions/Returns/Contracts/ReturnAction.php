<?php

namespace App\Actions\Returns\Contracts;

use App\Models\Order\OrderReturn;

interface ReturnAction
{
    /**
     * Execute the action on the given return
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn;

    /**
     * Validate if the action can be performed on the return
     */
    public function validate(OrderReturn $return): bool;
}
