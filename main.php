#!/usr/bin/php
<?php

/*
 * Plugin Name: Backend Api
 */

abstract class simple_type_t {
	abstract public function isfit($var);
}

class int_type_t extends simple_type_t {
	public function isfit($var) {
		return is_int($var);
	}
}

class str_type_t extends simple_type_t {
	public function isfit($var) {
		return is_string($var);
	}
}

$types = [
	"int" => new int_type_t,
	"str" => new str_type_t,
];

abstract class service_t {
	protected function get_curl_opts() {
		return [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 1, # all the services are local. Abort on timeout
			CURLOPT_TIMEOUT => 5,
		];
	}

	protected function get_host() {
		return "127.0.0.1";
	}

	abstract protected function get_port();
	protected function get_url() {
		return "";
	}

	protected function get_args() {
		return [];
	}

	public function request(array $args) {
		$curl_opts = $this->get_curl_opts();

		if (!$this->curl) {
			$this->curl = curl_init();
			curl_setopt_array($this->curl, $curl_opts);
		}

		$args = http_build_query($args);

		curl_setopt($this->curl, CURLOPT_URL,
			"http://" . $this->get_host() . ":" . $this->get_port() . "/" . $this->get_url() . "?" . $args);

		$response = curl_exec($this->curl);
		if (!$response) {
			trigger_error("Can't call method: " . curl_error($this->curl), E_USER_ERROR);
			return Null;
		}

		$decoded = json_decode($response);
		if (json_last_error() != JSON_ERROR_NONE) {
			trigger_error("Can't decode response: " . json_last_error_msg(), E_USER_ERROR);
			return Null;
		}

		return $decoded;
	}

	function __destruct() {
		if ($this->curl)
			curl_close($this->curl);
	}
}

class test_service_t extends service_t {
	protected function get_port() {
		return "3000";
	}
}

$services = [
	"test" => new test_service_t,
];

$interface = [
	"test"		=> [
		"args"		=> [
			"arg_1"		=> [
				"type"		=> "int",
				"required"	=> true,
			],
			"arg_2"		=> [
				"type"		=> "str",
				"required"	=> false,
			],
		],
		"method"	=> "/test",
		"service"	=> "test",
	],
];

function call_service($method_name, array $args) {
	global $interface;
	global $services;
	$service = $services[$interface[$method_name]["service"]];
	if (!$service) {
		trigger_error("Can't find valid service for method '$method_name'", E_USER_ERROR);
		return Null;
	}

	return $service->request($args);
}

function check_method($method_name, array $args) {
	global $interface;
	global $types;

	$expected = $interface[$method_name] ? $interface[$method_name]["args"] : Null;
	if (!$expected) {
		trigger_error("Method $method_name not found", E_USER_ERROR);
		return false;
	}

	$processed_args = [];
	foreach ($expected as $arg_name => $arg_descr) {
		if (!$args[$arg_name] and $arg_descr["required"]) {
			trigger_error("Required argument '$arg_name' not found in args list, method '$method_name'", E_USER_ERROR);
			return false;
		}

		$processed_args[$arg_name] = 1;

		if (!$args[$arg_name])
			continue;

		$type = $arg_descr["type"];
		if (!$type or !$types[$type]) {
			trigger_error("Unknown type found for arg '$arg_name', method '$method_name', type == '"
				. $arg_descr["type"] . "'", E_USER_ERROR);
			return false;
		}

		if (!$types[$type]->isfit($args[$arg_name])) {
			trigger_error("Invalid value found for argument '$arg_name', method '$method_name'. "
				. "Value of type '" . $arg_descr["type"] . "' is expected, but '" . $args[$arg_name] . "' found.", E_USER_ERROR);
			return false;
		}
	}

	foreach ($args as $arg_name => $arg_descr)
		if (!$processed_args[$arg_name]) {
			error_log("Unsupported argument '$arg_name' found in method '$method_name'. Skip");
			unset($args[$arg_name]);
		}

	return true;
}

function call_method($method_name, array $args) {
	if (!check_method($method_name, $args))
		return Null;
	return call_service($method_name, $args);
}

function process_method_call($method_name, array $args) {
	if (!check_method($method_name, $args))
		return false;
	return call_method($method_name, $args);
}

if (function_exists("add_action")) {
	foreach ($interface as $method_name => $_) {
		add_action($method_name, create_function('$args', "return process_method_call('$method_name', \$args);"));
	}
} else {
	# Test mode
	$method_name = create_function('$args', "return process_method_call('test', \$args);");
	var_dump($method_name([ "arg_1" => 123, "arg_2" => "ddd", ]));
}

?>
