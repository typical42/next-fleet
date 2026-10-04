<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Api;

/**
 * The instance under test, reached the two ways the suite needs: HTTP, as a client reaches it,
 * and occ, for what a client cannot do itself - make an account and its app password.
 *
 * `NEXTCLOUD_ROOT` names the server's directory and `NEXTFLEET_API_URL` its address; inside the
 * dev container the defaults are right.
 */
final class Server {
	private const OCS = '/ocs/v2.php/apps/nextfleet/api/v1';

	private function __construct(
		private string $root,
		private string $url,
	) {
	}

	public static function fromEnvironment(): self {
		return new self(
			getenv('NEXTCLOUD_ROOT') ?: '/var/www/html',
			rtrim(getenv('NEXTFLEET_API_URL') ?: 'http://localhost', '/'),
		);
	}

	/**
	 * A fresh account with an app password made without its login password, which is all a
	 * client is ever given (docs/api.md).
	 */
	public function account(string $prefix): Account {
		$uid = $prefix . bin2hex(random_bytes(4));
		$this->occ('user:add', '--generate-password', $uid);
		// The command asks for the login password; stdin is closed, so it makes the app password
		// without one. The password is its last line on NC 31 and 34 alike.
		$lines = explode("\n", trim($this->occ('user:auth-tokens:add', $uid)));

		return new Account($uid, trim(end($lines)));
	}

	/** Deletes every account whose name starts with `$prefix` (docs/development.md#testing). */
	public function forget(string $prefix): void {
		$users = json_decode($this->occ('user:list', '--output=json', '--limit=1000'), true, 512, JSON_THROW_ON_ERROR);
		foreach (array_keys((array)$users) as $uid) {
			if (str_starts_with((string)$uid, $prefix)) {
				$this->occ('user:delete', (string)$uid);
			}
		}
	}

	/**
	 * Clears the failed logins counted against the suite's address. Nextcloud delays every later
	 * login from an address by its failures, and the E2E run logs in from the same one.
	 */
	public function forgiveFailedLogins(): void {
		foreach (['127.0.0.1', '::1'] as $address) {
			$this->occ('security:bruteforce:reset', $address);
		}
	}

	/** Writes a file into the account's Files over WebDAV and answers its file id. */
	public function upload(Account $as, string $name, string $content): int {
		$answer = $this->request('PUT', '/remote.php/dav/files/' . rawurlencode($as->uid) . '/' . rawurlencode($name), $as, [], $content);
		// `OC-FileId` is the id padded to eight digits, then the instance id.
		if ($answer->status !== 201 || preg_match('/^\d+/', (string)$answer->header('oc-fileid'), $id) !== 1) {
			throw new \RuntimeException("Upload of $name refused: $answer->status $answer->body");
		}

		return (int)$id[0];
	}

	/**
	 * A call to the OCS API under `/api/v1`, its parameters the JSON body of a POST or PUT and the
	 * query string of a GET or DELETE (docs/api.md#writes-and-tokens).
	 *
	 * @param array<string, mixed> $params
	 */
	public function ocs(Account $as, string $verb, string $path, array $params = []): Answer {
		[$path, $headers, $body] = self::ocsCall($verb, $path, $params);

		return $this->request($verb, $path, $as, $headers, $body);
	}

	/**
	 * OCS calls sent at once, each on its own connection, answered in the order given: what two
	 * devices tapping together do, for a race the server has to settle.
	 *
	 * @param list<array{string, string, array<string, mixed>}> $calls verb, path, parameters
	 * @return list<Answer>
	 */
	public function race(Account $as, array $calls): array {
		$multi = curl_multi_init();
		$handles = [];
		$received = [];
		foreach ($calls as $i => [$verb, $path, $params]) {
			[$path, $headers, $body] = self::ocsCall($verb, $path, $params);
			$received[$i] = [];
			$handles[$i] = $this->handle($verb, $path, $as, $headers, $body, $received[$i]);
			curl_multi_add_handle($multi, $handles[$i]);
		}
		do {
			$status = curl_multi_exec($multi, $running);
			if ($running > 0) {
				curl_multi_select($multi);
			}
		} while ($running > 0 && $status === CURLM_OK);
		// Hands each transfer's result to its handle, for curl_errno() below.
		while (curl_multi_info_read($multi) !== false) {
		}

		$answers = [];
		foreach ($handles as $i => $curl) {
			// A failed transfer still has content, an empty string: only its error number tells.
			$response = curl_errno($curl) === 0 ? curl_multi_getcontent($curl) : false;
			$answers[] = $this->answer($calls[$i][0], $calls[$i][1], $curl, $response, $received[$i]);
			curl_multi_remove_handle($multi, $curl);
		}
		curl_multi_close($multi);

		return $answers;
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{string, list<string>, ?string} the full path, the headers, the body
	 */
	private static function ocsCall(string $verb, string $path, array $params): array {
		$headers = ['OCS-APIRequest: true', 'Accept: application/json'];
		$body = null;
		if ($verb === 'POST' || $verb === 'PUT') {
			$headers[] = 'Content-Type: application/json';
			$body = json_encode((object)$params, JSON_THROW_ON_ERROR);
		} elseif ($params !== []) {
			$path .= '?' . http_build_query($params);
		}

		return [self::OCS . $path, $headers, $body];
	}

	/** @param list<string> $headers */
	public function request(string $verb, string $path, ?Account $as, array $headers = [], ?string $body = null): Answer {
		$received = [];
		$curl = $this->handle($verb, $path, $as, $headers, $body, $received);

		return $this->answer($verb, $path, $curl, curl_exec($curl), $received);
	}

	/**
	 * @param list<string> $headers
	 * @param array<string, string> $received filled with the answer's headers as they arrive
	 */
	private function handle(string $verb, string $path, ?Account $as, array $headers, ?string $body, array &$received): \CurlHandle {
		$curl = curl_init($this->url . $path);
		curl_setopt_array($curl, [
			CURLOPT_CUSTOMREQUEST => $verb,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$received[strtolower(trim($parts[0]))] = trim($parts[1]);
				}

				return strlen($line);
			},
		]);
		if ($as !== null) {
			curl_setopt($curl, CURLOPT_USERPWD, $as->uid . ':' . $as->password);
		}
		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}

		return $curl;
	}

	/** @param array<string, string> $received */
	private function answer(string $verb, string $path, \CurlHandle $curl, string|bool|null $response, array $received): Answer {
		if (!is_string($response)) {
			throw new \RuntimeException("$verb $path: " . curl_error($curl));
		}

		return new Answer((int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $received, $response);
	}

	/**
	 * Runs occ as the user running the suite, with stdin closed, and answers what it printed to
	 * stdout. Stderr is kept apart, since a server's deprecation notice there would break the JSON
	 * and the last line the callers read; a failure names both.
	 */
	public function occ(string ...$args): string {
		// A file rather than a second pipe: reading one pipe to its end while occ fills the other
		// would hang both.
		$errors = tmpfile();
		$process = proc_open(
			[PHP_BINARY, $this->root . '/occ', ...$args],
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $errors],
			$pipes,
		);
		if (!is_resource($process)) {
			throw new \RuntimeException('occ did not start');
		}
		$output = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$exit = proc_close($process);
		rewind($errors);
		$stderr = (string)stream_get_contents($errors);
		fclose($errors);
		if ($exit !== 0) {
			$said = array_filter([trim($stderr), trim($output)], static fn (string $text): bool => $text !== '');
			throw new \RuntimeException('occ ' . implode(' ', $args) . " exited $exit:\n" . implode("\n", $said));
		}

		return $output;
	}
}
