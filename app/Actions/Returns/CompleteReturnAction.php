<?php

namespace App\Actions\Returns;

use App\Enums\Order\ReturnStatus;
use App\Models\Order\OrderReturn;
use App\Models\User;

class CompleteReturnAction extends BaseReturnAction
{
    /**
     * Execute the action to complete a return
     *
     * @param  array  $options  Required parameters:
     *                          - 'user' => User completing the return
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn
    {
        if (! $this->validate($return)) {
            throw new \Exception('Return cannot be completed in its current state');
        }

        $user = $options['user'] ?? null;

        if (! $user instanceof User) {
            throw new \Exception('User is required to complete return');
        }

        try {
            // Complete the return
            $return->complete($user);

            // Log success
            $this->logAction($return, 'return_completed', [
                'completed_by' => $user->id,
                'completed_by_name' => $user->name,
                'previous_status' => $return->getOriginal('status'),
            ]);

            return $return->fresh();
        } catch (\Exception $e) {
            $this->logFailure($return, 'return_completion', $e, [
                'user_id' => $user->id,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if return can be completed
     */
    public function validate(OrderReturn $return): bool
    {
        // Return can be completed from Inspecting or Received status
        return in_array($return->status, [
            ReturnStatus::Received,
            ReturnStatus::Inspecting,
        ]);
    }
}
