<?php

namespace App\Actions\Returns;

use App\Enums\Order\ReturnStatus;
use App\Models\Order\OrderReturn;
use App\Models\User;

class MarkAsReceivedAction extends BaseReturnAction
{
    /**
     * Execute the action to mark return as received
     *
     * @param  array  $options  Required parameters:
     *                          - 'user' => User marking the return as received
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn
    {
        if (! $this->validate($return)) {
            throw new \Exception('Return cannot be marked as received in its current state');
        }

        $user = $options['user'] ?? null;

        if (! $user instanceof User) {
            throw new \Exception('User is required to mark return as received');
        }

        try {
            // Mark as received
            $return->markAsReceived($user);

            // Log success
            $this->logAction($return, 'return_marked_as_received', [
                'received_by' => $user->id,
                'received_by_name' => $user->name,
                'previous_status' => $return->getOriginal('status'),
            ]);

            return $return->fresh();
        } catch (\Exception $e) {
            $this->logFailure($return, 'return_mark_as_received', $e, [
                'user_id' => $user->id,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if return can be marked as received
     */
    public function validate(OrderReturn $return): bool
    {
        // Return should be in InTransit status to be marked as received
        return in_array($return->status, [
            ReturnStatus::LabelGenerated,
            ReturnStatus::InTransit,
        ]);
    }
}
