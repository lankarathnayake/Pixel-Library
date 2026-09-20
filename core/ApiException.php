<?php

/** An error whose message is safe to show the user; code = HTTP status. */
class ApiException extends Exception {

	public function __construct(string $message, int $status = 400) {
		parent::__construct($message, $status);
	}
}
