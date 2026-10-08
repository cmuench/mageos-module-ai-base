<?php

declare(strict_types=1);

namespace MageOS\AiBase\Exceptions;

use MageOS\AiBase\Model\Client\AiExceptionMapper;

/**
 * The model requested a tool call whose arguments the bridge could not parse as JSON.
 *
 * Distinct from {@see AiInvalidRequestException} because the request this module sent was fine;
 * it is the model's reply that came back unusable, which a consumer running a tool loop needs to
 * tell apart from a request-side rejection to decide whether to retry the same turn.
 * {@see AiExceptionMapper} builds this from symfony/ai's `MalformedToolCallException`.
 *
 * @api
 */
class AiToolCallException extends AiServiceException
{
}
