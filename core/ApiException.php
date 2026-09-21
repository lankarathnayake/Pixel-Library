<?php

/** An error whose message is safe to show the user; code = HTTP status. $data is extra information sent to the page with it. */
class ApiException extends Exception {

	public array $data;

	public function __construct(string $message, int $status = 400, array $data = []) {
		parent::__construct($message, $status);
		$this->data = $data;
	}
}
