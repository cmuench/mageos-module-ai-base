<?php

declare(strict_types=1);

namespace MageOS\AiBase\Exceptions;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Model\Client\AiExceptionMapper;

/**
 * A call reached the provider and failed there, for a reason symfony/ai did not report as one of
 * the more specific failures below.
 *
 * Base of the typed hierarchy {@see AiExceptionMapper} builds: catching this one catches every
 * provider failure, the same way catching {@see LocalizedException} always has, while a consumer
 * that wants to tell one failure from another catches a subclass instead. Unlike
 * {@see AiRequestNotSentException}, an exception of this type (or any subclass) means the request
 * was sent and, on most providers, billed.
 *
 * @api
 */
class AiServiceException extends LocalizedException
{
}
