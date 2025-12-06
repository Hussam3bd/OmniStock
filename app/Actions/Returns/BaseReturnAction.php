<?php

namespace App\Actions\Returns;

use App\Actions\Returns\Contracts\ReturnAction;
use App\Models\Order\OrderReturn;

abstract class BaseReturnAction implements ReturnAction
{
    /**
     * Execute the action on the given return
     */
    abstract public function execute(OrderReturn $return, array $options = []): OrderReturn;

    /**
     * Validate if the action can be performed on the return
     */
    abstract public function validate(OrderReturn $return): bool;

    /**
     * Log the action being performed
     */
    protected function logAction(OrderReturn $return, string $action, array $properties = []): void
    {
        activity()
            ->performedOn($return)
            ->withProperties(array_merge([
                'return_id' => $return->id,
                'return_number' => $return->return_number,
                'order_id' => $return->order_id,
                'order_number' => $return->order->order_number,
            ], $properties))
            ->log($action);
    }

    /**
     * Log an action failure
     */
    protected function logFailure(OrderReturn $return, string $action, \Exception $exception, array $properties = []): void
    {
        activity()
            ->performedOn($return)
            ->withProperties(array_merge([
                'return_id' => $return->id,
                'return_number' => $return->return_number,
                'order_id' => $return->order_id,
                'order_number' => $return->order->order_number,
                'error' => $exception->getMessage(),
            ], $properties))
            ->log($action.'_failed');
    }
}
