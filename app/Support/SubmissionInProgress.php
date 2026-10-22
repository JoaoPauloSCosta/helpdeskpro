<?php

namespace App\Support;

use RuntimeException;

/** O primeiro envio com o mesmo token ainda está sendo processado (SingleSubmission). */
class SubmissionInProgress extends RuntimeException {}
