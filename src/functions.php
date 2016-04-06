<?php

namespace Bugcache;

use Aerys as aerys;

function jsonify(callable $handler) {
	return new class($handler) implements aerys\Bootable {
		private $handler;
		function __construct($handler) {
			$this->handler = $handler;
		}

		function boot(aerys\Server $server, aerys\Logger $logger) {
			$handler = $this->handler;
			if (is_array($handler) && is_object($handler[0])) {
				$handler = $handler[0];
			}
			if ($handler instanceof aerys\Bootable) {
				$handler->boot($server, $logger);
			}
		}
		
		function __invoke(aerys\Request $req, aerys\Response $res, $routerData = []) {
			$data = ($this->handler)($req, $routerData);

			if ($data instanceof \Generator) {
				$data = yield from $data;
			}

			$res->setHeader("content-type", "application/json");
			if ($data === null) {
				$res->setStatus(403);
				$res->end('{"error": "invalid request"}');
			} else {
				$res->end(json_encode($data));
			}
		}
	};
}