<?php

namespace Netgen\TagsBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

class RemoteId extends Constraint
{
    /**
     * @var string
     */
    public $message = 'eztags.validator.remote_id';

    /**
     * Returns the name of the class that validates this constraint.
     *
     * @return string
     */
    public function validatedBy()
    {
        return 'eztags_remote_id';
    }
}
