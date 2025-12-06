<?php

namespace App\Actions\Returns;

use App\Enums\Order\ReturnStatus;
use App\Models\Order\OrderReturn;
use App\Models\User;

class StartInspectionAction extends BaseReturnAction
{
    /**
     * Execute the action to start return inspection
     *
     * @param  array  $options  Required parameters:
     *                          - 'user' => User starting the inspection
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn
    {
        if (! $this->validate($return)) {
            throw new \Exception('Return cannot be inspected in its current state');
        }

        $user = $options['user'] ?? null;

        if (! $user instanceof User) {
            throw new \Exception('User is required to start inspection');
        }

        try {
            // Start inspection
            $return->startInspection($user);

            // Log success
            $this->logAction($return, 'return_inspection_started', [
                'inspected_by' => $user->id,
                'inspected_by_name' => $user->name,
                'previous_status' => $return->getOriginal('status'),
            ]);

            return $return->fresh();
        } catch (\Exception $e) {
            $this->logFailure($return, 'return_inspection_start', $e, [
                'user_id' => $user->id,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if inspection can be started
     */
    public function validate(OrderReturn $return): bool
    {
        // Inspection can only be started when return is received
        return $return->status === ReturnStatus::Received;
    }
}
