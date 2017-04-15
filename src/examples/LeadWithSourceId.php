<?php

namespace SharpSpring\RestApi\examples;

use SharpSpring\RestApi\Lead;

/**
 * A represenation of a Lead object in sharpspring which has a custom ID field.
 *
 * This is used by SharpSpringSyncJob. the custom ID must be populated with the
 * id of the contact object in the source system. SharpSpringSyncJob uses this
 * object instead of an array, so that the custom fieldname for the source ID is
 * automatically converted (if the job is properly configured).
 */
class LeadWithSourceId extends Lead
{
    /**
     * The internal ID of the contact object in the source system.
     */
    public $sourceId;
}
