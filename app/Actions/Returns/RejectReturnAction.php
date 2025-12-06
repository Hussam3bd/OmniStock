<?php

namespace App\Actions\Returns;

use App\Models\Order\OrderReturn;
use App\Models\User;

class RejectReturnAction extends BaseReturnAction
{
    /**
     * Execute the action to reject a return
     *
     * @param  array  $options  Required parameters:
     *                          - 'user' => User rejecting the return
     *                          - 'reason' => Reason for rejection (optional)
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn
    {
        if (! $this->validate($return)) {
            throw new \Exception('Return cannot be rejected in its current state');
        }

        $user = $options['user'] ?? null;

        if (! $user instanceof User) {
            throw new \Exception('User is required to reject a return');
        }

        $reason = $options['reason'] ?? null;

        try {
            // Reject the return
            $return->reject($user, $reason);

            // Log success
            $this->logAction($return, 'return_rejected', [
                'rejected_by' => $user->id,
                'rejected_by_name' => $user->name,
                'reason' => $reason,
                'previous_status' => $return->getOriginal('status'),
            ]);

            return $return->fresh();
        } catch (\Exception $e) {
            $this->logFailure($return, 'return_rejection', $e, [
                'user_id' => $user->id,
                'reason' => $reason,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if return can be rejected
     */
    public function validate(OrderReturn $return): bool
    {
        return $return->canReject();
    }
}
