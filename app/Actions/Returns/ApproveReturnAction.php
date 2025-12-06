<?php

namespace App\Actions\Returns;

use App\Models\Order\OrderReturn;
use App\Models\User;

class ApproveReturnAction extends BaseReturnAction
{
    /**
     * Execute the action to approve a return
     *
     * @param  array  $options  Required parameters:
     *                          - 'user' => User approving the return
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn
    {
        if (! $this->validate($return)) {
            throw new \Exception('Return cannot be approved in its current state');
        }

        $user = $options['user'] ?? null;

        if (! $user instanceof User) {
            throw new \Exception('User is required to approve a return');
        }

        try {
            // Approve the return
            $return->approve($user);

            // Log success
            $this->logAction($return, 'return_approved', [
                'approved_by' => $user->id,
                'approved_by_name' => $user->name,
                'previous_status' => $return->getOriginal('status'),
            ]);

            return $return->fresh();
        } catch (\Exception $e) {
            $this->logFailure($return, 'return_approval', $e, [
                'user_id' => $user->id,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if return can be approved
     */
    public function validate(OrderReturn $return): bool
    {
        return $return->canApprove();
    }
}
