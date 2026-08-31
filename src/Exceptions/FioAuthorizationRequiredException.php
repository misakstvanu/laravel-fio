<?php

namespace Misakstvanu\LaravelFio\Exceptions;

use RuntimeException;

/**
 * Fio refused the read because the requested window reaches further back than
 * 90 days and the account owner has not authorised it.
 *
 * The refusal arrives as HTTP 422 with a Czech body telling the owner to
 * authorise the read in Fio internet banking; that authorisation is valid for
 * 10 minutes, after which the same request has to be authorised again. A window
 * that stays inside the last 90 days never needs it.
 *
 * The message carries Fio's own wording, so a log line still says which of the
 * two remedies applies; user-facing copy is the application's job.
 */
class FioAuthorizationRequiredException extends RuntimeException {}
